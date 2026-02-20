<?php

namespace Modular\Connector\Services;

use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\data_get;

/**
 * Handles all functionality related to WordPress translations.
 */
class ManagerWhiteLabel
{
    /**
     * @var string
     */
    protected string $key = '_modular_connector_white_label';

    /**
     * @return void
     */
    public function init()
    {
        add_filter('all_plugins', [$this, 'setPluginName'], 10, 2);
        add_filter('debug_information', [$this, 'setPluginHealth']);
        add_filter('plugin_row_meta', [$this, 'setPluginMeta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'setPluginScripts']);
        add_filter('show_advanced_plugins', [$this, 'setAdvancedPlugins'], 10, 2);
        add_action('admin_menu', [$this, 'setPluginMenu'], PHP_INT_MAX);
    }

    /**
     * @return array
     */
    protected function getAllWhiteLabeledData(): array
    {
        try {
            $client = OauthClient::getClient();
        } catch (\Throwable $e) {
            return [];
        }

        if (empty($client->getUsedAt())) {
            return [];
        }

        if (!Cache::has($this->key)) {
            try {
                $client->validateOrRenewAccessToken();

                $response = $client->wordpress->getWhiteLabel();

                $this->update($response);
            } catch (\Throwable $e) {
                $this->update(null);
            }
        }

        return Cache::get($this->key, []);
    }

    /**
     * @param string|null $basename
     * @return array|null
     */
    public function getWhiteLabeledData(?string $basename = null): ?array
    {
        $data = $this->getAllWhiteLabeledData();

        return $data[$basename ?? MODULAR_CONNECTOR_BASENAME] ?? null;
    }

    /**
     * @param string|null $basename
     * @return bool
     */
    public function isEnabled(?string $basename = null)
    {
        return data_get($this->getWhiteLabeledData($basename), 'status', 'disabled') === 'enabled';
    }

    /**
     * @return void
     */
    public function forget()
    {
        Cache::forget($this->key);
    }

    /**
     * @param $payload
     * @return void
     */
    public function update($payload)
    {
        Cache::forget($this->key);

        if (empty($payload)) {
            Cache::put($this->key, [], 3 * DAY_IN_SECONDS);

            return;
        }

        $plugins = [];

        foreach ($payload as $data) {
            $basename = data_get($data, 'basename');

            if (empty($basename) || data_get($data, 'status') !== 'enabled') {
                continue;
            }

            $plugins[$basename] = [
                'Name' => data_get($data, 'name', ''),
                'Title' => data_get($data, 'name', ''),
                'Description' => data_get($data, 'description', ''),
                'AuthorURI' => data_get($data, 'author_url', ''),
                'Author' => data_get($data, 'author', ''),
                'AuthorName' => data_get($data, 'author_name', ''),
                'PluginURI' => '',
                'hide' => data_get($data, 'hide', false),
                'status' => data_get($data, 'status', 'disabled'),
            ];
        }

        Cache::put($this->key, $plugins, 3 * DAY_IN_SECONDS);
    }

    /**
     * @param $meta
     * @param $slug
     * @return mixed
     */
    public function setPluginMeta($meta, $slug)
    {
        $data = $this->getAllWhiteLabeledData();

        if (empty($data) || !isset($data[$slug])) {
            return $meta;
        }

        if (isset($meta[2])) {
            unset($meta[2]);
        }

        return $meta;
    }

    /**
     * @param $info
     * @return mixed
     */
    public function setPluginHealth($info)
    {
        $data = $this->getAllWhiteLabeledData();

        if (empty($data)) {
            return $info;
        }

        foreach ($data as $basename => $whiteLabel) {
            if ($whiteLabel['status'] !== 'enabled') {
                continue;
            }

            $pluginFile = WP_PLUGIN_DIR . '/' . $basename;

            if (!file_exists($pluginFile)) {
                continue;
            }

            $originalPlugin = get_plugin_data($pluginFile, false, false);
            $originalName = $originalPlugin['Name'] ?? '';

            if (empty($originalName)) {
                continue;
            }

            foreach (['wp-plugins-active', 'wp-mu-plugins'] as $section) {
                if (!isset($info[$section]['fields'][$originalName])) {
                    continue;
                }

                $field = $info[$section]['fields'][$originalName];

                if (!empty($whiteLabel['Name'])) {
                    $field['label'] = $whiteLabel['Name'];
                }

                if (!empty($whiteLabel['Author'])) {
                    $originalAuthor = $originalPlugin['AuthorName'] ?: ($originalPlugin['Author'] ?? '');

                    if (!empty($originalAuthor)) {
                        if (!empty($field['value'])) {
                            $field['value'] = Str::replace($originalAuthor, $whiteLabel['Author'], $field['value']);
                        }

                        if (!empty($field['debug'])) {
                            $field['debug'] = Str::replace($originalAuthor, $whiteLabel['Author'], $field['debug']);
                        }
                    }
                }

                $info[$section]['fields'][$originalName] = $field;
            }
        }

        return $info;
    }

    public function setAdvancedPlugins($previousValue, $type)
    {
        // TODO Implement must-use plugins
    }

    /**
     * @return void
     */
    public function setPluginMenu()
    {
        global $menu, $submenu;

        $data = $this->getAllWhiteLabeledData();

        if (empty($data)) {
            return;
        }

        foreach ($data as $basename => $whiteLabel) {
            if ($whiteLabel['status'] !== 'enabled' || empty($whiteLabel['Name'])) {
                continue;
            }

            $slug = dirname($basename);

            foreach ($menu as $position => $item) {
                if (isset($item[2]) && $item[2] === $slug) {
                    $menu[$position][0] = $whiteLabel['Name'];
                    $menu[$position][3] = $whiteLabel['Name'];
                }
            }

            foreach ($submenu as $parent => $items) {
                foreach ($items as $index => $item) {
                    if (isset($item[2]) && $item[2] === $slug) {
                        $submenu[$parent][$index][0] = $whiteLabel['Name'];
                        $submenu[$parent][$index][3] = $whiteLabel['Name'];
                    }
                }
            }
        }
    }

    /**
     * @param array $plugins
     * @return array|mixed
     */
    public function setPluginName(array $plugins)
    {
        $data = $this->getAllWhiteLabeledData();

        if (empty($data)) {
            return $plugins;
        }

        foreach ($data as $basename => $whiteLabel) {
            if ($whiteLabel['status'] !== 'enabled' || !isset($plugins[$basename])) {
                continue;
            }

            if (!empty($whiteLabel['hide'])) {
                unset($plugins[$basename]);

                continue;
            }

            $plugins[$basename]['PluginURI'] = '';

            if (!empty($whiteLabel['Name'])) {
                $plugins[$basename]['Name'] = $whiteLabel['Name'];
            }

            if (!empty($whiteLabel['Title'])) {
                $plugins[$basename]['Title'] = $whiteLabel['Name'];
            }

            if (!empty($whiteLabel['Description'])) {
                $plugins[$basename]['Description'] = $whiteLabel['Description'];
            }

            if (!empty($whiteLabel['AuthorURI'])) {
                $plugins[$basename]['AuthorURI'] = $whiteLabel['AuthorURI'];
            }

            if (!empty($whiteLabel['Author'])) {
                $plugins[$basename]['Author'] = $whiteLabel['Author'];
            }

            if (!empty($whiteLabel['AuthorName'])) {
                $plugins[$basename]['AuthorName'] = $whiteLabel['AuthorName'];
            }
        }

        return $plugins;
    }

    /**
     * FIXME Replace by site_transient_update_plugins
     *
     * @param $page
     * @return void
     */
    public function setPluginScripts($page)
    {
        if ($page !== 'update-core.php') {
            return;
        }

        $selectors = [];

        foreach ($data as $basename => $whiteLabel) {
            if ($whiteLabel['status'] === 'enabled' && !empty($whiteLabel['Name'])) {
                $selectors[] = "input[value='" . esc_js($basename) . "']";
            }
        }

        if (empty($selectors)) {
            return;
        }

        $selector = implode(', ', $selectors);

        echo '<script>
				document.addEventListener("DOMContentLoaded", function(event) {
					document.querySelectorAll("' . $selector . '").forEach(function(checkbox) {
						checkbox.closest("tr").style.display = "none";
					});
				});
			</script>';
    }
}

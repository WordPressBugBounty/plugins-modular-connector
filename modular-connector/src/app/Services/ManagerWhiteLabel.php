<?php

namespace Modular\Connector\Services;

use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\base_path;
use function Modular\ConnectorDependencies\data_get;

/**
 * Handles all functionality related to WordPress translations.
 */
class ManagerWhiteLabel
{
    /**
     * @var string
     */
    protected string $key = '_modular_white_label';

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
    }

    /**
     * @return array|null
     */
    public function getWhiteLabeledData()
    {
        try {
            $client = OauthClient::getClient();
        } catch (\Throwable $e) {
            return [
                'status' => 'disabled',
            ];
        }

        if (empty($client->getUsedAt())) {
            return [
                'status' => 'disabled',
            ];
        } else {
            if (!Cache::has($this->key)) {
                try {
                    $client->validateOrRenewAccessToken();

                    $response = $client->wordpress->getWhiteLabel();

                    $this->update($response);
                } catch (\Throwable $e) {
                    $this->update(null);
                }
            }
        }

        return Cache::get($this->key);
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return data_get($this->getWhiteLabeledData(), 'status', 'disabled') === 'enabled';
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

        if (!empty($payload) || data_get($payload, 'status') === 'enabled') {
            $payload = [
                'Name' => data_get($payload, 'name', ''),
                'Title' => data_get($payload, 'name', ''),
                'Description' => data_get($payload, 'description', ''),
                'AuthorURI' => data_get($payload, 'author_url', ''),
                'Author' => data_get($payload, 'author', ''),
                'AuthorName' => data_get($payload, 'author_name', ''),
                'PluginURI' => '',
                'hide' => data_get($payload, 'hide', false),
                'status' => data_get($payload, 'status', 'disabled'),
            ];
        } else {
            $payload = null;
        }

        Cache::put($this->key, $payload, 3 * DAY_IN_SECONDS);
    }

    /**
     * @param $meta
     * @param $slug
     * @return mixed
     */
    public function setPluginMeta($meta, $slug)
    {
        if ($slug !== MODULAR_CONNECTOR_BASENAME) {
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
        $whiteLabel = $this->getWhiteLabeledData();

        if (empty($whiteLabel) || $whiteLabel['status'] === 'disabled') {
            return $info;
        }

        if (
            !isset($info['wp-plugins-active']['fields']['Modular Connector']) &&
            !isset($info['wp-mu-plugins']['fields']['Modular Connector'])) {
            return $info;
        }

        $setWhiteLabel = function ($data) use ($whiteLabel) {
            if (!empty($whiteLabel['Name'])) {
                $data['label'] = $whiteLabel['Name'];
            }

            if (!empty($whiteLabel['Author'])) {
                $author = data_get($whiteLabel, 'Author');
                $value = data_get($data, 'value');

                if (!empty($value)) {
                    $data['value'] = Str::replace('Modular DS', $author, $value);
                }

                $debug = data_get($data, 'debug');

                if (!empty($debug)) {
                    $data['debug'] = Str::replace('Modular DS', $author, $debug);
                }
            }

            return $data;
        };

        $pluginData = data_get($info, 'wp-plugins-active.fields.Modular Connector', []);
        $muPluginData = data_get($info, 'wp-mu-plugins.fields.Modular Connector', []);

        $info['wp-plugins-active']['fields']['Modular Connector'] = $setWhiteLabel($pluginData);

        if (isset($info['wp-mu-plugins']['fields']['Modular Connector'])) {
            $info['wp-mu-plugins']['fields']['Modular Connector'] = $setWhiteLabel($muPluginData);
        }

        return $info;
    }

    public function setAdvancedPlugins($previousValue, $type)
    {
        // TODO Implement must-use plugins
    }

    /**
     * @param array $plugins
     * @return array|mixed
     */
    public function setPluginName(array $plugins)
    {
        $whiteLabel = $this->getWhiteLabeledData();

        if (empty($whiteLabel) || $whiteLabel['status'] === 'disabled') {
            return $plugins;
        }

        $basename = plugin_basename(realpath(base_path('../init.php')));

        if ($whiteLabel['hide']) {
            unset($plugins[$basename]);
            return $plugins;
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
        if ($page !== 'plugins.php' && $page !== 'update-core.php') {
            return;
        }

        if (isset($_GET['plugin_status']) && $_GET['plugin_status'] !== 'mustuse') {
            return;
        }

        $whiteLabel = $this->getWhiteLabeledData();

        if ($page === 'update-core.php' && !empty($whiteLabel['Name'])) {
            echo '<script>
				document.addEventListener("DOMContentLoaded", function(event) {
					const checkbox = document.querySelector("input[value=\'modular-connector/init.php\']")

					if(checkbox) {
						checkbox.closest("tr").style.display = "none";
					}
				});
			</script>';
        }
    }
}

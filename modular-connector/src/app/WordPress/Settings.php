<?php

namespace Modular\Connector\WordPress;

use Modular\Connector\Facades\Manager;
use Modular\Connector\Facades\WhiteLabel;
use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ClearableQueue;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\View;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\request;
use function Modular\ConnectorDependencies\storage_path;

class Settings
{
    /**
     * The slug name to refer to this menu by. Should be unique for this
     * menu page and only include lowercase alphanumeric,
     * dashes, and underscores characters to be
     * compatible with sanitize_key().
     *
     * @var string
     */
    public string $slug = 'modular-connector';

    /**
     * The text to be used for the menu.
     *
     * @return  string
     */
    public function title(): string
    {
        $data = WhiteLabel::getWhiteLabeledData();
        $isEnabled = WhiteLabel::isEnabled();

        if ($isEnabled) {
            return $data['Name'];
        }

        return sprintf(esc_attr__('%s - Connection manager', 'modular-connector'), 'Modular DS');
    }

    /**
     * Returns true if white label is active.
     *
     * @return bool
     */
    public function isWhiteLabelActive(): bool
    {
        return WhiteLabel::isEnabled();
    }

    /**
     * Returns true there is a established connection.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        $connection = OauthClient::getClient();

        return !empty($connection->getClientId()) && !empty($connection->getConnectedAt());
    }

    /**
     * The theme chosen depending on white label or not.
     *
     * @return  string
     */
    public function theme(): string
    {
        return $this->isWhiteLabelActive() ? 'theme-flat' : 'theme-default';
    }

    /**
     * The function to be called to output the content for this page.
     *
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function show(): void
    {
        $title = $this->title();
        $theme = $this->theme();
        $isWhiteLabelActive = $this->isWhiteLabelActive();
        $isConnected = $this->isConnected();

        $connection = OauthClient::getClient();

        $method = Str::lower(Request::method());
        $tab = sanitize_text_field(Request::get('tab', 'default'));

        $logs = [];

        if ($method === 'get') {
            if ($tab === 'logs') {
                $view = 'settings.logs';
                $logs = $this->getStoredLogs();
            } elseif ($tab === 'health') {
                $view = 'settings.health';
            } elseif ($isConnected) {
                $view = 'settings.connected';
            } elseif (!empty($connection->getClientId()) && empty($connection->getConnectedAt())) {
                $view = 'settings.pending';
            } else {
                $view = 'settings.disconnected';
            }

            // TODO Move styles to a separate file
            echo View::make($view, compact('title', 'theme', 'isWhiteLabelActive', 'connection', 'isConnected', 'tab', 'logs'))
                ->render();
        } elseif ($method === 'post') {
            if ($tab === 'logs') {
                $this->downloadLogs();
                return;
            } elseif ($tab === 'health') {
                $action = Request::get('action');

                if (in_array($action, ['queue', 'cache', 'reset'])) {
                    $this->clear();

                    $this->redirect(true, 'health');
                    return;
                }
            }

            $this->store();
        }
    }

    /**
     * @param bool $success
     * @param string $tab
     * @return void
     */
    protected function redirect(bool $success, string $tab = ''): void
    {
        ob_start();
        $response = Response::redirectTo(menu_page_url('modular-connector', false) . '&success=' . intval($success) . ($tab ? '&tab=' . $tab : ''));
        $response->send();
        ob_end_flush();
        die();
    }

    /**
     * The function used to get all stored modular logs
     *
     * @return array
     */
    public function getStoredLogs(): array
    {
        $path = storage_path('logs/*');

        $logs = [];

        foreach (File::glob($path) as $logFile) {
            if (Str::endsWith($logFile, '.log')) {
                $logs[] = basename($logFile);
            }
        }

        return $logs;
    }

    /**
     * The function used to download the Modular logs
     *
     * @return void
     */
    public function downloadLogs()
    {
        $request = request();

        // Verify nonce for security
        $nonce = sanitize_key(wp_unslash($request->get('_wpnonce')));

        if (!wp_verify_nonce($nonce, '_modular_connector_logs')) {
            wp_nonce_ays('_modular_connector_logs');
        }

        // Get the selected log file from the request
        $log = sanitize_text_field($request->get('log_file'));

        if (empty($log)) {
            wp_die(esc_html__('No log file selected for download.', 'modular-connector'));
        }

        // Prevent path traversal attacks
        if (!preg_match('/^[a-zA-Z0-9._-]+\.log$/', $log)) {
            wp_die(esc_html__('Invalid log file name.', 'modular-connector'));
        }

        // Build the file path
        $path = storage_path(sprintf('/logs/%s', $log));

        // Check if the file exists
        if (!file_exists($path)) {
            wp_die(esc_html__('The selected log file does not exist.', 'modular-connector'));
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            wp_die(esc_html__('The selected log file could not be read.', 'modular-connector'));
        }

        // Drop every buffer level (gzip, other plugins): a single ob_end_clean()
        // leaves the rest in front of the file and corrupts the download.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // A debug log can outgrow memory_limit: stream it instead of loading it.
        @set_time_limit(0);

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));

        fpassthru($handle);
        fclose($handle);

        exit;
    }

    /**
     * Allowed queue connection names for clear operations.
     */
    private const ALLOWED_QUEUE_CONNECTIONS = ['sync', 'wordpress', 'database'];

    /**
     * Allowed cache store names for clear operations.
     */
    private const ALLOWED_CACHE_STORES = ['file', 'database'];

    /**
     * The function used to clear the cache
     *
     * @return void
     */
    public function clear(): void
    {
        $request = request();

        $nonce = sanitize_key(wp_unslash($request->get('_wpnonce')));

        if (!wp_verify_nonce($nonce, '_modular_connector_clear')) {
            wp_nonce_ays('_modular_connector_clear');
        }

        if ($request->get('action') === 'queue') {
            $queueName = $request->get('queue');
            $connection = $request->get('driver');

            // Validate connection is in allowed list
            if (!in_array($connection, self::ALLOWED_QUEUE_CONNECTIONS, true)) {
                wp_die(esc_html__('Invalid queue connection.', 'modular-connector'));
            }

            $queue = app('queue')->connection($connection);

            if ($queue instanceof ClearableQueue) {
                $queue->clear($queueName);
            }
        } elseif ($request->get('action') === 'cache') {
            $driver = $request->get('driver');

            // Validate driver is in allowed list
            if (!in_array($driver, self::ALLOWED_CACHE_STORES, true)) {
                wp_die(esc_html__('Invalid cache driver.', 'modular-connector'));
            }

            app('cache')->driver($driver)->flush();
        } elseif ($request->get('action') === 'reset') {
            Manager::deactivate();
        }
    }

    /**
     * The function to save connection data
     *
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function store(): void
    {
        $request = request();

        $nonce = sanitize_key(wp_unslash($request->get('_wpnonce')));

        if (!wp_verify_nonce($nonce, '_modular_connector_connection')) {
            wp_nonce_ays('_modular_connector_connection');
        }

        $success = true;

        $clientId = $request->get('client_id');

        if (empty($clientId) || !Str::isUuid($clientId)) {
            $success = false;
            $clientId = '';
        }

        $clientSecret = $request->get('client_secret');

        if (empty($clientSecret)) {
            $success = false;
            $clientSecret = '';
        }

        $client = OauthClient::mapClient([]);

        $client->setClientId($clientId)
            ->setClientSecret($clientSecret)
            ->save();

        $this->redirect($success);
    }
}

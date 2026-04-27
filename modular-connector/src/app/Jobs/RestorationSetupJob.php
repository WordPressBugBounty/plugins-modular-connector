<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\RestorationSetupUpdated;
use Modular\Connector\Concerns\FormatsErrors;
use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class RestorationSetupJob implements ShouldQueue
{
    use Dispatchable;
    use FormatsErrors;
    use Queueable;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var mixed
     */
    protected $payload;

    /**
     * @param string $mrid
     * @param $payload
     */
    public function __construct(string $mrid, $payload)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $body = $this->payload;

        $restorationId = data_get($body, 'restoration');
        $zipUrl = data_get($body, 'zip_url');
        $folderPrefix = data_get($body, 'folder_prefix');
        $folderName = "{$folderPrefix}-{$restorationId}";

        try {
            $disk = Storage::disk('content');

            // 1. Create restoration folder
            $this->createFolder($disk, $folderName);

            // 2. Create config.php
            $this->createConfigFile($disk, $folderName, $body);

            // 3. Download and extract restoration ZIP
            $this->downloadAndExtractZip($disk, $folderName, $zipUrl);

            event(new RestorationSetupUpdated($this->mrid, [
                'success' => true,
                'result' => [
                    'folder' => $folderName,
                ],
            ]));
        } catch (\Throwable $e) {
            Log::error($e);

            event(new RestorationSetupUpdated($this->mrid, [
                'success' => false,
                'error' => $this->formatError($e),
            ]));
        }
    }

    /**
     * Creates the restoration directory.
     *
     * @param $disk
     * @param string $folderName
     * @return void
     */
    private function createFolder($disk, string $folderName): void
    {
        if ($disk->exists($folderName)) {
            Log::debug('RestorationSetupJob: Folder already exists, cleaning up', ['folder' => $folderName]);
            $disk->deleteDirectory($folderName);
        }

        $disk->makeDirectory($folderName);
    }

    /**
     * Generates config.php with local and remote constants.
     *
     * @param $disk
     * @param string $folderName
     * @param object $body
     * @return void
     */
    private function createConfigFile($disk, string $folderName, object $body): void
    {
        $constants = $this->buildConstants($body);

        $lines = ["<?php\n"];

        foreach ($constants as $section => $entries) {
            $lines[] = "// {$section}";

            foreach ($entries as $name => $value) {
                $lines[] = sprintf("define('%s', %s);", $name, $this->formatValue($value));
            }

            $lines[] = '';
        }

        $content = implode("\n", $lines);

        $disk->put("{$folderName}/config.php", $content);
    }

    /**
     * Downloads the restoration ZIP and extracts it into the folder.
     *
     * @param $disk
     * @param string $folderName
     * @param string $url
     * @return void
     */
    private function downloadAndExtractZip($disk, string $folderName, string $url): void
    {
        $zipRelative = "{$folderName}/modular-restoration.zip";
        $zipPath = $disk->path($zipRelative);

        $response = wp_remote_get($url, [
            'timeout' => 120,
            'stream' => true,
            'filename' => $zipPath,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Failed to download restoration ZIP: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode !== 200) {
            $disk->delete($zipRelative);

            throw new \RuntimeException("Failed to download restoration ZIP: HTTP {$statusCode}");
        }

        $folderPath = $disk->path($folderName);

        $zip = new \ZipArchive();
        $res = $zip->open($zipPath);

        if ($res !== true) {
            $disk->delete($zipRelative);

            throw new \RuntimeException("Failed to open restoration ZIP (code {$res})");
        }

        if (!$zip->extractTo($folderPath)) {
            $zip->close();
            $disk->delete($zipRelative);

            throw new \RuntimeException('Failed to extract restoration ZIP');
        }

        $zip->close();
        $disk->delete($zipRelative);
    }

    /**
     * Builds the constants array organized by section.
     *
     * @param object $body
     * @return array<string, array<string, mixed>>
     */
    private function buildConstants(object $body): array
    {
        $apiUrl = OauthClient::getClient()->getApiBaseUrl();
        $backupContent = data_get($body, 'backup_content', new \stdClass());

        $constants = [
            'API configuration' => [
                'APP_ENV' => defined('MODULAR_CONNECTOR_ENV') ? MODULAR_CONNECTOR_ENV : 'production',
                'API_URL' => rtrim($apiUrl, '/') . '/',
                'API_KEY' => data_get($body, 'api_key'),
                'RESTORATION_TYPE' => data_get($body, 'restoration_type'),
                'MRID' => $this->mrid,
                'BACKUP_RESTORATION_ID' => data_get($body, 'restoration'),
                'BASE_URL' => defined('WP_CONTENT_URL') ? WP_CONTENT_URL : rtrim(data_get($body, 'site_url', site_url()), '/') . '/wp-content',
            ],
            'WordPress paths' => [
                'ABSPATH' => Storage::disk('core')->path(''),
                'WP_CONTENT_DIR' => Storage::disk('content')->path(''),
                'WP_PLUGIN_DIR' => Storage::disk('plugins')->path(''),
                'WP_THEME_DIR' => Storage::disk('themes')->path(''),
                'WPMU_PLUGIN_DIR' => Storage::disk('mu_plugins')->path(''),
                'WP_UPLOAD_DIR' => Storage::disk('uploads')->path(''),
            ],
            'Backup configuration' => [
                'BACKUP_SIZE' => data_get($body, 'backup_size'),
                'BACKUP_TYPE' => data_get($body, 'backup_type'),
                'BACKUP_DRIVER' => data_get($body, 'backup_driver'),
                'BACKUP_FILESYSTEM' => data_get($body, 'backup_filesystem', 'default'),
                'BACKUP_CONTENT_CORE' => data_get($backupContent, 'core', []),
                'BACKUP_CONTENT_THEMES' => data_get($backupContent, 'themes', []),
                'BACKUP_CONTENT_PLUGINS' => data_get($backupContent, 'plugins', []),
                'BACKUP_CONTENT_UPLOADS' => data_get($backupContent, 'uploads', []),
                'BACKUP_CONTENT_MU_PLUGINS' => data_get($backupContent, 'mu_plugins', []),
                'BACKUP_CONTENT_CONTENT' => data_get($backupContent, 'content', []),
                'BACKUP_CONTENT_DATABASE' => data_get($backupContent, 'database', []),
            ],
            'Database configuration' => [
                'DB_HOST' => $this->buildDbHost(),
                'DB_USER' => Config::get('database.connections.wordpress.username'),
                'DB_PASSWORD' => Config::get('database.connections.wordpress.password'),
                'DB_NAME' => Config::get('database.connections.wordpress.database'),
            ],
        ];

        // Migration-specific constants
        $newClientId = data_get($body, 'new_client_id');
        $newClientSecret = data_get($body, 'new_client_secret');

        if ($newClientId && $newClientSecret) {
            $constants['Migration configuration'] = [
                'NEW_CLIENT_ID' => $newClientId,
                'NEW_CLIENT_SECRET' => $newClientSecret,
            ];
        }

        $oldDomain = data_get($body, 'old_domain');
        $newDomain = data_get($body, 'new_domain');

        if ($oldDomain !== null) {
            $constants['Migration configuration']['OLD_DOMAIN'] = $oldDomain;
        }

        if ($newDomain !== null) {
            $constants['Migration configuration']['NEW_DOMAIN'] = $newDomain;
        }

        $searchEngineVisible = data_get($body, 'search_engine_visible');

        if ($searchEngineVisible !== null) {
            $constants['Migration configuration']['SEARCH_ENGINE_VISIBLE'] = (bool)$searchEngineVisible;
        }

        return $constants;
    }

    /**
     * Reconstructs DB_HOST from parsed host, port and socket.
     *
     * @return string
     */
    private function buildDbHost(): string
    {
        $host = Config::get('database.connections.wordpress.host');
        $port = Config::get('database.connections.wordpress.port');
        $socket = Config::get('database.connections.wordpress.unix_socket');

        if ($socket) {
            return $host . ':' . $socket;
        }

        if ($port && (int)$port !== 3306) {
            return $host . ':' . $port;
        }

        return $host;
    }

    /**
     * Formats a PHP value for use in a define() statement.
     *
     * @param mixed $value
     * @return string
     */
    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '[]';
            }

            return '[' . implode(', ', array_map(function ($v) {
                    return is_string($v) ? "'{$v}'" : (string)$v;
                }, $value)) . ']';
        }

        // Escape single quotes in string values
        $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value);

        return "'{$escaped}'";
    }

}

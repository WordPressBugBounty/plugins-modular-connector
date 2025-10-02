<?php

namespace Modular\Connector\Services\Manager;

use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\data_get;

/**
 * Handles all functionality related to WordPress Safe Updates.
 */
class ManagerSafeUpgrade
{
    /**
     * Execute a bulk operation on items
     */
    private function executeBulkOperation(string $type, array $items, string $operation): array
    {
        $errors = Collection::make();

        foreach ($items as $item) {
            $slug = $type === ManagerPlugin::PLUGIN ? dirname($item) : $item;

            try {
                $this->{$operation}([
                    'slug' => $slug,
                    'disk' => "{$type}s",
                ]);

            } catch (\Throwable $e) {
                Log::error($e);

                $errors->push([
                    'basename' => $item,
                    'error' => [
                        'code' => $e->getCode(),
                        'message' => sprintf(
                            '%s in %s on line %s',
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine()
                        ),
                    ],
                ]);
            }
        }

        return array_map(function ($item) use ($errors, $type) {
            $error = $errors->first(fn($itemWithError) => data_get($itemWithError, 'basename') === $item);

            $data = [
                'item' => $item,
                'type' => $type,
                'success' => empty($error),
            ];

            if (!empty($error)) {
                $data['error'] = $error;
            }

            return $data;
        }, $items);
    }

    /**
     * Validate common arguments for operations
     */
    private function validateArgs(array $args, array $requiredKeys = ['slug', 'disk']): array
    {
        foreach ($requiredKeys as $key) {
            if (empty($args[$key])) {
                throw new \Exception("Missing required argument: {$key}");
            }
        }

        return [
            'slug' => $args['slug'],
            'disk' => $args['disk'],
        ];
    }

    /**
     * Validate disk accessibility
     */
    private function validateDisk(string $disk): void
    {
        $path = Storage::disk($disk)->path('');

        if (!File::isDirectory($path)) {
            throw new \Exception("Disk '{$disk}' is not accessible");
        }
    }

    /**
     * Build backup path
     */
    private function buildBackupPath(string $disk, string $slug): string
    {
        return $disk . DIRECTORY_SEPARATOR . $slug;
    }

    /**
     * @param string $type
     * @param array $items
     * @return array
     */
    public function bulkBackup(string $type, array $items): array
    {
        return $this->executeBulkOperation($type, $items, 'backup');
    }

    /**
     * @param $args
     * @return bool
     * @throws \Exception
     */
    private function backup($args)
    {
        ['slug' => $slug, 'disk' => $disk] = $this->validateArgs($args);
        $this->validateDisk($disk);

        $srcDir = Storage::disk($disk)->path($slug);
        $relativePath = $this->buildBackupPath($disk, $slug);
        $destDirectory = Storage::disk('safe_upgrades')->path($relativePath);

        // Check if source directory exists
        if (!File::isDirectory($srcDir)) {
            throw new \Exception("Source directory does not exist: {$srcDir}");
        }

        // Create disk directory if it doesn't exist
        if (!Storage::disk('safe_upgrades')->exists($disk)) {
            if (!Storage::disk('safe_upgrades')->makeDirectory($disk)) {
                throw new \Exception("Failed to create backup disk directory: {$disk}");
            }
        }

        // Remove existing backup if it exists
        if (Storage::disk('safe_upgrades')->exists($relativePath)) {
            if (!Storage::disk('safe_upgrades')->deleteDirectory($relativePath)) {
                throw new \Exception("Failed to delete existing backup: {$relativePath}");
            }
        }

        // Copy the directory to the safe_upgrades disk
        if (!File::copyDirectory($srcDir, $destDirectory)) {
            throw new \Exception("Failed to copy directory from {$srcDir} to {$destDirectory}");
        }

        // Verify the backup was created successfully
        if (!File::isDirectory($destDirectory)) {
            throw new \Exception("Backup directory was not created properly: {$destDirectory}");
        }

        return true;
    }

    public function bulkRollback(string $type, array $items): array
    {
        return $this->executeBulkOperation($type, $items, 'rollback');
    }

    /**
     * @param array $args
     * @return bool
     * @throws \Exception
     */
    private function rollback(array $args)
    {
        ['slug' => $slug, 'disk' => $disk] = $this->validateArgs($args);
        $this->validateDisk($disk);

        $srcPath = $this->buildBackupPath($disk, $slug);

        // Check that the element exists in the safe_upgrades disk
        if (!Storage::disk('safe_upgrades')->exists($srcPath)) {
            throw new \Exception("No backup found for {$slug} in {$disk}: {$srcPath}");
        }

        // Get absolute paths for File facade operations
        $srcDirectory = Storage::disk('safe_upgrades')->path($srcPath);
        $destDirectory = Storage::disk($disk)->path($slug);

        // Verify source directory exists
        if (!File::isDirectory($srcDirectory)) {
            throw new \Exception("Backup source directory does not exist: {$srcDirectory}");
        }

        // Delete destination directory if it exists to ensure clean rollback
        if (File::exists($destDirectory)) {
            if (!File::deleteDirectory($destDirectory)) {
                throw new \Exception("Failed to delete current directory for clean rollback: {$destDirectory}");
            }
        }

        // Copy directory using File facade
        if (!File::copyDirectory($srcDirectory, $destDirectory)) {
            throw new \Exception("Failed to copy backup from {$srcDirectory} to {$destDirectory}");
        }

        // Verify the copy was successful
        if (!File::isDirectory($destDirectory)) {
            throw new \Exception("Rollback verification failed: destination directory not found: {$destDirectory}");
        }

        return true;
    }

    public function bulkDelete(string $type, array $items): array
    {
        return $this->executeBulkOperation($type, $items, 'delete');
    }

    /**
     * @param $args
     * @return bool
     * @throws \Exception
     */
    public function delete($args)
    {
        ['slug' => $slug, 'disk' => $disk] = $this->validateArgs($args);
        $this->validateDisk($disk);

        $srcPath = $this->buildBackupPath($disk, $slug);

        // Check that the element exists in the safe_upgrades disk
        if (!Storage::disk('safe_upgrades')->exists($srcPath)) {
            // If backup doesn't exist, consider it already deleted (success)
            return true;
        }

        // Get absolute paths for File facade operations
        $srcDirectory = Storage::disk('safe_upgrades')->path($srcPath);

        // Delete the directory
        if (!File::deleteDirectory($srcDirectory)) {
            throw new \Exception("Failed to delete backup directory: {$srcDirectory}");
        }

        return true;
    }
}

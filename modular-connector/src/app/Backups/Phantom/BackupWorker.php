<?php

namespace Modular\Connector\Backups\Phantom;

use Modular\Connector\Backups\Facades\Backup;
use Modular\Connector\Backups\Phantom\Events\ManagerBackupPartsCalculated;
use Modular\Connector\Backups\Phantom\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Phantom\Jobs\ManagerBackupCompressDatabaseJob;
use Modular\Connector\Backups\Phantom\Jobs\ManagerBackupCompressFilesJob;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Contracts\Debug\ExceptionHandler;
use Modular\ConnectorDependencies\Illuminate\Queue\InvalidPayloadException;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class BackupWorker
{
    /**
     * @var BackupWorker|null
     */
    protected static ?BackupWorker $instance = null;

    /**
     * @var Collection<BackupPart> $parts
     */
    public Collection $parts;

    /**
     * @var string
     */
    protected string $identifier;

    /**
     * @var ExceptionHandler
     */
    protected ExceptionHandler $exceptions;

    /**
     * Prefix
     *
     * @var string
     * @access protected
     */
    public static string $prefix = 'modular';

    /**
     * @param string|null $queue
     * @throws \Throwable
     */
    public function __construct()
    {
        $this->identifier = $this->getIdentifier('bb_part');
        $this->exceptions = app()->make(ExceptionHandler::class);
    }

    /**
     * @return static
     */
    public static function getInstance()
    {
        if (static::$instance === null) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Get identifier for queue
     *
     * @param string $queue
     * @param string|null $suffix
     * @return string
     */
    public static function getIdentifier(string $queue, string $suffix = null)
    {
        return static::$prefix . '_' . $queue . ($suffix ?: '');
    }

    /**
     * @param array|Collection $parts
     * @return self
     */
    public function setParts($parts): self
    {
        if (is_array($parts)) {
            $parts = Collection::make($parts);
        }

        $this->parts = $parts;

        return $this;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        $unique = md5(microtime() . wp_rand());
        $prepend = $this->identifier . '_';

        return \substr($prepend . $unique, 0, 64);
    }

    /**
     * @return string
     */
    public function getUpdatedAtKey(): string
    {
        return $this->identifier . '_updated_at';
    }

    /**
     * @return Carbon|null
     */
    public function getUpdatedAt(): ?Carbon
    {
        $key = $this->getUpdatedAtKey();
        $value = get_site_option($key, null);

        return $value ? Carbon::createFromTimestamp($value) : null;
    }

    /**
     * Create a payload string from the given job
     *
     * @param BackupPart $part
     * @return string
     */
    protected function createPayload(BackupPart $part): string
    {
        $value = [
            'uuid' => Str::uuid(),
            'displayName' => get_class($part),
            'data' => [
                'commandName' => get_class($part),
                'command' => serialize(clone $part),
            ],
        ];

        $payload = wp_json_encode($value, \JSON_UNESCAPED_UNICODE);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error code: ' . json_last_error()
            );
        }

        return $payload;
    }

    /**
     * @param BackupOptions $options
     * @return BackupWorker
     * @throws \Throwable
     */
    public function calculateParts(BackupOptions $options)
    {
        $updatedAt = $this->getUpdatedAt();

        if (!is_null($updatedAt) && !$updatedAt->lessThan(Carbon::now()->subSeconds($options->batchMaxTimeout))) {
            return;
        }

        // This must be a relative path, so we don't use WP_CONTENT const
        // Backups folder must exist, so we handle it
        Backup::driver('phantom')->remove(null, true);
        $this->deleteAll();

        $parts = [];

        $part = new BackupPart(BackupPart::PART_TYPE_DATABASE, $options);
        $part->setDbKey($this->getKey());

        if (in_array(BackupOptions::INCLUDE_DATABASE, $options->included)) {
            $part->markAsPending();
        } else {
            $part->markAsExcluded();
        }

        $parts[] = $part;

        $partTypes = [
            BackupPart::PART_TYPE_CORE => [
                'root' => Storage::disk('core')->path(''),
                'excluded' => [
                    // Custom folders
                    Storage::disk('plugins')->path(''),
                    Storage::disk('themes')->path(''),
                    Storage::disk('uploads')->path(''),
                    Storage::disk('core')->path('wp-content/plugins'),
                    Storage::disk('core')->path('wp-content/themes'),
                    Storage::disk('core')->path('wp-content/uploads'),
                ],
            ],
            BackupPart::PART_TYPE_THEMES => [
                'root' => Storage::disk('themes')->path(''),
                'excluded' => [],
            ],
            BackupPart::PART_TYPE_PLUGINS => [
                'root' => Storage::disk('plugins')->path(''),
                'excluded' => [],
            ],
            BackupPart::PART_TYPE_UPLOADS => [
                'root' => Storage::disk('uploads')->path(''),
                'excluded' => [],
            ],
        ];

        foreach ($partTypes as $type => $conf) {
            $partOptions = clone $options;

            $partOptions->root = $conf['root'];
            $partOptions->addExcludedFiles($conf['excluded']);

            $part = new BackupPart($type, $partOptions);
            $part->markAsExcluded();

            if (in_array(BackupOptions::INCLUDE_CORE, $options->included)) {
                $part->calculateTotalItems();

                if ($part->totalItems > 0) {
                    $part->markAsPending();
                }
            }

            $part->setDbKey($this->getKey());

            $parts[] = $part;
        }

        $this->setParts($parts)->save();

        event(new ManagerBackupPartsCalculated($options, $this->parts));

        return $this;
    }

    /**
     * Get Parts in Queue.
     *
     * @param int $limit Number of batches to return, defaults to all.
     * @return Collection
     * @throws \Throwable
     */
    public function getParts($limit = 0)
    {
        global $wpdb;

        try {
            if (empty($limit) || !is_int($limit)) {
                $limit = 0;
            }

            $table = $wpdb->options;
            $column = 'option_name';
            $keyColumn = 'option_id';
            $valueColumn = 'option_value';

            $key = $wpdb->esc_like($this->identifier) . '%';

            $sql = '
			SELECT *
			FROM ' . $table . '
			WHERE ' . $column . ' LIKE %s
			ORDER BY ' . $keyColumn . ' ASC
			';

            $args = [$key];

            if (!empty($limit)) {
                $sql .= ' LIMIT %d';
                $args[] = $limit;
            }

            $items = $wpdb->get_results($wpdb->prepare($sql, $args));
            $items = Collection::make($items);

            return $items->map(function ($item) use ($column, $valueColumn) {
                $part = json_decode($item->{$valueColumn}, true);

                if (is_int($part)) {
                    return false;
                }

                /**
                 * @var BackupPart $command
                 */
                $command = unserialize($part['data']['command']);
                $command->setDbKey($item->{$column});

                return $command;
            })
                ->filter(function ($item) {
                    return $item !== false;
                });
        } catch (\Throwable $e) {
            $this->exceptions->report($e);

            return Collection::make();
        }
    }

    /**
     * @param BackupPart $part
     * @return void
     * @throws \Throwable
     */
    public function addPart(BackupPart $part)
    {
        event(new ManagerBackupPartUpdated($part));

        $part->setDbKey($this->getKey());
        $this->update($part);
    }

    /**
     * @return BackupWorker
     */
    public function save(): self
    {
        /**
         * @var BackupPart $part
         */
        $parts = $this->parts
            ->filter(function (BackupPart $part) {
                return $part->status !== BackupPart::PART_STATUS_EXCLUDED;
            });

        if (!$parts->isEmpty()) {
            $parts->each(function (BackupPart $part) {
                update_option($part->dbKey, $this->createPayload($part));
            });

            update_option($this->getUpdatedAtKey(), Carbon::now()->timestamp);
        }

        return $this;
    }

    /**
     * @param BackupPart $part
     * @return $this
     * @throws \Throwable
     */
    public function update(BackupPart $part)
    {
        if (!isset($this->parts)) {
            $parts = $this->getParts();
            $this->setParts($parts);
        }

        $exists = $this->parts->filter(fn(BackupPart $item) => $item->dbKey === $part->dbKey)->count() > 0;

        if (!$exists) {
            $this->parts->push($part);
        }

        if ($part->status === BackupPart::PART_STATUS_DONE) {
            $this->delete($part->dbKey);

            return $this;
        }

        $this->parts->transform(function (BackupPart $item) use ($part) {
            if ($item->dbKey === $part->dbKey) {
                $item = $part;
            }

            return $item;
        });

        update_option($part->dbKey, $this->createPayload($part));
        update_option($this->getUpdatedAtKey(), Carbon::now()->timestamp);

        return $this;
    }

    /**
     * @return void
     * @throws \Throwable
     */
    public function dispatch()
    {
        /**
         * @var BackupPart $part
         */
        $part = $this->parts
            ->sortBy(function (BackupPart $part) {
                if ($part->type === 'database') {
                    return '0';
                }

                return $part->type;
            })
            ->first(
                fn(BackupPart $part) => in_array($part->status, [
                    BackupPart::PART_STATUS_PENDING,
                    BackupPart::PART_STATUS_IN_PROGRESS,
                ])
            );

        if (isset($part->type) && !$part->options->isCancelled()) {
            if ($part->type === BackupPart::PART_TYPE_DATABASE) {
                dispatch(new ManagerBackupCompressDatabaseJob($part));
            } else {
                dispatch(new ManagerBackupCompressFilesJob($part));
            }
        } else {
            // Delete previous backup
            $this->deleteAll();
        }
    }

    /**
     * Delete a batch of queued items.
     *
     * @param string $key Key.
     *
     * @return $this
     */
    public function delete($key)
    {
        if (isset($this->parts)) {
            $parts = $this->parts->filter(function (BackupPart $part) use ($key) {
                return $part->dbKey !== $key;
            });

            $this->setParts($parts);
        }

        delete_option($key);

        return $this;
    }

    /**
     * Delete entire job queue.
     *
     * @return void
     * @throws \Throwable
     */
    public function deleteAll()
    {
        $parts = $this->getParts();

        foreach ($parts as $part) {
            $dbKey = $part->dbKey;

            $this->delete($dbKey);
        }

        delete_option($this->getUpdatedAtKey());
    }
}

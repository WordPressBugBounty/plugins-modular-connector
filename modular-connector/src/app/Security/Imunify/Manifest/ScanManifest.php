<?php

namespace Modular\Connector\Security\Imunify\Manifest;

use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\Backups\Iron\Helpers\ManifestHasher;
use Modular\Connector\Backups\Iron\Manifest\CalculateManifestJob;
use Modular\Connector\Manifest\Contracts\ManifestContract;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Helpers\ScanFile;
use Modular\Connector\Security\Imunify\ScanPart;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\dispatch;

class ScanManifest implements ManifestContract
{
    use HasMaxTime;

    /**
     * @var ScanPart
     */
    protected $part;

    /**
     * @var string
     */
    protected $delimiter = ';';

    /**
     * @var int
     */
    protected $bufferSize = 100;

    /**
     * @var int
     */
    protected $maxTime = 25;

    /**
     * @param ScanPart $part
     */
    public function __construct(ScanPart $part)
    {
        $this->part = $part;

        $memoryLimit = HttpUtils::maxMemoryLimit(true);

        if ($memoryLimit === -1) {
            $this->bufferSize = 500;
        } elseif ($memoryLimit <= 128) {
            $this->bufferSize = 50;
        } elseif ($memoryLimit <= 256) {
            $this->bufferSize = 100;
        } elseif ($memoryLimit <= 512) {
            $this->bufferSize = 250;
        } else {
            $this->bufferSize = 500;
        }
    }

    /**
     * @param ScanPart $part
     * @return self
     */
    public static function create(ScanPart $part)
    {
        return new self($part);
    }

    /**
     * @param array $buffer
     * @param int $offset
     * @return void
     */
    private function writeManifest(array $buffer, $offset)
    {
        $currentOffset = $this->part->offset;
        $filePath = Storage::disk('scans')->path($this->part->manifestPath);

        if ($currentOffset > 0 && !file_exists($filePath)) {
            throw new \RuntimeException('Manifest file not found');
        }

        $mode = $currentOffset === 0 ? 'w' : 'a';
        $handle = fopen($filePath, $mode);

        if (!$handle) {
            throw new \RuntimeException('Cannot open manifest file for writing');
        }

        try {
            foreach ($buffer as $line) {
                fwrite($handle, $line . "\n");
            }

            fflush($handle);
        } finally {
            fclose($handle);
        }

        $this->part->offset = $offset;
    }

    /**
     * @return int
     */
    public function count()
    {
        // totalItems is incremented inline during calculate() each time a row is added
        // to the manifest buffer; avoids an O(N) re-read of the manifest file.
        return (int)$this->part->totalItems;
    }

    /**
     * @return void
     */
    public function calculate()
    {
        $startTime = $this->getCurrentTime();
        $filesProcessed = 0;

        if ($this->part->status === ManagerScanPartUpdated::STATUS_PENDING) {
            $this->part->offset = 0;
            $this->part->markAs(ManagerScanPartUpdated::STATUS_IN_PROGRESS);
        }

        $disk = $this->part->type;
        $limit = $this->part->limit;
        $offset = $this->part->offset;
        $allowedExtensions = $this->part->allowedExtensions;
        $excludedExtensions = $this->part->excludedExtensions;
        $maxFileSize = $this->part->maxFileSize;

        $hasher = new ManifestHasher('sha256', true);

        Log::debug('ScanManifest: Starting', [
            'type' => $disk,
            'status' => $this->part->status,
            'offset' => $offset,
            'limit' => $limit,
            'hash_algorithm' => $hasher->getAlgorithm(),
        ]);

        $files = ScanFile::finderWithLimiter($disk, $offset, $limit, $this->part->excludedFiles);

        $buffer = [];

        foreach ($files as $file) {
            $offset++;
            $filesProcessed++;

            if (!ScanFile::shouldInclude($file, $allowedExtensions, $maxFileSize, $excludedExtensions)) {
                continue;
            }

            if ($filesProcessed % 500 === 0 && $this->checkMemoryUsage()) {
                Log::warning('ScanManifest: High memory usage', [
                    'type' => $disk,
                    'offset' => $offset,
                    'filesProcessed' => $filesProcessed,
                    'memory_usage' => memory_get_usage(true),
                ]);

                if (!empty($buffer)) {
                    $this->writeManifest($buffer, $offset);
                    $buffer = [];
                }

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $item = ScanFile::mapItem($file, $disk, $hasher);

            // mapItem returns null when the file cannot be hashed (empty files,
            // unreadable files, files that vanished mid-scan). Dropping the row
            // keeps `total_files` in /scan/start in sync with the hashes we will
            // actually submit, so Imunify never sees a file it cannot resolve.
            if ($item === null) {
                continue;
            }

            $item['path'] = sprintf('"%s"', Str::replace('"', '""', $item['path']));

            $buffer[] = implode($this->delimiter, $item);
            // Track included items inline to avoid an O(N) re-read at the end.
            $this->part->totalItems++;

            if ($this->isTimeExceeded($startTime, $this->maxTime)) {
                Log::debug('ScanManifest: Max time exceeded', [
                    'type' => $disk,
                    'offset' => $offset,
                ]);

                if (!empty($buffer)) {
                    $this->writeManifest($buffer, $offset);
                    $buffer = [];
                }

                break;
            }

            if (count($buffer) >= $this->bufferSize) {
                $this->writeManifest($buffer, $offset);
                $buffer = [];
            }
        }

        if (!empty($buffer)) {
            $this->writeManifest($buffer, $offset);
            unset($buffer);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        if ($filesProcessed > 0) {
            Log::debug('ScanManifest: Files found, re-dispatching', [
                'type' => $disk,
                'offset' => $offset,
            ]);

            $this->part->offset = $offset;

            dispatch(new CalculateManifestJob($this->part));
        } else {
            $totalItems = $this->part->totalItems;

            Log::debug('ScanManifest: Enumeration complete', [
                'type' => $disk,
                'totalItems' => $totalItems,
            ]);

            if ($totalItems > 0) {
                $this->part->markAs(ManagerScanPartUpdated::STATUS_DONE);
            } else {
                $this->part->markAs(ManagerScanPartUpdated::STATUS_EXCLUDED);
            }
        }
    }
}

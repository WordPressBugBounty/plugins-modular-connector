<?php

namespace Modular\Connector\Security;

use Modular\Connector\Security\Imunify\Events\ManagerScanCancelled;
use Modular\Connector\Security\Imunify\ImunifyDriver;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Manager;
use function Modular\ConnectorDependencies\event;

/**
 * Files and database are independent flows. Every per-flow operation (cancel
 * marker, cache cleanup, abort emission) has a `*Files` / `*Database` variant
 * so failing one flow does not stop the other.
 *
 * The user-initiated full cancel (`cancel()`) marks the scan name on the
 * shared `_cancelled_scan` list, which both `isFilesCancelled()` and
 * `isDatabaseCancelled()` honour. Imunify-side / internal failures use the
 * per-flow methods so the surviving flow keeps running.
 */
class ScanManager extends Manager
{
    /**
     * Fallback for `max_poll_hours` when the API does not send it.
     */
    private const DEFAULT_MAX_POLL_HOURS = 24;

    /**
     * Minimum seconds between two consecutive progress webhooks of a flow, and
     * the cadence of the "still alive" envelopes emitted while a job waits out
     * an Imunify Retry-After. API's stall heuristic fires after 10 minutes
     * without an envelope, so every wait is sliced in ticks of at most this
     * length and each tick refreshes the step.
     */
    public const HEARTBEAT_INTERVAL_SECONDS = 120;

    /**
     * @return string
     */
    public function getDefaultDriver()
    {
        return 'imunify';
    }

    /**
     * @return ImunifyDriver
     */
    public function createImunifyDriver()
    {
        return new ImunifyDriver();
    }

    /**
     * True when the user issued a full cancel for this scan name. Both flows
     * honour this signal — see `isFilesCancelled()` / `isDatabaseCancelled()`.
     *
     * @param string|null $name
     * @return bool
     */
    public function isCancelled($name)
    {
        if (!$name) {
            return false;
        }

        return in_array($name, Cache::get('_cancelled_scan', []), true);
    }

    /**
     * True when the files flow is cancelled — either by a user-initiated full
     * cancel or by an Imunify/internal failure that aborted only the files flow.
     *
     * @param string|null $name
     * @return bool
     */
    public function isFilesCancelled($name)
    {
        if (!$name) {
            return false;
        }

        if ($this->isCancelled($name)) {
            return true;
        }

        return in_array($name, Cache::get('_cancelled_scan_files', []), true);
    }

    /**
     * True when the database flow is cancelled — either by a user-initiated
     * full cancel or by a failure that aborted only the database flow.
     *
     * @param string|null $name
     * @return bool
     */
    public function isDatabaseCancelled($name)
    {
        if (!$name) {
            return false;
        }

        if ($this->isCancelled($name)) {
            return true;
        }

        return in_array($name, Cache::get('_cancelled_scan_database', []), true);
    }

    /**
     * Read the Imunify site_key. Imunify issues a single physical key per
     * site (not per scan) that authenticates every flow — file scans, database
     * scans and every post-scan action. There is therefore one cache slot for
     * the whole site (no `site_scan` suffix), stored on the WordPress driver
     * for durability across requests.
     *
     * Single read point — every job that needs to authenticate to Imunify
     * goes through this method instead of touching the cache directly.
     *
     * @return string|null
     */
    public function siteKey()
    {
        $siteKey = Cache::driver('wordpress')->get('_scan_imunify_site_key');

        return !empty($siteKey) ? (string)$siteKey : null;
    }

    /**
     * Persist the Imunify site_key in cache. Keeps the API key out of the
     * serialized job payload (which is stored in wp_modular_jobs.payload and
     * logged on failure).
     *
     * Global per site (no `site_scan` keying): every new `manager.security.scan`
     * — and every post-scan action — re-stamps the slot, and downstream jobs
     * read it through `siteKey()`. Stored on the WordPress driver with no TTL
     * so it survives the inter-request gap between dispatch (HTTP request that
     * runs the controller) and execution (wp-cron tick that picks up the job
     * from the queue).
     *
     * @param string $siteKey
     * @return void
     */
    public function storeSiteKey($siteKey)
    {
        Cache::driver('wordpress')->forever('_scan_imunify_site_key', (string)$siteKey);
    }

    /**
     * Drop the cached site_key. Called by post-action jobs (quarantine, db
     * cleanup, db restore) once they have authenticated to Imunify and no
     * follow-up call is expected, and by `cancel()` on a full cancel. The next
     * `manager.security.scan` request will re-stamp the slot with a fresh key
     * from API.
     *
     * @return void
     */
    public function forgetSiteKey()
    {
        Cache::driver('wordpress')->forget('_scan_imunify_site_key');
    }

    /**
     * Persist scan-time options that travel from API's `manager.security.scan`
     * payload all the way to the Imunify `/scan/start` (and `/scan/database/start`)
     * call. Kept out of the serialized job payload — these may include sensitive
     * material (`webhook_secret`) and are looked up by `site_scan` from the
     * jobs that actually need them (`SubmitToImunifyJob`, `ScanDbJob`).
     *
     * @param int $siteScan
     * @param array $options Whitelisted to `{force_rescan, webhook_url, webhook_secret, max_poll_hours}`.
     * @return void
     */
    public function storeScanOptions($siteScan, array $options)
    {
        $clean = [];

        if (isset($options['force_rescan'])) {
            $clean['force_rescan'] = (bool)$options['force_rescan'];
        }

        if (!empty($options['webhook_url'])) {
            $clean['webhook_url'] = (string)$options['webhook_url'];
        }

        if (!empty($options['webhook_secret'])) {
            $clean['webhook_secret'] = (string)$options['webhook_secret'];
        }

        // Hard ceiling for the PollImunifyJob re-dispatch chain. API sends this
        // per scan so we can tune behaviour without releasing a new plugin
        // version; positive ints only, fallback applied at the consumer.
        if (isset($options['max_poll_hours']) && (int)$options['max_poll_hours'] > 0) {
            $clean['max_poll_hours'] = (int)$options['max_poll_hours'];
        }

        if (empty($clean)) {
            return;
        }

        // 7-day TTL: long enough that a slow scan can still find these options
        // when it finally submits to Imunify; short enough to self-clean if the
        // terminal cleanup never runs (orphan jobs, queue lost mid-flight).
        Cache::put('_scan_options_' . (int)$siteScan, $clean, Carbon::now()->addDays(7));
    }

    /**
     * Read the stored scan options. Always returns an array (empty when no
     * options were persisted) so callers can spread it directly into the
     * Imunify client call without `isset` dance.
     *
     * @param int $siteScan
     * @return array
     */
    public function scanOptions($siteScan)
    {
        $stored = Cache::get('_scan_options_' . (int)$siteScan);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Absolute Unix timestamp after which a flow stops waiting on Imunify and
     * cancels with `poll_timeout`. Computed once per flow from the stored
     * `max_poll_hours` option (24 h fallback) and carried by the jobs across
     * every re-dispatch — busy retries included.
     *
     * @param int $siteScan
     * @return int
     */
    public function pollDeadlineAt($siteScan)
    {
        $options = $this->scanOptions($siteScan);
        $hours = isset($options['max_poll_hours']) && (int)$options['max_poll_hours'] > 0
            ? (int)$options['max_poll_hours']
            : self::DEFAULT_MAX_POLL_HOURS;

        return Carbon::now()->timestamp + ($hours * 3600);
    }

    /**
     * Cache key of the per-file upload failure counters kept by PollImunifyJob
     * (file_id => attempts). Lives here so the cleanup can forget it.
     *
     * @param int $siteScan
     * @return string
     */
    public function uploadFailuresKey($siteScan)
    {
        return '_scan_upload_failures_' . (int)$siteScan;
    }

    /**
     * User-initiated full cancel: mark the scan name on `_cancelled_scan` so
     * in-flight jobs of **both** flows exit early, and (when `$siteScan` is
     * known) wipe every per-scan cache key. No event is emitted — the UI that
     * triggered the cancel already knows.
     *
     * @param string|null $name
     * @param int|null $siteScan
     * @return void
     */
    public function cancel($name, $siteScan = null)
    {
        if (!$name) {
            return;
        }

        $value = Cache::get('_cancelled_scan', []);
        $value[] = $name;

        Cache::put('_cancelled_scan', array_unique($value), Carbon::now()->addDay());

        if ($siteScan !== null) {
            $this->forgetFilesCache((int)$siteScan);
            $this->forgetDatabaseCache((int)$siteScan);
        }

        // The per-flow cleanups intentionally leave the shared site_key in
        // place (post-actions need it). A full cancel is terminal — no
        // post-action will run, so drop it now. The site_key is global, so
        // this runs even when $siteScan is unknown.
        $this->forgetSiteKey();
    }

    /**
     * Cancel only the files flow — emits `ManagerScanCancelled::forFiles(...)`
     * so API can mark this flow terminal, and adds the scan name to the
     * per-flow cancel list so straggler files jobs exit via `isFilesCancelled()`.
     *
     * Cache cleanup is delegated to `ScanCleanupListener` reacting to the event.
     *
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param string $error Short tag or message included in the event payload.
     * @param array $details Optional structured context (e.g. the quota block on
     *                       `quota_exceeded`) forwarded under `details`.
     * @return void
     */
    public function cancelFiles($mrid, $name, $siteScan, $error, array $details = [])
    {
        $this->markFlowCancelled('_cancelled_scan_files', $name);

        event(ManagerScanCancelled::forFiles($mrid, (int)$siteScan, $error, $details));
    }

    /**
     * Cancel only the database flow. Same shape as `cancelFiles()`.
     *
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param string $error
     * @param array $details
     * @return void
     */
    public function cancelDatabase($mrid, $name, $siteScan, $error, array $details = [])
    {
        $this->markFlowCancelled('_cancelled_scan_database', $name);

        event(ManagerScanCancelled::forDatabase($mrid, (int)$siteScan, $error, $details));
    }

    /**
     * Mark the files flow as active for this scan. Used by the other flow's
     * cleanup as a "the other flow still needs the shared options" check —
     * see `forgetDatabaseCache()`. Written at dispatch time by
     * `ScanPartsCalculatedListener`, wiped by `forgetFilesCache()`.
     *
     * @param int $siteScan
     * @return void
     */
    public function markFilesActive($siteScan)
    {
        Cache::put('_scan_files_active_' . (int)$siteScan, 1, Carbon::now()->addDays(7));
    }

    /**
     * Mark the database flow as active. Mirror of `markFilesActive()`.
     *
     * @param int $siteScan
     * @return void
     */
    public function markDatabaseActive($siteScan)
    {
        Cache::put('_scan_db_active_' . (int)$siteScan, 1, Carbon::now()->addDays(7));
    }

    /**
     * Wipe every cache key scoped to the files flow. Called by `ScanCleanupListener`
     * on `ManagerScanCancelled` / `ManagerScanCompleted` with `type=files`.
     *
     * @param int $siteScan
     * @return void
     */
    public function forgetFilesCache($siteScan)
    {
        Cache::forget('_scan_imunify_job_' . (int)$siteScan);
        Cache::forget(sprintf('_scan_parts_status_%s', (int)$siteScan));
        Cache::forget(sprintf('_scan_parts_expected_%s', (int)$siteScan));
        Cache::forget('_scan_files_active_' . (int)$siteScan);
        Cache::forget($this->uploadFailuresKey($siteScan));

        // `_scan_options_` is shared with the DB flow (SubmitToImunifyJob reads
        // it; ScanDbJob::ensureScanStarted reads it too). Only wipe when the
        // other flow is no longer active — otherwise a flow that finishes first
        // takes the options out from under the survivor.
        if (!Cache::has('_scan_db_active_' . (int)$siteScan)) {
            Cache::forget('_scan_options_' . (int)$siteScan);
        }
    }

    /**
     * Wipe every cache key scoped to the database flow.
     *
     * @param int $siteScan
     * @return void
     */
    public function forgetDatabaseCache($siteScan)
    {
        Cache::forget('_scan_db_imunify_job_' . (int)$siteScan);
        Cache::forget('_scan_db_imunify_job_started_' . (int)$siteScan);
        Cache::forget('_scan_db_scan_config_' . (int)$siteScan);
        Cache::forget('_scan_db_tables_' . (int)$siteScan);
        Cache::forget('_scan_db_active_' . (int)$siteScan);

        if (!Cache::has('_scan_files_active_' . (int)$siteScan)) {
            Cache::forget('_scan_options_' . (int)$siteScan);
        }
    }

    /**
     * @param string $cacheKey
     * @param string $name
     * @return void
     */
    private function markFlowCancelled($cacheKey, $name)
    {
        $value = Cache::get($cacheKey, []);
        $value[] = $name;

        Cache::put($cacheKey, array_unique($value), Carbon::now()->addDay());
    }
}

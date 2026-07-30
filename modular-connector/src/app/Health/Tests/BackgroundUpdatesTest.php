<?php

namespace Modular\Connector\Health\Tests;

use Modular\Connector\Health\AbstractHealthTest;

class BackgroundUpdatesTest extends AbstractHealthTest
{
    /**
     * @return string
     */
    public function identifier()
    {
        return 'background_updates';
    }

    /**
     * @return array
     */
    public function run()
    {
        // wp-admin/includes is not autoloaded in cron / REST contexts.
        if (!class_exists('WP_Site_Health_Auto_Updates')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health-auto-updates.php';
        }

        // Core's test methods instantiate Automatic_Upgrader_Skin and
        // Core_Upgrader, which fatal without these requires outside admin.
        $this->ensureUpgraderClassesLoaded();

        // The Bootloader (SetupAdminEnvironment) suppresses auto-updates on
        // every direct request so WordPress never updates itself mid-backup.
        // WP_Site_Health_Auto_Updates inspects those very filters, so we lift
        // them while probing — otherwise the site always reports auto-updates
        // as disabled regardless of its real configuration.
        $results = $this->withoutBootloaderAutoUpdateOverrides(function () {
            return $this->runCoreChecks();
        });

        $checks = [];
        $worstStatus = 'good';

        foreach ($results as $result) {
            $status = $this->severityToStatus($result['severity']);

            if ($status === 'critical') {
                $worstStatus = 'critical';
            } elseif ($status === 'recommended' && $worstStatus !== 'critical') {
                $worstStatus = 'recommended';
            }

            // Emit a stable identifier (+ sanitized params) instead of core's
            // server-localized description: the API renders the message in its
            // own locale and no absolute filesystem paths ever leave the site.
            $check = ['key' => $result['key'], 'status' => $status];

            if (!empty($result['params'])) {
                $check['params'] = $result['params'];
            }

            $checks[] = $check;
        }

        return $worstStatus === 'good'
            ? $this->result('good')
            : $this->result($worstStatus, ['checks' => $checks]);
    }

    /**
     * Invoke WordPress core's auto-update probes one by one, labelling each
     * result with a stable identifier the API can localize. Mirrors
     * WP_Site_Health_Auto_Updates::run_tests() — same methods, same order, same
     * false/null filtering — but never returns core's rendered description,
     * which is server-localized and embeds absolute filesystem paths.
     *
     * @return array
     */
    private function runCoreChecks()
    {
        $autoUpdates = new \WP_Site_Health_Auto_Updates();

        $probes = [
            ['key' => 'wp_auto_update_core_constant', 'method' => 'test_constants', 'args' => ['WP_AUTO_UPDATE_CORE', [true, 'beta', 'rc', 'development', 'branch-development', 'minor']]],
            ['key' => 'wp_version_check_filter', 'method' => 'test_wp_version_check_attached'],
            ['key' => 'automatic_updater_disabled_filter', 'method' => 'test_filters_automatic_updater_disabled'],
            ['key' => 'automatic_updates_disabled', 'method' => 'test_wp_automatic_updates_disabled'],
            ['key' => 'previous_update_failed', 'method' => 'test_if_failed_update'],
            ['key' => 'vcs_checkout', 'method' => 'test_vcs_abspath'],
            ['key' => 'filesystem_credentials', 'method' => 'test_check_wp_filesystem_method'],
            ['key' => 'files_writable', 'method' => 'test_all_files_writable'],
            ['key' => 'accepts_dev_updates', 'method' => 'test_accepts_dev_updates'],
            ['key' => 'accepts_minor_updates', 'method' => 'test_accepts_minor_updates'],
        ];

        $results = [];

        foreach ($probes as $probe) {
            if (!method_exists($autoUpdates, $probe['method'])) {
                continue;
            }

            $raw = call_user_func_array(
                [$autoUpdates, $probe['method']],
                isset($probe['args']) ? $probe['args'] : []
            );

            // Core returns array|false|null; false/null is the "nothing to
            // report" path that run_tests() array_filters away — do the same.
            if ($raw === false || $raw === null) {
                continue;
            }

            $raw = (object)$raw;
            $severity = !empty($raw->severity) ? $raw->severity : 'warning';

            $results[] = [
                'key' => $probe['key'],
                'severity' => $severity,
                'params' => $severity === 'pass' ? null : $this->paramsFor($probe['key']),
            ];
        }

        return $results;
    }

    /**
     * Map a WP_Site_Health_Auto_Updates severity (fail / warning / info / pass)
     * to our status enum. Anything non-fatal collapses to "good".
     *
     * @param string $severity
     * @return string
     */
    private function severityToStatus($severity)
    {
        if ($severity === 'fail') {
            return 'critical';
        }

        if ($severity === 'warning') {
            return 'recommended';
        }

        return 'good';
    }

    /**
     * Build the sanitized parameter bag for a check so the API can render a
     * complete localized message without ever receiving an absolute path.
     * Returns null for checks that carry no dynamic data.
     *
     * @param string $key
     * @return array|null
     */
    private function paramsFor($key)
    {
        if ($key === 'vcs_checkout') {
            $vcsDir = $this->detectVcsDir();

            return $vcsDir !== null ? ['vcs_dir' => $vcsDir] : null;
        }

        if ($key === 'previous_update_failed') {
            $failed = get_site_option('auto_core_update_failed');

            if (!is_array($failed)) {
                return null;
            }

            $params = ['critical' => !empty($failed['critical'])];

            if (!empty($failed['error_code'])) {
                $params['error_code'] = (string)$failed['error_code'];
            }

            return $params;
        }

        return null;
    }

    /**
     * Which version-control marker sits at or above ABSPATH, if any. Mirrors
     * the walk in WP_Site_Health_Auto_Updates::test_vcs_abspath() but returns
     * only the marker name (e.g. ".git") so the API can name the SCM without
     * the absolute directory that contains it ever leaving the site.
     *
     * @return string|null
     */
    private function detectVcsDir()
    {
        $markers = ['.svn', '.git', '.hg', '.bzr'];
        $dir = ABSPATH;
        $previous = null;

        while ($dir !== $previous) {
            foreach ($markers as $marker) {
                if (is_dir(rtrim($dir, '\\/') . '/' . $marker)) {
                    return $marker;
                }
            }

            $previous = $dir;
            $dir = dirname($dir);
        }

        return null;
    }

    /**
     * @return void
     */
    private function ensureUpgraderClassesLoaded()
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    /**
     * Run $callback with the Bootloader's auto-update suppression filters
     * lifted, then restore them exactly as they were.
     *
     * SetupAdminEnvironment::disableWordPressAutoActions() adds these on every
     * direct request so WordPress never auto-updates mid-backup. Only filters
     * we actually added are removed and restored, so this is a no-op when the
     * test runs outside a direct request (e.g. native wp-cron). The genuine
     * signal is preserved: a real AUTOMATIC_UPDATER_DISABLED constant,
     * DISALLOW_FILE_MODS, or a third-party filter still surfaces normally.
     *
     * @param callable $callback
     * @return mixed
     */
    private function withoutBootloaderAutoUpdateOverrides(callable $callback)
    {
        $overrides = [
            ['automatic_updater_disabled', '__return_true'],
            ['auto_update_core', '__return_false'],
            ['auto_update_translation', '__return_false'],
            ['auto_update_theme', '__return_false'],
            ['auto_update_plugin', '__return_false'],
        ];

        $removed = [];

        foreach ($overrides as $override) {
            [$hook, $callbackName] = $override;
            $priority = has_filter($hook, $callbackName);

            // has_filter() returns the priority (0 is valid) or false.
            if ($priority !== false) {
                remove_filter($hook, $callbackName, $priority);
                $removed[] = [$hook, $callbackName, $priority];
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($removed as $override) {
                [$hook, $callbackName, $priority] = $override;
                add_filter($hook, $callbackName, $priority);
            }
        }
    }
}

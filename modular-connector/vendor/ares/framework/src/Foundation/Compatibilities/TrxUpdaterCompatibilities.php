<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * ThemeREX Updater (trx_updater) update-detection bridge.
 *
 * ThemeREX themes (Qwery, etc.) manage updates for the theme and its bundled
 * plugins (trx_addons, ...) through a fully custom system: versions are
 * fetched from upgrade.themerex.net into its own transients and updates run
 * through TGMPA, so the WordPress update transients are never populated
 * during a check and standard update detection sees nothing.
 *
 * The bridge has two pieces:
 * 1. Force TGMPA to load outside wp-admin (filter `tgmpa_load`) so ThemeREX's
 *    own code refreshes its version caches (`trx_updater_plugins_list` /
 *    `trx_updater_theme_info` transients) during our requests.
 * 2. Merge those caches into the standard update transients via
 *    `pre_set_site_transient_update_{plugins,themes}` so detection AND
 *    updates flow through the standard WordPress upgrader (their
 *    backup/restore hooks for trx_addons addons and theme skins hang on
 *    standard upgrader hooks and keep working).
 *
 * Package URLs are resolved with minimal coupling to ThemeREX internals:
 * 1. Primary: the TGMPA `source` URL that trx_updater itself rewrites on
 *    tgmpa_register — built 100% by their code, always current format.
 * 2. Derived: for items without a rewritten source (trx_addons is excluded
 *    from their rewrite; the theme URL is only built inside their
 *    update_theme() handler), the base URL and common params (src, key,
 *    theme_slug, theme_name, domain) are extracted from a sibling rewritten
 *    source, so server or credential format changes are absorbed.
 * 3. Fallback: full reconstruction from the same options ThemeREX reads,
 *    guarded by a drift canary that logs a warning whenever our
 *    reconstruction diverges from a URL actually built by their code.
 *
 * Theme skins are OUT OF SCOPE: they are theme subfolders, not WordPress
 * entities. The theme package itself ships the active skin (`skin` param).
 *
 * Everything is gated on the theme purchase key: without an activated theme
 * there is no key, no package URL is built and no update is reported.
 */
class TrxUpdaterCompatibilities
{
    /**
     * ThemeREX upgrade server (same value as \TrxUpdater\Core\Update\Base).
     */
    const UPDATE_URL = 'http://upgrade.themerex.net/upgrade.php';
    /**
     * Indicates if the bridge has already been registered.
     */
    private static bool $registered = \false;
    /**
     * Indicates if the URL-format drift canary has already run this request.
     */
    private static bool $driftChecked = \false;
    /**
     * Register the ThemeREX bridge.
     *
     * Must be called BEFORE the `init` hook fires: TGMPA checks the
     * `tgmpa_load` filter on init, and `tgmpa_register` (where trx_updater
     * refreshes its version transients) fires from there.
     *
     * @return void
     */
    public static function register(): void
    {
        if (static::$registered || !defined('TRX_UPDATER_VERSION')) {
            return;
        }
        static::$registered = \true;
        // TGMPA only loads when is_admin() && !DOING_AJAX
        add_filter('tgmpa_load', '__return_true');
        add_filter('pre_set_site_transient_update_plugins', [static::class, 'injectPluginUpdates'], 20);
        add_filter('pre_set_site_transient_update_themes', [static::class, 'injectThemeUpdates'], 20);
        Log::debug('TrxUpdaterCompatibilities: ThemeREX update bridge registered');
    }
    /**
     * Merge trx_updater's cached plugin versions into the update transient.
     *
     * Reads the `trx_updater_plugins_list` transient (populated by
     * \TrxUpdater\Core\Update\Plugins::tgmpa_register(), 4h TTL) with shape:
     * slug => ['title', 'slug', 'path', 'version', 'update'].
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function injectPluginUpdates($transient)
    {
        try {
            $list = get_transient('trx_updater_plugins_list');
            if (!is_array($list) || empty($list)) {
                return $transient;
            }
            if (!is_object($transient)) {
                $transient = new \stdClass();
            }
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            foreach ($list as $slug => $data) {
                $path = !empty($data['path']) ? $data['path'] : sprintf('%1$s/%1$s.php', $slug);
                if (isset($transient->response[$path]) || empty($data['update']) || empty($data['version']) || !version_compare($data['update'], $data['version'], '>')) {
                    continue;
                }
                $package = static::resolvePluginPackage($slug);
                if (empty($package)) {
                    continue;
                }
                $transient->response[$path] = (object) ['slug' => $slug, 'plugin' => $path, 'new_version' => $data['update'], 'package' => $package, 'url' => ''];
            }
        } catch (\Throwable $e) {
            Log::warning('TrxUpdaterCompatibilities: plugin bridge failed', ['error' => $e->getMessage()]);
        }
        return $transient;
    }
    /**
     * Merge trx_updater's cached theme version into the update transient.
     *
     * Reads the `trx_updater_theme_info` transient (populated by
     * \TrxUpdater\Core\Update\Themes::get_theme_info(), 2 days TTL) with
     * shape: template => ['version', 'update_from', 'attention'].
     *
     * @param mixed $transient
     * @return mixed
     */
    public static function injectThemeUpdates($transient)
    {
        try {
            $template = (string) get_option('template');
            $info = get_transient('trx_updater_theme_info');
            if (empty($template) || !is_array($info) || empty($info[$template]['version'])) {
                return $transient;
            }
            $theme = wp_get_theme($template);
            if (!$theme->exists()) {
                return $transient;
            }
            $current = (string) $theme->get('Version');
            $new = (string) $info[$template]['version'];
            if (!version_compare($new, $current, '>') || !empty($info[$template]['update_from']) && version_compare($current, $info[$template]['update_from'], '<')) {
                return $transient;
            }
            if (!is_object($transient)) {
                $transient = new \stdClass();
            }
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }
            if (isset($transient->response[$template])) {
                return $transient;
            }
            // The 'skin' param mirrors Themes::update_theme(): the theme
            // package ships the active skin for multiskin themes.
            $package = static::buildPackageUrl('install_theme', ['skin' => (string) get_option(sprintf('theme_skin_%s', get_option('stylesheet')), '')]);
            if (empty($package)) {
                return $transient;
            }
            // Theme entries are arrays (WordPress convention), not objects
            $transient->response[$template] = ['theme' => $template, 'new_version' => $new, 'package' => $package, 'url' => '', 'requires' => '4.7.0', 'requires_php' => '5.6.0'];
        } catch (\Throwable $e) {
            Log::warning('TrxUpdaterCompatibilities: theme bridge failed', ['error' => $e->getMessage()]);
        }
        return $transient;
    }
    /**
     * Resolve the package URL for a plugin.
     *
     * Primary source: the TGMPA `source` rewritten by trx_updater itself on
     * tgmpa_register — zero coupling to their URL format. Falls back to
     * building the URL for plugins excluded from their rewrite (trx_addons).
     *
     * @param string $slug
     * @return string Empty when no package URL can be resolved
     */
    private static function resolvePluginPackage(string $slug): string
    {
        $source = static::tgmpaSource($slug);
        if (static::isRewrittenSource($source)) {
            static::checkUrlDrift($slug, $source);
            return $source;
        }
        return static::buildPackageUrl('install_plugin', ['plugin' => sprintf('%1$s/%1$s.zip', $slug)]);
    }
    /**
     * Get the TGMPA source registered for a plugin slug.
     *
     * @param string $slug
     * @return string
     */
    private static function tgmpaSource(string $slug): string
    {
        if (empty($GLOBALS['tgmpa']) || !is_object($GLOBALS['tgmpa']) || empty($GLOBALS['tgmpa']->plugins[$slug]['source'])) {
            return '';
        }
        $source = $GLOBALS['tgmpa']->plugins[$slug]['source'];
        return is_string($source) ? $source : '';
    }
    /**
     * Whether a TGMPA source is a URL rewritten by trx_updater to its
     * upgrade server. Detected by shape (install_plugin action + key param)
     * instead of by host, so a server URL change doesn't break detection.
     *
     * @param string $source
     * @return bool
     */
    private static function isRewrittenSource(string $source): bool
    {
        return strpos($source, 'action=install_plugin') !== \false && strpos($source, 'key=') !== \false;
    }
    /**
     * Extract the upgrade-server base URL and common query params (src, key,
     * theme_slug, theme_name, domain) from any TGMPA source rewritten by
     * trx_updater. Lets us compose the theme / trx_addons URLs from values
     * their own code produced.
     *
     * @return array|null ['base' => string, 'query' => array] or null
     */
    private static function upgradeServerTemplate(): ?array
    {
        if (empty($GLOBALS['tgmpa']) || !is_object($GLOBALS['tgmpa']) || empty($GLOBALS['tgmpa']->plugins)) {
            return null;
        }
        foreach ($GLOBALS['tgmpa']->plugins as $plugin) {
            $source = isset($plugin['source']) && is_string($plugin['source']) ? $plugin['source'] : '';
            if (!static::isRewrittenSource($source)) {
                continue;
            }
            $parts = explode('?', $source, 2);
            if (count($parts) !== 2) {
                continue;
            }
            parse_str($parts[1], $query);
            if (empty($query['key'])) {
                continue;
            }
            return ['base' => $parts[0], 'query' => $query];
        }
        return null;
    }
    /**
     * Build a ThemeREX upgrade-server package URL.
     *
     * Prefers composing from a sibling rewritten TGMPA source (base URL and
     * common params produced by their code); reconstructs everything from
     * options only as a last resort.
     *
     * @param string $action 'install_plugin' or 'install_theme'
     * @param array $extra Extra params ('plugin' => basename zip, 'skin' => slug)
     * @return string Empty when the theme has no purchase key
     */
    private static function buildPackageUrl(string $action, array $extra = []): string
    {
        $template = static::upgradeServerTemplate();
        if ($template !== null) {
            $args = $template['query'];
            $args['action'] = $action;
            unset($args['plugin'], $args['skin'], $args['rnd']);
            foreach ($extra as $name => $value) {
                $args[$name] = $value;
            }
            $args['rnd'] = mt_rand();
            return static::composeUrl($template['base'], $args);
        }
        return static::buildPackageUrlFromOptions($action, $extra);
    }
    /**
     * Reconstruct a package URL from the same options ThemeREX reads.
     *
     * Mirrors the URL construction in \TrxUpdater\Core\Update\Plugins
     * (tgmpa_register) and \TrxUpdater\Core\Update\Themes (update_theme):
     * action, src, key, theme_slug, theme_name, [plugin|skin], domain, rnd.
     *
     * @param string $action 'install_plugin' or 'install_theme'
     * @param array $extra Extra params ('plugin' => basename zip, 'skin' => slug)
     * @return string Empty when the theme has no purchase key
     */
    private static function buildPackageUrlFromOptions(string $action, array $extra = []): string
    {
        $key = static::themeKey();
        if (empty($key)) {
            return '';
        }
        $template = (string) get_option('template');
        $theme = wp_get_theme($template);
        $args = array_merge([
            'action' => $action,
            'src' => static::themeMarketCode($template),
            'key' => $key,
            // Same filter ThemeREX applies to support renamed theme folders
            'theme_slug' => (string) apply_filters('trx_updater_filter_original_theme_slug', $template),
            'theme_name' => (string) $theme->get('Name'),
        ], $extra, [
            // Their server expects the domain without protocol
            'domain' => preg_replace('#^https?://#', '', get_home_url()),
            'rnd' => mt_rand(),
        ]);
        return static::composeUrl(static::UPDATE_URL, $args);
    }
    /**
     * Compose a URL with urlencoded params, preserving param order.
     *
     * @param string $base
     * @param array $args
     * @return string
     */
    private static function composeUrl(string $base, array $args): string
    {
        $query = [];
        foreach ($args as $name => $value) {
            $query[] = $name . '=' . urlencode((string) $value);
        }
        return $base . '?' . implode('&', $query);
    }
    /**
     * Drift canary: compare our options-based reconstruction against a URL
     * actually built by trx_updater. A divergence means ThemeREX changed
     * their URL format and the fallback builder needs a review — logged as
     * a warning so it surfaces before updates break.
     *
     * Runs at most once per request, only when a rewritten source exists.
     *
     * @param string $slug
     * @param string $theirUrl
     * @return void
     */
    private static function checkUrlDrift(string $slug, string $theirUrl): void
    {
        if (static::$driftChecked) {
            return;
        }
        static::$driftChecked = \true;
        $ours = static::buildPackageUrlFromOptions('install_plugin', ['plugin' => sprintf('%1$s/%1$s.zip', $slug)]);
        if (empty($ours)) {
            return;
        }
        if (static::normalizeForComparison($ours) !== static::normalizeForComparison($theirUrl)) {
            Log::warning('TrxUpdaterCompatibilities: package URL format drift detected', ['theirs' => static::maskKey($theirUrl), 'ours' => static::maskKey($ours)]);
        }
    }
    /**
     * Canonicalize a URL for drift comparison: drop the random param and
     * sort the query so only structural differences count.
     *
     * @param string $url
     * @return string
     */
    private static function normalizeForComparison(string $url): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) !== 2) {
            return $url;
        }
        parse_str($parts[1], $query);
        unset($query['rnd']);
        ksort($query);
        return $parts[0] . '?' . http_build_query($query);
    }
    /**
     * Mask the purchase key before logging a package URL.
     *
     * @param string $url
     * @return string
     */
    private static function maskKey(string $url): string
    {
        return preg_replace('#(key=)[^&]*#', '$1***', $url);
    }
    /**
     * Resolve the theme purchase key.
     *
     * Mirrors \TrxUpdater\Core\Update\Base::get_theme_activation_code():
     * trx_updater's own option first, then the activation options written by
     * trx_addons / the theme activation flow.
     *
     * @return string
     */
    private static function themeKey(): string
    {
        $template = (string) get_option('template');
        $key = get_option('trx_updater_theme_key', '');
        if (empty($key)) {
            $key = apply_filters('trx_updater_filter_theme_purchase_key', get_option(sprintf('trx_addons_theme_%s_activated', $template)) == 1 ? get_option(sprintf('purchase_code_%s', $template)) : get_option(sprintf('%s_theme_code_activation', $template)));
        }
        return is_string($key) ? $key : '';
    }
    /**
     * Resolve the theme market code ('src' param).
     *
     * Mirrors \TrxUpdater\Core\Update\Base::get_theme_market_code().
     *
     * @param string $template
     * @return string
     */
    private static function themeMarketCode(string $template): string
    {
        $code = get_option(sprintf('purchase_code_src_%s', $template));
        if (empty($code)) {
            $code = apply_filters('trx_addons_filter_get_theme_data', '', 'theme_pro_key');
        }
        if (empty($code)) {
            $info = apply_filters('trx_addons_filter_get_theme_info', ['theme_pro_key' => '']);
            if (!empty($info['theme_pro_key'])) {
                $code = $info['theme_pro_key'];
            }
        }
        return empty($code) ? '*' : (string) $code;
    }
}

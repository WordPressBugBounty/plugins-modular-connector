<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Cookie;
/**
 * Login-specific compatibility fixes.
 *
 * These fixes bypass security plugins that would block Modular's authenticated login.
 * They are ONLY applied during the login flow, not for all requests.
 */
class LoginCompatibilities
{
    /**
     * Indicates if beforeLogin has already been called.
     */
    private static bool $beforeLoginApplied = \false;
    /**
     * Apply compatibility fixes BEFORE login.
     *
     * These disable security plugin checks that would block the login.
     * Must be called before wp_set_current_user().
     */
    public static function beforeLogin(): void
    {
        if (static::$beforeLoginApplied) {
            return;
        }
        static::$beforeLoginApplied = \true;
        Log::debug('LoginCompatibilities: Applying security plugin');
        // Shield Security - Create dummy 2FA class to prevent checks
        static::fixWpSimpleFirewall();
        // Shield Security - Mark visitor as whitelisted
        static::fixShieldUserManagement();
        // Login LockDown - Disable login blocking
        static::fixLoginLockdown();
        // Duo Two-Factor - Remove verification
        static::fixDuoFactor();
        // WP 2FA - Bypass 2FA enforcement
        static::fixWp2Fa();
        // WPO365 - Disable SSO authentication redirect
        static::fixWpo365Login();
        // Post SMTP - Bypass Office365 OAuth handling
        static::fixOffice365forPostSMTPExtension();
    }
    /**
     * @return void
     */
    public static function fixWpo365Login()
    {
        if (!class_exists('\Wpo\Services\Authentication_Service')) {
            return;
        }
        Log::debug('LoginCompatibilities: Disabling Wpo365 SSO authentication');
        // Disables possible redirect caused by Wpo365's SSO options
        remove_action('init', '\Wpo\Services\Authentication_Service::authenticate_request', 1);
    }
    public static function fixOffice365forPostSMTPExtension()
    {
        if (!class_exists('Post_Smtp_Office365')) {
            return;
        }
        Log::debug('LoginCompatibilities: Disabling Post SMTP Office365 OAuth handling');
        // Disabled "Authorization code should be in the "code" query param"
        Compatibilities::removeFilterByClassName('post_smtp_handle_oauth', \Post_Smtp_Office365::class, 'handle_oauth');
    }
    /**
     * Apply compatibility fixes AFTER login.
     *
     * These update plugin metadata to prevent issues after login.
     * Must be called after wp_set_current_user().
     *
     * @param int $userId The logged-in user ID
     */
    public static function afterLogin(int $userId): void
    {
        Log::debug('LoginCompatibilities: Applying post-login fixes', ['user_id' => $userId]);
        // All In One Security - Update last login time
        static::fixAllInOneSecurity($userId);
        // WP 2FA - Set grace period for the user
        static::fixWp2FaGracePeriod($userId);
    }
    /**
     * Get hosting-specific cookies required for filesystem operations.
     *
     * NOTE: WordPress authentication cookies (AUTH_COOKIE, LOGGED_IN_COOKIE) are NOT
     * needed in $_COOKIE because WordPress's is_user_logged_in() checks $GLOBALS['current_user']
     * which is set by wp_set_current_user(), not by cookies.
     *
     * @param bool $isSecure Whether the connection is secure (HTTPS)
     * @return array<Cookie>
     */
    public static function hostingCookies(bool $isSecure): array
    {
        $cookies = [];
        // WP Engine verifies this cookie independently for filesystem write access
        // This is separate from WordPress's user authentication system
        if (defined('WPE_APIKEY')) {
            $value = hash('sha256', 'wpe_auth_salty_dog|' . \WPE_APIKEY);
            $cookies[] = new Cookie('wpe-auth', $value, 0, '/', '', $isSecure, \true);
            Log::debug('HostingCompatibilities: Added WPEngine auth cookie for filesystem access');
        }
        return $cookies;
    }
    /**
     * Shield Security (WP Simple Firewall) - Create dummy 2FA class.
     */
    private static function fixWpSimpleFirewall(): void
    {
        if (!class_exists('ICWP_WPSF_Shield_Security')) {
            return;
        }
        if (!class_exists('ICWP_WPSF_Processor_LoginProtect_TwoFactorAuth', \false)) {
            require_once __DIR__ . '/Stubs/ShieldTwoFactorAuthStub.php';
            Log::debug('LoginCompatibilities: Created dummy Shield 2FA class');
        }
    }
    /**
     * Shield Security - Mark visitor as whitelisted.
     */
    private static function fixShieldUserManagement(): void
    {
        add_filter('icwp-wpsf-visitor_is_whitelisted', '__return_true');
        Log::debug('LoginCompatibilities: Shield visitor whitelisted');
    }
    /**
     * Login LockDown - Disable login blocking.
     *
     * Must be called BEFORE plugins_loaded so this action gets registered
     * and executes after the plugin registers its init action.
     */
    private static function fixLoginLockdown(): void
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('loginlockdown')) {
                return;
            }
            remove_action('init', 'loginlockdown::init', -1);
            Log::debug('LoginCompatibilities: Login LockDown disabled');
        });
    }
    /**
     * Duo Two-Factor - Remove verification action.
     *
     * Must be called BEFORE plugins_loaded so this action gets registered
     * and can remove duo_verify_auth after Duo registers it.
     */
    private static function fixDuoFactor(): void
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('Duo')) {
                return;
            }
            add_action('init', function () {
                remove_action('init', 'duo_verify_auth', 10);
                Log::debug('LoginCompatibilities: Duo verification disabled');
            }, -1);
        });
    }
    /**
     * WP 2FA - Remove enforcement filter.
     *
     * Must be called BEFORE plugins_loaded so this action gets registered
     * and can remove WP2FA's block after it registers.
     */
    private static function fixWp2Fa(): void
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('\WP2FA\WP2FA')) {
                return;
            }
            Compatibilities::removeFilterByClassName('init', \WP2FA\WP2FA::class, 'block_unconfigured_users_from_admin');
            Log::debug('LoginCompatibilities: WP 2FA enforcement disabled');
        }, \PHP_INT_MAX);
    }
    /**
     * WP 2FA - Set grace period for user after login.
     *
     * @param int $userId
     */
    private static function fixWp2FaGracePeriod(int $userId): void
    {
        if (!class_exists('\WP2FA\WP2FA') || !defined('WP_2FA_PREFIX')) {
            return;
        }
        update_user_meta($userId, \WP_2FA_PREFIX . 'enforcement_state', \true);
        update_user_meta($userId, \WP_2FA_PREFIX . 'grace_period_expiry', Carbon::now()->addMinutes(30)->timestamp);
        Log::debug('LoginCompatibilities: WP 2FA grace period set', ['user_id' => $userId]);
    }
    /**
     * All In One Security - Update last login time.
     *
     * @param int $userId
     */
    private static function fixAllInOneSecurity(int $userId): void
    {
        if (!class_exists('AIO_WP_Security')) {
            return;
        }
        update_user_meta($userId, 'aiowps_last_login_time', Carbon::now()->format('Y-m-d H:i:s'));
        Log::debug('LoginCompatibilities: AIOS last login time updated', ['user_id' => $userId]);
    }
}

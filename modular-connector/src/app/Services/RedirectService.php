<?php

namespace Modular\Connector\Services;

use Modular\Connector\Cache\Jobs\CacheClearJob;
use Modular\Connector\Models\Redirect;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Schema;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;

class RedirectService
{
    private const CACHE_KEY = 'broken_links_redirects';
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * Maximum rows per UPSERT batch. ~2.3 KB per row (source_pattern up to 2000
     * chars dominates), so 500 keeps each statement under ~1.2 MB and well below
     * MySQL's default 4 MB max_allowed_packet on shared hosting.
     */
    private const UPSERT_CHUNK_SIZE = 500;

    private const ALLOWED_REDIRECT_TYPES = [301, 302, 307, 308];
    private const ALLOWED_DESTINATION_SCHEMES = ['http', 'https'];
    private const MAX_SOURCE_PATTERN_LEN = 2000;
    private const MAX_REGEX_PATTERN_LEN = 500;

    /**
     * Custom regex delimiter (SOH, ASCII 0x01). URLs and user-authored regex source
     * patterns cannot contain this byte unencoded, so it is safe against the
     * "user pattern contains the delimiter" silent-miss class of bug.
     */
    private const REGEX_DELIMITER = "\x01";

    private const PROTECTED_PATHS = [
        '/wp-admin/',
        '/wp-login.php',
        '/wp-json/',
        '/xmlrpc.php',
        '/wp-cron.php',
    ];

    /**
     * Hooked to template_redirect at priority 10. Uses wp_redirect()/exit
     * because we are inside the WordPress request lifecycle, not Laravel's
     * router — abort()-style exceptions do not unwind cleanly through hooks.
     */
    public static function handleRedirect(): void
    {
        // Skip on cron and on Modular's own direct API traffic — those requests are
        // not real visitors and have their own routing pipeline inside the connector.
        // The admin/AJAX checks below remain as defense-in-depth for the standard
        // WordPress lifecycle.
        if (HttpUtils::isCron() || HttpUtils::isDirectRequest()) {
            return;
        }

        if (\is_admin() || \wp_doing_ajax()) {
            return;
        }

        $requestPath = static::currentRequestPath();

        if ($requestPath === null || static::isProtectedPath($requestPath)) {
            return;
        }

        $rules = static::loadRules();

        if (empty($rules['exact']) && empty($rules['regex'])) {
            return;
        }

        $normalizedPath = static::normalizePath($requestPath);

        if (isset($rules['exact'][$normalizedPath])) {
            $match = $rules['exact'][$normalizedPath];

            static::emitRedirect($match['destination_url'], (int)$match['type'], $normalizedPath);
        }

        foreach ($rules['regex'] as $rule) {
            $destination = static::matchRegex($rule['source_pattern'], $requestPath, $rule['destination_url']);

            if ($destination !== null) {
                static::emitRedirect($destination, (int)$rule['type'], $normalizedPath);
            }
        }
    }

    /**
     * @return array{exact: array, regex: array}
     */
    public static function loadRules(): array
    {
        // Schema lookup goes through the modular connection so the existence check
        // hits the same table the query below targets, regardless of $wpdb->prefix
        // vs connection prefix mismatches. Caching an empty result during the
        // pre-migration window is intentional: invalidateCache() is called from
        // sync() and Manager::uninstall(), which are the only paths that change
        // the table state.
        $compute = static function (): array {
            // Same shape ManagerServer::getPhpData() reports to the manager —
            // skip the schema lookup entirely on shared hosts missing pdo /
            // pdo_mysql so we never reach Connection::run() and never throw a
            // QueryException on the public template_redirect hook.
            if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
                return ['exact' => [], 'regex' => []];
            }

            try {
                $model = new Redirect();

                if (!Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
                    return ['exact' => [], 'regex' => []];
                }

                return static::buildRulesFromDatabase();
            } catch (\Throwable $e) {
                // DB unreachable for any other reason — degrade to no-op so
                // template_redirect doesn't 500 the public site.
                Log::warning('RedirectService: DB unavailable, skipping redirect rules', [
                    'error' => $e->getMessage(),
                ]);

                return ['exact' => [], 'regex' => []];
            }
        };

        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, $compute);
        } catch (\Throwable $e) {
            // Cache driver unavailable (e.g. early bootstrap) — fall through to DB
            // without caching; the next call will retry the driver naturally.
            Log::warning('RedirectService: cache driver unavailable, falling through to DB', [
                'error' => $e->getMessage(),
            ]);

            return $compute();
        }
    }

    public static function matchRegex(string $pattern, string $requestPath, string $destination): ?string
    {
        $delimited = self::REGEX_DELIMITER . $pattern . self::REGEX_DELIMITER . 'i';
        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '10000');

            $result = preg_match($delimited, $requestPath, $matches);

            if ($result === false) {
                Log::warning('RedirectService: regex match failed', [
                    'pattern' => $pattern,
                    'pcre_error' => function_exists('preg_last_error_msg') ? preg_last_error_msg() : (string)preg_last_error(),
                ]);
                return null;
            }
            if ($result !== 1) {
                return null;
            }

            // Resolve $1..$N backreferences in a single pass to avoid the
            // order-sensitivity of repeated str_replace.
            return preg_replace_callback(
                '/\$([1-9]\d*)/',
                static fn(array $m) => $matches[(int)$m[1]] ?? $m[0],
                $destination
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string)$previousLimit);
        }
    }

    public static function checkRedirectLoop(string $path): void
    {
        // Deliberate trade-off: REMOTE_ADDR is proxy-unsafe — behind
        // Cloudflare/nginx every visitor shares the proxy IP, which collapses
        // every visitor onto the same loop bucket and turns this guard into a
        // DoS amplifier (5 hits from an attacker → 508 for everyone on that
        // path for 30 s). The proper fix is platform-wide trusted-proxy
        // configuration so we can read the real client IP from
        // CF-Connecting-IP / X-Forwarded-For only when the proxy is trusted;
        // that is not in scope for v3.0.0. Until then we accept the
        // amplifier in exchange for honest loop detection on direct-hit
        // sites. Read through the Laravel Request abstraction so we keep the
        // same semantics as HttpUtils (no raw $_SERVER access).
        $ip = (string)app('request')->server('REMOTE_ADDR', '0.0.0.0');
        $key = 'blc_loop_' . md5($ip . '|' . $path);

        $count = (int)Cache::get($key, 0);
        $count++;

        Cache::put($key, $count, 30);

        if ($count >= 5) {
            \wp_die(
                'Redirect loop detected',
                'Loop Detected',
                ['response' => 508]
            );
        }
    }

    public static function isProtectedPath(string $path): bool
    {
        $lower = Str::lower($path);

        foreach (self::PROTECTED_PATHS as $protected) {
            if (Str::startsWith($lower, $protected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a regex pattern for safety. Returns null when valid,
     * otherwise an error reason for the caller to surface.
     */
    public static function validateRegexPattern(string $pattern): ?string
    {
        if (strlen($pattern) > self::MAX_REGEX_PATTERN_LEN) {
            return 'Pattern exceeds maximum length of ' . self::MAX_REGEX_PATTERN_LEN . ' characters';
        }

        $delimited = self::REGEX_DELIMITER . $pattern . self::REGEX_DELIMITER;

        if (@preg_match($delimited, '') === false) {
            $reason = function_exists('preg_last_error_msg') ? preg_last_error_msg() : (string)preg_last_error();

            return 'Invalid regex pattern: ' . $reason;
        }

        if (static::isReDoSVulnerable($pattern)) {
            Log::warning('Potentially vulnerable regex pattern rejected', ['pattern' => $pattern]);

            return 'Pattern contains potentially dangerous nested quantifiers';
        }

        return null;
    }

    /**
     * Conservative ReDoS heuristic. Returns true (deny) on any of:
     *  - same-level nested quantifiers: (a+)+, (a*)+
     *  - quantified group containing alternation: (a|b)+
     *  - alternation overlap: (a|a)+, (.*|.+)+
     *  - deeply-nested patterns that exhaust the unwrapping budget (deny by default).
     */
    public static function isReDoSVulnerable(string $pattern): bool
    {
        if (preg_match('/\([^()]*[+*][^()]*\)[+*]/', $pattern)) {
            return true;
        }

        if (preg_match('/\([^()]*\|[^()]*\)[+*]/', $pattern)) {
            return true;
        }

        // Alternation overlap detector: scan quantified alternation groups, split
        // alternatives, flag duplicates / common catastrophic shapes (.*|.+).
        if (preg_match_all('/\(([^()]+)\)[+*]/', $pattern, $matches)) {
            foreach ($matches[1] as $body) {
                if (strpos($body, '|') === false) {
                    continue;
                }

                $alternatives = array_map('trim', explode('|', $body));

                if (count($alternatives) !== count(array_unique($alternatives))) {
                    return true;
                }

                foreach ($alternatives as $alt) {
                    if (preg_match('/^\.[+*]$/', $alt)) {
                        // (.+|.*) and similar: any greedy dot-alternation is suspect.
                        return true;
                    }
                }
            }
        }

        // Deeply-nested unwrapping: collapse inner-most groups and propagate a Q
        // marker outward whenever a group contains a quantifier. Bail-out is conservative.
        $unwrapped = $pattern;

        for ($i = 0; $i < 16; $i++) {
            if (!preg_match('/\(([^()]*)\)/', $unwrapped, $m)) {
                return false;
            }

            $hasQuant = strpbrk($m[1], '+*') !== false || strpos($m[1], 'Q') !== false;
            $placeholder = $hasQuant ? 'Q' : 'G';
            $unwrapped = preg_replace('/\([^()]*\)/', $placeholder, $unwrapped, 1);

            if (preg_match('/\([^()]*Q[^()]*\)[+*]/', $unwrapped)) {
                return true;
            }

            if (preg_match('/Q[+*]/', $unwrapped)) {
                return true;
            }
        }

        // Loop budget exhausted — deny rather than risk a false negative on a
        // pathologically-nested pattern.
        return true;
    }

    public static function invalidateCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            Log::warning('RedirectService: cache invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync redirect rules from the full historical manifest.
     *
     * Contract (per entry):
     *   source_id        : unique manager-side identifier (column `source_id`)
     *   source_pattern   : path or regex
     *   destination_url  : http/https only
     *   type             : 301|302|307|308
     *   match            : "exact" | "regex" (unknown → treated as exact, forward-compat)
     *   status           : "enabled" | "disabled" (unknown → treated as disabled, fail-safe)
     *   deleted_at       : null when alive; ISO-8601 when tombstoned. Non-null wins
     *                      over status — the entry is skipped from the upsert and
     *                      the diff-delete (whereNotIn) wipes it from the table.
     *
     * The manifest is the full historical (with deletions from the last 30 days),
     * so the diff-delete is authoritative: anything in the DB not present in the
     * incoming alive set is removed. An empty manifest legitimately means "the
     * site has no active rules and nothing was deleted recently" — we trust the
     * source and apply it; the natural whereNotIn([]) wipes the table.
     *
     * For a 5k-rule payload: 1 SELECT (created/updated bookkeeping, bounded by
     * payload) + ceil(N/UPSERT_CHUNK) batched upserts + 1 diff-delete pushed
     * down to SQL. Memory is O(payload), not O(table).
     *
     * @param array $payload
     * @return array
     */
    public static function sync(array $payload): array
    {
        $rows = [];
        $incomingIds = [];
        $rejected = [];

        foreach ($payload as $redirect) {
            $sourceId = data_get($redirect, 'source_id');

            if (!$sourceId) {
                continue;
            }

            // Soft-delete tombstone wins over everything else. We deliberately do
            // not store deleted_at in the local schema — the diff-delete below
            // converts "not in incoming set" into "wipe from table".
            if (!empty(data_get($redirect, 'deleted_at'))) {
                continue;
            }

            $validationError = static::validateEntry($redirect);

            if ($validationError !== null) {
                // Rejected entries are NOT added to $incomingIds — the diff-delete
                // step below treats them like rules the remote no longer asserts,
                // so a broken row from a prior sync is cleaned up instead of
                // preserved.
                $rejected[] = ['source_id' => $sourceId, 'reason' => $validationError];
                continue;
            }

            // Lenient on unknown match types: an older plugin should not reject a
            // future match kind (e.g. "wildcard") added by the manager. Anything
            // that is not explicitly "regex" is treated as an exact match.
            $matchType = data_get($redirect, 'match') === Redirect::MATCH_REGEX
                ? Redirect::MATCH_REGEX
                : Redirect::MATCH_EXACT;

            $incomingIds[] = $sourceId;

            $rows[] = [
                'source_id' => $sourceId,
                'source_pattern' => (string)data_get($redirect, 'source_pattern', ''),
                'destination_url' => (string)data_get($redirect, 'destination_url', ''),
                'type' => (int)data_get($redirect, 'type', 301),
                // `match` on the wire maps to `match_type` locally to avoid the
                // MySQL reserved keyword.
                'match_type' => $matchType,
                // Fail-safe: only the explicit "enabled" string activates the rule.
                // Unknown status values (future "scheduled", typos) are stored
                // verbatim and the active() scope leaves them out, so we never
                // silently apply a rule the manager intended to gate.
                'status' => data_get($redirect, 'status') === Redirect::STATUS_ENABLED
                    ? Redirect::STATUS_ENABLED
                    : Redirect::STATUS_DISABLED,
            ];
            // created_at / updated_at are injected by Eloquent\Builder::upsert()
            // via addTimestampsToUpsertValues() and addUpdatedAtToUpsertColumns(),
            // so we deliberately do not set them here.
        }

        // Resolve created vs updated by checking which incoming ids already exist.
        // Bounded by the payload, not by the table size, so safe at 5k+ rules.
        $existingIds = empty($incomingIds)
            ? []
            : Redirect::query()->whereIn('source_id', $incomingIds)->pluck('source_id')->all();

        $updated = count($existingIds);
        $created = count($incomingIds) - $updated;

        $deleted = Redirect::query()->getConnection()->transaction(
            function () use ($rows, $incomingIds) {
                // Batched UPSERT (MySQL ON DUPLICATE KEY / SQLite ON CONFLICT) keyed
                // on the `source_id` unique index. Chunked so we stay well under
                // max_allowed_packet on hosts that ship the 4 MB default.
                foreach (array_chunk($rows, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    // `updated_at` is appended by Eloquent\Builder::upsert() —
                    // do not duplicate it here.
                    Redirect::query()->upsert(
                        $chunk,
                        ['source_id'],
                        ['source_pattern', 'destination_url', 'type', 'match_type', 'status']
                    );
                }

                // Diff-delete pushed down to SQL: anything not in $incomingIds is gone.
                // whereNotIn([]) is rewritten to `WHERE 1 = 1` by the Query builder,
                // so the "trust the source — wipe everything when the manifest is
                // empty" semantic is preserved naturally.
                return Redirect::query()->whereNotIn('source_id', $incomingIds)->delete();
            }
        );

        static::invalidateCache();
        dispatch(new CacheClearJob());

        return [
            'synced' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'rejected' => $rejected,
        ];
    }

    /**
     * @return array{exact: array, regex: array}
     */
    private static function buildRulesFromDatabase(): array
    {
        $rows = Redirect::active()
            ->get(['source_pattern', 'destination_url', 'type', 'match_type']);

        $rules = ['exact' => [], 'regex' => []];

        foreach ($rows as $row) {
            $entry = [
                'source_pattern' => $row->source_pattern,
                'destination_url' => $row->destination_url,
                'type' => $row->type,
            ];

            if ($row->match_type === Redirect::MATCH_REGEX) {
                $rules['regex'][] = $entry;
            } else {
                $key = static::normalizePath((string)$row->source_pattern);
                $rules['exact'][$key] = $entry;
            }
        }

        return $rules;
    }

    private static function currentRequestPath(): ?string
    {
        // Symfony/Laravel already URL-decodes and strips the query string from
        // getPathInfo(), so the wp_parse_url(..., PHP_URL_PATH) wrapper is no longer needed.
        $path = app('request')->getPathInfo();

        return !empty($path) ? (string)$path : null;
    }

    private static function normalizePath(string $path): string
    {
        $normalized = rtrim(Str::lower($path), '/');

        return $normalized === '' ? '/' : $normalized;
    }

    /**
     * Defense in depth: even though sync() validateEntry blocks unsafe destinations,
     * a row could land in the DB via direct SQL, migration import, or a future
     * code path that bypasses validation. Re-check at emission time.
     */
    private static function emitRedirect(string $destination, int $statusCode, string $loopKeyPath): void
    {
        $scheme = Str::lower((string)\wp_parse_url($destination, PHP_URL_SCHEME));

        if (!in_array($scheme, self::ALLOWED_DESTINATION_SCHEMES, true)) {
            Log::warning('RedirectService: refused to emit redirect to disallowed scheme', [
                'destination' => $destination,
                'scheme' => $scheme,
            ]);

            return;
        }

        static::checkRedirectLoop($loopKeyPath);

        // wp_safe_redirect + scoped allowlist: defaults to same-host only, so
        // even if a future code path bypassed validateEntry, an internal-only
        // redirect could not be turned into an open redirect by an attacker
        // controlling the row. The destination host was already validated by
        // scheme + loop checks above.
        $destinationHost = (string)\wp_parse_url($destination, PHP_URL_HOST);

        \add_filter('allowed_redirect_hosts', function (array $hosts) use ($destinationHost) {
            if ($destinationHost !== '') {
                $hosts[] = $destinationHost;
            }

            return $hosts;
        });

        \wp_safe_redirect($destination, $statusCode);
        exit;
    }

    /**
     * @param mixed $redirect
     */
    private static function validateEntry($redirect): ?string
    {
        $destinationUrl = (string)data_get($redirect, 'destination_url', '');

        if ($destinationUrl === '') {
            return 'destination_url is required';
        }

        if (filter_var($destinationUrl, FILTER_VALIDATE_URL) === false) {
            return 'destination_url is not a valid URL';
        }
        // FILTER_VALIDATE_URL accepts javascript:, data:, file:, gopher:, ftp: — the
        // scheme whitelist below is the actual security check.
        $scheme = Str::lower((string)\wp_parse_url($destinationUrl, PHP_URL_SCHEME));

        if (!in_array($scheme, self::ALLOWED_DESTINATION_SCHEMES, true)) {
            return 'destination_url scheme must be http or https';
        }

        $redirectType = (int)data_get($redirect, 'type', 301);

        if (!in_array($redirectType, self::ALLOWED_REDIRECT_TYPES, true)) {
            return 'type must be one of 301, 302, 307, 308';
        }

        $sourcePattern = (string)data_get($redirect, 'source_pattern', '');
        if (strlen($sourcePattern) > self::MAX_SOURCE_PATTERN_LEN) {
            return 'source_pattern exceeds maximum length of ' . self::MAX_SOURCE_PATTERN_LEN . ' characters';
        }

        // We only enforce regex validity when match is explicitly "regex"; any
        // other value is treated as exact by the caller (forward-compat) so
        // there is no syntax to validate.
        if (data_get($redirect, 'match') === Redirect::MATCH_REGEX) {
            $regexError = static::validateRegexPattern($sourcePattern);

            if ($regexError !== null) {
                return $regexError;
            }
        }

        return null;
    }
}

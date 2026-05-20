<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Helpers;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Schema;

class NoindexDetector
{
    private const YOAST_META = '_yoast_wpseo_meta-robots-noindex';
    private const RANKMATH_META = 'rank_math_robots';
    private const SEOPRESS_META = '_seopress_robots_index';
    private const AIOSEO_V3_META = '_aioseo_noindex';
    private const AIOSEO_TABLE = 'aioseo_posts';

    /**
     * AIOSEO v4 robots_noindex keyed by post_id, primed per batch by primeBatch().
     *
     * @var array<int,int|null>
     */
    private static array $aioseoCache = [];

    /**
     * Lookup set of post_ids covered by the last primeBatch() call. Distinguishes
     * "primed and AIOSEO has no row for this post" (skip) from "not in the
     * batch, run a lazy lookup" (per-post query).
     *
     * @var array<int,int>|null
     */
    private static $aioseoPrimed = null;

    /** Memoized Schema::hasTable(aioseo_posts) result for the current process. */
    private static $aioseoTableExists = null;

    /**
     * Pre-load AIOSEO v4 noindex flags for a batch in one whereIn query. Mirrors
     * the update_meta_cache pattern from the calling job: 50 per-post lookups
     * collapse to a single round-trip.
     *
     * @param int[] $postIds
     */
    public static function primeBatch(array $postIds): void
    {
        self::$aioseoPrimed = array_flip($postIds);

        if (empty($postIds) || !self::aioseoTableExists()) {
            self::$aioseoCache = [];
            return;
        }

        self::$aioseoCache = DB::table(self::AIOSEO_TABLE)
            ->whereIn('post_id', $postIds)
            ->pluck('robots_noindex', 'post_id')
            ->all();
    }

    /**
     * Returns true when any of these mark the post as noindex:
     *   - blog_public = '0' (site-wide "discourage search engines")
     *   - Yoast / Rank Math / SEOPress / AIOSEO v4+ / AIOSEO v3 per-post setting
     */
    public static function isNoindex(int $postId): bool
    {
        // Site-wide signal short-circuits per-post overrides. If the admin set
        // "discourage search engines" (Settings → Reading), every post inherits
        // noindex regardless of what any SEO plugin says.
        if (\get_option('blog_public') === '0') {
            return true;
        }

        // Yoast: '1' = noindex, '2' = explicit index, absent = default.
        if (\get_post_meta($postId, self::YOAST_META, true) === '1') {
            return true;
        }

        // Rank Math stores as a serialized PHP array (['noindex','nofollow']) on
        // recent versions; older installs and some import paths persist a
        // comma-separated string. Normalize before checking.
        $rm = \get_post_meta($postId, self::RANKMATH_META, true);
        if (is_string($rm) && $rm !== '') {
            $rm = array_map('trim', explode(',', $rm));
        }
        if (is_array($rm) && in_array('noindex', $rm, true)) {
            return true;
        }

        // SEOPress: the postmeta is named *_index but its 'yes' value actually
        // means "do NOT index this content" — counter-intuitive but documented.
        if (\get_post_meta($postId, self::SEOPRESS_META, true) === 'yes') {
            return true;
        }

        // AIOSEO v4+: prefer the batch-primed cache. Lazy per-post fallback
        // when the post wasn't part of the primed batch (ad-hoc callers).
        $primed = self::$aioseoPrimed !== null && isset(self::$aioseoPrimed[$postId]);
        if ($primed) {
            if (isset(self::$aioseoCache[$postId]) && (int)self::$aioseoCache[$postId] === 1) {
                return true;
            }
        } elseif (self::aioseoTableExists()) {
            $value = DB::table(self::AIOSEO_TABLE)
                ->where('post_id', $postId)
                ->value('robots_noindex');
            if ($value !== null && (int)$value === 1) {
                return true;
            }
        }

        // AIOSEO v3 legacy postmeta fallback.
        if (\get_post_meta($postId, self::AIOSEO_V3_META, true) === '1') {
            return true;
        }

        return false;
    }

    private static function aioseoTableExists(): bool
    {
        if (self::$aioseoTableExists === null) {
            self::$aioseoTableExists = Schema::hasTable(self::AIOSEO_TABLE);
        }
        return self::$aioseoTableExists;
    }
}

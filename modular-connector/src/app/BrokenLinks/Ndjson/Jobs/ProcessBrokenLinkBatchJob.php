<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Jobs;

use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\BrokenLinks\Ndjson\BrokenLinkPart;
use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartUpdated;
use Modular\Connector\BrokenLinks\Ndjson\Helpers\ContentRenderer;
use Modular\Connector\BrokenLinks\Ndjson\Helpers\LinkExtractor;
use Modular\Connector\BrokenLinks\Ndjson\Helpers\NoindexDetector;
use Modular\Connector\BrokenLinks\Ndjson\Manifest\NdjsonWriter;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\dispatch;

class ProcessBrokenLinkBatchJob implements ShouldQueue
{
    use Queueable, Dispatchable, HasMaxTime;

    private const PAGE_SIZE = 50;
    private const HARD_CAP_PER_JOB = 500;

    /**
     * Per-tick budget — same as Manifest / MergeScanManifestsJob / PollImunifyJob.
     * The loop breaks before reaching it, flushes the writer and self-redispatches.
     */
    protected int $maxTime = 25;

    public BrokenLinkPart $part;

    public function __construct(BrokenLinkPart $part)
    {
        $this->part = $part;
    }

    public function handle(): void
    {
        $part = $this->part;

        if ($part->isCancelled()) {
            return;
        }

        $writer = new NdjsonWriter($part->manifestPath);
        $extractor = new LinkExtractor(null, [
            'internal' => $part->checkInternal,
            'external' => $part->checkExternal,
            'mixed_content' => $part->checkMixedContent,
        ]);

        if ($part->processedCount === 0) {
            $writer->delete();
            $part->markAs(
                ManagerBrokenLinkPartUpdated::STEP_SCAN,
                ManagerBrokenLinkPartUpdated::STATUS_IN_PROGRESS
            );
        }

        $startTime = $this->getCurrentTime();
        $processedThisJob = 0;
        $linksThisJob = 0;
        $exhausted = false;

        try {
            while (true) {
                $rows = $this->nextRowBatch();

                if ($rows->isEmpty()) {
                    $exhausted = true;
                    break;
                }

                $ids = $rows->pluck('ID')->map(static fn($id) => (int)$id)->all();

                // Prime the postmeta cache in a single round-trip for the whole
                // batch. Without this, the first get_post_meta() per post fires
                // a separate query each — re-introducing the N+1 we're killing
                // here (Elementor/Beaver/Divi/WPBakery flags + NoindexDetector
                // SEO keys + plugins hooked on the_content all read meta).
                update_meta_cache('post', $ids);

                // Prime AIOSEO v4+ noindex flags for the batch. Yoast / Rank Math
                // / SEOPress / AIOSEO v3 all read from postmeta (already cached
                // above), but AIOSEO v4 stores robots state in a custom table —
                // without this prime, NoindexDetector would fire one query per
                // post inside the loop.
                NoindexDetector::primeBatch($ids);

                $stop = false;

                foreach ($rows as $row) {
                    // Hydrate WP_Post locally from the row we already fetched —
                    // no extra query — and seed the post cache so plugins /
                    // builders that call get_post($id) downstream are cache hits.
                    $post = new \WP_Post($row);
                    wp_cache_add($post->ID, $post, 'posts');

                    $linksThisJob += $this->processPost($post, $writer, $extractor);

                    $part->processedCount++;

                    if ($part->type === BrokenLinkPart::TYPE_FULL) {
                        $part->lastSeenId = $post->ID;
                    }

                    $processedThisJob++;

                    if ($this->checkIfBatchIsOversize($processedThisJob, $startTime)) {
                        $stop = true;
                        break;
                    }
                }

                if ($stop) {
                    break;
                }
            }
        } finally {
            $writer->close();
        }

        $this->checkIsReady($linksThisJob, $exhausted);
    }

    public function failed(\Throwable $e): void
    {
        $part = $this->part;

        Log::error('BLC ProcessBrokenLinkBatchJob failed', [
            'site_broken_link_scan' => $part->siteBrokenLinkScan,
            'source_type' => $part->postType,
            'processed_count' => $part->processedCount,
            'last_seen_id' => $part->lastSeenId,
            'error' => $e->getMessage(),
        ]);

        try {
            $part->markAsFailed(ManagerBrokenLinkPartUpdated::STEP_SCAN, $e);
        } catch (\Throwable $eventError) {
            Log::error('BLC ProcessBrokenLinkBatchJob::failed: event dispatch also failed', [
                'site_broken_link_scan' => $part->siteBrokenLinkScan,
                'source_type' => $part->postType,
                'event_error' => $eventError->getMessage(),
            ]);
        }
    }

    /**
     * Next batch of rows (stdClass with every wp_posts column) ready to be
     * hydrated into WP_Post locally. Two strategies:
     *   - TYPE_FULL: keyset pagination (ID > lastSeenId ORDER BY ID ASC). Stable
     *     against post edits between ticks — the offset never desyncs because we
     *     never use a numeric OFFSET. Inserts with higher IDs land at the tail.
     *   - TYPE_PAGE: $postIds is the finite list resolved up-front in
     *     calculateExclusion(); we slice it by processedCount and re-fetch
     *     the rows in one query.
     *
     * Fluent on wp_posts instead of WP_Query: avoids pre_get_posts / the_posts /
     * posts_clauses filters that third-party plugins hook into. SELECT * is
     * deliberate — WP_Post::__construct copies every column, and downstream
     * code (or plugins on the_content) may read any of them.
     */
    private function nextRowBatch(): Collection
    {
        $part = $this->part;

        // Raw table name — the `wordpress` connection adds $wpdb->prefix
        // automatically. Passing $wpdb->posts here would double-prefix.
        $base = DB::table('posts')
            ->select('*')
            ->where('post_type', $part->postType)
            ->where('post_status', 'publish');

        if ($part->type === BrokenLinkPart::TYPE_FULL) {
            $remaining = $part->maxPages - $part->processedCount;
            if ($remaining <= 0) {
                return Collection::make();
            }

            return $base
                ->where('ID', '>', $part->lastSeenId)
                ->orderBy('ID', 'asc')
                ->limit(min(self::PAGE_SIZE, $remaining))
                ->get();
        }

        // TYPE_PAGE — finite, resolved up-front, ordered by ID.
        $slice = array_slice($part->postIds, $part->processedCount, self::PAGE_SIZE);
        if (empty($slice)) {
            return Collection::make();
        }

        return $base
            ->whereIn('ID', $slice)
            ->orderBy('ID', 'asc')
            ->get();
    }

    /**
     * Extract one post's links and append one NDJSON row. Returns the link count.
     *
     * Mode determines the source HTML:
     *   - MODE_RENDER: ContentRenderer + apply_filters('the_content'). Postdata
     *     is set up so builders/shortcodes that read $GLOBALS['post'] see a
     *     coherent context. Heavy, robust, has plugin side-effects.
     *   - MODE_PLAIN:  raw post_content. No postdata/globals — get_permalink()
     *     reads from the WP_Post arg directly. Cheap, misses builder output.
     *
     * The post cache is freed in finally to keep memory bounded across the batch.
     */
    private function processPost(\WP_Post $post, NdjsonWriter $writer, LinkExtractor $extractor): int
    {
        $part = $this->part;

        // wpdb returns every column as a string, so $post->ID is a string after
        // hydrating WP_Post from a Fluent row. We cast once here for the wire
        // format (json_encode would otherwise emit "post_id":"123") and reuse
        // the int across the method.
        $postId = (int)$post->ID;

        if ($post->post_status !== 'publish') {
            // Free the in-process cache entries directly. clean_post_cache()
            // would also fire the clean_post_cache action — which page-cache
            // plugins (WP Rocket, LiteSpeed, W3TC) hook to purge the post's
            // rendered HTML. We only want to read, never invalidate.
            \wp_cache_delete($postId, 'posts');
            \wp_cache_delete($postId, 'post_meta');
            return 0;
        }

        $isRender = $part->mode === BrokenLinkPart::MODE_RENDER;
        $previousPost = null;

        if ($isRender) {
            $previousPost = $GLOBALS['post'] ?? null;
            $GLOBALS['post'] = $post;
            setup_postdata($post);
        }

        try {
            $permalink = get_permalink($post);
            $html = $isRender
                ? ContentRenderer::render($post)
                : (string)$post->post_content;
            $links = $extractor->extract($html, $permalink, $part->resourceTypes, $part->excludedUrlPatterns);

            $writer->append([
                'source_id' => $postId,
                'source_type' => $post->post_type,
                'source_title' => $post->post_title,
                'url' => $permalink,
                'status' => $post->post_status,
                'modified_at' => $this->toIso8601Zulu($post->post_modified_gmt),
                'noindex' => NoindexDetector::isNoindex($postId),
                'links' => $links,
            ]);

            return count($links);
        } finally {
            if ($isRender) {
                if ($previousPost instanceof \WP_Post) {
                    $GLOBALS['post'] = $previousPost;
                    setup_postdata($previousPost);
                } else {
                    unset($GLOBALS['post']);
                    wp_reset_postdata();
                }
            }

            // Free this post's cache entries to keep memory bounded across the
            // batch. Skip clean_post_cache() — see the early-return comment.
            \wp_cache_delete($postId, 'posts');
            \wp_cache_delete($postId, 'post_meta');
        }
    }

    /**
     * Called after every post. Returns true to break the loop. Mirrors
     * ProcessFilesJob::checkIfBatchSizeIsOversize.
     */
    private function checkIfBatchIsOversize(int $processedThisJob, float $startTime): bool
    {
        $part = $this->part;

        if ($part->isCancelled()) {
            return true;
        }

        if ($this->isTimeExceeded($startTime, $this->maxTime)) {
            return true;
        }

        if ($this->checkMemoryUsage()) {
            Log::warning('BLC ProcessBrokenLinkBatchJob: memory ceiling hit, re-dispatching', [
                'site_broken_link_scan' => $part->siteBrokenLinkScan,
                'source_type' => $part->postType,
                'processed_count' => $part->processedCount,
            ]);
            return true;
        }

        if ($processedThisJob >= self::HARD_CAP_PER_JOB) {
            return true;
        }

        if ($part->type === BrokenLinkPart::TYPE_FULL && $part->processedCount >= $part->maxPages) {
            return true;
        }

        return false;
    }

    /**
     * Called once at end of the tick. Mirrors ProcessFilesJob::checkFilesIsReady.
     * Decides the part's next transition:
     *   - cancelled                     → silent exit (no event, no redispatch)
     *   - exhausted/cap with links      → step=upload status=pending (dispatcher fires Upload)
     *   - exhausted/cap, totalLinks=0   → step=upload status=done directly. Sources
     *                                     may have been processed (NDJSON has rows
     *                                     with empty `links: []`), but there's
     *                                     nothing actionable to send. Same shortcut
     *                                     when the post-type had no published sources.
     *   - otherwise                     → self-redispatch with the updated state
     */
    private function checkIsReady(int $linksThisJob, bool $exhausted): void
    {
        $part = $this->part;
        $part->totalLinks += $linksThisJob;

        if ($part->isCancelled()) {
            return;
        }

        $capReached = $part->type === BrokenLinkPart::TYPE_FULL && $part->processedCount >= $part->maxPages;

        if ($exhausted || $capReached) {
            $part->markAs(
                ManagerBrokenLinkPartUpdated::STEP_UPLOAD,
                $part->totalLinks > 0
                    ? ManagerBrokenLinkPartUpdated::STATUS_PENDING
                    : ManagerBrokenLinkPartUpdated::STATUS_DONE
            );
            return;
        }

        dispatch(new self($part));
    }

    private function toIso8601Zulu(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }

        return Carbon::parse($mysqlDatetime, 'UTC')->toIso8601ZuluString();
    }
}

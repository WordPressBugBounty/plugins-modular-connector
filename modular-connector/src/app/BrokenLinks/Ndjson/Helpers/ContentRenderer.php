<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Helpers;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

/**
 * Dispatches a post to the first matching page-builder branch, falling back
 * to the standard the_content filter when no builder is detected.
 *
 * Re-entrancy: the_content filter triggers every plugin's content-pipeline,
 * which can mutate $wp_query and queue late hooks. Callers running this in a
 * loop (e.g. ProcessBrokenLinkBatchJob) MUST call wp_reset_postdata() after
 * each render to keep per-iteration state isolated.
 *
 * Order matters: builders are tried in declaration order. A post with both
 * Elementor and Beaver flags (rare; happens after dirty migrations) is
 * rendered as Elementor.
 */
final class ContentRenderer
{
    public static function render(\WP_Post $post): string
    {
        $builders = [
            ['elementor', 'supportsElementor', 'renderElementor'],
            ['beaver-builder', 'supportsBeaver', 'renderBeaver'],
            ['divi', 'supportsDivi', 'renderDivi'],
            ['wpbakery', 'supportsWPBakery', 'renderWPBakery'],
        ];

        foreach ($builders as [$name, $supports, $render]) {
            if (!self::{$supports}($post)) {
                continue;
            }

            try {
                $html = self::{$render}($post);
            } catch (\Throwable $e) {
                Log::warning('BLC ContentRenderer: builder render failed', [
                    'builder' => $name,
                    'source_id' => $post->ID,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($html !== '') {
                return $html;
            }
        }

        // A throwing plugin on the_content would otherwise tank the whole
        // batch — STATUS_FAILED for the part. Fall back to the raw
        // post_content so the post still contributes its editorial links
        // (oembed-rendered HTML, page-builder shortcodes etc. are skipped,
        // but raw <a href> URLs survive).
        try {
            return (string)apply_filters('the_content', (string)$post->post_content);
        } catch (\Throwable $e) {
            Log::warning('BLC ContentRenderer: the_content filter failed, falling back to raw post_content', [
                'source_id' => $post->ID,
                'error' => $e->getMessage(),
            ]);

            return (string)$post->post_content;
        }
    }

    private static function supportsElementor(\WP_Post $post): bool
    {
        return get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder'
            && class_exists(\Elementor\Plugin::class);
    }

    private static function renderElementor(\WP_Post $post): string
    {
        $instance = \Elementor\Plugin::$instance ?? null;

        if (!$instance || !isset($instance->frontend)) {
            return '';
        }

        $html = $instance->frontend->get_builder_content_for_display($post->ID, true);

        return is_string($html) ? $html : '';
    }

    private static function supportsBeaver(\WP_Post $post): bool
    {
        return get_post_meta($post->ID, '_fl_builder_enabled', true) === '1'
            && class_exists(\FLBuilder::class)
            && method_exists(\FLBuilder::class, 'render_content_by_id');
    }

    private static function renderBeaver(\WP_Post $post): string
    {
        $html = \FLBuilder::render_content_by_id($post->ID);
        return is_string($html) ? $html : '';
    }

    private static function supportsDivi(\WP_Post $post): bool
    {
        return get_post_meta($post->ID, '_et_pb_use_builder', true) === 'on';
    }

    private static function renderDivi(\WP_Post $post): string
    {
        // Without et_builder_render_layout the raw post_content contains
        // [et_pb_*] shortcodes that aren't registered → do_shortcode produces
        // useless output. Return '' so the dispatcher falls through to the
        // generic the_content filter.
        if (!function_exists('et_builder_render_layout')) {
            return '';
        }

        $html = et_builder_render_layout((string)$post->post_content);
        if (!is_string($html) || $html === '') {
            return '';
        }

        return (string)do_shortcode($html);
    }

    private static function supportsWPBakery(\WP_Post $post): bool
    {
        // Any non-empty meta value indicates a WPBakery-authored post.
        $status = get_post_meta($post->ID, '_wpb_vc_js_status', true);
        return is_string($status) && $status !== ''
            && (class_exists(\Vc_Manager::class) || function_exists('vc_manager'));
    }

    private static function renderWPBakery(\WP_Post $post): string
    {
        $content = (string)$post->post_content;

        if ($content === '') {
            return '';
        }

        return (string)\do_shortcode($content);
    }
}

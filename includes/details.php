<?php

/**
 * Issue layout and issue details.
 *
 * Layout: the shared section (entered once, printed on both pages) is either
 * a sidebar beside each page or a masthead across the top of each page.
 *
 * Issue details: month, volume and issue number. Authors insert them into any
 * text with the "Issue detail" button; they are stored as
 * <span class="opn-detail" data-opn-detail="…">preview</span> and filled in
 * when the issue is shown, printed or emailed. A copied issue moves on to the
 * next month and number by itself, so a masthead carried over from last month
 * never shows last month's date. "Page number" prints 1 or 2; text containing
 * it is left out of the website and email, which have no pages.
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const LAYOUT_META = '_omni_platform_newsletter_shared_area';
const MONTH_META = '_omni_platform_newsletter_month';
const VOLUME_META = '_omni_platform_newsletter_volume';
const NUMBER_META = '_omni_platform_newsletter_number';
const TEXT_SIZE_META = '_omni_platform_newsletter_text_size';

/** 'top' (masthead across the top of each page) or 'side' (sidebar beside each page). */
function sharedArea(?\WP_Post $post): string
{
    return $post && get_post_meta($post->ID, LAYOUT_META, true) === 'top' ? 'top' : 'side';
}

/** Printed text size the author chose: '' (regular), 'compact' or 'small'. */
function printTextSize(?\WP_Post $post): string
{
    $size = $post ? (string) get_post_meta($post->ID, TEXT_SIZE_META, true) : '';

    return in_array($size, ['compact', 'small'], true) ? $size : '';
}

/** A 'YYYY-MM' month, or '' when the value is not one. */
function sanitizeMonth(mixed $value): string
{
    return is_string($value) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) ? $value : '';
}

/** Short free text such as "05" or "12A". */
function sanitizeDetail(mixed $value): string
{
    return substr(sanitize_text_field(is_scalar($value) ? (string) $value : ''), 0, 20);
}

/** The month after 'YYYY-MM', or this month if that is later. */
function nextMonth(string $month): string
{
    $current = wp_date('Y-m');
    if (sanitizeMonth($month) === '') {
        return $current;
    }
    [$year, $number] = array_map('intval', explode('-', $month));
    $next = sprintf('%04d-%02d', $number === 12 ? $year + 1 : $year, $number === 12 ? 1 : $number + 1);

    return strcmp($next, $current) > 0 ? $next : $current;
}

/** "11" → "12", "05" → "06", "Issue 9" → "Issue 10"; text without a number is kept. */
function nextNumber(string $number): string
{
    return (string) preg_replace_callback('/(\d+)(?!.*\d)/', function ($m) {
        return str_pad((string) ((int) $m[1] + 1), strlen($m[1]), '0', STR_PAD_LEFT);
    }, $number, 1);
}

/**
 * The details as they appear in text.
 *
 * @return array{issue_date: string, volume: string, issue_number: string}
 */
function issueDetails(?\WP_Post $post): array
{
    $month = $post ? sanitizeMonth(get_post_meta($post->ID, MONTH_META, true)) : '';
    if ($month === '') {
        $month = $post ? (string) mysql2date('Y-m', $post->post_date) : wp_date('Y-m');
    }
    $timestamp = strtotime($month . '-15 12:00:00');

    $details = [
        'issue_date' => (string) date_i18n('F Y', $timestamp ?: time()),
        'volume' => $post ? sanitizeDetail(get_post_meta($post->ID, VOLUME_META, true)) : '',
        'issue_number' => $post ? sanitizeDetail(get_post_meta($post->ID, NUMBER_META, true)) : '',
    ];

    /**
     * Issue details inserted into newsletter text.
     *
     * @param array{issue_date: string, volume: string, issue_number: string} $details
     */
    return (array) apply_filters('omni_platform_newsletter_issue_details', $details, $post);
}

/** Pattern matching an inserted issue detail; group 1 is its name. */
function detailPattern(): string
{
    return '#<span\b(?=[^>]*\bclass="[^"]*\bopn-detail\b)[^>]*\bdata-opn-detail="([a-z_]+)"[^>]*>.*?</span>#s';
}

/**
 * Fill in issue details. $page is '1' or '2' on paper and '' elsewhere.
 */
function fillDetails(string $html, array $details, string $page = ''): string
{
    if (!str_contains($html, 'data-opn-detail')) {
        return $html;
    }

    return (string) preg_replace_callback(detailPattern(), function ($m) use ($details, $page) {
        $value = $m[1] === 'page' ? $page : (string) ($details[$m[1]] ?? '');
        // Plain text: a wrapper element makes text-only email read "Volume 05 , Issue # 11".
        return esc_html($value);
    }, $html);
}

/**
 * One block for the email: details filled in, and text that shows the page number left out.
 *
 * @return array|null Null when the block only makes sense on paper.
 */
function emailDetailBlock(array $block, array $details): ?array
{
    if (preg_match('#data-opn-detail="page"#', (string) ($block['innerHTML'] ?? '')) && empty($block['innerBlocks'])) {
        return null;
    }
    // An empty bullet (a stray Enter at the end of a list) would show as a lone dot.
    if (($block['blockName'] ?? '') === 'core/list-item' && empty($block['innerBlocks']) && trim(wp_strip_all_tags((string) ($block['innerHTML'] ?? ''))) === '') {
        return null;
    }
    $block['innerHTML'] = fillDetails((string) ($block['innerHTML'] ?? ''), $details);
    $block['innerContent'] = array_map(fn($part) => is_string($part) ? fillDetails($part, $details) : $part, (array) ($block['innerContent'] ?? []));

    if (!empty($block['innerBlocks'])) {
        $inner = [];
        $content = [];
        $index = 0;
        foreach ($block['innerContent'] as $part) {
            if (is_string($part)) {
                $content[] = $part;
                continue;
            }
            $child = emailDetailBlock($block['innerBlocks'][$index++], $details);
            if ($child !== null) {
                $inner[] = $child;
                $content[] = null;
            }
        }
        $block['innerBlocks'] = $inner;
        $block['innerContent'] = $content;
    }

    return $block;
}

/** Settings carried from one issue to the next: layout, volume, the next number and month. */
function carryDetails(\WP_Post $source, int $target): void
{
    if (sharedArea($source) === 'top') {
        update_post_meta($target, LAYOUT_META, 'top');
    } else {
        delete_post_meta($target, LAYOUT_META);
    }
    if (printTextSize($source) !== '') {
        update_post_meta($target, TEXT_SIZE_META, printTextSize($source));
    }
    $volume = sanitizeDetail(get_post_meta($source->ID, VOLUME_META, true));
    $number = sanitizeDetail(get_post_meta($source->ID, NUMBER_META, true));
    if ($volume !== '') {
        update_post_meta($target, VOLUME_META, $volume);
    }
    if ($number !== '') {
        update_post_meta($target, NUMBER_META, nextNumber($number));
    }
    update_post_meta($target, MONTH_META, nextMonth(sanitizeMonth(get_post_meta($source->ID, MONTH_META, true)) ?: (string) mysql2date('Y-m', $source->post_date)));
}

add_action('init', function () {
    $metas = [
        LAYOUT_META => fn($value) => $value === 'top' ? 'top' : '',
        TEXT_SIZE_META => fn($value) => in_array($value, ['compact', 'small'], true) ? $value : '',
        MONTH_META => __NAMESPACE__ . '\\sanitizeMonth',
        VOLUME_META => __NAMESPACE__ . '\\sanitizeDetail',
        NUMBER_META => __NAMESPACE__ . '\\sanitizeDetail',
    ];
    foreach (supportedPostTypes() as $type) {
        foreach ($metas as $key => $sanitize) {
            register_post_meta($type, $key, [
                'type' => 'string',
                'single' => true,
                'default' => '',
                'show_in_rest' => true,
                'sanitize_callback' => $sanitize,
                'auth_callback' => fn($allowed, $key, $postId) => current_user_can('edit_post', (int) $postId),
            ]);
        }
    }
});

/** Data for the editor's Layout panel and "Issue detail" button. */
function detailsEditorData(\WP_Post $post): array
{
    $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);

    return [
        'keys' => ['layout' => LAYOUT_META, 'month' => MONTH_META, 'volume' => VOLUME_META, 'number' => NUMBER_META, 'textSize' => TEXT_SIZE_META],
        'postMonth' => (string) mysql2date('Y-m', $post->post_date ?: current_time('mysql')),
        'mastheadPattern' => mastheadPatternContent(),
        'siteName' => $site,
    ];
}

/**
 * A ready-made masthead for the shared section: organization name, newsletter
 * name, contact box, and a bar with the issue month, volume and number.
 */
function mastheadPatternContent(): string
{
    $parts = starterParts();
    $siteName = esc_html(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
    $detail = fn(string $key, string $preview) => '<span class="opn-detail" data-opn-detail="' . $key . '">' . esc_html($preview) . '</span>';
    $details = issueDetails(null);
    $para = function (string $html, string $class = '', string $align = ''): string {
        $attrs = array_filter(['align' => $align, 'className' => $class]);
        $classes = trim(($align ? 'has-text-align-' . $align . ' ' : '') . $class);
        return '<!-- wp:paragraph ' . ($attrs ? wp_json_encode($attrs) . ' ' : '') . '-->'
            . '<p' . ($classes ? ' class="' . esc_attr($classes) . '"' : '') . '>' . $html . '</p><!-- /wp:paragraph -->';
    };
    $column = fn(string $inner, string $width = '') => '<!-- wp:column ' . ($width ? wp_json_encode(['width' => $width]) : '') . ' -->'
        . '<div class="wp-block-column"' . ($width ? ' style="flex-basis:' . esc_attr($width) . '"' : '') . '>' . $inner . '</div><!-- /wp:column -->';
    $columns = fn(string $inner, string $class = '') => '<!-- wp:columns ' . ($class ? wp_json_encode(['className' => $class]) : '') . ' -->'
        . '<div class="wp-block-columns' . ($class ? ' ' . esc_attr($class) : '') . '">' . $inner . '</div><!-- /wp:columns -->';

    $title = '<!-- wp:heading {"level":1,"className":"is-style-opn-nameplate"} --><h1 class="wp-block-heading is-style-opn-nameplate">'
        . esc_html__('Newsletter name', 'omni-platform-newsletter') . '</h1><!-- /wp:heading -->';
    $contact = '<!-- wp:group {"className":"opn-contact-card"} --><div class="wp-block-group opn-contact-card">'
        . $para(inlineHtml((string) $parts['contact_html']), '', 'right') . '</div><!-- /wp:group -->';
    $bar = '<!-- wp:group {"className":"opn-masthead-bar"} --><div class="wp-block-group opn-masthead-bar">'
        . $columns(
            $column($para($siteName), '40%')
            . $column($para($detail('issue_date', $details['issue_date']), '', 'center'), '24%')
            /* translators: 1: volume, 2: issue number */
            . $column($para(sprintf(esc_html__('Volume %1$s, Issue #%2$s', 'omni-platform-newsletter'), $detail('volume', '1'), $detail('issue_number', '1')), '', 'right'), '36%')
        )
        . '</div><!-- /wp:group -->';

    return $columns($column($para($siteName, 'is-style-opn-pill') . $title, '60%') . $column($contact, '40%')) . $bar;
}

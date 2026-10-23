<?php

/**
 * Plugin Name: Omni Platform Newsletter
 * Plugin URI: https://github.com/Ultimate-Multisite/omni-platform-newsletter
 * Description: Write one block newsletter and publish it to your website, email it to subscribers, and print it as a two-page letter.
 * Version: 0.2.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: newspack-newsletters, newsletter
 * Author: Ultimate Multisite Community
 * License: GPL-2.0-or-later
 * Text Domain: omni-platform-newsletter
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const VERSION = '0.2.0';
const ISSUE_META_KEY = '_omni_platform_newsletter_layout';

/** Return post types supported by the current authoring adapter. */
function supportedPostTypes(): array
{
    return apply_filters('omni_platform_newsletter_post_types', ['newspack_nl_cpt']);
}

/** Only explicitly opted-in newsletters are affected. */
function isIssue($post): bool
{
    return $post instanceof \WP_Post
        && in_array($post->post_type, supportedPostTypes(), true)
        && get_post_meta($post->ID, ISSUE_META_KEY, true) === '1';
}

/** Read named top-level sections without duplicating their stored content. */
function sections(\WP_Post $post): array
{
    $sections = [];
    foreach (parse_blocks($post->post_content) as $block) {
        if ($block['blockName'] === null && trim($block['innerHTML']) === '') {
            continue;
        }

        $classes = preg_split('/\s+/', $block['attrs']['className'] ?? '');
        $matched = false;
        foreach (['sidebar', 'front', 'back', 'footer'] as $role) {
            if (in_array('opn-source-' . $role, $classes, true)) {
                if (isset($sections[$role]) || $matched) {
                    return [];
                }
                $sections[$role] = $block;
                $matched = true;
            }
        }

        if (!$matched) {
            return [];
        }
    }

    return $sections;
}

/** Compose two print sides while storing shared regions only once. */
function renderIssue(\WP_Post $post): string
{
    $sections = sections($post);
    if (count($sections) !== 4) {
        return '<p class="opn-layout-warning" role="alert">Duplex layout needs Sidebar, Front, Back and Footer sections. Nothing has been omitted.</p>'
            . do_blocks($post->post_content);
    }

    $sidebar = render_block($sections['sidebar']);
    $footer = render_block($sections['footer']);
    $front = render_block($sections['front']);
    $back = render_block($sections['back']);

    // Print: two Letter sheets, each with the shared sidebar and footer.
    $print = '<div class="opn-print">';
    foreach (['front' => $front, 'back' => $back] as $side => $main) {
        $print .= '<section class="opn-sheet opn-sheet--' . $side . '" aria-label="Newsletter ' . $side . '">'
            . '<aside class="opn-sidebar">' . $sidebar . '</aside>'
            . '<div class="opn-main">' . $main . '</div>'
            . '<footer class="opn-sheet-footer">' . $footer . '</footer></section>';
    }
    $print .= '</div>';

    if (isPrintView()) {
        return '<div class="opn-issue">' . $print . '</div>';
    }

    // Website: shown inside the site's own post template (site header and footer),
    // as one continuous page with the sidebar once and no newsletter footer.
    // Printing switches to the sheets.
    $links = '<nav class="opn-web-actions" aria-label="' . esc_attr__('Newsletter', 'omni-platform-newsletter') . '">';
    $archive = get_post_type_archive_link($post->post_type);
    if ($archive && get_post_meta($post->ID, 'is_public', true)) {
        $links .= '<a href="' . esc_url($archive) . '">' . esc_html__('All newsletters', 'omni-platform-newsletter') . '</a>';
    }
    $links .= '<a href="' . esc_url(add_query_arg('opn_view', 'print', get_permalink($post))) . '">' . esc_html__('Print / PDF', 'omni-platform-newsletter') . '</a></nav>';

    $web = '<article class="opn-web">'
        . '<aside class="opn-sidebar">' . $sidebar . '</aside>'
        . '<div class="opn-main">' . $front . $back . '</div></article>';

    return '<div class="opn-issue alignwide">' . $links . $web . $print . '</div>';
}

/** Replace opted-in newsletter content before WordPress renders blocks and shortcodes. */
function filterWebContent(string $content): string
{
    $post = get_post();
    if (!isIssue($post) || is_admin() || post_password_required($post)) {
        return $content;
    }

    // Do not feed the web/print composition into Newspack's email renderer.
    $controller = '\Newspack\Newsletters\Email_Renderers\Renderer_Controller';
    if (class_exists($controller) && $controller::get_rendering_post()) {
        return $content;
    }

    return renderIssue($post);
}

add_filter('the_content', __NAMESPACE__ . '\\filterWebContent', 8);

/** Whether the current request is the on-screen two-page print view. */
function isPrintView(): bool
{
    return isset($_GET['opn_view']) && $_GET['opn_view'] === 'print';
}

add_action('wp_enqueue_scripts', function () {
    $printPost = get_post(absint($_GET['pmb_p'] ?? 0));
    if (!isIssue(get_post()) && !isIssue($printPost)) {
        return;
    }

    wp_enqueue_style('omni-platform-newsletter', plugins_url('layout.css', __FILE__), [], VERSION);
    wp_add_inline_style('omni-platform-newsletter', paletteCss());
    wp_enqueue_script('omni-platform-newsletter-overflow', plugins_url('overflow.js', __FILE__), [], VERSION, true);
    wp_localize_script('omni-platform-newsletter-overflow', 'omniNewsletterPrint', [
        'fits' => __('Fits on two pages. Ready to print.', 'omni-platform-newsletter'),
        /* translators: %s: comma-separated page numbers */
        'tooLong' => __('Too long to fit on page %s. Shorten the text or use smaller photos, then refresh this page.', 'omni-platform-newsletter'),
        /* translators: %d: page number */
        'sheetWarning' => __('Page %d is too long to fit. Nothing has been hidden: shorten the text or use smaller photos before printing.', 'omni-platform-newsletter'),
    ]);
}, 1100);

add_filter('body_class', function (array $classes) {
    if (isPrintView() && isIssue(get_post())) {
        $classes[] = 'opn-print-mode';
    }
    if (is_singular(supportedPostTypes()) && isIssue(get_post())) {
        $classes[] = 'opn-newsletter-single';
    }

    return $classes;
});

/** The print view is for preparing paper copies; keep it out of search results. */
add_action('wp_head', function () {
    if (isPrintView() && isIssue(get_post())) {
        echo '<meta name="robots" content="noindex">' . "\n";
    }
}, 1);

/** Lead email with primary stories and include shared details only once. */
function filterNewsletterContent(string $content, $post): string
{
    if (!isIssue($post)) {
        return $content;
    }

    $sections = sections($post);
    if (count($sections) !== 4) {
        wp_die(
            esc_html__('Newsletter email was not generated because the required Sidebar, Front, Back and Footer sections are invalid.', 'omni-platform-newsletter'),
            esc_html__('Newsletter layout error', 'omni-platform-newsletter'),
            ['response' => 422]
        );
        return '';
    }

    return serialize_blocks(emailBlocks($sections));
}

/** @return array<string, array{background: string, text: string}> Email colors per region (print uses layout.css). */
function emailColors(): array
{
    $p = palette();
    // Accent headings only where they stay readable on the colored panel.
    $onDark = contrast($p['accent'], $p['dark']) >= 3 ? $p['accent'] : $p['on_dark'];
    $onPrimary = contrast($p['accent'], $p['primary']) >= 3 ? $p['accent'] : $p['on_primary'];

    return (array) apply_filters('omni_platform_newsletter_email_colors', [
        'front' => ['background' => $p['background'], 'text' => $p['text'], 'heading' => $p['primary']],
        'back' => ['background' => $p['background'], 'text' => $p['text'], 'heading' => $p['primary']],
        'callout' => ['background' => $p['dark'], 'text' => $p['on_dark'], 'heading' => $onDark],
        'sidebar' => ['background' => $p['primary'], 'text' => $p['on_primary'], 'heading' => $onPrimary],
        'footer' => ['background' => $p['text'], 'text' => $p['on_text'], 'heading' => $p['on_text']],
        'label' => ['background' => $p['accent'], 'text' => $p['on_accent'], 'heading' => $p['on_accent']],
    ]);
}

/** Give headings and the issue date the same accents as the printed letter. */
function styleEmailBlock(array $block, string $region): array
{
    $colors = emailColors();
    $classes = preg_split('/\s+/', (string) ($block['attrs']['className'] ?? ''));
    if ($block['blockName'] === 'core/heading' || in_array('opn-kicker', $classes, true)) {
        $block['attrs']['style']['color']['text'] = $colors[$region]['heading'] ?? $colors['front']['heading'];
    }
    if ($block['blockName'] === 'core/paragraph' && in_array('opn-issue-label', $classes, true)) {
        $block['attrs']['style']['color'] = ['background' => $colors['label']['background'], 'text' => $colors['label']['text']];
        $block['attrs']['align'] = 'right';
    }

    return $block;
}

/** A top-level group block for the email, carrying a region's colors. */
function emailGroup(array $innerBlocks, string $region, string $className): array
{
    $colors = emailColors()[$region] ?? emailColors()['front'];
    $innerBlocks = array_map(fn($block) => styleEmailBlock($block, $region), $innerBlocks);

    return [
        'blockName' => 'core/group',
        'attrs' => [
            'className' => trim($className),
            'style' => ['color' => ['background' => $colors['background'], 'text' => $colors['text']]],
        ],
        'innerBlocks' => $innerBlocks,
        'innerHTML' => '<div class="wp-block-group"></div>',
        'innerContent' => ['<div class="wp-block-group">', ...array_fill(0, count($innerBlocks), null), '</div>'],
    ];
}

/** Content blocks of a group with any deeper groups dissolved into their contents. */
function flattenGroups(array $blocks): array
{
    $flat = [];
    foreach ($blocks as $block) {
        if ($block['blockName'] === 'core/group') {
            array_push($flat, ...flattenGroups($block['innerBlocks']));
        } elseif ($block['blockName'] !== null) {
            $flat[] = $block;
        }
    }

    return $flat;
}

/**
 * Email order: stories first, shared contacts once, then the footer.
 *
 * Email layout cannot nest sections inside sections, so each story or callout
 * group inside a page becomes its own top-level email section, in order.
 */
function emailBlocks(array $sections): array
{
    $out = [];
    foreach (['front', 'back', 'sidebar', 'footer'] as $role) {
        $section = $sections[$role];
        if ($section['blockName'] !== 'core/group') {
            $out[] = emailGroup([$section], $role, 'opn-email-' . $role);
            continue;
        }

        $loose = [];
        foreach ($section['innerBlocks'] as $child) {
            if ($child['blockName'] === null) {
                continue;
            }
            if ($child['blockName'] !== 'core/group') {
                $loose[] = $child;
                continue;
            }
            if ($loose) {
                $out[] = emailGroup($loose, $role, 'opn-email-' . $role);
                $loose = [];
            }
            $isCallout = str_contains((string) ($child['attrs']['className'] ?? ''), 'opn-callout');
            $region = $isCallout ? 'callout' : $role;
            $out[] = emailGroup(flattenGroups($child['innerBlocks']), $region, 'opn-email-' . $region . ' ' . ($child['attrs']['className'] ?? ''));
        }
        if ($loose) {
            $out[] = emailGroup($loose, $role, 'opn-email-' . $role);
        }
    }

    return $out;
}

add_filter('newspack_newsletters_newsletter_content', __NAMESPACE__ . '\\filterNewsletterContent', 20, 2);

add_filter('template_include', function ($template) {
    // The website view uses the theme's own post template; only the print view is standalone.
    if (isPrintView() && is_singular(supportedPostTypes()) && isIssue(get_post()) && !isset($_GET['print-my-blog'])) {
        return __DIR__ . '/web-template.php';
    }

    return $template;
}, 999);

/** Use Pro Print because Print My Blog Quick Print rejects custom post types. */
function printUrl(\WP_Post $post): string
{
    return wp_nonce_url(add_query_arg([
        'pmb_loading' => 1,
        'pmb_f' => 'print_pdf',
        'pmb_p' => $post->ID,
    ], site_url('/')), 'pmb_loading');
}

add_filter('post_row_actions', function ($actions, $post) {
    if (isIssue($post) && current_user_can('edit_post', $post->ID)) {
        $actions['opn_print'] = '<a href="' . esc_url(printPreviewUrl($post)) . '" target="_blank" rel="noopener">' . esc_html__('Print / PDF', 'omni-platform-newsletter') . '</a>';
    }

    return $actions;
}, 10, 2);

add_action('save_post_newspack_nl_cpt', function ($id, $post) {
    if (!wp_is_post_revision($id) && count(sections($post)) === 4) {
        update_post_meta($id, ISSUE_META_KEY, '1');
    }
}, 10, 2);

/** Return a content-neutral pattern; organizations provide all facts and assets. */
function starterPatternContent(): string
{
    return <<<'BLOCKS'
<!-- wp:group {"className":"opn-source-sidebar","lock":{"move":true,"remove":true}} --><div class="wp-block-group opn-source-sidebar"><!-- wp:paragraph {"className":"opn-motto"} --><p class="opn-motto">Organization motto</p><!-- /wp:paragraph --><!-- wp:group {"className":"opn-contact-card"} --><div class="wp-block-group opn-contact-card"><!-- wp:heading --><h2 class="wp-block-heading">Contact details</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Add approved names, roles, phone numbers, email addresses, and mailing address.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:heading --><h2 class="wp-block-heading">Support our work</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Add approved giving details and QR destination.</p><!-- /wp:paragraph --></div><!-- /wp:group -->
<!-- wp:group {"className":"opn-source-front","lock":{"move":true,"remove":true}} --><div class="wp-block-group opn-source-front"><!-- wp:paragraph {"className":"opn-issue-label"} --><p class="opn-issue-label">Month Year</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"opn-kicker"} --><p class="opn-kicker">Organization newsletter</p><!-- /wp:paragraph --><!-- wp:heading {"level":1,"className":"opn-title"} --><h1 class="wp-block-heading opn-title">Newsletter title</h1><!-- /wp:heading --><!-- wp:group {"className":"opn-story"} --><div class="wp-block-group opn-story"><!-- wp:heading --><h2 class="wp-block-heading">Lead story</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Add approved story copy and photographs.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:group {"className":"opn-callout"} --><div class="wp-block-group opn-callout"><!-- wp:heading --><h2 class="wp-block-heading">Upcoming opportunity</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Add current, confirmed event information.</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:group -->
<!-- wp:group {"className":"opn-source-back","lock":{"move":true,"remove":true}} --><div class="wp-block-group opn-source-back"><!-- wp:group {"className":"opn-story"} --><div class="wp-block-group opn-story"><!-- wp:heading --><h2 class="wp-block-heading">More news</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Add approved story copy and photographs.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:group {"className":"opn-callout"} --><div class="wp-block-group opn-callout"><!-- wp:heading --><h2 class="wp-block-heading">Requests and next steps</h2><!-- /wp:heading --><!-- wp:list --><ul class="wp-block-list"><li>Add a current item.</li><li>Add a current item.</li></ul><!-- /wp:list --></div><!-- /wp:group --></div><!-- /wp:group -->
<!-- wp:paragraph {"className":"opn-source-footer","lock":{"move":true,"remove":true}} --><p class="opn-source-footer">Add the organization website and approved legal footer.</p><!-- /wp:paragraph -->
BLOCKS;
}

add_action('init', function () {
    if (function_exists('register_block_pattern')) {
        register_block_pattern('omni-platform-newsletter/duplex-starter', [
            'title' => __('Two-page newsletter layout', 'omni-platform-newsletter'),
            'description' => __('Enter the Sidebar and Footer once; Front and Back become the two printed pages.', 'omni-platform-newsletter'),
            'postTypes' => supportedPostTypes(),
            'content' => starterPatternContent(),
        ]);
    }
});

require_once __DIR__ . '/includes/palette.php';
require_once __DIR__ . '/includes/capabilities.php';
require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/rest.php';
// Admin screens plus helpers (settings, print links) that the front end also uses.
require_once __DIR__ . '/includes/admin.php';

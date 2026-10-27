<?php

/**
 * Plugin Name: Omni Platform Newsletter
 * Plugin URI: https://github.com/Ultimate-Multisite/omni-platform-newsletter
 * Description: Write one block newsletter and publish it to your website, email it to subscribers, and print it as a two-page letter.
 * Version: 0.3.0
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

const VERSION = '0.3.0';
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
    $web = '<article class="opn-web">'
        . '<div class="opn-main">' . $front . $back . '</div>'
        . '<aside class="opn-sidebar"><div class="opn-sidebar__inner">' . $sidebar . '</div></aside></article>';

    return '<div class="opn-issue alignwide">' . breadcrumb($post) . $web . $print . '</div>';
}

/** Home › Newsletters › This issue, with schema.org markup. */
function breadcrumb(\WP_Post $post): string
{
    $items = [[__('Home', 'omni-platform-newsletter'), home_url('/')]];
    $archive = get_post_type_archive_link($post->post_type);
    if ($archive) {
        $type = get_post_type_object($post->post_type);
        $items[] = [$type ? $type->labels->name : __('Newsletters', 'omni-platform-newsletter'), $archive];
    }
    $items[] = [get_the_title($post), ''];

    $html = '<nav class="opn-breadcrumb" aria-label="' . esc_attr__('Breadcrumb', 'omni-platform-newsletter') . '">'
        . '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';
    foreach ($items as $i => [$label, $url]) {
        $html .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">'
            . ($url !== ''
                ? '<a itemprop="item" href="' . esc_url($url) . '"><span itemprop="name">' . esc_html($label) . '</span></a>'
                : '<span itemprop="name" aria-current="page">' . esc_html($label) . '</span>')
            . '<meta itemprop="position" content="' . ($i + 1) . '"></li>';
    }

    return $html . '</ol></nav>';
}

/**
 * Block themes: a single-newsletter template with the site's header and footer
 * but no post-title banner (each issue has its own masthead). Themes can
 * override it with their own single-newspack_nl_cpt template.
 */
add_action('init', function () {
    if (!function_exists('register_block_template') || !wp_is_block_theme()) {
        return;
    }

    $content = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
        . '<!-- wp:group {"tagName":"main","align":"full","className":"opn-newsletter-main","style":{"spacing":{"padding":{"top":"2rem","bottom":"4rem"}}},"layout":{"type":"constrained"}} -->'
        . '<main class="wp-block-group alignfull opn-newsletter-main" style="padding-top:2rem;padding-bottom:4rem">'
        . '<!-- wp:post-content {"align":"wide","layout":{"type":"constrained"}} /--></main><!-- /wp:group -->'
        . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';

    foreach (supportedPostTypes() as $type) {
        register_block_template('omni-platform-newsletter//single-' . $type, [
            'title' => __('Single newsletter', 'omni-platform-newsletter'),
            'description' => __('A newsletter issue with the site header and footer.', 'omni-platform-newsletter'),
            'post_types' => [$type],
            'content' => $content,
        ]);
    }
}, 20);

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

/** The site's published giving page, if it has one ('' otherwise). */
function givingPageUrl(): string
{
    foreach (['donate', 'give', 'giving', 'support-us'] as $slug) {
        $page = get_page_by_path($slug);
        if ($page instanceof \WP_Post && $page->post_status === 'publish') {
            return (string) get_permalink($page);
        }
    }

    return '';
}

/**
 * The parts of a first newsletter, pre-filled from the site so a new user only
 * writes the stories: site name, tagline, this month, the writer's name and
 * email, the website address and the giving page.
 *
 * Text values are inline HTML (links, emphasis, line breaks; anything else is
 * stripped so each part stays valid inside its block); image values are
 * attachment IDs (0 for none); help_items is a list of inline-HTML list items.
 *
 * @return array<string, mixed>
 */
function starterParts(): array
{
    $siteName = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    $tagline = wp_specialchars_decode((string) get_bloginfo('description'), ENT_QUOTES);
    $home = home_url('/');
    $host = (string) preg_replace('/^www\./', '', (string) wp_parse_url($home, PHP_URL_HOST));
    $user = wp_get_current_user();
    $name = $user->exists() ? trim($user->first_name . ' ' . $user->last_name) : '';
    if ($name === '' && $user->exists() && $user->display_name !== $user->user_login) {
        $name = $user->display_name; // A username is not a name to sign a letter with.
    }
    $email = $user->exists() ? (string) $user->user_email : '';
    $give = givingPageUrl();
    $link = fn(string $url, string $label) => '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    $site = $link($home, $host);
    $mail = $email !== '' ? $link('mailto:' . $email, $email) : '';
    $contact = $mail !== '' ? $mail : $site;

    $parts = [
        'motto' => esc_html($tagline !== '' ? $tagline : $siteName),
        'contact_heading' => esc_html__('Contact us', 'omni-platform-newsletter'),
        'contact_html' => implode('<br>', array_filter([esc_html($name), $mail, $site])),
        'give_heading' => esc_html__('Support our work', 'omni-platform-newsletter'),
        'give_html' => $give !== ''
            /* translators: %s: link to the giving page */
            ? sprintf(esc_html__('Your gifts make this work possible. Give online at %s.', 'omni-platform-newsletter'), $link($give, untrailingslashit((string) preg_replace('#^https?://(www\.)?#', '', $give))))
            /* translators: %s: email or website link */
            : sprintf(esc_html__('Your gifts make this work possible. Contact us at %s to give.', 'omni-platform-newsletter'), $contact),
        'label' => esc_html(wp_date('F Y')),
        'kicker' => esc_html($siteName),
        'title' => esc_html__('Write this issue’s headline', 'omni-platform-newsletter'),
        'lead_heading' => esc_html__('Your main story', 'omni-platform-newsletter'),
        'lead_image' => 0,
        'lead_html' => esc_html__('Tell one story from this month: who was involved, what happened, and why it matters. Press + to add a photo.', 'omni-platform-newsletter'),
        'event_heading' => esc_html__('Coming up', 'omni-platform-newsletter'),
        'event_html' => esc_html__('Add the date, time and place of your next event, and how to sign up.', 'omni-platform-newsletter'),
        'more_heading' => esc_html__('More news', 'omni-platform-newsletter'),
        'more_image' => 0,
        'more_html' => esc_html__('Share a second story, a thank-you, or a volunteer spotlight.', 'omni-platform-newsletter'),
        'help_heading' => esc_html__('How you can help', 'omni-platform-newsletter'),
        'help_items' => [
            esc_html__('Pray for our work and the people we serve.', 'omni-platform-newsletter'),
            /* translators: %s: email or website link */
            sprintf(esc_html__('Volunteer: contact us at %s.', 'omni-platform-newsletter'), $contact),
            $give !== ''
                /* translators: %s: link to the giving page */
                ? sprintf(esc_html__('Give online at %s.', 'omni-platform-newsletter'), $link($give, __('our giving page', 'omni-platform-newsletter')))
                : esc_html__('Give: reply to this newsletter to find out how.', 'omni-platform-newsletter'),
        ],
        'footer_html' => esc_html($siteName) . ' · ' . $site,
    ];

    /**
     * Pre-filled parts of an organization's first newsletter.
     *
     * @param array<string, mixed> $parts See starterParts().
     */
    return (array) apply_filters('omni_platform_newsletter_starter_parts', $parts);
}

/** Inline HTML only, so starter text can sit inside a paragraph, heading or list item. */
function inlineHtml(string $html): string
{
    $attrs = ['class' => true, 'title' => true];

    return wp_kses($html, [
        'a' => ['href' => true, 'target' => true, 'rel' => true] + $attrs,
        'br' => [],
        'strong' => $attrs, 'b' => $attrs, 'em' => $attrs, 'i' => $attrs,
        'span' => $attrs, 'mark' => $attrs, 'code' => $attrs, 'sub' => [], 'sup' => [],
    ]);
}

/** Serialized block markup for an image attachment ('' when it no longer exists). */
function starterImage(int $id): string
{
    $url = $id > 0 ? wp_get_attachment_image_url($id, 'large') : false;
    if (!$url) {
        return '';
    }
    $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);

    return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large","linkDestination":"none"} -->'
        . '<figure class="wp-block-image size-large"><img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="wp-image-' . $id . '"/></figure>'
        . '<!-- /wp:image -->';
}

/** Build the four-section starter newsletter from its parts (see starterParts()). */
function starterPatternContent(?array $parts = null): string
{
    $defaults = starterParts();
    $p = array_merge($defaults, (array) ($parts ?? []));
    $html = fn(string $key) => inlineHtml((string) ($p[$key] ?? $defaults[$key]));
    $para = fn(string $key) => '<!-- wp:paragraph --><p>' . $html($key) . '</p><!-- /wp:paragraph -->';
    $heading = fn(string $key) => '<!-- wp:heading --><h2 class="wp-block-heading">' . $html($key) . '</h2><!-- /wp:heading -->';
    $classed = fn(string $class, string $key) => '<!-- wp:paragraph {"className":"' . $class . '"} --><p class="' . $class . '">' . $html($key) . '</p><!-- /wp:paragraph -->';
    $group = fn(string $class, string $inner, bool $locked = false) => '<!-- wp:group {"className":"' . $class . '"' . ($locked ? ',"lock":{"move":true,"remove":true}' : '') . '} -->'
        . '<div class="wp-block-group ' . $class . '">' . $inner . '</div><!-- /wp:group -->';
    $items = '';
    foreach ((array) ($p['help_items'] ?: $defaults['help_items']) as $item) {
        $items .= '<!-- wp:list-item --><li>' . inlineHtml((string) $item) . '</li><!-- /wp:list-item -->';
    }

    $sidebar = $group('opn-source-sidebar', $classed('opn-motto', 'motto')
        . $group('opn-contact-card', $heading('contact_heading') . $para('contact_html'))
        . $heading('give_heading') . $para('give_html'), true);
    $front = $group('opn-source-front', $classed('opn-issue-label', 'label') . $classed('opn-kicker', 'kicker')
        . '<!-- wp:heading {"level":1,"className":"opn-title"} --><h1 class="wp-block-heading opn-title">' . $html('title') . '</h1><!-- /wp:heading -->'
        . $group('opn-story', $heading('lead_heading') . starterImage((int) $p['lead_image']) . $para('lead_html'))
        . $group('opn-callout', $heading('event_heading') . $para('event_html')), true);
    $back = $group('opn-source-back', $group('opn-story', $heading('more_heading') . starterImage((int) $p['more_image']) . $para('more_html'))
        . $group('opn-callout', $heading('help_heading') . '<!-- wp:list --><ul class="wp-block-list">' . $items . '</ul><!-- /wp:list -->'), true);
    $footer = '<!-- wp:paragraph {"className":"opn-source-footer","lock":{"move":true,"remove":true}} --><p class="opn-source-footer">' . $html('footer_html') . '</p><!-- /wp:paragraph -->';

    return implode("\n", [$sidebar, $front, $back, $footer]);
}

/** Only editors need the pattern; building it reads the site and the current user. */
function registerStarterPattern(): void
{
    if (function_exists('register_block_pattern') && !\WP_Block_Patterns_Registry::get_instance()->is_registered('omni-platform-newsletter/duplex-starter')) {
        register_block_pattern('omni-platform-newsletter/duplex-starter', [
            'title' => __('Two-page newsletter layout', 'omni-platform-newsletter'),
            'description' => __('Enter the Sidebar and Footer once; Front and Back become the two printed pages.', 'omni-platform-newsletter'),
            'postTypes' => supportedPostTypes(),
            'content' => starterPatternContent(),
        ]);
    }
}

add_action('admin_init', __NAMESPACE__ . '\\registerStarterPattern');
add_action('rest_api_init', __NAMESPACE__ . '\\registerStarterPattern');

require_once __DIR__ . '/includes/palette.php';
require_once __DIR__ . '/includes/capabilities.php';
require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/archive.php';
// Admin screens plus helpers (settings, print links) that the front end also uses.
require_once __DIR__ . '/includes/admin.php';

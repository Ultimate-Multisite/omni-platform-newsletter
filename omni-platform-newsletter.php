<?php

/**
 * Plugin Name: Omni Platform Newsletter
 * Plugin URI: https://github.com/Ultimate-Multisite/omni-platform-newsletter
 * Description: Write one block newsletter and publish it to your website, email it to subscribers, and print it as a two-page letter.
 * Version: 0.5.0
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

const VERSION = '0.5.0';
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
    $colors = issueColors($post);
    $style = fn(string $side) => ' style="' . esc_attr(colorVars($colors[$side])) . '"';
    // A page border only when its colour differs from the page itself.
    $framed = fn(string $side) => ($colors[$side]['frame'] ?? '') !== ($colors[$side]['page'] ?? '');
    $details = issueDetails($post);
    $top = sharedArea($post) === 'top';
    // A masthead runs across the top of each page; a sidebar sits beside it.
    $shared = fn(string $html, string $attrs = '') => $top
        ? '<header class="opn-sidebar opn-masthead"' . $attrs . '>' . $html . '</header>'
        : '<aside class="opn-sidebar"' . $attrs . '>' . $html . '</aside>';

    // Print: two Letter sheets, each with the shared section and footer, in that page's colours.
    $print = '<div class="opn-print">';
    foreach (['front' => $front, 'back' => $back] as $side => $main) {
        $page = $side === 'front' ? '1' : '2';
        $classes = 'opn-sheet opn-sheet--' . $side . ($top ? ' opn-sheet--top' : '') . ($framed($side) ? ' opn-sheet--framed' : '')
            . (printTextSize($post) !== '' ? ' opn-text--' . printTextSize($post) : '');
        $print .= '<section class="' . $classes . '"' . $style($side) . ' aria-label="Newsletter ' . $side . '">'
            . $shared(fillDetails($sidebar, $details, $page))
            . '<div class="opn-main">' . fillDetails($main, $details, $page) . '</div>'
            . '<footer class="opn-sheet-footer">' . fillDetails($footer, $details, $page) . '</footer></section>';
    }
    $print .= '</div>';

    if (isPrintView()) {
        return '<div class="opn-issue">' . $print . '</div>';
    }

    // Website: shown inside the site's own post template (site header and footer),
    // as one continuous page with the shared section once and no newsletter footer.
    // Printing switches to the sheets.
    $pageDiv = fn(string $side, string $html) => '<div class="opn-page opn-page--' . $side . ($framed($side) ? ' opn-page--framed' : '') . '"' . $style($side) . '>'
        . fillDetails($html, $details) . '</div>';
    $sharedWeb = $shared('<div class="opn-sidebar__inner">' . fillDetails($sidebar, $details) . '</div>', $style('front'));
    $main = '<div class="opn-main">' . $pageDiv('front', $front) . $pageDiv('back', $back) . '</div>';
    $web = '<article class="opn-web' . ($top ? ' opn-web--top' : '') . '">' . ($top ? $sharedWeb . $main : $main . $sharedWeb) . '</article>';

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

    wp_register_style('omni-platform-newsletter-blocks', plugins_url('assets/blocks.css', __FILE__), [], VERSION);
    wp_enqueue_style('omni-platform-newsletter', plugins_url('layout.css', __FILE__), ['omni-platform-newsletter-blocks'], VERSION);
    wp_add_inline_style('omni-platform-newsletter', paletteCss());
    wp_enqueue_script('omni-platform-newsletter-overflow', plugins_url('overflow.js', __FILE__), [], VERSION, true);
    wp_localize_script('omni-platform-newsletter-overflow', 'omniNewsletterPrint', [
        'fits' => __('Fits on two pages. Ready to print.', 'omni-platform-newsletter'),
        /* translators: %s: comma-separated page numbers */
        'tooLong' => __('Too long to fit on page %s. Shorten the text, use smaller photos, or choose a smaller “Printed text size” in the editor, then refresh this page.', 'omni-platform-newsletter'),
        /* translators: %d: page number */
        'sheetWarning' => __('Page %d is too long to fit. Nothing has been hidden: shorten the text, use smaller photos, or choose a smaller printed text size before printing.', 'omni-platform-newsletter'),
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

    // Issue details filled in; text showing the page number is for paper only.
    $details = issueDetails($post);
    foreach ($sections as $role => $section) {
        $sections[$role] = emailDetailBlock($section, $details) ?? ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    }

    return serialize_blocks(emailBlocks($sections, $post));
}

/**
 * Email colours per region for one printed page (print and web use the same issueColors()).
 *
 * @return array<string, array{background: string, text: string, heading: string}>
 */
function emailColors(?\WP_Post $post = null, string $side = 'front'): array
{
    $c = issueColors($post)[$side] ?? issueColors($post)['front'];
    $page = ['background' => $c['page'], 'text' => $c['pageText'], 'heading' => $c['heading'], 'title' => $c['title']];

    return (array) apply_filters('omni_platform_newsletter_email_colors', [
        'front' => $page,
        'back' => $page,
        'callout' => ['background' => $c['callout'], 'text' => $c['calloutText'], 'heading' => $c['calloutHeading']],
        'sidebar' => ['background' => $c['sidebar'], 'text' => $c['sidebarText'], 'heading' => $c['sidebarHeading']],
        'card' => ['background' => $c['card'], 'text' => $c['cardText'], 'heading' => $c['cardHeading']],
        'motto' => ['background' => $c['motto'], 'text' => $c['mottoText'], 'heading' => $c['mottoText']],
        'footer' => ['background' => $c['footer'], 'text' => $c['footerText'], 'heading' => $c['footerText']],
        'label' => ['background' => $c['label'], 'text' => $c['labelText'], 'heading' => $c['labelText']],
        // "Rounded box" groups: a light tint of the tag colour, as on the printed page.
        'box' => ['background' => mixColors($c['label'], $c['page'], 0.16), 'text' => $c['pageText'], 'heading' => $c['heading']],
    ], $side, $post);
}

/** Whether the author gave this block its own text colour in the editor. */
function hasOwnTextColor(array $block): bool
{
    return !empty($block['attrs']['textColor']) || !empty($block['attrs']['style']['color']['text']);
}

/** Give headings, the title and the issue date the same colours as the printed letter. */
function styleEmailBlock(array $block, string $region, array $colors): array
{
    $classes = preg_split('/\s+/', (string) ($block['attrs']['className'] ?? ''));
    $regionColors = $colors[$region] ?? $colors['front'];
    $own = hasOwnTextColor($block);
    if (in_array('opn-title', $classes, true) && !$own) {
        $block['attrs']['style']['color']['text'] = $regionColors['title'] ?? $regionColors['heading'];
    } elseif (($block['blockName'] === 'core/heading' || in_array('opn-kicker', $classes, true)) && !$own) {
        $block['attrs']['style']['color']['text'] = $regionColors['heading'];
    }
    if ($block['blockName'] === 'core/paragraph' && array_intersect(['opn-issue-label', 'is-style-opn-label', 'is-style-opn-tag'], $classes)) {
        // Colours the author picked for this label win over the scheme's label colours.
        $block['attrs']['style']['color'] = array_filter((array) ($block['attrs']['style']['color'] ?? []))
            + array_filter([
                'background' => empty($block['attrs']['backgroundColor']) ? $colors['label']['background'] : '',
                'text' => $own ? '' : $colors['label']['text'],
            ]);
        $block['attrs']['align'] = 'right';
    }
    if ($block['blockName'] === 'core/paragraph' && in_array('opn-motto', $classes, true) && empty($block['attrs']['style']['color']['background'])) {
        $block['attrs']['style']['color']['background'] = $colors['motto']['background'];
        if (!$own) {
            $block['attrs']['style']['color']['text'] = $colors['motto']['text'];
        }
    }
    // Boxes and contact cards inside columns keep their colours (top-level ones become their own sections).
    $nested = in_array('is-style-opn-box', $classes, true) ? 'box' : (in_array('opn-contact-card', $classes, true) ? 'card' : '');
    if ($block['blockName'] === 'core/group' && $nested !== '' && isset($colors[$nested])) {
        $block['attrs']['style']['color'] = array_filter((array) ($block['attrs']['style']['color'] ?? [])) + [
            'background' => $colors[$nested]['background'],
            'text' => $colors[$nested]['text'],
        ];
        $region = $nested;
    }
    if ($block['blockName'] === 'core/image') {
        // Email cannot wrap text around photos: sized photos keep a matching width (of a 600px email).
        foreach (['opn-photo-small' => 160, 'opn-photo-medium' => 240, 'opn-photo-large' => 320] as $class => $width) {
            if (in_array($class, $classes, true)) {
                $block['attrs']['width'] = $width;
            }
        }
        // The email renderer appends "px" itself; "84px" would become "84pxpx".
        if (isset($block['attrs']['width']) && is_string($block['attrs']['width']) && preg_match('/^(\d+(?:\.\d+)?)px$/', $block['attrs']['width'], $m)) {
            $block['attrs']['width'] = (int) round((float) $m[1]);
        }
    }
    // Headings inside columns and other containers need the region's colours too.
    if (!empty($block['innerBlocks'])) {
        $block['innerBlocks'] = array_map(fn($inner) => styleEmailBlock($inner, $region, $colors), $block['innerBlocks']);
    }

    return $block;
}

/** A top-level group block for the email, carrying a region's colors. */
function emailGroup(array $innerBlocks, string $region, string $className, array $colors): array
{
    $innerBlocks = array_map(fn($block) => styleEmailBlock($block, $region, $colors), $innerBlocks);
    $colors = $colors[$region] ?? $colors['front'];

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
function emailBlocks(array $sections, ?\WP_Post $post = null): array
{
    $out = [];
    // A masthead opens the email; a sidebar's contact details follow the stories.
    $order = sharedArea($post) === 'top' ? ['sidebar', 'front', 'back', 'footer'] : ['front', 'back', 'sidebar', 'footer'];
    foreach ($order as $role) {
        $section = $sections[$role];
        // The sidebar and footer appear once in the email, in the front page's colours.
        $colors = emailColors($post, $role === 'back' ? 'back' : 'front');
        if ($section['blockName'] !== 'core/group') {
            $out[] = emailGroup([$section], $role, 'opn-email-' . $role, $colors);
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
                $out[] = emailGroup($loose, $role, 'opn-email-' . $role, $colors);
                $loose = [];
            }
            $childClass = (string) ($child['attrs']['className'] ?? '');
            $region = match (true) {
                str_contains($childClass, 'opn-callout') => 'callout',
                str_contains($childClass, 'opn-contact-card') => 'card',
                str_contains($childClass, 'opn-masthead-bar') => 'label',
                str_contains($childClass, 'is-style-opn-box') => 'box',
                default => $role,
            };
            $groupColors = $colors;
            // A background the author picked for this box is kept.
            $own = normalizeHex($child['attrs']['style']['color']['background'] ?? '');
            if ($own !== '' && isset($groupColors[$region])) {
                $groupColors[$region]['background'] = $own;
                $groupColors[$region]['text'] = normalizeHex($child['attrs']['style']['color']['text'] ?? '') ?: readableOn($own, palette()['text']);
            }
            $out[] = emailGroup(flattenGroups($child['innerBlocks']), $region, 'opn-email-' . $region . ' ' . $childClass, $groupColors);
        }
        if ($loose) {
            $out[] = emailGroup($loose, $role, 'opn-email-' . $role, $colors);
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
        // The issue month as an issue detail, so copies of this issue show their own month.
        'label' => '<span class="opn-detail" data-opn-detail="issue_date">' . esc_html(wp_date('F Y')) . '</span>',
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
        'span' => $attrs + ['data-opn-detail' => true], 'mark' => $attrs, 'code' => $attrs, 'sub' => [], 'sup' => [],
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
require_once __DIR__ . '/includes/colors.php';
require_once __DIR__ . '/includes/details.php';
require_once __DIR__ . '/includes/capabilities.php';
require_once __DIR__ . '/includes/qr.php';
require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/archive.php';
// Admin screens plus helpers (settings, print links) that the front end also uses.
require_once __DIR__ . '/includes/admin.php';

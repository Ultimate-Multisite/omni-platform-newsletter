<?php

/**
 * Per-issue colour schemes, with separate colours for the front and back pages.
 *
 * Each issue stores only the colours the author changed (COLORS_META_KEY, JSON).
 * Anything left unchanged follows the site palette (palette()); text left on
 * "Automatic" is chosen for contrast with its background. Web, print and email
 * all read the resolved colours from issueColors().
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const COLORS_META_KEY = '_omni_platform_newsletter_colors';
const SIDES = ['front', 'back'];

/**
 * Areas of a printed page and the colours each one has.
 *
 * Slot keys: background, text, heading, title. Each slot names a colour role.
 *
 * @return array<string, array{label: string, help: string, slots: array<string, string>}>
 */
function colorRegions(): array
{
    return [
        'page' => [
            'label' => __('Page', 'omni-platform-newsletter'),
            'help' => __('The main area with your stories.', 'omni-platform-newsletter'),
            'slots' => ['background' => 'page', 'text' => 'pageText', 'heading' => 'heading', 'title' => 'title'],
        ],
        'label' => [
            'label' => __('Date tags', 'omni-platform-newsletter'),
            'help' => __('Issue and photo labels (the “Issue label” style).', 'omni-platform-newsletter'),
            'slots' => ['background' => 'label', 'text' => 'labelText'],
        ],
        'callout' => [
            'label' => __('Highlight box', 'omni-platform-newsletter'),
            'help' => __('Boxes for events, announcements and prayer requests.', 'omni-platform-newsletter'),
            'slots' => ['background' => 'callout', 'text' => 'calloutText', 'heading' => 'calloutHeading'],
        ],
        'sidebar' => [
            'label' => __('Sidebar', 'omni-platform-newsletter'),
            'help' => __('The column beside each page.', 'omni-platform-newsletter'),
            'slots' => ['background' => 'sidebar', 'text' => 'sidebarText', 'heading' => 'sidebarHeading'],
        ],
        'motto' => [
            'label' => __('Motto box', 'omni-platform-newsletter'),
            'help' => __('The motto at the top of the sidebar.', 'omni-platform-newsletter'),
            'slots' => ['background' => 'motto', 'text' => 'mottoText'],
        ],
        'card' => [
            'label' => __('Contact box', 'omni-platform-newsletter'),
            'help' => __('The address box in the sidebar.', 'omni-platform-newsletter'),
            'slots' => ['background' => 'card', 'text' => 'cardText', 'heading' => 'cardHeading'],
        ],
        'footer' => [
            'label' => __('Footer strip', 'omni-platform-newsletter'),
            'help' => __('The strip along the bottom of each page.', 'omni-platform-newsletter'),
            'slots' => ['background' => 'footer', 'text' => 'footerText'],
        ],
    ];
}

/** @return list<string> Every colour role. */
function colorRoles(): array
{
    $roles = [];
    foreach (colorRegions() as $region) {
        array_push($roles, ...array_values($region['slots']));
    }

    return $roles;
}

/** @return array<string, string> Site-palette colour for every role (the "Site colours" scheme). */
function defaultColors(): array
{
    $p = palette();
    // Accent headings only where they stay readable on the coloured panel.
    $onDark = contrast($p['accent'], $p['dark']) >= 3 ? $p['accent'] : $p['on_dark'];
    $onPrimary = contrast($p['accent'], $p['primary']) >= 3 ? $p['accent'] : $p['on_primary'];

    return [
        'page' => $p['background'], 'pageText' => $p['text'], 'heading' => $p['primary'], 'title' => $p['primary'],
        'label' => $p['accent'], 'labelText' => $p['on_accent'],
        'callout' => $p['dark'], 'calloutText' => $p['on_dark'], 'calloutHeading' => $onDark,
        'sidebar' => $p['primary'], 'sidebarText' => $p['on_primary'], 'sidebarHeading' => $onPrimary,
        'motto' => $p['primary'], 'mottoText' => $p['on_primary'],
        'card' => $p['dark'], 'cardText' => $p['on_dark'], 'cardHeading' => $onDark,
        'footer' => $p['text'], 'footerText' => $p['on_text'],
    ];
}

/**
 * Ready-made schemes. Each lists only the roles it changes; text left out is
 * chosen automatically for contrast.
 *
 * @return list<array{slug: string, label: string, colors: array<string, string>}>
 */
function colorSchemes(): array
{
    $schemes = [
        ['slug' => 'site', 'label' => __('Site colours', 'omni-platform-newsletter'), 'colors' => []],
        ['slug' => 'autumn-sky', 'label' => __('Autumn sky', 'omni-platform-newsletter'), 'colors' => [
            'page' => '#5b72f2', 'pageText' => '#ffffff', 'heading' => '#ffffff', 'title' => '#ffc93c',
            'label' => '#ffc93c', 'labelText' => '#323650',
            'callout' => '#323650', 'calloutHeading' => '#ffffff',
            'sidebar' => '#565e70', 'sidebarHeading' => '#ffb347',
            'motto' => '#323650', 'card' => '#5b72f2', 'cardHeading' => '#ffc93c',
            'footer' => '#000000',
        ]],
        ['slug' => 'autumn-harvest', 'label' => __('Autumn harvest', 'omni-platform-newsletter'), 'colors' => [
            'page' => '#ffffff', 'pageText' => '#111111', 'heading' => '#111111', 'title' => '#e8463c',
            'label' => '#ff5757', 'labelText' => '#111111',
            'callout' => '#323650', 'calloutHeading' => '#ff5757',
            'sidebar' => '#323650', 'sidebarHeading' => '#c3d94e',
            'motto' => '#ff5757', 'card' => '#ff5757', 'cardText' => '#323650', 'cardHeading' => '#323650',
            'footer' => '#000000',
        ]],
        ['slug' => 'winter', 'label' => __('Winter', 'omni-platform-newsletter'), 'colors' => [
            'page' => '#ffffff', 'heading' => '#1f3a5f', 'title' => '#1f3a5f',
            'label' => '#a9d6ef', 'callout' => '#1f3a5f', 'calloutHeading' => '#a9d6ef',
            'sidebar' => '#2e5a88', 'sidebarHeading' => '#d6ecf8',
            'motto' => '#1f3a5f', 'card' => '#14273f', 'cardHeading' => '#a9d6ef',
            'footer' => '#0e1a2b',
        ]],
        ['slug' => 'spring', 'label' => __('Spring', 'omni-platform-newsletter'), 'colors' => [
            'page' => '#fbfdf7', 'heading' => '#2f6b3f', 'title' => '#c2416b',
            'label' => '#f7c8d8', 'callout' => '#2f6b3f', 'calloutHeading' => '#f7c8d8',
            'sidebar' => '#3f7d4e', 'sidebarHeading' => '#fde68a',
            'motto' => '#2c5a37', 'card' => '#2c5a37', 'cardHeading' => '#f7c8d8',
            'footer' => '#1f3324',
        ]],
        ['slug' => 'summer', 'label' => __('Summer', 'omni-platform-newsletter'), 'colors' => [
            'page' => '#fffdf6', 'heading' => '#0b6774', 'title' => '#d9650f',
            'label' => '#ffd166', 'callout' => '#0a5a66', 'calloutHeading' => '#ffd166',
            'sidebar' => '#0f7c8c', 'sidebarHeading' => '#ffd166',
            'motto' => '#0a5a66', 'card' => '#0a5a66', 'cardHeading' => '#ffd166',
            'footer' => '#06343b',
        ]],
        ['slug' => 'christmas', 'label' => __('Christmas', 'omni-platform-newsletter'), 'colors' => [
            'page' => '#ffffff', 'heading' => '#1e5631', 'title' => '#b3202a',
            'label' => '#d4af37', 'callout' => '#b3202a', 'calloutHeading' => '#ffe08a',
            'sidebar' => '#1e5631', 'sidebarHeading' => '#ffe08a',
            'motto' => '#b3202a', 'card' => '#0f3a20', 'cardHeading' => '#ffe08a',
            'footer' => '#111111',
        ]],
    ];

    /**
     * Colour schemes offered in the editor.
     *
     * @param list<array{slug: string, label: string, colors: array<string, string>}> $schemes
     */
    return (array) apply_filters('omni_platform_newsletter_color_schemes', $schemes);
}

/** A #rrggbb colour, or '' when the value is not a colour. */
function normalizeHex(mixed $value): string
{
    $rgb = is_string($value) ? hexToRgb($value) : null;

    return $rgb ? rgbToHex($rgb) : '';
}

/**
 * Keep only known roles with valid colours.
 *
 * @return array{linked: bool, front: array<string, string>, back: array<string, string>}
 */
function parseIssueColors(mixed $raw): array
{
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
    $data = is_array($data) ? $data : [];
    $roles = colorRoles();
    $out = ['linked' => !array_key_exists('linked', $data) || (bool) $data['linked'], 'front' => [], 'back' => []];
    foreach (SIDES as $side) {
        foreach ((array) ($data[$side] ?? []) as $role => $value) {
            $hex = normalizeHex($value);
            if ($hex !== '' && in_array($role, $roles, true)) {
                $out[$side][$role] = $hex;
            }
        }
    }

    return $out;
}

/** Sanitize the stored meta value: canonical JSON with known roles only. */
function sanitizeIssueColors(mixed $raw): string
{
    $colors = parseIssueColors($raw);
    if ($colors['linked'] && $colors['front'] === [] && $colors['back'] === []) {
        return '';
    }

    return (string) wp_json_encode([
        'linked' => $colors['linked'],
        'front' => (object) $colors['front'],
        'back' => (object) ($colors['linked'] ? [] : $colors['back']),
    ]);
}

/**
 * Fill every role from the author's choices and the site palette.
 *
 * Text left on "Automatic" follows its background; headings that would not
 * stand out from a changed background fall back to the text colour.
 *
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function resolveColors(array $overrides): array
{
    $defaults = defaultColors();
    $base = palette()['text'];
    $out = [];
    foreach (colorRegions() as $region) {
        $slots = $region['slots'];
        $bg = $overrides[$slots['background']] ?? $defaults[$slots['background']];
        $out[$slots['background']] = $bg;
        $text = $overrides[$slots['text']]
            ?? (isset($overrides[$slots['background']]) ? readableOn($bg, $base) : $defaults[$slots['text']]);
        $out[$slots['text']] = $text;
        foreach (['heading', 'title'] as $slot) {
            if (isset($slots[$slot])) {
                $role = $slots[$slot];
                $out[$role] = $overrides[$role]
                    ?? (contrast($defaults[$role], $bg) >= 3 ? $defaults[$role] : $text);
            }
        }
    }

    return $out;
}

/**
 * Resolved colours for both printed pages of an issue.
 *
 * @return array{front: array<string, string>, back: array<string, string>}
 */
function issueColors(?\WP_Post $post): array
{
    $stored = parseIssueColors($post ? get_post_meta($post->ID, COLORS_META_KEY, true) : '');
    $back = $stored['linked'] ? $stored['front'] : $stored['back'];
    $colors = ['front' => resolveColors($stored['front']), 'back' => resolveColors($back)];

    /**
     * Resolved per-page colours of an issue (web, print and email).
     *
     * @param array{front: array<string, string>, back: array<string, string>} $colors
     */
    return (array) apply_filters('omni_platform_newsletter_issue_colors', $colors, $post);
}

/** CSS custom properties for one page, for an inline style attribute. */
function colorVars(array $c): string
{
    $vars = [
        'paper' => 'page', 'text' => 'pageText', 'heading' => 'heading', 'title' => 'title',
        'accent' => 'label', 'on-accent' => 'labelText',
        'dark' => 'callout', 'on-dark' => 'calloutText', 'callout-heading' => 'calloutHeading',
        'sidebar' => 'sidebar', 'on-sidebar' => 'sidebarText', 'sidebar-heading' => 'sidebarHeading',
        'motto' => 'motto', 'on-motto' => 'mottoText',
        'card' => 'card', 'on-card' => 'cardText', 'card-heading' => 'cardHeading',
        'footer' => 'footer', 'on-footer' => 'footerText',
    ];
    $css = '';
    foreach ($vars as $name => $role) {
        $hex = normalizeHex($c[$role] ?? '');
        if ($hex !== '') {
            $css .= '--opn-' . $name . ':' . $hex . ';';
        }
    }

    return $css;
}

add_action('init', function () {
    foreach (supportedPostTypes() as $type) {
        register_post_meta($type, COLORS_META_KEY, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'show_in_rest' => true,
            'sanitize_callback' => __NAMESPACE__ . '\\sanitizeIssueColors',
            'auth_callback' => fn($allowed, $key, $postId) => current_user_can('edit_post', (int) $postId),
        ]);
    }
});

/** Data for the editor's Colours panel. */
function colorEditorData(\WP_Post $post): array
{
    $site = [];
    if (function_exists('wp_get_global_settings')) {
        foreach ((array) (wp_get_global_settings(['color', 'palette'])['theme'] ?? []) as $entry) {
            $hex = normalizeHex($entry['color'] ?? '');
            if ($hex !== '') {
                $site[] = ['name' => (string) ($entry['name'] ?? $hex), 'color' => $hex];
            }
        }
    }
    $schemes = array_map(fn($s) => [
        'slug' => (string) $s['slug'],
        'label' => (string) $s['label'],
        'colors' => (object) parseIssueColors(['front' => (array) $s['colors']])['front'],
        'preview' => resolveColors(parseIssueColors(['front' => (array) $s['colors']])['front']),
    ], colorSchemes());

    return [
        'metaKey' => COLORS_META_KEY,
        'regions' => colorRegions(),
        'defaults' => defaultColors(),
        'baseText' => palette()['text'],
        'schemes' => $schemes,
        'siteColors' => $site,
    ];
}

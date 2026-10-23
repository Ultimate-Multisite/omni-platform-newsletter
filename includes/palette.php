<?php

/**
 * Newsletter colors from the site's theme, so web, print and email match the website.
 *
 * Roles: primary (sidebar, headings) = the theme's link color; dark (callouts)
 * = a darker primary; accent (date band, headings on dark) = the theme's
 * brightest strong color; text/background = the theme's text and background.
 * Text on colored areas is chosen for contrast. Sites without a usable theme
 * palette fall back to a neutral slate/navy/yellow set.
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

/** @return array{0:int,1:int,2:int}|null */
function hexToRgb(string $hex): ?array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return null;
    }

    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function rgbToHex(array $rgb): string
{
    return sprintf('#%02x%02x%02x', ...array_map(fn($c) => max(0, min(255, (int) round($c))), $rgb));
}

/** @return array{0:float,1:float} Saturation and lightness (0–1). */
function saturationLightness(array $rgb): array
{
    [$r, $g, $b] = array_map(fn($c) => $c / 255, $rgb);
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $s = $max === $min ? 0.0 : ($l > 0.5 ? ($max - $min) / (2 - $max - $min) : ($max - $min) / ($max + $min));

    return [$s, $l];
}

function luminance(array $rgb): float
{
    $c = array_map(function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, $rgb);

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

function contrast(string $a, string $b): float
{
    $la = luminance(hexToRgb($a) ?? [0, 0, 0]);
    $lb = luminance(hexToRgb($b) ?? [0, 0, 0]);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** The more readable of the theme text color and white on a background. */
function readableOn(string $background, string $text): string
{
    return contrast($background, '#ffffff') >= contrast($background, $text) ? '#ffffff' : $text;
}

/** Resolve a theme.json color value (hex, var:preset|color|slug or var(--wp--preset--color--slug)). */
function resolveThemeColor($value, array $bySlug): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    if (preg_match('/(?:var:preset\|color\||--wp--preset--color--)([a-z0-9-]+)/i', $value, $m)) {
        $value = $bySlug[$m[1]] ?? '';
    }

    return hexToRgb($value) ? rgbToHex(hexToRgb($value)) : null;
}

/** @return array{primary:string, dark:string, accent:string, text:string, background:string, on_primary:string, on_dark:string, on_accent:string, on_text:string} */
function palette(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $colors = ['primary' => '#565e70', 'dark' => '#323650', 'accent' => '#ffda36', 'text' => '#111111', 'background' => '#ffffff'];

    if (function_exists('wp_get_global_settings')) {
        $bySlug = [];
        foreach ((array) (wp_get_global_settings(['color', 'palette'])['theme'] ?? []) as $entry) {
            if (!empty($entry['slug']) && hexToRgb((string) ($entry['color'] ?? ''))) {
                $bySlug[$entry['slug']] = rgbToHex(hexToRgb($entry['color']));
            }
        }

        $text = resolveThemeColor(wp_get_global_styles(['color', 'text']), $bySlug);
        $background = resolveThemeColor(wp_get_global_styles(['color', 'background']), $bySlug);
        $link = resolveThemeColor(wp_get_global_styles(['elements', 'link', 'color', 'text']), $bySlug);

        // Strong = clearly colored, excluding near-white tints (cream) and near-black shades.
        $strong = array_filter($bySlug, function ($hex) {
            [$s, $l] = saturationLightness(hexToRgb($hex));
            return $s >= 0.35 && $l >= 0.12 && $l <= 0.8;
        });
        $primary = $link && saturationLightness(hexToRgb($link))[0] >= 0.35 ? $link : null;
        if (!$primary && $strong) {
            // The darkest strong color reads best as a sidebar and heading color.
            uasort($strong, fn($a, $b) => luminance(hexToRgb($a)) <=> luminance(hexToRgb($b)));
            $primary = reset($strong);
        }

        if ($primary) {
            $accent = null;
            $best = 0.0;
            foreach ($strong as $hex) {
                [$s, $l] = saturationLightness(hexToRgb($hex));
                $score = $s * $l;
                if ($hex !== $primary && $l >= 0.45 && $score > $best) {
                    [$best, $accent] = [$score, $hex];
                }
            }

            $colors['primary'] = $primary;
            $colors['dark'] = rgbToHex(array_map(fn($c) => $c * 0.7, hexToRgb($primary)));
            $colors['accent'] = $accent ?? $colors['accent'];
            $colors['text'] = $text ?? $colors['text'];
            $colors['background'] = $background ?? $colors['background'];
        }
    }

    $colors['on_primary'] = readableOn($colors['primary'], $colors['text']);
    $colors['on_dark'] = readableOn($colors['dark'], $colors['text']);
    $colors['on_accent'] = readableOn($colors['accent'], $colors['text']);
    $colors['on_text'] = readableOn($colors['text'], $colors['text']);

    $cache = (array) apply_filters('omni_platform_newsletter_palette', $colors);

    return $cache;
}

/** CSS custom properties for the web and print layout. */
function paletteCss(): string
{
    $p = palette();

    return '.opn-issue{'
        . '--opn-sidebar:' . $p['primary'] . ';--opn-on-sidebar:' . $p['on_primary'] . ';'
        . '--opn-dark:' . $p['dark'] . ';--opn-on-dark:' . $p['on_dark'] . ';'
        . '--opn-accent:' . $p['accent'] . ';--opn-on-accent:' . $p['on_accent'] . ';'
        . '--opn-heading:' . $p['primary'] . ';--opn-text:' . $p['text'] . ';--opn-paper:' . $p['background'] . ';'
        . '--opn-footer:' . $p['text'] . ';--opn-on-footer:' . $p['on_text'] . ';}';
}

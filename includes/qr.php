<?php

/**
 * QR codes for newsletters: "scan to register", "visit our website".
 *
 * The editor's QR code block sends a web address here; we encode it (byte
 * mode, error correction level M, versions 1–10, enough for ~210 characters),
 * save it as a crisp PNG in the Media Library and the block becomes an
 * ordinary image. An image works the same on the website, on paper and in
 * every email client, which cannot show inline SVG.
 *
 * The encoder follows ISO/IEC 18004 (the structure mirrors Project Nayuki's
 * public-domain reference implementation).
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const QR_META_KEY = '_omni_platform_newsletter_qr';

/**
 * Codeword layout for error-correction level M: [EC codewords per block, [[blocks, data codewords per block], ...]].
 *
 * @return array<int, array{0: int, 1: list<array{0: int, 1: int}>}>
 */
function qrBlocksM(): array
{
    return [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];
}

/** @return list<int> Alignment pattern centre coordinates for a version. */
function qrAlignment(int $version): array
{
    return [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]][$version];
}

function qrGfMul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }

    return $z;
}

/** @return list<int> Reed–Solomon error-correction codewords for one block. */
function qrReedSolomon(array $data, int $degree): array
{
    $divisor = array_fill(0, $degree, 0);
    $divisor[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $divisor[$j] = qrGfMul($divisor[$j], $root);
            if ($j + 1 < $degree) {
                $divisor[$j] ^= $divisor[$j + 1];
            }
        }
        $root = qrGfMul($root, 0x02);
    }

    $result = array_fill(0, $degree, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ array_shift($result);
        $result[] = 0;
        foreach ($divisor as $i => $coefficient) {
            $result[$i] ^= qrGfMul($coefficient, $factor);
        }
    }

    return $result;
}

/**
 * Encode text as a QR code.
 *
 * @return list<list<bool>>|null Rows of dark (true) and light modules, or null when the text is too long.
 */
function qrMatrix(string $text): ?array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $layout = null;
    foreach (qrBlocksM() as $version => [$ecLength, $groups]) {
        $capacity = array_sum(array_map(fn($g) => $g[0] * $g[1], $groups));
        $countBits = $version < 10 ? 8 : 16;
        if (4 + $countBits + 8 * count($bytes) <= $capacity * 8) {
            $layout = [$version, $ecLength, $groups, $capacity, $countBits];
            break;
        }
    }
    if ($layout === null) {
        return null;
    }
    [$version, $ecLength, $groups, $capacity, $countBits] = $layout;

    // Data bits: byte mode, length, the bytes, terminator, padding.
    $bits = [];
    $push = function (int $value, int $length) use (&$bits): void {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    };
    $push(0b0100, 4);
    $push(count($bytes), $countBits);
    foreach ($bytes as $byte) {
        $push($byte, 8);
    }
    $push(0, min(4, $capacity * 8 - count($bits)));
    $push(0, (8 - count($bits) % 8) % 8);
    $data = [];
    foreach (array_chunk($bits, 8) as $chunk) {
        $data[] = (int) bindec(implode('', $chunk));
    }
    for ($pad = 0xEC; count($data) < $capacity; $pad ^= 0xEC ^ 0x11) {
        $data[] = $pad;
    }

    // Split into blocks, add error correction, interleave.
    $blocks = [];
    $offset = 0;
    foreach ($groups as [$count, $length]) {
        for ($b = 0; $b < $count; $b++) {
            $chunk = array_slice($data, $offset, $length);
            $offset += $length;
            $blocks[] = [$chunk, qrReedSolomon($chunk, $ecLength)];
        }
    }
    $codewords = [];
    $longest = max(array_map(fn($block) => count($block[0]), $blocks));
    for ($i = 0; $i < $longest; $i++) {
        foreach ($blocks as [$chunk]) {
            if (isset($chunk[$i])) {
                $codewords[] = $chunk[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLength; $i++) {
        foreach ($blocks as [, $ec]) {
            $codewords[] = $ec[$i];
        }
    }

    // Function patterns.
    $size = $version * 4 + 17;
    $modules = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = $modules;
    $set = function (int $x, int $y, bool $dark) use (&$modules, &$reserved): void {
        $modules[$y][$x] = $dark;
        $reserved[$y][$x] = true;
    };
    for ($i = 0; $i < $size; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                    $distance = max(abs($dx), abs($dy));
                    $set($x, $y, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }
    $positions = qrAlignment($version);
    $last = count($positions) - 1;
    foreach ($positions as $i => $cx) {
        foreach ($positions as $j => $cy) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
    }
    $drawFormat = function (int $mask) use ($set, $size): void {
        $data = $mask; // Level M is 00.
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = fn(int $i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);
    };
    $drawFormat(0);
    if ($version >= 7) {
        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $set($a, $b, $dark);
            $set($b, $a, $dark);
        }
    }

    // Data in the two-column zigzag, from the bottom-right corner.
    $i = 0;
    $total = count($codewords) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vertical = 0; $vertical < $size; $vertical++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = (($right + 1) & 2) === 0;
                $y = $upward ? $size - 1 - $vertical : $vertical;
                if (!$reserved[$y][$x] && $i < $total) {
                    $modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    // Try each mask and keep the easiest to scan.
    $base = $modules;
    $best = null;
    $bestPenalty = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $modules = $base;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$reserved[$y][$x] && qrMaskBit($mask, $x, $y)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
        $drawFormat($mask);
        $penalty = qrPenalty($modules);
        if ($penalty < $bestPenalty) {
            [$best, $bestPenalty] = [$modules, $penalty];
        }
    }

    return $best;
}

function qrMaskBit(int $mask, int $x, int $y): bool
{
    return match ($mask) {
        0 => ($x + $y) % 2 === 0,
        1 => $y % 2 === 0,
        2 => $x % 3 === 0,
        3 => ($x + $y) % 3 === 0,
        4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
        5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
        6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
        default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
    };
}

/** Standard mask penalty: long runs, 2×2 blocks, finder look-alikes and dark/light balance. */
function qrPenalty(array $modules): int
{
    $size = count($modules);
    $penalty = 0;
    $lines = $modules;
    for ($x = 0; $x < $size; $x++) {
        $lines[] = array_column($modules, $x);
    }
    $finderLike = [[1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1]];
    foreach ($lines as $line) {
        $run = 1;
        for ($i = 1; $i <= $size; $i++) {
            if ($i < $size && $line[$i] === $line[$i - 1]) {
                $run++;
                continue;
            }
            if ($run >= 5) {
                $penalty += $run - 2;
            }
            $run = 1;
        }
        $bits = array_map('intval', $line);
        for ($i = 0; $i + 11 <= $size; $i++) {
            $window = array_slice($bits, $i, 11);
            if ($window === $finderLike[0] || $window === $finderLike[1]) {
                $penalty += 40;
            }
        }
    }
    $dark = 0;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $dark += $modules[$y][$x] ? 1 : 0;
            $square = $x + 1 < $size && $y + 1 < $size
                && $modules[$y][$x] === $modules[$y][$x + 1]
                && $modules[$y][$x] === $modules[$y + 1][$x]
                && $modules[$y][$x] === $modules[$y + 1][$x + 1];
            if ($square) {
                $penalty += 3;
            }
        }
    }
    $total = $size * $size;
    $penalty += (int) (ceil(abs($dark * 20 - $total * 10) / $total) - 1) * 10;

    return $penalty;
}

/** PNG bytes of a QR code: black on white with the standard four-module quiet zone. */
function qrPng(array $modules, int $scale = 12): string
{
    $size = count($modules);
    $quiet = 4;
    $pixels = ($size + 2 * $quiet) * $scale;
    $image = imagecreate($pixels, $pixels);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);
    foreach ($modules as $y => $row) {
        foreach ($row as $x => $dark) {
            if ($dark) {
                $left = ($x + $quiet) * $scale;
                $top = ($y + $quiet) * $scale;
                imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
            }
        }
    }
    ob_start();
    imagepng($image, null, 9);
    imagedestroy($image);

    return (string) ob_get_clean();
}

/** A web address worth encoding: http(s), mailto or tel only. */
function qrTarget(string $value): string
{
    $value = trim($value);
    if ($value !== '' && !preg_match('#^(https?://|mailto:|tel:)#i', $value) && preg_match('/^[\w.-]+\.[a-z]{2,}(\/|$)/i', $value)) {
        $value = 'https://' . $value;
    }

    return (string) esc_url_raw($value, ['http', 'https', 'mailto', 'tel']);
}

/**
 * Media Library image of a QR code for a web address, reused when the same address was encoded before.
 *
 * @return array{id: int, url: string, alt: string, target: string}|\WP_Error
 */
function qrAttachment(string $value): array|\WP_Error
{
    $target = qrTarget($value);
    if ($target === '') {
        return new \WP_Error('opn_qr_address', __('Enter a web address, such as https://example.org/register.', 'omni-platform-newsletter'), ['status' => 400]);
    }
    /* translators: %s: web address */
    $alt = sprintf(__('QR code: %s', 'omni-platform-newsletter'), preg_replace('#^(https?://|mailto:|tel:)#i', '', $target));
    $hash = md5($target);
    $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => QR_META_KEY, 'meta_value' => $hash]);
    if ($existing && wp_get_attachment_url((int) $existing[0])) {
        return ['id' => (int) $existing[0], 'url' => (string) wp_get_attachment_url((int) $existing[0]), 'alt' => $alt, 'target' => $target];
    }

    $modules = qrMatrix($target);
    if ($modules === null) {
        return new \WP_Error('opn_qr_long', __('That address is too long for a QR code. Use a shorter link.', 'omni-platform-newsletter'), ['status' => 400]);
    }
    if (!function_exists('imagecreate')) {
        return new \WP_Error('opn_qr_gd', __('This server cannot create images. Please contact support.', 'omni-platform-newsletter'), ['status' => 500]);
    }

    $upload = wp_upload_bits('qr-' . substr($hash, 0, 10) . '.png', null, qrPng($modules));
    if (!empty($upload['error'])) {
        return new \WP_Error('opn_qr_upload', (string) $upload['error'], ['status' => 500]);
    }
    $id = wp_insert_attachment([
        'post_mime_type' => 'image/png',
        'post_title' => $alt,
        'post_status' => 'inherit',
    ], $upload['file'], 0, true);
    if (is_wp_error($id)) {
        return $id;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    update_post_meta($id, QR_META_KEY, $hash);

    return ['id' => (int) $id, 'url' => (string) wp_get_attachment_url($id), 'alt' => $alt, 'target' => $target];
}

add_action('rest_api_init', function () {
    register_rest_route(REST_NAMESPACE, '/qr', [
        'methods' => 'POST',
        'permission_callback' => fn() => current_user_can(CAP_WRITE) && current_user_can('upload_files'),
        'args' => ['url' => ['type' => 'string', 'required' => true]],
        'callback' => function (\WP_REST_Request $request) {
            $result = qrAttachment((string) $request['url']);
            return is_wp_error($result) ? $result : rest_ensure_response($result);
        },
    ]);
});

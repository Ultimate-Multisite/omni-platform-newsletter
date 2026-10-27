<?php

declare(strict_types=1);

class WP_Post
{
    public int $ID = 1;
    public string $post_type = 'newspack_nl_cpt';
    public string $post_status = 'publish';
    public array $post_content = [];
}

define('ABSPATH', __DIR__ . '/');

function add_filter(...$arguments): void
{
}

function add_action(...$arguments): void
{
}

function apply_filters($hook, $value)
{
    return $value;
}

function get_post()
{
    return $GLOBALS['test_post'] ?? null;
}

function is_admin(): bool
{
    return false;
}

function post_password_required($post): bool
{
    return $GLOBALS['password_required'] ?? false;
}

function esc_html__($text, $domain): string
{
    return $text;
}

function wp_die($message, $title = '', $args = []): void
{
    throw new RuntimeException($title . ': ' . $message, $args['response'] ?? 0);
}

function get_post_meta($id, $key, $single)
{
    return $key === '_omni_platform_newsletter_layout' ? '1' : '';
}

function parse_blocks($content): array
{
    return $content;
}

function add_shortcode(...$arguments): void
{
}

// Site details the starter newsletter is pre-filled from.
function get_bloginfo($show): string
{
    return $show === 'name' ? 'Example Mission' : 'Serving our town';
}

function wp_get_current_user(): object
{
    return new class {
        public string $first_name = 'Jane';
        public string $last_name = 'Doe';
        public string $display_name = 'jane';
        public string $user_email = 'jane@example.org';
        public string $user_login = 'jane';

        public function exists(): bool
        {
            return true;
        }
    };
}

function get_page_by_path($path)
{
    return null;
}

function home_url($path = ''): string
{
    return 'https://example.org' . $path;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function wp_specialchars_decode($text, $quotes = 0): string
{
    return htmlspecialchars_decode((string) $text, ENT_QUOTES);
}

function wp_date($format): string
{
    return date($format);
}

function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_attr($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_url($url): string
{
    return (string) $url;
}

function __($text, $domain = ''): string
{
    return $text;
}

function wp_kses_post($html): string
{
    return (string) $html;
}

function untrailingslashit($text): string
{
    return rtrim((string) $text, '/\\');
}

function wp_get_attachment_image_url($id, $size = 'thumbnail')
{
    return false;
}

require dirname(__DIR__) . '/omni-platform-newsletter.php';

function block(string $class): array
{
    return [
        'blockName' => 'core/group',
        'attrs' => ['className' => $class],
        'innerHTML' => '<div></div>',
    ];
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$post = new WP_Post();
$post->post_content = [
    block('opn-source-sidebar'),
    block('opn-source-front'),
    block('opn-source-back'),
    block('opn-source-footer'),
];

assertTrue(OmniPlatform\Newsletter\isIssue($post), 'valid opted-in Newspack post recognized');
assertTrue(
    array_keys(OmniPlatform\Newsletter\sections($post)) === ['sidebar', 'front', 'back', 'footer'],
    'four source sections preserve their authored order'
);

$unexpected = clone $post;
$unexpected->post_content[] = block('unrelated-content');
assertTrue(OmniPlatform\Newsletter\sections($unexpected) === [], 'unexpected top-level content fails closed');

$duplicate = clone $post;
$duplicate->post_content[] = block('opn-source-front');
assertTrue(OmniPlatform\Newsletter\sections($duplicate) === [], 'duplicate source section fails closed');

$GLOBALS['test_post'] = $post;
$GLOBALS['password_required'] = true;
assertTrue(
    OmniPlatform\Newsletter\filterWebContent('password form') === 'password form',
    'password-protected issue preserves the WordPress password form'
);
$GLOBALS['password_required'] = false;

$invalidEmail = clone $post;
$invalidEmail->post_content = [block('opn-source-front')];
try {
    OmniPlatform\Newsletter\filterNewsletterContent('stored source', $invalidEmail);
    assertTrue(false, 'invalid section contract stops email generation');
} catch (RuntimeException $error) {
    assertTrue($error->getCode() === 422, 'invalid section contract surfaces a validation error');
}

$pattern = OmniPlatform\Newsletter\starterPatternContent();
foreach (['sidebar', 'front', 'back', 'footer'] as $role) {
    assertTrue(substr_count($pattern, 'opn-source-' . $role) === 2, $role . ' pattern class occurs in metadata and HTML');
}
assertTrue(!str_contains(strtolower($pattern), 'cef'), 'starter pattern is organization-neutral');
foreach (['Example Mission', 'Serving our town', 'Jane Doe', 'mailto:jane@example.org', date('F Y')] as $detail) {
    assertTrue(str_contains($pattern, $detail), 'starter pattern is pre-filled with ' . $detail);
}

echo 'PASS: Omni Platform Newsletter content contract' . PHP_EOL;

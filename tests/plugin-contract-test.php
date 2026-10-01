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

function get_post_meta($id, $key, $single)
{
    return $key === '_omni_platform_newsletter_layout' ? '1' : '';
}

function parse_blocks($content): array
{
    return $content;
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

$pattern = OmniPlatform\Newsletter\starterPatternContent();
foreach (['sidebar', 'front', 'back', 'footer'] as $role) {
    assertTrue(substr_count($pattern, 'opn-source-' . $role) === 2, $role . ' pattern class occurs in metadata and HTML');
}
assertTrue(!str_contains(strtolower($pattern), 'cef'), 'starter pattern is organization-neutral');

echo 'PASS: Omni Platform Newsletter content contract' . PHP_EOL;

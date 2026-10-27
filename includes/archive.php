<?php

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

/** Text of a list of blocks, without markup. */
function blocksText(array $blocks): string
{
    $text = '';
    foreach ($blocks as $block) {
        $text .= ' ' . wp_strip_all_tags(render_block($block));
    }

    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/** Blocks named $name anywhere inside $blocks, in document order. */
function findBlocks(array $blocks, string $name): array
{
    $found = [];
    foreach ($blocks as $block) {
        if ($block['blockName'] === $name) {
            $found[] = $block;
        }
        if (!empty($block['innerBlocks'])) {
            array_push($found, ...findBlocks($block['innerBlocks'], $name));
        }
    }

    return $found;
}

/**
 * An issue's summary: the lead story's text (not the breadcrumb, masthead or sidebar
 * that the full composition would put first).
 */
function issueLeadText(\WP_Post $post): string
{
    $sections = sections($post);
    if (count($sections) !== 4) {
        return '';
    }

    foreach ($sections['front']['innerBlocks'] as $child) {
        if (str_contains((string) ($child['attrs']['className'] ?? ''), 'opn-story')) {
            $text = blocksText(findBlocks($child['innerBlocks'], 'core/paragraph'));
            if ($text !== '') {
                return $text;
            }
        }
    }

    $paragraphs = array_filter(
        findBlocks($sections['front']['innerBlocks'], 'core/paragraph'),
        fn($b) => !preg_match('/\bopn-(issue-label|kicker)\b/', (string) ($b['attrs']['className'] ?? ''))
    );

    return blocksText($paragraphs);
}

add_filter('get_the_excerpt', function ($excerpt, $post = null) {
    $post = get_post($post);
    if (!isIssue($post) || trim((string) $post->post_excerpt) !== '' || post_password_required($post)) {
        return $excerpt;
    }

    return wp_trim_words(issueLeadText($post), (int) apply_filters('excerpt_length', 55));
}, 5, 2);

/** The first photo in an issue (front page first), or 0. */
function firstIssueImage(\WP_Post $post): int
{
    $sections = sections($post);
    foreach (['front', 'back', 'sidebar'] as $role) {
        foreach (findBlocks(isset($sections[$role]) ? [$sections[$role]] : [], 'core/image') as $image) {
            $id = (int) ($image['attrs']['id'] ?? 0);
            if ($id > 0 && wp_attachment_is_image($id)) {
                return $id;
            }
        }
    }

    return 0;
}

const AUTO_THUMBNAIL_META = '_opn_auto_thumbnail';

/**
 * Use the issue's first photo as its featured image (archive cards, social shares)
 * unless the writer chose one. Follows later photo changes until they do.
 */
function syncAutoThumbnail(\WP_Post $post): void
{
    if (wp_is_post_revision($post->ID) || !isIssue($post)) {
        return;
    }

    $current = (int) get_post_thumbnail_id($post);
    $auto = (int) get_post_meta($post->ID, AUTO_THUMBNAIL_META, true);
    if ($current > 0 && $current !== $auto) {
        delete_post_meta($post->ID, AUTO_THUMBNAIL_META);
        return;
    }

    $image = firstIssueImage($post);
    if ($image > 0) {
        set_post_thumbnail($post, $image);
        update_post_meta($post->ID, AUTO_THUMBNAIL_META, $image);
    } elseif ($auto > 0) {
        delete_post_thumbnail($post);
        delete_post_meta($post->ID, AUTO_THUMBNAIL_META);
    }
}

add_action('save_post', function ($id, $post) {
    if ($post instanceof \WP_Post) {
        syncAutoThumbnail($post);
    }
}, 20, 2);

// The block editor saves through REST, which applies `featured_media` after save_post
// (as 0 when none was chosen); sync again once it has.
add_action('init', function () {
    foreach (supportedPostTypes() as $type) {
        add_action('rest_after_insert_' . $type, fn($post) => $post instanceof \WP_Post ? syncAutoThumbnail(get_post($post->ID)) : null);
    }
}, 30);

/** [omni_newsletter_signup]: the subscription form of the delivery plugin, with a heading. */
add_shortcode('omni_newsletter_signup', function () {
    if (!shortcode_exists('newsletter_form')) {
        return '';
    }

    return '<div class="opn-signup"><h2 class="opn-signup__title">' . esc_html__('Get the next issue by email', 'omni-platform-newsletter') . '</h2>'
        . '<p class="opn-signup__text">' . esc_html__('Sign up and we’ll send each new newsletter to your inbox. You can unsubscribe at any time.', 'omni-platform-newsletter') . '</p>'
        . do_shortcode('[newsletter_form]') . '</div>';
});

/** Block markup of the newsletter archive: intro, issue cards, and a sign-up box. */
function archiveTemplateContent(): string
{
    $intro = esc_html__('Every issue, newest first. Open one to read it online or print a copy.', 'omni-platform-newsletter');
    $more = esc_html__('Read this issue', 'omni-platform-newsletter');
    $none = esc_html__('Our first newsletter is coming soon.', 'omni-platform-newsletter');

    return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
        . '<!-- wp:group {"tagName":"main","align":"full","className":"opn-archive","layout":{"type":"constrained"}} --><main class="wp-block-group alignfull opn-archive">'
        . '<!-- wp:group {"align":"full","className":"opn-archive__hero","layout":{"type":"constrained"}} --><div class="wp-block-group alignfull opn-archive__hero">'
        . '<!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide","className":"opn-archive__title"} /-->'
        . '<!-- wp:paragraph {"className":"opn-archive__intro"} --><p class="opn-archive__intro">' . $intro . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:query {"query":{"inherit":true},"align":"wide","className":"opn-archive__issues"} --><div class="wp-block-query alignwide opn-archive__issues">'
        . '<!-- wp:post-template {"layout":{"type":"grid","columnCount":3}} -->'
        . '<!-- wp:group {"tagName":"article","className":"opn-issue-card","layout":{"type":"default"}} --><article class="wp-block-group opn-issue-card">'
        . '<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"3/2","className":"opn-issue-card__image"} /-->'
        . '<!-- wp:group {"className":"opn-issue-card__body","layout":{"type":"default"}} --><div class="wp-block-group opn-issue-card__body">'
        . '<!-- wp:post-date {"format":"F Y","className":"opn-issue-card__date"} /-->'
        . '<!-- wp:post-title {"isLink":true,"level":2,"className":"opn-issue-card__title"} /-->'
        . '<!-- wp:post-excerpt {"excerptLength":28,"className":"opn-issue-card__excerpt"} /-->'
        . '<!-- wp:read-more {"content":"' . $more . '","className":"opn-issue-card__more"} /-->'
        . '</div><!-- /wp:group --></article><!-- /wp:group -->'
        . '<!-- /wp:post-template -->'
        . '<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"},"className":"opn-archive__pagination"} -->'
        . '<!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /-->'
        . '<!-- /wp:query-pagination -->'
        . '<!-- wp:query-no-results --><!-- wp:paragraph {"className":"opn-archive__empty"} --><p class="opn-archive__empty">' . $none . '</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->'
        . '</div><!-- /wp:query -->'
        . '<!-- wp:group {"align":"wide","className":"opn-archive__signup","layout":{"type":"default"}} --><div class="wp-block-group alignwide opn-archive__signup">'
        . '<!-- wp:shortcode -->[omni_newsletter_signup]<!-- /wp:shortcode -->'
        . '</div><!-- /wp:group -->'
        . '</main><!-- /wp:group -->'
        . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
}

/** Block themes: a newsletter archive template. Themes can override it with archive-newspack_nl_cpt. */
add_action('init', function () {
    if (!function_exists('register_block_template') || !wp_is_block_theme()) {
        return;
    }

    foreach (supportedPostTypes() as $type) {
        register_block_template('omni-platform-newsletter//archive-' . $type, [
            'title' => __('Newsletter archive', 'omni-platform-newsletter'),
            'description' => __('All published newsletters as cards, with a sign-up box.', 'omni-platform-newsletter'),
            'content' => archiveTemplateContent(),
        ]);
    }
}, 20);

add_action('wp_enqueue_scripts', function () {
    if (!is_post_type_archive(supportedPostTypes())) {
        return;
    }

    wp_enqueue_style('omni-platform-newsletter-archive', plugins_url('archive.css', __DIR__), [], VERSION);
    wp_add_inline_style('omni-platform-newsletter-archive', paletteCss());
});

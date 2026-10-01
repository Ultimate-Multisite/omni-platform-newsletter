<?php

/** Newsletter-only web view. WordPress still owns access and visibility. */

defined('ABSPATH') || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('opn-newsletter-view'); ?>>
<?php wp_body_open(); ?>
<nav class="opn-newsletter-toolbar" aria-label="Newsletter actions">
    <strong><?php echo esc_html(get_the_title()); ?></strong>
    <button type="button" class="opn-browser-print">Print / save PDF</button>
    <?php if (current_user_can('edit_post', get_the_ID())) : ?>
        <?php if (shortcode_exists('pmb_print_page_url')) : ?>
            <a href="<?php echo esc_url(\OmniPlatform\Newsletter\printUrl(get_post())); ?>">Print My Blog Pro preview</a>
        <?php endif; ?>
        <a href="<?php echo esc_url(get_edit_post_link()); ?>">Edit newsletter blocks</a>
    <?php endif; ?>
</nav>
<main>
    <?php
    while (have_posts()) {
        the_post();
        the_content();
    }
    ?>
</main>
<?php wp_footer(); ?>
</body>
</html>

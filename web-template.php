<?php

/** Newsletter web and print views. WordPress still owns access and visibility. */

defined('ABSPATH') || exit;

$opnPost = get_post();
$opnPrint = \OmniPlatform\Newsletter\isPrintView();
$opnArchive = get_post_type_archive_link($opnPost->post_type);
$opnCanEdit = current_user_can('edit_post', $opnPost->ID);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('opn-newsletter-view'); ?>>
<?php wp_body_open(); ?>
<?php if ($opnPrint) : ?>
    <div class="opn-newsletter-toolbar opn-print-toolbar" role="region" aria-label="<?php esc_attr_e('Print options', 'omni-platform-newsletter'); ?>">
        <div class="opn-print-toolbar__main">
            <strong><?php echo esc_html(get_the_title()); ?></strong>
            <span class="opn-fit-status" data-opn-fit-status role="status" aria-live="polite"></span>
            <button type="button" class="opn-browser-print opn-button-primary"><?php esc_html_e('Print / Save as PDF', 'omni-platform-newsletter'); ?></button>
            <?php if ($opnCanEdit) : ?>
                <a href="<?php echo esc_url(get_edit_post_link()); ?>"><?php esc_html_e('Back to editing', 'omni-platform-newsletter'); ?></a>
            <?php endif; ?>
        </div>
        <details class="opn-print-help">
            <summary><?php esc_html_e('Printing tips', 'omni-platform-newsletter'); ?></summary>
            <ul>
                <li><?php esc_html_e('Paper size: Letter. Scale: 100% (or “Default”).', 'omni-platform-newsletter'); ?></li>
                <li><?php esc_html_e('Turn on “Background graphics” so the colors print.', 'omni-platform-newsletter'); ?></li>
                <li><?php esc_html_e('Turn off “Headers and footers”.', 'omni-platform-newsletter'); ?></li>
                <li><?php esc_html_e('Print on both sides and choose “Flip on long edge”.', 'omni-platform-newsletter'); ?></li>
                <li><?php esc_html_e('To make a PDF, choose “Save as PDF” as the printer.', 'omni-platform-newsletter'); ?></li>
            </ul>
        </details>
    </div>
<?php else : ?>
    <nav class="opn-newsletter-toolbar" aria-label="<?php esc_attr_e('Newsletter', 'omni-platform-newsletter'); ?>">
        <a class="opn-site-link" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(get_bloginfo('name')); ?></a>
        <?php if ($opnArchive && get_post_meta($opnPost->ID, 'is_public', true)) : ?>
            <a href="<?php echo esc_url($opnArchive); ?>"><?php esc_html_e('All newsletters', 'omni-platform-newsletter'); ?></a>
        <?php endif; ?>
        <span class="opn-toolbar-spacer"></span>
        <a href="<?php echo esc_url(add_query_arg('opn_view', 'print')); ?>"><?php esc_html_e('Print / PDF', 'omni-platform-newsletter'); ?></a>
        <?php if ($opnCanEdit) : ?>
            <a href="<?php echo esc_url(get_edit_post_link()); ?>"><?php esc_html_e('Edit', 'omni-platform-newsletter'); ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
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

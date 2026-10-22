<?php

/**
 * Newsletters list.
 *
 * @var list<WP_Post> $issues
 * @var array{confirmed:int, pending:int, unsubscribed:int, total:int} $stats
 * @var string|false $archive
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

$hasPublic = (bool) array_filter($issues, fn($issue) => webUrl($issue) !== '');
?>
<div class="wrap opn-admin">
    <div class="opn-page-header">
        <h1><?php esc_html_e('Newsletters', 'omni-platform-newsletter'); ?></h1>
        <a class="button button-primary button-hero" href="<?php echo esc_url(newIssueUrl()); ?>"><?php esc_html_e('Write a newsletter', 'omni-platform-newsletter'); ?></a>
    </div>
    <hr class="wp-header-end">
    <?php renderNotice(); ?>

    <p class="opn-lead"><?php esc_html_e('Write each newsletter once. From the editor you can publish it on your website, email it to your subscribers, and print it as a two-page, front-and-back letter.', 'omni-platform-newsletter'); ?></p>

    <div class="opn-cards">
        <a class="opn-card" href="<?php echo esc_url(pageUrl(PAGE_SUBSCRIBERS)); ?>">
            <span class="opn-card__value"><?php echo esc_html(number_format_i18n($stats['confirmed'])); ?></span>
            <span class="opn-card__label"><?php echo esc_html(_n('subscriber', 'subscribers', $stats['confirmed'], 'omni-platform-newsletter')); ?></span>
            <span class="opn-card__link"><?php esc_html_e('Manage subscribers', 'omni-platform-newsletter'); ?></span>
        </a>
        <?php if ($archive && $hasPublic) : ?>
            <a class="opn-card" href="<?php echo esc_url($archive); ?>" target="_blank" rel="noopener">
                <span class="opn-card__value dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                <span class="opn-card__label"><?php esc_html_e('Newsletter page on your website', 'omni-platform-newsletter'); ?></span>
                <span class="opn-card__link"><?php esc_html_e('View page', 'omni-platform-newsletter'); ?></span>
            </a>
        <?php endif; ?>
        <?php if (current_user_can(CAP_SETTINGS)) : ?>
            <a class="opn-card" href="<?php echo esc_url(pageUrl(PAGE_SETTINGS)); ?>">
                <span class="opn-card__value dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <span class="opn-card__label"><?php esc_html_e('Sender name, audience, and social sharing', 'omni-platform-newsletter'); ?></span>
                <span class="opn-card__link"><?php esc_html_e('Newsletter settings', 'omni-platform-newsletter'); ?></span>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$issues) : ?>
        <div class="opn-empty">
            <h2><?php esc_html_e('Write your first newsletter', 'omni-platform-newsletter'); ?></h2>
            <p><?php esc_html_e('You will start with a ready-made layout. Fill in the sidebar and footer once; future newsletters reuse them automatically.', 'omni-platform-newsletter'); ?></p>
            <a class="button button-primary" href="<?php echo esc_url(newIssueUrl()); ?>"><?php esc_html_e('Write a newsletter', 'omni-platform-newsletter'); ?></a>
        </div>
    <?php else : ?>
        <table class="widefat striped opn-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Newsletter', 'omni-platform-newsletter'); ?></th>
                    <th scope="col"><?php esc_html_e('Website', 'omni-platform-newsletter'); ?></th>
                    <th scope="col"><?php esc_html_e('Email', 'omni-platform-newsletter'); ?></th>
                    <th scope="col"><?php esc_html_e('Last edited', 'omni-platform-newsletter'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($issues as $issue) :
                $status = deliveryStatus($issue);
                $web = webUrl($issue);
                $canEdit = current_user_can('edit_post', $issue->ID);
                ?>
                <tr>
                    <td class="opn-table__title">
                        <strong>
                            <?php if ($canEdit) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($issue->ID)); ?>"><?php echo esc_html(get_the_title($issue) ?: __('(untitled)', 'omni-platform-newsletter')); ?></a>
                            <?php else : ?>
                                <?php echo esc_html(get_the_title($issue) ?: __('(untitled)', 'omni-platform-newsletter')); ?>
                            <?php endif; ?>
                        </strong>
                        <div class="opn-actions">
                            <?php if ($canEdit) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($issue->ID)); ?>"><?php esc_html_e('Edit', 'omni-platform-newsletter'); ?></a>
                                <a href="<?php echo esc_url(printPreviewUrl($issue)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Print / PDF', 'omni-platform-newsletter'); ?></a>
                            <?php endif; ?>
                            <?php if ($web !== '') : ?>
                                <a href="<?php echo esc_url($web); ?>" target="_blank" rel="noopener"><?php esc_html_e('View on website', 'omni-platform-newsletter'); ?></a>
                            <?php endif; ?>
                            <?php if ($canEdit && current_user_can('edit_posts')) : ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=opn_duplicate&post=' . $issue->ID), 'opn_duplicate_' . $issue->ID)); ?>"><?php esc_html_e('Copy to start a new issue', 'omni-platform-newsletter'); ?></a>
                            <?php endif; ?>
                            <?php if (current_user_can('delete_post', $issue->ID) && $status['state'] === 'not_sent') : ?>
                                <a class="opn-danger" href="<?php echo esc_url(get_delete_post_link($issue->ID)); ?>"><?php esc_html_e('Move to trash', 'omni-platform-newsletter'); ?></a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><span class="opn-badge opn-badge--<?php echo esc_attr($web !== '' ? 'live' : sanitize_html_class($issue->post_status)); ?>"><?php echo esc_html(webStateLabel($issue)); ?></span></td>
                    <td><span class="opn-badge opn-badge--email-<?php echo esc_attr($status['state']); ?>"><?php echo esc_html(emailStateLabel($status)); ?></span></td>
                    <td><?php echo esc_html(sprintf(
                        /* translators: %s: human time difference */
                        __('%s ago', 'omni-platform-newsletter'),
                        human_time_diff((int) get_post_modified_time('U', true, $issue), time())
                    )); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php

/**
 * Newsletter settings.
 *
 * @var array<int, string> $lists
 * @var array<string, mixed> $main
 * @var list<array{id:int, name:string, platform:string}> $accounts
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

$defaultLists = defaultAudience();
?>
<div class="wrap opn-admin">
    <h1><?php esc_html_e('Newsletter settings', 'omni-platform-newsletter'); ?></h1>
    <hr class="wp-header-end">
    <?php renderNotice(); ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="opn-settings">
        <input type="hidden" name="action" value="opn_save_settings">
        <?php wp_nonce_field('opn_save_settings'); ?>

        <section class="opn-panel">
            <h2><?php esc_html_e('Email', 'omni-platform-newsletter'); ?></h2>
            <?php if (deliveryAvailable()) : ?>
                <p><label for="opn-sender-name"><?php esc_html_e('From name', 'omni-platform-newsletter'); ?></label><br>
                    <input id="opn-sender-name" class="regular-text" type="text" name="sender_name" value="<?php echo esc_attr((string) ($main['sender_name'] ?? '')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <br><span class="description"><?php esc_html_e('Shown as the sender in your subscribers’ inboxes. Leave blank to use your website name.', 'omni-platform-newsletter'); ?></span></p>
                <p><label for="opn-reply-to"><?php esc_html_e('Replies go to', 'omni-platform-newsletter'); ?></label><br>
                    <input id="opn-reply-to" class="regular-text" type="email" name="reply_to" value="<?php echo esc_attr((string) ($main['reply_to'] ?? '')); ?>" placeholder="<?php esc_attr_e('name@example.org', 'omni-platform-newsletter'); ?>">
                    <br><span class="description"><?php esc_html_e('When a subscriber clicks Reply, their message goes to this address.', 'omni-platform-newsletter'); ?></span></p>
                <?php $sender = senderEmail(); ?>
                <p><?php esc_html_e('Sending address', 'omni-platform-newsletter'); ?><br>
                    <code><?php echo esc_html($sender !== '' ? $sender : __('Not set up yet — contact support', 'omni-platform-newsletter')); ?></code>
                    <br><span class="description"><?php esc_html_e('Set by your hosting provider so your newsletters are trusted by email services.', 'omni-platform-newsletter'); ?></span></p>
                <?php if ($lists) : ?>
                    <fieldset><legend><?php esc_html_e('Send newsletters to', 'omni-platform-newsletter'); ?></legend>
                        <p class="description"><?php esc_html_e('Leave all unchecked to send to every subscriber. You can change this for each newsletter before sending.', 'omni-platform-newsletter'); ?></p>
                        <?php foreach ($lists as $id => $name) : ?>
                            <label class="opn-check"><input type="checkbox" name="default_lists[]" value="<?php echo esc_attr((string) $id); ?>" <?php checked(in_array($id, $defaultLists, true)); ?>> <?php echo esc_html($name); ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
            <?php else : ?>
                <p><?php esc_html_e('Email sending is not set up on this site yet. Please contact support.', 'omni-platform-newsletter'); ?></p>
            <?php endif; ?>
        </section>

        <section class="opn-panel">
            <h2><?php esc_html_e('Website', 'omni-platform-newsletter'); ?></h2>
            <label class="opn-check"><input type="checkbox" name="publish_web" value="1" <?php checked((bool) getSetting('publish_web')); ?>>
                <?php esc_html_e('Show new newsletters on the website when they are published', 'omni-platform-newsletter'); ?></label>
            <p class="description"><?php esc_html_e('You can still choose for each newsletter in the editor.', 'omni-platform-newsletter'); ?></p>
        </section>

        <?php if (socialAvailable()) : ?>
            <section class="opn-panel">
                <h2><?php esc_html_e('Social media', 'omni-platform-newsletter'); ?></h2>
                <label class="opn-check"><input type="checkbox" name="share_social" value="1" <?php checked((bool) getSetting('share_social')); ?>>
                    <?php esc_html_e('Share each newsletter on our social media accounts when it is published on the website', 'omni-platform-newsletter'); ?></label>
                <?php if ($accounts) : ?>
                    <p><?php echo esc_html(sprintf(
                        /* translators: %s: comma-separated account names */
                        __('Connected accounts: %s', 'omni-platform-newsletter'),
                        implode(', ', array_map(fn($account) => trim($account['name'] . ' (' . ucfirst($account['platform']) . ')'), $accounts))
                    )); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('No social media accounts are connected yet.', 'omni-platform-newsletter'); ?></p>
                <?php endif; ?>
                <?php if (socialAccountsUrl() !== '' && current_user_can(\BitApps\Social\Config::accessCapability())) : ?>
                    <p><a class="button" href="<?php echo esc_url(socialAccountsUrl()); ?>"><?php esc_html_e('Connect social media accounts', 'omni-platform-newsletter'); ?></a></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <p><button type="submit" class="button button-primary button-large"><?php esc_html_e('Save settings', 'omni-platform-newsletter'); ?></button></p>
    </form>

    <?php if (currentUserManagesEngines()) : ?>
        <section class="opn-panel opn-advanced">
            <h2><?php esc_html_e('Advanced (network administrators)', 'omni-platform-newsletter'); ?></h2>
            <p><?php esc_html_e('These tools power the newsletter area and are hidden from other users.', 'omni-platform-newsletter'); ?></p>
            <ul>
                <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=newspack_nl_cpt&opn_engine_list=1')); ?>"><?php esc_html_e('Newspack Newsletters list', 'omni-platform-newsletter'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=newsletter_main_index')); ?>"><?php esc_html_e('Newsletter plugin (subscribers, lists, delivery)', 'omni-platform-newsletter'); ?></a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=print-my-blog-projects')); ?>"><?php esc_html_e('Print My Blog', 'omni-platform-newsletter'); ?></a></li>
            </ul>
        </section>
    <?php endif; ?>
</div>

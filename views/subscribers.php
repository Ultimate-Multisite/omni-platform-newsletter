<?php

/**
 * Subscribers.
 *
 * @var array<int, string> $lists
 * @var array{confirmed:int, pending:int, unsubscribed:int, total:int} $stats
 * @var string $search
 * @var int $paged
 * @var int $perPage
 * @var list<object> $subscribers
 * @var int $found
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

$pages = max(1, (int) ceil($found / $perPage));
?>
<div class="wrap opn-admin">
    <div class="opn-page-header">
        <h1><?php esc_html_e('Subscribers', 'omni-platform-newsletter'); ?></h1>
        <?php if ($stats['total'] > 0) : ?>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=opn_export_subscribers'), 'opn_export_subscribers')); ?>"><?php esc_html_e('Download as spreadsheet (CSV)', 'omni-platform-newsletter'); ?></a>
        <?php endif; ?>
    </div>
    <hr class="wp-header-end">
    <?php renderNotice(); ?>

    <?php if (!deliveryAvailable()) : ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('Email sending is not set up on this site yet. Please contact support.', 'omni-platform-newsletter'); ?></p></div>
        <?php echo '</div>';
        return; ?>
    <?php endif; ?>

    <div class="opn-cards">
        <div class="opn-card"><span class="opn-card__value"><?php echo esc_html(number_format_i18n($stats['confirmed'])); ?></span><span class="opn-card__label"><?php esc_html_e('receive your newsletter', 'omni-platform-newsletter'); ?></span></div>
        <div class="opn-card"><span class="opn-card__value"><?php echo esc_html(number_format_i18n($stats['pending'])); ?></span><span class="opn-card__label"><?php esc_html_e('waiting to confirm their email', 'omni-platform-newsletter'); ?></span></div>
        <div class="opn-card"><span class="opn-card__value"><?php echo esc_html(number_format_i18n($stats['unsubscribed'])); ?></span><span class="opn-card__label"><?php esc_html_e('unsubscribed or bounced', 'omni-platform-newsletter'); ?></span></div>
    </div>

    <div class="opn-columns">
        <form class="opn-panel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <h2><?php esc_html_e('Add a subscriber', 'omni-platform-newsletter'); ?></h2>
            <input type="hidden" name="action" value="opn_add_subscriber">
            <?php wp_nonce_field('opn_add_subscriber'); ?>
            <p><label for="opn-email"><?php esc_html_e('Email address', 'omni-platform-newsletter'); ?></label><br>
                <input id="opn-email" class="regular-text" type="email" name="email" required autocomplete="off"></p>
            <p><label for="opn-name"><?php esc_html_e('Name (optional)', 'omni-platform-newsletter'); ?></label><br>
                <input id="opn-name" class="regular-text" type="text" name="name" autocomplete="off"></p>
            <?php if ($lists) : ?>
                <fieldset><legend><?php esc_html_e('Lists', 'omni-platform-newsletter'); ?></legend>
                    <?php foreach ($lists as $id => $name) : ?>
                        <label class="opn-check"><input type="checkbox" name="lists[]" value="<?php echo esc_attr((string) $id); ?>"> <?php echo esc_html($name); ?></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <p><label class="opn-check"><input type="checkbox" name="consent" value="1" required> <?php esc_html_e('This person asked to receive our newsletter.', 'omni-platform-newsletter'); ?></label></p>
            <p><button class="button button-primary" type="submit"><?php esc_html_e('Add subscriber', 'omni-platform-newsletter'); ?></button></p>
        </form>

        <form class="opn-panel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <h2><?php esc_html_e('Add many at once', 'omni-platform-newsletter'); ?></h2>
            <input type="hidden" name="action" value="opn_import_subscribers">
            <?php wp_nonce_field('opn_import_subscribers'); ?>
            <p><label for="opn-addresses"><?php esc_html_e('Paste one person per line: an email address, optionally followed by a comma and their name. You can copy two columns straight from a spreadsheet. Up to 5,000 people at a time.', 'omni-platform-newsletter'); ?></label></p>
            <textarea id="opn-addresses" name="addresses" rows="7" class="large-text code" placeholder="mary@example.org, Mary Smith&#10;john@example.org" required></textarea>
            <?php if ($lists) : ?>
                <fieldset><legend><?php esc_html_e('Add everyone to these lists', 'omni-platform-newsletter'); ?></legend>
                    <?php foreach ($lists as $id => $name) : ?>
                        <label class="opn-check"><input type="checkbox" name="lists[]" value="<?php echo esc_attr((string) $id); ?>"> <?php echo esc_html($name); ?></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <p><label class="opn-check"><input type="checkbox" name="consent" value="1" required> <?php esc_html_e('Everyone on this list asked to receive our newsletter.', 'omni-platform-newsletter'); ?></label></p>
            <p><button class="button" type="submit"><?php esc_html_e('Add these subscribers', 'omni-platform-newsletter'); ?></button></p>
        </form>
    </div>

    <p class="opn-tip"><?php esc_html_e('People can also sign up themselves using the newsletter signup form on your website. They will appear here after confirming their email address.', 'omni-platform-newsletter'); ?></p>

    <form class="opn-search" method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>">
        <input type="hidden" name="post_type" value="newspack_nl_cpt">
        <input type="hidden" name="page" value="<?php echo esc_attr(PAGE_SUBSCRIBERS); ?>">
        <label class="screen-reader-text" for="opn-search"><?php esc_html_e('Search subscribers', 'omni-platform-newsletter'); ?></label>
        <input id="opn-search" type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search by name or email', 'omni-platform-newsletter'); ?>">
        <button class="button" type="submit"><?php esc_html_e('Search', 'omni-platform-newsletter'); ?></button>
    </form>

    <?php if (!$subscribers) : ?>
        <p class="opn-empty-inline"><?php echo esc_html($search !== '' ? __('No subscribers match your search.', 'omni-platform-newsletter') : __('No subscribers yet. Add your first subscriber above.', 'omni-platform-newsletter')); ?></p>
    <?php else : ?>
        <table class="widefat striped opn-table">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Email', 'omni-platform-newsletter'); ?></th>
                <th scope="col"><?php esc_html_e('Name', 'omni-platform-newsletter'); ?></th>
                <th scope="col"><?php esc_html_e('Status', 'omni-platform-newsletter'); ?></th>
                <?php if ($lists) : ?>
                    <th scope="col"><?php esc_html_e('Lists', 'omni-platform-newsletter'); ?></th>
                <?php endif; ?>
                <th scope="col"><?php esc_html_e('Joined', 'omni-platform-newsletter'); ?></th>
                <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'omni-platform-newsletter'); ?></span></th>
            </tr></thead>
            <tbody>
            <?php foreach ($subscribers as $subscriber) :
                $memberOf = array_filter($lists, fn($name, $id) => !empty($subscriber->{'list_' . $id}), ARRAY_FILTER_USE_BOTH);
                $actionUrl = fn(string $do) => wp_nonce_url(admin_url('admin-post.php?action=opn_subscriber_action&do=' . $do . '&subscriber=' . (int) $subscriber->id), 'opn_subscriber_' . $do . '_' . (int) $subscriber->id);
                ?>
                <tr>
                    <td><?php echo esc_html($subscriber->email); ?></td>
                    <td><?php echo esc_html(trim($subscriber->name . ' ' . $subscriber->surname)); ?></td>
                    <td><span class="opn-badge opn-badge--sub-<?php echo esc_attr(strtolower((string) $subscriber->status)); ?>"><?php echo esc_html(subscriberStatusLabel((string) $subscriber->status)); ?></span></td>
                    <?php if ($lists) : ?>
                        <td><?php echo esc_html(implode(', ', $memberOf)); ?></td>
                    <?php endif; ?>
                    <td><?php echo esc_html(mysql2date(get_option('date_format'), (string) $subscriber->created)); ?></td>
                    <td class="opn-actions">
                        <?php if ($subscriber->status === 'C') : ?>
                            <a href="<?php echo esc_url($actionUrl('unsubscribe')); ?>" onclick="return confirm('<?php echo esc_js(__('Unsubscribe this person? They will stop receiving your newsletter.', 'omni-platform-newsletter')); ?>');"><?php esc_html_e('Unsubscribe', 'omni-platform-newsletter'); ?></a>
                        <?php endif; ?>
                        <a class="opn-danger" href="<?php echo esc_url($actionUrl('delete')); ?>" onclick="return confirm('<?php echo esc_js(__('Remove this subscriber completely?', 'omni-platform-newsletter')); ?>');"><?php esc_html_e('Remove', 'omni-platform-newsletter'); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($pages > 1) : ?>
            <div class="tablenav"><div class="tablenav-pages">
                <?php echo wp_kses_post((string) paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $paged,
                    'total' => $pages,
                ])); ?>
            </div></div>
        <?php endif; ?>
    <?php endif; ?>
</div>

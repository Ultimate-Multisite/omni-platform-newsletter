<?php

/**
 * Email delivery through The Newsletter Plugin ("Newsletter" by Stefano Lissa).
 *
 * Newspack Newsletters converts the block newsletter into email-safe HTML and
 * stores it in post meta when the newsletter is saved. This adapter hands that
 * HTML to the Newsletter plugin, which owns subscribers, lists, consent,
 * unsubscribe links, batching, and the actual sending.
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const EMAIL_HTML_META = 'newspack_email_html';
const EMAIL_SOURCE_META = '_omni_platform_newsletter_email_source';
const DELIVERY_META = '_omni_platform_newsletter_delivery';
const SUBJECT_META = '_omni_platform_newsletter_subject';

/** Whether the Newsletter plugin is active and its tables are known. */
function deliveryAvailable(): bool
{
    return class_exists('\Newsletter')
        && defined('NEWSLETTER_USERS_TABLE')
        && defined('NEWSLETTER_EMAILS_TABLE');
}

/** @return array<int, string> Newsletter plugin list ID => name for configured lists. */
function deliveryLists(): array
{
    if (!deliveryAvailable()) {
        return [];
    }

    $lists = [];
    foreach ((array) \Newsletter::instance()->get_lists() as $list) {
        if (!empty($list->id) && trim((string) ($list->name ?? '')) !== '') {
            $lists[(int) $list->id] = (string) $list->name;
        }
    }

    return $lists;
}

/** @param list<int> $lists Restrict to subscribers on any of these lists (empty = everyone). */
function audienceQuery(array $lists = []): string
{
    $query = 'select * from ' . NEWSLETTER_USERS_TABLE . " where status='C'";
    $known = array_keys(deliveryLists());
    $where = [];
    foreach ($lists as $list) {
        $list = (int) $list;
        if (in_array($list, $known, true)) {
            $where[] = 'list_' . $list . '=1';
        }
    }

    return $where ? $query . ' and (' . implode(' or ', $where) . ')' : $query;
}

/** Number of confirmed subscribers who would receive a campaign. */
function audienceCount(array $lists = []): int
{
    global $wpdb;

    if (!deliveryAvailable()) {
        return 0;
    }

    return (int) $wpdb->get_var(str_replace('select *', 'select count(*)', audienceQuery($lists)));
}

/** @return array{confirmed:int, pending:int, unsubscribed:int, total:int} */
function subscriberStats(): array
{
    global $wpdb;

    $stats = ['confirmed' => 0, 'pending' => 0, 'unsubscribed' => 0, 'total' => 0];
    if (!deliveryAvailable()) {
        return $stats;
    }

    foreach ((array) $wpdb->get_results('select status, count(*) as n from ' . NEWSLETTER_USERS_TABLE . ' group by status') as $row) {
        $count = (int) $row->n;
        $stats['total'] += $count;
        match ((string) $row->status) {
            'C' => $stats['confirmed'] += $count,
            'S' => $stats['pending'] += $count,
            default => $stats['unsubscribed'] += $count,
        };
    }

    return $stats;
}

/**
 * The address newsletters are sent from.
 *
 * The Newsletter plugin sends with an empty From header (which every mail
 * transport rejects) when its sender address was never set, for example on a
 * site cloned from a template. Fall back to WordPress's own From address, which
 * hosting platforms already filter to a verified sender.
 */
function senderEmail(): string
{
    if (!deliveryAvailable()) {
        return '';
    }

    $main = (array) get_option('newsletter_main', []);
    $current = sanitize_email((string) ($main['sender_email'] ?? ''));
    if (is_email($current)) {
        return $current;
    }

    $host = (string) preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
    $fallback = sanitize_email((string) apply_filters('wp_mail_from', 'wordpress@' . $host));
    if (is_email($fallback)) {
        $main['sender_email'] = $fallback;
        update_option('newsletter_main', $main);
    }

    return is_email($fallback) ? $fallback : '';
}

/** Default audience (list IDs) chosen in settings; empty means all confirmed subscribers. */
function defaultAudience(): array
{
    $lists = array_map('intval', (array) getSetting('default_lists'));
    return array_values(array_intersect($lists, array_keys(deliveryLists())));
}

/** Remember which saved content Newspack's email HTML was generated from. */
function recordEmailSource(int $metaId, int $postId, string $metaKey): void
{
    if ($metaKey !== EMAIL_HTML_META) {
        return;
    }

    $post = get_post($postId);
    if ($post instanceof \WP_Post && isIssue($post)) {
        update_post_meta($postId, EMAIL_SOURCE_META, md5($post->post_content));
    }
}

add_action('added_post_meta', fn($metaId, $postId, $metaKey) => recordEmailSource((int) $metaId, (int) $postId, (string) $metaKey), 10, 3);
add_action('updated_post_meta', fn($metaId, $postId, $metaKey) => recordEmailSource((int) $metaId, (int) $postId, (string) $metaKey), 10, 3);

const PREVIEW_AUTO_META = '_omni_platform_newsletter_preview_auto';

/**
 * Inbox preview text ("preheader") from the front page.
 *
 * Newspack otherwise uses the start of the post, which is the sidebar (motto
 * and contact details). Text the author typed is kept; only text this plugin
 * generated is refreshed when the front page changes.
 */
function refreshPreviewText(int $postId): void
{
    $post = get_post($postId);
    if (!$post instanceof \WP_Post || wp_is_post_revision($postId) || !isIssue($post)) {
        return;
    }

    $sections = sections($post);
    if (!isset($sections['front'])) {
        return;
    }

    $current = (string) get_post_meta($postId, 'preview_text', true);
    $lastAuto = (string) get_post_meta($postId, PREVIEW_AUTO_META, true);
    if ($current !== '' && $current !== $lastAuto) {
        return;
    }

    // Skip the masthead (issue date, kicker, title): the subject line already says it.
    $body = array_filter($sections['front']['innerBlocks'] ?? [], fn($block) => !array_intersect(
        preg_split('/\s+/', (string) ($block['attrs']['className'] ?? '')),
        ['opn-issue-label', 'opn-kicker', 'opn-title']
    ));
    $html = implode(' ', array_map('serialize_block', $body ?: [$sections['front']]));
    $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(str_replace('</', ' </', $html))));
    $preview = wp_trim_words($text, 28, '…');
    update_post_meta($postId, 'preview_text', $preview);
    update_post_meta($postId, PREVIEW_AUTO_META, $preview);
}

// Before Newspack builds the email version (it does so right after the save request).
add_action('save_post_newspack_nl_cpt', fn($id) => refreshPreviewText((int) $id), 30);
add_action('rest_after_insert_newspack_nl_cpt', fn($post) => refreshPreviewText((int) $post->ID), 30);

/** Email subject: the explicit subject, otherwise the newsletter title. */
function emailSubject(\WP_Post $post): string
{
    $subject = trim((string) get_post_meta($post->ID, SUBJECT_META, true));
    return $subject !== '' ? $subject : html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');
}

/** Public web address of a published newsletter, or '' when not on the website. */
function webUrl(\WP_Post $post): string
{
    return $post->post_status === 'publish' && get_post_meta($post->ID, 'is_public', true) ? (string) get_permalink($post) : '';
}

/** Email-ready HTML with the subscriber footer, or an error explaining what to do. */
function emailHtml(\WP_Post $post): string|\WP_Error
{
    if (count(sections($post)) !== 4) {
        return new \WP_Error('opn_layout', __('This newsletter needs its Sidebar, Front, Back and Footer sections before it can be emailed.', 'omni-platform-newsletter'));
    }

    $html = (string) get_post_meta($post->ID, EMAIL_HTML_META, true);
    if (trim($html) === '') {
        return new \WP_Error('opn_email_missing', __('The email version has not been created yet. Save the newsletter, then try again.', 'omni-platform-newsletter'));
    }

    if (get_post_meta($post->ID, EMAIL_SOURCE_META, true) !== md5($post->post_content)) {
        return new \WP_Error('opn_email_stale', __('The email version is older than your latest changes. Save the newsletter, then try again.', 'omni-platform-newsletter'));
    }

    $web = webUrl($post);
    $links = [];
    if ($web !== '') {
        $links[] = '<a href="' . esc_url($web) . '" style="color:#555555;">' . esc_html__('Read it on our website', 'omni-platform-newsletter') . '</a>';
    }
    $links[] = '<a href="{unsubscription_confirm_url}" style="color:#555555;">' . esc_html__('Unsubscribe', 'omni-platform-newsletter') . '</a>';

    $footer = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td align="center" style="padding:24px 16px 32px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#555555;">'
        . esc_html(sprintf(
            /* translators: %s: site name */
            __('You are receiving this email because you subscribed to updates from %s.', 'omni-platform-newsletter'),
            html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8')
        ))
        . '<br>' . implode(' &middot; ', $links) . '</td></tr></table>';

    // Insert by offset: a replacement string would treat "$1" or "\\" in the site name as back-references.
    $at = strripos($html, '</body>');
    $html = $at !== false ? substr_replace($html, $footer, $at, 0) : $html . $footer;

    return (string) apply_filters('omni_platform_newsletter_email_html', $html, $post);
}

/** Build the Newsletter plugin email record for a newsletter. */
function deliveryRecord(\WP_Post $post, string $html, array $lists): array
{
    $query = audienceQuery($lists);

    return [
        'subject' => emailSubject($post),
        'message' => $html,
        'message_text' => '',
        'status' => 'new',
        'type' => 'message',
        'editor' => 1,
        'track' => 0,
        'private' => 0,
        'token' => \Newsletter::instance()->get_token(),
        'send_on' => time(),
        'updated' => time(),
        'query' => $query,
        'total' => audienceCount($lists),
        'options' => [
            'lists' => array_map('intval', $lists),
            'lists_operator' => 'or',
            'status' => 'C',
            'wp_users' => '0',
            'omni_post_id' => $post->ID,
        ],
    ];
}

/** Send the newsletter to one address. Nothing is recorded as a campaign. */
function sendTest(\WP_Post $post, string $address): true|\WP_Error
{
    if (!deliveryAvailable()) {
        return new \WP_Error('opn_delivery', __('Email sending is not set up on this site.', 'omni-platform-newsletter'));
    }

    $address = sanitize_email($address);
    if (!is_email($address)) {
        return new \WP_Error('opn_address', __('Enter a valid email address for the test.', 'omni-platform-newsletter'));
    }

    $html = emailHtml($post);
    if (is_wp_error($html)) {
        return $html;
    }
    if (senderEmail() === '') {
        return new \WP_Error('opn_sender', __('Email sending is not set up on this site yet. Please contact support.', 'omni-platform-newsletter'));
    }

    $newsletter = \Newsletter::instance();
    $email = (object) deliveryRecord($post, $html, []);
    $email->id = 0;
    /* translators: %s: email subject */
    $email->subject = sprintf(__('[Test] %s', 'omni-platform-newsletter'), $email->subject);

    $recipient = $newsletter->get_user($address) ?: $newsletter->get_dummy_user();
    $recipient->email = $address;

    // The Newsletter plugin reports success even when wp_mail() fails; listen for the failure.
    $failure = null;
    $listen = function ($error) use (&$failure) {
        $failure = $error;
    };
    add_action('wp_mail_failed', $listen);
    $result = $newsletter->send($email, [$recipient], true);
    remove_action('wp_mail_failed', $listen);

    if (is_wp_error($result) || $failure instanceof \WP_Error) {
        $detail = is_wp_error($result) ? $result->get_error_message() : $failure->get_error_message();
        return new \WP_Error('opn_send', __('The test email could not be sent. Please try again or contact support.', 'omni-platform-newsletter'), $detail);
    }

    update_post_meta($post->ID, '_omni_platform_newsletter_last_test', ['to' => $address, 'at' => time()]);

    return true;
}

/** Current delivery state of a newsletter. */
function deliveryStatus(\WP_Post $post): array
{
    $record = get_post_meta($post->ID, DELIVERY_META, true);
    if (!is_array($record) || empty($record['email_id']) || !deliveryAvailable()) {
        return ['state' => 'not_sent'];
    }

    $email = \Newsletter::instance()->get_email((int) $record['email_id']);
    if (!$email) {
        return ['state' => 'not_sent'];
    }

    $state = match ((string) $email->status) {
        'sent' => 'sent',
        'sending' => 'sending',
        'paused' => 'paused',
        'error' => 'error',
        default => 'queued',
    };
    // The Newsletter plugin marks a campaign finished on its next run; every recipient delivered means sent.
    if ($state === 'sending' && (int) $email->total > 0 && (int) $email->sent >= (int) $email->total) {
        $state = 'sent';
    }

    return [
        'state' => $state,
        'sent' => (int) $email->sent,
        'total' => (int) $email->total,
        'started' => (int) ($record['started'] ?? 0),
        'lists' => array_map('intval', (array) ($record['lists'] ?? [])),
    ];
}

/** Queue the newsletter for every confirmed subscriber in the chosen lists. */
function sendCampaign(\WP_Post $post, array $lists, bool $again = false): array|\WP_Error
{
    if (!deliveryAvailable()) {
        return new \WP_Error('opn_delivery', __('Email sending is not set up on this site.', 'omni-platform-newsletter'));
    }

    // One send at a time per newsletter (double clicks, two staff members).
    $lock = 'omni_platform_newsletter_lock_' . $post->ID;
    if (!add_option($lock, time(), '', false)) {
        if ((int) get_option($lock) > time() - 120) {
            return new \WP_Error('opn_in_progress', __('This newsletter is already being sent.', 'omni-platform-newsletter'));
        }
        delete_option($lock); // Stale lock from an interrupted request.
        if (!add_option($lock, time(), '', false)) {
            return new \WP_Error('opn_in_progress', __('This newsletter is already being sent.', 'omni-platform-newsletter'));
        }
    }

    try {
        return queueCampaign($post, $lists, $again);
    } finally {
        delete_option($lock);
    }
}

/** Queue a campaign; the caller holds the per-newsletter send lock. */
function queueCampaign(\WP_Post $post, array $lists, bool $again): array|\WP_Error
{
    global $wpdb;

    $status = deliveryStatus($post);
    if (in_array($status['state'], ['sending', 'queued', 'paused'], true)) {
        return new \WP_Error('opn_in_progress', __('This newsletter is already being sent.', 'omni-platform-newsletter'));
    }
    if ($status['state'] === 'sent' && !$again) {
        return new \WP_Error('opn_already_sent', __('This newsletter was already emailed. Confirm that you want to send it again.', 'omni-platform-newsletter'));
    }

    $html = emailHtml($post);
    if (is_wp_error($html)) {
        return $html;
    }
    if (senderEmail() === '') {
        return new \WP_Error('opn_sender', __('Email sending is not set up on this site yet. Please contact support.', 'omni-platform-newsletter'));
    }

    $requested = array_values(array_unique(array_map('intval', $lists)));
    $lists = array_values(array_intersect($requested, array_keys(deliveryLists())));
    // Never widen a list send to everyone because the chosen lists no longer exist.
    if ($requested !== [] && $lists === []) {
        return new \WP_Error('opn_unknown_list', __('The selected list no longer exists. Reload the page and choose who should receive it.', 'omni-platform-newsletter'));
    }
    $record = deliveryRecord($post, $html, $lists);
    if ($record['total'] < 1) {
        return new \WP_Error('opn_no_audience', __('There are no confirmed subscribers to send to yet.', 'omni-platform-newsletter'));
    }

    $newsletter = \Newsletter::instance();
    $email = $newsletter->save_email($record);
    if (!$email || is_wp_error($email)) {
        return new \WP_Error('opn_save', __('The newsletter could not be queued. Please try again or contact support.', 'omni-platform-newsletter'));
    }

    $wpdb->update(NEWSLETTER_EMAILS_TABLE, ['status' => 'sending', 'send_on' => time()], ['id' => (int) $email->id]);

    update_post_meta($post->ID, DELIVERY_META, [
        'engine' => 'newsletter',
        'email_id' => (int) $email->id,
        'lists' => $lists,
        'started' => time(),
        'by' => get_current_user_id(),
    ]);

    // Like the Newsletter plugin's own "Send" button: deliver a small first batch now.
    if ($record['total'] <= (int) apply_filters('omni_platform_newsletter_immediate_batch', 20) && class_exists('\NewsletterEngine')) {
        \NewsletterEngine::instance()->run();
    }

    do_action('omni_platform_newsletter_campaign_queued', $post, (int) $email->id, $lists);

    return deliveryStatus($post);
}

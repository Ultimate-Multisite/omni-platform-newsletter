<?php

/**
 * One "Newsletters" area: list, write, subscribers, and settings.
 *
 * Newspack Newsletters (block editor + email HTML), the Newsletter plugin
 * (subscribers + sending), and Print My Blog stay installed as engines, but
 * their own menus are reserved for users with the engine capability.
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const SETTINGS_OPTION = 'omni_platform_newsletter_settings';
const MENU_PARENT = 'edit.php?post_type=newspack_nl_cpt';
const PAGE_LIST = 'omni-newsletters';
const PAGE_SUBSCRIBERS = 'omni-newsletter-subscribers';
const PAGE_SETTINGS = 'omni-newsletter-settings';

/** @return array{default_lists: list<int>, publish_web: bool, share_social: bool} */
function settingsDefaults(): array
{
    return ['default_lists' => [], 'publish_web' => true, 'share_social' => false];
}

function getSetting(string $key): mixed
{
    $settings = array_merge(settingsDefaults(), (array) get_option(SETTINGS_OPTION, []));
    return $settings[$key] ?? null;
}

function pageUrl(string $page, array $args = []): string
{
    return add_query_arg(array_merge(['post_type' => 'newspack_nl_cpt', 'page' => $page], $args), admin_url('edit.php'));
}

function newIssueUrl(): string
{
    return admin_url('post-new.php?post_type=newspack_nl_cpt');
}

/** Where the issue's two-page print preview lives (draft issues use the preview link). */
function printPreviewUrl(\WP_Post $post): string
{
    if ($post->post_status === 'publish') {
        return add_query_arg('opn_view', 'print', get_permalink($post));
    }

    return add_query_arg('opn_view', 'print', get_preview_post_link($post));
}

/** Friendly wording for actions that would otherwise talk about "sending". */
add_filter('register_post_type_args', function (array $args, string $type) {
    if ($type !== 'newspack_nl_cpt') {
        return $args;
    }

    $args['menu_position'] = 4;
    $args['labels'] = array_merge($args['labels'] ?? [], [
        'add_new' => __('Write a newsletter', 'omni-platform-newsletter'),
        'add_new_item' => __('Write a newsletter', 'omni-platform-newsletter'),
        'all_items' => __('All newsletters', 'omni-platform-newsletter'),
        'item_published' => __('Newsletter published on your website.', 'omni-platform-newsletter'),
        'item_published_privately' => __('Newsletter saved privately. It is not on your website.', 'omni-platform-newsletter'),
        'item_scheduled' => __('Newsletter scheduled for your website.', 'omni-platform-newsletter'),
        'item_updated' => __('Newsletter updated.', 'omni-platform-newsletter'),
    ]);

    return $args;
}, 20, 2);

/** Register Omni's pages under the Newsletters menu. */
add_action('admin_menu', function () {
    add_submenu_page(MENU_PARENT, __('Newsletters', 'omni-platform-newsletter'), __('All newsletters', 'omni-platform-newsletter'), CAP_WRITE, PAGE_LIST, __NAMESPACE__ . '\\renderListPage', 0);
    add_submenu_page(MENU_PARENT, __('Subscribers', 'omni-platform-newsletter'), __('Subscribers', 'omni-platform-newsletter'), CAP_SUBSCRIBERS, PAGE_SUBSCRIBERS, __NAMESPACE__ . '\\renderSubscribersPage');
    add_submenu_page(MENU_PARENT, __('Newsletter settings', 'omni-platform-newsletter'), __('Settings', 'omni-platform-newsletter'), CAP_SETTINGS, PAGE_SETTINGS, __NAMESPACE__ . '\\renderSettingsPage');
}, 20);

/** Keep one tidy menu: Omni pages first, engine screens only for engine users. */
add_action('admin_menu', function () {
    global $submenu;

    $engines = currentUserManagesEngines();
    $ordered = [];
    $rest = [];
    foreach ($submenu[MENU_PARENT] ?? [] as $item) {
        $slug = html_entity_decode((string) $item[2]);
        if ($slug === PAGE_LIST) {
            $ordered[0] = $item;
        } elseif (str_starts_with($slug, 'post-new.php')) {
            $item[0] = __('Write a newsletter', 'omni-platform-newsletter');
            $ordered[1] = $item;
        } elseif ($slug === PAGE_SUBSCRIBERS) {
            $ordered[2] = $item;
        } elseif ($slug === PAGE_SETTINGS) {
            $ordered[3] = $item;
        } elseif ($engines && $slug !== MENU_PARENT) {
            $rest[] = $item;
        }
    }
    ksort($ordered);
    $submenu[MENU_PARENT] = array_values([...$ordered, ...$rest]); // phpcs:ignore WordPress.WP.GlobalVariablesOverride

    if (!$engines) {
        remove_menu_page('newsletter_main_index');
        remove_menu_page('print-my-blog-projects');
        remove_menu_page('edit.php?post_type=newspack_nl_ads_cpt');
        remove_menu_page('edit.php?post_type=pmb_content');
    }
}, PHP_INT_MAX - 10);

/** Send the bare post-type screen and blocked engine screens to Omni's list. */
add_action('current_screen', function ($screen) {
    $page = sanitize_key((string) ($_GET['page'] ?? ''));
    $listScreen = $screen->id === 'edit-newspack_nl_cpt' && ($page === '' || $page === 'newspack-newsletters-list');
    $engineScreen = !currentUserManagesEngines() && (
        str_starts_with($page, 'newspack-newsletters')
        || str_starts_with($page, 'newsletter_')
        || str_starts_with($page, 'print-my-blog')
        || in_array($screen->post_type ?? '', ['newspack_nl_ads_cpt', 'pmb_content', 'pmb_design', 'pmb_project'], true)
        || ($screen->taxonomy ?? '') === 'newspack_nl_advertiser'
    );

    // Only engine users may open Newspack's own list (linked from Settings → Advanced).
    $engineList = isset($_GET['opn_engine_list']) && currentUserManagesEngines();
    if (($listScreen && !$engineList) || $engineScreen) {
        wp_safe_redirect(pageUrl(PAGE_LIST));
        exit;
    }
}, 1);

/** Highlight "All newsletters" while editing an issue. */
add_filter('submenu_file', function ($file, $parent) {
    $screen = get_current_screen();
    if ($parent === MENU_PARENT && $screen && $screen->base === 'post' && $screen->post_type === 'newspack_nl_cpt') {
        return $screen->action === 'add' ? 'post-new.php?post_type=newspack_nl_cpt' : PAGE_LIST;
    }

    return $file;
}, 10, 2);

/** Latest issue whose shared sidebar/footer can seed the next one. */
function latestIssue(int $exclude = 0): ?\WP_Post
{
    $posts = get_posts([
        'post_type' => 'newspack_nl_cpt',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
        'exclude' => $exclude ? [$exclude] : [],
        'meta_key' => ISSUE_META_KEY,
        'meta_value' => '1',
    ]);

    foreach ($posts as $post) {
        if (count(sections($post)) === 4) {
            return $post;
        }
    }

    return null;
}

/** New issues reuse the latest Sidebar and Footer, with fresh Front and Back placeholders. */
function newIssueContent(): string
{
    /**
     * Starter content for an organization's first newsletter (before any issue exists).
     * Must keep the four opn-source-* sections; defaults to the neutral pattern.
     */
    $starterContent = (string) apply_filters('omni_platform_newsletter_starter_content', starterPatternContent());
    if (count(array_filter(parse_blocks($starterContent), fn($b) => $b['blockName'] !== null)) !== 4) {
        $starterContent = starterPatternContent();
    }
    $starter = parse_blocks($starterContent);
    $previous = latestIssue();
    if (!$previous) {
        return $starterContent;
    }

    $shared = sections($previous);
    $blocks = [];
    foreach ($starter as $block) {
        if ($block['blockName'] === null && trim($block['innerHTML']) === '') {
            continue;
        }
        $classes = preg_split('/\s+/', $block['attrs']['className'] ?? '');
        $next = in_array('opn-source-sidebar', $classes, true) ? $shared['sidebar']
            : (in_array('opn-source-footer', $classes, true) ? $shared['footer'] : $block);
        $next['attrs']['lock'] = ['move' => true, 'remove' => true];
        $blocks[] = $next;
    }

    return serialize_blocks($blocks);
}

add_filter('default_content', function ($content, $post) {
    return $post instanceof \WP_Post && $post->post_type === 'newspack_nl_cpt' && trim((string) $content) === '' ? newIssueContent() : $content;
}, 10, 2);

// Newspack titles new newsletters with today's date; a month name reads better for a monthly letter.
add_filter('default_title', function ($title, $post) {
    if ($post instanceof \WP_Post && $post->post_type === 'newspack_nl_cpt') {
        /* translators: %s: month and year */
        return sprintf(__('%s Newsletter', 'omni-platform-newsletter'), wp_date('F Y'));
    }

    return $title;
}, 20, 2);

/** The issue's look comes from Omni's own template, so a theme template choice would only mislead. */
add_filter('block_editor_settings_all', function (array $settings, $context) {
    $post = $context->post ?? null;
    if ($post instanceof \WP_Post && $post->post_type === 'newspack_nl_cpt' && !currentUserManagesEngines()) {
        $settings['availableTemplates'] = [];
        $settings['supportsTemplateMode'] = false;
    }

    return $settings;
}, 20, 2);

/** In the editor canvas, label each section with where it appears. */
function enqueueCanvasLabels(): void
{
    // Runs while WordPress collects the iframe canvas assets, where the screen may not be set yet.
    $post = get_post();
    if (!is_admin() || !$post instanceof \WP_Post || $post->post_type !== 'newspack_nl_cpt') {
        return;
    }

    $labels = [
        'sidebar' => __('Sidebar · printed beside both pages, and once at the end of the email', 'omni-platform-newsletter'),
        'front' => __('Front page', 'omni-platform-newsletter'),
        'back' => __('Back page', 'omni-platform-newsletter'),
        'footer' => __('Footer · printed at the bottom of both pages', 'omni-platform-newsletter'),
    ];
    $tints = ['sidebar' => '#eef0f6', 'front' => '#ffffff', 'back' => '#ffffff', 'footer' => '#f3f3f3'];
    $css = '';
    foreach ($labels as $role => $label) {
        $selector = '.editor-styles-wrapper .opn-source-' . $role;
        $css .= $selector . '{position:relative;margin:28px 0!important;padding:34px 18px 14px!important;border:2px dashed #a7aab4;border-radius:8px;background:' . $tints[$role] . ';}'
            . $selector . '::before{content:' . wp_json_encode($label, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';position:absolute;top:6px;left:12px;padding:2px 8px;border-radius:4px;background:#fff;box-shadow:0 0 0 1px #a7aab4;font:600 12px/1.3 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:.04em;text-transform:uppercase;color:#2a4f9c;z-index:2;}';
    }

    // Omni flattens story and callout groups for email (see emailBlocks()), so Newspack's
    // "Nested group" warning does not apply inside the four sections.
    $css .= '.editor-styles-wrapper [class*="opn-source-"] .newspack-newsletters__editor-block__warning{display:none;}';

    wp_register_style('omni-platform-newsletter-canvas', false, [], VERSION);
    wp_enqueue_style('omni-platform-newsletter-canvas');
    wp_add_inline_style('omni-platform-newsletter-canvas', $css);
}

add_action('enqueue_block_assets', __NAMESPACE__ . '\\enqueueCanvasLabels');
// Newspack strips canvas assets that are not on its allowlist.
add_filter('newspack_newsletters_allowed_editor_actions', fn(array $actions) => [...$actions, __NAMESPACE__ . '\\enqueueCanvasLabels']);

/** Print My Blog's editor buttons and post-type switcher are engine tools. */
add_action('enqueue_block_editor_assets', function () {
    $post = get_post();
    if ($post instanceof \WP_Post && $post->post_type === 'newspack_nl_cpt' && !currentUserManagesEngines()) {
        wp_dequeue_script('pmb_blockeditor');
        wp_dequeue_style('pmb_blockeditor');
    }
}, 100);

/** Every new issue skips Newspack's layout picker and uses the duplex layout. */
add_action('save_post_newspack_nl_cpt', function ($id, $post, $update) {
    if ($update || wp_is_post_revision($id)) {
        return;
    }

    update_post_meta($id, 'template_id', 0);
    update_post_meta($id, ISSUE_META_KEY, '1');
    update_post_meta($id, 'is_public', getSetting('publish_web') ? '1' : '');
    // Match the printed letter's typeface (Newspack defaults the email body to Georgia).
    update_post_meta($id, 'font_header', 'Arial');
    update_post_meta($id, 'font_body', 'Arial');
}, 20, 3);

/** Copy an issue into a new draft: the quickest way to start next month's edition. */
add_action('admin_post_opn_duplicate', function () {
    $source = get_post(absint($_GET['post'] ?? 0));
    if (!$source instanceof \WP_Post || $source->post_type !== 'newspack_nl_cpt') {
        wp_die(esc_html__('Newsletter not found.', 'omni-platform-newsletter'));
    }
    check_admin_referer('opn_duplicate_' . $source->ID);
    if (!current_user_can(CAP_WRITE) || !current_user_can('edit_post', $source->ID) || !current_user_can('edit_posts')) {
        wp_die(esc_html__('You are not allowed to copy this newsletter.', 'omni-platform-newsletter'), '', ['response' => 403]);
    }

    $id = wp_insert_post([
        'post_type' => 'newspack_nl_cpt',
        'post_status' => 'draft',
        /* translators: %s: original newsletter title */
        'post_title' => sprintf(__('%s (copy)', 'omni-platform-newsletter'), $source->post_title),
        'post_content' => wp_slash($source->post_content),
        'post_author' => get_current_user_id(),
    ], true);
    if (is_wp_error($id)) {
        wp_die(esc_html($id->get_error_message()));
    }

    foreach (['font_header', 'font_body', 'background_color', 'text_color', 'custom_css', 'preview_text', COLORS_META_KEY] as $key) {
        $value = get_post_meta($source->ID, $key, true);
        if ($value !== '') {
            update_post_meta($id, $key, $value);
        }
    }

    wp_safe_redirect(add_query_arg('opn_notice', 'copied', get_edit_post_link($id, 'raw')));
    exit;
});

/** Plain-language description of an issue's email state. */
function emailStateLabel(array $status): string
{
    return match ($status['state']) {
        'sent' => sprintf(
            /* translators: 1: recipients, 2: date */
            _n('Emailed to %1$s subscriber on %2$s', 'Emailed to %1$s subscribers on %2$s', $status['sent'], 'omni-platform-newsletter'),
            number_format_i18n($status['sent']),
            wp_date(get_option('date_format'), $status['started'])
        ),
        'sending', 'queued' => sprintf(
            /* translators: 1: sent so far, 2: total */
            __('Sending: %1$s of %2$s delivered', 'omni-platform-newsletter'),
            number_format_i18n($status['sent']),
            number_format_i18n($status['total'])
        ),
        'paused' => __('Sending paused', 'omni-platform-newsletter'),
        'error' => __('Sending stopped with an error. Contact support.', 'omni-platform-newsletter'),
        default => __('Not emailed yet', 'omni-platform-newsletter'),
    };
}

/** Plain-language website state. */
function webStateLabel(\WP_Post $post): string
{
    return match ($post->post_status) {
        'publish' => webUrl($post) !== '' ? __('On your website', 'omni-platform-newsletter') : __('Published privately', 'omni-platform-newsletter'),
        'future' => __('Scheduled', 'omni-platform-newsletter'),
        'private' => __('Private', 'omni-platform-newsletter'),
        default => __('Draft', 'omni-platform-newsletter'),
    };
}

function renderNotice(): void
{
    $messages = [
        'saved' => __('Settings saved.', 'omni-platform-newsletter'),
        'added' => __('Subscriber added.', 'omni-platform-newsletter'),
        'unsubscribed' => __('Subscriber unsubscribed. They will not receive future newsletters.', 'omni-platform-newsletter'),
        'deleted' => __('Subscriber removed.', 'omni-platform-newsletter'),
    ];
    $errors = [
        'consent' => __('Please confirm that the people you add asked to receive your newsletter.', 'omni-platform-newsletter'),
        'opn_exists' => __('That email address is already a subscriber.', 'omni-platform-newsletter'),
        'opn_invalid' => __('That email address is not valid.', 'omni-platform-newsletter'),
        'opn_delivery' => __('Subscriber management is not set up on this site.', 'omni-platform-newsletter'),
    ];
    $key = sanitize_key((string) ($_GET['opn_notice'] ?? ''));
    $error = sanitize_key((string) ($_GET['opn_error'] ?? ''));
    if ($key === 'imported') {
        $text = sprintf(
            /* translators: 1: added count, 2: skipped count */
            __('Import finished: %1$s added, %2$s skipped (already subscribed or not a valid address).', 'omni-platform-newsletter'),
            number_format_i18n(absint($_GET['added'] ?? 0)),
            number_format_i18n(absint($_GET['skipped'] ?? 0))
        );
        echo '<div class="notice notice-success opn-notice"><p>' . esc_html($text) . '</p></div>';
    } elseif (isset($messages[$key])) {
        echo '<div class="notice notice-success opn-notice"><p>' . esc_html($messages[$key]) . '</p></div>';
    }
    if (isset($errors[$error])) {
        echo '<div class="notice notice-error opn-notice"><p>' . esc_html($errors[$error]) . '</p></div>';
    }
}

function renderListPage(): void
{
    $issues = get_posts([
        'post_type' => 'newspack_nl_cpt',
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page' => 100,
        'orderby' => 'modified',
        'order' => 'DESC',
    ]);
    $stats = subscriberStats();
    $archive = get_post_type_archive_link('newspack_nl_cpt');
    require __DIR__ . '/../views/list.php';
}

function renderSubscribersPage(): void
{
    global $wpdb;

    $lists = deliveryLists();
    $stats = subscriberStats();
    $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
    $paged = max(1, absint($_GET['paged'] ?? 1));
    $perPage = 50;
    $subscribers = [];
    $found = 0;
    if (deliveryAvailable()) {
        $where = $search !== '' ? $wpdb->prepare(' where email like %s or name like %s or surname like %s', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%') : '';
        $found = (int) $wpdb->get_var('select count(*) from ' . NEWSLETTER_USERS_TABLE . $where);
        $subscribers = (array) $wpdb->get_results('select * from ' . NEWSLETTER_USERS_TABLE . $where . ' order by id desc' . $wpdb->prepare(' limit %d offset %d', $perPage, ($paged - 1) * $perPage));
    }
    require __DIR__ . '/../views/subscribers.php';
}

function renderSettingsPage(): void
{
    $lists = deliveryLists();
    $main = storedNewsletterMain();
    $accounts = socialAccounts();
    require __DIR__ . '/../views/settings.php';
}

function redirectBack(string $page, array $args): void
{
    wp_safe_redirect(pageUrl($page, $args));
    exit;
}

add_action('admin_post_opn_save_settings', function () {
    check_admin_referer('opn_save_settings');
    if (!current_user_can(CAP_SETTINGS)) {
        wp_die(esc_html__('You are not allowed to change newsletter settings.', 'omni-platform-newsletter'), '', ['response' => 403]);
    }

    $lists = array_values(array_intersect(array_map('intval', (array) ($_POST['default_lists'] ?? [])), array_keys(deliveryLists())));
    update_option(SETTINGS_OPTION, [
        'default_lists' => $lists,
        'publish_web' => !empty($_POST['publish_web']),
        'share_social' => !empty($_POST['share_social']),
    ]);

    if (deliveryAvailable()) {
        $main = storedNewsletterMain();
        $main['sender_name'] = sanitize_text_field(wp_unslash((string) ($_POST['sender_name'] ?? '')));
        $reply = sanitize_email(wp_unslash((string) ($_POST['reply_to'] ?? '')));
        $main['reply_to'] = is_email($reply) ? $reply : '';
        update_option('newsletter_main', $main);
    }

    syncSocialRule();
    redirectBack(PAGE_SETTINGS, ['opn_notice' => 'saved']);
});

/** Add one subscriber who has asked to receive the newsletter. */
function addSubscriber(string $email, string $name, array $lists): true|\WP_Error
{
    if (!deliveryAvailable() || !class_exists('\TNP')) {
        return new \WP_Error('opn_delivery', __('Subscriber management is not set up on this site.', 'omni-platform-newsletter'));
    }

    $result = \TNP::add_subscriber([
        'email' => $email,
        'name' => $name,
        'lists' => array_values(array_intersect(array_map('intval', $lists), array_keys(deliveryLists()))),
        'status' => 'C',
    ]);
    if (is_wp_error($result)) {
        return str_contains($result->get_error_message(), 'exists')
            ? new \WP_Error('opn_exists', __('That email address is already a subscriber.', 'omni-platform-newsletter'))
            : new \WP_Error('opn_invalid', __('That email address is not valid.', 'omni-platform-newsletter'));
    }

    return true;
}

add_action('admin_post_opn_add_subscriber', function () {
    check_admin_referer('opn_add_subscriber');
    if (!current_user_can(CAP_SUBSCRIBERS)) {
        wp_die(esc_html__('You are not allowed to manage subscribers.', 'omni-platform-newsletter'), '', ['response' => 403]);
    }
    if (empty($_POST['consent'])) {
        redirectBack(PAGE_SUBSCRIBERS, ['opn_error' => 'consent']);
    }

    $result = addSubscriber(
        sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))),
        sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))),
        (array) ($_POST['lists'] ?? [])
    );
    redirectBack(PAGE_SUBSCRIBERS, is_wp_error($result) ? ['opn_error' => $result->get_error_code()] : ['opn_notice' => 'added']);
});

add_action('admin_post_opn_import_subscribers', function () {
    check_admin_referer('opn_import_subscribers');
    if (!current_user_can(CAP_SUBSCRIBERS)) {
        wp_die(esc_html__('You are not allowed to manage subscribers.', 'omni-platform-newsletter'), '', ['response' => 403]);
    }
    if (empty($_POST['consent'])) {
        redirectBack(PAGE_SUBSCRIBERS, ['opn_error' => 'consent']);
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($_POST['addresses'] ?? ''));
    $added = 0;
    $skipped = 0;
    $rows = array_values(array_filter(array_map('trim', $lines)));
    $limit = (int) apply_filters('omni_platform_newsletter_import_limit', 5000);
    // Rows beyond the limit are not imported; report them as skipped.
    $skipped += max(0, count($rows) - $limit);
    foreach (array_slice($rows, 0, $limit) as $line) {
        $parts = array_map('trim', str_getcsv($line, ',', '"', ''));
        $email = sanitize_email($parts[0] ?? '');
        $name = sanitize_text_field($parts[1] ?? '');
        if (!is_email($email) && is_email(sanitize_email($parts[1] ?? ''))) {
            [$email, $name] = [sanitize_email($parts[1]), sanitize_text_field($parts[0] ?? '')];
        }
        addSubscriber($email, $name, (array) ($_POST['lists'] ?? [])) === true ? $added++ : $skipped++;
    }

    redirectBack(PAGE_SUBSCRIBERS, ['opn_notice' => 'imported', 'added' => $added, 'skipped' => $skipped]);
});

add_action('admin_post_opn_subscriber_action', function () {
    global $wpdb;

    $id = absint($_GET['subscriber'] ?? 0);
    $action = sanitize_key((string) ($_GET['do'] ?? ''));
    check_admin_referer('opn_subscriber_' . $action . '_' . $id);
    if (!current_user_can(CAP_SUBSCRIBERS) || !deliveryAvailable() || !$id) {
        wp_die(esc_html__('You are not allowed to manage subscribers.', 'omni-platform-newsletter'), '', ['response' => 403]);
    }

    if ($action === 'unsubscribe') {
        $wpdb->update(NEWSLETTER_USERS_TABLE, ['status' => 'U', 'updated' => time()], ['id' => $id]);
        redirectBack(PAGE_SUBSCRIBERS, ['opn_notice' => 'unsubscribed']);
    }
    if ($action === 'delete') {
        \Newsletter::instance()->delete_user($id);
        redirectBack(PAGE_SUBSCRIBERS, ['opn_notice' => 'deleted']);
    }

    redirectBack(PAGE_SUBSCRIBERS, []);
});

add_action('admin_post_opn_export_subscribers', function () {
    global $wpdb;

    check_admin_referer('opn_export_subscribers');
    if (!current_user_can(CAP_SUBSCRIBERS) || !deliveryAvailable()) {
        wp_die(esc_html__('You are not allowed to manage subscribers.', 'omni-platform-newsletter'), '', ['response' => 403]);
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'name', 'status', 'joined'], ',', '"', '');
    foreach ((array) $wpdb->get_results('select email, name, surname, status, created from ' . NEWSLETTER_USERS_TABLE . ' order by id') as $row) {
        // Neutralise spreadsheet formulas in exported text.
        $cells = array_map(fn($cell) => preg_match('/^[=+\-@]/', (string) $cell) ? "'" . $cell : (string) $cell, [
            $row->email,
            trim($row->name . ' ' . $row->surname),
            subscriberStatusLabel((string) $row->status),
            $row->created,
        ]);
        fputcsv($out, $cells, ',', '"', '');
    }
    fclose($out);
    exit;
});

function subscriberStatusLabel(string $status): string
{
    return match ($status) {
        'C' => __('Subscribed', 'omni-platform-newsletter'),
        'S' => __('Waiting for confirmation', 'omni-platform-newsletter'),
        'U' => __('Unsubscribed', 'omni-platform-newsletter'),
        'B', 'P' => __('Email bounced', 'omni-platform-newsletter'),
        default => __('Inactive', 'omni-platform-newsletter'),
    };
}

/** Styles for Omni's admin pages and editor panel. */
add_action('admin_enqueue_scripts', function () {
    $screen = get_current_screen();
    if ($screen && ($screen->post_type === 'newspack_nl_cpt' || str_contains((string) $screen->id, 'omni-newsletter'))) {
        wp_enqueue_style('omni-platform-newsletter-admin', plugins_url('assets/admin.css', __DIR__ . '/../omni-platform-newsletter.php'), [], VERSION);
    }
});

/** Editor panel: subject, website, print, test, and send. */
add_action('enqueue_block_editor_assets', function () {
    $post = get_post();
    if (!$post instanceof \WP_Post || $post->post_type !== 'newspack_nl_cpt') {
        return;
    }

    $deps = ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-url', 'wp-notices', 'wp-dom-ready'];
    wp_enqueue_script('omni-platform-newsletter-editor', plugins_url('assets/editor.js', __DIR__ . '/../omni-platform-newsletter.php'), $deps, VERSION, true);
    wp_enqueue_script('omni-platform-newsletter-colors', plugins_url('assets/colors.js', __DIR__ . '/../omni-platform-newsletter.php'), [...$deps, 'wp-blocks', 'omni-platform-newsletter-editor'], VERSION, true);
    wp_localize_script('omni-platform-newsletter-colors', 'omniNewsletterColors', colorEditorData($post));
    wp_enqueue_style('omni-platform-newsletter-admin', plugins_url('assets/admin.css', __DIR__ . '/../omni-platform-newsletter.php'), [], VERSION);

    if (!currentUserManagesEngines()) {
        // Newspack's publish dialog offers ad insertion and HTML for an external provider.
        // Neither applies here: keep the preview and the buttons, and say what Publish does.
        $explain = __('Publishing puts this newsletter on your website. It does not email anyone; use “Send to subscribers” in the newsletter panel for that.', 'omni-platform-newsletter');
        wp_add_inline_style(
            'omni-platform-newsletter-admin',
            '.opn-simple-editor .newspack-newsletters__modal__content > :not(.modal-buttons){display:none!important;}'
            . '.opn-simple-editor .newspack-newsletters__modal__content::before{content:' . wp_json_encode($explain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';display:block;margin:0 0 20px;font-size:15px;line-height:1.5;}'
        );
    }

    $lists = [];
    foreach (deliveryLists() as $id => $name) {
        $lists[] = ['id' => $id, 'name' => $name, 'count' => audienceCount([$id])];
    }

    wp_localize_script('omni-platform-newsletter-editor', 'omniNewsletter', [
        'restPath' => '/omni-platform-newsletter/v1/issues/',
        'canSend' => current_user_can(CAP_SEND),
        'engines' => currentUserManagesEngines(),
        'delivery' => deliveryAvailable(),
        'userEmail' => wp_get_current_user()->user_email,
        'lists' => $lists,
        'allCount' => audienceCount([]),
        'defaultLists' => defaultAudience(),
        'subscribersUrl' => pageUrl(PAGE_SUBSCRIBERS),
        'listUrl' => pageUrl(PAGE_LIST),
        'socialEnabled' => socialAvailable() && (bool) getSetting('share_social') && socialAccounts() !== [],
        'copied' => sanitize_key((string) ($_GET['opn_notice'] ?? '')) === 'copied',
    ]);
});

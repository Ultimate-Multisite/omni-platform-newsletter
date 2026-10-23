<?php

/**
 * REST endpoints used by the editor's "Publish & send" panel.
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const REST_NAMESPACE = 'omni-platform-newsletter/v1';

/** Resolve an editable issue or an error response. */
function restIssue(\WP_REST_Request $request): \WP_Post|\WP_Error
{
    $post = get_post((int) $request['id']);
    if (!$post instanceof \WP_Post || !in_array($post->post_type, supportedPostTypes(), true)) {
        return new \WP_Error('opn_not_found', __('Newsletter not found.', 'omni-platform-newsletter'), ['status' => 404]);
    }

    return $post;
}

function restCanEdit(\WP_REST_Request $request): bool
{
    return current_user_can(CAP_WRITE) && current_user_can('edit_post', (int) $request['id']);
}

function restCanSend(\WP_REST_Request $request): bool
{
    return restCanEdit($request) && current_user_can(CAP_SEND);
}

/** Everything the panel shows about an issue. */
function issueSummary(\WP_Post $post): array
{
    return [
        'delivery' => deliveryStatus($post),
        'deliveryLabel' => emailStateLabel(deliveryStatus($post)),
        'webUrl' => webUrl($post),
        'printUrl' => printPreviewUrl($post),
        'layoutValid' => count(sections($post)) === 4,
        'lastTest' => get_post_meta($post->ID, '_omni_platform_newsletter_last_test', true) ?: null,
    ];
}

/** Turn a delivery error into a REST error with a helpful status. */
function restError(\WP_Error $error): \WP_Error
{
    $status = in_array($error->get_error_code(), ['opn_email_missing', 'opn_email_stale', 'opn_layout', 'opn_address', 'opn_no_audience', 'opn_already_sent', 'opn_in_progress', 'opn_unknown_list'], true) ? 409 : 500;
    return new \WP_Error($error->get_error_code(), $error->get_error_message(), ['status' => $status]);
}

add_action('rest_api_init', function () {
    $id = '/issues/(?P<id>\d+)';

    register_rest_route(REST_NAMESPACE, $id, [
        'methods' => 'GET',
        'permission_callback' => __NAMESPACE__ . '\\restCanEdit',
        'callback' => function (\WP_REST_Request $request) {
            $post = restIssue($request);
            return is_wp_error($post) ? $post : rest_ensure_response(issueSummary($post));
        },
    ]);

    register_rest_route(REST_NAMESPACE, $id . '/test', [
        'methods' => 'POST',
        'permission_callback' => __NAMESPACE__ . '\\restCanSend',
        'args' => ['email' => ['type' => 'string', 'required' => true]],
        'callback' => function (\WP_REST_Request $request) {
            $post = restIssue($request);
            if (is_wp_error($post)) {
                return $post;
            }
            $result = sendTest($post, (string) $request['email']);
            return is_wp_error($result) ? restError($result) : rest_ensure_response(issueSummary(get_post($post->ID)));
        },
    ]);

    register_rest_route(REST_NAMESPACE, $id . '/send', [
        'methods' => 'POST',
        'permission_callback' => __NAMESPACE__ . '\\restCanSend',
        'args' => [
            'lists' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => []],
            'again' => ['type' => 'boolean', 'default' => false],
        ],
        'callback' => function (\WP_REST_Request $request) {
            $post = restIssue($request);
            if (is_wp_error($post)) {
                return $post;
            }
            $result = sendCampaign($post, (array) $request['lists'], (bool) $request['again']);
            return is_wp_error($result) ? restError($result) : rest_ensure_response(issueSummary(get_post($post->ID)));
        },
    ]);
});

/** Subject line is editable from the panel like any other field. */
add_action('init', function () {
    foreach (supportedPostTypes() as $type) {
        register_post_meta($type, SUBJECT_META, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => fn($allowed, $key, $postId) => current_user_can('edit_post', (int) $postId),
        ]);
    }
});

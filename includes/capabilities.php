<?php

/**
 * Newsletter capabilities.
 *
 * Site administrators receive every newsletter capability. Other roles must be
 * granted them explicitly (for example by a hosting platform's custom role).
 * The underlying engine plugins (Newspack Newsletters, Newsletter, Print My
 * Blog) are technical tools: only users who can manage the whole network see
 * their own admin screens unless a site opts in through a filter.
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const CAP_WRITE = 'opn_write_newsletters';
const CAP_SEND = 'opn_send_newsletters';
const CAP_SUBSCRIBERS = 'opn_manage_subscribers';
const CAP_SETTINGS = 'opn_manage_newsletter_settings';
const CAP_ENGINES = 'opn_manage_newsletter_engines';

/** @return list<string> Capabilities granted to site administrators. */
function adminCapabilities(): array
{
    return [CAP_WRITE, CAP_SEND, CAP_SUBSCRIBERS, CAP_SETTINGS];
}

/** Grant newsletter capabilities to administrators and engine access to network managers. */
function grantCapabilities(array $allcaps, array $caps, array $args, $user): array
{
    if (!array_intersect($caps, [...adminCapabilities(), CAP_ENGINES])) {
        return $allcaps;
    }

    if (!empty($allcaps['manage_options'])) {
        foreach (adminCapabilities() as $cap) {
            $allcaps[$cap] = true;
        }
    }

    // Single-site administrators manage everything; on multisite only network
    // managers see the engine plugins unless a site explicitly allows it.
    $engines = !is_multisite() ? !empty($allcaps['manage_options']) : !empty($allcaps['manage_network_options']);
    if ((bool) apply_filters('omni_platform_newsletter_user_manages_engines', $engines, $user)) {
        $allcaps[CAP_ENGINES] = true;
    }

    return $allcaps;
}

add_filter('user_has_cap', __NAMESPACE__ . '\\grantCapabilities', 10, 4);

/** Whether the current user should see the technical engine plugin screens. */
function currentUserManagesEngines(): bool
{
    return current_user_can(CAP_ENGINES);
}

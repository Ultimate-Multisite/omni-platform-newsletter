<?php

/**
 * Optional Bit Social integration: share newsletters when they are published.
 *
 * Bit Social shares posts through "auto post" rules that name post types and
 * accounts. Omni owns one rule for newsletters and keeps its account list in
 * step with the site's connected accounts, so the newsletter screens only need
 * a single on/off choice.
 */

declare(strict_types=1);

namespace OmniPlatform\Newsletter;

defined('ABSPATH') || exit;

const SOCIAL_RULE_ID = 'omni-platform-newsletter';

function socialAvailable(): bool
{
    return class_exists('\BitApps\Social\Config')
        && class_exists('\BitApps\Social\Model\Account')
        && class_exists('\BitApps\Social\HTTP\Services\AutoPost\AutoPostRuleService');
}

/** @return list<array{id:int, name:string, platform:string}> Active connected accounts. */
function socialAccounts(): array
{
    if (!socialAvailable()) {
        return [];
    }

    $accounts = \BitApps\Social\Model\Account::where('status', \BitApps\Social\Model\Account::ACCOUNT_STATUS['active'])->get();
    $result = [];
    foreach (is_array($accounts) ? $accounts : [] as $account) {
        $details = is_string($account->details ?? null) ? json_decode($account->details, true) : (array) ($account->details ?? []);
        $result[] = [
            'id' => (int) $account->id,
            'name' => (string) (($account->account_name ?? '') ?: ($details['name'] ?? '')),
            'platform' => (string) ($account->platform ?? ''),
        ];
    }

    return $result;
}

/** Admin URL where social accounts are connected, or '' without Bit Social. */
function socialAccountsUrl(): string
{
    return socialAvailable() ? admin_url('admin.php?page=bit-social#/accounts') : '';
}

/** Create, update, or disable the Omni newsletter auto-post rule. */
function syncSocialRule(): void
{
    if (!socialAvailable()) {
        return;
    }

    $enabled = (bool) getSetting('share_social');
    $settings = \BitApps\Social\Config::getOption('auto_post_settings');
    $settings = \BitApps\Social\HTTP\Services\AutoPost\AutoPostRuleService::normalizeSettings(is_array($settings) ? $settings : []);
    $rules = array_values(array_filter($settings['rules'] ?? [], fn($rule) => ($rule['id'] ?? '') !== SOCIAL_RULE_ID));

    $accountIds = array_map(fn($account) => $account['id'], socialAccounts());
    $rules[] = \BitApps\Social\HTTP\Services\AutoPost\AutoPostRuleService::normalizeRule([
        'id' => SOCIAL_RULE_ID,
        'name' => __('Newsletters', 'omni-platform-newsletter'),
        'status' => 'publish',
        'isEnabled' => $enabled && $accountIds !== [],
        'postType' => supportedPostTypes(),
        'accounts' => ['accountIds' => $accountIds, 'groupIds' => []],
        'postDelay' => ['every' => 0, 'unit' => ''],
        'templates' => [],
    ]);

    $settings['rules'] = $rules;
    if ($enabled && $accountIds !== []) {
        $settings['isEnabled'] = true;
    }

    \BitApps\Social\Config::updateOption('auto_post_settings', $settings);
}

/** Refresh the rule's accounts just before Bit Social reacts to a publish. */
add_action('transition_post_status', function ($new, $old, $post) {
    if ($new === 'publish' && $old !== 'publish' && $post instanceof \WP_Post && in_array($post->post_type, supportedPostTypes(), true)) {
        syncSocialRule();
    }
}, 5, 3);

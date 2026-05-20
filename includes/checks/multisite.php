<?php
/**
 * Multisite checks — network mode, site count, super admins.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_multisite(): array
{
    $out = [];

    if (!is_multisite()) {
        $out[] = wp7rc_result('multisite_mode', 'multisite', 'Multisite', 'info', 'single site', '', 'Not a multisite install — network checks skipped.');
        return $out;
    }

    $sites = get_sites(['count' => true]);
    $out[] = wp7rc_result('multisite_mode', 'multisite', 'Multisite', 'info', sprintf('%d sites', $sites), '', sprintf('Multisite network with %d site%s.', $sites, $sites === 1 ? '' : 's'));

    // Super admin count
    $super_admins = get_super_admins();
    $super_count  = is_array($super_admins) ? count($super_admins) : 0;
    if ($super_count <= 3) {
        $out[] = wp7rc_result('super_admins', 'multisite', 'Super admins', 'pass', sprintf('%d user%s', $super_count, $super_count === 1 ? '' : 's'), '≤ 3', sprintf('%d super admin%s — within reasonable bounds.', $super_count, $super_count === 1 ? '' : 's'));
    } else {
        $out[] = wp7rc_result(
            'super_admins',
            'multisite',
            'Super admins',
            'warn',
            sprintf('%d users', $super_count),
            '≤ 3',
            sprintf('%d super admins is more than typical. The Settings → Connectors UI (new in WP 7.0 AI Client) is restricted by capability — every super admin can configure provider credentials.', $super_count),
            'Audit the super admin list. Remove super-admin privileges from accounts that do not need network-wide control.'
        );
    }

    // Spam-flag behavior changed in 7.0
    $out[] = wp7rc_result(
        'spam_behavior',
        'multisite',
        'Network spam-flag behavior (7.0 change)',
        'info',
        'behavior changed in WP 7.0',
        '',
        'In WordPress 7.0, sites are no longer automatically marked as spam when their owner user is flagged as spam. If your network previously relied on this for content moderation, you will need to re-implement explicitly.',
        'If your moderation workflow depends on the old behavior, write a small plugin that hooks the user-spam transition and applies spam to owned sites manually.'
    );

    return $out;
}

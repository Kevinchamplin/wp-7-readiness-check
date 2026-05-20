<?php
/**
 * Security & compliance checks.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_security(): array
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $out      = [];
    $active   = (array) get_option('active_plugins', []);
    $all      = get_plugins();

    // File-edit lockdown
    if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT === true) {
        $out[] = wp7rc_result('disallow_file_edit', 'security', 'In-admin file editing', 'pass', 'DISALLOW_FILE_EDIT = true', 'true', 'In-admin file editing is disabled. Best practice.');
    } else {
        $out[] = wp7rc_result(
            'disallow_file_edit',
            'security',
            'In-admin file editing',
            'warn',
            'DISALLOW_FILE_EDIT not set',
            'true',
            'In-admin file editing is enabled. Any Administrator who phishes a session can drop arbitrary PHP via the theme/plugin editors.',
            "Add to wp-config.php: define('DISALLOW_FILE_EDIT', true);",
            '',
            'disallow_file_edit'
        );
    }

    // Admin user count
    $admin_count = 0;
    foreach (get_users(['role' => 'administrator', 'fields' => 'ID']) as $u) {
        $admin_count++;
    }
    if ($admin_count <= 3) {
        $out[] = wp7rc_result('admin_count', 'security', 'Administrator accounts', 'pass', sprintf('%d', $admin_count), '≤ 3', sprintf('%d administrator account%s — within reasonable bounds.', $admin_count, $admin_count === 1 ? '' : 's'));
    } elseif ($admin_count <= 6) {
        $out[] = wp7rc_result('admin_count', 'security', 'Administrator accounts', 'warn', sprintf('%d', $admin_count), '≤ 3', sprintf('%d administrators is more than typical. Each is a potential entry point to the new AI Connectors UI in WP 7.0.', $admin_count), 'Demote any Administrator who does not actively need full admin capabilities to Editor or a custom role.');
    } else {
        $out[] = wp7rc_result('admin_count', 'security', 'Administrator accounts', 'fail', sprintf('%d', $admin_count), '≤ 3', sprintf('%d administrators is a significant access-surface risk.', $admin_count), 'Audit the administrator list. Demote everyone who does not need full admin to Editor or below.');
    }

    // 2FA plugin detection (any of several well-known slugs)
    $tfa_slugs = [
        'two-factor/two-factor.php'            => 'Two-Factor',
        'wp-2fa/wp-2fa.php'                    => 'WP 2FA',
        'duo-wordpress/duo_wordpress.php'      => 'Duo Universal',
        'miniorange-2-factor-authentication/miniorange_2_factor_settings.php' => 'miniOrange 2FA',
        'google-authenticator/google-authenticator.php' => 'Google Authenticator',
    ];
    $tfa_detected = [];
    foreach ($tfa_slugs as $slug => $label) {
        if (in_array($slug, $active, true)) {
            $tfa_detected[] = $label;
        }
    }
    if ($tfa_detected !== []) {
        $out[] = wp7rc_result('two_factor', 'security', 'Two-factor authentication plugin', 'pass', implode(', ', $tfa_detected), 'a 2FA plugin active', 'Two-factor authentication plugin detected.');
    } else {
        $out[] = wp7rc_result('two_factor', 'security', 'Two-factor authentication plugin', 'warn', 'none detected', 'a 2FA plugin active', 'No well-known 2FA plugin is active. Administrator and Super Admin accounts should require 2FA, especially with the new AI Connectors surface in WP 7.0.', 'Install and enforce 2FA for all Administrator accounts. Recommended: the official "Two-Factor" plugin from the WordPress Plugin Directory.', '', 'install_two_factor');
    }

    // Backup plugin detection (any of several well-known slugs)
    $backup_slugs = [
        'updraftplus/updraftplus.php'                                   => 'UpdraftPlus',
        'backupbuddy/backupbuddy.php'                                   => 'BackupBuddy',
        'backwpup/backwpup.php'                                         => 'BackWPup',
        'all-in-one-wp-migration/all-in-one-wp-migration.php'           => 'All-in-One WP Migration',
        'duplicator/duplicator.php'                                     => 'Duplicator',
        'wp-staging/wp-staging.php'                                     => 'WP Staging',
        'wp-database-backup/wp-database-backup.php'                     => 'WP Database Backup',
    ];
    $backup_detected = [];
    foreach ($backup_slugs as $slug => $label) {
        if (in_array($slug, $active, true)) {
            $backup_detected[] = $label;
        }
    }
    if ($backup_detected !== []) {
        $out[] = wp7rc_result('backup_plugin', 'security', 'Backup plugin', 'pass', implode(', ', $backup_detected), 'a backup plugin active', 'Backup plugin detected — verify your latest backup is recent and a restore has been tested in the last 30 days.');
    } else {
        $out[] = wp7rc_result('backup_plugin', 'security', 'Backup plugin', 'warn', 'none detected', 'a backup plugin or host-level snapshot', 'No well-known backup plugin is active. You may rely on host-level snapshots — verify before the WP 7.0 upgrade.', 'Either confirm host snapshots are running and tested, or install a backup plugin and take a snapshot before the upgrade.');
    }

    // AI Connectors policy — only relevant on post-7.0 sites; informational on pre-7.0
    if (wp7rc_is_post_seven()) {
        $out[] = wp7rc_result(
            'ai_connectors_policy',
            'security',
            'AI Connectors policy (WP 7.0+)',
            'info',
            'review required',
            'documented policy',
            'WordPress 7.0 ships the AI Connectors UI at Settings → Connectors. Any administrator can configure an AI provider (OpenAI, Anthropic, Google). For regulated stacks, a written policy must define which providers are permitted.',
            'Draft a policy. If no AI provider is yet approved, restrict the Connectors UI via a capability filter until a Data Processing Addendum is signed.'
        );
    } else {
        $out[] = wp7rc_result(
            'ai_connectors_policy',
            'security',
            'AI Connectors policy (prepare for WP 7.0)',
            'info',
            'pre-upgrade — draft policy now',
            'documented policy ready by upgrade day',
            'WordPress 7.0 introduces an AI Connectors UI at Settings → Connectors. Once you upgrade, any administrator can configure an AI provider. Regulated stacks (SOC 2, HIPAA, FedRAMP-adjacent) should have a written policy ready BEFORE update day.',
            'Draft the policy now. Default: no providers permitted until a Data Processing Addendum is signed for each approved provider.'
        );
    }

    return $out;
}

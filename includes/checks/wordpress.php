<?php
/**
 * WordPress core checks — version, debug mode, auto-updates, HTTPS.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_wordpress(): array
{
    global $wp_version;
    $out = [];

    $version = (string) $wp_version;
    $is_seven = version_compare($version, '7.0', '>=');
    if ($is_seven) {
        $out[] = wp7rc_result('wp_version', 'wordpress', 'WordPress version', 'pass', $version, '6.9+ (pre-flight) or 7.0+ (post-flight)', 'You are running WordPress ' . $version . '. This audit operates in post-flight verification mode — checks below validate that the upgrade landed cleanly.');
    } elseif (version_compare($version, '6.9', '>=')) {
        $out[] = wp7rc_result('wp_version', 'wordpress', 'WordPress version', 'pass', $version, '6.9+ for clean upgrade', 'You are on the 6.9.x branch. This is the recommended starting point for upgrading to 7.0.');
    } else {
        $out[] = wp7rc_result('wp_version', 'wordpress', 'WordPress version', 'warn', $version, '6.9.x recommended before 7.0', 'WordPress ' . $version . ' is below the 6.9 branch. Major-version skips occasionally surface compatibility issues.', 'Update to WordPress 6.9.x first, then upgrade to 7.0.');
    }

    // WP_DEBUG
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        $out[] = wp7rc_result('wp_debug', 'wordpress', 'WP_DEBUG mode', 'warn', 'true', 'false (in production)', 'WP_DEBUG is enabled. Acceptable for staging; risky in production — debug output can leak into responses.', 'Set WP_DEBUG to false in wp-config.php for production environments.');
    } else {
        $out[] = wp7rc_result('wp_debug', 'wordpress', 'WP_DEBUG mode', 'pass', 'false', 'false (in production)', 'WP_DEBUG is off — appropriate for production.');
    }

    // Core auto-updates — risky to leave on during a major release window
    $core_updates = (string) get_site_option('auto_update_core_major', 'unset');
    if ($core_updates === 'enabled') {
        $out[] = wp7rc_result(
            'auto_updates_major', 'wordpress', 'Auto-updates (major)',
            'warn', 'enabled', 'disabled during 7.0 launch week',
            'Major-version auto-updates are enabled. WordPress could update itself to 7.0 unattended during the launch window.',
            'Disable major auto-updates until you have completed pre-flight: under Dashboard → Updates, click "Switch to automatic updates for maintenance and security releases only."',
            '',
            'disable_major_auto_updates'
        );
    } else {
        $out[] = wp7rc_result('auto_updates_major', 'wordpress', 'Auto-updates (major)', 'pass', 'manual or maintenance-only', 'disabled during 7.0 launch week', 'Major auto-updates are not active — upgrades will happen on your schedule.');
    }

    // HTTPS
    $site_url = (string) get_site_url();
    if (strpos($site_url, 'https://') === 0) {
        $out[] = wp7rc_result('https', 'wordpress', 'HTTPS site URL', 'pass', $site_url, 'https://...', 'Site URL uses HTTPS.');
    } else {
        $out[] = wp7rc_result('https', 'wordpress', 'HTTPS site URL', 'fail', $site_url, 'https://...', 'Site URL does not use HTTPS. This is a baseline issue independent of WordPress 7.0 but should be fixed before any upgrade work.', 'Issue a TLS cert (Let\'s Encrypt is free) and update the Site URL + Home URL under Settings → General.');
    }

    return $out;
}

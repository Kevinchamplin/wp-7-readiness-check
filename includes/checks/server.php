<?php
/**
 * Server & runtime checks — PHP version, opcache, extensions, memory.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_server(): array
{
    $out = [];

    // PHP version — 7.4 minimum for WP 7.0
    $php = PHP_VERSION;
    if (version_compare($php, '8.3', '>=')) {
        $status  = 'pass';
        $message = sprintf('PHP %s is in the recommended band for WordPress 7.0.', $php);
        $remedy  = '';
    } elseif (version_compare($php, '7.4', '>=')) {
        $status  = 'pass';
        $message = sprintf('PHP %s meets the 7.4 minimum for WordPress 7.0. PHP 8.3+ is recommended for performance.', $php);
        $remedy  = 'Plan a PHP upgrade to 8.3 or 8.4 in your next maintenance window.';
    } else {
        $status  = 'fail';
        $message = sprintf('PHP %s is below the WordPress 7.0 minimum of 7.4. Auto-updates will not advance this site to 7.0.', $php);
        $remedy  = 'Upgrade PHP to 7.4 or higher via your host control panel before the upgrade.';
    }
    $out[] = wp7rc_result('php_version', 'server', 'PHP version', $status, $php, '7.4 minimum (8.3+ recommended)', $message, $remedy);

    // OPcache
    $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    if (is_array($opcache) && !empty($opcache['opcache_enabled'])) {
        $mem   = isset($opcache['memory_usage']['used_memory'], $opcache['memory_usage']['free_memory'])
            ? (int) (($opcache['memory_usage']['used_memory'] + $opcache['memory_usage']['free_memory']) / 1048576)
            : 0;
        $out[] = wp7rc_result(
            'opcache',
            'server',
            'OPcache',
            'pass',
            sprintf('enabled (%d MB)', $mem),
            'enabled, 128 MB+ memory',
            'OPcache is enabled. After the upgrade, reset it (USR2 the FPM master or use opcache_reset() via the WP CLI).',
            ''
        );
    } else {
        $out[] = wp7rc_result(
            'opcache',
            'server',
            'OPcache',
            'warn',
            'disabled',
            'enabled',
            'OPcache is not enabled. Performance will be measurably slower; without OPcache, WordPress recompiles every .php file on every request.',
            'Enable opcache via php.ini (opcache.enable=1) and restart PHP-FPM.'
        );
    }

    // Memory limit
    $mem_label = (string) ini_get('memory_limit');
    $mem_limit = wp_convert_hr_to_bytes($mem_label);
    if ($mem_label === '-1') {
        $out[] = wp7rc_result('memory_limit', 'server', 'PHP memory_limit', 'pass', 'unlimited (-1)', '256M+', 'memory_limit is unlimited. WordPress will use as much memory as it needs.');
    } elseif ($mem_limit >= 256 * 1048576) {
        $out[] = wp7rc_result('memory_limit', 'server', 'PHP memory_limit', 'pass', $mem_label, '256M+', sprintf('memory_limit is %s.', $mem_label));
    } elseif ($mem_limit >= 128 * 1048576) {
        $out[] = wp7rc_result(
            'memory_limit', 'server', 'PHP memory_limit',
            'warn', $mem_label, '256M+',
            sprintf('memory_limit is %s. WordPress 7.0 + DataViews REST chatter may stress this on larger admin screens.', $mem_label),
            'Bump memory_limit to 256M via php.ini or wp-config.php (define WP_MEMORY_LIMIT).'
        );
    } else {
        $out[] = wp7rc_result(
            'memory_limit', 'server', 'PHP memory_limit',
            'fail', $mem_label, '256M+',
            sprintf('memory_limit of %s is below the recommended floor.', $mem_label),
            'Increase memory_limit to at least 256M before the upgrade.'
        );
    }

    // Max execution time
    $max_exec = (int) ini_get('max_execution_time');
    if ($max_exec === 0 || $max_exec >= 60) {
        $out[] = wp7rc_result('max_execution_time', 'server', 'PHP max_execution_time', 'pass', (string) $max_exec, '60+', sprintf('max_execution_time is %d seconds.', $max_exec));
    } else {
        $out[] = wp7rc_result(
            'max_execution_time', 'server', 'PHP max_execution_time',
            'warn', (string) $max_exec, '60+',
            sprintf('max_execution_time of %d seconds may abort the WordPress upgrade mid-run.', $max_exec),
            'Set max_execution_time to 60+ in php.ini for the upgrade window.'
        );
    }

    // Required PHP extensions
    $required = ['curl', 'mbstring', 'json', 'openssl'];
    $missing  = array_filter($required, static fn($ext) => !extension_loaded($ext));
    if ($missing === []) {
        $out[] = wp7rc_result('php_extensions', 'server', 'PHP extensions', 'pass', implode(', ', $required), 'curl, mbstring, json, openssl', 'All required PHP extensions are loaded.');
    } else {
        $out[] = wp7rc_result(
            'php_extensions', 'server', 'PHP extensions',
            'fail', 'missing: ' . implode(', ', $missing), 'curl, mbstring, json, openssl',
            'One or more required PHP extensions are missing.',
            sprintf('Install the following PHP extensions on the server: %s.', implode(', ', $missing))
        );
    }

    // WordPress cron (system or wp-cron)
    $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    if ($wp_cron_disabled) {
        $out[] = wp7rc_result(
            'cron', 'server', 'WordPress cron',
            'info', 'system cron (DISABLE_WP_CRON=true)', 'enabled',
            'WP-cron is disabled. You are likely running WP-cron from a system cron job — verify it is firing on schedule.',
            'Run: wp cron event list --due-now — to confirm no events are stuck.'
        );
    } else {
        $out[] = wp7rc_result('cron', 'server', 'WordPress cron', 'pass', 'wp-cron enabled', 'enabled', 'WP-cron is enabled via WordPress (default).');
    }

    return $out;
}

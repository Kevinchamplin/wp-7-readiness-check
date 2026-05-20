<?php
/**
 * AJAX handlers for autofix actions.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once WP7RC_DIR . 'includes/fixes.php';
require_once WP7RC_DIR . 'includes/snapshots.php';

/**
 * Apply a single fix by id.
 * POST: wp7rc_apply_fix
 * Body: fix_id, _ajax_nonce
 */
add_action('wp_ajax_wp7rc_apply_fix', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    check_ajax_referer('wp7rc_fix', 'nonce');

    $fix_id = isset($_POST['fix_id']) ? sanitize_key((string) $_POST['fix_id']) : '';
    if ($fix_id === '') {
        wp_send_json_error(['message' => 'Missing fix_id.'], 400);
    }

    $fix = wp7rc_fix_get($fix_id);
    if ($fix === null) {
        wp_send_json_error(['message' => sprintf('Unknown fix: %s', $fix_id)], 404);
    }

    $result = $fix['apply']();
    $log    = (array) get_option('wp7rc_fix_log', []);
    $log[]  = [
        'fix_id'   => $fix_id,
        'status'   => $result['status'] ?? 'unknown',
        'message'  => $result['message'] ?? '',
        'user_id'  => get_current_user_id(),
        'at'       => current_time('mysql'),
    ];
    if (count($log) > 100) {
        $log = array_slice($log, -100);
    }
    update_option('wp7rc_fix_log', $log, false);

    if (($result['status'] ?? 'error') === 'success') {
        wp_send_json_success($result);
    }
    wp_send_json_error($result, 500);
});

/**
 * Apply all available fixes in sequence.
 */
add_action('wp_ajax_wp7rc_apply_all_fixes', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    check_ajax_referer('wp7rc_fix', 'nonce');

    $registry = wp7rc_fix_registry();
    $results  = [];
    foreach ($registry as $id => $fix) {
        try {
            if (!($fix['check'])()) {
                $results[$id] = ['status' => 'skip', 'message' => 'No change needed.'];
                continue;
            }
            $results[$id] = ($fix['apply'])();
        } catch (\Throwable $e) {
            $results[$id] = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // Persist fix log for forensic visibility
    $log = (array) get_option('wp7rc_fix_log', []);
    foreach ($results as $fix_id => $r) {
        $log[] = [
            'fix_id'  => $fix_id,
            'status'  => $r['status'] ?? 'unknown',
            'message' => $r['message'] ?? '',
            'user_id' => get_current_user_id(),
            'at'      => current_time('mysql'),
            'context' => 'apply_all',
        ];
    }
    if (count($log) > 200) {
        $log = array_slice($log, -200);
    }
    update_option('wp7rc_fix_log', $log, false);

    wp_send_json_success(['results' => $results]);
});

/**
 * Restore a snapshot.
 * POST: wp7rc_restore_snapshot
 * Body: snapshot_id, _ajax_nonce
 */
add_action('wp_ajax_wp7rc_restore_snapshot', static function (): void {
    if (!current_user_can('update_plugins')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }
    check_ajax_referer('wp7rc_fix', 'nonce');

    $id = isset($_POST['snapshot_id']) ? sanitize_file_name((string) $_POST['snapshot_id']) : '';
    if ($id === '') {
        wp_send_json_error(['message' => 'Missing snapshot_id.'], 400);
    }
    $result = wp7rc_restore_snapshot($id);
    if (!empty($result['ok'])) {
        wp_send_json_success(['message' => $result['message']]);
    }
    wp_send_json_error(['message' => $result['message'] ?? 'Restore failed.'], 500);
});

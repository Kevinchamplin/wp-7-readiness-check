<?php
/**
 * WP 7 Readiness Check — uninstall cleanup.
 *
 * Runs when the user clicks "Delete" on the plugin row. Removes any options
 * we set. Currently the plugin is stateless (no options stored), so this is
 * a placeholder for future state.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// No persistent options to clean up in v1.0.0. Reserved for future use.

<?php
/**
 * Headless & API checks — WPGraphQL, REST routes, custom endpoints.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_headless(): array
{
    $out = [];

    // WPGraphQL detection
    $graphql_active = class_exists('WPGraphQL') || function_exists('graphql');
    if ($graphql_active) {
        $version = defined('WPGRAPHQL_VERSION') ? constant('WPGRAPHQL_VERSION') : 'unknown';
        $out[] = wp7rc_result('wpgraphql', 'headless', 'WPGraphQL detected', 'info', sprintf('active (v%s)', $version), '', 'WPGraphQL is active — this site is likely the data layer for a headless front end. Validate every consumer query against WordPress 7.0 RC before update day.', 'Trace each query the front end consumes. Test all of them on staging against 7.0 before production upgrade.');

        // WPGraphQL ACF detection
        if (class_exists('WPGraphQL_Acf')) {
            $out[] = wp7rc_result('wpgraphql_acf', 'headless', 'WPGraphQL ACF detected', 'info', 'active', '', 'WPGraphQL ACF is active. ACF Pro 6.8.1+ contained the WP 7.0 RC Image-field fix; verify your installed version.');
        }
    } else {
        $out[] = wp7rc_result('wpgraphql', 'headless', 'WPGraphQL', 'info', 'not detected', '', 'WPGraphQL not active — headless GraphQL checks skipped.');
    }

    // REST API base
    $rest_url = (string) rest_url();
    if ($rest_url !== '' && strpos($rest_url, 'http') === 0) {
        $out[] = wp7rc_result('rest_api', 'headless', 'REST API base', 'pass', $rest_url, 'reachable', 'REST API base URL resolves. WordPress 7.0 adds new endpoints around DataViews and the AI Client — your existing REST consumers should remain stable but test against RC.');
    }

    // Count custom REST routes (not core ones)
    $rest_server = rest_get_server();
    $custom_routes = 0;
    if ($rest_server instanceof WP_REST_Server) {
        foreach (array_keys($rest_server->get_routes()) as $route) {
            // Skip core routes
            if (preg_match('#^/(wp/v2|oembed/1\.0|wp/v2/types|wp-site-health/v1|wp-block-editor/v1)#', $route)) {
                continue;
            }
            if ($route === '/' || $route === '/batch/v1') {
                continue;
            }
            $custom_routes++;
        }
    }
    if ($custom_routes > 0) {
        $out[] = wp7rc_result(
            'custom_rest_routes',
            'headless',
            'Custom REST routes',
            'info',
            sprintf('%d custom namespace%s', $custom_routes, $custom_routes === 1 ? '' : 's'),
            '',
            sprintf('Detected %d custom REST route namespace%s outside core. These are surfaces a headless front end or partner integration may consume.', $custom_routes, $custom_routes === 1 ? '' : 's'),
            'Test every custom REST endpoint against WordPress 7.0 RC before upgrade.'
        );
    } else {
        $out[] = wp7rc_result('custom_rest_routes', 'headless', 'Custom REST routes', 'pass', '0 custom namespaces', '', 'No custom REST routes detected outside core — REST surface is core-only.');
    }

    return $out;
}

<?php
/**
 * Custom code static scan — grep active theme + mu-plugins + active plugins for WP 7.0 breaking patterns.
 *
 * @package WP7ReadinessCheck
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

function wp7rc_check_custom_code(): array
{
    $out = [];

    // Build list of dirs to scan: active theme, mu-plugins, active plugins
    $dirs = [];

    // Active theme + parent theme
    $theme = wp_get_theme();
    $dirs['active theme'] = $theme->get_stylesheet_directory();
    if ($theme->parent()) {
        $dirs['parent theme'] = $theme->get_template_directory();
    }

    // mu-plugins
    if (defined('WPMU_PLUGIN_DIR') && is_dir(WPMU_PLUGIN_DIR)) {
        $dirs['mu-plugins'] = WPMU_PLUGIN_DIR;
    }

    // Active plugins (excluding our own + well-known third-party we can't fix)
    $active = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $active = array_unique(array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', []))));
    }
    // Vendor plugins to skip — these are maintained by external teams who own
    // their own WP 7.0 compatibility work. Flagging their code as if it were
    // user-owned creates false positives and noise.
    $skip_vendor_slugs = [
        // Core ecosystem
        'akismet/', 'jetpack/',
        // Page builders
        'elementor/', 'elementor-pro/', 'beaver-builder-lite-version/', 'divi/', 'bricks/', 'breakdance/', 'oxygen/',
        // Commerce
        'woocommerce/', 'wpforms-lite/', 'wpforms/', 'easy-digital-downloads/', 'memberpress/',
        // SEO
        'yoast-seo/', 'wordpress-seo/', 'seo-by-rank-math/', 'wp-seopress/', 'add-wpgraphql-seo/',
        // ACF (Free + Pro)
        'advanced-custom-fields/', 'advanced-custom-fields-pro/', 'acf-extended/', 'acf-extended-pro/',
        // GraphQL / headless
        'wp-graphql/', 'wpgraphql/', 'wpgraphql-acf/', 'wp-graphql-acf/', 'wp-gatsby/', 'wp-graphql-jwt-authentication/',
        // Security / 2FA
        'two-factor/', 'wp-2fa/', 'wordfence/', 'sucuri-scanner/',
        // Cache
        'wp-rocket/', 'w3-total-cache/', 'litespeed-cache/', 'wp-super-cache/', 'autoptimize/',
        // Forms
        'gravityforms/', 'ninja-forms/', 'contact-form-7/',
        // Translation
        'wpml-multilingual-cms/', 'polylang/', 'translatepress-multilingual/',
        // Backup
        'updraftplus/', 'backupbuddy/', 'backwpup/', 'duplicator/', 'all-in-one-wp-migration/',
        // Our own
        'champlin-pre-flight-audit/',
    ];
    foreach ($active as $plugin_file) {
        $plugin_dir = dirname($plugin_file);
        if ($plugin_dir === '.' || $plugin_dir === '') {
            continue;
        }
        $skip = false;
        foreach ($skip_vendor_slugs as $vendor) {
            if (strpos($plugin_file, $vendor) === 0) {
                $skip = true; break;
            }
        }
        if ($skip) {
            continue;
        }
        $abs = WP_PLUGIN_DIR . '/' . $plugin_dir;
        if (is_dir($abs)) {
            $dirs['plugin: ' . $plugin_dir] = $abs;
        }
    }

    // Collect all PHP and JSON files (block.json relevant) across all scan dirs
    $all_files = [];
    foreach ($dirs as $label => $dir) {
        $all_files = array_merge($all_files, wp7rc_scan_files($dir, ['php', 'json', 'js'], 1500));
    }

    if ($all_files === []) {
        $out[] = wp7rc_result('custom_code_scan', 'custom-code', 'Custom code scan', 'info', '0 files', '', 'No custom theme, mu-plugin, or non-vendor plugin files were available to scan.');
        return $out;
    }

    $out[] = wp7rc_result('scan_summary', 'custom-code', 'Custom code scan scope', 'info', sprintf('%d files in %d directories', count($all_files), count($dirs)), '', sprintf('Scanned: %s', implode(', ', array_keys($dirs))));

    // Patterns to grep for, each with status + message
    $patterns = [
        [
            'id'      => 'wp_list_table',
            'label'   => 'WP_List_Table consumers',
            'pattern' => '/\bWP_List_Table\b/',
            'fail_status' => 'warn',
            'message' => 'Code references the classic WP_List_Table. DataViews replaces this in 7.0 on Posts, Pages, and Media. Custom admin tables built on WP_List_Table still work, but classic admin-column hooks are not fully integrated with DataViews.',
            'remedy'  => 'Audit each match. For Posts/Pages/Media customizations, plan a DataViews-extension migration when the public API lands.',
        ],
        [
            'id'      => 'manage_posts_columns',
            'label'   => 'manage_posts_columns / manage_posts_custom_column hooks',
            'pattern' => '/\bmanage_(posts|pages|media)_(columns|custom_column|sortable_columns)\b/',
            'fail_status' => 'warn',
            'message' => 'Code hooks the classic admin-column filters that are not yet fully integrated with DataViews in 7.0. Custom columns may not render or filter on Posts/Pages/Media list views.',
            'remedy'  => 'Validate each custom column on staging against WordPress 7.0. The DataViews extension API is forthcoming — revisit when it ships.',
        ],
        [
            'id'      => 'bulk_actions',
            'label'   => 'bulk_actions-* filters',
            'pattern' => '/\bbulk_actions-/',
            'fail_status' => 'warn',
            'message' => 'Code registers custom bulk actions on classic admin tables. Not fully integrated with DataViews in 7.0.',
            'remedy'  => 'Test each bulk action on staging against 7.0.',
        ],
        [
            'id'      => 'add_meta_box',
            'label'   => 'add_meta_box() (classic meta boxes)',
            'pattern' => '/\badd_meta_box\s*\(/',
            'fail_status' => 'info',
            'message' => 'Code uses classic add_meta_box(). Still supported in 7.0. Future implication: classic meta boxes disable real-time collaboration for that post type when RTC ships in a later release.',
            'remedy'  => 'For collaboration-bound CPTs, plan migration to register_post_meta() + custom block.',
        ],
        [
            'id'      => 'apiversion_2',
            'label'   => 'Block API version 2',
            'pattern' => '/"apiVersion"\s*:\s*2\b/',
            'fail_status' => 'warn',
            'message' => 'block.json declares apiVersion: 2. WordPress 7.0 enforces an iframed editor for apiVersion: 3+. Lower versions fall back but miss new editor improvements.',
            'remedy'  => 'Bump custom blocks to apiVersion: 3 and verify iframed-editor rendering. Replace direct window/document access with useRefEffect.',
        ],
        [
            'id'      => 'effect_interactivity',
            'label'   => 'Interactivity API effect()',
            'pattern' => '/@wordpress\/interactivity/',
            'fail_status' => 'info',
            'message' => 'Code imports @wordpress/interactivity. The effect() function was renamed to watch() in 7.0.',
            'remedy'  => 'Search your JS for `effect(` calls on Interactivity contexts and rename to `watch(`. Update navigation tracking to watch(() => state.url).',
        ],
        [
            'id'      => 'html5_script_support',
            'label'   => "add_theme_support('html5', ['script'])",
            'pattern' => "/add_theme_support\s*\(\s*[\"']html5[\"']\s*,\s*[^)]*[\"']script[\"']/",
            'fail_status' => 'fail',
            'message' => 'Theme/plugin uses the deprecated HTML5 script theme support. The script loader does not honor this in 7.0.',
            'remedy'  => 'Remove `script` from the html5 theme-support array.',
        ],
        [
            'id'      => 'groupByField',
            'label'   => 'DataViews groupByField (renamed)',
            'pattern' => '/\bgroupByField\b/',
            'fail_status' => 'fail',
            'message' => 'Code uses the DataViews groupByField property, which became a groupBy object in 7.0.',
            'remedy'  => 'Update to: groupBy: { field, direction, showLabel }.',
        ],
    ];

    $any_findings = false;
    foreach ($patterns as $p) {
        $matches = wp7rc_grep($all_files, $p['pattern'], 8);
        if ($matches === []) {
            continue;
        }
        $any_findings = true;
        $sample = array_slice(array_map(static fn($m) => sprintf('%s:%d', wp7rc_relpath($m['path']), $m['line']), $matches), 0, 6);
        $out[] = wp7rc_result(
            $p['id'],
            'custom-code',
            $p['label'],
            $p['fail_status'],
            sprintf('%d match%s', count($matches), count($matches) === 1 ? '' : 'es'),
            'no matches',
            $p['message'] . sprintf(' Matches: %s', implode('; ', $sample)),
            $p['remedy']
        );
    }

    if (!$any_findings) {
        $out[] = wp7rc_result('custom_code_clean', 'custom-code', 'Static-scan summary', 'pass', 'no breaking patterns', 'no breaking patterns', 'Static scan of custom theme + mu-plugins + active non-vendor plugins found no WordPress 7.0 breaking-change patterns.');
    }

    return $out;
}

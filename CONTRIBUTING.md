# Contributing to Champlin Pre-Flight Audit

Pull requests welcome. This file describes what we look for in a good PR.

## Quick start

```bash
git clone https://github.com/Kevinchamplin/champlin-pre-flight-audit.git
cd champlin-pre-flight-audit
# Symlink into a local WordPress install for testing
ln -s "$(pwd)" /path/to/wp-content/plugins/champlin-pre-flight-audit
```

Activate the plugin in your local WordPress, then navigate to **Tools → WP 7 Readiness**.

## What good PRs look like

### New checks

Add a new check function in the appropriate file under `includes/checks/`. Each check module exposes a function named `wp7rc_check_{category}()` that returns an array of result arrays.

Use the `wp7rc_result()` helper for normalized result structure:

```php
$out[] = wp7rc_result(
    'my_check_id',          // unique slug
    'category',             // server | database | wordpress | plugins | themes | custom-code | headless | multisite | security
    'Human-readable label',
    'pass',                 // pass | warn | fail | info | skip
    '8.3.31',               // current value observed
    '7.4 minimum',          // expected value
    'Verbose explanation of the finding.',
    'Concrete remediation hint.',
    '',                     // optional reference URL
    'optional_fix_id'       // optional — only if a corresponding fix module exists
);
```

### New autofixes

Add to the registry in `includes/fixes.php`. Every fix MUST:

1. Be **idempotent** — running it twice does nothing the second time.
2. Be **reversible** — the user has a clear path back.
3. Be **capability-gated** — verify the user has the right capability before acting.
4. Refuse to mutate user code or `wp-config.php` directly. Config-style changes go via mu-plugin files in `wp-content/mu-plugins/`.
5. Return a normalized `['status' => 'success'|'error', 'message' => string]` response.

### Bug fixes

Bug fixes are always welcome. Include:
- A short description of what was broken
- Steps to reproduce
- The fix

## Code style

- Follow WordPress PHP coding standards
- `declare(strict_types=1)` at the top of every PHP file
- Capability + nonce checks at every AJAX endpoint
- No `wp-config.php` edits, ever
- No telemetry, no external data calls except to wordpress.org for plugin install via core API
- All user-facing strings use the `champlin-pre-flight-audit` text domain (we'll add i18n in a future release)

## Testing

Manual testing is sufficient for now. We'll add PHPUnit + WordPress test infrastructure in a future release. When you submit a PR, describe what you tested:

- WordPress version
- PHP version
- Multisite / single-site
- Active plugin stack (for custom-code scan changes)

### Manual regression test: legacy-slug migration shim

The plugin was renamed from `wp-7-readiness-check` to `champlin-pre-flight-audit` on 2026-05-20 after WordPress.org's trademark policy rejected the "WP" prefix. `includes/migration.php` cleans up any pre-rename install on first load. Re-run this test whenever you touch the shim or anything in the boot path of the main plugin file.

**Setup.** Activate the current plugin in a test WordPress. Confirm no migration notice shows on any admin page. This is the no-legacy baseline: `is_dir('wp-content/plugins/wp-7-readiness-check/')` returns false and `active_plugins` does not reference the old basename.

**Simulate a legacy install** (single-site):

```bash
WP=/path/to/wp                    # adjust
PHP=/opt/plesk/php/8.3/bin/php    # adjust
WPCLI="$PHP /usr/local/bin/wp"

# Stub the legacy folder + main file. The header is enough to satisfy WP's
# plugin loader; this file does nothing on load.
mkdir -p $WP/wp-content/plugins/wp-7-readiness-check
cat > $WP/wp-content/plugins/wp-7-readiness-check/wp-7-readiness-check.php <<'STUB'
<?php
/**
 * Plugin Name: WP 7 Readiness Check (legacy stub)
 * Version: 1.0.6
 */
STUB

# Append the legacy basename to active_plugins
$WPCLI option get active_plugins --format=json --path=$WP \
  | jq '. + ["wp-7-readiness-check/wp-7-readiness-check.php"]' \
  | $WPCLI option update active_plugins --format=json --path=$WP

# Clear the migration suppression option in case it was set by a prior test
$WPCLI option delete wp7rc_legacy_slug_migrated --path=$WP
```

**Verify detection.** Reload wp-admin. The yellow "legacy install detected" notice should appear. Reload again, notice persists.

**Migrate.** Click **Migrate now**. Page reloads with the green flash ("legacy install cleaned up. Your audit history and settings are preserved.").

Confirm via CLI:

```bash
ls $WP/wp-content/plugins/wp-7-readiness-check 2>&1
# Expected: ls: cannot access ...: No such file or directory

$WPCLI option get active_plugins --path=$WP | grep wp-7-readiness-check
# Expected: (empty)

$WPCLI option get wp7rc_legacy_slug_migrated --path=$WP
# Expected: 1
```

Reload wp-admin: no notice should reappear on this site, ever.

**Dismiss path.** Re-run the setup. Click **Dismiss** instead of Migrate. Verify the option is set to `'1'`, the folder remains (we deliberately do not delete on dismiss), and the notice does not reappear.

**Dup-load case** (both plugins active at the same time). With the legacy stub still active, reload wp-admin. Instead of a `Cannot redeclare WP7RC_VERSION` fatal, you should see a red error notice asking the admin to deactivate the legacy copy. Both plugins coexist on disk; the renamed one stays inert until the legacy is deactivated.

**Multisite variant.** Add the legacy basename to `active_sitewide_plugins` (`$WPCLI site option ...`) instead of `active_plugins`. The shim's detection + migrate path should clean both lists.

### What good PRs against migration.php look like

- Preserve the gate order: capability → nonce → action. No exceptions.
- The folder delete uses `WP_Filesystem`, never `unlink/rmdir` directly. Some hosts require FTP credentials; honor that path by falling through silently (the option still suppresses the notice; the orphan folder without an active_plugins entry is harmless).
- No em dashes or hyphens as sentence connectors in user-facing copy. Brand voice rule from BRAND.md.

## Security issues

Don't open a public PR or issue for security vulnerabilities. See [SECURITY.md](SECURITY.md) for the responsible-disclosure process.

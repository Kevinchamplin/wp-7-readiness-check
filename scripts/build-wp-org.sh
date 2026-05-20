#!/usr/bin/env bash
# Build the WordPress.org distribution zip.
#
# Source repo is already named champlin-pre-flight-audit (slug, text domain,
# filenames) — no renaming required. This script just strips the PUC auto-update
# block + the bundled PUC vendor library (WP.org plugins must use WP.org's
# native update channel, not third-party updaters) and produces a clean zip
# ready to upload to WP.org.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
SLUG="champlin-pre-flight-audit"
OUT_DIR="$REPO_ROOT/dist"

mkdir -p "$OUT_DIR"
rm -f "$OUT_DIR/$SLUG.zip"

echo "==> Staging source in $BUILD_DIR/$SLUG"
mkdir -p "$BUILD_DIR/$SLUG"
rsync -a \
  --exclude '.git' \
  --exclude '.gitignore' \
  --exclude '.DS_Store' \
  --exclude '.editorconfig' \
  --exclude '.github' \
  --exclude 'scripts/' \
  --exclude 'dist/' \
  --exclude 'vendor/plugin-update-checker/' \
  "$REPO_ROOT/" "$BUILD_DIR/$SLUG/"

echo "==> Stripping PUC block from main file"
python3 - <<PYEOF
import re
p = "$BUILD_DIR/$SLUG/$SLUG.php"
src = open(p).read()
# Remove the entire PUC block (docblock + if (is_admin || DOING_CRON || WP_CLI) { … })
pattern = re.compile(
    r"/\*\*\s*\n \* Auto-update from GitHub releases\..*?\n\}\s*\n",
    re.DOTALL,
)
new = pattern.sub('', src, count=1)
# Also drop the WP7RC_GITHUB_URL define so the WP.org variant has no GitHub URL constant.
new = re.sub(r"^define\('WP7RC_GITHUB_URL'.*\n", "", new, flags=re.MULTILINE)
open(p, "w").write(new)
print("  PUC block + WP7RC_GITHUB_URL removed")
PYEOF

echo "==> Annotating readme.txt with the distribution note"
python3 - <<PYEOF
p = "$BUILD_DIR/$SLUG/readme.txt"
src = open(p).read()
note = (
    "= About this distribution =\n\n"
    "This is the WordPress.org distribution of the plugin. A self-hosted variant is available at "
    "https://github.com/Kevinchamplin/champlin-pre-flight-audit for users who prefer to install directly from GitHub. "
    "The audit logic, autofixes, and snapshot system are identical; only the update mechanism differs.\n\n"
)
if "= About this distribution =" not in src and "= What it checks =" in src:
    src = src.replace("= What it checks =", note + "= What it checks =")
    open(p, "w").write(src)
    print("  Distribution note injected")
else:
    print("  Distribution note already present (or readme structure unexpected)")
PYEOF

echo "==> Verifying no PUC refs remain"
if grep -rl "plugin-update-checker\|PucFactory\|YahnisElsts" "$BUILD_DIR/$SLUG" 2>/dev/null; then
    echo "FAIL: PUC references still present"
    exit 1
fi
echo "  Clean"

echo "==> PHP syntax check"
ERR=0
while IFS= read -r f; do
  if ! php -l "$f" > /dev/null 2>&1; then
    php -l "$f"
    ERR=1
  fi
done < <(find "$BUILD_DIR/$SLUG" -name "*.php")
[ $ERR -eq 0 ] && echo "  All clean" || exit 1

echo "==> Zipping"
cd "$BUILD_DIR"
COPYFILE_DISABLE=1 zip -qr "$OUT_DIR/$SLUG.zip" "$SLUG"

echo ""
echo "=== BUILD COMPLETE ==="
ls -lh "$OUT_DIR/$SLUG.zip"
echo ""
unzip -l "$OUT_DIR/$SLUG.zip" | head -12
echo ""
echo "Plugin header preview:"
unzip -p "$OUT_DIR/$SLUG.zip" "$SLUG/$SLUG.php" | head -16

rm -rf "$BUILD_DIR"

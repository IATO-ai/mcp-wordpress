#!/bin/zsh
# 1.21.0 must keep whatever 1.20.0's markup rule kept. Writes 1.20.0's sanitizer, with
# its classes renamed, into a folder of its own under tests/wp-env/.compat-1.20.0/
# (gitignored; every local site mounts this checkout, so runs on several sites at once
# each need their own), runs sanitizer-compat-live.php on real WordPress with that
# folder's name, and removes the copy again.
#   tests/wp-env/sanitizer-compat-live.sh
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
RUN="run-$$-$RANDOM"
OLD="$REPO/tests/wp-env/.compat-1.20.0/$RUN"
mkdir -p "$OLD"
for f in class-inline-markup.php class-elementor-sanitizer.php; do
	git -C "$REPO" show "v1.20.0:includes/$f" | perl -pe 's/\bIATO_MCP_Inline_Markup\b/IATO_MCP_Inline_Markup_120/g; s/\bIATO_MCP_Elementor_Sanitizer\b/IATO_MCP_Elementor_Sanitizer_120/g; s#__DIR__ \. \x27/class-html-token-scanner\.php\x27#IATO_MCP_DIR . \x27includes/class-html-token-scanner.php\x27#g' > "$OLD/$f"
done
cd "${WPENV_DIR:-$REPO}"
npx @wordpress/env run "${WPENV_SVC:-cli}" wp eval-file wp-content/plugins/mcp-wordpress/tests/wp-env/sanitizer-compat-live.php "$RUN" --user=admin 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔'
rc=${pipestatus[1]}
rm -f "$OLD"/*.php && rmdir "$OLD"
rmdir "$REPO/tests/wp-env/.compat-1.20.0" 2>/dev/null
exit $rc

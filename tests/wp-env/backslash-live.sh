#!/bin/zsh
# Live checks that values containing backslashes are stored as sent by every
# MCP write path, and restored as they were by rollback (1.13.0). WordPress'
# write functions (wp_insert_post, wp_update_post, update_post_meta,
# wp_insert_term, wp_update_term, wp_update_nav_menu_item,
# wp_insert_attachment) expect slashed input and unslash it.
# Yoast SEO is installed for the run when it is not already installed, and
# removed again at the end. Then runs tests/wp-env/backslash-live.py.
#   tests/wp-env/backslash-live.sh [base-url]
#   tests/wp-env/backslash-live.sh --cleanup
set -u
cd "$(dirname "$0")/../.."
# W's exit status is grep's, not wp-cli's: test installed plugins by listing them.
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

cleanup() {
	for id in $(W wp db query "SELECT ID FROM wp_posts WHERE post_title LIKE 'backslash-live%' OR post_title LIKE 'a_b C:_Users%'" --skip-column-names); do W wp post delete "$id" --force >/dev/null; done
	for tax in category post_tag; do
		for t in $(W wp db query "SELECT t.term_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = '$tax' AND (t.name LIKE 'backslash-live%' OR t.slug LIKE 'backslash-live%')" --skip-column-names); do W wp term delete "$tax" "$t" >/dev/null; done
	done
	W wp menu delete "Backslash Live Menu" >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

BASE="${1:-http://localhost:8888}"
YOAST_INSTALLED_HERE=0
if ! W wp plugin list --field=name | grep -qx wordpress-seo; then
	W wp plugin install wordpress-seo --activate >/dev/null && YOAST_INSTALLED_HERE=1
else
	W wp plugin activate wordpress-seo >/dev/null
fi
export BASE
export KEY=$(W wp option get iato_mcp_key)
export POST=$(W wp post create --post_type=post --post_status=publish --post_author=1 --post_title="backslash-live post" --porcelain)
export PAGE=$(W wp post create --post_type=page --post_status=publish --post_author=1 --post_title="backslash-live page" --porcelain)
export MENU=$(W wp menu create "Backslash Live Menu" --porcelain)
python3 -I tests/wp-env/backslash-live.py
rc=$?  # not "status": read-only in zsh
cleanup
[ "$YOAST_INSTALLED_HERE" = "1" ] && W wp plugin uninstall wordpress-seo --deactivate >/dev/null && echo "Yoast SEO removed again"
exit $rc

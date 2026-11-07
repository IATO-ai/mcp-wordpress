#!/bin/zsh
# Live checks for 1.17.0: create_menu, assign_menu_location and get_menus' locations;
# create_menu_item on a menu made in the same session; every rollback, with its two
# safeguards (a menu with items, a theme switch); resolve_url with site-wide templates
# and theme_locations, with real Elementor Pro when the site has it; the upgrade
# backfill; the server instructions. Installs a test-only mu-plugin
# (tests/wp-env/mu-menu-locations-test.php) that registers two locations on any theme.
#   tests/wp-env/menu-tools-live.sh [base-url]
#   tests/wp-env/menu-tools-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MU=wp-content/mu-plugins/iato-mcp-test-menus.php
SRC=wp-content/plugins/mcp-wordpress/tests/wp-env
# Per site, so the sites can run at the same time.
export THEME_MARKER="/tmp/iato-menu-live-theme-$WPENV_SVC-$(basename "${WPENV_DIR:-$REPO}")"

cleanup() {
	if [ -f "$THEME_MARKER" ]; then
		W wp theme activate "$(cat "$THEME_MARKER")" >/dev/null
		rm -f "$THEME_MARKER"
	fi
	for id in $(W wp menu list --fields=term_id,name --format=csv | grep 'menu-tools-live' | cut -d, -f1); do W wp menu delete "$id" >/dev/null; done
	for id in $(W wp post list --post_type=page,post,elementor_library --post_status=any,trash --s=menu-tools-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	W wp eval '$l=get_nav_menu_locations();unset($l["iato-live-a"],$l["iato-live-b"]);set_theme_mod("nav_menu_locations",$l);if(class_exists("\ElementorPro\Modules\ThemeBuilder\Module")){\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();}' >/dev/null
	W wp user application-password list author --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-menus-*) W wp user application-password delete author "$uuid" >/dev/null ;; esac; done
	W rm -f "$MU"
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W mkdir -p wp-content/mu-plugins
W cp "$SRC/mu-menu-locations-test.php" "$MU"
export KEY=$(W wp option get iato_mcp_key)
W wp user get author --field=ID >/dev/null 2>&1 || W wp user create author author@example.test --role=author --user_pass=author-pass >/dev/null
export PW_AUTHOR=$(W wp user application-password create author live-menus-$(date +%s) --porcelain)
python3 -I "$REPO/tests/wp-env/menu-tools-live.py"
rc=$?
cleanup
exit $rc

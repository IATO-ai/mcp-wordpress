#!/bin/zsh
# Live checks for 1.21.0's page assets: set_page_css, set_page_fonts and
# create_preview_link (and set_page_script, when that is part of the build), with real
# WordPress, real Elementor and every kind of credential, the "Page assets" tab and the
# Plugins screen through a logged-in wp-admin session, and preview links requested
# without one.
#   tests/wp-env/page-assets-live.sh [base-url] [--plugins]
#   tests/wp-env/page-assets-live.sh --cleanup
# --plugins also checks a preview's response with LiteSpeed Cache installed for the run
# (removed after; refuses to start if it is already installed).
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
# The wp-admin password of the local user `admin` comes from ADMIN_PASS (wp-env's
# default, "password", when unset). KEEP=1 leaves pages for a browser check and prints
# their URLs (BROWSER_*); run --cleanup afterwards.
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MU=wp-content/mu-plugins/iato-mcp-test-page-assets-probe.php
SPY=wp-content/mu-plugins/iato-mcp-test-page-assets-spy.php
SRC=wp-content/plugins/mcp-wordpress/tests/wp-env

cleanup() {
	W wp eval '$b=get_option("iato_test_page_assets_backup");if(is_array($b)){foreach(["iato_mcp_tools","show_on_front","page_on_front","page_for_posts","iato_mcp_key_owner","elementor_local_google_fonts","_elementor_local_google_fonts","iato_mcp_page_scripts_enabled"] as $k){if(null===$b[$k]){delete_option($k);}else{update_option($k,$b[$k]);}}delete_option("iato_test_page_assets_backup");}' >/dev/null
	for id in $(W wp post list --post_type=post,page --post_status=any,trash --s=page-assets-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	if [ "$(W wp option get iato_test_page_assets_installed_litespeed-cache)" = "1" ]; then
		W wp plugin deactivate litespeed-cache >/dev/null
		W wp plugin uninstall litespeed-cache >/dev/null
		W wp option delete iato_test_page_assets_installed_litespeed-cache >/dev/null
	fi
	for who in admin assetseditor assetsauthor assetscontrib; do
		W wp user application-password list "$who" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-assets-*) W wp user application-password delete "$who" "$uuid" >/dev/null ;; esac; done
	done
	for who in assetseditor assetsauthor assetscontrib; do W wp user delete "$who" --yes >/dev/null 2>&1; done
	W rm -f "$MU" "$SPY"
	W wp option delete iato_mcp_test_purges >/dev/null
	W wp plugin activate mcp-wordpress >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="http://localhost:8888"
export PLUGINS=0
for arg in "$@"; do
	case "$arg" in
		--plugins) PLUGINS=1 ;;
		http*) BASE="$arg" ;;
	esac
done
if [ "$PLUGINS" = "1" ] && W wp plugin is-installed litespeed-cache; then
	echo "litespeed-cache is already installed on this site; --plugins installs and removes it, so it will not run here."
	exit 2
fi

W wp plugin activate elementor >/dev/null
W mkdir -p wp-content/mu-plugins
W cp "$SRC/mu-page-assets-probe.php" "$MU"
W cp "$SRC/mu-cache-spy.php" "$SPY"
W wp eval 'add_option("iato_test_page_assets_backup",["iato_mcp_tools"=>get_option("iato_mcp_tools",null),"show_on_front"=>get_option("show_on_front",null),"page_on_front"=>get_option("page_on_front",null),"page_for_posts"=>get_option("page_for_posts",null),"iato_mcp_key_owner"=>get_option("iato_mcp_key_owner",null),"elementor_local_google_fonts"=>get_option("elementor_local_google_fonts",null),"_elementor_local_google_fonts"=>get_option("_elementor_local_google_fonts",null),"iato_mcp_page_scripts_enabled"=>get_option("iato_mcp_page_scripts_enabled",null)],"","no");' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
# Writes need a WordPress user behind the key, so it is bound to admin for the run (some
# sites keep it unbound); cleanup puts the owner back from the backup above.
export ADMIN_ID=$(W wp user get admin --field=ID)
W wp option update iato_mcp_key_owner "$ADMIN_ID" >/dev/null
STAMP=$(date +%s)
W wp user get assetseditor --field=ID >/dev/null 2>&1 || W wp user create assetseditor assetseditor@example.test --role=editor --user_pass=assets-pass >/dev/null
W wp user get assetsauthor --field=ID >/dev/null 2>&1 || W wp user create assetsauthor assetsauthor@example.test --role=author --user_pass=assets-pass >/dev/null
W wp user get assetscontrib --field=ID >/dev/null 2>&1 || W wp user create assetscontrib assetscontrib@example.test --role=contributor --user_pass=assets-pass >/dev/null
export PW_ADMIN=$(W wp user application-password create admin live-assets-$STAMP --porcelain)
export PW_EDITOR=$(W wp user application-password create assetseditor live-assets-$STAMP --porcelain)
export PW_AUTHOR=$(W wp user application-password create assetsauthor live-assets-$STAMP --porcelain)
export PW_CONTRIB=$(W wp user application-password create assetscontrib live-assets-$STAMP --porcelain)
export ADMIN_PASS="${ADMIN_PASS:-password}"
python3 -I "$REPO/tests/wp-env/page-assets-live.py"
rc=$?
# KEEP=1 leaves the pages for a browser check (it prints their URLs); run --cleanup after.
[ "${KEEP:-0}" = "1" ] || cleanup
exit $rc

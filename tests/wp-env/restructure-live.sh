#!/bin/zsh
# Live checks for 1.20.0: parent and menu_order on create_post and update_post, the
# permalink settings in update_site_settings, the redirects both create and their
# rollback, and update_post's slug check. Old URLs are requested without following
# redirects; the stored rewrite rules are read after the change. A test-only mu-plugin
# adds a rule, an endpoint, a custom post type and a taxonomy that must survive.
#   tests/wp-env/restructure-live.sh [base-url] [--plugins]
#   tests/wp-env/restructure-live.sh --cleanup
# --plugins also runs the redirects through Redirection and Safe Redirect Manager, and the
# cache report with LiteSpeed Cache, installing each for its section and removing it
# after. It refuses to start if one of them is already installed.
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
# The Pro site adds the /blog/ page with an Elementor Posts widget:
#   WPENV_DIR=../iato-sprint/wp-env-pro tests/wp-env/restructure-live.sh http://localhost:8892
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

MU=wp-content/mu-plugins/iato-mcp-test-rewrite-probe.php
SRC=wp-content/plugins/mcp-wordpress/tests/wp-env

cleanup() {
	W wp eval '$b=get_option("iato_test_restructure_backup");if(is_array($b)){foreach(["permalink_structure","category_base","tag_base","show_on_front","page_on_front","page_for_posts","iato_mcp_key_owner"] as $k){update_option($k,$b[$k]);}update_option("iato_mcp_redirects",$b["redirects"],false);delete_option("iato_test_restructure_backup");}' >/dev/null
	for id in $(W wp post list --post_type=post,page,iato_probe_work,nav_menu_item --post_status=any,trash --s=restructure-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for id in $(W wp post list --post_type=post,page,nav_menu_item --post_status=any,trash --name=restructure-live-menu-name --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for id in $(W wp term list category --search=restructure-live --field=term_id); do W wp term delete category "$id" >/dev/null; done
	for plugin in redirection safe-redirect-manager litespeed-cache; do
		if [ "$(W wp option get "iato_test_restructure_installed_$plugin")" = "1" ]; then
			W wp plugin deactivate "$plugin" >/dev/null
			W wp plugin uninstall "$plugin" >/dev/null
			W wp option delete "iato_test_restructure_installed_$plugin" >/dev/null
		fi
	done
	W rm -f "$MU"
	for who in admin editor; do
		W wp user application-password list "$who" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-restructure-*) W wp user application-password delete "$who" "$uuid" >/dev/null ;; esac; done
	done
	W wp rewrite flush >/dev/null
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
if [ "$PLUGINS" = "1" ]; then
	for plugin in redirection safe-redirect-manager litespeed-cache; do
		if W wp plugin is-installed "$plugin"; then
			echo "$plugin is already installed on this site; --plugins installs and removes it, so it will not run here."
			exit 2
		fi
	done
fi

W wp eval 'add_option("iato_test_restructure_backup",["permalink_structure"=>get_option("permalink_structure"),"category_base"=>get_option("category_base"),"tag_base"=>get_option("tag_base"),"show_on_front"=>get_option("show_on_front"),"page_on_front"=>get_option("page_on_front"),"page_for_posts"=>get_option("page_for_posts"),"iato_mcp_key_owner"=>get_option("iato_mcp_key_owner"),"redirects"=>get_option("iato_mcp_redirects",[])],"","no");' >/dev/null
W mkdir -p wp-content/mu-plugins
W cp "$SRC/mu-rewrite-probe.php" "$MU"
W wp rewrite flush >/dev/null
export KEY=$(W wp option get iato_mcp_key)
W wp user get editor --field=ID >/dev/null 2>&1 || W wp user create editor editor@example.test --role=editor --user_pass=editor-pass >/dev/null
STAMP=$(date +%s)
export PW_ADMIN=$(W wp user application-password create admin live-restructure-$STAMP --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-restructure-$STAMP --porcelain)
python3 -I "$REPO/tests/wp-env/restructure-live.py"
rc=$?
cleanup
exit $rc

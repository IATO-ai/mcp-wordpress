#!/bin/zsh
# Live checks for 1.15.0: page cache purging, the `cache` object, per-post Elementor
# CSS and update_site_settings, on a real site with real Elementor and, with
# --plugins, the real cache plugins installed one at a time from WordPress.org.
#   tests/wp-env/cache-purge-live.sh [dev|tests|legacy] [--plugins]
#   tests/wp-env/cache-purge-live.sh [dev|tests|legacy] --cleanup
# dev = :8888 (Elementor 4.3.4), tests = :8889 (4.3.5), legacy = :8890 (3.35.9,
# from ../iato-sprint/wp-env-legacy, which must be started).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO"
TARGET="${1:-dev}"
case "$TARGET" in
	dev)    SVC=cli;       BASE=http://localhost:8888 ;;
	tests)  SVC=tests-cli; BASE=http://localhost:8889 ;;
	legacy) SVC=cli;       BASE=http://localhost:8890; cd "$REPO/../iato-sprint/wp-env-legacy" ;;
	*) echo "usage: $0 [dev|tests|legacy] [--plugins|--cleanup]"; exit 2 ;;
esac
W() { npx @wordpress/env run "$SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
MU=wp-content/mu-plugins/iato-mcp-test-cache.php
SRC=wp-content/plugins/mcp-wordpress/tests/wp-env
OPTS=(iato_mcp_test_purges iato_mcp_test_global_clears iato_mcp_test_purge_filter_off iato_mcp_test_head_requests iato_mcp_test_edge_headers)
CACHE_PLUGINS=(litespeed-cache wp-super-cache w3-total-cache wp-fastest-cache sg-cachepress)

if [ "${2:-}" = "--cleanup" ]; then
	set +e
	for slug in $CACHE_PLUGINS; do
		W wp plugin list --field=name | grep -qx "$slug" && W wp plugin uninstall "$slug" --deactivate >/dev/null
	done
	W rm -f "$MU" wp-content/advanced-cache.php wp-content/object-cache.php wp-content/db.php wp-content/wp-cache-config.php
	W rm -rf wp-content/cache wp-content/w3tc-config wp-content/wp-fastest-cache
	W wp config delete WP_CACHE >/dev/null
	W wp config delete WPCACHEHOME >/dev/null
	W wp option delete $OPTS iato_mcp_cache_edge >/dev/null
	W wp transient delete iato_mcp_cache_edge >/dev/null
	W wp option delete iato_mcp_cache_purge_enabled >/dev/null
	for id in $(W wp post list --post_type=page,post,elementor_library --post_status=any,trash --s=cache-live --format=ids | tr ' ' '\n') $(W wp post list --post_type=page --post_status=any --name=iato-cache-fixture-a --format=ids) $(W wp post list --post_type=page --post_status=any --name=iato-cache-fixture-b --format=ids); do W wp post delete "$id" --force >/dev/null; done
	W wp user application-password list admin --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-ap.csv
	while IFS=, read -r uuid name; do case "$name" in live-cache-*) W wp user application-password delete admin "$uuid" >/dev/null && echo "deleted admin $name";; esac; done < /tmp/iato-ap.csv
	W wp user get cacheeditor --field=ID >/dev/null 2>&1 && W wp user delete cacheeditor --yes >/dev/null
	rm -f /tmp/iato-ap.csv
	echo "cleanup done"
	exit 0
fi

W mkdir -p wp-content/mu-plugins
W cp "$SRC/mu-cache-spy.php" "$MU"
W wp plugin activate elementor >/dev/null
W wp option delete $OPTS iato_mcp_cache_purge_enabled >/dev/null
W wp transient delete iato_mcp_cache_edge >/dev/null
stamp=$(date +%s)
export BASE SVC PLUGINS="${2:-}"
export KEY=$(W wp option get iato_mcp_key)
export PW_ADMIN=$(W wp user application-password create admin live-cache-$stamp --porcelain)
W wp user get cacheeditor --field=ID >/dev/null 2>&1 || W wp user create cacheeditor cacheeditor@example.test --role=editor --user_pass=cacheeditor-pass >/dev/null
export PW_EDITOR=$(W wp user application-password create cacheeditor live-cache-$stamp --porcelain)
export FIXTURE_A=$(W wp eval-file "$SRC/create-sanitize-fixture.php" cache-a --user=admin | tail -1)
export FIXTURE_B=$(W wp eval-file "$SRC/create-sanitize-fixture.php" cache-b --user=admin | tail -1)
export LIBRARY=$(W wp post create --post_type=elementor_library --post_status=publish --post_title="cache-live saved section $stamp" --meta_input='{"_elementor_template_type":"section","_elementor_edit_mode":"builder"}' --porcelain)
export KIT=$(W wp option get elementor_active_kit)
export PLAIN=$(W wp post create --post_type=post --post_status=publish --post_author=1 --post_title="cache-live plain post $stamp" --porcelain)
export PAGE_ONE=$(W wp post create --post_type=page --post_status=publish --post_author=1 --post_title="cache-live home $stamp" --porcelain)
export PAGE_TWO=$(W wp post create --post_type=page --post_status=publish --post_author=1 --post_title="cache-live blog $stamp" --porcelain)
export PAGE_DRAFT=$(W wp post create --post_type=page --post_status=draft --post_author=1 --post_title="cache-live draft $stamp" --porcelain)
export MENU=$(W wp menu create "cache-live menu $stamp" --porcelain)
export ELEMENTOR_VERSION=$(W wp plugin get elementor --field=version)
echo "Elementor $ELEMENTOR_VERSION on $BASE"
python3 -I "$REPO/tests/wp-env/cache-purge-live.py"

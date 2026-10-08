#!/bin/zsh
# Provisions users, Application Passwords and fixtures on the wp-env dev site,
# then runs tests/wp-env/permissions-live.py against it.
#
#   tests/wp-env/permissions-live.sh [base-url]
#   tests/wp-env/permissions-live.sh --cleanup   # remove everything a run created
set -eu
cd "$(dirname "$0")/../.."
# Exit status is grep's (non-zero when wp-cli printed nothing), which the
# create-if-missing lines below rely on.
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

if [ "${1:-}" = "--cleanup" ]; then
	set +e
	# --user=admin: an anonymous search query hides password-protected posts.
	for id in $(W wp post list --user=admin --post_type=post,page,attachment,elementor_library,revision --post_status=any --s=secpatch --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force; done
	for id in $(W wp post list --post_type=attachment --post_status=inherit --s=wordpress-logo --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force; done
	W wp term update category 1 --description='' >/dev/null
	for u in contributor author; do W wp user get "$u" --field=ID >/dev/null 2>&1 && W wp user delete "$u" --yes; done
	for u in admin editor; do
		W wp user application-password list "$u" --fields=uuid,name --format=csv | tail -n +2 > /tmp/iato-ap.csv
		while IFS=, read -r uuid name; do case "$name" in live-*) W wp user application-password delete "$u" "$uuid" >/dev/null && echo "deleted $u $name";; esac; done < /tmp/iato-ap.csv
	done
	rm -f /tmp/iato-ap.csv
	W wp eval '$c = get_option("iato_mcp_oauth_clients", []); $c = array_filter($c, fn($v) => ($v["client_name"] ?? "") !== "secpatch"); update_option("iato_mcp_oauth_clients", $c); delete_transient("iato_mcp_oauth_pkce"); echo "oauth clients left: " . count($c) . "\n";'
	echo "cleanup done"
	exit 0
fi
BASE="${1:-http://localhost:8888}"
W wp user get contributor --field=ID >/dev/null 2>&1 || W wp user create contributor contributor@example.test --role=contributor --user_pass=contributor-pass >/dev/null
W wp user get author --field=ID >/dev/null 2>&1 || W wp user create author author@example.test --role=author --user_pass=author-pass >/dev/null
W wp user get editor --field=ID >/dev/null 2>&1 || W wp user create editor editor@example.test --role=editor --user_pass=editor-pass >/dev/null
CID=$(W wp user get contributor --field=ID); AID=$(W wp user get author --field=ID)
stamp=$(date +%s)
export BASE
export KEY=$(W wp option get iato_mcp_key)
export PW_CONTRIBUTOR=$(W wp user application-password create contributor live-$stamp --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-$stamp --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-$stamp --porcelain)
export PW_ADMIN=$(W wp user application-password create admin live-$stamp --porcelain)
export ADMIN_PAGE=$(W wp post create --post_type=page --post_status=publish --post_author=1 --post_title="secpatch admin page $stamp" --porcelain)
export CONTRIB_DRAFT=$(W wp post create --post_type=post --post_status=draft --post_author=$CID --post_title="secpatch contributor draft $stamp" --porcelain)
export CONTRIB_DRAFT2=$(W wp post create --post_type=post --post_status=draft --post_author=$CID --post_title="secpatch contributor draft 2 $stamp" --porcelain)
export AUTHOR_DRAFT=$(W wp post create --post_type=post --post_status=draft --post_author=$AID --post_title="secpatch author draft $stamp" --porcelain)
export ATTACHMENT=$(W wp media import wp-admin/images/wordpress-logo.png --user=admin --porcelain)
export AUTHOR_ATTACHMENT=$(W wp media import wp-admin/images/wordpress-logo.png --user=author --porcelain)
export ADMIN_DRAFT=$(W wp post create --post_type=page --post_status=draft --post_author=1 --post_title="secpatch admin draft $stamp" --porcelain)
export ADMIN_PRIVATE=$(W wp post create --post_type=post --post_status=private --post_author=1 --post_title="secpatch admin private $stamp" --porcelain)
export PASSWORD_PAGE=$(W wp post create --post_type=page --post_status=publish --post_author=1 --post_password=secpatch --post_title="secpatch password page $stamp" --porcelain)
export TEMPLATE=$(W wp post create --post_type=elementor_library --post_status=publish --post_author=1 --post_title="secpatch template $stamp" --porcelain)
W wp post meta update "$TEMPLATE" _elementor_template_type page >/dev/null
# Revision rows of the password-protected page and of the template (what
# wp_save_post_revision() writes: post_type revision, status inherit, parent set).
export REV_PASSWORD=$(W wp post create --post_type=revision --post_status=inherit --post_parent="$PASSWORD_PAGE" --post_author=1 --post_name="$PASSWORD_PAGE-revision-v1" --post_title="secpatch password page rev $stamp" --post_content="revision body" --porcelain)
export REV_TEMPLATE=$(W wp post create --post_type=revision --post_status=inherit --post_parent="$TEMPLATE" --post_author=1 --post_name="$TEMPLATE-revision-v1" --post_title="secpatch template rev $stamp" --post_content="revision body" --porcelain)
export ELEMENTOR_PAGE="${ELEMENTOR_PAGE:-10}"
# A dangerous value stored before the fix: the render-time encoding must neutralise it.
W wp post meta update "$ADMIN_PAGE" _iato_mcp_structured_data '{"@type":"Article","name":"x</script><script>alert(1)</script>"}' >/dev/null
echo "fixtures: page=$ADMIN_PAGE contrib=$CONTRIB_DRAFT,$CONTRIB_DRAFT2 author=$AUTHOR_DRAFT attachment=$ATTACHMENT elementor=$ELEMENTOR_PAGE"
python3 -I tests/wp-env/permissions-live.py

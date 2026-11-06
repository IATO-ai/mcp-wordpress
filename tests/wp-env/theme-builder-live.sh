#!/bin/zsh
# Live checks for 1.17.0's template tools on a real site: create_elementor_template and
# set_elementor_template_conditions, what they leave behind against what wp-admin does,
# what logged-out visitors then see, verified and the overlap warning, rollback with and
# without force, and the header workflow with a menu and elementor_header_footer.
# Without Elementor Pro it checks the refusals and the free types, says so, and passes.
#   tests/wp-env/theme-builder-live.sh [base-url]
#   tests/wp-env/theme-builder-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
# The Pro site: WPENV_DIR=../iato-sprint/wp-env-pro tests/wp-env/theme-builder-live.sh http://localhost:8892
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

MU=wp-content/mu-plugins/iato-mcp-test-template-throw.php
SRC=wp-content/plugins/mcp-wordpress/tests/wp-env

cleanup() {
	W rm -f "$MU"
	W wp option delete iato_mcp_test_template_throw >/dev/null
	for id in $(W wp post list --post_type=page,post,elementor_library --post_status=any,trash --s=theme-builder-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	for id in $(W wp menu list --fields=term_id,name --format=csv | grep 'theme-builder-live' | cut -d, -f1); do W wp menu delete "$id" >/dev/null; done
	W wp eval 'if(class_exists("\ElementorPro\Modules\ThemeBuilder\Module")){\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();}' >/dev/null
	for who in admin editor; do
		W wp user application-password list "$who" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-templates-*) W wp user application-password delete "$who" "$uuid" >/dev/null ;; esac; done
	done
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W mkdir -p wp-content/mu-plugins
W cp "$SRC/mu-template-throw-test.php" "$MU"
export KEY=$(W wp option get iato_mcp_key)
W wp user get editor --field=ID >/dev/null 2>&1 || W wp user create editor editor@example.test --role=editor --user_pass=editor-pass >/dev/null
stamp=$(date +%s)
export PW_ADMIN=$(W wp user application-password create admin live-templates-$stamp --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-templates-$stamp --porcelain)
python3 -I "$REPO/tests/wp-env/theme-builder-live.py"
rc=$?
cleanup
exit $rc

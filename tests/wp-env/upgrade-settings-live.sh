#!/bin/zsh
# Do settings survive an update from WordPress.org? (1.21.1, after a report that "Allow
# inline SVG and style blocks" was off after updating to 1.21.0.)
#
# Installs the previous release from WordPress.org next to the mounted checkout (which
# is deactivated for the run), turns the setting on through the real settings form in a
# real wp-admin login, updates through `wp plugin update` (WordPress.org's path), then
# reads the setting, renders the form and saves it with two tools switched on. A second
# phase saves the mounted checkout's form: the save message names a reconnect only when
# the tools changed.
#   tests/wp-env/upgrade-settings-live.sh [base-url] [from-version]
#   tests/wp-env/upgrade-settings-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli). The wp-admin
# password of `admin` comes from ADMIN_PASS (wp-env's default, "password", when unset).
# Both copies use the same option names, so every iato_mcp_ option is backed up first
# and put back exactly afterwards, and the WordPress.org copy is removed without its
# uninstall routine, which would delete them.
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
# W's exit status is grep's; this one is the command's.
Q() { npx @wordpress/env run "$WPENV_SVC" "$@" >/dev/null 2>&1 </dev/null; }

# Put every iato_mcp_ option back from the backup; `final` also deletes the backup.
restore() {
	W wp eval 'global $wpdb; $b = get_option( "iato_test_upgrade_backup" ); if ( is_array( $b ) ) { foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( "iato_mcp_" ) . "%" ) ) as $name ) { if ( ! isset( $b[ $name ] ) ) { $wpdb->delete( $wpdb->options, [ "option_name" => $name ] ); } } foreach ( $b as $name => $row ) { $wpdb->replace( $wpdb->options, [ "option_name" => $name, "option_value" => $row[0], "autoload" => $row[1] ] ); } wp_cache_flush(); echo "restored ", count( $b ), " options\n"; }'
	[ "${1:-}" = "final" ] && W wp option delete iato_test_upgrade_backup >/dev/null
}

cleanup() {
	if Q wp plugin is-installed iato-mcp; then
		W wp plugin deactivate iato-mcp >/dev/null
		# Not `wp plugin delete`: that runs uninstall.php, which deletes the shared options.
		W rm -rf wp-content/plugins/iato-mcp
	fi
	restore "${1:-}"
	W wp plugin activate mcp-wordpress >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup final
	echo "cleanup done"
	exit 0
fi

export BASE="http://localhost:8888"
export FROM_VERSION="1.20.0"
for arg in "$@"; do
	case "$arg" in
		http*) BASE="$arg" ;;
		[0-9]*) FROM_VERSION="$arg" ;;
	esac
done
if Q wp plugin is-installed iato-mcp; then
	echo "A plugin folder named iato-mcp is already installed on this site; this check installs and removes one, so it will not run here."
	exit 2
fi
if [ -n "$(W wp option get iato_test_upgrade_backup 2>/dev/null)" ]; then
	echo "A backup from an earlier run is still there; run --cleanup first."
	exit 2
fi

# Every iato_mcp_ option, raw, so the restore puts back exactly what was there.
W wp eval 'global $wpdb; $b = []; foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( "iato_mcp_" ) . "%" ) ) as $r ) { $b[ $r->option_name ] = [ $r->option_value, $r->autoload ]; } add_option( "iato_test_upgrade_backup", $b, "", "no" ); echo "backed up ", count( $b ), " options\n";'
export KEY=$(W wp option get iato_mcp_key)
export ADMIN_PASS="${ADMIN_PASS:-password}"

W wp plugin deactivate mcp-wordpress >/dev/null
# A site on the previous release never had what this one added.
W wp option delete iato_mcp_page_scripts_enabled iato_mcp_page_script_approvals >/dev/null
# The zip's URL, not --version: `wp-env run` takes --version as its own flag, and WP-CLI
# then installs the latest release.
W wp plugin install "https://downloads.wordpress.org/plugin/iato-mcp.$FROM_VERSION.zip" --activate >/dev/null
W wp option update iato_mcp_db_version "$FROM_VERSION" >/dev/null
W wp eval 'update_option( "iato_mcp_tools", ( new ReflectionClass( "IATO_MCP_Settings" ) )->getConstant( "TOOL_NAMES" ) ); update_option( "iato_mcp_inline_markup_enabled", "0" );' >/dev/null

PHASE=upgrade python3 -I "$REPO/tests/wp-env/upgrade-settings-live.py"
rc1=$?
cleanup >/dev/null
PHASE=branch python3 -I "$REPO/tests/wp-env/upgrade-settings-live.py"
rc2=$?
cleanup final
[ $rc1 = 0 ] && [ $rc2 = 0 ]

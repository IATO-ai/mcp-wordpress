<?php
/**
 * Page scripts (1.21.0): inline JavaScript for one page that runs only after an
 * administrator has approved that exact text in wp-admin, for get_page_script,
 * set_page_script, the "Page assets" tab and rollback.
 *
 * An agent writing script is the main prompt-injection risk, so the gates matter more
 * than the feature:
 *   - the setting "Allow page scripts" (iato_mcp_page_scripts_enabled), off by default,
 *     and the two tools, which ship switched off in the Tools card;
 *   - writing needs a bound user holding manage_options, unfiltered_html past the
 *     lock, and edit_post;
 *   - a script is stored pending and never printed until approved. The approval holds
 *     the script's SHA-256, so any change to the text makes it pending again;
 *   - approving takes a logged-in wp-admin session (no MCP credential works on
 *     admin-post.php), a nonce tied to the post and that hash, and the administrator's
 *     password, with failures limited. Sites signing in through SSO can replace the
 *     password check with the `iato_mcp_verify_script_approval` filter;
 *   - approvals live in an option, never in post meta, and the meta key is denied to
 *     every meta tool, so nothing written another way can make a script run;
 *   - it prints only while the setting is on and set_page_script is switched on, and
 *     changing either, or deactivating the plugin, purges every page that has a script.
 *
 * Inline only: no src. `<script`, `</script` and `<!--` are refused. Whether the code
 * fetches anything is for the approving administrator to judge, which is why the tab
 * shows all of it. `<script>` stays removed inside HTML widgets.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Page_Script {

	public const META          = '_iato_mcp_page_script';
	public const OPTION        = 'iato_mcp_page_scripts_enabled';
	public const APPROVALS     = 'iato_mcp_page_script_approvals';
	public const RECEIPT       = 'page_script';
	public const FIELD         = 'script';
	public const TOOL          = 'set_page_script';
	public const MAX_BYTES     = 102400; // 100 KB
	public const MAX_FAILURES  = 5;
	public const FAILURE_SPAN  = 900; // 15 minutes

	public static function init(): void {
		add_action( 'wp_footer', [ self::class, 'print_script' ], 100 );
		add_action( 'iato_mcp_page_assets_tab', [ self::class, 'render_section' ] );
		add_filter( 'iato_mcp_page_assets_count', [ self::class, 'count_for_warning' ], 10, 2 );
		add_action( 'admin_post_iato_mcp_approve_page_script', [ self::class, 'handle_approve' ] );
		add_action( 'admin_post_iato_mcp_revoke_page_script', [ self::class, 'handle_revoke' ] );
		add_action( 'admin_notices', [ self::class, 'pending_notice' ] );
		// Either switch changing, in either direction, changes what pages print. The first
		// save of an option goes through add_option(), which does not fire update_option_*;
		// no script can be stored before both switches have been on, but hooking both keeps
		// "every change purges" true if that ever changes.
		add_action( 'update_option_' . self::OPTION, [ self::class, 'setting_changed' ], 10, 2 );
		add_action( 'add_option_' . self::OPTION, [ self::class, 'setting_added' ], 10, 2 );
		add_action( 'update_option_iato_mcp_tools', [ self::class, 'tools_changed' ], 10, 2 );
		add_action( 'add_option_iato_mcp_tools', [ self::class, 'tools_added' ], 10, 2 );
	}

	public static function setting_on(): bool {
		return rest_sanitize_boolean( get_option( self::OPTION, '0' ) );
	}

	public static function tool_on(): bool {
		return class_exists( 'IATO_MCP_Settings' ) && IATO_MCP_Settings::is_tool_enabled( self::TOOL );
	}

	/** Do approved scripts print right now? */
	public static function active(): bool {
		return self::setting_on() && self::tool_on();
	}

	public static function stored( int $post_id ): string {
		$value = get_post_meta( $post_id, self::META, true );
		return is_string( $value ) ? $value : '';
	}

	public static function write( int $post_id, string $script ): void {
		if ( '' === $script ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_post_meta( $post_id, self::META, wp_slash( $script ) );
	}

	/** @return array<int,array{sha256:string,user_id:int,at:int}> */
	public static function approvals(): array {
		$value = get_option( self::APPROVALS, [] );
		return is_array( $value ) ? $value : [];
	}

	public static function approval( int $post_id ): ?array {
		$all = self::approvals();
		return isset( $all[ $post_id ] ) && is_array( $all[ $post_id ] ) ? $all[ $post_id ] : null;
	}

	/** none, pending or approved. */
	public static function status( int $post_id ): string {
		$script = self::stored( $post_id );
		if ( '' === $script ) {
			return 'none';
		}
		$approval = self::approval( $post_id );
		return null !== $approval && hash_equals( (string) $approval['sha256'], hash( 'sha256', $script ) ) ? 'approved' : 'pending';
	}

	/** May this request store a script on this post? */
	public static function may_write( int $post_id, bool $need_setting = true ): bool|WP_Error {
		if ( $need_setting && ! self::setting_on() ) {
			return new WP_Error( 'page_scripts_disabled', 'Page scripts are turned off on this site. An administrator decides whether to allow them, under Settings > IATO MCP. Nothing was written.', [ 'status' => 403 ] );
		}
		$bound = IATO_MCP_Auth::require_bound_user();
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}
		$admin = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		// The lock denies unfiltered_html to every MCP request; this reads what the user holds outside it.
		$unfiltered = IATO_MCP_Auth::write_unlocked( static fn() => IATO_MCP_Auth::require_cap( 'unfiltered_html' ) );
		if ( is_wp_error( $unfiltered ) ) {
			return $unfiltered;
		}
		return IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
	}

	/** Why this script cannot be stored, or null. */
	public static function refusal( mixed $script ): ?WP_Error {
		if ( ! is_string( $script ) ) {
			return new WP_Error( 'invalid_script', 'script must be text. Nothing was written.' );
		}
		if ( strlen( $script ) > self::MAX_BYTES ) {
			return new WP_Error( 'script_too_large', sprintf( 'This script is %1$d bytes, and the most is %2$d. Nothing was written.', strlen( $script ), self::MAX_BYTES ) );
		}
		if ( 1 !== preg_match( '//u', $script ) ) {
			return new WP_Error( 'script_not_allowed', 'The script is not valid UTF-8. Nothing was written.' );
		}
		// What the approving administrator reads must be what runs. Direction controls and
		// zero-width characters (Unicode format characters) reorder or hide text on screen
		// while JavaScript reads it as written ("Trojan Source"), and U+2028/U+2029 end a
		// // comment without showing a line break.
		if ( 1 === preg_match( '/[\p{Cf}\x{2028}\x{2029}]/u', $script, $found ) ) {
			return new WP_Error( 'script_not_allowed', sprintf( 'The script holds an invisible or direction-changing character (U+%04X), which would make the text an administrator reads differ from what runs. Remove it, or write it as an escape such as \\u%04X inside a string. Nothing was written.', self::code_point( $found[0] ), self::code_point( $found[0] ) ), [ 'found' => sprintf( 'U+%04X', self::code_point( $found[0] ) ) ] );
		}
		foreach ( [ '<script', '</script', '<!--' ] as $needle ) {
			if ( false !== stripos( $script, $needle ) ) {
				return new WP_Error( 'script_not_allowed', sprintf( 'The script may not contain %s: it is printed inside a script element, so send only the JavaScript, with no tags, and build any such text from parts. Nothing was written.', $needle ), [ 'found' => $needle ] );
			}
		}
		return null;
	}

	private static function code_point( string $char ): int {
		$code = mb_ord( $char, 'UTF-8' );
		return false === $code ? 0 : $code;
	}

	/**
	 * The script for the approval screen: escaped, with every character outside ASCII
	 * shown as a marked \u{...} code, so a look-alike letter in a name or a string
	 * cannot pass for the one it imitates.
	 *
	 * @return array{html:string,non_ascii:int}
	 */
	public static function review_html( string $script ): array {
		$out   = '';
		$count = 0;
		foreach ( (array) preg_split( '//u', $script, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$char = (string) $char;
			if ( strlen( $char ) > 1 ) {
				++$count;
				$out .= '<mark title="' . esc_attr( $char ) . '">\\u{' . sprintf( '%04X', self::code_point( $char ) ) . '}</mark>';
			} else {
				$out .= esc_html( $char );
			}
		}
		return [ 'html' => $out, 'non_ascii' => $count ];
	}

	public static function before_value( int $post_id ): array {
		return [ 'script' => self::stored( $post_id ) ];
	}

	public static function after_value( int $post_id ): array {
		$script = self::stored( $post_id );
		return [ 'bytes' => strlen( $script ), 'sha256' => hash( 'sha256', $script ) ];
	}

	public static function restore_view( mixed $before ): array {
		$script = (string) ( IATO_MCP_Rollback_Conflict::json_array( $before )['script'] ?? '' );
		return [ 'bytes' => strlen( $script ), 'sha256' => hash( 'sha256', $script ) ];
	}

	/** Back as it was. It runs again only if that exact text is the approved one. */
	public static function restore( int $post_id, mixed $before ): bool|WP_Error {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'rollback_unavailable', "Post {$post_id} no longer exists.", [ 'status' => 409 ] );
		}
		self::write( $post_id, (string) ( IATO_MCP_Rollback_Conflict::json_array( $before )['script'] ?? '' ) );
		return true;
	}

	/** Rollback needs the same rights as a write, but not the setting: nothing prints while it is off. */
	public static function rollback_unavailable( int $post_id ): ?WP_Error {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'rollback_unavailable', 'The post this script belonged to no longer exists.', [ 'status' => 409 ] );
		}
		$may = self::may_write( $post_id, false );
		return is_wp_error( $may ) ? $may : null;
	}

	/** wp_footer: the approved script of the page being shown, and only then. */
	public static function print_script(): void {
		if ( ! self::active() ) {
			return;
		}
		$post_id = IATO_MCP_Page_CSS::queried_post_id();
		if ( $post_id <= 0 || 'approved' !== self::status( $post_id ) || self::in_elementor_editor() ) {
			return;
		}
		$script = self::stored( $post_id );
		if ( null !== self::refusal( $script ) ) {
			return; // what reached the meta another way, checked again
		}
		wp_print_inline_script_tag( $script, [ 'id' => 'iato-mcp-page-script-' . $post_id ] );
	}

	private static function in_elementor_editor(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		try {
			$preview = \Elementor\Plugin::$instance->preview ?? null;
			return is_object( $preview ) && method_exists( $preview, 'is_preview_mode' ) && $preview->is_preview_mode();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/** Posts with a stored script. @return int[] */
	public static function post_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own key; admin screens and switch changes only.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ) );
	}

	/**
	 * Purge the page cache of every post with a stored script. A script stopping is a
	 * security change, so this purges even when "Purge page caches after changes" is off
	 * (only the iato_mcp_cache_purge filter can skip it), straight through the cache
	 * plugins rather than the per-call queue.
	 */
	public static function purge_all(): void {
		foreach ( self::post_ids() as $id ) {
			self::purge_post( $id );
		}
	}

	/** One post's page cache, whatever the purge setting says. */
	public static function purge_post( int $post_id ): void {
		if ( $post_id > 0 && class_exists( 'IATO_MCP_Cache' ) ) {
			IATO_MCP_Cache::purge( 'post', $post_id );
		}
	}

	/** Caches in front of the site that no plugin can purge, for the admin messages. */
	private static function edge_warning(): string {
		$notes = class_exists( 'IATO_MCP_Cache' ) ? (array) ( IATO_MCP_Cache::site_info()['notes'] ?? [] ) : [];
		return [] === $notes ? '' : ' ' . implode( ' ', $notes );
	}

	/** @param mixed $old @param mixed $new */
	public static function setting_changed( $old, $new ): void {
		if ( rest_sanitize_boolean( $old ) !== rest_sanitize_boolean( $new ) ) {
			self::purge_all();
		}
	}

	/** @param string $option @param mixed $value */
	public static function setting_added( $option, $value ): void {
		if ( rest_sanitize_boolean( $value ) ) {
			self::purge_all();
		}
	}

	/** Was set_page_script on under this saved tool list? An empty list means the defaults, where it is off. */
	public static function tool_on_in( mixed $tools ): bool {
		return is_array( $tools ) && [] !== $tools && in_array( self::TOOL, $tools, true );
	}

	/** @param mixed $old @param mixed $new */
	public static function tools_changed( $old, $new ): void {
		if ( self::tool_on_in( $old ) !== self::tool_on_in( $new ) ) {
			self::purge_all();
		}
	}

	/** @param string $option @param mixed $value */
	public static function tools_added( $option, $value ): void {
		if ( self::tool_on_in( $value ) ) {
			self::purge_all();
		}
	}

	/** The deactivation warning counts pages with an approved script too. @param int $count @param int[] $ids */
	public static function count_for_warning( $count, $ids = [] ): int {
		$approved = array_values( array_filter( self::post_ids(), static fn( int $id ): bool => 'approved' === self::status( $id ) ) );
		return [] === $approved ? (int) $count : count( array_unique( array_merge( (array) $ids, $approved ) ) );
	}

	// ── wp-admin ───────────────────────────────────────────────────────────

	private static function may_approve(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	/** Pending scripts, on the plugin's pages and the Dashboard. */
	public static function pending_notice(): void {
		if ( ! self::may_approve() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'settings_page_iato-mcp' ], true ) ) {
			return;
		}
		$pending = count( array_filter( self::post_ids(), static fn( int $id ): bool => 'pending' === self::status( $id ) ) );
		if ( $pending < 1 ) {
			return;
		}
		/* translators: %d: number of scripts */
		$text = sprintf( _n( '%d page script is waiting for approval.', '%d page scripts are waiting for approval.', $pending, 'iato-mcp' ), $pending );
		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . ' <a href="' . esc_url( IATO_MCP_Page_Assets_Admin::url() ) . '">' . esc_html__( 'Review them', 'iato-mcp' ) . '</a></p></div>';
	}

	/** The "Page scripts" section of the Page assets tab. */
	public static function render_section(): void {
		echo '<div class="iato-card"><h2>' . esc_html__( 'Page scripts', 'iato-mcp' ) . '</h2>';
		if ( ! self::may_approve() ) {
			echo '<p>' . esc_html__( 'Approving page scripts needs an administrator who may post unfiltered HTML.', 'iato-mcp' ) . '</p></div>';
			return;
		}
		echo '<p class="iato-card-desc">' . esc_html__( 'Scripts sent with set_page_script. A script runs on its page only after you approve it here, and any change to it waits for approval again. Read all of it before approving: it runs for every visitor of that page, administrators included while they are logged in, and can do anything JavaScript can, including sending data to other sites. Prefer scripts that read nothing from the page\'s own content: editors can change that content, and a script that reads settings from element IDs, names or data attributes can be steered by them.', 'iato-mcp' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after a redirect.
		if ( isset( $_GET['iato_edge'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( __( 'A cache in front of the site may still serve copies of the page with the script until they expire.', 'iato-mcp' ) . self::edge_warning() ) . '</p></div>';
		}
		if ( ! self::active() ) {
			echo '<p><strong>' . esc_html__( 'Page scripts do not run on this site now: "Allow page scripts" is off under General, or set_page_script is switched off under Tools.', 'iato-mcp' ) . '</strong></p>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after a redirect.
		$error = isset( $_GET['iato_script_error'] ) ? sanitize_key( wp_unslash( $_GET['iato_script_error'] ) ) : '';
		if ( '' !== $error ) {
			$messages = [
				'password' => __( 'The password was not right. The script was not approved.', 'iato-mcp' ),
				'locked'   => __( 'Too many wrong passwords. Wait 15 minutes, then try again.', 'iato-mcp' ),
				'changed'  => __( 'The script changed after this page was shown, so it was not approved. Read it again.', 'iato-mcp' ),
			];
			echo '<div class="notice notice-error inline"><p>' . esc_html( $messages[ $error ] ?? __( 'The script was not approved.', 'iato-mcp' ) ) . '</p></div>';
		}
		$ids = self::post_ids();
		if ( [] === $ids ) {
			echo '<p>' . esc_html__( 'No page has a script.', 'iato-mcp' ) . '</p></div>';
			return;
		}
		foreach ( $ids as $id ) {
			$script   = self::stored( $id );
			$sha      = hash( 'sha256', $script );
			$status   = self::status( $id );
			$approval = self::approval( $id );
			$approver = $approval && $approval['user_id'] ? get_userdata( (int) $approval['user_id'] ) : null;
			echo '<div class="iato-page-script" data-post="' . (int) $id . '" data-status="' . esc_attr( $status ) . '" style="border-top:1px solid #dcdcde;padding-top:12px;margin-top:12px">';
			echo '<p><strong>' . esc_html( get_the_title( $id ) ) . '</strong> <code>' . (int) $id . '</code> · ' . esc_html( 'approved' === $status ? __( 'Approved', 'iato-mcp' ) : __( 'Waiting for approval', 'iato-mcp' ) );
			if ( 'approved' === $status && $approver ) {
				echo ' · ' . esc_html( sprintf( /* translators: 1: user login, 2: date */ __( 'by %1$s on %2$s (UTC)', 'iato-mcp' ), $approver->user_login, gmdate( 'Y-m-d H:i', (int) $approval['at'] ) ) );
			}
			echo ' · ' . esc_html( number_format_i18n( strlen( $script ) ) ) . ' bytes · <code>sha256 ' . esc_html( $sha ) . '</code></p>';
			$review = self::review_html( $script );
			if ( $review['non_ascii'] > 0 ) {
				/* translators: %d: number of characters */
				echo '<p><strong>' . esc_html( sprintf( _n( 'This script holds %d character outside plain ASCII, shown marked as \\u{…}. Check that each one is meant: a look-alike letter can make a name or address read as another.', 'This script holds %d characters outside plain ASCII, shown marked as \\u{…}. Check that each one is meant: a look-alike letter can make a name or address read as another.', $review['non_ascii'], 'iato-mcp' ), $review['non_ascii'] ) ) . '</strong></p>';
			}
			echo '<pre style="max-height:420px;overflow:auto;background:#f6f7f7;padding:12px;white-space:pre-wrap">' . $review['html'] . '</pre>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_html()/esc_attr() per character in review_html().
			if ( 'pending' === $status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="iato_mcp_approve_page_script">';
				echo '<input type="hidden" name="post_id" value="' . (int) $id . '">';
				echo '<input type="hidden" name="sha256" value="' . esc_attr( $sha ) . '">';
				wp_nonce_field( 'iato_mcp_approve_page_script_' . $id . '_' . $sha );
				echo '<label>' . esc_html__( 'Your password, to approve exactly this script:', 'iato-mcp' ) . ' <input type="password" name="iato_password" autocomplete="current-password" required></label> ';
				echo '<button type="submit" class="button button-primary">' . esc_html__( 'Approve', 'iato-mcp' ) . '</button></form>';
			} else {
				IATO_MCP_Page_Assets_Admin::action_button( 'iato_mcp_revoke_page_script', $id, __( 'Revoke approval', 'iato-mcp' ), __( 'Stop running this script?', 'iato-mcp' ) );
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private static function back( array $args ): void {
		wp_safe_redirect( IATO_MCP_Page_Assets_Admin::url( $args ) );
		exit;
	}

	/** Approve: a logged-in administrator, the nonce for this post and hash, the password. */
	public static function handle_approve(): void {
		if ( ! self::may_approve() ) {
			wp_die( esc_html__( 'You are not allowed to approve page scripts.', 'iato-mcp' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Both are part of the nonce action checked next.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$sha     = isset( $_POST['sha256'] ) ? sanitize_key( wp_unslash( $_POST['sha256'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'iato_mcp_approve_page_script_' . $post_id . '_' . $sha );

		$user     = wp_get_current_user();
		$failures = 'iato_mcp_script_approve_failures_' . (int) $user->ID;
		if ( (int) get_transient( $failures ) >= self::MAX_FAILURES ) {
			self::back( [ 'iato_script_error' => 'locked' ] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; a password is compared as typed and never stored or shown.
		$password = isset( $_POST['iato_password'] ) ? (string) wp_unslash( $_POST['iato_password'] ) : '';
		$ok       = '' !== $password && wp_check_password( $password, $user->user_pass, $user->ID );
		/**
		 * Replace the password check, for sites that sign in through SSO. Return true only
		 * when this request has been verified some other way.
		 *
		 * @param bool    $ok   Whether the password matched.
		 * @param WP_User $user The administrator approving.
		 */
		$ok = (bool) apply_filters( 'iato_mcp_verify_script_approval', $ok, $user );
		if ( ! $ok ) {
			set_transient( $failures, (int) get_transient( $failures ) + 1, self::FAILURE_SPAN );
			self::back( [ 'iato_script_error' => 'password' ] );
		}
		delete_transient( $failures );

		$script = self::stored( $post_id );
		if ( '' === $script || ! hash_equals( hash( 'sha256', $script ), $sha ) ) {
			self::back( [ 'iato_script_error' => 'changed' ] );
		}
		$all             = self::approvals();
		$all[ $post_id ] = [ 'sha256' => $sha, 'user_id' => (int) $user->ID, 'at' => time() ];
		update_option( self::APPROVALS, $all, false );
		self::purge_post( $post_id );
		self::back( [ 'iato_done' => 'script_approved' ] );
	}

	public static function handle_revoke(): void {
		if ( ! self::may_approve() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'iato-mcp' ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line.
		check_admin_referer( 'iato_mcp_revoke_page_script_' . $post_id );
		$all = self::approvals();
		unset( $all[ $post_id ] );
		update_option( self::APPROVALS, $all, false );
		self::purge_post( $post_id );
		self::back( [ 'iato_done' => 'script_revoked' ] + ( '' !== self::edge_warning() ? [ 'iato_edge' => '1' ] : [] ) );
	}

}

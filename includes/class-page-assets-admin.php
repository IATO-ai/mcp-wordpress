<?php
/**
 * Page assets in wp-admin (1.21.0): the "Page assets" tab of Settings > IATO MCP, and
 * the warning on the Plugins screen.
 *
 * The tab lists every post with page CSS or fonts (bytes, hash, fonts, who set them)
 * with View, Remove CSS and Remove fonts, and every live preview link with Revoke and
 * Revoke all, so someone editing a page in Elementor can find where its styles come
 * from and remove them without an MCP client. Each action is an admin_post handler
 * with its own nonce; each records a receipt (actor kind `wordpress`) and purges that
 * post's page cache, so it can be rolled back like a tool's change.
 *
 * Page CSS, fonts and scripts print from this plugin's hooks, so deactivating it
 * takes them off their pages. WordPress runs no code of an inactive plugin, so the
 * warning comes while it is active: a notice under its row on the Plugins screen and a
 * confirmation on its Deactivate link, both with the number of posts affected. The
 * count passes through the `iato_mcp_page_assets_count` filter, which page scripts
 * use to add theirs.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_Page_Assets_Admin {

	public const TAB = 'assets';

	public static function init(): void {
		add_action( 'admin_post_iato_mcp_remove_page_css', [ self::class, 'handle_remove_css' ] );
		add_action( 'admin_post_iato_mcp_remove_page_fonts', [ self::class, 'handle_remove_fonts' ] );
		add_action( 'admin_post_iato_mcp_revoke_preview_link', [ self::class, 'handle_revoke_link' ] );
		add_action( 'admin_post_iato_mcp_revoke_all_preview_links', [ self::class, 'handle_revoke_all' ] );
		if ( defined( 'IATO_MCP_FILE' ) ) {
			$basename = plugin_basename( IATO_MCP_FILE );
			add_action( 'after_plugin_row_' . $basename, [ self::class, 'plugin_row_notice' ], 10, 2 );
			add_filter( 'plugin_action_links_' . $basename, [ self::class, 'deactivate_confirmation' ] );
		}
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( [ 'page' => 'iato-mcp', 'tab' => self::TAB ] + $args, admin_url( 'options-general.php' ) );
	}

	/** Posts with page CSS or fonts. @return int[] */
	public static function asset_post_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin screens only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) ORDER BY post_id", IATO_MCP_Page_CSS::META, IATO_MCP_Page_Fonts::META ) );
		return array_map( 'intval', (array) $ids );
	}

	/** How many posts lose something when the plugin is deactivated. */
	public static function count(): int {
		return (int) apply_filters( 'iato_mcp_page_assets_count', count( self::asset_post_ids() ) );
	}

	/** Who last changed this through a receipt, for the list. */
	private static function last_setter( int $post_id, string $type ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'iato_change_receipts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; admin screen only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND target_type = %s ORDER BY applied_at DESC LIMIT 1", $post_id, $type ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return '';
		}
		$actor = IATO_MCP_Change_Receipt::describe_actor( IATO_MCP_Change_Receipt::actor_of( $row ) );
		$who   = (string) ( $actor['user_login'] ?? $actor['login'] ?? '' );
		return trim( $who . ' · ' . (string) $row['applied_at'], ' ·' );
	}

	/** The tab. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice after a redirect.
		$done = isset( $_GET['iato_done'] ) ? sanitize_key( wp_unslash( $_GET['iato_done'] ) ) : '';
		if ( '' !== $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::done_message( $done ) ) . '</p></div>';
		}
		echo '<div class="iato-card"><h2>' . esc_html__( 'Page CSS and fonts', 'iato-mcp' ) . '</h2>';
		echo '<p class="iato-card-desc">' . esc_html__( 'CSS and fonts set through set_page_css and set_page_fonts. This plugin prints them in the page head, so a page loses them while the plugin is deactivated. Uninstalling keeps them, so reinstalling brings them back.', 'iato-mcp' ) . '</p>';
		$ids = self::asset_post_ids();
		if ( [] === $ids ) {
			echo '<p>' . esc_html__( 'No page has page CSS or fonts.', 'iato-mcp' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'iato-mcp' ) . '</th><th>' . esc_html__( 'CSS', 'iato-mcp' ) . '</th><th>' . esc_html__( 'Fonts', 'iato-mcp' ) . '</th><th>' . esc_html__( 'Set by', 'iato-mcp' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $ids as $id ) {
				$css   = IATO_MCP_Page_CSS::stored( $id );
				$fonts = IATO_MCP_Page_Fonts::stored( $id );
				$title = get_the_title( $id );
				echo '<tr><td>' . esc_html( '' !== $title ? $title : '#' . $id ) . ' <code>' . (int) $id . '</code> · ' . esc_html( (string) get_post_status( $id ) ) . '</td>';
				echo '<td>' . ( '' === $css ? '—' : esc_html( number_format_i18n( strlen( $css ) ) . ' bytes' ) . '<br><code title="sha256">' . esc_html( substr( hash( 'sha256', $css ), 0, 16 ) ) . '…</code>' ) . '</td>';
				echo '<td>' . ( [] === $fonts ? '—' : esc_html( implode( ', ', $fonts ) ) ) . '</td>';
				echo '<td>' . esc_html( trim( self::last_setter( $id, IATO_MCP_Page_CSS::RECEIPT ) . ' ' . self::last_setter( $id, IATO_MCP_Page_Fonts::RECEIPT ) ) ) . '</td><td>';
				echo '<a class="button button-small" href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'View', 'iato-mcp' ) . '</a> ';
				if ( '' !== $css ) {
					self::action_button( 'iato_mcp_remove_page_css', $id, __( 'Remove CSS', 'iato-mcp' ), __( 'Remove this page\'s CSS? The receipt can roll it back.', 'iato-mcp' ) );
				}
				if ( [] !== $fonts ) {
					self::action_button( 'iato_mcp_remove_page_fonts', $id, __( 'Remove fonts', 'iato-mcp' ), __( 'Stop loading these fonts on this page? The receipt can roll it back.', 'iato-mcp' ) );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="iato-card"><h2>' . esc_html__( 'Preview links', 'iato-mcp' ) . '</h2>';
		echo '<p class="iato-card-desc">' . esc_html__( 'Links made with create_preview_link. Anyone with one can see that draft until it expires.', 'iato-mcp' ) . '</p>';
		$links = IATO_MCP_Preview_Link::all_live();
		if ( [] === $links ) {
			echo '<p>' . esc_html__( 'No live preview links.', 'iato-mcp' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'iato-mcp' ) . '</th><th>' . esc_html__( 'Made by', 'iato-mcp' ) . '</th><th>' . esc_html__( 'Expires (UTC)', 'iato-mcp' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $links as $link ) {
				$user = $link['user_id'] > 0 ? get_userdata( $link['user_id'] ) : null;
				echo '<tr><td>' . esc_html( get_the_title( $link['post_id'] ) ) . ' <code>' . (int) $link['post_id'] . '</code></td><td>' . esc_html( $user ? $user->user_login : '—' ) . '</td><td>' . esc_html( gmdate( 'Y-m-d H:i:s', $link['expires'] ) ) . '</td><td>';
				self::action_button( 'iato_mcp_revoke_preview_link', $link['post_id'], __( 'Revoke', 'iato-mcp' ), '', [ 'link_id' => $link['link_id'] ] );
				echo '</td></tr>';
			}
			echo '</tbody></table><p>';
			self::action_button( 'iato_mcp_revoke_all_preview_links', 0, __( 'Revoke all', 'iato-mcp' ), __( 'Revoke every preview link?', 'iato-mcp' ) );
			echo '</p>';
		}
		echo '</div>';

		/** Page scripts add their section here. */
		do_action( 'iato_mcp_page_assets_tab' );
	}

	/** A small POST form: one button, its own nonce. */
	public static function action_button( string $action, int $post_id, string $label, string $confirm = '', array $extra = [] ): void {
		$nonce_action = $action . '_' . $post_id . ( isset( $extra['link_id'] ) ? '_' . $extra['link_id'] : '' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="post_id" value="' . (int) $post_id . '">';
		foreach ( $extra as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		wp_nonce_field( $nonce_action );
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button></form> ';
	}

	private static function done_message( string $done ): string {
		return [
			'css_removed'   => __( 'The page CSS was removed. Its receipt can roll it back.', 'iato-mcp' ),
			'fonts_removed' => __( 'The page fonts were removed. Their receipt can roll it back.', 'iato-mcp' ),
			'link_revoked'  => __( 'The preview link was revoked.', 'iato-mcp' ),
			'links_revoked' => __( 'Every preview link was revoked.', 'iato-mcp' ),
		][ $done ] ?? __( 'Done.', 'iato-mcp' );
	}

	/** The post an action names, after the capability and nonce checks; dies otherwise. */
	private static function checked_post( string $action, string $suffix = '' ): int {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'iato-mcp' ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified on the next line.
		check_admin_referer( $action . '_' . $post_id . $suffix );
		if ( $post_id > 0 && ! get_post( $post_id ) ) {
			wp_die( esc_html__( 'Post not found.', 'iato-mcp' ), 404 );
		}
		IATO_MCP_Cache::begin();
		return $post_id;
	}

	private static function finish( string $done ): void {
		IATO_MCP_Cache::flush(); // the receipt queued the post's page cache
		wp_safe_redirect( self::url( [ 'iato_done' => $done ] ) );
		exit;
	}

	public static function handle_remove_css(): void {
		$post_id = self::checked_post( 'iato_mcp_remove_page_css' );
		$before  = IATO_MCP_Page_CSS::before_value( $post_id );
		if ( '' !== $before['css'] ) {
			IATO_MCP_Page_CSS::write( $post_id, '' );
			IATO_MCP_Change_Receipt::record( $post_id, IATO_MCP_Page_CSS::RECEIPT, IATO_MCP_Page_CSS::FIELD, $before, IATO_MCP_Page_CSS::after_value( $post_id ) );
		}
		self::finish( 'css_removed' );
	}

	public static function handle_remove_fonts(): void {
		$post_id = self::checked_post( 'iato_mcp_remove_page_fonts' );
		$before  = IATO_MCP_Page_Fonts::stored( $post_id );
		if ( [] !== $before ) {
			IATO_MCP_Page_Fonts::write( $post_id, [] );
			IATO_MCP_Change_Receipt::record( $post_id, IATO_MCP_Page_Fonts::RECEIPT, IATO_MCP_Page_Fonts::FIELD, $before, [] );
		}
		self::finish( 'fonts_removed' );
	}

	public static function handle_revoke_link(): void {
		$link_id = isset( $_POST['link_id'] ) ? sanitize_key( wp_unslash( $_POST['link_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Part of the nonce action checked in checked_post().
		$post_id = self::checked_post( 'iato_mcp_revoke_preview_link', '_' . $link_id );
		IATO_MCP_Preview_Link::revoke( $post_id, $link_id );
		self::finish( 'link_revoked' );
	}

	public static function handle_revoke_all(): void {
		self::checked_post( 'iato_mcp_revoke_all_preview_links' );
		foreach ( array_unique( array_column( IATO_MCP_Preview_Link::all_live(), 'post_id' ) ) as $post_id ) {
			IATO_MCP_Preview_Link::revoke( (int) $post_id, null );
		}
		self::finish( 'links_revoked' );
	}

	// ── The Plugins screen ─────────────────────────────────────────────────

	private static function warning( int $count ): string {
		return sprintf(
			/* translators: %d: number of posts */
			_n(
				'%d page has CSS, fonts or scripts this plugin prints. Deactivating the plugin takes them off that page; deleting it removes page scripts for good, while page CSS and fonts come back if it is installed again. See Settings > IATO MCP > Page assets.',
				'%d pages have CSS, fonts or scripts this plugin prints. Deactivating the plugin takes them off those pages; deleting it removes page scripts for good, while page CSS and fonts come back if it is installed again. See Settings > IATO MCP > Page assets.',
				$count,
				'iato-mcp'
			),
			$count
		);
	}

	/** A notice under the plugin's row, while it is active and pages depend on it. */
	public static function plugin_row_notice( $file, $data ): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! is_plugin_active( (string) $file ) ) {
			return;
		}
		$count = self::count();
		if ( $count < 1 ) {
			return;
		}
		$columns = function_exists( '_get_list_table' ) ? (int) _get_list_table( 'WP_Plugins_List_Table' )->get_column_count() : 3;
		echo '<tr class="plugin-update-tr active iato-mcp-assets-row"><td colspan="' . (int) $columns . '" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>' . esc_html( self::warning( $count ) ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Page assets', 'iato-mcp' ) . '</a></p></div></td></tr>';
	}

	/** The Deactivate link asks first, with the same number. @param array $actions */
	public static function deactivate_confirmation( $actions ): array {
		$actions = is_array( $actions ) ? $actions : [];
		$count   = isset( $actions['deactivate'] ) ? self::count() : 0;
		if ( $count > 0 ) {
			$message               = self::warning( $count ) . ' ' . __( 'Deactivate anyway?', 'iato-mcp' );
			$link = (string) $actions['deactivate'];
			if ( str_starts_with( $link, '<a ' ) ) {
				// Spliced, not preg_replace(): a translation holding $1 or a backslash would be read as a back-reference.
				$actions['deactivate'] = '<a data-iato-mcp-assets="' . (int) $count . '" onclick="return confirm(' . esc_attr( (string) wp_json_encode( $message ) ) . ');" ' . substr( $link, 3 );
			}
		}
		return $actions;
	}
}

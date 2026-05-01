<?php
/**
 * Diagnostics — MCP server status and recent call log.
 *
 * Rendered as a tab ("Diagnostics") on the main Settings > IATO MCP page
 * via IATO_MCP_Diagnostics::render(). This class no longer registers a
 * menu of its own — the settings page loads its CSS and calls render()
 * when ?tab=diagnostics is active.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Diagnostics {

	/**
	 * Boot hooks — just the clear-log handler. Menu + enqueue are owned by the settings page.
	 */
	public static function init(): void {
		add_action( 'admin_post_iato_mcp_diag_clear_log', [ self::class, 'handle_clear_log' ] );
	}

	/**
	 * Inline styles — called from the settings page enqueue block when the
	 * Diagnostics tab is active.
	 *
	 * @return string
	 */
	public static function get_inline_styles(): string {
		return <<<'CSS'
.iato-diag .section { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px 20px; margin-bottom: 16px; }
.iato-diag .section h2 { margin-top: 0; font-size: 15px; }
.iato-diag table.diag-kv { width: 100%; border-collapse: collapse; font-size: 13px; }
.iato-diag table.diag-kv td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.iato-diag table.diag-kv td:first-child { font-weight: 600; color: #374151; width: 200px; }
.iato-diag table.diag-kv td code { background: #f3f4f6; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
.iato-diag table.diag-list { width: 100%; border-collapse: collapse; font-size: 12px; }
.iato-diag table.diag-list th { text-align: left; padding: 6px 8px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151; }
.iato-diag table.diag-list td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.iato-diag .empty { color: #6b7280; font-style: italic; padding: 16px; background: #f9fafb; border-radius: 4px; }
.iato-diag .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.iato-diag .badge.success { background: #d1fae5; color: #065f46; }
.iato-diag .badge.error { background: #fee2e2; color: #991b1b; }
.iato-diag .badge.unauthorized { background: #fef3c7; color: #92400e; }
CSS;
	}

	/**
	 * Render the diagnostics content. Called by the settings page when
	 * ?tab=diagnostics is active — the wrapping .wrap and <h1> come from
	 * the settings page's render_page(), so we only emit the body here.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}

		$mcp_url   = rest_url( 'iato-mcp/v1/message' );
		$api_key   = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$api_valid = (bool) get_option( 'iato_mcp_api_key_valid', false );
		?>
		<div class="iato-diag">
			<div class="section">
				<h2><?php esc_html_e( 'Server status', 'iato-mcp' ); ?></h2>
				<table class="diag-kv">
					<tr>
						<td><?php esc_html_e( 'MCP endpoint', 'iato-mcp' ); ?></td>
						<td><code><?php echo esc_html( $mcp_url ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Plugin version', 'iato-mcp' ); ?></td>
						<td><code><?php echo esc_html( IATO_MCP_VERSION ); ?></code></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'IATO API key', 'iato-mcp' ); ?></td>
						<td>
							<?php if ( '' === $api_key ) : ?>
								<span class="badge"><?php esc_html_e( 'Not configured', 'iato-mcp' ); ?></span>
								<?php esc_html_e( '(optional — required only for bridge read tools)', 'iato-mcp' ); ?>
							<?php elseif ( $api_valid ) : ?>
								<span class="badge success"><?php esc_html_e( 'Valid', 'iato-mcp' ); ?></span>
							<?php else : ?>
								<span class="badge error"><?php esc_html_e( 'Not validated', 'iato-mcp' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>

			<?php self::render_call_log(); ?>
		</div>
		<?php
	}

	/**
	 * Render the recent MCP call log section.
	 */
	private static function render_call_log(): void {
		$rows = self::fetch_recent_calls( 50 );
		?>
		<div class="section">
			<h2><?php esc_html_e( 'Recent MCP calls', 'iato-mcp' ); ?></h2>
			<p style="color:#6b7280;font-size:13px">
				<?php esc_html_e( 'Last 50 JSON-RPC requests handled by the MCP endpoint. Useful for debugging Claude Desktop connection and tool calls.', 'iato-mcp' ); ?>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<div class="empty">
					<?php esc_html_e( 'No MCP calls recorded yet. Once an AI client connects and invokes tools, they will appear here.', 'iato-mcp' ); ?>
				</div>
			<?php else : ?>
				<table class="diag-list">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Method', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Error', 'iato-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) :
							$status   = (string) ( $r['response_status'] ?? '' );
							$tool     = (string) ( $r['tool_name'] ?? '' );
							$method   = (string) ( $r['rpc_method'] ?? '' );
							$duration = (int) ( $r['duration_ms'] ?? 0 );
							$err_code = (string) ( $r['error_code'] ?? '' );
							$err_msg  = (string) ( $r['error_message'] ?? '' );
							$created  = (string) ( $r['created_at'] ?? '' );
							?>
							<tr>
								<td><?php echo esc_html( $created ); ?></td>
								<td><code><?php echo esc_html( $method ); ?></code></td>
								<td><?php echo $tool !== '' ? '<code>' . esc_html( $tool ) . '</code>' : '<span style="color:#9ca3af">—</span>'; ?></td>
								<td>
									<span class="badge <?php echo esc_attr( $status ); ?>">
										<?php echo esc_html( $status ?: 'unknown' ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $duration . ' ms' ); ?></td>
								<td style="color:#991b1b">
									<?php if ( $err_code !== '' || $err_msg !== '' ) : ?>
										<code><?php echo esc_html( $err_code ); ?></code> <?php echo esc_html( $err_msg ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:12px">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=iato_mcp_diag_clear_log' ), 'iato_mcp_diag_clear_log' ) ); ?>" class="button" onclick="return confirm('Clear all MCP call log entries?');">
						<?php esc_html_e( 'Clear log', 'iato-mcp' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Fetch recent call log rows.
	 *
	 * @param int $limit
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_recent_calls( int $limit = 50 ): array {
		if ( ! class_exists( 'IATO_MCP_Call_Log' ) ) {
			return [];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read; diagnostics log isn't cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d',
				IATO_MCP_Call_Log::table_name(),
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Handler: clear the call log table. Returns to the Diagnostics tab.
	 */
	public static function handle_clear_log(): void {
		check_admin_referer( 'iato_mcp_diag_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}
		if ( class_exists( 'IATO_MCP_Call_Log' ) ) {
			IATO_MCP_Call_Log::purge();
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=iato-mcp&tab=diagnostics' ) );
		exit;
	}
}

<?php
/**
 * Diagnostics Admin Page — answers "Is Autopilot working?" at a glance.
 *
 * Surfaces six sections:
 *   1. Platform connection status
 *   2. Current crawl status
 *   3. Raw IATO activity log
 *   4. Raw IATO autopilot queue
 *   5. Local incoming MCP call log
 *   6. Governance policy (local vs remote)
 *
 * Registered as a submenu under the IATO Reviews top-level menu.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Diagnostics {

	const PAGE_SLUG = 'iato-mcp-diagnostics';

	private static string $page_hook = '';

	/**
	 * Boot hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_iato_mcp_diag_clear_log', [ self::class, 'handle_clear_log' ] );
		add_action( 'admin_post_iato_mcp_diag_test_conn', [ self::class, 'handle_test_connection' ] );
		add_action( 'admin_post_iato_mcp_diag_sync_policy', [ self::class, 'handle_sync_policy' ] );
	}

	/**
	 * Register Diagnostics as a submenu under the IATO Reviews top-level menu.
	 */
	public static function register_page(): void {
		self::$page_hook = (string) add_submenu_page(
			'iato-review-queue',
			__( 'Diagnostics', 'iato-mcp' ),
			__( 'Diagnostics', 'iato-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Inline styles for the Diagnostics page only.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( '' === self::$page_hook || $hook !== self::$page_hook ) {
			return;
		}

		wp_register_style( 'iato-mcp-diagnostics', false, [], IATO_MCP_VERSION );
		wp_enqueue_style( 'iato-mcp-diagnostics' );
		wp_add_inline_style( 'iato-mcp-diagnostics', self::get_inline_styles() );
	}

	private static function get_inline_styles(): string {
		return <<<'CSS'
.iato-diag { max-width: 1100px; font-family: system-ui, -apple-system, sans-serif; }
.iato-diag .section { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px 20px; margin-bottom: 18px; }
.iato-diag .section h2 { margin-top: 0; font-size: 16px; display: flex; align-items: center; gap: 8px; }
.iato-diag .section h2 .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.iato-diag .dot-ok { background: #10b981; }
.iato-diag .dot-warn { background: #f59e0b; }
.iato-diag .dot-err { background: #ef4444; }
.iato-diag .dot-idle { background: #9ca3af; }
.iato-diag table.diag-kv { width: 100%; border-collapse: collapse; font-size: 13px; }
.iato-diag table.diag-kv td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.iato-diag table.diag-kv td:first-child { font-weight: 600; color: #374151; width: 200px; }
.iato-diag table.diag-kv td code { background: #f3f4f6; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
.iato-diag table.diag-list { width: 100%; border-collapse: collapse; font-size: 12px; }
.iato-diag table.diag-list th { text-align: left; padding: 6px 8px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151; }
.iato-diag table.diag-list td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.iato-diag table.diag-list tr:hover { background: #f9fafb; }
.iato-diag .empty { color: #6b7280; font-style: italic; padding: 16px; background: #f9fafb; border-radius: 6px; }
.iato-diag .empty.big { background: #fef3c7; color: #92400e; padding: 20px; font-size: 14px; font-style: normal; }
.iato-diag .status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
.iato-diag .status-badge.success { background: #d1fae5; color: #065f46; }
.iato-diag .status-badge.error { background: #fee2e2; color: #991b1b; }
.iato-diag .status-badge.unauthorized { background: #fef3c7; color: #92400e; }
.iato-diag .status-badge.applied { background: #dbeafe; color: #1e40af; }
.iato-diag .status-badge.pending, .iato-diag .status-badge.pending_review { background: #fef3c7; color: #92400e; }
.iato-diag .status-badge.rejected { background: #f3f4f6; color: #4b5563; }
.iato-diag .status-badge.failed { background: #fee2e2; color: #991b1b; }
.iato-diag .section-actions { margin-top: 12px; display: flex; gap: 8px; }
.iato-diag .mismatch { background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.iato-diag .match { background: #d1fae5; color: #065f46; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.iato-diag .truncate { max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
CSS;
	}

	/**
	 * Main render — delegates to per-section renderers.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}

		$workspace_id = IATO_MCP_IATO_Client::resolve_workspace_id();

		?>
		<div class="wrap iato-diag">
			<h1><?php esc_html_e( 'IATO Diagnostics', 'iato-mcp' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Use this page to verify that Autopilot is working end-to-end. It shows the raw state of the IATO platform and every incoming MCP call this site has received.', 'iato-mcp' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button">
					<?php esc_html_e( 'Refresh', 'iato-mcp' ); ?>
				</a>
			</p>

			<?php
			self::render_notices();
			self::render_section_connection( $workspace_id );
			self::render_section_crawl( $workspace_id );
			self::render_section_activity_log( $workspace_id );
			self::render_section_queue( $workspace_id );
			self::render_section_call_log();
			self::render_section_policy( $workspace_id );
			?>
		</div>
		<?php
	}

	private static function render_notices(): void {
		$notice = get_transient( 'iato_mcp_diag_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'iato_mcp_diag_notice' );
		$type = $notice['type'] ?? 'success';
		$msg  = $notice['message'] ?? '';
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $msg )
		);
	}

	// ── Section 1: Platform Connection ─────────────────────────────────────

	private static function render_section_connection( string $workspace_id ): void {
		$api_key       = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$api_valid     = (bool) get_option( 'iato_mcp_api_key_valid', false );
		$has_key       = '' !== $api_key;
		$rest_endpoint = rest_url( 'iato-mcp/v1/message' );
		$site_url      = site_url();

		$overall = $has_key && $api_valid && '' !== $workspace_id ? 'ok' : ( $has_key ? 'warn' : 'err' );

		?>
		<div class="section">
			<h2>
				<span class="status-dot dot-<?php echo esc_attr( $overall ); ?>"></span>
				<?php esc_html_e( '1. Platform Connection', 'iato-mcp' ); ?>
			</h2>
			<table class="diag-kv">
				<tr>
					<td><?php esc_html_e( 'API key configured', 'iato-mcp' ); ?></td>
					<td><?php echo $has_key ? '<strong style="color:#065f46">Yes</strong>' : '<strong style="color:#991b1b">No</strong>'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'API key validated', 'iato-mcp' ); ?></td>
					<td><?php echo $api_valid ? '<strong style="color:#065f46">Yes</strong>' : '<strong style="color:#991b1b">No</strong>'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Workspace ID', 'iato-mcp' ); ?></td>
					<td><?php echo '' !== $workspace_id ? '<code>' . esc_html( $workspace_id ) . '</code>' : '<em style="color:#991b1b">not resolved</em>'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Site URL', 'iato-mcp' ); ?></td>
					<td><code><?php echo esc_html( $site_url ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'MCP endpoint', 'iato-mcp' ); ?></td>
					<td><code><?php echo esc_html( $rest_endpoint ); ?></code></td>
				</tr>
			</table>
			<div class="section-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="iato_mcp_diag_test_conn" />
					<?php wp_nonce_field( 'iato_mcp_diag_test_conn' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Test Connection', 'iato-mcp' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	// ── Section 2: Current Crawl ───────────────────────────────────────────

	private static function render_section_crawl( string $workspace_id ): void {
		$crawl_id   = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		$sitemap_id = (int) get_option( 'iato_mcp_sitemap_id', 0 );

		$crawls_raw = '' !== $workspace_id ? IATO_MCP_IATO_Client::list_crawls() : [];
		$crawl_err  = is_wp_error( $crawls_raw ) ? $crawls_raw->get_error_message() : '';

		// Unwrap { success, data: { crawls: [...] } } or { crawls: [...] } or [...].
		$crawls = [];
		if ( ! is_wp_error( $crawls_raw ) && is_array( $crawls_raw ) ) {
			$inner  = $crawls_raw['data'] ?? $crawls_raw;
			$crawls = $inner['crawls'] ?? $inner['items'] ?? ( isset( $inner[0] ) ? $inner : [] );
			if ( ! is_array( $crawls ) ) {
				$crawls = [];
			}
		}

		$latest = $crawls[0] ?? null;

		?>
		<div class="section">
			<h2>
				<span class="status-dot dot-<?php echo $latest ? 'ok' : 'idle'; ?>"></span>
				<?php esc_html_e( '2. Current Crawl', 'iato-mcp' ); ?>
			</h2>
			<table class="diag-kv">
				<tr>
					<td><?php esc_html_e( 'Stored crawl_id', 'iato-mcp' ); ?></td>
					<td><?php echo '' !== $crawl_id ? '<code>' . esc_html( $crawl_id ) . '</code>' : '<em>not set</em>'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Stored sitemap_id', 'iato-mcp' ); ?></td>
					<td><?php echo $sitemap_id > 0 ? '<code>' . esc_html( (string) $sitemap_id ) . '</code>' : '<em>not set (run wizard Step 4 to populate)</em>'; ?></td>
				</tr>
				<?php if ( $latest ) : ?>
				<tr>
					<td><?php esc_html_e( 'Latest crawl ID', 'iato-mcp' ); ?></td>
					<td><code><?php echo esc_html( (string) ( $latest['id'] ?? $latest['crawl_id'] ?? '?' ) ); ?></code></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Status', 'iato-mcp' ); ?></td>
					<td><?php echo esc_html( (string) ( $latest['status'] ?? '?' ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Created at', 'iato-mcp' ); ?></td>
					<td><?php echo esc_html( (string) ( $latest['created_at'] ?? $latest['started_at'] ?? '?' ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Completed at', 'iato-mcp' ); ?></td>
					<td><?php echo esc_html( (string) ( $latest['completed_at'] ?? $latest['finished_at'] ?? '—' ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Pages crawled', 'iato-mcp' ); ?></td>
					<td><?php echo esc_html( (string) ( $latest['pages_crawled'] ?? $latest['total_pages'] ?? '?' ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>
			<?php if ( '' !== $crawl_err ) : ?>
				<div class="empty"><?php echo esc_html( $crawl_err ); ?></div>
			<?php elseif ( empty( $crawls ) ) : ?>
				<div class="empty"><?php esc_html_e( 'No crawls returned from IATO for this workspace.', 'iato-mcp' ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Section 3: Platform Activity Log ───────────────────────────────────

	private static function render_section_activity_log( string $workspace_id ): void {
		$result  = '' !== $workspace_id
			? IATO_MCP_IATO_Client::get_activity_log( $workspace_id, [ 'limit' => 50 ] )
			: new WP_Error( 'no_workspace', __( 'No workspace resolved. Configure the API key in Settings first.', 'iato-mcp' ) );

		$err     = is_wp_error( $result ) ? $result->get_error_message() : '';
		$entries = [];
		$total   = 0;
		if ( ! is_wp_error( $result ) && is_array( $result ) ) {
			$inner   = $result['data'] ?? $result;
			$entries = $inner['items'] ?? $inner['entries'] ?? [];
			if ( ! is_array( $entries ) ) {
				$entries = [];
			}
			$total = (int) ( $inner['total'] ?? $result['total'] ?? count( $entries ) );
		}

		$status = empty( $entries ) ? 'warn' : 'ok';

		?>
		<div class="section">
			<h2>
				<span class="status-dot dot-<?php echo esc_attr( $status ); ?>"></span>
				<?php esc_html_e( '3. Platform Activity Log (raw)', 'iato-mcp' ); ?>
				<small style="color:#6b7280;font-weight:normal">— <?php echo esc_html( sprintf( 'total: %d', $total ) ); ?></small>
			</h2>
			<?php if ( '' !== $err ) : ?>
				<div class="empty"><?php echo esc_html( $err ); ?></div>
			<?php elseif ( empty( $entries ) ) : ?>
				<div class="empty big">
					<?php esc_html_e( 'The IATO platform has NOT recorded any Autopilot activity for this workspace. This means the platform has not processed any fixes yet, or Autopilot is not firing. Check Section 2 (Current Crawl) to see if a crawl has actually completed, and Section 5 (Incoming MCP Call Log) to see if the platform is calling back into this site.', 'iato-mcp' ); ?>
				</div>
			<?php else : ?>
				<table class="diag-list">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Action', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Issue type', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Field', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Page', 'iato-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						if ( ! is_array( $entry ) ) {
							continue;
						}
						$action = (string) ( $entry['action'] ?? $entry['status'] ?? 'unknown' );
						$time   = (string) ( $entry['created_at'] ?? $entry['applied_at'] ?? $entry['timestamp'] ?? '' );
						$issue  = (string) ( $entry['issue_type'] ?? $entry['type'] ?? '' );
						$field  = (string) ( $entry['field'] ?? '' );
						$page   = (string) ( $entry['page_url'] ?? $entry['url'] ?? '' );
						?>
						<tr>
							<td><?php echo esc_html( $time ); ?></td>
							<td><span class="status-badge <?php echo esc_attr( $action ); ?>"><?php echo esc_html( $action ); ?></span></td>
							<td><?php echo esc_html( $issue ); ?></td>
							<td><?php echo esc_html( $field ); ?></td>
							<td><span class="truncate" title="<?php echo esc_attr( $page ); ?>"><?php echo esc_html( $page ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Section 4: Platform Queue ──────────────────────────────────────────

	private static function render_section_queue( string $workspace_id ): void {
		$result = '' !== $workspace_id
			? IATO_MCP_IATO_Client::get_queue( $workspace_id, [ 'limit' => 50 ] )
			: new WP_Error( 'no_workspace', __( 'No workspace resolved.', 'iato-mcp' ) );

		$err   = is_wp_error( $result ) ? $result->get_error_message() : '';
		$inner = is_wp_error( $result ) ? [] : ( $result['data'] ?? $result );
		$items = is_wp_error( $result ) ? [] : ( $inner['items'] ?? $inner['queue'] ?? [] );
		if ( ! is_array( $items ) ) {
			$items = [];
		}

		$status = empty( $items ) ? 'idle' : 'ok';

		?>
		<div class="section">
			<h2>
				<span class="status-dot dot-<?php echo esc_attr( $status ); ?>"></span>
				<?php esc_html_e( '4. Platform Queue (raw, all statuses)', 'iato-mcp' ); ?>
				<small style="color:#6b7280;font-weight:normal">— <?php echo esc_html( sprintf( 'items: %d', count( $items ) ) ); ?></small>
			</h2>
			<?php if ( '' !== $err ) : ?>
				<div class="empty"><?php echo esc_html( $err ); ?></div>
			<?php elseif ( empty( $items ) ) : ?>
				<div class="empty"><?php esc_html_e( 'No queue items on the IATO platform.', 'iato-mcp' ); ?></div>
			<?php else : ?>
				<table class="diag-list">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Issue type', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Page', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Created', 'iato-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php $s = (string) ( $item['status'] ?? 'unknown' ); ?>
						<tr>
							<td><span class="status-badge <?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></span></td>
							<td><?php echo esc_html( (string) ( $item['issue_type'] ?? '' ) ); ?></td>
							<td><span class="truncate" title="<?php echo esc_attr( (string) ( $item['page_url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $item['page_url'] ?? '' ) ); ?></span></td>
							<td><?php echo esc_html( (string) ( $item['created_at'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Section 5: Local MCP Call Log ──────────────────────────────────────

	private static function render_section_call_log(): void {
		$entries = IATO_MCP_Call_Log::get_recent( 50 );
		$total   = IATO_MCP_Call_Log::count();
		$status  = empty( $entries ) ? 'warn' : 'ok';

		?>
		<div class="section">
			<h2>
				<span class="status-dot dot-<?php echo esc_attr( $status ); ?>"></span>
				<?php esc_html_e( '5. Incoming MCP Call Log (local)', 'iato-mcp' ); ?>
				<small style="color:#6b7280;font-weight:normal">— <?php echo esc_html( sprintf( 'total: %d', $total ) ); ?></small>
			</h2>
			<?php if ( empty( $entries ) ) : ?>
				<div class="empty big">
					<?php esc_html_e( 'No incoming MCP calls have been received by this site yet. If you expect Autopilot to be running, either (1) the IATO platform is not firing callbacks, or (2) its callbacks are not reaching your site (firewall, DNS, or auth). If section 3 above is also empty, the platform has no record of activity — check that a crawl has completed.', 'iato-mcp' ); ?>
				</div>
			<?php else : ?>
				<table class="diag-list">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Method', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'Error', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'ms', 'iato-mcp' ); ?></th>
							<th><?php esc_html_e( 'IP', 'iato-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $row ) : ?>
						<?php $s = (string) ( $row['response_status'] ?? 'unknown' ); ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $row['rpc_method'] ?? '' ) ); ?></code></td>
							<td><code><?php echo esc_html( (string) ( $row['tool_name'] ?? '' ) ); ?></code></td>
							<td><span class="status-badge <?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></span></td>
							<td><span class="truncate" title="<?php echo esc_attr( (string) ( $row['error_message'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $row['error_message'] ?? '' ) ); ?></span></td>
							<td><?php echo esc_html( (string) ( $row['duration_ms'] ?? '0' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $row['ip_address'] ?? '' ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<div class="section-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				      onsubmit="return confirm('<?php echo esc_js( __( 'Clear the MCP call log? This cannot be undone.', 'iato-mcp' ) ); ?>');">
					<input type="hidden" name="action" value="iato_mcp_diag_clear_log" />
					<?php wp_nonce_field( 'iato_mcp_diag_clear_log' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Clear Log', 'iato-mcp' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	// ── Section 6: Governance Policy ───────────────────────────────────────

	private static function render_section_policy( string $workspace_id ): void {
		$local    = get_option( 'iato_mcp_governance_policy', [] );
		$synced_at = get_option( 'iato_mcp_policy_synced_at', '' );

		$remote_raw = '' !== $workspace_id
			? IATO_MCP_IATO_Client::get_governance_policy( $workspace_id )
			: new WP_Error( 'no_workspace', __( 'No workspace resolved.', 'iato-mcp' ) );
		$remote_err = is_wp_error( $remote_raw ) ? $remote_raw->get_error_message() : '';
		$remote     = is_wp_error( $remote_raw ) ? [] : ( $remote_raw['policy'] ?? $remote_raw['data'] ?? $remote_raw );
		if ( ! is_array( $remote ) ) {
			$remote = [];
		}

		$local_keys  = is_array( $local ) ? array_keys( $local ) : [];
		$remote_keys = array_keys( $remote );
		$match       = '' === $remote_err && $local_keys === $remote_keys && self::policies_equal( $local, $remote );

		?>
		<div class="section">
			<h2>
				<span class="status-dot dot-<?php echo $match ? 'ok' : 'warn'; ?>"></span>
				<?php esc_html_e( '6. Governance Policy', 'iato-mcp' ); ?>
				<?php if ( '' === $remote_err ) : ?>
					<?php if ( $match ) : ?>
						<span class="match"><?php esc_html_e( 'local = remote', 'iato-mcp' ); ?></span>
					<?php else : ?>
						<span class="mismatch"><?php esc_html_e( 'MISMATCH', 'iato-mcp' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</h2>

			<table class="diag-kv">
				<tr>
					<td><?php esc_html_e( 'Last synced', 'iato-mcp' ); ?></td>
					<td><?php echo '' !== $synced_at ? esc_html( $synced_at ) : '<em>never</em>'; ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Local rules', 'iato-mcp' ); ?></td>
					<td><code><?php echo esc_html( (string) count( $local_keys ) ); ?></code> <?php esc_html_e( 'rule(s)', 'iato-mcp' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Remote rules', 'iato-mcp' ); ?></td>
					<td>
						<?php if ( '' !== $remote_err ) : ?>
							<em style="color:#991b1b"><?php echo esc_html( $remote_err ); ?></em>
						<?php else : ?>
							<code><?php echo esc_html( (string) count( $remote_keys ) ); ?></code> <?php esc_html_e( 'rule(s)', 'iato-mcp' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( ! $match && '' === $remote_err && ! empty( $remote_keys ) ) : ?>
				<div class="section-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="iato_mcp_diag_sync_policy" />
						<?php wp_nonce_field( 'iato_mcp_diag_sync_policy' ); ?>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Pull remote policy → local', 'iato-mcp' ); ?>
						</button>
					</form>
					<p class="description" style="margin-top:8px">
						<?php esc_html_e( 'Replaces the local policy with whatever the IATO platform currently has. Use this when the rule counts differ.', 'iato-mcp' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Compare two policy arrays structurally (action per rule).
	 */
	private static function policies_equal( array $a, array $b ): bool {
		$norm = static function ( array $p ): array {
			$out = [];
			foreach ( $p as $k => $v ) {
				if ( is_array( $v ) ) {
					$out[ $k ] = $v['action'] ?? '';
				} else {
					$out[ $k ] = (string) $v;
				}
			}
			ksort( $out );
			return $out;
		};
		return $norm( $a ) === $norm( $b );
	}

	// ── Admin post handlers ────────────────────────────────────────────────

	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}
		check_admin_referer( 'iato_mcp_diag_clear_log' );

		IATO_MCP_Call_Log::purge();

		set_transient(
			'iato_mcp_diag_notice',
			[ 'type' => 'success', 'message' => __( 'MCP call log cleared.', 'iato-mcp' ) ],
			30
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public static function handle_sync_policy(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}
		check_admin_referer( 'iato_mcp_diag_sync_policy' );

		$workspace_id = IATO_MCP_IATO_Client::resolve_workspace_id();
		if ( '' === $workspace_id ) {
			set_transient(
				'iato_mcp_diag_notice',
				[ 'type' => 'error', 'message' => __( 'Cannot sync policy: no workspace resolved.', 'iato-mcp' ) ],
				30
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		$remote = IATO_MCP_IATO_Client::get_governance_policy( $workspace_id );
		if ( is_wp_error( $remote ) ) {
			set_transient(
				'iato_mcp_diag_notice',
				[
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s is the error message. */
						__( 'Failed to fetch remote policy: %s', 'iato-mcp' ),
						$remote->get_error_message()
					),
				],
				30
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		$policy = $remote['policy'] ?? $remote['data'] ?? $remote;
		if ( ! is_array( $policy ) ) {
			$policy = [];
		}

		update_option( 'iato_mcp_governance_policy', $policy );
		update_option( 'iato_mcp_policy_synced_at', current_time( 'mysql', true ) );

		set_transient(
			'iato_mcp_diag_notice',
			[
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %d is the number of rules pulled. */
					_n( 'Pulled %d rule from remote policy.', 'Pulled %d rules from remote policy.', count( $policy ), 'iato-mcp' ),
					count( $policy )
				),
			],
			30
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	public static function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}
		check_admin_referer( 'iato_mcp_diag_test_conn' );

		$result = IATO_MCP_IATO_Client::list_workspaces();

		if ( is_wp_error( $result ) ) {
			set_transient(
				'iato_mcp_diag_notice',
				[
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s is the error message. */
						__( 'Connection test failed: %s', 'iato-mcp' ),
						$result->get_error_message()
					),
				],
				30
			);
		} else {
			$ws    = $result['workspaces'] ?? $result['data'] ?? $result;
			$count = is_array( $ws ) ? count( $ws ) : 0;
			update_option( 'iato_mcp_last_api_ok_at', current_time( 'mysql', true ) );
			set_transient(
				'iato_mcp_diag_notice',
				[
					'type'    => 'success',
					'message' => sprintf(
						/* translators: %d is the number of workspaces. */
						_n( 'Connection OK. %d workspace returned.', 'Connection OK. %d workspaces returned.', $count, 'iato-mcp' ),
						$count
					),
				],
				30
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}

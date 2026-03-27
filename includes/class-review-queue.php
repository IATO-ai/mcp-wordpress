<?php
/**
 * Review Queue Admin Page — surfaces pending IATO change queue items in wp-admin.
 *
 * All IATO API calls are proxied server-side (API key never exposed to browser).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Review_Queue {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );

		// AJAX handlers.
		add_action( 'wp_ajax_iato_mcp_review_action', [ __CLASS__, 'ajax_action' ] );
		add_action( 'wp_ajax_iato_mcp_review_bulk', [ __CLASS__, 'ajax_bulk' ] );
	}

	/**
	 * Register the top-level admin page.
	 */
	public static function register_page(): void {
		add_menu_page(
			__( 'IATO Review Queue', 'iato-mcp' ),
			__( 'IATO Reviews', 'iato-mcp' ),
			'edit_posts',
			'iato-review-queue',
			[ __CLASS__, 'render' ],
			'dashicons-feedback',
			80
		);
	}

	/**
	 * Render the review queue page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( __( 'Unauthorized.', 'iato-mcp' ) );
		}

		$api_key      = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$workspace_id = ! empty( $api_key ) ? IATO_MCP_IATO_Client::resolve_workspace_id() : '';
		$nonce        = wp_create_nonce( 'iato_mcp_review' );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'IATO Review Queue', 'iato-mcp' ); ?></h1>

			<style>
				.iato-rq { max-width: 1100px; }
				.iato-rq-notice { padding: 12px 16px; border-left: 4px solid #dba617; background: #fcf9e8; margin: 16px 0; border-radius: 2px; }
				.iato-rq-notice a { color: #2271b1; }
				.iato-rq-actions-bar { display: flex; justify-content: space-between; align-items: center; margin: 16px 0; flex-wrap: wrap; gap: 8px; }
				.iato-rq-filters { display: flex; gap: 8px; }
				.iato-rq-filters select { padding: 4px 8px; }
				.iato-rq-bulk .button { margin-left: 4px; }
				.iato-rq-group { margin-bottom: 24px; }
				.iato-rq-group-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f6f7f7; border: 1px solid #c3c4c7; border-bottom: none; border-radius: 4px 4px 0 0; cursor: pointer; }
				.iato-rq-group-header h3 { margin: 0; font-size: 14px; }
				.iato-rq-group-header .count { color: #50575e; font-size: 13px; }
				.iato-rq-items { border: 1px solid #c3c4c7; border-radius: 0 0 4px 4px; }
				.iato-rq-item { display: flex; justify-content: space-between; align-items: flex-start; padding: 16px; border-bottom: 1px solid #f0f0f1; gap: 16px; }
				.iato-rq-item:last-child { border-bottom: none; }
				.iato-rq-item-info { flex: 1; min-width: 0; }
				.iato-rq-item-info .page-url { font-weight: 600; color: #1d2327; margin-bottom: 4px; }
				.iato-rq-item-info .current { color: #50575e; font-size: 13px; }
				.iato-rq-item-info .proposed { color: #1d2327; font-size: 13px; margin-top: 4px; padding: 8px; background: #f0f6fc; border-radius: 3px; }
				.iato-rq-item-info .confidence { display: inline-block; background: #dba617; color: #fff; padding: 1px 8px; border-radius: 10px; font-size: 11px; margin-top: 6px; }
				.iato-rq-item-actions { display: flex; gap: 4px; flex-shrink: 0; align-items: flex-start; }
				.iato-rq-item-actions .button-small { font-size: 12px; }
				.iato-rq-empty { text-align: center; padding: 60px 20px; color: #50575e; }
				.iato-rq-empty h2 { color: #1d2327; }
				.iato-rq-upsell { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 24px; margin-bottom: 20px; text-align: center; }
				.iato-rq-upsell h3 { margin-top: 0; }
				.iato-rq-upsell .cta { display: inline-block; padding: 10px 20px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600; }
				.iato-rq-upsell .cta:hover { background: #135e96; color: #fff; }
				.iato-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #c3c4c7; border-top-color: #2271b1; border-radius: 50%; animation: iato-spin 0.6s linear infinite; vertical-align: middle; }
				@keyframes iato-spin { to { transform: rotate(360deg); } }
				.iato-rq-loading { text-align: center; padding: 40px; }
			</style>

			<div class="iato-rq">

				<?php if ( empty( $api_key ) || empty( $workspace_id ) ) : ?>
					<!-- Upsell for unconnected users -->
					<div class="iato-rq-upsell">
						<h3><?php esc_html_e( 'The IATO MCP plugin is free and works with any LLM.', 'iato-mcp' ); ?></h3>
						<p><?php esc_html_e( 'Connect IATO to unlock Autopilot — your site fixes itself.', 'iato-mcp' ); ?></p>
						<p><?php esc_html_e( 'Automated SEO audits, auto-fixes for meta descriptions and alt text, a review queue for your approval, and full activity logs with one-click rollback.', 'iato-mcp' ); ?></p>
						<p><?php esc_html_e( 'Free for up to 500 pages. No credit card required.', 'iato-mcp' ); ?></p>
						<a href="https://iato.ai" target="_blank" class="cta"><?php esc_html_e( 'Get Started Free at iato.ai', 'iato-mcp' ); ?> &rarr;</a>
						<p style="margin-top:12px;font-size:13px">
							<?php esc_html_e( 'Already have an account?', 'iato-mcp' ); ?>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=iato-mcp' ) ); ?>"><?php esc_html_e( 'Connect IATO in Settings', 'iato-mcp' ); ?></a>
						</p>
					</div>
				<?php else : ?>

					<div class="iato-rq-notice">
						<?php esc_html_e( 'Showing all pending items for this workspace.', 'iato-mcp' ); ?>
						<a href="https://iato.ai" target="_blank"><?php esc_html_e( 'Learn more', 'iato-mcp' ); ?></a>
					</div>

					<!-- Filters and bulk actions -->
					<div class="iato-rq-actions-bar">
						<div class="iato-rq-filters">
							<select id="rq-filter-type">
								<option value=""><?php esc_html_e( 'All Types', 'iato-mcp' ); ?></option>
								<option value="title"><?php esc_html_e( 'Title', 'iato-mcp' ); ?></option>
								<option value="meta_description"><?php esc_html_e( 'Meta Description', 'iato-mcp' ); ?></option>
								<option value="alt_text"><?php esc_html_e( 'Alt Text', 'iato-mcp' ); ?></option>
								<option value="canonical"><?php esc_html_e( 'Canonical', 'iato-mcp' ); ?></option>
								<option value="h1"><?php esc_html_e( 'H1', 'iato-mcp' ); ?></option>
							</select>
						</div>
						<div class="iato-rq-bulk">
							<button class="button" id="rq-approve-all"><?php esc_html_e( 'Approve All', 'iato-mcp' ); ?></button>
							<button class="button" id="rq-reject-all"><?php esc_html_e( 'Reject All', 'iato-mcp' ); ?></button>
							<button class="button" id="rq-fix-all"><?php esc_html_e( 'Mark All Fixed', 'iato-mcp' ); ?></button>
						</div>
					</div>

					<div id="rq-content">
						<div class="iato-rq-loading"><span class="iato-spinner"></span> <?php esc_html_e( 'Loading review queue...', 'iato-mcp' ); ?></div>
					</div>

					<script>
					(function(){
						const nonce = <?php echo wp_json_encode( $nonce ); ?>;
						const ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
						const workspaceId = <?php echo wp_json_encode( $workspace_id ); ?>;
						const siteUrl = <?php echo wp_json_encode( site_url() ); ?>;

						function loadQueue() {
							fetch(ajaxurl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
								body: new URLSearchParams({
									action: 'iato_mcp_review_action',
									_wpnonce: nonce,
									op: 'list',
									workspace_id: workspaceId,
									site_url: siteUrl,
								})
							})
							.then(r => r.json())
							.then(r => {
								if (!r.success) { document.getElementById('rq-content').innerHTML = '<div class="iato-rq-empty"><p>' + (r.data || 'Failed to load.') + '</p></div>'; return; }
								renderQueue(r.data.items || []);
							})
							.catch(() => {
								document.getElementById('rq-content').innerHTML = '<div class="iato-rq-empty"><p>Failed to load review queue.</p></div>';
							});
						}

						function renderQueue(items) {
							const container = document.getElementById('rq-content');
							if (!items.length) {
								container.innerHTML = '<div class="iato-rq-empty"><h2>All clear!</h2><p>No pending items for review.</p></div>';
								return;
							}

							// Group by issue type.
							const groups = {};
							items.forEach(item => {
								const type = item.issue_type || item.field || 'other';
								if (!groups[type]) groups[type] = [];
								groups[type].push(item);
							});

							let html = '';
							for (const [type, groupItems] of Object.entries(groups)) {
								html += '<div class="iato-rq-group" data-type="' + type + '">';
								html += '<div class="iato-rq-group-header"><h3>' + escHtml(type.replace(/_/g, ' ')) + '</h3><span class="count">' + groupItems.length + ' items</span></div>';
								html += '<div class="iato-rq-items">';
								groupItems.forEach(item => {
									const cid = item.change_id || item.id || '';
									html += '<div class="iato-rq-item" data-id="' + cid + '">';
									html += '<div class="iato-rq-item-info">';
									html += '<div class="page-url">' + escHtml(item.page_url || item.url || '') + '</div>';
									html += '<div class="current">Current: ' + escHtml(item.current_value || '(none)') + '</div>';
									html += '<div class="proposed">Proposed: ' + escHtml(item.proposed_value || item.value || '') + '</div>';
									if (item.confidence) html += '<span class="confidence">' + item.confidence + '%</span>';
									html += '</div>';
									html += '<div class="iato-rq-item-actions">';
									if (item.post_id) html += '<a href="' + <?php echo wp_json_encode( admin_url( 'post.php?action=edit&post=' ) ); ?> + item.post_id + '" class="button button-small">Edit Post</a>';
									html += '<button class="button button-small" onclick="rqAction(\'mark_fixed\',\'' + cid + '\',this)">Mark as Fixed</button>';
									html += '<button class="button button-small" onclick="rqAction(\'reject\',\'' + cid + '\',this)">Reject</button>';
									html += '<button class="button button-primary button-small" onclick="rqAction(\'approve\',\'' + cid + '\',this)">Approve</button>';
									html += '</div></div>';
								});
								html += '</div></div>';
							}
							container.innerHTML = html;

							// Apply type filter.
							applyFilter();
						}

						function applyFilter() {
							const filter = document.getElementById('rq-filter-type').value;
							document.querySelectorAll('.iato-rq-group').forEach(g => {
								g.style.display = (!filter || g.dataset.type === filter) ? '' : 'none';
							});
						}
						document.getElementById('rq-filter-type').addEventListener('change', applyFilter);

						window.rqAction = function(op, changeId, btn) {
							if (op === 'mark_fixed') {
								const notes = prompt('Optional notes:');
								if (notes === null) return;
								doAction(op, changeId, btn, notes);
							} else {
								doAction(op, changeId, btn);
							}
						};

						function doAction(op, changeId, btn, notes) {
							if (btn) btn.disabled = true;
							fetch(ajaxurl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
								body: new URLSearchParams({
									action: 'iato_mcp_review_action',
									_wpnonce: nonce,
									op: op,
									change_id: changeId,
									notes: notes || '',
								})
							})
							.then(r => r.json())
							.then(r => {
								if (r.success) {
									const row = btn ? btn.closest('.iato-rq-item') : null;
									if (row) row.remove();
								} else {
									alert(r.data || 'Action failed.');
									if (btn) btn.disabled = false;
								}
							});
						}

						// Bulk actions.
						['approve-all', 'reject-all', 'fix-all'].forEach(id => {
							document.getElementById('rq-' + id).addEventListener('click', function() {
								const opMap = { 'approve-all': 'approve_batch', 'reject-all': 'reject_batch', 'fix-all': 'mark_batch_fixed' };
								let notes = '';
								if (id === 'fix-all') notes = prompt('Optional notes for all items:') || '';
								this.disabled = true;
								fetch(ajaxurl, {
									method: 'POST',
									headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
									body: new URLSearchParams({
										action: 'iato_mcp_review_bulk',
										_wpnonce: nonce,
										op: opMap[id],
										workspace_id: workspaceId,
										notes: notes,
									})
								})
								.then(r => r.json())
								.then(r => {
									this.disabled = false;
									if (r.success) loadQueue();
									else alert(r.data || 'Bulk action failed.');
								});
							});
						});

						function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

						loadQueue();
					})();
					</script>

				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ── AJAX Handlers ────────────────────────────────────────────────────────

	/**
	 * Handle individual item actions + list fetch.
	 */
	public static function ajax_action(): void {
		check_ajax_referer( 'iato_mcp_review', '_wpnonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$op        = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		$change_id = sanitize_text_field( wp_unslash( $_POST['change_id'] ?? '' ) );
		$notes     = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

		switch ( $op ) {
			case 'list':
				$workspace_id = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
				$site_url     = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );

				if ( empty( $workspace_id ) ) {
					wp_send_json_error( 'workspace_id required.' );
				}

				$result = IATO_MCP_IATO_Client::get_change_queue( $workspace_id, [
					'status'   => 'pending_review',
					'site_url' => $site_url,
				] );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}

				wp_send_json_success( $result );
				break;

			case 'approve':
				if ( empty( $change_id ) ) wp_send_json_error( 'change_id required.' );
				$result = IATO_MCP_IATO_Client::approve_change( $change_id );
				break;

			case 'reject':
				if ( empty( $change_id ) ) wp_send_json_error( 'change_id required.' );
				$result = IATO_MCP_IATO_Client::reject_change( $change_id );
				break;

			case 'mark_fixed':
				if ( empty( $change_id ) ) wp_send_json_error( 'change_id required.' );
				$result = IATO_MCP_IATO_Client::mark_as_fixed( $change_id, $notes );
				break;

			default:
				wp_send_json_error( 'Unknown operation.' );
				return;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle bulk actions.
	 */
	public static function ajax_bulk(): void {
		check_ajax_referer( 'iato_mcp_review', '_wpnonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$op           = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		$workspace_id = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
		$notes        = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

		if ( empty( $workspace_id ) ) {
			wp_send_json_error( 'workspace_id required.' );
		}

		// Use workspace_id as batch_id (IATO batches by workspace for pending items).
		$batch_id = $workspace_id;

		switch ( $op ) {
			case 'approve_batch':
				$result = IATO_MCP_IATO_Client::approve_batch( $batch_id );
				break;
			case 'reject_batch':
				$result = IATO_MCP_IATO_Client::reject_batch( $batch_id );
				break;
			case 'mark_batch_fixed':
				$result = IATO_MCP_IATO_Client::mark_batch_as_fixed( $batch_id, $notes );
				break;
			default:
				wp_send_json_error( 'Unknown bulk operation.' );
				return;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}
}

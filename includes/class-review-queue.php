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
			IATO_MCP_URL . 'icon-white.png',
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
		$api_valid    = (bool) get_option( 'iato_mcp_api_key_valid', false );
		$workspace_id = ( ! empty( $api_key ) && $api_valid ) ? IATO_MCP_IATO_Client::resolve_workspace_id() : '';
		$nonce        = wp_create_nonce( 'iato_mcp_review' );

		wp_enqueue_style( 'iato-mcp-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono&display=swap', [], null );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline" style="font-family: 'Instrument Serif', Georgia, serif; font-weight: 400;"><?php esc_html_e( 'IATO Review Queue', 'iato-mcp' ); ?></h1>

			<style>
				.iato-rq { max-width: 1100px; font-family: 'DM Sans', system-ui, sans-serif; }
				.iato-rq-notice { padding: 12px 16px; border-left: 4px solid #eda145; background: rgba(237,161,69,0.12); margin: 16px 0; border-radius: 8px; }
				.iato-rq-notice a { color: #5a89f4; }
				.iato-rq-actions-bar { display: flex; justify-content: space-between; align-items: center; margin: 16px 0; flex-wrap: wrap; gap: 8px; }
				.iato-rq-filters { display: flex; gap: 8px; }
				.iato-rq-filters select { padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-family: 'DM Sans', system-ui, sans-serif; font-size: 14px; transition: border-color 0.15s, box-shadow 0.15s; }
				.iato-rq-filters select:focus { border-color: #5a89f4; box-shadow: 0 0 0 2px rgba(90,137,244,0.1); outline: none; }
				.iato-rq-bulk .button { margin-left: 4px; border-radius: 8px; transition: all 0.2s; }
				.iato-rq-group { margin-bottom: 24px; }
				.iato-rq-group-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f3f4f6; border: 1px solid #e5e7eb; border-bottom: none; border-radius: 12px 12px 0 0; cursor: pointer; transition: background 0.15s; }
				.iato-rq-group-header:hover { background: #e5e7eb; }
				.iato-rq-group-header h3 { margin: 0; font-size: 14px; color: #111827; text-transform: capitalize; }
				.iato-rq-group-header .count { color: #6b7280; font-size: 13px; }
				.iato-rq-items { border: 1px solid #e5e7eb; border-radius: 0 0 12px 12px; background: #fff; }
				.iato-rq-item { display: flex; justify-content: space-between; align-items: flex-start; padding: 16px; border-bottom: 1px solid #e5e7eb; gap: 16px; transition: background 0.15s; }
				.iato-rq-item:hover { background: #f9fafb; }
				.iato-rq-item:last-child { border-bottom: none; }
				.iato-rq-item-info { flex: 1; min-width: 0; }
				.iato-rq-item-info .page-url { font-weight: 600; color: #111827; margin-bottom: 4px; }
				.iato-rq-item-info .current { color: #6b7280; font-size: 13px; }
				.iato-rq-item-info .proposed { color: #111827; font-size: 13px; margin-top: 4px; padding: 8px; background: rgba(90,137,244,0.08); border-radius: 8px; }
				.iato-rq-item-info .confidence { display: inline-flex; align-items: center; background: rgba(237,161,69,0.12); color: #eda145; padding: 4px 12px; border-radius: 99px; font-size: 12px; font-weight: 600; margin-top: 6px; }
				.iato-rq-item-actions { display: flex; gap: 4px; flex-shrink: 0; align-items: flex-start; }
				.iato-rq-item-actions .button-small { font-size: 12px; border-radius: 8px; transition: all 0.2s; }
				.iato-rq-item-actions .button-primary { background: #4b72cc; border-color: #4b72cc; box-shadow: 0 0 24px rgba(90,137,244,0.18); }
				.iato-rq-item-actions .button-primary:hover { background: #3f64b8; border-color: #3f64b8; box-shadow: 0 0 36px rgba(90,137,244,0.3); }
				.iato-rq-empty { text-align: center; padding: 60px 20px; color: #6b7280; }
				.iato-rq-empty h2 { color: #111827; font-family: 'Instrument Serif', Georgia, serif; font-weight: 400; }
				.iato-rq-upsell { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 20px; text-align: center; }
				.iato-rq-upsell h3 { margin-top: 0; color: #111827; }
				.iato-rq-upsell .cta { display: inline-block; padding: 8px 20px; background: #4b72cc; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 13.5px; box-shadow: 0 0 24px rgba(90,137,244,0.18); transition: all 0.2s; }
				.iato-rq-upsell .cta:hover { background: #3f64b8; color: #fff; box-shadow: 0 0 36px rgba(90,137,244,0.3); }
				.iato-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #e5e7eb; border-top-color: #5a89f4; border-radius: 50%; animation: iato-spin 0.6s linear infinite; vertical-align: middle; }
				@keyframes iato-spin { to { transform: rotate(360deg); } }
				.iato-rq-loading { text-align: center; padding: 40px; color: #6b7280; }
			</style>

			<div class="iato-rq">

				<?php if ( empty( $api_key ) ) : ?>
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
				<?php elseif ( ! $api_valid ) : ?>
					<!-- API key present but validation failed -->
					<div class="notice notice-error" style="margin: 16px 0; padding: 12px 16px;">
						<p><strong><?php esc_html_e( 'IATO API key is invalid.', 'iato-mcp' ); ?></strong></p>
						<p><?php esc_html_e( 'Your API key failed validation when it was saved. Please re-enter or regenerate your key in Settings.', 'iato-mcp' ); ?></p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=iato-mcp' ) ); ?>" class="button"><?php esc_html_e( 'Go to Settings', 'iato-mcp' ); ?></a>
							<a href="https://iato.ai" target="_blank" style="margin-left: 8px;"><?php esc_html_e( 'Get a new API key at iato.ai', 'iato-mcp' ); ?> &rarr;</a>
						</p>
					</div>
				<?php elseif ( empty( $workspace_id ) ) : ?>
					<!-- API key valid but no workspace found -->
					<div class="notice notice-warning" style="margin: 16px 0; padding: 12px 16px;">
						<p><strong><?php esc_html_e( 'No IATO workspace found.', 'iato-mcp' ); ?></strong></p>
						<p><?php esc_html_e( 'Your API key is valid but no workspaces were returned. Create a workspace at iato.ai first, then reload this page.', 'iato-mcp' ); ?></p>
						<p>
							<a href="https://iato.ai" target="_blank" class="button"><?php esc_html_e( 'Go to iato.ai', 'iato-mcp' ); ?> &rarr;</a>
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

							// Build filter options dynamically from item types.
							buildFilterOptions(items);

							// Apply type filter.
							applyFilter();
						}

						function buildFilterOptions(items) {
							const types = new Set();
							items.forEach(item => {
								types.add(item.issue_type || item.field || 'other');
							});
							const select = document.getElementById('rq-filter-type');
							const current = select.value;
							select.length = 1; // Keep "All Types" only.
							const labels = {
								title: 'Title', meta_description: 'Meta Description',
								alt_text: 'Alt Text', canonical: 'Canonical', h1: 'H1',
								content: 'Content', navigation: 'Navigation', menu: 'Navigation',
								taxonomy: 'Taxonomy', redirect: 'Redirects', link: 'Links',
								structure: 'Structure'
							};
							Array.from(types).sort().forEach(type => {
								const opt = document.createElement('option');
								opt.value = type;
								opt.textContent = labels[type] || type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
								select.appendChild(opt);
							});
							if (current) select.value = current;
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

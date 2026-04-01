<?php
/**
 * Review Queue Admin Page — surfaces pending IATO autopilot queue items
 * and activity history with rollback in wp-admin.
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

		// AJAX handler.
		add_action( 'wp_ajax_iato_mcp_review_action', [ __CLASS__, 'ajax_action' ] );
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

				/* Tabs */
				.iato-rq-tabs { display: flex; gap: 0; margin: 16px 0 0; border-bottom: 2px solid #e5e7eb; }
				.iato-rq-tab { padding: 10px 20px; font-size: 14px; font-weight: 500; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s; background: none; border-top: none; border-left: none; border-right: none; font-family: inherit; }
				.iato-rq-tab:hover { color: #111827; }
				.iato-rq-tab.active { color: #4b72cc; border-bottom-color: #4b72cc; font-weight: 600; }
				.iato-rq-tab .tab-count { background: #e5e7eb; color: #6b7280; padding: 2px 8px; border-radius: 99px; font-size: 12px; margin-left: 6px; }
				.iato-rq-tab.active .tab-count { background: rgba(75,114,204,0.12); color: #4b72cc; }

				.iato-rq-actions-bar { display: flex; justify-content: space-between; align-items: center; margin: 16px 0; flex-wrap: wrap; gap: 8px; }
				.iato-rq-filters { display: flex; gap: 8px; }
				.iato-rq-filters select { padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-family: 'DM Sans', system-ui, sans-serif; font-size: 14px; transition: border-color 0.15s, box-shadow 0.15s; }
				.iato-rq-filters select:focus { border-color: #5a89f4; box-shadow: 0 0 0 2px rgba(90,137,244,0.1); outline: none; }
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
				.iato-rq-item-info .item-meta { display: flex; gap: 8px; align-items: center; margin-top: 6px; font-size: 12px; color: #9ca3af; }
				.iato-rq-item-actions { display: flex; gap: 4px; flex-shrink: 0; align-items: flex-start; }
				.iato-rq-item-actions .button-small { font-size: 12px; border-radius: 8px; transition: all 0.2s; }
				.iato-rq-item-actions .button-primary { background: #4b72cc; border-color: #4b72cc; box-shadow: 0 0 24px rgba(90,137,244,0.18); }
				.iato-rq-item-actions .button-primary:hover { background: #3f64b8; border-color: #3f64b8; box-shadow: 0 0 36px rgba(90,137,244,0.3); }

				/* Status badges */
				.iato-status-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
				.iato-status-badge.approved { background: rgba(34,197,94,0.12); color: #16a34a; }
				.iato-status-badge.applied { background: rgba(90,137,244,0.12); color: #4b72cc; }
				.iato-status-badge.dismissed { background: #f3f4f6; color: #9ca3af; }
				.iato-status-badge.rolled_back { background: rgba(237,161,69,0.12); color: #d97706; }
				.iato-status-badge.pending_review { background: rgba(237,161,69,0.12); color: #eda145; }
				.iato-status-badge.pending { background: rgba(237,161,69,0.12); color: #eda145; }
				.iato-status-badge.rejected { background: rgba(239,68,68,0.12); color: #dc2626; }
				.iato-status-badge.failed { background: rgba(239,68,68,0.12); color: #dc2626; }

				.iato-rq-empty { text-align: center; padding: 60px 20px; color: #6b7280; }
				.iato-rq-empty h2 { color: #111827; font-family: 'Instrument Serif', Georgia, serif; font-weight: 400; }
				.iato-rq-upsell { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 20px; text-align: center; }
				.iato-rq-upsell h3 { margin-top: 0; color: #111827; }
				.iato-rq-upsell .cta { display: inline-block; padding: 8px 20px; background: #4b72cc; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 13.5px; box-shadow: 0 0 24px rgba(90,137,244,0.18); transition: all 0.2s; }
				.iato-rq-upsell .cta:hover { background: #3f64b8; color: #fff; box-shadow: 0 0 36px rgba(90,137,244,0.3); }
				.iato-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #e5e7eb; border-top-color: #5a89f4; border-radius: 50%; animation: iato-spin 0.6s linear infinite; vertical-align: middle; }
				@keyframes iato-spin { to { transform: rotate(360deg); } }
				.iato-rq-loading { text-align: center; padding: 40px; color: #6b7280; }

				/* Undo / rollback button */
				.iato-rq-item-actions .button-undo { color: #d97706; border-color: #d97706; }
				.iato-rq-item-actions .button-undo:hover { background: rgba(217,119,6,0.08); }

				/* Pagination */
				.iato-rq-pagination { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 16px 0; margin-top: 8px; }
				.iato-rq-pagination button { padding: 6px 16px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; font-family: 'DM Sans', system-ui, sans-serif; font-size: 13px; color: #374151; cursor: pointer; transition: all 0.15s; }
				.iato-rq-pagination button:hover:not(:disabled) { border-color: #4b72cc; color: #4b72cc; background: rgba(75,114,204,0.04); }
				.iato-rq-pagination button:disabled { opacity: 0.4; cursor: default; }
				.iato-rq-pagination .page-info { font-size: 13px; color: #6b7280; }
				.iato-rq-pagination .page-info strong { color: #111827; }
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

					<!-- Tabs -->
					<div class="iato-rq-tabs">
						<button class="iato-rq-tab active" data-tab="pending" id="rq-tab-pending">
							<?php esc_html_e( 'Pending Review', 'iato-mcp' ); ?>
							<span class="tab-count" id="rq-pending-count">-</span>
						</button>
						<button class="iato-rq-tab" data-tab="history" id="rq-tab-history">
							<?php esc_html_e( 'History', 'iato-mcp' ); ?>
							<span class="tab-count" id="rq-history-count"></span>
						</button>
					</div>

					<!-- Filters -->
					<div class="iato-rq-actions-bar">
						<div class="iato-rq-filters">
							<select id="rq-filter-type">
								<option value=""><?php esc_html_e( 'All Types', 'iato-mcp' ); ?></option>
							</select>
							<select id="rq-filter-status" style="display:none;">
								<option value=""><?php esc_html_e( 'All Statuses', 'iato-mcp' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending', 'iato-mcp' ); ?></option>
								<option value="approved"><?php esc_html_e( 'Approved', 'iato-mcp' ); ?></option>
								<option value="applied"><?php esc_html_e( 'Applied', 'iato-mcp' ); ?></option>
								<option value="dismissed"><?php esc_html_e( 'Dismissed', 'iato-mcp' ); ?></option>
								<option value="rejected"><?php esc_html_e( 'Rejected', 'iato-mcp' ); ?></option>
								<option value="rolled_back"><?php esc_html_e( 'Rolled Back', 'iato-mcp' ); ?></option>
								<option value="failed"><?php esc_html_e( 'Failed', 'iato-mcp' ); ?></option>
							</select>
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
						let currentTab = 'pending';
						let historyItems = [];
						let queuePage = 1, queuePages = 1, queueTotal = 0;
						let historyPage = 1, historyPages = 1, historyTotal = 0;

						// ── Tab switching ──────────────────────────────────

						document.querySelectorAll('.iato-rq-tab').forEach(tab => {
							tab.addEventListener('click', function() {
								document.querySelectorAll('.iato-rq-tab').forEach(t => t.classList.remove('active'));
								this.classList.add('active');
								currentTab = this.dataset.tab;

								const statusFilter = document.getElementById('rq-filter-status');
								if (currentTab === 'history') {
									statusFilter.style.display = '';
									historyPage = 1;
									loadHistory();
								} else {
									statusFilter.style.display = 'none';
									queuePage = 1;
									loadQueue();
								}
							});
						});

						// ── Pending Review ──────────────────────────────────

						function loadQueue() {
							document.getElementById('rq-content').innerHTML = '<div class="iato-rq-loading"><span class="iato-spinner"></span> Loading...</div>';
							fetch(ajaxurl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
								body: new URLSearchParams({
									action: 'iato_mcp_review_action',
									_wpnonce: nonce,
									op: 'list',
									workspace_id: workspaceId,
									page: queuePage,
								})
							})
							.then(r => r.json())
							.then(r => {
								if (!r.success) { document.getElementById('rq-content').innerHTML = '<div class="iato-rq-empty"><p>' + (r.data || 'Failed to load.') + '</p></div>'; return; }
								const d = r.data || {};
								const items = d.items || [];
								queueTotal = d.total || items.length;
								queuePages = d.pages || 1;
								queuePage = d.page || queuePage;
								document.getElementById('rq-pending-count').textContent = queueTotal;
								renderQueue(items);
							})
							.catch(() => {
								document.getElementById('rq-content').innerHTML = '<div class="iato-rq-empty"><p>Failed to load review queue.</p></div>';
							});
						}

						function renderQueue(items) {
							const container = document.getElementById('rq-content');
							if (!items.length && queuePage === 1) {
								container.innerHTML = '<div class="iato-rq-empty"><h2>All clear!</h2><p>No pending items for review.</p></div>';
								return;
							}

							const groups = {};
							items.forEach(item => {
								const type = item.issue_type || 'other';
								if (!groups[type]) groups[type] = [];
								groups[type].push(item);
							});

							let html = '';
							for (const [type, groupItems] of Object.entries(groups)) {
								html += '<div class="iato-rq-group" data-type="' + type + '">';
								html += '<div class="iato-rq-group-header"><h3>' + escHtml(type.replace(/_/g, ' ')) + '</h3><span class="count">' + groupItems.length + ' items</span></div>';
								html += '<div class="iato-rq-items">';
								groupItems.forEach(item => {
									const cid = item.id || '';
									html += '<div class="iato-rq-item" data-id="' + cid + '">';
									html += '<div class="iato-rq-item-info">';
									html += '<div class="page-url">' + escHtml(item.page_url || '') + '</div>';
									html += '<div class="current">Current: ' + escHtml(item.before_value || '(none)') + '</div>';
									html += '<div class="proposed">Proposed: ' + escHtml(item.proposed_value || '') + '</div>';
									html += '</div>';
									html += '<div class="iato-rq-item-actions">';
									html += '<button class="button button-small" onclick="rqAction(\'dismiss\',\'' + cid + '\',this)">Dismiss</button>';
									html += '<button class="button button-primary button-small" onclick="rqAction(\'approve\',\'' + cid + '\',this)">Approve</button>';
									html += '</div></div>';
								});
								html += '</div></div>';
							}

							html += renderPagination(queuePage, queuePages, queueTotal, 'queue');
							container.innerHTML = html;

							buildFilterOptions(items, 'rq-filter-type');
							applyFilters();

							// Bind pagination buttons
							bindPaginationEvents('queue');
						}

						// ── Activity History ──────────────────────────────────

						function loadHistory() {
							document.getElementById('rq-content').innerHTML = '<div class="iato-rq-loading"><span class="iato-spinner"></span> Loading history...</div>';
							const statusVal = document.getElementById('rq-filter-status').value;
							const params = {
								action: 'iato_mcp_review_action',
								_wpnonce: nonce,
								op: 'history',
								workspace_id: workspaceId,
								page: historyPage,
							};
							if (statusVal) params.status_filter = statusVal;

							fetch(ajaxurl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
								body: new URLSearchParams(params)
							})
							.then(r => r.json())
							.then(r => {
								if (!r.success) { document.getElementById('rq-content').innerHTML = '<div class="iato-rq-empty"><p>' + (r.data || 'Failed to load history.') + '</p></div>'; return; }
								const d = r.data || {};
								historyItems = Array.isArray(d) ? d : (d.entries || d.items || (Array.isArray(d.data) ? d.data : []));
								historyTotal = d.total || historyItems.length;
								historyPages = d.pages || 1;
								historyPage = d.page || historyPage;
								document.getElementById('rq-history-count').textContent = historyTotal;
								renderHistory(historyItems);
							})
							.catch(() => {
								document.getElementById('rq-content').innerHTML = '<div class="iato-rq-empty"><p>Failed to load activity history.</p></div>';
							});
						}

						function renderHistory(items) {
							const container = document.getElementById('rq-content');
							if (!items.length && historyPage === 1) {
								container.innerHTML = '<div class="iato-rq-empty"><h2>No history yet</h2><p>Activity will appear here once Autopilot starts processing items.</p></div>';
								return;
							}

							let html = '';
							items.forEach(item => {
								const status = item.action || item.status || 'unknown';
								const changeId = item.change_queue_id || item.change_id || item.id || '';
								html += '<div class="iato-rq-item" data-id="' + (item.id || '') + '" data-status="' + status + '">';
								html += '<div class="iato-rq-item-info">';
								html += '<div class="page-url">' + escHtml(item.page_url || '') + '</div>';
								html += '<div class="current">';
								html += '<span style="font-weight:500;color:#374151;">' + escHtml((item.issue_type || '').replace(/_/g, ' ')) + '</span>';
								if (item.field && item.field !== 'unknown') html += ' &middot; ' + escHtml(item.field);
								html += '</div>';
								if (item.before_value || item.after_value) {
									html += '<div class="current">Before: ' + escHtml(item.before_value || '(none)') + '</div>';
									html += '<div class="proposed">After: ' + escHtml(item.after_value || item.proposed_value || '(none)') + '</div>';
								}
								html += '<div class="item-meta">';
								html += '<span class="iato-status-badge ' + status + '">' + escHtml(status.replace(/_/g, ' ')) + '</span>';
								html += '<span>' + escHtml(item.source || '') + '</span>';
								if (item.created_at) html += '<span>' + timeAgo(item.created_at) + '</span>';
								html += '</div>';
								html += '</div>';
								html += '<div class="iato-rq-item-actions">';
								if (status === 'applied' && changeId) {
									html += '<button class="button button-small button-undo" onclick="rqRollback(\'' + escAttr(changeId) + '\',this)">Undo</button>';
								}
								html += '</div></div>';
							});

							html += renderPagination(historyPage, historyPages, historyTotal, 'history');
							container.innerHTML = html;

							bindPaginationEvents('history');
						}

						// ── Pagination ──────────────────────────────────

						function renderPagination(page, pages, total, tabName) {
							if (pages <= 1) return '';
							let html = '<div class="iato-rq-pagination" data-tab="' + tabName + '">';
							html += '<button class="pg-prev"' + (page <= 1 ? ' disabled' : '') + '>&laquo; Previous</button>';
							html += '<span class="page-info">Page <strong>' + page + '</strong> of <strong>' + pages + '</strong> &middot; ' + total.toLocaleString() + ' total</span>';
							html += '<button class="pg-next"' + (page >= pages ? ' disabled' : '') + '>Next &raquo;</button>';
							html += '</div>';
							return html;
						}

						function bindPaginationEvents(tabName) {
							const pagination = document.querySelector('.iato-rq-pagination[data-tab="' + tabName + '"]');
							if (!pagination) return;
							const prev = pagination.querySelector('.pg-prev');
							const next = pagination.querySelector('.pg-next');
							if (prev) prev.addEventListener('click', function() {
								if (tabName === 'queue' && queuePage > 1) { queuePage--; loadQueue(); }
								if (tabName === 'history' && historyPage > 1) { historyPage--; loadHistory(); }
							});
							if (next) next.addEventListener('click', function() {
								if (tabName === 'queue' && queuePage < queuePages) { queuePage++; loadQueue(); }
								if (tabName === 'history' && historyPage < historyPages) { historyPage++; loadHistory(); }
							});
						}

						// ── Shared Utilities ──────────────────────────────────

						function buildFilterOptions(items, selectId) {
							const types = new Set();
							items.forEach(item => {
								types.add(item.issue_type || item.field || 'other');
							});
							const select = document.getElementById(selectId);
							const current = select.value;
							select.length = 1;
							const labels = {
								title: 'Title', meta_description: 'Meta Description',
								alt_text: 'Alt Text', canonical: 'Canonical', h1: 'H1',
								content: 'Content', navigation: 'Navigation', menu: 'Navigation',
								taxonomy: 'Taxonomy', redirect: 'Redirects', link: 'Links',
								structure: 'Structure', nofollow_meta: 'Nofollow Meta',
								short_title: 'Short Title', missing_meta_description: 'Missing Meta Description',
								missing_alt_text: 'Missing Alt Text',
							};
							Array.from(types).sort().forEach(type => {
								const opt = document.createElement('option');
								opt.value = type;
								opt.textContent = labels[type] || type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
								select.appendChild(opt);
							});
							if (current) select.value = current;
						}

						function applyFilters() {
							const typeFilter = document.getElementById('rq-filter-type').value;
							const statusFilter = document.getElementById('rq-filter-status').value;

							document.querySelectorAll('.iato-rq-group').forEach(g => {
								if (typeFilter && g.dataset.type !== typeFilter) { g.style.display = 'none'; return; }
								g.style.display = '';

								if (currentTab === 'history' && statusFilter) {
									g.querySelectorAll('.iato-rq-item').forEach(item => {
										item.style.display = item.dataset.status === statusFilter ? '' : 'none';
									});
								} else {
									g.querySelectorAll('.iato-rq-item').forEach(item => { item.style.display = ''; });
								}
							});
						}

						document.getElementById('rq-filter-type').addEventListener('change', applyFilters);
						document.getElementById('rq-filter-status').addEventListener('change', function() {
							if (currentTab === 'history') {
								historyPage = 1;
								loadHistory();
							}
						});

						// ── Actions ──────────────────────────────────

						window.rqAction = function(op, changeId, btn) {
							if (btn) btn.disabled = true;
							fetch(ajaxurl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
								body: new URLSearchParams({
									action: 'iato_mcp_review_action',
									_wpnonce: nonce,
									op: op,
									change_id: changeId,
									workspace_id: workspaceId,
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
						};

						window.rqRollback = function(changeId, btn) {
							if (!confirm('Undo this change? The original value will be restored.')) return;
							if (btn) btn.disabled = true;
							btn.textContent = 'Undoing...';
							fetch(ajaxurl, {
								method: 'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
								body: new URLSearchParams({
									action: 'iato_mcp_review_action',
									_wpnonce: nonce,
									op: 'rollback',
									change_id: changeId,
									workspace_id: workspaceId,
								})
							})
							.then(r => r.json())
							.then(r => {
								if (r.success) {
									const row = btn.closest('.iato-rq-item');
									if (row) {
										row.dataset.status = 'rolled_back';
										const badge = row.querySelector('.iato-status-badge');
										if (badge) { badge.className = 'iato-status-badge rolled_back'; badge.textContent = 'rolled back'; }
										btn.remove();
									}
								} else {
									alert(r.data || 'Rollback failed.');
									btn.disabled = false;
									btn.textContent = 'Undo';
								}
							});
						};

						function timeAgo(dateStr) {
							const now = new Date();
							const d = new Date(dateStr);
							const diff = Math.floor((now - d) / 1000);
							if (diff < 60) return 'just now';
							if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
							if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
							return Math.floor(diff / 86400) + 'd ago';
						}

						function displayVal(v) { if (v === null || v === undefined) return ''; if (typeof v === 'object') return JSON.stringify(v); return String(v); }
						function escHtml(s) { const str = displayVal(s); if (!str) return ''; const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
						function escAttr(s) { return displayVal(s).replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

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
	 * Handle queue actions: list, approve, dismiss, history, rollback.
	 */
	public static function ajax_action(): void {
		check_ajax_referer( 'iato_mcp_review', '_wpnonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$op           = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		$change_id    = sanitize_text_field( wp_unslash( $_POST['change_id'] ?? '' ) );
		$workspace_id = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
		$page         = max( 1, intval( $_POST['page'] ?? 1 ) );

		if ( empty( $workspace_id ) ) {
			wp_send_json_error( 'workspace_id required.' );
		}

		switch ( $op ) {
			case 'list':
				$result = IATO_MCP_IATO_Client::get_queue( $workspace_id, [
					'status' => 'pending_review',
					'limit'  => 50,
					'page'   => $page,
				] );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}

				// IATO returns { success, data: { items, total, page, pages } }.
				// Extract inner data so JS receives it directly via wp_send_json_success().
				$data = $result['data'] ?? $result;
				wp_send_json_success( $data );
				break;

			case 'history':
				$params = [
					'limit' => 50,
					'page'  => $page,
				];

				$status_filter = sanitize_text_field( wp_unslash( $_POST['status_filter'] ?? '' ) );
				if ( ! empty( $status_filter ) ) {
					$params['action'] = $status_filter;
				}

				$result = IATO_MCP_IATO_Client::get_activity_log( $workspace_id, $params );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}

				// Guard against API error responses that slip through as arrays.
				if ( ! empty( $result['code'] ) && 'NOT_FOUND' === ( $result['code'] ?? '' ) ) {
					wp_send_json_error( $result['message'] ?? 'Activity endpoint not found.' );
				}

				$data = $result['data'] ?? $result;
				wp_send_json_success( $data );
				break;

			case 'approve':
				if ( empty( $change_id ) ) wp_send_json_error( 'item id required.' );
				$result = IATO_MCP_IATO_Client::update_queue_item( $workspace_id, $change_id, 'approved' );
				break;

			case 'dismiss':
				if ( empty( $change_id ) ) wp_send_json_error( 'item id required.' );
				$result = IATO_MCP_IATO_Client::update_queue_item( $workspace_id, $change_id, 'dismissed' );
				break;

			case 'rollback':
				if ( empty( $change_id ) ) wp_send_json_error( 'change_id required.' );
				$result = IATO_MCP_Rollback::rollback_by_id( $change_id );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}

				wp_send_json_success( $result );
				return;

			default:
				wp_send_json_error( 'Unknown operation.' );
				return;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

}

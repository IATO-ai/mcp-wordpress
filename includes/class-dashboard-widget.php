<?php
/**
 * Dashboard Widget — IATO SEO Health overview on the wp-admin dashboard.
 *
 * Shows SEO score, issue counts, recent auto-fixes, review queue items,
 * and crawl freshness. All data cached via transients (5-minute TTL).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Dashboard_Widget {

	/** @var int Transient TTL in seconds. */
	private const CACHE_TTL = 300; // 5 minutes.

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'register_widget' ] );
		add_action( 'wp_ajax_iato_mcp_widget_run_audit', [ __CLASS__, 'ajax_run_audit' ] );
	}

	/**
	 * Register the dashboard widget.
	 */
	public static function register_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'iato_mcp_dashboard',
			__( 'IATO SEO Health', 'iato-mcp' ),
			[ __CLASS__, 'render' ]
		);
	}

	/**
	 * Render the widget content.
	 */
	public static function render(): void {
		$api_key      = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$workspace_id = sanitize_text_field( get_option( 'iato_mcp_workspace_id', '' ) );

		if ( empty( $api_key ) || empty( $workspace_id ) ) {
			self::render_empty_state();
			return;
		}

		$data  = self::get_cached_data( $workspace_id );
		$nonce = wp_create_nonce( 'iato_mcp_widget' );

		?>
		<style>
			.iato-widget { font-size: 13px; }
			.iato-widget-score { display: flex; gap: 24px; align-items: center; padding-bottom: 16px; border-bottom: 1px solid #f0f0f1; margin-bottom: 12px; }
			.iato-widget-score .score-circle { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; color: #fff; flex-shrink: 0; }
			.iato-widget-score .score-circle.green { background: #00a32a; }
			.iato-widget-score .score-circle.amber { background: #dba617; }
			.iato-widget-score .score-circle.red { background: #d63638; }
			.iato-widget-score .delta { font-size: 12px; color: #50575e; margin-top: 4px; }
			.iato-widget-score .delta.positive { color: #00a32a; }
			.iato-widget-score .delta.negative { color: #d63638; }
			.iato-widget-issues { display: flex; gap: 16px; margin-bottom: 12px; }
			.iato-widget-issues .issue-count { display: flex; align-items: center; gap: 4px; }
			.iato-widget-issues .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
			.iato-widget-issues .dot.error { background: #d63638; }
			.iato-widget-issues .dot.warning { background: #dba617; }
			.iato-widget-issues .dot.info { background: #2271b1; }
			.iato-widget-section { padding: 12px 0; border-top: 1px solid #f0f0f1; }
			.iato-widget-section h4 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; color: #50575e; letter-spacing: 0.5px; }
			.iato-widget-section ul { margin: 0; padding: 0; list-style: none; }
			.iato-widget-section li { padding: 4px 0; display: flex; justify-content: space-between; font-size: 12px; }
			.iato-widget-section li .field { color: #50575e; }
			.iato-widget-section li .time { color: #8c8f94; }
			.iato-widget-section li .icon-fixed { color: #00a32a; }
			.iato-widget-section li .icon-manual { color: #dba617; }
			.iato-widget-freshness { display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #f0f0f1; }
			.iato-widget-freshness .indicator { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
			.iato-widget-freshness .indicator.green { background: #00a32a; }
			.iato-widget-freshness .indicator.amber { background: #dba617; }
			.iato-widget-freshness .indicator.red { background: #d63638; }
			.iato-widget-run { margin-top: 12px; text-align: right; }
			.iato-widget-empty { text-align: center; padding: 20px 10px; }
			.iato-widget-empty .cta { display: inline-block; padding: 8px 16px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600; margin-top: 12px; }
			.iato-widget-empty .cta:hover { background: #135e96; color: #fff; }
			.iato-widget-links { text-align: right; font-size: 12px; margin-top: 8px; }
		</style>

		<div class="iato-widget">
			<?php if ( isset( $data['error'] ) ) : ?>
				<p style="color:#d63638"><?php echo esc_html( $data['error'] ); ?></p>
			<?php else : ?>

				<!-- SEO Score -->
				<?php
				$score = (int) ( $data['score'] ?? 0 );
				$delta = (int) ( $data['delta'] ?? 0 );
				$score_class = $score >= 70 ? 'green' : ( $score >= 40 ? 'amber' : 'red' );
				$delta_class = $delta > 0 ? 'positive' : ( $delta < 0 ? 'negative' : '' );
				$delta_text  = $delta > 0 ? "+{$delta}" : (string) $delta;
				?>
				<div class="iato-widget-score">
					<div class="score-circle <?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score ); ?></div>
					<div>
						<strong><?php esc_html_e( 'SEO Score', 'iato-mcp' ); ?></strong>
						<div>/100</div>
						<?php if ( 0 !== $delta ) : ?>
							<div class="delta <?php echo esc_attr( $delta_class ); ?>"><?php echo esc_html( $delta_text ); ?> vs last crawl</div>
						<?php endif; ?>
					</div>
					<div style="margin-left:auto">
						<div class="iato-widget-issues">
							<span class="issue-count"><span class="dot error"></span> <?php echo esc_html( $data['errors'] ?? 0 ); ?></span>
							<span class="issue-count"><span class="dot warning"></span> <?php echo esc_html( $data['warnings'] ?? 0 ); ?></span>
							<span class="issue-count"><span class="dot info"></span> <?php echo esc_html( $data['info'] ?? 0 ); ?></span>
						</div>
					</div>
				</div>

				<!-- Recent Auto-Fixes -->
				<?php if ( ! empty( $data['fixes'] ) ) : ?>
					<div class="iato-widget-section">
						<h4><?php esc_html_e( 'Recent Auto-Fixes', 'iato-mcp' ); ?></h4>
						<ul>
							<?php foreach ( array_slice( $data['fixes'], 0, 5 ) as $fix ) : ?>
								<li>
									<span>
										<?php if ( 'manually_fixed' === ( $fix['action'] ?? '' ) ) : ?>
											<span class="icon-manual" title="Manually fixed">&#9998;</span>
										<?php else : ?>
											<span class="icon-fixed" title="Auto-fixed">&#10003;</span>
										<?php endif; ?>
										<span class="field"><?php echo esc_html( $fix['field'] ?? '' ); ?></span>
										&mdash; <?php echo esc_html( $fix['page_url'] ?? $fix['url'] ?? '' ); ?>
									</span>
									<span class="time"><?php echo esc_html( $fix['time_ago'] ?? '' ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="iato-widget-links">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=iato-review-queue' ) ); ?>"><?php esc_html_e( 'View all fixes', 'iato-mcp' ); ?></a>
						</div>
					</div>
				<?php endif; ?>

				<!-- Needs Review -->
				<?php if ( ! empty( $data['review_items'] ) ) : ?>
					<div class="iato-widget-section">
						<h4><?php printf( esc_html__( 'Needs Your Review (%d items)', 'iato-mcp' ), count( $data['review_items'] ) ); ?></h4>
						<ul>
							<?php foreach ( array_slice( $data['review_items'], 0, 3 ) as $item ) : ?>
								<li>
									<span><?php echo esc_html( ( $item['page_url'] ?? '' ) . ' — ' . ( $item['issue_type'] ?? '' ) ); ?></span>
									<?php if ( ! empty( $item['post_id'] ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?action=edit&post=' . (int) $item['post_id'] ) ); ?>"><?php esc_html_e( 'Edit Post', 'iato-mcp' ); ?></a>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="iato-widget-links">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=iato-review-queue' ) ); ?>"><?php esc_html_e( 'Open Review Queue', 'iato-mcp' ); ?></a>
						</div>
					</div>
				<?php endif; ?>

				<!-- Crawl Freshness -->
				<?php
				$last_crawled = $data['last_crawled'] ?? null;
				$freshness    = 'red';
				$freshness_text = __( 'No crawl data', 'iato-mcp' );
				if ( $last_crawled ) {
					$age_hours = ( time() - strtotime( $last_crawled ) ) / 3600;
					if ( $age_hours < 2 ) {
						$freshness = 'green';
					} elseif ( $age_hours < 24 ) {
						$freshness = 'amber';
					}
					$freshness_text = sprintf( __( 'Last crawled: %s ago', 'iato-mcp' ), human_time_diff( strtotime( $last_crawled ) ) );
				}
				?>
				<div class="iato-widget-freshness">
					<span><span class="indicator <?php echo esc_attr( $freshness ); ?>"></span><?php echo esc_html( $freshness_text ); ?></span>
				</div>

				<div class="iato-widget-run">
					<button class="button button-small" id="iato-run-audit"><?php esc_html_e( 'Run Audit Now', 'iato-mcp' ); ?></button>
				</div>

				<script>
				document.getElementById('iato-run-audit').addEventListener('click', function() {
					this.disabled = true;
					this.textContent = '<?php echo esc_js( __( 'Running...', 'iato-mcp' ) ); ?>';
					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams({
							action: 'iato_mcp_widget_run_audit',
							_wpnonce: <?php echo wp_json_encode( $nonce ); ?>,
						})
					})
					.then(r => r.json())
					.then(r => {
						if (r.success) location.reload();
						else { alert(r.data || 'Failed.'); this.disabled = false; this.textContent = 'Run Audit Now'; }
					});
				});
				</script>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render empty state when IATO is not connected.
	 */
	private static function render_empty_state(): void {
		?>
		<div class="iato-widget-empty">
			<p><strong><?php esc_html_e( 'Your IATO MCP plugin is active.', 'iato-mcp' ); ?> &#10003;</strong></p>
			<p><?php esc_html_e( 'Connect the free IATO platform to see your SEO score, auto-fix issues, and monitor your site health — all from this dashboard.', 'iato-mcp' ); ?></p>
			<p><?php esc_html_e( 'Free for up to 500 pages. No card required.', 'iato-mcp' ); ?></p>
			<a href="https://iato.ai" target="_blank" class="cta"><?php esc_html_e( 'Connect IATO — it\'s free', 'iato-mcp' ); ?> &rarr;</a>
		</div>
		<?php
	}

	/**
	 * Get widget data with transient caching.
	 *
	 * @param string $workspace_id
	 * @return array
	 */
	private static function get_cached_data( string $workspace_id ): array {
		$cache_key = 'iato_mcp_widget_data_' . $workspace_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$data = self::fetch_data( $workspace_id );
		set_transient( $cache_key, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Fetch fresh data from IATO APIs.
	 *
	 * @param string $workspace_id
	 * @return array
	 */
	private static function fetch_data( string $workspace_id ): array {
		$crawl_id = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );

		$data = [
			'score'        => 0,
			'delta'        => 0,
			'errors'       => 0,
			'warnings'     => 0,
			'info'         => 0,
			'fixes'        => [],
			'review_items' => [],
			'last_crawled' => null,
		];

		if ( empty( $crawl_id ) ) {
			// Try to get the latest crawl.
			$crawls = IATO_MCP_IATO_Client::list_crawls();
			if ( ! is_wp_error( $crawls ) ) {
				$crawl_list = $crawls['crawls'] ?? $crawls['data'] ?? $crawls;
				if ( is_array( $crawl_list ) && ! empty( $crawl_list ) ) {
					$latest   = $crawl_list[0];
					$crawl_id = (string) ( $latest['job_id'] ?? $latest['id'] ?? '' );
					$data['last_crawled'] = $latest['completed_at'] ?? $latest['created_at'] ?? null;
				}
			}
		}

		// SEO Score.
		if ( $crawl_id ) {
			$score_result = IATO_MCP_IATO_Client::get_seo_score( $crawl_id );
			if ( ! is_wp_error( $score_result ) ) {
				$data['score'] = (int) ( $score_result['score'] ?? $score_result['seo_score'] ?? 0 );
				$data['delta'] = (int) ( $score_result['delta'] ?? 0 );
			}

			// Issue counts.
			$issues_result = IATO_MCP_IATO_Client::get_seo_issues( $crawl_id );
			if ( ! is_wp_error( $issues_result ) ) {
				$issues = $issues_result['issues'] ?? $issues_result['data'] ?? [];
				if ( is_array( $issues ) ) {
					foreach ( $issues as $issue ) {
						$sev = $issue['severity'] ?? 'info';
						if ( 'error' === $sev ) $data['errors']++;
						elseif ( 'warning' === $sev ) $data['warnings']++;
						else $data['info']++;
					}
				}
			}
		}

		// Activity log (recent fixes).
		$log_result = IATO_MCP_IATO_Client::get_activity_log( $workspace_id, [ 'limit' => 5 ] );
		if ( ! is_wp_error( $log_result ) ) {
			$entries = $log_result['entries'] ?? $log_result['data'] ?? $log_result;
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					$data['fixes'][] = [
						'field'    => $entry['field'] ?? $entry['action_type'] ?? '',
						'page_url' => $entry['page_url'] ?? $entry['url'] ?? '',
						'action'   => $entry['action'] ?? $entry['status'] ?? 'applied',
						'time_ago' => isset( $entry['created_at'] ) ? human_time_diff( strtotime( $entry['created_at'] ) ) : '',
					];
				}
			}
		}

		// Review queue items.
		$queue_result = IATO_MCP_IATO_Client::get_change_queue( $workspace_id, [
			'status'   => 'pending_review',
			'site_url' => site_url(),
			'limit'    => 5,
		] );
		if ( ! is_wp_error( $queue_result ) ) {
			$items = $queue_result['items'] ?? $queue_result['data'] ?? [];
			if ( is_array( $items ) ) {
				$data['review_items'] = $items;
			}
		}

		return $data;
	}

	/**
	 * AJAX: Run audit now — triggers crawl and invalidates cache.
	 */
	public static function ajax_run_audit(): void {
		check_ajax_referer( 'iato_mcp_widget', '_wpnonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$schedule_id  = sanitize_text_field( get_option( 'iato_mcp_schedule_id', '' ) );
		$workspace_id = sanitize_text_field( get_option( 'iato_mcp_workspace_id', '' ) );

		if ( empty( $schedule_id ) ) {
			wp_send_json_error( 'No schedule configured. Set one up in Settings > IATO MCP.' );
		}

		$result = IATO_MCP_IATO_Client::run_schedule_now( $schedule_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// Invalidate widget cache.
		if ( $workspace_id ) {
			delete_transient( 'iato_mcp_widget_data_' . $workspace_id );
		}

		wp_send_json_success( $result );
	}
}

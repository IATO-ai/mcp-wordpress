<?php
/**
 * Setup Wizard — 4-step onboarding flow for IATO Autopilot.
 *
 * Step 1: Connect to IATO (API key + workspace selection)
 * Step 2: Configure Autopilot Policy (governance policy)
 * Step 3: Set crawl schedule
 * Step 4: Run first crawl
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Setup_Wizard {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_redirect' ] );

		// AJAX handlers for each step.
		add_action( 'wp_ajax_iato_mcp_wizard_connect', [ __CLASS__, 'ajax_connect' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_policy', [ __CLASS__, 'ajax_policy' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_schedule', [ __CLASS__, 'ajax_schedule' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_crawl', [ __CLASS__, 'ajax_crawl' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_skip_policy', [ __CLASS__, 'ajax_skip_policy' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_complete', [ __CLASS__, 'ajax_complete' ] );
		add_action( 'wp_ajax_iato_mcp_debug_policy', [ __CLASS__, 'ajax_debug_policy' ] );
	}

	/**
	 * Register hidden admin page (no menu entry).
	 */
	public static function register_page(): void {
		add_submenu_page(
			null, // No parent = hidden page.
			__( 'IATO Setup', 'iato-mcp' ),
			__( 'IATO Setup', 'iato-mcp' ),
			'manage_options',
			'iato-mcp-setup',
			[ __CLASS__, 'render' ]
		);
	}

	/**
	 * Redirect to wizard on activation if not yet completed.
	 */
	public static function maybe_redirect(): void {
		if ( ! get_option( 'iato_mcp_show_wizard' ) ) {
			return;
		}
		if ( get_option( 'iato_mcp_setup_complete' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Don't redirect during AJAX, cron, or if already on the wizard page.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'admin_page_iato-mcp-setup' === $screen->id ) {
			return;
		}
		// Only redirect once per activation.
		if ( get_transient( 'iato_mcp_wizard_redirect' ) ) {
			delete_transient( 'iato_mcp_wizard_redirect' );
			wp_safe_redirect( admin_url( 'admin.php?page=iato-mcp-setup' ) );
			exit;
		}
	}

	/**
	 * Render the wizard page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized.', 'iato-mcp' ) );
		}

		$current_step  = (int) get_option( 'iato_mcp_wizard_step', 1 );
		$workspace_id  = sanitize_text_field( get_option( 'iato_mcp_workspace_id', '' ) );
		$api_key       = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$nonce         = wp_create_nonce( 'iato_mcp_wizard' );

		wp_enqueue_style( 'iato-mcp-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono&display=swap', [], null );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'IATO Autopilot Setup', 'iato-mcp' ); ?></h1>

			<style>
				.iato-wizard { max-width: 680px; margin: 30px auto; font-family: 'DM Sans', system-ui, sans-serif; }
				.iato-wizard-steps { display: flex; gap: 8px; margin-bottom: 30px; }
				.iato-wizard-steps .step { flex: 1; padding: 12px; text-align: center; background: #f3f4f6; border-radius: 8px; font-size: 13px; color: #6b7280; transition: all 0.2s; }
				.iato-wizard-steps .step.active { background: #5a89f4; color: #fff; font-weight: 600; }
				.iato-wizard-steps .step.done { background: #38d68e; color: #fff; }
				.iato-wizard-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; margin-bottom: 20px; transition: background 0.2s, box-shadow 0.2s; }
				.iato-wizard-card h2 { margin-top: 0; color: #111827; font-family: 'Instrument Serif', Georgia, serif; font-weight: 400; }
				.iato-wizard-card p { color: #6b7280; }
				.iato-wizard-card label { display: block; margin-bottom: 8px; font-weight: 600; color: #111827; }
				.iato-wizard-card input[type="text"],
				.iato-wizard-card input[type="password"],
				.iato-wizard-card select { width: 100%; padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; font-family: 'DM Sans', system-ui, sans-serif; transition: border-color 0.15s, box-shadow 0.15s; }
				.iato-wizard-card input[type="text"]:focus,
				.iato-wizard-card input[type="password"]:focus,
				.iato-wizard-card select:focus { border-color: #5a89f4; box-shadow: 0 0 0 2px rgba(90,137,244,0.1); outline: none; }
				.iato-wizard-card .field-group { margin-bottom: 16px; }
				.iato-wizard-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; }
				.iato-wizard-actions .skip { color: #6b7280; text-decoration: none; font-size: 13px; cursor: pointer; transition: color 0.2s; }
				.iato-wizard-actions .skip:hover { color: #5a89f4; }
				.iato-wizard-actions .button-primary { background: #4b72cc; border-color: #4b72cc; border-radius: 8px; box-shadow: 0 0 24px rgba(90,137,244,0.18); transition: all 0.2s; }
				.iato-wizard-actions .button-primary:hover { background: #3f64b8; border-color: #3f64b8; box-shadow: 0 0 36px rgba(90,137,244,0.3); }
				.iato-notice { padding: 12px 16px; border-left: 4px solid #eda145; background: rgba(237,161,69,0.12); margin-bottom: 16px; border-radius: 8px; }
				.iato-notice.success { border-color: #38d68e; background: rgba(56,214,142,0.12); }
				.iato-notice.error { border-color: #ef4444; background: rgba(239,68,68,0.12); }
				.iato-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #e5e7eb; border-top-color: #5a89f4; border-radius: 50%; animation: iato-spin 0.6s linear infinite; vertical-align: middle; margin-left: 8px; }
				@keyframes iato-spin { to { transform: rotate(360deg); } }
				.iato-completion { text-align: center; padding: 40px 20px; }
				.iato-completion .checkmark { font-size: 48px; margin-bottom: 16px; }
				.iato-completion h2 { color: #38d68e; font-family: 'Instrument Serif', Georgia, serif; font-weight: 400; }
				.iato-cta { display: inline-block; padding: 8px 20px; background: #4b72cc; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 13.5px; margin-top: 16px; box-shadow: 0 0 24px rgba(90,137,244,0.18); transition: all 0.2s; }
				.iato-cta:hover { background: #3f64b8; color: #fff; box-shadow: 0 0 36px rgba(90,137,244,0.3); }
			</style>

			<div class="iato-wizard">
				<!-- Step indicator -->
				<div class="iato-wizard-steps">
					<div class="step <?php echo $current_step === 1 ? 'active' : ( $current_step > 1 ? 'done' : '' ); ?>">1. Connect</div>
					<div class="step <?php echo $current_step === 2 ? 'active' : ( $current_step > 2 ? 'done' : '' ); ?>">2. Policy</div>
					<div class="step <?php echo $current_step === 3 ? 'active' : ( $current_step > 3 ? 'done' : '' ); ?>">3. Schedule</div>
					<div class="step <?php echo $current_step === 4 ? 'active' : ( $current_step > 4 ? 'done' : '' ); ?>">4. First Crawl</div>
				</div>

				<div id="iato-wizard-message"></div>

				<!-- Step 1: Connect to IATO -->
				<div class="iato-wizard-card" id="step-1" style="<?php echo $current_step !== 1 ? 'display:none' : ''; ?>">
					<h2><?php esc_html_e( 'Connect to IATO', 'iato-mcp' ); ?></h2>
					<p>
					<?php
					printf(
						/* translators: %s: link to IATO account page */
						esc_html__( 'Enter your IATO API key to connect this WordPress site to the IATO platform. Visit the %s.', 'iato-mcp' ),
						'<a href="https://iato.ai/#/account" target="_blank">' . esc_html__( 'My Account page', 'iato-mcp' ) . '</a>'
					);
					?>
				</p>

					<div class="field-group">
						<label for="iato-api-key"><?php esc_html_e( 'IATO API Key', 'iato-mcp' ); ?></label>
						<input type="password" id="iato-api-key" value="<?php echo esc_attr( $api_key ); ?>" placeholder="Enter your IATO API key" />
					</div>

					<div class="field-group" id="workspace-select-group" style="display:none">
						<label for="iato-workspace"><?php esc_html_e( 'Workspace', 'iato-mcp' ); ?></label>
						<select id="iato-workspace"></select>
					</div>

					<div class="iato-wizard-actions">
						<span></span>
						<button class="button button-primary" id="btn-connect"><?php esc_html_e( 'Connect & Continue', 'iato-mcp' ); ?></button>
					</div>

					<p style="margin-top:16px;font-size:13px;color:#50575e">
						<?php esc_html_e( "Don't have an account?", 'iato-mcp' ); ?>
						<a href="https://iato.ai" target="_blank"><?php esc_html_e( 'Get started free at iato.ai', 'iato-mcp' ); ?></a>
					</p>
				</div>

				<!-- Step 2: Configure Autopilot Policy -->
				<div class="iato-wizard-card" id="step-2" style="<?php echo $current_step !== 2 ? 'display:none' : ''; ?>">
					<h2><?php esc_html_e( 'Configure Autopilot Policy', 'iato-mcp' ); ?></h2>
					<p><?php esc_html_e( 'Set rules for what Autopilot can fix automatically vs. what requires your review.', 'iato-mcp' ); ?></p>

					<div class="field-group">
						<label><input type="checkbox" id="policy-auto-title" checked /> <?php esc_html_e( 'Auto-fix missing/poor SEO titles', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-auto-desc" checked /> <?php esc_html_e( 'Auto-fix missing meta descriptions', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-auto-alt" checked /> <?php esc_html_e( 'Auto-fix missing image alt text', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-auto-canonical" /> <?php esc_html_e( 'Auto-fix canonical URL issues', 'iato-mcp' ); ?></label>
					</div>

					<div class="field-group">
						<label for="policy-tone"><?php esc_html_e( 'AI Writing Tone', 'iato-mcp' ); ?></label>
						<select id="policy-tone">
							<option value="professional"><?php esc_html_e( 'Professional', 'iato-mcp' ); ?></option>
							<option value="casual"><?php esc_html_e( 'Casual', 'iato-mcp' ); ?></option>
							<option value="technical"><?php esc_html_e( 'Technical', 'iato-mcp' ); ?></option>
							<option value="friendly"><?php esc_html_e( 'Friendly', 'iato-mcp' ); ?></option>
						</select>
					</div>

					<div class="field-group">
						<label for="policy-brand"><?php esc_html_e( 'Brand Context (optional)', 'iato-mcp' ); ?></label>
						<input type="text" id="policy-brand" placeholder="e.g., We are a B2B SaaS company focused on..." />
					</div>

					<div class="iato-wizard-actions">
						<a class="skip" id="btn-skip-policy"><?php esc_html_e( 'Skip — use conservative defaults', 'iato-mcp' ); ?></a>
						<button class="button button-primary" id="btn-save-policy"><?php esc_html_e( 'Save Policy & Continue', 'iato-mcp' ); ?></button>
					</div>
				</div>

				<!-- Step 3: Set Crawl Schedule -->
				<div class="iato-wizard-card" id="step-3" style="<?php echo $current_step !== 3 ? 'display:none' : ''; ?>">
					<h2><?php esc_html_e( 'Set Crawl Schedule', 'iato-mcp' ); ?></h2>
					<p><?php esc_html_e( 'Choose how often IATO should crawl and audit your site.', 'iato-mcp' ); ?></p>

					<div class="field-group">
						<label for="schedule-frequency"><?php esc_html_e( 'Frequency', 'iato-mcp' ); ?></label>
						<select id="schedule-frequency">
							<option value="daily"><?php esc_html_e( 'Daily', 'iato-mcp' ); ?></option>
							<option value="weekly" selected><?php esc_html_e( 'Weekly', 'iato-mcp' ); ?></option>
							<option value="monthly"><?php esc_html_e( 'Monthly', 'iato-mcp' ); ?></option>
						</select>
					</div>

					<div class="field-group">
						<label for="schedule-time"><?php esc_html_e( 'Preferred Time', 'iato-mcp' ); ?></label>
						<select id="schedule-time">
							<?php for ( $h = 0; $h < 24; $h++ ) : ?>
								<option value="<?php echo esc_attr( sprintf( '%02d:00', $h ) ); ?>" <?php echo 3 === $h ? 'selected' : ''; ?>>
									<?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
								</option>
							<?php endfor; ?>
						</select>
					</div>

					<div class="field-group">
						<label for="schedule-timezone"><?php esc_html_e( 'Timezone', 'iato-mcp' ); ?></label>
						<select id="schedule-timezone">
							<?php
							$site_tz = wp_timezone_string();
							$zones   = timezone_identifiers_list();
							foreach ( $zones as $tz ) :
							?>
								<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $tz, $site_tz ); ?>>
									<?php echo esc_html( $tz ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="iato-wizard-actions">
						<span></span>
						<button class="button button-primary" id="btn-save-schedule"><?php esc_html_e( 'Create Schedule & Continue', 'iato-mcp' ); ?></button>
					</div>
				</div>

				<!-- Step 4: Run First Crawl -->
				<div class="iato-wizard-card" id="step-4" style="<?php echo $current_step !== 4 ? 'display:none' : ''; ?>">
					<h2><?php esc_html_e( 'Run Your First Crawl', 'iato-mcp' ); ?></h2>
					<p><?php esc_html_e( 'Start your first crawl to begin discovering SEO issues and content opportunities.', 'iato-mcp' ); ?></p>

					<div id="crawl-status"></div>

					<div class="iato-wizard-actions">
						<span></span>
						<button class="button button-primary" id="btn-run-crawl"><?php esc_html_e( 'Start Crawl Now', 'iato-mcp' ); ?></button>
					</div>
				</div>

				<!-- Completion screen -->
				<div class="iato-wizard-card" id="step-complete" style="display:none">
					<div class="iato-completion">
						<div class="checkmark">&#10003;</div>
						<h2><?php esc_html_e( 'Setup complete! IATO is now monitoring your site.', 'iato-mcp' ); ?></h2>
						<p><?php esc_html_e( 'Your first crawl is running. Here\'s what happens next:', 'iato-mcp' ); ?></p>
						<ol style="text-align:left;max-width:400px;margin:16px auto">
							<li><?php esc_html_e( 'Crawl completes — SEO audit runs automatically', 'iato-mcp' ); ?></li>
							<li><?php esc_html_e( 'Auto-fixable issues — applied directly to your site', 'iato-mcp' ); ?></li>
							<li><?php esc_html_e( 'Review items — appear in your Review Queue', 'iato-mcp' ); ?></li>
						</ol>
						<a href="https://iato.ai" target="_blank" class="iato-cta"><?php esc_html_e( 'View your Autopilot dashboard at iato.ai', 'iato-mcp' ); ?> &rarr;</a>
						<br><br>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=iato-review-queue' ) ); ?>"><?php esc_html_e( 'Go to Review Queue', 'iato-mcp' ); ?></a>
					</div>
				</div>
			</div>

			<script>
			(function(){
				const nonce = <?php echo wp_json_encode( $nonce ); ?>;
				const ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				let currentStep = <?php echo (int) $current_step; ?>;
				let workspaceId = <?php echo wp_json_encode( $workspace_id ); ?>;

				function showMessage(msg, type) {
					if (typeof msg === 'object' && msg !== null) {
						msg = msg.message || msg.error || JSON.stringify(msg);
					}
					const el = document.getElementById('iato-wizard-message');
					el.innerHTML = '<div class="iato-notice ' + type + '">' + msg + '</div>';
					if (type !== 'error') setTimeout(() => el.innerHTML = '', 5000);
				}

				function goToStep(step) {
					currentStep = step;
					document.querySelectorAll('.iato-wizard-card').forEach(c => c.style.display = 'none');
					const el = document.getElementById('step-' + step);
					if (el) el.style.display = '';
					document.querySelectorAll('.iato-wizard-steps .step').forEach((s, i) => {
						s.className = 'step' + (i + 1 === step ? ' active' : (i + 1 < step ? ' done' : ''));
					});
				}

				function post(action, data, btn) {
					let originalHTML;
					if (btn) { originalHTML = btn.innerHTML; btn.disabled = true; btn.innerHTML += '<span class="iato-spinner"></span>'; }
					return fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: new URLSearchParams(Object.assign({ action, _wpnonce: nonce }, data))
					})
					.then(r => r.json())
					.then(r => { if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; } return r; })
					.catch(e => { if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; } showMessage(e.message, 'error'); throw e; });
				}

				// Step 1: Connect
				document.getElementById('btn-connect').addEventListener('click', function() {
					const key = document.getElementById('iato-api-key').value.trim();
					if (!key) { showMessage('Please enter your API key.', 'error'); return; }
					post('iato_mcp_wizard_connect', { api_key: key, workspace_id: document.getElementById('iato-workspace').value }, this)
						.then(r => {
							if (r.success && r.data.workspaces) {
								// Show workspace selector.
								const sel = document.getElementById('iato-workspace');
								sel.innerHTML = '';
								r.data.workspaces.forEach(w => {
									const opt = document.createElement('option');
									opt.value = w.id; opt.textContent = w.name;
									sel.appendChild(opt);
								});
								document.getElementById('workspace-select-group').style.display = '';
								showMessage('Connected! Select a workspace and click again.', 'success');
							} else if (r.success && r.data.step) {
								workspaceId = r.data.workspace_id;
								showMessage('Connected!', 'success');
								goToStep(r.data.step);
							} else {
								showMessage(r.data || 'Connection failed.', 'error');
							}
						});
				});

				// Step 2: Save Policy
				document.getElementById('btn-save-policy').addEventListener('click', function() {
					post('iato_mcp_wizard_policy', {
						workspace_id: workspaceId,
						auto_fix_types: JSON.stringify({
							title: document.getElementById('policy-auto-title').checked,
							meta_description: document.getElementById('policy-auto-desc').checked,
							alt_text: document.getElementById('policy-auto-alt').checked,
							canonical: document.getElementById('policy-auto-canonical').checked,
						}),
						tone: document.getElementById('policy-tone').value,
						brand_context: document.getElementById('policy-brand').value,
					}, this).then(r => {
						if (r.success) { showMessage('Policy saved!', 'success'); goToStep(3); }
						else showMessage(r.data || 'Failed to save policy.', 'error');
					});
				});

				// Step 2: Skip Policy
				document.getElementById('btn-skip-policy').addEventListener('click', function() {
					if (!confirm('Autopilot will be disabled until you configure a policy. All issues will go to the review queue. Continue?')) return;
					post('iato_mcp_wizard_skip_policy', { workspace_id: workspaceId }, this)
						.then(r => { if (r.success) goToStep(3); });
				});

				// Step 3: Save Schedule
				document.getElementById('btn-save-schedule').addEventListener('click', function() {
					post('iato_mcp_wizard_schedule', {
						workspace_id: workspaceId,
						frequency: document.getElementById('schedule-frequency').value,
						time: document.getElementById('schedule-time').value,
						timezone: document.getElementById('schedule-timezone').value,
						site_url: <?php echo wp_json_encode( site_url() ); ?>,
					}, this).then(r => {
						if (r.success) { showMessage('Schedule created!', 'success'); goToStep(4); }
						else showMessage(r.data || 'Failed to create schedule.', 'error');
					});
				});

				// Step 4: Run Crawl
				document.getElementById('btn-run-crawl').addEventListener('click', function() {
					post('iato_mcp_wizard_crawl', { workspace_id: workspaceId }, this)
						.then(r => {
							if (r.success) {
								document.getElementById('crawl-status').innerHTML = '<div class="iato-notice success">Crawl started! It will complete in the background.</div>';
								// Complete the wizard.
								post('iato_mcp_wizard_complete', {}).then(() => {
									setTimeout(() => {
										document.querySelectorAll('.iato-wizard-card').forEach(c => c.style.display = 'none');
										document.getElementById('step-complete').style.display = '';
									}, 1000);
								});
							} else {
								showMessage(r.data || 'Failed to start crawl.', 'error');
							}
						});
				});
			})();
			</script>
		</div>
		<?php
	}

	// ── AJAX Handlers ────────────────────────────────────────────────────────

	/**
	 * Step 1: Validate API key, list workspaces, save selection.
	 */
	public static function ajax_connect(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$api_key      = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$workspace_id = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );

		if ( empty( $api_key ) ) {
			wp_send_json_error( 'API key is required.' );
		}

		// Save the key temporarily for validation.
		update_option( 'iato_mcp_api_key', $api_key );

		// Fetch workspaces.
		$workspaces = IATO_MCP_IATO_Client::list_workspaces();
		if ( is_wp_error( $workspaces ) ) {
			delete_option( 'iato_mcp_api_key' );
			wp_send_json_error( 'Failed to connect: ' . $workspaces->get_error_message() );
		}

		$ws_list = $workspaces['workspaces'] ?? $workspaces['data'] ?? $workspaces;
		if ( ! is_array( $ws_list ) ) {
			$ws_list = [];
		}

		// If no workspace selected yet, return the list.
		if ( empty( $workspace_id ) && count( $ws_list ) > 1 ) {
			wp_send_json_success( [ 'workspaces' => $ws_list ] );
		}

		// Select first workspace if not specified.
		if ( empty( $workspace_id ) && ! empty( $ws_list ) ) {
			$workspace_id = (string) ( $ws_list[0]['id'] ?? '' );
		}

		if ( empty( $workspace_id ) ) {
			wp_send_json_error( 'No workspaces found. Create one at iato.ai first.' );
		}

		update_option( 'iato_mcp_workspace_id', $workspace_id );
		update_option( 'iato_mcp_wizard_step', 2 );

		wp_send_json_success( [
			'step'         => 2,
			'workspace_id' => $workspace_id,
		] );
	}

	/**
	 * Step 2: Save governance policy.
	 */
	public static function ajax_policy(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$workspace_id   = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
		$auto_fix_types = json_decode( wp_unslash( $_POST['auto_fix_types'] ?? '{}' ), true );
		$tone           = sanitize_text_field( wp_unslash( $_POST['tone'] ?? 'professional' ) );
		$brand_context  = sanitize_textarea_field( wp_unslash( $_POST['brand_context'] ?? '' ) );

		if ( empty( $workspace_id ) ) {
			wp_send_json_error( 'Workspace ID required.' );
		}

		// Convert checkbox booleans to IATO rules format.
		$rules = [];
		$issue_types = [ 'title', 'meta_description', 'alt_text', 'canonical' ];
		foreach ( $issue_types as $type ) {
			$rules[ $type ] = [
				'action' => ! empty( $auto_fix_types[ $type ] ) ? 'auto_fix' : 'needs_review',
			];
		}

		$policy = [
			'is_active'        => true,
			'rules'            => $rules,
			'ai_tone'          => $tone,
			'ai_brand_context' => $brand_context,
			'cms_integration'  => 'wordpress',
		];

		// DEBUG: Try multiple endpoint/method combos. Remove after debugging.
		$api_key = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$headers = [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];

		// Include workspace_id in body for variants that need it.
		$policy_with_ws = array_merge( $policy, [ 'workspace_id' => (int) $workspace_id ] );

		$attempts = [
			// 1. GET workspace — does it still exist?
			[
				'method' => 'GET',
				'url'    => 'https://iato.ai/api/workspaces/' . $workspace_id,
				'body'   => null,
			],
			// 2. GET all workspaces — what's available?
			[
				'method' => 'GET',
				'url'    => 'https://iato.ai/api/workspaces',
				'body'   => null,
			],
			// 3. Minimal POST — just is_active to isolate body issue
			[
				'method' => 'POST',
				'url'    => 'https://iato.ai/api/workspaces/' . $workspace_id . '/governance-policy',
				'body'   => [ 'is_active' => true ],
			],
			// 4. Full POST with all fields
			[
				'method' => 'POST',
				'url'    => 'https://iato.ai/api/workspaces/' . $workspace_id . '/governance-policy',
				'body'   => $policy,
			],
		];

		$results = [];
		foreach ( $attempts as $i => $attempt ) {
			$args = [
				'method'  => $attempt['method'],
				'timeout' => 15,
				'headers' => $headers,
			];
			if ( $attempt['body'] !== null ) {
				$args['body'] = wp_json_encode( $attempt['body'] );
			}
			$raw = wp_remote_request( $attempt['url'], $args );

			$results[] = [
				'attempt'       => $i + 1,
				'method'        => $attempt['method'],
				'url'           => $attempt['url'],
				'body_sent'     => $attempt['body'],
				'response_code' => is_wp_error( $raw ) ? 'WP_ERROR' : wp_remote_retrieve_response_code( $raw ),
				'response_body' => is_wp_error( $raw ) ? $raw->get_error_message() : wp_remote_retrieve_body( $raw ),
			];

			// If we got a 2xx, stop trying.
			if ( ! is_wp_error( $raw ) ) {
				$code = wp_remote_retrieve_response_code( $raw );
				if ( $code >= 200 && $code < 300 ) {
					break;
				}
			}
		}

		wp_send_json_error( [ 'debug_attempts' => $results ] );

		update_option( 'iato_mcp_wizard_step', 3 );
		wp_send_json_success( [ 'step' => 3 ] );
	}

	/**
	 * Step 2 (skip): Set policy inactive with warning.
	 */
	public static function ajax_skip_policy(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$workspace_id = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
		if ( $workspace_id ) {
			IATO_MCP_IATO_Client::update_governance_policy( $workspace_id, [ 'is_active' => false ] );
		}

		update_option( 'iato_mcp_wizard_step', 3 );
		wp_send_json_success( [ 'step' => 3 ] );
	}

	/**
	 * Step 3: Create crawl schedule.
	 */
	public static function ajax_schedule(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$workspace_id = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
		$frequency    = sanitize_text_field( wp_unslash( $_POST['frequency'] ?? 'weekly' ) );
		$time         = sanitize_text_field( wp_unslash( $_POST['time'] ?? '03:00' ) );
		$timezone     = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'UTC' ) );
		$site_url     = esc_url_raw( wp_unslash( $_POST['site_url'] ?? site_url() ) );
		$site_name    = sanitize_text_field( get_bloginfo( 'name' ) );

		$result = IATO_MCP_IATO_Client::create_schedule( [
			'workspace_id'  => $workspace_id,
			'name'          => $site_name . ' — ' . $frequency . ' crawl',
			'frequency'     => $frequency,
			'schedule_time' => $time,
			'timezone'      => $timezone,
			'url'           => $site_url,
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$schedule_id = (string) ( $result['id'] ?? $result['schedule_id'] ?? '' );
		if ( $schedule_id ) {
			update_option( 'iato_mcp_schedule_id', $schedule_id );
		}

		update_option( 'iato_mcp_wizard_step', 4 );
		wp_send_json_success( [ 'step' => 4, 'schedule_id' => $schedule_id ] );
	}

	/**
	 * Step 4: Run first crawl.
	 */
	public static function ajax_crawl(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$schedule_id = sanitize_text_field( get_option( 'iato_mcp_schedule_id', '' ) );
		if ( empty( $schedule_id ) ) {
			wp_send_json_error( 'No schedule configured. Go back and create one.' );
		}

		$result = IATO_MCP_IATO_Client::run_schedule_now( $schedule_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$crawl_id = (string) ( $result['crawl_id'] ?? $result['job_id'] ?? '' );
		if ( $crawl_id ) {
			update_option( 'iato_mcp_crawl_id', $crawl_id );
		}

		wp_send_json_success( [ 'crawl_id' => $crawl_id ] );
	}

	/**
	 * Mark wizard complete.
	 */
	public static function ajax_complete(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		update_option( 'iato_mcp_setup_complete', true );
		update_option( 'iato_mcp_wizard_dismissed', true );
		delete_option( 'iato_mcp_show_wizard' );

		wp_send_json_success();
	}

	/**
	 * DEBUG: Trace the exact IATO API request/response for policy update.
	 * TEMPORARY — remove after debugging.
	 */
	public static function ajax_debug_policy(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$workspace_id   = sanitize_text_field( wp_unslash( $_POST['workspace_id'] ?? '' ) );
		$auto_fix_types = json_decode( wp_unslash( $_POST['auto_fix_types'] ?? '{}' ), true );
		$tone           = sanitize_text_field( wp_unslash( $_POST['tone'] ?? 'professional' ) );
		$brand_context  = sanitize_textarea_field( wp_unslash( $_POST['brand_context'] ?? '' ) );

		$rules = [];
		$issue_types = [ 'title', 'meta_description', 'alt_text', 'canonical' ];
		foreach ( $issue_types as $type ) {
			$rules[ $type ] = [
				'action' => ! empty( $auto_fix_types[ $type ] ) ? 'auto_fix' : 'needs_review',
			];
		}

		$policy = [
			'is_active'        => true,
			'rules'            => $rules,
			'ai_tone'          => $tone,
			'ai_brand_context' => $brand_context,
			'cms_integration'  => 'wordpress',
		];

		$api_key = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$url     = 'https://iato.ai/api/workspaces/' . $workspace_id . '/governance-policy';
		$body    = wp_json_encode( $policy );

		$response = wp_remote_post( $url, [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body' => $body,
		] );

		$debug = [
			'request_url'     => $url,
			'request_body'    => $policy,
			'request_body_json' => $body,
			'response_code'   => is_wp_error( $response ) ? 'WP_ERROR' : wp_remote_retrieve_response_code( $response ),
			'response_body'   => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ),
			'response_headers'=> is_wp_error( $response ) ? null : wp_remote_retrieve_headers( $response )->getAll(),
		];

		wp_send_json_success( $debug );
	}
}

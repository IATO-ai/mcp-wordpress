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

	/** Page hook suffix for enqueue check. */
	private static string $page_hook = '';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_redirect' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// AJAX handlers for each step.
		add_action( 'wp_ajax_iato_mcp_wizard_connect', [ __CLASS__, 'ajax_connect' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_policy', [ __CLASS__, 'ajax_policy' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_schedule', [ __CLASS__, 'ajax_schedule' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_crawl', [ __CLASS__, 'ajax_crawl' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_skip_policy', [ __CLASS__, 'ajax_skip_policy' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_complete', [ __CLASS__, 'ajax_complete' ] );
	}

	/**
	 * Register hidden admin page (no menu entry).
	 */
	public static function register_page(): void {
		self::$page_hook = (string) add_submenu_page(
			null, // No parent = hidden page.
			__( 'IATO Setup', 'iato-mcp' ),
			__( 'IATO Setup', 'iato-mcp' ),
			'manage_options',
			'iato-mcp-setup',
			[ __CLASS__, 'render' ]
		);
	}

	/**
	 * Enqueue inline CSS/JS for the wizard page only.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( self::$page_hook === '' || $hook !== self::$page_hook ) {
			return;
		}

		wp_enqueue_style( 'iato-mcp-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono&display=swap', [], null );

		wp_register_style( 'iato-mcp-setup-wizard', false, [], IATO_MCP_VERSION );
		wp_enqueue_style( 'iato-mcp-setup-wizard' );
		wp_add_inline_style( 'iato-mcp-setup-wizard', self::get_inline_styles() );

		wp_register_script( 'iato-mcp-setup-wizard', false, [], IATO_MCP_VERSION, true );
		wp_enqueue_script( 'iato-mcp-setup-wizard' );
		wp_add_inline_script( 'iato-mcp-setup-wizard', self::get_inline_scripts() );
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

		wp_localize_script( 'iato-mcp-setup-wizard', 'iatoWizard', [
			'nonce'       => $nonce,
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'currentStep' => $current_step,
			'workspaceId' => $workspace_id,
			'siteUrl'     => site_url(),
		] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'IATO Autopilot Setup', 'iato-mcp' ); ?></h1>
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

					<fieldset class="field-group">
						<legend style="font-weight:600;margin-bottom:8px;color:#111827"><?php esc_html_e( 'Auto-Fixable Rules', 'iato-mcp' ); ?></legend>
						<label><input type="checkbox" id="policy-title_too_short" checked /> <?php esc_html_e( 'Short SEO titles', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-title_too_long" checked /> <?php esc_html_e( 'Long SEO titles', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-missing_meta_description" checked /> <?php esc_html_e( 'Missing meta descriptions', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-missing_alt_text" checked /> <?php esc_html_e( 'Missing image alt text', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-missing_canonical" /> <?php esc_html_e( 'Missing canonical URLs', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-missing_structured_data" /> <?php esc_html_e( 'Missing structured data', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-missing_taxonomy" /> <?php esc_html_e( 'Missing taxonomy assignments', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-sitemap_node_missing_title" /> <?php esc_html_e( 'Missing sitemap node titles', 'iato-mcp' ); ?></label>
					</fieldset>

					<fieldset class="field-group">
						<legend style="font-weight:600;margin-bottom:8px;color:#111827"><?php esc_html_e( 'Review Only', 'iato-mcp' ); ?></legend>
						<label><input type="checkbox" id="policy-missing_h1" /> <?php esc_html_e( 'Missing H1 headings', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-thin_content" /> <?php esc_html_e( 'Thin content pages', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-broken_links" /> <?php esc_html_e( 'Broken links', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-orphan_pages" /> <?php esc_html_e( 'Orphan pages', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-duplicate_content" /> <?php esc_html_e( 'Duplicate content', 'iato-mcp' ); ?></label>
						<label><input type="checkbox" id="policy-slow_response" /> <?php esc_html_e( 'Slow page response', 'iato-mcp' ); ?></label>
					</fieldset>

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
							<?php for ( $h = 0; $h < 24; $h++ ) :
								$display = ( 0 === $h ) ? '12:00 AM' : ( $h < 12 ? $h . ':00 AM' : ( 12 === $h ? '12:00 PM' : ( $h - 12 ) . ':00 PM' ) );
							?>
								<option value="<?php echo esc_attr( sprintf( '%02d:00', $h ) ); ?>" <?php echo 3 === $h ? 'selected' : ''; ?>>
									<?php echo esc_html( $display ); ?>
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
		</div>
		<?php
	}

	// ── Inline Assets ────────────────────────────────────────────────────────

	private static function get_inline_styles(): string {
		return <<<'CSS'
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
CSS;
	}

	private static function get_inline_scripts(): string {
		return <<<'JS'
(function(){
	var nonce = iatoWizard.nonce;
	var ajaxurl = iatoWizard.ajaxurl;
	var currentStep = parseInt(iatoWizard.currentStep, 10);
	var workspaceId = iatoWizard.workspaceId;
	var siteUrl = iatoWizard.siteUrl;

	function showMessage(msg, type) {
		if (typeof msg === 'object' && msg !== null) {
			msg = msg.message || msg.error || JSON.stringify(msg);
		}
		var el = document.getElementById('iato-wizard-message');
		el.innerHTML = '<div class="iato-notice ' + type + '">' + msg + '</div>';
		if (type !== 'error') setTimeout(function() { el.innerHTML = ''; }, 5000);
	}

	function goToStep(step) {
		currentStep = step;
		document.querySelectorAll('.iato-wizard-card').forEach(function(c) { c.style.display = 'none'; });
		var el = document.getElementById('step-' + step);
		if (el) el.style.display = '';
		document.querySelectorAll('.iato-wizard-steps .step').forEach(function(s, i) {
			s.className = 'step' + (i + 1 === step ? ' active' : (i + 1 < step ? ' done' : ''));
		});
	}

	function post(action, data, btn) {
		var originalHTML;
		if (btn) { originalHTML = btn.innerHTML; btn.disabled = true; btn.innerHTML += '<span class="iato-spinner"></span>'; }
		return fetch(ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams(Object.assign({ action: action, _wpnonce: nonce }, data))
		})
		.then(function(r) { return r.json(); })
		.then(function(r) { if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; } return r; })
		.catch(function(e) { if (btn) { btn.disabled = false; btn.innerHTML = originalHTML; } showMessage(e.message, 'error'); throw e; });
	}

	// Step 1: Connect
	document.getElementById('btn-connect').addEventListener('click', function() {
		var key = document.getElementById('iato-api-key').value.trim();
		if (!key) { showMessage('Please enter your API key.', 'error'); return; }
		var self = this;
		post('iato_mcp_wizard_connect', { api_key: key, workspace_id: document.getElementById('iato-workspace').value }, self)
			.then(function(r) {
				if (r.success && r.data.workspaces) {
					var sel = document.getElementById('iato-workspace');
					sel.innerHTML = '';
					r.data.workspaces.forEach(function(w) {
						var opt = document.createElement('option');
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
		var ruleKeys = [
			'title_too_short','title_too_long','missing_meta_description','missing_alt_text',
			'missing_canonical','missing_structured_data','missing_taxonomy','sitemap_node_missing_title',
			'missing_h1','thin_content','broken_links','orphan_pages','duplicate_content','slow_response'
		];
		var autoFixTypes = {};
		ruleKeys.forEach(function(k) {
			var el = document.getElementById('policy-' + k);
			if (el) autoFixTypes[k] = el.checked;
		});
		post('iato_mcp_wizard_policy', {
			workspace_id: workspaceId,
			auto_fix_types: JSON.stringify(autoFixTypes),
			tone: document.getElementById('policy-tone').value,
			brand_context: document.getElementById('policy-brand').value,
		}, this).then(function(r) {
			if (r.success) { showMessage('Policy saved!', 'success'); goToStep(3); }
			else showMessage(r.data || 'Failed to save policy.', 'error');
		});
	});

	// Step 2: Skip Policy
	document.getElementById('btn-skip-policy').addEventListener('click', function() {
		if (!confirm('Autopilot will be disabled until you configure a policy. All issues will go to the review queue. Continue?')) return;
		post('iato_mcp_wizard_skip_policy', { workspace_id: workspaceId }, this)
			.then(function(r) { if (r.success) goToStep(3); });
	});

	// Step 3: Save Schedule
	document.getElementById('btn-save-schedule').addEventListener('click', function() {
		post('iato_mcp_wizard_schedule', {
			workspace_id: workspaceId,
			frequency: document.getElementById('schedule-frequency').value,
			time: document.getElementById('schedule-time').value,
			timezone: document.getElementById('schedule-timezone').value,
			site_url: siteUrl,
		}, this).then(function(r) {
			if (r.success) { showMessage('Schedule created!', 'success'); goToStep(4); }
			else showMessage(r.data || 'Failed to create schedule.', 'error');
		});
	});

	// Step 4: Run Crawl
	document.getElementById('btn-run-crawl').addEventListener('click', function() {
		post('iato_mcp_wizard_crawl', { workspace_id: workspaceId }, this)
			.then(function(r) {
				if (r.success) {
					var syncMsg = r.data.pages_synced ? ' ' + r.data.pages_synced + ' pages synced to IATO.' : '';
					document.getElementById('crawl-status').innerHTML = '<div class="iato-notice success">Crawl started!' + syncMsg + ' It will complete in the background.</div>';
					post('iato_mcp_wizard_complete', {}).then(function() {
						setTimeout(function() {
							document.querySelectorAll('.iato-wizard-card').forEach(function(c) { c.style.display = 'none'; });
							document.getElementById('step-complete').style.display = '';
						}, 1000);
					});
				} else {
					showMessage(r.data || 'Failed to start crawl.', 'error');
				}
			});
	});
})();
JS;
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
		$rules       = [];
		$issue_types = [
			'title_too_short', 'title_too_long', 'missing_meta_description', 'missing_alt_text',
			'missing_canonical', 'missing_structured_data', 'missing_taxonomy', 'sitemap_node_missing_title',
			'missing_h1', 'thin_content', 'broken_links', 'orphan_pages', 'duplicate_content', 'slow_response',
		];
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

		$result = IATO_MCP_IATO_Client::update_governance_policy( $workspace_id, $policy );
		if ( is_wp_error( $result ) ) {
			// Save policy locally even if IATO API fails, so user can proceed.
			update_option( 'iato_mcp_local_policy', $policy );
		}

		// Sync to settings page options.
		update_option( 'iato_mcp_autopilot_enabled', true );
		update_option( 'iato_mcp_governance_policy', $policy );

		update_option( 'iato_mcp_wizard_step', 3 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() . ' Policy saved locally — you can skip to the next step.' );
		}

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

		update_option( 'iato_mcp_autopilot_enabled', false );
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

		// Auto-sync WordPress pages to IATO so Autopilot has wp_post_id mappings.
		$sync_result = self::auto_sync_pages();

		wp_send_json_success( [
			'crawl_id'     => $crawl_id,
			'pages_synced' => $sync_result['created'] ?? 0,
			'pages_total'  => $sync_result['total'] ?? 0,
		] );
	}

	/**
	 * Automatically sync WordPress posts/pages to IATO sitemap nodes.
	 *
	 * Resolves the sitemap_id from the workspace, then creates IATO nodes
	 * for every published post/page with wp_post_id and wp_post_type so the
	 * platform can map Autopilot fixes back to WordPress.
	 *
	 * @return array{created: int, skipped: int, total: int, sitemap_id: int}
	 */
	private static function auto_sync_pages(): array {
		$empty = [ 'created' => 0, 'skipped' => 0, 'total' => 0, 'sitemap_id' => 0 ];

		// Resolve workspace → sitemap.
		$workspace_id = IATO_MCP_IATO_Client::resolve_workspace_id();
		if ( empty( $workspace_id ) ) {
			return $empty;
		}

		$sitemaps = IATO_MCP_IATO_Client::list_sitemaps( (int) $workspace_id );
		if ( is_wp_error( $sitemaps ) ) {
			return $empty;
		}

		$sitemap_list = $sitemaps['sitemaps'] ?? $sitemaps['data'] ?? $sitemaps;
		if ( empty( $sitemap_list ) || ! is_array( $sitemap_list ) ) {
			return $empty;
		}

		$sitemap_id = (int) ( $sitemap_list[0]['id'] ?? $sitemap_list[0]['sitemap_id'] ?? 0 );
		if ( ! $sitemap_id ) {
			return $empty;
		}

		update_option( 'iato_mcp_sitemap_id', $sitemap_id );

		// Fetch existing IATO nodes for dedup.
		$nodes_response = IATO_MCP_IATO_Client::get_sitemap_nodes( $sitemap_id );
		$existing_urls  = [];
		if ( ! is_wp_error( $nodes_response ) ) {
			$nodes = $nodes_response['nodes'] ?? $nodes_response['data'] ?? $nodes_response;
			if ( is_array( $nodes ) ) {
				foreach ( $nodes as $node ) {
					$url = $node['url'] ?? '';
					if ( $url ) {
						$existing_urls[ untrailingslashit( $url ) ] = true;
					}
				}
			}
		}

		// Query all published posts and pages.
		$posts = get_posts( [
			'post_type'   => [ 'post', 'page' ],
			'post_status' => 'publish',
			'numberposts' => 500,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		$created = 0;
		$skipped = 0;

		foreach ( $posts as $post ) {
			$url = untrailingslashit( get_permalink( $post ) );

			if ( isset( $existing_urls[ $url ] ) ) {
				++$skipped;
				continue;
			}

			$page_type = match ( $post->post_type ) {
				'post' => 'article',
				'page' => 'landing',
				default => $post->post_type,
			};

			$result = IATO_MCP_IATO_Client::create_sitemap_node(
				$sitemap_id,
				$post->post_title,
				$url,
				null,
				'page',
				$page_type,
				$post->ID,
				$post->post_type
			);

			if ( ! is_wp_error( $result ) ) {
				++$created;
			}
		}

		return [
			'created'    => $created,
			'skipped'    => $skipped,
			'total'      => count( $posts ),
			'sitemap_id' => $sitemap_id,
		];
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

}

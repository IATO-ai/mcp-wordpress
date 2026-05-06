<?php
/**
 * Setup Wizard — single-screen onboarding for the IATO MCP server.
 *
 * Shows:
 *   - MCP server URL
 *   - Link to generate a WordPress Application Password
 *   - Copy-pasteable Claude Desktop JSON config
 *   - Optional IATO API key field (enables the IATO bridge read tools)
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

		add_action( 'wp_ajax_iato_mcp_wizard_connect', [ __CLASS__, 'ajax_connect' ] );
		add_action( 'wp_ajax_iato_mcp_wizard_complete', [ __CLASS__, 'ajax_complete' ] );
	}

	/**
	 * Register hidden admin page (no menu entry — accessed via redirect or Settings link).
	 */
	public static function register_page(): void {
		self::$page_hook = (string) add_submenu_page(
			null,
			__( 'IATO MCP Setup', 'iato-mcp' ),
			__( 'IATO MCP Setup', 'iato-mcp' ),
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
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'admin_page_iato-mcp-setup' === $screen->id ) {
			return;
		}
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
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}

		$api_key   = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$mcp_url   = rest_url( 'iato-mcp/v1/message' );
		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );
		$profile   = admin_url( 'profile.php#application-passwords-section' );
		$nonce     = wp_create_nonce( 'iato_mcp_wizard' );

		// Claude Desktop / Cursor / Cline / Zed config snippet for stdio clients.
		// Uses `mcp-remote` (the real npm package that bridges remote HTTP MCP to stdio).
		// Credential is held in `env` and referenced via ${IATO_AUTH} in `args` because
		// Claude Desktop's args parser is fragile around spaces in inline header strings.
		$config_snippet = wp_json_encode(
			[
				'mcpServers' => [
					iato_mcp_connection_name() => [
						'command' => 'npx',
						'args'    => [
							'-y',
							'mcp-remote',
							$mcp_url,
							'--header',
							'Authorization: Basic ${IATO_AUTH}',
						],
						'env'     => [
							'IATO_AUTH' => '<base64(username:application_password)>',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		wp_localize_script( 'iato-mcp-setup-wizard', 'iatoWizard', [
			'nonce'   => $nonce,
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
		] );

		?>
		<div class="wrap iato-wizard">
			<h1><?php esc_html_e( 'IATO MCP Server Setup', 'iato-mcp' ); ?></h1>

			<div id="iato-wizard-message"></div>

			<div class="iato-wizard-card">
				<h2><?php esc_html_e( 'Your MCP server URL', 'iato-mcp' ); ?></h2>
				<p><?php esc_html_e( 'Point your AI client at this endpoint:', 'iato-mcp' ); ?></p>
				<code class="iato-endpoint"><?php echo esc_html( $mcp_url ); ?></code>
				<p class="iato-method-intro">
					<?php esc_html_e( 'Choose ONE connection method below based on your client.', 'iato-mcp' ); ?>
				</p>
			</div>

			<div class="iato-wizard-card recommended">
				<h2>
					<?php esc_html_e( 'Claude.ai or Claude Desktop (Connectors UI)', 'iato-mcp' ); ?>
					<span class="iato-method-badge"><?php esc_html_e( 'Recommended', 'iato-mcp' ); ?></span>
				</h2>
				<p class="iato-method-subhead"><?php esc_html_e( 'No credentials required — OAuth handles the handshake.', 'iato-mcp' ); ?></p>
				<p>
					<?php esc_html_e( 'In Claude, click Add Connector, paste the URL above, and click Connect. You will be redirected to your WordPress site to authorize the connection. Done.', 'iato-mcp' ); ?>
				</p>
			</div>

			<div class="iato-method-divider"><?php esc_html_e( '— or —', 'iato-mcp' ); ?></div>

			<div class="iato-wizard-card">
				<h2><?php esc_html_e( 'Direct HTTP clients (Basic Auth)', 'iato-mcp' ); ?></h2>
				<p class="iato-method-subhead"><?php esc_html_e( 'For clients that speak HTTP MCP directly: MCP Inspector, IDEs with native HTTP MCP support, scripts.', 'iato-mcp' ); ?></p>
				<ol class="iato-method-steps">
					<li>
						<?php
						printf(
							/* translators: %s: link to WP user profile Application Passwords section */
							esc_html__( 'Generate a WordPress Application Password from %s.', 'iato-mcp' ),
							'<a href="' . esc_url( $profile ) . '" target="_blank" rel="noopener">' . esc_html__( 'your profile → Application Passwords', 'iato-mcp' ) . '</a>'
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							/* translators: %s: code-formatted credential pair */
							sprintf( __( 'Base64-encode %s.', 'iato-mcp' ), '<code>username:application_password</code>' ),
							[ 'code' => [] ]
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							/* translators: %s: code-formatted Authorization header */
							sprintf( __( 'Configure your client to send the header %s to the endpoint above.', 'iato-mcp' ), '<code>Authorization: Basic &lt;encoded&gt;</code>' ),
							[ 'code' => [] ]
						);
						?>
					</li>
				</ol>
			</div>

			<div class="iato-method-divider"><?php esc_html_e( '— or —', 'iato-mcp' ); ?></div>

			<div class="iato-wizard-card">
				<h2><?php esc_html_e( 'Manual config file (stdio clients)', 'iato-mcp' ); ?></h2>
				<p class="iato-method-subhead"><?php esc_html_e( 'For Claude Desktop config file, Cursor, Cline, Zed, and other stdio-only MCP clients.', 'iato-mcp' ); ?></p>
				<ol class="iato-method-steps">
					<li>
						<?php
						printf(
							/* translators: %s: link to WP user profile Application Passwords section */
							esc_html__( 'Generate a WordPress Application Password from %s.', 'iato-mcp' ),
							'<a href="' . esc_url( $profile ) . '" target="_blank" rel="noopener">' . esc_html__( 'your profile → Application Passwords', 'iato-mcp' ) . '</a>'
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							/* translators: %s: code-formatted credential pair */
							sprintf( __( 'Base64-encode %s and keep the result handy.', 'iato-mcp' ), '<code>username:application_password</code>' ),
							[ 'code' => [] ]
						);
						?>
					</li>
					<li>
						<?php
						echo wp_kses(
							/* translators: %s: code-formatted env var name */
							sprintf( __( 'Add this to your client\'s MCP config file, replacing the %s placeholder with your encoded value:', 'iato-mcp' ), '<code>IATO_AUTH</code>' ),
							[ 'code' => [] ]
						);
						?>
					</li>
				</ol>
				<pre class="iato-config"><?php echo esc_html( $config_snippet ); ?></pre>
			</div>

			<div class="iato-wizard-card">
				<h2><?php esc_html_e( 'IATO API key (optional)', 'iato-mcp' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: link to IATO account page */
						esc_html__( 'Add your IATO API key to unlock bridge read tools (SEO fixes, sitemap, orphan pages, broken links, suggestions). Get your key at %s.', 'iato-mcp' ),
						'<a href="https://iato.ai/#/account" target="_blank" rel="noopener">iato.ai</a>'
					);
					?>
				</p>
				<p style="font-size:13px;color:#50575e;margin-top:-4px">
					<?php
					printf(
						/* translators: %s: link to iato.ai dashboard */
						esc_html__( 'Before the bridge tools return data, you also need (a) at least one workspace on iato.ai (auto-created on signup), and (b) a completed crawl of this site. Start a crawl from your %s, then copy the crawl ID into Settings > IATO MCP > Default Crawl ID.', 'iato-mcp' ),
						'<a href="https://iato.ai/dashboard" target="_blank" rel="noopener">IATO dashboard</a>'
					);
					?>
				</p>
				<div class="field-group">
					<label for="iato-api-key"><?php esc_html_e( 'IATO API Key', 'iato-mcp' ); ?></label>
					<input type="password" id="iato-api-key" value="<?php echo esc_attr( $api_key ); ?>" placeholder="<?php esc_attr_e( 'Optional — paste your IATO API key', 'iato-mcp' ); ?>" />
				</div>
				<p>
					<button class="button" id="btn-save-key"><?php esc_html_e( 'Save API key', 'iato-mcp' ); ?></button>
				</p>
			</div>

			<div class="iato-wizard-actions">
				<button class="button button-primary" id="btn-finish"><?php esc_html_e( "I'm all set — finish setup", 'iato-mcp' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline styles.
	 */
	private static function get_inline_styles(): string {
		return <<<'CSS'
.iato-wizard { max-width: 820px; }
.iato-wizard-card { background: #fff; border: 1px solid #dcdcde; padding: 20px 24px; margin: 16px 0; border-radius: 4px; }
.iato-wizard-card.recommended { border-left: 4px solid #00a32a; }
.iato-wizard-card h2 { margin-top: 0; font-size: 16px; }
.iato-method-badge { display: inline-block; background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 8px; vertical-align: middle; }
.iato-method-subhead { font-size: 13px; color: #50575e; margin-top: -8px; margin-bottom: 12px; }
.iato-method-intro { margin-top: 12px; font-size: 13px; color: #50575e; }
.iato-method-divider { text-align: center; color: #8c8f94; font-size: 12px; margin: 8px 0; letter-spacing: 2px; text-transform: uppercase; }
.iato-method-steps { padding-left: 22px; margin: 8px 0 12px; }
.iato-method-steps li { margin: 4px 0; }
.iato-method-steps code, .iato-wizard-card p code { background: #f6f7f7; padding: 1px 6px; border-radius: 3px; font-family: Menlo, Consolas, monospace; font-size: 12px; }
.iato-endpoint { display: inline-block; background: #f6f7f7; padding: 8px 12px; font-family: Menlo, Consolas, monospace; font-size: 13px; border-radius: 3px; }
.iato-config { background: #1d2327; color: #e5e5e5; padding: 16px; border-radius: 3px; font-family: Menlo, Consolas, monospace; font-size: 12px; overflow-x: auto; white-space: pre; }
.field-group { margin: 8px 0; }
.field-group label { display: block; font-weight: 600; margin-bottom: 4px; }
.field-group input[type=password] { width: 100%; max-width: 420px; padding: 6px 8px; }
.iato-wizard-actions { margin-top: 24px; }
.iato-notice { padding: 10px 14px; border-radius: 3px; margin: 12px 0; }
.iato-notice.success { background: #d7f5dc; border-left: 4px solid #2e7d32; }
.iato-notice.error { background: #fde2e1; border-left: 4px solid #c62828; }
CSS;
	}

	/**
	 * Inline JS — save API key, finish wizard.
	 */
	private static function get_inline_scripts(): string {
		return <<<'JS'
(function() {
	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('_wpnonce', iatoWizard.nonce);
		Object.keys(data || {}).forEach(function(k) { body.append(k, data[k]); });
		return fetch(iatoWizard.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function(r) { return r.json(); });
	}

	function showMessage(msg, type) {
		var el = document.getElementById('iato-wizard-message');
		el.innerHTML = '<div class="iato-notice ' + type + '">' + msg + '</div>';
		setTimeout(function() { el.innerHTML = ''; }, 4000);
	}

	document.getElementById('btn-save-key').addEventListener('click', function(e) {
		e.preventDefault();
		var key = document.getElementById('iato-api-key').value.trim();
		post('iato_mcp_wizard_connect', { api_key: key }).then(function(r) {
			if (r.success) {
				showMessage('API key saved.', 'success');
			} else {
				showMessage(r.data || 'Failed to save API key.', 'error');
			}
		});
	});

	document.getElementById('btn-finish').addEventListener('click', function(e) {
		e.preventDefault();
		post('iato_mcp_wizard_complete', {}).then(function(r) {
			if (r.success) {
				window.location.href = iatoWizard.ajaxurl.replace('admin-ajax.php', 'options-general.php?page=iato-mcp');
			} else {
				showMessage(r.data || 'Failed to complete setup.', 'error');
			}
		});
	});
})();
JS;
	}

	// ── AJAX Handlers ────────────────────────────────────────────────────────

	/**
	 * Save (and optionally validate) the IATO API key.
	 *
	 * Empty key is allowed — the plugin works in WP-only mode without IATO.
	 */
	public static function ajax_connect(): void {
		check_ajax_referer( 'iato_mcp_wizard', '_wpnonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( $api_key === '' ) {
			delete_option( 'iato_mcp_api_key' );
			delete_option( 'iato_mcp_api_key_valid' );
			delete_option( 'iato_mcp_workspace_id' );
			wp_send_json_success( [ 'cleared' => true ] );
		}

		update_option( 'iato_mcp_api_key', $api_key );

		// Validate by listing workspaces — handles new {data: {workspaces: []}} shape.
		$workspaces = IATO_MCP_IATO_Client::list_workspaces();
		if ( is_wp_error( $workspaces ) ) {
			update_option( 'iato_mcp_api_key_valid', false );
			wp_send_json_error( 'Key saved but validation failed: ' . $workspaces->get_error_message() );
		}

		$ws_list = $workspaces['data']['workspaces'] ?? $workspaces['workspaces'] ?? [];
		if ( ! is_array( $ws_list ) || empty( $ws_list ) ) {
			update_option( 'iato_mcp_api_key_valid', false );
			wp_send_json_error( 'Key saved but no workspaces were returned. Create one at iato.ai first.' );
		}

		update_option( 'iato_mcp_api_key_valid', true );
		$workspace_id = (string) ( $ws_list[0]['id'] ?? '' );
		if ( $workspace_id !== '' ) {
			update_option( 'iato_mcp_workspace_id', $workspace_id );
		}

		wp_send_json_success( [ 'validated' => true ] );
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

<?php
/**
 * Settings page — registers "Settings > IATO MCP" in WP Admin.
 *
 * Fields:
 *   - iato_mcp_api_key      IATO API key (password input, stored in wp_options)
 *   - iato_mcp_crawl_id     Default crawl ID used as fallback by bridge tools
 *   - iato_mcp_tools        Array of enabled tool names (all enabled by default)
 *
 * On save: validate IATO API key by calling GET /api/v1/workspaces and checking 200.
 * On activation: show setup wizard admin notice with the plugin-generated MCP key.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Settings {

	/** Option group name. */
	private const OPTION_GROUP = 'iato_mcp_settings';

	/** Settings page slug. */
	private const PAGE_SLUG = 'iato-mcp';

	/** All tool names available for enable/disable toggles. */
	private const TOOL_NAMES = [
		'get_site_info',
		'get_site_settings',
		'get_posts',
		'get_post',
		'create_post',
		'update_post',
		'search_posts',
		'get_seo_data',
		'update_seo_data',
		'get_media',
		'update_alt_text',
		'get_comments',
		'get_menus',
		'get_menu_items',
		'update_menu_item',
		'get_terms',
		'assign_term',
	];

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_notices', [ self::class, 'setup_wizard_notice' ] );
		add_action( 'admin_post_iato_mcp_dismiss_wizard', [ self::class, 'dismiss_wizard' ] );
		add_action( 'admin_post_iato_mcp_regenerate_key', [ self::class, 'handle_regenerate_key' ] );
	}

	/**
	 * Check if a tool is enabled in settings.
	 */
	public static function is_tool_enabled( string $tool_name ): bool {
		$enabled = get_option( 'iato_mcp_tools', [] );

		// If option is empty (fresh install), all tools are enabled.
		if ( empty( $enabled ) ) {
			return true;
		}

		return in_array( $tool_name, $enabled, true );
	}

	// ── Admin Menu ───────────────────────────────────────────────────────────────

	public static function add_menu(): void {
		add_options_page(
			__( 'IATO MCP', 'iato-mcp' ),
			__( 'IATO MCP', 'iato-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	// ── Register Settings ────────────────────────────────────────────────────────

	public static function register_settings(): void {

		// --- IATO API Key ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_api_key', [
			'type'              => 'string',
			'sanitize_callback' => [ self::class, 'sanitize_api_key' ],
			'default'           => '',
		] );

		// --- Default Crawl ID ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_crawl_id', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		// --- Enabled Tools ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_tools', [
			'type'              => 'array',
			'sanitize_callback' => [ self::class, 'sanitize_tools' ],
			'default'           => [],
		] );

		// ── Section: MCP Connection ─────────────────────────────────────────────

		add_settings_section(
			'iato_mcp_connection_section',
			__( 'MCP Connection', 'iato-mcp' ),
			[ self::class, 'render_connection_section' ],
			self::PAGE_SLUG
		);

		// ── Section: IATO API Configuration ─────────────────────────────────────

		add_settings_section(
			'iato_mcp_api_section',
			__( 'IATO API Configuration', 'iato-mcp' ),
			function () {
				echo '<p>' . esc_html__( 'Connect your IATO account to enable bridge tools.', 'iato-mcp' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'iato_mcp_api_key',
			__( 'IATO API Key', 'iato-mcp' ),
			[ self::class, 'render_api_key_field' ],
			self::PAGE_SLUG,
			'iato_mcp_api_section'
		);

		add_settings_field(
			'iato_mcp_crawl_id',
			__( 'Default Crawl ID', 'iato-mcp' ),
			[ self::class, 'render_crawl_id_field' ],
			self::PAGE_SLUG,
			'iato_mcp_api_section'
		);

		// ── Section: Tools ──────────────────────────────────────────────────────

		add_settings_section(
			'iato_mcp_tools_section',
			__( 'Enabled Tools', 'iato-mcp' ),
			function () {
				echo '<p>' . esc_html__( 'Choose which MCP tools are available to AI clients. All tools are enabled by default.', 'iato-mcp' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'iato_mcp_tools',
			__( 'Tools', 'iato-mcp' ),
			[ self::class, 'render_tools_field' ],
			self::PAGE_SLUG,
			'iato_mcp_tools_section'
		);
	}

	// ── Section / Field Renderers ────────────────────────────────────────────────

	/**
	 * Render the MCP Connection section with the plugin-generated key and endpoint.
	 */
	public static function render_connection_section(): void {
		$key          = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );
		$endpoint     = site_url( '/wp-json/iato-mcp/v1/message' );
		$regenerate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=iato_mcp_regenerate_key' ),
			'iato_mcp_regenerate_key'
		);

		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP Endpoint', 'iato-mcp' ); ?></th>
				<td>
					<code id="iato-mcp-endpoint"><?php echo esc_html( $endpoint ); ?></code>
					<button type="button" class="button button-small iato-mcp-copy" data-target="iato-mcp-endpoint">
						<?php esc_html_e( 'Copy', 'iato-mcp' ); ?>
					</button>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP API Key', 'iato-mcp' ); ?></th>
				<td>
					<code id="iato-mcp-key"><?php echo esc_html( $key ); ?></code>
					<button type="button" class="button button-small iato-mcp-copy" data-target="iato-mcp-key">
						<?php esc_html_e( 'Copy', 'iato-mcp' ); ?>
					</button>
					<a href="<?php echo esc_url( $regenerate_url ); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Regenerate key? Existing clients will need the new key.', 'iato-mcp' ) ); ?>');">
						<?php esc_html_e( 'Regenerate Key', 'iato-mcp' ); ?>
					</a>
					<p class="description"><?php esc_html_e( 'Use this key in the Authorization: Bearer header when connecting AI clients.', 'iato-mcp' ); ?></p>
				</td>
			</tr>
		</table>
		<script>
		document.querySelectorAll('.iato-mcp-copy').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var text = document.getElementById(btn.getAttribute('data-target')).textContent;
				navigator.clipboard.writeText(text).then(function() {
					var orig = btn.textContent;
					btn.textContent = '<?php echo esc_js( __( 'Copied!', 'iato-mcp' ) ); ?>';
					setTimeout(function() { btn.textContent = orig; }, 2000);
				});
			});
		});
		</script>
		<?php
	}

	public static function render_api_key_field(): void {
		$value = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		printf(
			'<input type="password" name="iato_mcp_api_key" value="%s" class="regular-text" autocomplete="off" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Your IATO API key. Validated on save.', 'iato-mcp' ) . '</p>';
	}

	public static function render_crawl_id_field(): void {
		$value = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		printf(
			'<input type="text" name="iato_mcp_crawl_id" value="%s" class="regular-text" />',
			esc_attr( $value )
		);
		echo '<p class="description">' . esc_html__( 'Default crawl ID used by bridge tools when no crawl_id is specified.', 'iato-mcp' ) . '</p>';
	}

	public static function render_tools_field(): void {
		$enabled = get_option( 'iato_mcp_tools', [] );
		$all_on  = empty( $enabled );

		echo '<fieldset>';
		foreach ( self::TOOL_NAMES as $tool ) {
			$checked = $all_on || in_array( $tool, $enabled, true );
			printf(
				'<label><input type="checkbox" name="iato_mcp_tools[]" value="%s" %s /> <code>%s</code></label><br />',
				esc_attr( $tool ),
				checked( $checked, true, false ),
				esc_html( $tool )
			);
		}
		echo '</fieldset>';
	}

	// ── Sanitize Callbacks ───────────────────────────────────────────────────────

	/**
	 * Sanitize and validate the IATO API key on save.
	 */
	public static function sanitize_api_key( string $value ): string {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return '';
		}

		// Validate by calling IATO API.
		$response = wp_remote_get( 'https://iato.ai/api/v1/workspaces', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $value,
				'Accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			add_settings_error(
				'iato_mcp_api_key',
				'iato_mcp_api_key_error',
				__( 'Could not reach the IATO API. Please check your connection and try again.', 'iato-mcp' ),
				'error'
			);
			return $value;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			add_settings_error(
				'iato_mcp_api_key',
				'iato_mcp_api_key_invalid',
				/* translators: %d: HTTP status code */
				sprintf( __( 'IATO API key validation failed (HTTP %d). Please check your key.', 'iato-mcp' ), $code ),
				'error'
			);
			return $value;
		}

		add_settings_error(
			'iato_mcp_api_key',
			'iato_mcp_api_key_valid',
			__( 'IATO API key validated successfully.', 'iato-mcp' ),
			'updated'
		);

		return $value;
	}

	/**
	 * Sanitize the enabled tools array.
	 */
	public static function sanitize_tools( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_intersect( array_map( 'sanitize_text_field', $value ), self::TOOL_NAMES ) );
	}

	// ── Settings Page ────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// ── Setup Wizard Notice ──────────────────────────────────────────────────────

	public static function setup_wizard_notice(): void {
		if ( ! get_option( 'iato_mcp_show_wizard' ) ) {
			return;
		}
		if ( get_option( 'iato_mcp_wizard_dismissed' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key          = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );
		$site_url     = site_url();
		$settings_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$dismiss_url  = wp_nonce_url( admin_url( 'admin-post.php?action=iato_mcp_dismiss_wizard' ), 'iato_mcp_dismiss_wizard' );

		$config_json = wp_json_encode( [
			'mcpServers' => [
				'wordpress' => [
					'url'     => $site_url . '/wp-json/iato-mcp/v1/message',
					'headers' => [
						'Authorization' => 'Bearer ' . $key,
					],
				],
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		?>
		<div class="notice notice-info">
			<h3><?php esc_html_e( 'IATO MCP — Setup', 'iato-mcp' ); ?></h3>
			<p><?php esc_html_e( 'Your MCP server is ready. Copy the config below into your Claude Desktop settings:', 'iato-mcp' ); ?></p>
			<pre id="iato-mcp-wizard-config" style="background:#f0f0f0;padding:10px;overflow-x:auto;position:relative;"><?php echo esc_html( $config_json ); ?></pre>
			<p>
				<button type="button" class="button button-primary" onclick="navigator.clipboard.writeText(document.getElementById('iato-mcp-wizard-config').textContent).then(function(){var b=event.target;b.textContent='<?php echo esc_js( __( 'Copied!', 'iato-mcp' ) ); ?>';setTimeout(function(){b.textContent='<?php echo esc_js( __( 'Copy Config', 'iato-mcp' ) ); ?>';},2000);});">
					<?php esc_html_e( 'Copy Config', 'iato-mcp' ); ?>
				</button>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: link to settings page */
					esc_html__( '(Optional) Enter your IATO API key in %s to enable bridge tools.', 'iato-mcp' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings > IATO MCP', 'iato-mcp' ) . '</a>'
				);
				?>
			</p>
			<p><a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-small"><?php esc_html_e( 'Dismiss', 'iato-mcp' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Handle wizard dismiss action.
	 */
	public static function dismiss_wizard(): void {
		check_admin_referer( 'iato_mcp_dismiss_wizard' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}

		update_option( 'iato_mcp_wizard_dismissed', true );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle MCP key regeneration.
	 */
	public static function handle_regenerate_key(): void {
		check_admin_referer( 'iato_mcp_regenerate_key' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'iato-mcp' ) );
		}

		IATO_MCP_Auth::rotate_key();

		add_settings_error(
			'iato_mcp_key',
			'iato_mcp_key_regenerated',
			__( 'MCP API key regenerated. Update your AI client configuration with the new key.', 'iato-mcp' ),
			'updated'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&settings-updated=true' ) );
		exit;
	}
}

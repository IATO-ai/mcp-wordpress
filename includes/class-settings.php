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
		'create_menu_item',
		'delete_menu_item',
		'update_menu_item_details',
		'get_terms',
		'assign_term',
		'create_term',
		'update_term',
		'delete_term',
		'update_taxonomy',
		'update_canonical',
		'update_structured_data',
		'update_redirect',
	];

	/** Tool descriptions for UI display. */
	private const TOOL_DESCRIPTIONS = [
		'get_site_info'            => 'Basic site information and health',
		'get_site_settings'        => 'WordPress settings (admin only)',
		'get_posts'                => 'List published posts with filters',
		'get_post'                 => 'Single post details and meta',
		'create_post'              => 'Create new posts and pages',
		'update_post'              => 'Edit existing post content',
		'search_posts'             => 'Full-text search across posts',
		'get_seo_data'             => 'Read SEO meta fields',
		'update_seo_data'          => 'Update SEO titles and descriptions',
		'get_media'                => 'List media library items',
		'update_alt_text'          => 'Update image alt text',
		'get_comments'             => 'List and filter comments',
		'get_menus'                => 'List navigation menus',
		'get_menu_items'           => 'Menu item details and structure',
		'update_menu_item'         => 'Add items to a menu (admin only)',
		'create_menu_item'         => 'Create new menu items (admin only)',
		'delete_menu_item'         => 'Remove menu items (admin only)',
		'update_menu_item_details' => 'Edit menu item properties (admin only)',
		'get_terms'                => 'List categories, tags, and terms',
		'assign_term'              => 'Assign terms to posts',
		'create_term'              => 'Create new taxonomy terms (admin only)',
		'update_term'              => 'Edit existing terms (admin only)',
		'delete_term'              => 'Remove taxonomy terms (admin only)',
		'update_taxonomy'          => 'Replace all terms on a post',
		'update_canonical'         => 'Set canonical URL for a post',
		'update_structured_data'   => 'Add JSON-LD structured data',
		'update_redirect'          => 'Create or update redirect rules (admin only)',
	];

	/** Tool groupings for UI categories. */
	private const TOOL_CATEGORIES = [
		'Content'    => [ 'get_posts', 'get_post', 'create_post', 'update_post', 'search_posts' ],
		'Site'       => [ 'get_site_info', 'get_site_settings' ],
		'SEO'        => [ 'get_seo_data', 'update_seo_data', 'update_canonical', 'update_structured_data' ],
		'Media'      => [ 'get_media', 'update_alt_text' ],
		'Navigation' => [ 'get_menus', 'get_menu_items', 'update_menu_item', 'create_menu_item', 'delete_menu_item', 'update_menu_item_details' ],
		'Taxonomy'   => [ 'get_terms', 'assign_term', 'create_term', 'update_term', 'delete_term', 'update_taxonomy' ],
		'Redirects'  => [ 'update_redirect' ],
		'Comments'   => [ 'get_comments' ],
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

		// --- Autopilot Enabled ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_autopilot_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => [ self::class, 'sanitize_autopilot_enabled' ],
			'default'           => false,
		] );

		// --- Governance Policy ---
		register_setting( self::OPTION_GROUP, 'iato_mcp_governance_policy', [
			'type'              => 'array',
			'sanitize_callback' => [ self::class, 'sanitize_governance_policy' ],
			'default'           => [],
		] );
	}

	// ── Sanitize Callbacks ───────────────────────────────────────────────────────

	/**
	 * Sanitize and validate the IATO API key on save.
	 */
	public static function sanitize_api_key( string $value ): string {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			delete_option( 'iato_mcp_api_key_valid' );
			return '';
		}

		// Validate by calling IATO API.
		$response = wp_remote_get( 'https://iato.ai/api/workspaces', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $value,
				'Accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			update_option( 'iato_mcp_api_key_valid', false );
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
			update_option( 'iato_mcp_api_key_valid', false );
			add_settings_error(
				'iato_mcp_api_key',
				'iato_mcp_api_key_invalid',
				/* translators: %d: HTTP status code */
				sprintf( __( 'IATO API key validation failed (HTTP %d). Please check your key.', 'iato-mcp' ), $code ),
				'error'
			);
			return $value;
		}

		update_option( 'iato_mcp_api_key_valid', true );
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

	/**
	 * Sanitize autopilot enabled toggle and sync to IATO API.
	 */
	public static function sanitize_autopilot_enabled( $value ): bool {
		$enabled      = (bool) $value;
		$workspace_id = sanitize_text_field( get_option( 'iato_mcp_workspace_id', '' ) );

		if ( $workspace_id ) {
			$result = IATO_MCP_IATO_Client::update_governance_policy( $workspace_id, [
				'is_active' => $enabled,
			] );
			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'iato_mcp_autopilot_enabled',
					'iato_mcp_autopilot_sync_error',
					__( 'Autopilot setting saved locally but failed to sync with IATO.', 'iato-mcp' ),
					'warning'
				);
			} else {
				update_option( 'iato_mcp_policy_synced_at', current_time( 'mysql' ) );
			}
		}

		return $enabled;
	}

	/** Allowed AI tone values. */
	private const ALLOWED_TONES = [ 'professional', 'casual', 'technical', 'friendly' ];

	/** Allowed auto-fix rule types. */
	private const ALLOWED_RULE_TYPES = [ 'title', 'meta_description', 'alt_text', 'canonical' ];

	/**
	 * Sanitize governance policy array and sync to IATO API.
	 */
	public static function sanitize_governance_policy( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		// Build sanitized rules.
		$rules = [];
		$raw_rules = $value['rules'] ?? [];
		foreach ( self::ALLOWED_RULE_TYPES as $type ) {
			$rules[ $type ] = [
				'action' => ! empty( $raw_rules[ $type ] ) ? 'auto_fix' : 'needs_review',
			];
		}

		$tone = sanitize_text_field( $value['ai_tone'] ?? 'professional' );
		if ( ! in_array( $tone, self::ALLOWED_TONES, true ) ) {
			$tone = 'professional';
		}

		$policy = [
			'rules'            => $rules,
			'ai_tone'          => $tone,
			'ai_brand_context' => sanitize_textarea_field( $value['ai_brand_context'] ?? '' ),
			'cms_integration'  => 'wordpress',
		];

		// Sync to IATO API (best-effort).
		$workspace_id = sanitize_text_field( get_option( 'iato_mcp_workspace_id', '' ) );
		if ( $workspace_id ) {
			$result = IATO_MCP_IATO_Client::update_governance_policy( $workspace_id, $policy );
			if ( ! is_wp_error( $result ) ) {
				update_option( 'iato_mcp_policy_synced_at', current_time( 'mysql' ) );
			}
		}

		return $policy;
	}

	// ── Settings Page ────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mcp_key      = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );
		$endpoint     = site_url( '/wp-json/iato-mcp/v1/message' );
		$iato_api_key = sanitize_text_field( get_option( 'iato_mcp_api_key', '' ) );
		$crawl_id     = sanitize_text_field( get_option( 'iato_mcp_crawl_id', '' ) );
		$enabled      = get_option( 'iato_mcp_tools', [] );
		$all_on       = empty( $enabled );

		// Autopilot & Governance Policy.
		$api_valid          = (bool) get_option( 'iato_mcp_api_key_valid', false );
		$autopilot_enabled  = (bool) get_option( 'iato_mcp_autopilot_enabled', false );
		$governance_policy  = get_option( 'iato_mcp_governance_policy', [] );
		$workspace_id       = sanitize_text_field( get_option( 'iato_mcp_workspace_id', '' ) );
		$policy_synced_at   = get_option( 'iato_mcp_policy_synced_at', '' );

		// Fetch from IATO API on first load if local cache is empty.
		if ( empty( $governance_policy ) && $iato_api_key && $api_valid && $workspace_id ) {
			$remote_policy = IATO_MCP_IATO_Client::get_governance_policy( $workspace_id );
			if ( ! is_wp_error( $remote_policy ) && is_array( $remote_policy ) ) {
				$governance_policy = $remote_policy;
				$autopilot_enabled = ! empty( $remote_policy['is_active'] );
				update_option( 'iato_mcp_governance_policy', $governance_policy );
				update_option( 'iato_mcp_autopilot_enabled', $autopilot_enabled );
				update_option( 'iato_mcp_policy_synced_at', current_time( 'mysql' ) );
				$policy_synced_at = get_option( 'iato_mcp_policy_synced_at', '' );
			}
		}

		// Fall back to wizard's local policy.
		if ( empty( $governance_policy ) ) {
			$governance_policy = get_option( 'iato_mcp_local_policy', [] );
		}

		// Extract policy fields with defaults.
		$policy_rules   = $governance_policy['rules'] ?? [];
		$policy_tone    = $governance_policy['ai_tone'] ?? 'professional';
		$policy_brand   = $governance_policy['ai_brand_context'] ?? '';

		$regenerate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=iato_mcp_regenerate_key' ),
			'iato_mcp_regenerate_key'
		);

		$masked_key = '';
		if ( $mcp_key ) {
			$masked_key = substr( $mcp_key, 0, 8 ) . '••••••••' . substr( $mcp_key, -4 );
		}

		$config_json = wp_json_encode( [
			'mcpServers' => [
				'wordpress' => [
					'url'     => $endpoint,
					'headers' => [
						'Authorization' => 'Bearer ' . $mcp_key,
					],
				],
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$enabled_count = $all_on ? count( self::TOOL_NAMES ) : count( $enabled );
		$total_count   = count( self::TOOL_NAMES );

		wp_enqueue_style( 'iato-mcp-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono&display=swap', [], null );
		self::render_styles();
		?>
		<div class="iato-wrap">
			<div class="iato-header">
				<div class="iato-header-top">
					<h1 class="iato-title"><img src="<?php echo esc_url( IATO_MCP_URL . 'assets/img/logo.png' ); ?>" alt="IATO" height="36" style="vertical-align: middle;" /> <span class="iato-title-mcp">MCP</span></h1>
					<span class="iato-version">v<?php echo esc_html( IATO_MCP_VERSION ); ?></span>
				</div>
				<p class="iato-subtitle"><?php esc_html_e( 'Model Context Protocol server for WordPress', 'iato-mcp' ); ?></p>
			</div>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<!-- Card 1: MCP Connection -->
				<div class="iato-card iato-card--hero">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-admin-network"></span>
							<h2><?php esc_html_e( 'MCP Connection', 'iato-mcp' ); ?></h2>
						</div>
						<?php if ( $mcp_key ) : ?>
							<span class="iato-badge iato-badge--success"><?php esc_html_e( 'Ready', 'iato-mcp' ); ?></span>
						<?php else : ?>
							<span class="iato-badge iato-badge--warning"><?php esc_html_e( 'Key Missing', 'iato-mcp' ); ?></span>
						<?php endif; ?>
					</div>

					<div class="iato-field-row">
						<label class="iato-label"><?php esc_html_e( 'Endpoint URL', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<div class="iato-code-block">
								<code id="iato-endpoint"><?php echo esc_html( $endpoint ); ?></code>
								<button type="button" class="iato-copy-btn" data-target="iato-endpoint" title="<?php esc_attr_e( 'Copy', 'iato-mcp' ); ?>">
									<span class="dashicons dashicons-clipboard"></span>
								</button>
							</div>
						</div>
					</div>

					<div class="iato-field-row">
						<label class="iato-label"><?php esc_html_e( 'API Key', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<div class="iato-key-row">
								<div class="iato-code-block">
									<code id="iato-mcp-key" class="iato-key-masked" data-full="<?php echo esc_attr( $mcp_key ); ?>"><?php echo esc_html( $masked_key ); ?></code>
									<button type="button" class="iato-copy-btn" data-copy-value="<?php echo esc_attr( $mcp_key ); ?>" title="<?php esc_attr_e( 'Copy key', 'iato-mcp' ); ?>">
										<span class="dashicons dashicons-clipboard"></span>
									</button>
								</div>
								<button type="button" class="iato-reveal-btn" data-target="iato-mcp-key" title="<?php esc_attr_e( 'Show/hide key', 'iato-mcp' ); ?>">
									<span class="dashicons dashicons-visibility"></span>
								</button>
								<a href="<?php echo esc_url( $regenerate_url ); ?>" class="iato-btn iato-btn--danger" onclick="return confirm('<?php echo esc_js( __( 'Regenerate key? Existing clients will need the new key.', 'iato-mcp' ) ); ?>');">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Regenerate', 'iato-mcp' ); ?>
								</a>
							</div>
							<p class="iato-hint"><?php esc_html_e( 'Used in the Authorization: Bearer header. Keep this secret.', 'iato-mcp' ); ?></p>
						</div>
					</div>

					<div class="iato-config-section">
						<h3 class="iato-config-title"><?php esc_html_e( 'Claude Desktop Configuration', 'iato-mcp' ); ?></h3>
						<p class="iato-hint"><?php esc_html_e( 'Paste this into your Claude Desktop settings to connect:', 'iato-mcp' ); ?></p>
						<div class="iato-config-block">
							<pre id="iato-config-json"><?php echo esc_html( $config_json ); ?></pre>
							<button type="button" class="iato-copy-btn iato-copy-btn--config" data-target="iato-config-json" title="<?php esc_attr_e( 'Copy config', 'iato-mcp' ); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<span class="iato-copy-label"><?php esc_html_e( 'Copy', 'iato-mcp' ); ?></span>
							</button>
						</div>
					</div>
				</div>

				<!-- Card 2: IATO Platform -->
				<div class="iato-card">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-cloud"></span>
							<h2><?php esc_html_e( 'IATO Platform', 'iato-mcp' ); ?></h2>
						</div>
						<?php if ( $iato_api_key && $api_valid ) : ?>
							<span class="iato-badge iato-badge--success"><?php esc_html_e( 'Connected', 'iato-mcp' ); ?></span>
						<?php elseif ( $iato_api_key ) : ?>
							<span class="iato-badge iato-badge--danger"><?php esc_html_e( 'Key Invalid', 'iato-mcp' ); ?></span>
						<?php else : ?>
							<span class="iato-badge iato-badge--neutral"><?php esc_html_e( 'Not connected', 'iato-mcp' ); ?></span>
						<?php endif; ?>
					</div>
					<p class="iato-card-desc"><?php esc_html_e( 'Connect your IATO account to enable bridge tools for sitemap analysis, SEO audits, and performance reports.', 'iato-mcp' ); ?></p>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_api_key"><?php esc_html_e( 'API Key', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<div class="iato-input-group">
								<input type="password" name="iato_mcp_api_key" id="iato_mcp_api_key" value="<?php echo esc_attr( $iato_api_key ); ?>" class="iato-input" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter your IATO API key', 'iato-mcp' ); ?>" />
								<button type="button" class="iato-input-toggle" data-toggle="iato_mcp_api_key" title="<?php esc_attr_e( 'Show/hide', 'iato-mcp' ); ?>">
									<span class="dashicons dashicons-visibility"></span>
								</button>
							</div>
							<p class="iato-hint"><?php esc_html_e( 'Get your API key from your IATO dashboard. Validated on save.', 'iato-mcp' ); ?></p>
						</div>
					</div>

					<div class="iato-field-row">
						<label class="iato-label" for="iato_mcp_crawl_id"><?php esc_html_e( 'Default Crawl ID', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<input type="text" name="iato_mcp_crawl_id" id="iato_mcp_crawl_id" value="<?php echo esc_attr( $crawl_id ); ?>" class="iato-input" placeholder="<?php esc_attr_e( 'e.g. crawl_abc123', 'iato-mcp' ); ?>" />
							<p class="iato-hint"><?php esc_html_e( 'Used by bridge tools when no crawl ID is specified in the request.', 'iato-mcp' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Card 3: Autopilot & Governance Policy -->
				<div class="iato-card">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-controls-repeat"></span>
							<h2><?php esc_html_e( 'Autopilot', 'iato-mcp' ); ?></h2>
						</div>
						<?php if ( $autopilot_enabled ) : ?>
							<span class="iato-badge iato-badge--success"><?php esc_html_e( 'Enabled', 'iato-mcp' ); ?></span>
						<?php else : ?>
							<span class="iato-badge iato-badge--neutral"><?php esc_html_e( 'Disabled', 'iato-mcp' ); ?></span>
						<?php endif; ?>
					</div>
					<p class="iato-card-desc"><?php esc_html_e( 'When enabled, IATO automatically fixes SEO issues based on your policy rules. When disabled, all issues go to the Review Queue for manual review.', 'iato-mcp' ); ?></p>

					<div class="iato-field-row">
						<label class="iato-label"><?php esc_html_e( 'Enable Autopilot', 'iato-mcp' ); ?></label>
						<div class="iato-field-value">
							<label class="iato-toggle iato-autopilot-toggle">
								<input type="checkbox" name="iato_mcp_autopilot_enabled" value="1" id="iato-autopilot-toggle" <?php checked( $autopilot_enabled ); ?> />
								<span class="iato-toggle-slider" role="switch" aria-checked="<?php echo $autopilot_enabled ? 'true' : 'false'; ?>"></span>
							</label>
						</div>
					</div>

					<div class="iato-policy-section" id="iato-policy-section" style="<?php echo ! $autopilot_enabled ? 'display:none' : ''; ?>">

						<h3 class="iato-section-title"><?php esc_html_e( 'Auto-Fix Rules', 'iato-mcp' ); ?></h3>
						<p class="iato-hint" style="margin-bottom:12px"><?php esc_html_e( 'Choose which issue types Autopilot can fix automatically. Unchecked types will be sent to the Review Queue.', 'iato-mcp' ); ?></p>

						<div class="iato-policy-rules">
							<?php
							$rule_labels = [
								'title'            => __( 'Auto-fix missing/poor SEO titles', 'iato-mcp' ),
								'meta_description' => __( 'Auto-fix missing meta descriptions', 'iato-mcp' ),
								'alt_text'         => __( 'Auto-fix missing image alt text', 'iato-mcp' ),
								'canonical'        => __( 'Auto-fix canonical URL issues', 'iato-mcp' ),
							];
							foreach ( $rule_labels as $rule_key => $rule_label ) :
								$rule_active = ( ( $policy_rules[ $rule_key ]['action'] ?? 'needs_review' ) === 'auto_fix' );
							?>
								<label class="iato-tool-item">
									<div class="iato-toggle">
										<input type="checkbox" name="iato_mcp_governance_policy[rules][<?php echo esc_attr( $rule_key ); ?>]" value="1" <?php checked( $rule_active ); ?> />
										<span class="iato-toggle-slider" role="switch" aria-checked="<?php echo $rule_active ? 'true' : 'false'; ?>"></span>
									</div>
									<div class="iato-tool-info">
										<span class="iato-tool-desc"><?php echo esc_html( $rule_label ); ?></span>
									</div>
								</label>
							<?php endforeach; ?>
						</div>

						<h3 class="iato-section-title" style="margin-top:20px"><?php esc_html_e( 'AI Writing Style', 'iato-mcp' ); ?></h3>

						<div class="iato-field-row">
							<label class="iato-label" for="iato-policy-tone"><?php esc_html_e( 'Tone', 'iato-mcp' ); ?></label>
							<div class="iato-field-value">
								<select name="iato_mcp_governance_policy[ai_tone]" id="iato-policy-tone" class="iato-input">
									<option value="professional" <?php selected( $policy_tone, 'professional' ); ?>><?php esc_html_e( 'Professional', 'iato-mcp' ); ?></option>
									<option value="casual" <?php selected( $policy_tone, 'casual' ); ?>><?php esc_html_e( 'Casual', 'iato-mcp' ); ?></option>
									<option value="technical" <?php selected( $policy_tone, 'technical' ); ?>><?php esc_html_e( 'Technical', 'iato-mcp' ); ?></option>
									<option value="friendly" <?php selected( $policy_tone, 'friendly' ); ?>><?php esc_html_e( 'Friendly', 'iato-mcp' ); ?></option>
								</select>
							</div>
						</div>

						<div class="iato-field-row">
							<label class="iato-label" for="iato-policy-brand"><?php esc_html_e( 'Brand Context', 'iato-mcp' ); ?></label>
							<div class="iato-field-value">
								<textarea name="iato_mcp_governance_policy[ai_brand_context]" id="iato-policy-brand" class="iato-input" rows="3" placeholder="<?php esc_attr_e( 'e.g., We are a B2B SaaS company focused on...', 'iato-mcp' ); ?>"><?php echo esc_textarea( $policy_brand ); ?></textarea>
								<p class="iato-hint"><?php esc_html_e( 'Provide brand context to guide AI-generated content. Optional.', 'iato-mcp' ); ?></p>
							</div>
						</div>
					</div>

					<?php if ( $policy_synced_at ) : ?>
						<p class="iato-hint" style="margin-top:12px">
							<?php
							/* translators: %s: date/time of last sync */
							printf( esc_html__( 'Last synced with IATO: %s', 'iato-mcp' ), esc_html( $policy_synced_at ) );
							?>
						</p>
					<?php elseif ( $iato_api_key && ! $api_valid ) : ?>
						<p class="iato-hint" style="margin-top:12px;color:var(--iato-warning)"><?php esc_html_e( 'Local only — IATO API key is invalid.', 'iato-mcp' ); ?></p>
					<?php elseif ( ! $iato_api_key ) : ?>
						<p class="iato-hint" style="margin-top:12px"><?php esc_html_e( 'Local only — connect IATO API to sync.', 'iato-mcp' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Card 4: Tools -->
				<div class="iato-card">
					<div class="iato-card-header">
						<div class="iato-card-title">
							<span class="dashicons dashicons-admin-tools"></span>
							<h2><?php esc_html_e( 'Tools', 'iato-mcp' ); ?></h2>
						</div>
						<span class="iato-tools-count" id="iato-tools-count">
							<?php
							/* translators: %1$d: enabled count, %2$d: total count */
							printf( esc_html__( '%1$d of %2$d enabled', 'iato-mcp' ), (int) $enabled_count, (int) $total_count );
							?>
						</span>
					</div>
					<p class="iato-card-desc"><?php esc_html_e( 'Choose which MCP tools are available to AI clients.', 'iato-mcp' ); ?></p>

					<?php foreach ( self::TOOL_CATEGORIES as $category => $tools ) : ?>
						<div class="iato-tool-category">
							<div class="iato-tool-category-header">
								<h3><?php echo esc_html( $category ); ?></h3>
								<div class="iato-tool-category-actions">
									<button type="button" class="iato-link-btn iato-select-all"><?php esc_html_e( 'All', 'iato-mcp' ); ?></button>
									<span class="iato-separator">|</span>
									<button type="button" class="iato-link-btn iato-select-none"><?php esc_html_e( 'None', 'iato-mcp' ); ?></button>
								</div>
							</div>
							<div class="iato-tool-grid">
								<?php foreach ( $tools as $tool ) :
									$checked = $all_on || in_array( $tool, $enabled, true );
									$desc    = self::TOOL_DESCRIPTIONS[ $tool ] ?? '';
								?>
									<label class="iato-tool-item">
										<div class="iato-toggle">
											<input type="checkbox" name="iato_mcp_tools[]" value="<?php echo esc_attr( $tool ); ?>" <?php checked( $checked ); ?> />
											<span class="iato-toggle-slider" role="switch" aria-checked="<?php echo $checked ? 'true' : 'false'; ?>"></span>
										</div>
										<div class="iato-tool-info">
											<code class="iato-tool-name"><?php echo esc_html( $tool ); ?></code>
											<?php if ( $desc ) : ?>
												<span class="iato-tool-desc"><?php echo esc_html( $desc ); ?></span>
											<?php endif; ?>
										</div>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="iato-submit">
					<?php submit_button( __( 'Save Settings', 'iato-mcp' ), 'primary large', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<?php self::render_scripts(); ?>
		<?php
	}

	// ── Styles ───────────────────────────────────────────────────────────────────

	private static function render_styles(): void {
		?>
		<style>
			/* ── Reset & Variables ──────────────────────────────── */
			.iato-wrap {
				--iato-primary: #5a89f4;
				--iato-primary-hover: #3f64b8;
				--iato-primary-light: rgba(90,137,244,0.12);
				--iato-primary-btn: #4b72cc;
				--iato-success: #38d68e;
				--iato-success-bg: rgba(56,214,142,0.12);
				--iato-warning: #eda145;
				--iato-warning-bg: rgba(237,161,69,0.12);
				--iato-danger: #ef4444;
				--iato-danger-bg: rgba(239,68,68,0.12);
				--iato-neutral: #6b7280;
				--iato-neutral-bg: #f3f4f6;
				--iato-bg: #f3f4f6;
				--iato-card-bg: #ffffff;
				--iato-border: #e5e7eb;
				--iato-text: #111827;
				--iato-text-secondary: #6b7280;
				--iato-text-muted: #9ca3af;
				--iato-code-bg: #0b0d17;
				--iato-code-text: #e6e8f0;
				--iato-radius: 12px;
				--iato-radius-sm: 8px;

				max-width: 860px;
				margin: 20px auto 40px;
				padding: 0 20px;
				font-family: 'DM Sans', system-ui, sans-serif;
			}

			/* ── Header ────────────────────────────────────────── */
			.iato-header {
				margin-bottom: 24px;
			}
			.iato-header-top {
				display: flex;
				align-items: center;
				gap: 12px;
			}
			.iato-title {
				font-size: 28px;
				font-weight: 400;
				font-family: 'Instrument Serif', Georgia, serif;
				color: var(--iato-primary);
				margin: 0;
				letter-spacing: -0.5px;
			}
			.iato-title-mcp {
				font-weight: 400;
				color: var(--iato-text-secondary);
			}
			.iato-version {
				display: inline-block;
				padding: 2px 10px;
				font-size: 12px;
				font-weight: 600;
				color: var(--iato-primary);
				background: var(--iato-primary-light);
				border-radius: 20px;
			}
			.iato-subtitle {
				margin: 4px 0 0;
				color: var(--iato-text-secondary);
				font-size: 14px;
			}

			/* ── Cards ─────────────────────────────────────────── */
			.iato-card {
				background: var(--iato-card-bg);
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius);
				padding: 24px;
				margin-bottom: 20px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.06);
				transition: background 0.2s, box-shadow 0.2s;
			}
			.iato-card--hero {
				border-left: 4px solid var(--iato-primary);
			}
			.iato-card-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 20px;
				padding-bottom: 16px;
				border-bottom: 1px solid var(--iato-border);
			}
			.iato-card-title {
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.iato-card-title .dashicons {
				font-size: 22px;
				width: 22px;
				height: 22px;
				color: var(--iato-primary);
			}
			.iato-card-title h2 {
				margin: 0;
				font-size: 17px;
				font-weight: 600;
				color: var(--iato-text);
			}
			.iato-card-desc {
				color: var(--iato-text-secondary);
				font-size: 13px;
				margin: -12px 0 20px;
			}

			/* ── Badges ────────────────────────────────────────── */
			.iato-badge {
				display: inline-flex;
				align-items: center;
				gap: 5px;
				padding: 4px 12px;
				font-size: 12px;
				font-weight: 600;
				border-radius: 99px;
				line-height: 1;
			}
			.iato-badge--success {
				color: var(--iato-success);
				background: var(--iato-success-bg);
			}
			.iato-badge--success::before {
				content: '';
				display: inline-block;
				width: 7px;
				height: 7px;
				background: var(--iato-success);
				border-radius: 50%;
			}
			.iato-badge--warning {
				color: var(--iato-warning);
				background: var(--iato-warning-bg);
			}
			.iato-badge--warning::before {
				content: '';
				display: inline-block;
				width: 7px;
				height: 7px;
				background: var(--iato-warning);
				border-radius: 50%;
			}
			.iato-badge--neutral {
				color: var(--iato-neutral);
				background: var(--iato-neutral-bg);
			}
			.iato-badge--danger {
				color: var(--iato-danger);
				background: var(--iato-danger-bg);
			}
			.iato-badge--danger::before {
				content: '';
				display: inline-block;
				width: 7px;
				height: 7px;
				background: var(--iato-danger);
				border-radius: 50%;
			}

			/* ── Field Rows ────────────────────────────────────── */
			.iato-field-row {
				display: flex;
				gap: 16px;
				margin-bottom: 20px;
			}
			.iato-field-row:last-child {
				margin-bottom: 0;
			}
			.iato-label {
				flex: 0 0 140px;
				font-size: 13px;
				font-weight: 600;
				color: var(--iato-text);
				padding-top: 8px;
			}
			.iato-field-value {
				flex: 1;
				min-width: 0;
			}
			.iato-hint {
				margin: 6px 0 0;
				font-size: 12px;
				color: var(--iato-text-muted);
			}

			/* ── Code Blocks (light) ──────────────────────────── */
			.iato-code-block {
				display: flex;
				align-items: center;
				background: var(--iato-bg);
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius-sm);
				padding: 8px 12px;
				gap: 8px;
				min-width: 0;
			}
			.iato-code-block code {
				flex: 1;
				font-size: 13px;
				color: var(--iato-text);
				background: none;
				padding: 0;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
			}

			/* ── Key Row ──────────────────────────────────────── */
			.iato-key-row {
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.iato-key-row .iato-code-block {
				flex: 1;
			}
			.iato-key-masked {
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
				letter-spacing: 0.5px;
			}

			/* ── Copy Button ──────────────────────────────────── */
			.iato-copy-btn {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 4px 8px;
				border: none;
				background: transparent;
				color: var(--iato-text-muted);
				cursor: pointer;
				border-radius: 4px;
				transition: color 0.15s, background 0.15s;
				flex-shrink: 0;
			}
			.iato-copy-btn:hover {
				color: var(--iato-primary);
				background: var(--iato-primary-light);
			}
			.iato-copy-btn .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}
			.iato-copy-btn.copied {
				color: var(--iato-success);
			}

			/* ── Reveal Button ────────────────────────────────── */
			.iato-reveal-btn {
				display: inline-flex;
				align-items: center;
				padding: 6px;
				border: 1px solid var(--iato-border);
				background: var(--iato-card-bg);
				color: var(--iato-text-muted);
				cursor: pointer;
				border-radius: var(--iato-radius-sm);
				transition: color 0.15s, border-color 0.15s;
			}
			.iato-reveal-btn:hover {
				color: var(--iato-text-secondary);
				border-color: var(--iato-text-muted);
			}
			.iato-reveal-btn .dashicons {
				font-size: 18px;
				width: 18px;
				height: 18px;
			}

			/* ── Buttons ──────────────────────────────────────── */
			.iato-btn {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 6px 14px;
				font-size: 12px;
				font-weight: 500;
				border-radius: var(--iato-radius-sm);
				text-decoration: none;
				cursor: pointer;
				transition: background 0.15s, color 0.15s;
				border: none;
				white-space: nowrap;
			}
			.iato-btn .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
			}
			.iato-btn--danger {
				color: var(--iato-danger);
				background: var(--iato-danger-bg);
			}
			.iato-btn--danger:hover {
				background: rgba(239,68,68,0.2);
				color: var(--iato-danger);
			}

			/* ── Config Block (dark) ─────────────────────────── */
			.iato-config-section {
				margin-top: 24px;
				padding-top: 20px;
				border-top: 1px solid var(--iato-border);
			}
			.iato-config-title {
				font-size: 14px;
				font-weight: 600;
				color: var(--iato-text);
				margin: 0 0 4px;
			}
			.iato-config-block {
				position: relative;
				background: var(--iato-code-bg);
				border-radius: var(--iato-radius-sm);
				overflow: hidden;
				margin-top: 10px;
			}
			.iato-config-block pre {
				margin: 0;
				padding: 20px;
				padding-right: 80px;
				font-size: 13px;
				line-height: 1.6;
				color: var(--iato-code-text);
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
				overflow-x: auto;
				white-space: pre;
				tab-size: 2;
			}
			.iato-copy-btn--config {
				position: absolute;
				top: 10px;
				right: 10px;
				background: rgba(255, 255, 255, 0.1);
				color: rgba(255, 255, 255, 0.7);
				padding: 6px 12px;
				border-radius: 6px;
			}
			.iato-copy-btn--config:hover {
				background: rgba(255, 255, 255, 0.2);
				color: #fff;
			}
			.iato-copy-label {
				font-size: 12px;
				font-weight: 500;
			}

			/* ── Input Fields ────────────────────────────────── */
			.iato-input-group {
				display: flex;
				align-items: center;
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius-sm);
				overflow: hidden;
				transition: border-color 0.15s, box-shadow 0.15s;
			}
			.iato-input-group:focus-within {
				border-color: var(--iato-primary);
				box-shadow: 0 0 0 2px rgba(90,137,244,0.1);
			}
			.iato-input {
				flex: 1;
				padding: 8px 12px;
				border: none;
				outline: none;
				font-size: 14px;
				font-family: inherit;
				background: transparent;
				color: var(--iato-text);
			}
			.iato-input-group + .iato-hint,
			.iato-field-value > .iato-input {
				width: 100%;
				max-width: 400px;
			}
			.iato-field-value > .iato-input {
				border: 1px solid var(--iato-border);
				border-radius: var(--iato-radius-sm);
				transition: border-color 0.15s, box-shadow 0.15s;
			}
			.iato-field-value > .iato-input:focus {
				border-color: var(--iato-primary);
				box-shadow: 0 0 0 2px rgba(90,137,244,0.1);
				outline: none;
			}
			.iato-input-toggle {
				display: inline-flex;
				align-items: center;
				padding: 8px 10px;
				border: none;
				border-left: 1px solid var(--iato-border);
				background: var(--iato-bg);
				color: var(--iato-text-muted);
				cursor: pointer;
				transition: color 0.15s;
			}
			.iato-input-toggle:hover {
				color: var(--iato-text-secondary);
			}
			.iato-input-toggle .dashicons {
				font-size: 18px;
				width: 18px;
				height: 18px;
			}

			/* ── Tool Categories ──────────────────────────────── */
			.iato-tool-category {
				margin-top: 20px;
				padding-top: 16px;
				border-top: 1px solid var(--iato-border);
			}
			.iato-tool-category:first-of-type {
				margin-top: 0;
				padding-top: 0;
				border-top: none;
			}
			.iato-tool-category-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 12px;
			}
			.iato-tool-category-header h3 {
				margin: 0;
				font-size: 11px;
				font-weight: 600;
				color: var(--iato-text-secondary);
				text-transform: uppercase;
				letter-spacing: 0.06em;
			}
			.iato-tool-category-actions {
				display: flex;
				align-items: center;
				gap: 4px;
			}
			.iato-link-btn {
				padding: 2px 6px;
				border: none;
				background: none;
				color: var(--iato-primary);
				font-size: 12px;
				cursor: pointer;
				border-radius: 4px;
			}
			.iato-link-btn:hover {
				background: var(--iato-primary-light);
			}
			.iato-separator {
				color: var(--iato-border);
				font-size: 12px;
			}

			/* ── Tool Grid ────────────────────────────────────── */
			.iato-tool-grid {
				display: grid;
				grid-template-columns: repeat(2, 1fr);
				gap: 8px;
			}
			@media (max-width: 782px) {
				.iato-tool-grid {
					grid-template-columns: 1fr;
				}
				.iato-field-row {
					flex-direction: column;
					gap: 4px;
				}
				.iato-label {
					flex: none;
					padding-top: 0;
				}
			}
			.iato-tool-item {
				display: flex;
				align-items: center;
				gap: 12px;
				padding: 10px 12px;
				border-radius: var(--iato-radius-sm);
				cursor: pointer;
				transition: background 0.15s;
			}
			.iato-tool-item:hover {
				background: var(--iato-bg);
			}
			.iato-tool-info {
				display: flex;
				flex-direction: column;
				gap: 2px;
				min-width: 0;
			}
			.iato-tool-name {
				font-size: 12px;
				color: var(--iato-text);
				background: none;
				padding: 0;
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
			}
			.iato-tool-desc {
				font-size: 11px;
				color: var(--iato-text-muted);
			}

			/* ── Toggle Switch ────────────────────────────────── */
			.iato-toggle {
				position: relative;
				width: 40px;
				height: 22px;
				flex-shrink: 0;
			}
			.iato-toggle input {
				opacity: 0;
				width: 0;
				height: 0;
				position: absolute;
			}
			.iato-toggle-slider {
				position: absolute;
				inset: 0;
				background: #cbd5e1;
				border-radius: 22px;
				transition: background 0.2s;
				cursor: pointer;
			}
			.iato-toggle-slider::before {
				content: '';
				position: absolute;
				height: 16px;
				width: 16px;
				left: 3px;
				bottom: 3px;
				background: #fff;
				border-radius: 50%;
				transition: transform 0.2s;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
			}
			.iato-toggle input:checked + .iato-toggle-slider {
				background: var(--iato-primary);
			}
			.iato-toggle input:checked + .iato-toggle-slider::before {
				transform: translateX(18px);
			}
			.iato-toggle input:focus-visible + .iato-toggle-slider {
				box-shadow: 0 0 0 2px var(--iato-primary-light);
			}

			/* ── Autopilot & Policy ──────────────────────────── */
			.iato-autopilot-toggle {
				transform: scale(1.15);
				transform-origin: left center;
			}
			.iato-section-title {
				font-size: 13px;
				font-weight: 600;
				color: var(--iato-text);
				margin: 0 0 8px;
				padding-top: 16px;
				border-top: 1px solid var(--iato-border);
			}
			.iato-policy-rules {
				display: grid;
				grid-template-columns: 1fr;
				gap: 8px;
			}
			@media (min-width: 600px) {
				.iato-policy-rules {
					grid-template-columns: 1fr 1fr;
				}
			}
			.iato-policy-section textarea.iato-input {
				width: 100%;
				max-width: 480px;
				resize: vertical;
			}

			/* ── Tools Count ──────────────────────────────────── */
			.iato-tools-count {
				font-size: 12px;
				color: var(--iato-text-muted);
				font-weight: 500;
			}

			/* ── Submit ───────────────────────────────────────── */
			.iato-submit {
				margin-top: 4px;
			}
			.iato-submit .button-primary {
				padding: 8px 24px;
				height: auto;
				font-size: 13.5px;
				font-weight: 600;
				font-family: 'DM Sans', system-ui, sans-serif;
				background: var(--iato-primary-btn);
				border-color: var(--iato-primary-btn);
				border-radius: 8px;
				box-shadow: 0 0 24px rgba(90,137,244,0.18);
				transition: all 0.2s;
			}
			.iato-submit .button-primary:hover {
				background: var(--iato-primary-hover);
				border-color: var(--iato-primary-hover);
				box-shadow: 0 0 36px rgba(90,137,244,0.3);
			}

			/* ── WordPress overrides ─────────────────────────── */
			.iato-wrap .notice {
				margin-left: 0;
				margin-right: 0;
			}
		</style>
		<?php
	}

	// ── Scripts ──────────────────────────────────────────────────────────────────

	private static function render_scripts(): void {
		?>
		<script>
		(function() {
			// Copy to clipboard
			document.querySelectorAll('.iato-copy-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					var text;
					if (btn.dataset.copyValue) {
						text = btn.dataset.copyValue;
					} else if (btn.dataset.target) {
						var el = document.getElementById(btn.dataset.target);
						text = el ? el.textContent : '';
					}
					if (!text) return;
					navigator.clipboard.writeText(text).then(function() {
						btn.classList.add('copied');
						var label = btn.querySelector('.iato-copy-label');
						var icon = btn.querySelector('.dashicons');
						if (label) {
							var orig = label.textContent;
							label.textContent = '<?php echo esc_js( __( 'Copied!', 'iato-mcp' ) ); ?>';
							setTimeout(function() { label.textContent = orig; btn.classList.remove('copied'); }, 2000);
						} else if (icon) {
							icon.className = 'dashicons dashicons-yes';
							setTimeout(function() { icon.className = 'dashicons dashicons-clipboard'; btn.classList.remove('copied'); }, 2000);
						}
					});
				});
			});

			// Reveal / hide key
			document.querySelectorAll('.iato-reveal-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var target = document.getElementById(btn.dataset.target);
					if (!target) return;
					var full = target.dataset.full;
					if (target.textContent === full) {
						target.textContent = full.substring(0, 8) + '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022' + full.substring(full.length - 4);
						btn.querySelector('.dashicons').className = 'dashicons dashicons-visibility';
					} else {
						target.textContent = full;
						btn.querySelector('.dashicons').className = 'dashicons dashicons-hidden';
					}
				});
			});

			// Show/hide password input
			document.querySelectorAll('.iato-input-toggle').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var input = document.getElementById(btn.dataset.toggle);
					if (!input) return;
					var icon = btn.querySelector('.dashicons');
					if (input.type === 'password') {
						input.type = 'text';
						icon.className = 'dashicons dashicons-hidden';
					} else {
						input.type = 'password';
						icon.className = 'dashicons dashicons-visibility';
					}
				});
			});

			// Select All / None per category
			document.querySelectorAll('.iato-tool-category').forEach(function(cat) {
				var checkboxes = cat.querySelectorAll('input[type="checkbox"]');
				var allBtn = cat.querySelector('.iato-select-all');
				var noneBtn = cat.querySelector('.iato-select-none');

				if (allBtn) {
					allBtn.addEventListener('click', function() {
						checkboxes.forEach(function(cb) { cb.checked = true; });
						updateCount();
					});
				}
				if (noneBtn) {
					noneBtn.addEventListener('click', function() {
						checkboxes.forEach(function(cb) { cb.checked = false; });
						updateCount();
					});
				}
			});

			// Update enabled count
			function updateCount() {
				var total = document.querySelectorAll('.iato-tool-grid input[type="checkbox"]').length;
				var checked = document.querySelectorAll('.iato-tool-grid input[type="checkbox"]:checked').length;
				var counter = document.getElementById('iato-tools-count');
				if (counter) {
					counter.textContent = checked + ' of ' + total + ' enabled';
				}
			}

			document.querySelectorAll('.iato-tool-grid input[type="checkbox"]').forEach(function(cb) {
				cb.addEventListener('change', updateCount);
			});

			// Autopilot toggle — show/hide policy section
			var autopilotToggle = document.getElementById('iato-autopilot-toggle');
			var policySection = document.getElementById('iato-policy-section');
			if (autopilotToggle && policySection) {
				autopilotToggle.addEventListener('change', function() {
					policySection.style.display = this.checked ? '' : 'none';
				});
			}
		})();
		</script>
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
		<div class="notice" style="border-left-color: #5a89f4; padding: 0; overflow: hidden;">
			<div style="padding: 20px 24px;">
				<h3 style="margin: 0 0 12px; font-size: 16px; color: #5a89f4;"><img src="<?php echo esc_url( IATO_MCP_URL . 'assets/img/logo.png' ); ?>" alt="IATO" height="28" style="vertical-align: middle; margin-right: 8px;" /><span style="vertical-align: middle;">MCP — Ready to Connect</span></h3>
				<div style="display: flex; gap: 24px; margin-bottom: 16px;">
					<div style="flex: 0 0 24px; text-align: center;">
						<span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: rgba(90,137,244,0.12); color: #5a89f4; border-radius: 50%; font-size: 12px; font-weight: 700;">1</span>
					</div>
					<div>
						<strong><?php esc_html_e( 'Copy this configuration', 'iato-mcp' ); ?></strong>
						<div style="background: #0f172a; border-radius: 8px; margin-top: 8px; position: relative; overflow: hidden;">
							<pre id="iato-wizard-config" style="margin: 0; padding: 16px; padding-right: 70px; color: #e2e8f0; font-size: 13px; line-height: 1.6; overflow-x: auto; white-space: pre; font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace;"><?php echo esc_html( $config_json ); ?></pre>
							<button type="button" style="position: absolute; top: 8px; right: 8px; background: rgba(255,255,255,0.1); border: none; color: rgba(255,255,255,0.7); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;" onclick="navigator.clipboard.writeText(document.getElementById('iato-wizard-config').textContent).then(function(){var b=event.target.closest('button');b.textContent='Copied!';setTimeout(function(){b.innerHTML='<span class=\'dashicons dashicons-clipboard\' style=\'font-size:14px;width:14px;height:14px;\'></span> Copy';},2000);});">
								<span class="dashicons dashicons-clipboard" style="font-size:14px;width:14px;height:14px;"></span> <?php esc_html_e( 'Copy', 'iato-mcp' ); ?>
							</button>
						</div>
					</div>
				</div>
				<div style="display: flex; gap: 24px; margin-bottom: 16px;">
					<div style="flex: 0 0 24px; text-align: center;">
						<span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: rgba(90,137,244,0.12); color: #5a89f4; border-radius: 50%; font-size: 12px; font-weight: 700;">2</span>
					</div>
					<div>
						<strong><?php esc_html_e( 'Open Claude Desktop settings and paste under MCP Servers', 'iato-mcp' ); ?></strong>
						<p style="margin: 4px 0 0; color: #64748b; font-size: 13px;"><?php esc_html_e( 'Or use "Add Custom Connector" and enter your endpoint URL.', 'iato-mcp' ); ?></p>
					</div>
				</div>
				<div style="display: flex; gap: 24px; margin-bottom: 16px;">
					<div style="flex: 0 0 24px; text-align: center;">
						<span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: rgba(90,137,244,0.12); color: #5a89f4; border-radius: 50%; font-size: 12px; font-weight: 700;">3</span>
					</div>
					<div>
						<?php
						printf(
							/* translators: %s: link to settings page */
							esc_html__( '(Optional) Enter your IATO API key in %s to enable bridge tools.', 'iato-mcp' ),
							'<a href="' . esc_url( $settings_url ) . '" style="color: #5a89f4; font-weight: 500;">' . esc_html__( 'Settings', 'iato-mcp' ) . '</a>'
						);
						?>
					</div>
				</div>
				<div style="margin-top: 8px; display: flex; gap: 16px; align-items: center;">
					<?php if ( ! get_option( 'iato_mcp_setup_complete' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=iato-mcp-setup' ) ); ?>" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; background: #4b72cc; color: #fff; text-decoration: none; border-radius: 8px; box-shadow: 0 0 24px rgba(90,137,244,0.18); font-size: 13px; font-weight: 600;"><?php esc_html_e( 'Run Setup Wizard', 'iato-mcp' ); ?> &rarr;</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( $dismiss_url ); ?>" style="color: #94a3b8; font-size: 13px; text-decoration: none;"><?php esc_html_e( 'Dismiss this notice', 'iato-mcp' ); ?></a>
				</div>
			</div>
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

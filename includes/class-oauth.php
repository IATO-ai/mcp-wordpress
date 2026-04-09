<?php
/**
 * OAuth 2.0 Authorization Server for Claude Desktop's "Add Custom Connector" flow.
 *
 * Implements minimal OAuth 2.0 with PKCE and dynamic client registration (RFC 7591)
 * so Claude Desktop can obtain a Bearer token through its standard connector UI.
 *
 * Endpoints:
 *   GET  /.well-known/oauth-authorization-server  — RFC 8414 authorization server metadata
 *   GET  /.well-known/oauth-protected-resource    — RFC 9728 protected resource metadata
 *   POST /oauth/register                          — dynamic client registration
 *   GET  /oauth/authorize                         — authorization (requires WP admin login)
 *   POST /oauth/token                             — token exchange
 *
 * The plugin's MCP API key (iato_mcp_key) serves as both the authorization code
 * and the access token, so Bearer auth works transparently after the OAuth handshake.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_OAuth {

	/**
	 * Hook into WordPress early to intercept OAuth paths.
	 */
	public static function init(): void {
		add_action( 'init', [ self::class, 'handle_request' ], 1 );
	}

	/**
	 * Route the request to the correct OAuth handler.
	 */
	public static function handle_request(): void {
		$path = self::get_request_path();

		switch ( $path ) {
			case '.well-known/oauth-authorization-server':
				self::handle_metadata();
				break;
			case '.well-known/oauth-protected-resource':
				self::handle_resource_metadata();
				break;
			case 'oauth/register':
				self::handle_register();
				break;
			case 'oauth/authorize':
				self::handle_authorize();
				break;
			case 'oauth/token':
				self::handle_token();
				break;
		}
	}

	// ── Protected Resource Metadata (RFC 9728) ──────────────────────────────

	private static function handle_resource_metadata(): void {
		$base = home_url();

		self::json_response( [
			'resource'                => rest_url( 'iato-mcp/v1/message' ),
			'authorization_servers'   => [ $base ],
			'bearer_methods_supported' => [ 'header' ],
		] );
	}

	// ── Metadata (RFC 8414) ──────────────────────────────────────────────────

	private static function handle_metadata(): void {
		$base = home_url();

		self::json_response( [
			'issuer'                                => $base,
			'authorization_endpoint'                => $base . '/oauth/authorize',
			'token_endpoint'                        => $base . '/oauth/token',
			'registration_endpoint'                 => $base . '/oauth/register',
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code' ],
			'code_challenge_methods_supported'       => [ 'S256' ],
			'token_endpoint_auth_methods_supported' => [ 'none' ],
		] );
	}

	// ── Dynamic Client Registration (RFC 7591) ──────────────────────────────

	private static function handle_register(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			self::json_response( [ 'error' => 'invalid_request', 'error_description' => 'POST required' ], 405 );
		}

		$body = json_decode( file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) {
			self::json_response( [ 'error' => 'invalid_request', 'error_description' => 'Invalid JSON body' ], 400 );
		}

		$redirect_uris = $body['redirect_uris'] ?? [];
		if ( empty( $redirect_uris ) || ! is_array( $redirect_uris ) ) {
			self::json_response( [ 'error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris required' ], 400 );
		}

		$client_id   = wp_generate_password( 32, false );
		$client_name = sanitize_text_field( $body['client_name'] ?? 'Unknown Client' );

		$clients                = get_option( 'iato_mcp_oauth_clients', [] );
		$clients[ $client_id ] = [
			'client_name'   => $client_name,
			'redirect_uris' => array_map( 'esc_url_raw', $redirect_uris ),
			'created'       => time(),
		];
		update_option( 'iato_mcp_oauth_clients', $clients );

		self::json_response( [
			'client_id'     => $client_id,
			'client_name'   => $client_name,
			'redirect_uris' => $clients[ $client_id ]['redirect_uris'],
		], 201 );
	}

	// ── Authorize ────────────────────────────────────────────────────────────

	private static function handle_authorize(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'GET' !== $method && 'POST' !== $method ) {
			self::json_response( [ 'error' => 'invalid_request' ], 405 );
		}

		// Require WordPress admin login.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			wp_safe_redirect( wp_login_url( home_url( $request_uri ) ) );
			exit;
		}

		// Read OAuth params from the query string (present on both GET and POST).
		$response_type         = sanitize_text_field( wp_unslash( $_GET['response_type'] ?? '' ) );
		$client_id             = sanitize_text_field( wp_unslash( $_GET['client_id'] ?? '' ) );
		$redirect_uri          = esc_url_raw( wp_unslash( $_GET['redirect_uri'] ?? '' ) );
		$state                 = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$code_challenge        = sanitize_text_field( wp_unslash( $_GET['code_challenge'] ?? '' ) );
		$code_challenge_method = sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ?? 'S256' ) );

		if ( 'code' !== $response_type ) {
			self::json_response( [ 'error' => 'unsupported_response_type' ], 400 );
		}
		if ( '' === $client_id || '' === $redirect_uri ) {
			self::json_response( [ 'error' => 'invalid_request', 'error_description' => 'client_id and redirect_uri required' ], 400 );
		}

		// If client was dynamically registered, verify redirect_uri.
		$clients = get_option( 'iato_mcp_oauth_clients', [] );
		if ( isset( $clients[ $client_id ] ) ) {
			if ( ! in_array( $redirect_uri, $clients[ $client_id ]['redirect_uris'], true ) ) {
				self::json_response( [ 'error' => 'invalid_request', 'error_description' => 'redirect_uri not registered' ], 400 );
			}
		}

		// POST = user approved the form.
		if ( 'POST' === $method ) {
			check_admin_referer( 'iato_mcp_oauth_authorize' );

			// Store PKCE challenge for verification at the token endpoint.
			if ( '' !== $code_challenge ) {
				set_transient( 'iato_mcp_oauth_pkce', [
					'code_challenge'        => $code_challenge,
					'code_challenge_method' => $code_challenge_method,
					'client_id'             => $client_id,
					'redirect_uri'          => $redirect_uri,
				], 600 );
			}

			$mcp_key = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );

			$params = [ 'code' => $mcp_key ];
			if ( '' !== $state ) {
				$params['state'] = $state;
			}

			wp_safe_redirect( add_query_arg( $params, $redirect_uri ) );
			exit;
		}

		// GET — render the approval screen.
		$client_label = isset( $clients[ $client_id ] )
			? $clients[ $client_id ]['client_name']
			: $client_id;

		self::render_authorize_screen( $client_label );
	}

	/**
	 * Minimal approval screen so the WP admin confirms the connection.
	 */
	/**
	 * Return the inline CSS for the authorize screen.
	 */
	private static function get_authorize_styles(): string {
		return <<<'CSS'
				:root {
					--iato-primary: #1e40af;
					--iato-primary-hover: #1e3a8a;
					--iato-primary-light: #dbeafe;
					--iato-text: #0f172a;
					--iato-text-secondary: #475569;
					--iato-text-muted: #94a3b8;
					--iato-border: #e2e8f0;
					--iato-bg: #f1f5f9;
					--iato-success: #16a34a;
				}
				* { box-sizing: border-box; }
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
					display: flex;
					justify-content: center;
					align-items: center;
					min-height: 100vh;
					margin: 0;
					background: var(--iato-bg);
					color: var(--iato-text);
				}
				.auth-card {
					background: #fff;
					padding: 32px;
					border-radius: 16px;
					box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
					max-width: 440px;
					width: 100%;
					margin: 20px;
				}
				.auth-brand {
					font-size: 22px;
					font-weight: 700;
					color: var(--iato-primary);
					letter-spacing: -0.5px;
					margin-bottom: 20px;
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.auth-brand img {
					height: 32px;
					width: auto;
				}
				.auth-brand span {
					font-weight: 400;
					color: var(--iato-text-secondary);
				}
				.auth-title {
					font-size: 18px;
					font-weight: 600;
					margin: 0 0 8px;
					color: var(--iato-text);
				}
				.auth-desc {
					font-size: 14px;
					color: var(--iato-text-secondary);
					margin: 0 0 20px;
					line-height: 1.5;
				}
				.auth-desc strong {
					color: var(--iato-text);
				}
				.auth-permissions {
					background: var(--iato-bg);
					border: 1px solid var(--iato-border);
					border-radius: 10px;
					padding: 16px 20px;
					margin-bottom: 24px;
				}
				.auth-permissions h3 {
					font-size: 12px;
					font-weight: 600;
					text-transform: uppercase;
					letter-spacing: 0.5px;
					color: var(--iato-text-secondary);
					margin: 0 0 12px;
				}
				.auth-permissions ul {
					list-style: none;
					margin: 0;
					padding: 0;
				}
				.auth-permissions li {
					font-size: 14px;
					color: var(--iato-text);
					padding: 6px 0;
					display: flex;
					align-items: center;
					gap: 10px;
				}
				.auth-permissions li::before {
					content: '\2713';
					display: inline-flex;
					align-items: center;
					justify-content: center;
					width: 20px;
					height: 20px;
					background: #dcfce7;
					color: var(--iato-success);
					border-radius: 50%;
					font-size: 12px;
					font-weight: 700;
					flex-shrink: 0;
				}
				.auth-actions {
					display: flex;
					gap: 10px;
					margin-top: 0;
				}
				.auth-btn {
					display: inline-flex;
					align-items: center;
					justify-content: center;
					padding: 10px 24px;
					border-radius: 8px;
					font-size: 14px;
					font-weight: 600;
					cursor: pointer;
					text-decoration: none;
					transition: background 0.15s, color 0.15s, box-shadow 0.15s;
					border: none;
				}
				.auth-btn--primary {
					flex: 1;
					background: var(--iato-primary);
					color: #fff;
				}
				.auth-btn--primary:hover {
					background: var(--iato-primary-hover);
					box-shadow: 0 2px 8px rgba(30, 64, 175, 0.3);
				}
				.auth-btn--secondary {
					padding: 10px 20px;
					background: #fff;
					color: var(--iato-text-secondary);
					border: 1px solid var(--iato-border);
				}
				.auth-btn--secondary:hover {
					background: var(--iato-bg);
					color: var(--iato-text);
				}
				.auth-footer {
					text-align: center;
					margin-top: 20px;
					padding-top: 16px;
					border-top: 1px solid var(--iato-border);
					font-size: 12px;
					color: var(--iato-text-muted);
				}
CSS;
	}

	/**
	 * Minimal approval screen so the WP admin confirms the connection.
	 */
	private static function render_authorize_screen( string $client_name ): void {
		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );

		// Enqueue inline styles via WP.
		wp_register_style( 'iato-mcp-oauth', false, [], IATO_MCP_VERSION );
		wp_enqueue_style( 'iato-mcp-oauth' );
		wp_add_inline_style( 'iato-mcp-oauth', self::get_authorize_styles() );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php
			/* translators: %s: site name */
			printf( esc_html__( 'Authorize — %s', 'iato-mcp' ), esc_html( $site_name ) );
			?></title>
			<?php wp_print_styles( 'iato-mcp-oauth' ); ?>
		</head>
		<body>
			<div class="auth-card">
				<div class="auth-brand"><img src="<?php echo esc_url( IATO_MCP_URL . 'assets/img/logo.png' ); ?>" alt="IATO" /> <span>MCP</span></div>
				<h2 class="auth-title"><?php esc_html_e( 'Authorize Application', 'iato-mcp' ); ?></h2>
				<p class="auth-desc">
					<?php
					printf(
						/* translators: %1$s: client/application name, %2$s: site name */
						esc_html__( '%1$s wants to connect to %2$s via the Model Context Protocol.', 'iato-mcp' ),
						'<strong>' . esc_html( $client_name ) . '</strong>',
						'<strong>' . esc_html( $site_name ) . '</strong>'
					);
					?>
				</p>
				<div class="auth-permissions">
					<h3><?php esc_html_e( 'This will allow', 'iato-mcp' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Read site content and settings', 'iato-mcp' ); ?></li>
						<li><?php esc_html_e( 'Create and modify posts', 'iato-mcp' ); ?></li>
						<li><?php esc_html_e( 'Update SEO metadata', 'iato-mcp' ); ?></li>
						<li><?php esc_html_e( 'Manage media and navigation', 'iato-mcp' ); ?></li>
					</ul>
				</div>
				<form method="post" class="auth-actions">
					<?php wp_nonce_field( 'iato_mcp_oauth_authorize' ); ?>
					<button type="submit" class="auth-btn auth-btn--primary"><?php esc_html_e( 'Approve', 'iato-mcp' ); ?></button>
					<a href="<?php echo esc_url( admin_url() ); ?>" class="auth-btn auth-btn--secondary"><?php esc_html_e( 'Deny', 'iato-mcp' ); ?></a>
				</form>
				<p class="auth-footer"><?php esc_html_e( 'Powered by IATO MCP', 'iato-mcp' ); ?></p>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	// ── Token Exchange ───────────────────────────────────────────────────────

	private static function handle_token(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			self::json_response( [ 'error' => 'invalid_request' ], 405 );
		}

		// Accept both form-encoded and JSON bodies.
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';
		if ( false !== strpos( $content_type, 'application/json' ) ) {
			$input = json_decode( file_get_contents( 'php://input' ), true );
			if ( ! is_array( $input ) ) {
				$input = [];
			}
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth token endpoint; nonce not applicable.
			$input = wp_unslash( $_POST );
		}

		$grant_type    = sanitize_text_field( $input['grant_type'] ?? '' );
		$code          = sanitize_text_field( $input['code'] ?? '' );
		$redirect_uri  = esc_url_raw( $input['redirect_uri'] ?? '' );
		$client_id     = sanitize_text_field( $input['client_id'] ?? '' );
		$code_verifier = sanitize_text_field( $input['code_verifier'] ?? '' );

		if ( 'authorization_code' !== $grant_type ) {
			self::json_response( [ 'error' => 'unsupported_grant_type' ], 400 );
		}

		// The authorization code IS the MCP key.
		$mcp_key = sanitize_text_field( get_option( 'iato_mcp_key', '' ) );
		if ( '' === $code || ! hash_equals( $mcp_key, $code ) ) {
			self::json_response( [ 'error' => 'invalid_grant' ], 400 );
		}

		// Verify PKCE if a challenge was stored during authorization.
		$pkce = get_transient( 'iato_mcp_oauth_pkce' );
		if ( $pkce ) {
			delete_transient( 'iato_mcp_oauth_pkce' );

			if ( $pkce['client_id'] !== $client_id ) {
				self::json_response( [ 'error' => 'invalid_grant' ], 400 );
			}
			if ( $pkce['redirect_uri'] !== $redirect_uri ) {
				self::json_response( [ 'error' => 'invalid_grant' ], 400 );
			}

			if ( '' !== $code_verifier && 'S256' === $pkce['code_challenge_method'] ) {
				$expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
				if ( ! hash_equals( $pkce['code_challenge'], $expected ) ) {
					self::json_response( [ 'error' => 'invalid_grant', 'error_description' => 'PKCE verification failed' ], 400 );
				}
			}
		}

		// Return the MCP key as the access token.
		self::json_response( [
			'access_token' => $mcp_key,
			'token_type'   => 'Bearer',
		] );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Extract the request path relative to the WordPress home URL.
	 * Handles subdirectory installs correctly.
	 */
	private static function get_request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = trim( strtok( $uri, '?' ), '/' );

		$home_path = trim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) + 1 );
		} elseif ( $home_path === $path ) {
			$path = '';
		}

		return $path;
	}

	/**
	 * Send a JSON response and terminate.
	 */
	private static function json_response( array $data, int $status = 200 ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $data );
		exit;
	}
}

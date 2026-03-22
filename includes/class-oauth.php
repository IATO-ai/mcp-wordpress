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
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
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
		if ( 'GET' !== $_SERVER['REQUEST_METHOD'] && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			self::json_response( [ 'error' => 'invalid_request' ], 405 );
		}

		// Require WordPress admin login.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_redirect( wp_login_url( home_url( $_SERVER['REQUEST_URI'] ) ) );
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
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
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

			wp_redirect( add_query_arg( $params, $redirect_uri ) );
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
	private static function render_authorize_screen( string $client_name ): void {
		$site_name = sanitize_text_field( get_bloginfo( 'name' ) );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php printf( esc_html__( 'Authorize — %s', 'iato-mcp' ), esc_html( $site_name ) ); ?></title>
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f0f0f1; }
				.card { background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.13); max-width: 420px; width: 100%; }
				h2 { margin-top: 0; }
				.actions { margin-top: 1.5rem; }
				.btn { display: inline-block; padding: .6rem 1.2rem; border: none; border-radius: 4px; font-size: .95rem; cursor: pointer; text-decoration: none; }
				.btn-primary { background: #2271b1; color: #fff; }
				.btn-primary:hover { background: #135e96; }
				.btn-cancel { background: #ddd; color: #50575e; margin-left: .5rem; }
			</style>
		</head>
		<body>
			<div class="card">
				<h2><?php esc_html_e( 'Authorize Application', 'iato-mcp' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: client/application name */
						esc_html__( '%s wants to connect to your WordPress site via MCP.', 'iato-mcp' ),
						'<strong>' . esc_html( $client_name ) . '</strong>'
					);
					?>
				</p>
				<p><?php esc_html_e( 'This will grant read and write access to your site content.', 'iato-mcp' ); ?></p>
				<form method="post" class="actions">
					<?php wp_nonce_field( 'iato_mcp_oauth_authorize' ); ?>
					<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Approve', 'iato-mcp' ); ?></button>
					<a href="<?php echo esc_url( admin_url() ); ?>" class="btn btn-cancel"><?php esc_html_e( 'Deny', 'iato-mcp' ); ?></a>
				</form>
			</div>
		</body>
		</html>
		<?php
		exit;
	}

	// ── Token Exchange ───────────────────────────────────────────────────────

	private static function handle_token(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			self::json_response( [ 'error' => 'invalid_request' ], 405 );
		}

		// Accept both form-encoded and JSON bodies.
		$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
		if ( false !== strpos( $content_type, 'application/json' ) ) {
			$input = json_decode( file_get_contents( 'php://input' ), true );
			if ( ! is_array( $input ) ) {
				$input = [];
			}
		} else {
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
		$uri  = $_SERVER['REQUEST_URI'] ?? '';
		$path = trim( strtok( $uri, '?' ), '/' );

		$home_path = trim( parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
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

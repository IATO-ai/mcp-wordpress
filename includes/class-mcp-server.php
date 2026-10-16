<?php
/**
 * MCP Server — JSON-RPC 2.0 over HTTP POST.
 *
 * Registers the REST endpoint, dispatches initialize / tools/list / tools/call.
 * Transport: Streamable HTTP (one POST per request). SSE deferred to Phase 3.
 *
 * Endpoint: POST /wp-json/iato-mcp/v1/message
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Server {

	/** @var array<string,array{definition:array,handler:callable}> Tools exposed on the MCP endpoint, keyed by tool name. */
	private static array $tools = [];

	/**
	 * Every tool that was ever passed to register_tool(), including tools
	 * toggled off in Settings and tools loaded in catalog-only mode. The
	 * WordPress Abilities layer (IATO_MCP_Abilities) reads definitions and
	 * handlers from here so the two surfaces share one implementation.
	 *
	 * @var array<string,array{definition:array,handler:callable}>
	 */
	private static array $catalog = [];

	/**
	 * When true, register_tool() fills the catalog but leaves $tools alone, so
	 * nothing new appears on tools/list. iato-mcp.php switches this on while
	 * loading the bridge tool files on a site that has no IATO API key but has
	 * abilities exposure enabled.
	 */
	private static bool $catalog_only = false;

	/**
	 * Boot: register REST route and auth response filter.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
		add_filter( 'rest_post_dispatch', [ self::class, 'add_auth_headers' ], 10, 3 );
	}

	/**
	 * Register the MCP message endpoint.
	 * Accepts both GET (endpoint probe / SSE) and POST (JSON-RPC messages).
	 * Authentication is handled via the plugin-generated API key (Bearer token).
	 */
	public static function register_routes(): void {
		register_rest_route( 'iato-mcp/v1', '/message', [
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_request' ],
				'permission_callback' => [ 'IATO_MCP_Auth', 'authenticate' ],
			],
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_get' ],
				'permission_callback' => [ 'IATO_MCP_Auth', 'authenticate' ],
			],
		] );
	}

	/**
	 * Handle GET — endpoint probe for Streamable HTTP transport.
	 *
	 * Claude Desktop and other MCP clients send GET to verify the endpoint
	 * exists before starting the OAuth flow or sending JSON-RPC messages.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_get( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( [
			'jsonrpc' => '2.0',
			'result'  => [
				'serverInfo' => [
					'name'    => 'iato-mcp',
					'version' => IATO_MCP_VERSION,
				],
			],
		], 200 );
	}

	/**
	 * Add WWW-Authenticate header to 401 responses on MCP routes.
	 *
	 * This tells Claude Desktop where to find the OAuth metadata so it can
	 * start the authorization flow automatically.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_REST_Server   $server   REST server.
	 * @param WP_REST_Request  $request  Request object.
	 * @return WP_REST_Response
	 */
	public static function add_auth_headers( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/iato-mcp/' ) ) {
			return $response;
		}

		if ( 401 === $response->get_status() ) {
			$resource_metadata_url = home_url( '/.well-known/oauth-protected-resource' );
			$challenge             = 'Bearer resource_metadata="' . esc_url_raw( $resource_metadata_url ) . '"';
			// RFC 6750 §3.1: a presented-but-refused token (expired or revoked
			// OAuth token, wrong site key) is reported as invalid_token, which
			// is what tells an OAuth client to refresh or re-authorize.
			if ( class_exists( 'IATO_MCP_Auth' ) && IATO_MCP_Auth::token_was_refused() ) {
				$challenge .= ', error="invalid_token"';
			}
			$response->header( 'WWW-Authenticate', $challenge );
		}

		return $response;
	}

	/**
	 * Register a tool with the server.
	 *
	 * Skips registration if the tool is toggled off in Settings > IATO MCP.
	 * Toggles default to "all on" when the option is empty (fresh install).
	 *
	 * @param string   $name       Tool name (snake_case, matches tools/list output).
	 * @param array    $definition JSON Schema definition for tools/list.
	 * @param callable $handler    Handler — receives assoc array of params, returns array|WP_Error.
	 */
	public static function register_tool( string $name, array $definition, callable $handler ): void {
		$entry = [
			'definition' => $definition,
			'handler'    => $handler,
		];

		// The catalog records every tool regardless of toggles or mode.
		self::$catalog[ $name ] = $entry;

		if ( self::$catalog_only ) {
			return;
		}

		if ( class_exists( 'IATO_MCP_Settings' ) && ! IATO_MCP_Settings::is_tool_enabled( $name ) ) {
			return;
		}

		self::$tools[ $name ] = $entry;
	}

	/**
	 * Toggle catalog-only registration (see $catalog_only).
	 */
	public static function set_catalog_only( bool $catalog_only ): void {
		self::$catalog_only = $catalog_only;
	}

	/**
	 * Definition + handler for a tool that was passed to register_tool(),
	 * whether or not it is exposed on the MCP endpoint. Null if unknown.
	 *
	 * @return array{definition:array,handler:callable}|null
	 */
	public static function get_catalog_entry( string $name ): ?array {
		return self::$catalog[ $name ] ?? null;
	}

	/**
	 * Is the tool exposed on the MCP endpoint (tools/list, tools/call)?
	 */
	public static function is_tool_registered( string $name ): bool {
		return isset( self::$tools[ $name ] );
	}

	/**
	 * Invoke a catalogued tool handler and return its decoded payload.
	 *
	 * Handlers return the MCP text envelope built by ok(); this unwraps it so
	 * non-MCP callers (WordPress Abilities) get the same associative array the
	 * MCP client would json_decode. WP_Error results pass through unchanged.
	 * Handlers that return an isError envelope are converted to WP_Error.
	 *
	 * @param array $args Assoc array of tool arguments.
	 */
	public static function invoke_tool( string $name, array $args ): array|WP_Error {
		$entry = self::$catalog[ $name ] ?? null;
		if ( null === $entry ) {
			return new WP_Error( 'tool_not_found', "Unknown tool: {$name}" );
		}

		$result = call_user_func( $entry['handler'], $args );

		if ( is_wp_error( $result ) ) {
			// Mirror handle_tools_call(): IATO API errors can carry an array
			// message, which the MCP path JSON-encodes. Do the same here so an
			// ability returns exactly the text the MCP tool would.
			$err_msg = $result->get_error_message();
			if ( ! is_string( $err_msg ) ) {
				$err_msg = is_array( $err_msg ) ? wp_json_encode( $err_msg ) : (string) $err_msg;
				return new WP_Error( $result->get_error_code(), $err_msg, $result->get_error_data() );
			}
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new WP_Error( 'tool_invalid_result', "Tool {$name} returned an unexpected result type." );
		}

		$text = $result['content'][0]['text'] ?? null;
		if ( ! empty( $result['isError'] ) ) {
			return new WP_Error( 'tool_error', is_string( $text ) ? $text : "Tool {$name} failed." );
		}

		if ( is_string( $text ) ) {
			$decoded = json_decode( $text, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
			return new WP_Error( 'tool_invalid_result', "Tool {$name} returned non-JSON content." );
		}

		// Not an ok() envelope — return as-is.
		return $result;
	}

	/**
	 * Main request dispatcher.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$id     = $body['id']     ?? null;
		$method = $body['method'] ?? '';
		$params = $body['params'] ?? [];

		// MCP notifications (e.g. notifications/initialized) are one-way — the
		// client does not expect a response per JSON-RPC 2.0 spec. Accept with
		// 202 and skip call-log entry (high-frequency protocol chatter).
		if ( is_string( $method ) && strpos( $method, 'notifications/' ) === 0 ) {
			return new WP_REST_Response( null, 202 );
		}

		$started   = microtime( true );
		$tool_name = null;
		$args      = null;

		if ( 'tools/call' === $method ) {
			$tool_name = (string) ( $params['name'] ?? '' );
			$args      = $params['arguments'] ?? [];
		}

		switch ( $method ) {
			case 'initialize':
				$result = self::handle_initialize( $params );
				break;
			case 'tools/list':
				$result = self::handle_tools_list();
				break;
			case 'tools/call':
				$result = self::handle_tools_call( $params );
				break;
			default:
				self::log_call(
					$method,
					$tool_name,
					$args,
					'error',
					'method_not_found',
					'Method not found',
					$started
				);
				return self::error_response( $id, -32601, 'Method not found' );
		}

		if ( is_wp_error( $result ) ) {
			self::log_call(
				$method,
				$tool_name,
				$args,
				'error',
				$result->get_error_code(),
				$result->get_error_message(),
				$started
			);
			return self::error_response( $id, -32000, $result->get_error_message() );
		}

		// tools/call may return a tool-level error envelope with isError=true.
		$tool_error = null;
		if ( 'tools/call' === $method && is_array( $result ) && ! empty( $result['isError'] ) ) {
			$tool_error = isset( $result['content'][0]['text'] )
				? (string) $result['content'][0]['text']
				: 'tool_error';
		}

		// A JSON error text names its own code (tool_exception, the atomic and
		// rollback refusals); anything else is logged as tool_error.
		$tool_code = 'tool_error';
		if ( null !== $tool_error ) {
			$decoded = json_decode( $tool_error, true );
			if ( is_array( $decoded ) && is_string( $decoded['error'] ?? null ) && '' !== $decoded['error'] ) {
				$tool_code = $decoded['error'];
			}
		}

		self::log_call(
			$method,
			$tool_name,
			$args,
			null === $tool_error ? 'success' : 'error',
			null === $tool_error ? null : $tool_code,
			$tool_error,
			$started
		);

		return new WP_REST_Response( [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		], 200 );
	}

	/**
	 * Record a call log entry for an incoming MCP request.
	 *
	 * @param string      $method   JSON-RPC method (initialize, tools/list, tools/call, etc.).
	 * @param string|null $tool     Tool name (only for tools/call).
	 * @param mixed       $args     Raw arguments array (only for tools/call).
	 * @param string      $status   'success' | 'error' | 'unauthorized'.
	 * @param string|null $code     Optional error code.
	 * @param string|null $message  Optional error message.
	 * @param float       $started  Value from microtime(true) at request start.
	 */
	private static function log_call(
		string $method,
		?string $tool,
		mixed $args,
		string $status,
		?string $code,
		?string $message,
		float $started
	): void {
		if ( ! class_exists( 'IATO_MCP_Call_Log' ) ) {
			return;
		}
		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		IATO_MCP_Call_Log::record( [
			'rpc_method'      => $method,
			'tool_name'       => $tool,
			'request_args'    => is_array( $args ) ? $args : null,
			'response_status' => $status,
			'error_code'      => $code,
			'error_message'   => $message,
			'duration_ms'     => $duration_ms,
			'auth_user_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		] );
	}

	// ── Method handlers ────────────────────────────────────────────────────────

	/**
	 * Handle initialize — return server capabilities.
	 *
	 * @param array $params Client capabilities (ignored for now).
	 * @return array
	 */
	private static function handle_initialize( array $params ): array {
		// Negotiate protocol version. Echo the client's requested version when it's
		// one we know about; fall back to our newest known version otherwise. The
		// MCP envelope and tools/list / tools/call response shapes are stable across
		// these revs — newer features (structured content, _meta, etc.) are additive
		// and our flat content responses degrade past them.
		$supported_versions = [ '2025-06-18', '2025-03-26', '2024-11-05' ];
		$requested          = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] )
			? sanitize_text_field( $params['protocolVersion'] )
			: '';
		$negotiated         = in_array( $requested, $supported_versions, true ) ? $requested : '2025-06-18';

		$capabilities = [
			'tools' => new stdClass(), // signals tool support
		];
		// Advertise rollback capability only when the tool is actually registered —
		// the per-tool toggle at Settings > IATO MCP can disable it, and clients
		// feature-detecting via this response shouldn't be told the tool exists
		// when it would return tool_not_found on call.
		if ( class_exists( 'IATO_MCP_Settings' ) && IATO_MCP_Settings::is_tool_enabled( 'rollback' ) ) {
			$capabilities['rollback'] = true;
		}
		// Advertise widget-grained Elementor v2 surface only when Elementor is
		// actually active — tells clients they can hand off to v2 tools without
		// a tools/list round-trip.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			// atomic_read: read tools normalise Elementor V4 (atomic) elements —
			// e-heading / e-paragraph / e-image / e-button / e-flexbox etc. — and
			// mark every node with schema: classic | atomic. Pure _elementor_data
			// parsing, so it does not depend on the Elementor version or the
			// Elementor MCP module.
			$capabilities['elementor'] = [ 'v2' => true, 'atomic_read' => true ];
		}
		return [
			'protocolVersion' => $negotiated,
			'serverInfo'      => [
				'name'    => 'iato-mcp',
				'version' => IATO_MCP_VERSION,
			],
			'capabilities'    => $capabilities,
			// Dynamic page-builder-aware instructions — tells the AI client which
			// write tools are correct for which builder, with a mandatory
			// get_page_builder check-first rule before any content edit.
			// Field added in MCP spec 2025-03-26; older clients ignore unknown fields.
			'instructions'    => iato_mcp_build_server_instructions(),
		];
	}

	/**
	 * Handle tools/list — return all registered tool definitions.
	 *
	 * @return array
	 */
	private static function handle_tools_list(): array {
		$tools = [];
		foreach ( self::$tools as $name => $entry ) {
			$tools[] = array_merge( [ 'name' => $name ], $entry['definition'] );
		}
		return [ 'tools' => $tools ];
	}

	/**
	 * Handle tools/call — dispatch to registered handler.
	 *
	 * @param array $params { name: string, arguments: array }
	 * @return array|WP_Error
	 */
	private static function handle_tools_call( array $params ): array|WP_Error {
		$name      = $params['name']      ?? '';
		$arguments = $params['arguments'] ?? [];

		if ( ! isset( self::$tools[ $name ] ) ) {
			return new WP_Error( 'tool_not_found', "Unknown tool: {$name}" );
		}

		// IATO bridge tools return the IATO account's crawl data, which can
		// cover other client sites: manage_options, as for the abilities
		// (1.13.0). Checked here, not in the handlers, because the abilities
		// run the same handlers outside MCP authentication, behind their own
		// permission_callback.
		$tool_cap = class_exists( 'IATO_MCP_Abilities' ) ? IATO_MCP_Abilities::capability_for_tool( $name ) : null;
		if ( null !== $tool_cap ) {
			$cap_check = IATO_MCP_Auth::require_cap( $tool_cap );
			if ( is_wp_error( $cap_check ) ) {
				return [
					'isError' => true,
					'content' => [ [ 'type' => 'text', 'text' => $cap_check->get_error_message() ] ],
				];
			}
		}

		$handler = self::$tools[ $name ]['handler'];
		// 1.14.0: an uncaught Throwable from a tool handler (or from code it calls
		// in WordPress or another plugin) becomes a clear tool error instead of a
		// PHP fatal, which WordPress answers with a non-JSON-RPC HTTP 500 and no
		// call-log entry.
		try {
			$result = call_user_func( $handler, $arguments );
		} catch ( \Throwable $e ) {
			return self::tool_exception_result( (string) $name, $e );
		}

		if ( is_wp_error( $result ) ) {
			$err_msg = $result->get_error_message();
			// MCP spec requires text to be a string — IATO API errors can leak objects.
			if ( ! is_string( $err_msg ) ) {
				$err_msg = is_array( $err_msg ) ? wp_json_encode( $err_msg ) : (string) $err_msg;
			}
			return [
				'isError' => true,
				'content' => [[
					'type' => 'text',
					'text' => $err_msg,
				]],
			];
		}

		return $result;
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * The isError result for a tool handler that threw: JSON text naming the
	 * tool and the exception, logged under WP_DEBUG with file and line. The
	 * call log records it as tool_exception (handle_request() reads the error
	 * code from JSON error texts).
	 */
	private static function tool_exception_result( string $tool, \Throwable $e ): array {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( sprintf( '[iato-mcp] tool %s threw %s: %s at %s:%d', $tool, get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ) );
		}
		return [
			'isError' => true,
			'content' => [ [
				'type' => 'text',
				'text' => (string) wp_json_encode( [
					'error'     => 'tool_exception',
					'tool'      => $tool,
					'exception' => get_class( $e ),
					'message'   => sprintf(
						'The tool %1$s failed with an error inside WordPress or another plugin (%2$s: %3$s). Changes made before the failure may have been applied: check the change receipts and the content before retrying.',
						$tool,
						get_class( $e ),
						$e->getMessage()
					),
				] ),
			] ],
		];
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param mixed  $id      Request ID.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Human-readable message.
	 * @return WP_REST_Response
	 */
	private static function error_response( mixed $id, int $code, string $message ): WP_REST_Response {
		return new WP_REST_Response( [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [ 'code' => $code, 'message' => $message ],
		], 200 ); // MCP spec: always 200, errors in body
	}

	/**
	 * Convenience wrapper — build a successful tool content response.
	 *
	 * @param mixed $data Data to JSON-encode as the text content.
	 * @return array
	 */
	public static function ok( mixed $data ): array {
		return [
			'content' => [[
				'type' => 'text',
				'text' => wp_json_encode( $data ),
			]],
		];
	}
}

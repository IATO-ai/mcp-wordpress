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

	/** @var array<string,callable> Registered tool handlers keyed by tool name. */
	private static array $tools = [];

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
			$response->header(
				'WWW-Authenticate',
				'Bearer resource_metadata="' . esc_url_raw( $resource_metadata_url ) . '"'
			);
		}

		return $response;
	}

	/**
	 * Register a tool with the server.
	 *
	 * @param string   $name       Tool name (snake_case, matches tools/list output).
	 * @param array    $definition JSON Schema definition for tools/list.
	 * @param callable $handler    Handler — receives assoc array of params, returns array|WP_Error.
	 */
	public static function register_tool( string $name, array $definition, callable $handler ): void {
		self::$tools[ $name ] = [
			'definition' => $definition,
			'handler'    => $handler,
		];
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
				return self::error_response( $id, -32601, 'Method not found' );
		}

		if ( is_wp_error( $result ) ) {
			return self::error_response( $id, -32000, $result->get_error_message() );
		}

		return new WP_REST_Response( [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		], 200 );
	}

	// ── Method handlers ────────────────────────────────────────────────────────

	/**
	 * Handle initialize — return server capabilities.
	 *
	 * @param array $params Client capabilities (ignored for now).
	 * @return array
	 */
	private static function handle_initialize( array $params ): array {
		return [
			'protocolVersion' => '2024-11-05',
			'serverInfo'      => [
				'name'    => 'iato-mcp',
				'version' => IATO_MCP_VERSION,
			],
			'capabilities' => [
				'tools' => new stdClass(), // signals tool support
			],
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

		$handler = self::$tools[ $name ]['handler'];
		$result  = call_user_func( $handler, $arguments );

		if ( is_wp_error( $result ) ) {
			return [
				'isError' => true,
				'content' => [[
					'type' => 'text',
					'text' => $result->get_error_message(),
				]],
			];
		}

		return $result;
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

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

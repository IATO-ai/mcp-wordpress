<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Tests for the WordPress Abilities bridge (IATO_MCP_Abilities) and the tool
 * catalog in IATO_MCP_Server. The real bridge tool files are loaded once in
 * catalog-only mode so schemas and handlers are the production ones.
 */
final class AbilitiesRegistrationTest extends TestCase {

	private const EXPECTED = [
		'iato/get-broken-links' => 'get_iato_broken_links',
		'iato/get-orphan-pages' => 'get_iato_orphan_pages',
		'iato/get-content-gaps' => 'get_iato_content_gaps',
		'iato/get-nav-audit'    => 'get_iato_nav_audit',
		'iato/get-seo-fixes'    => 'get_iato_seo_fixes',
		'iato/get-suggestions'  => 'get_iato_suggestions',
		'iato/get-perf-report'  => 'get_iato_perf_report',
		'iato/get-sitemap'      => 'get_iato_sitemap',
		'iato/get-taxonomy'     => 'get_iato_taxonomy',
		'iato/get-crawl-status' => 'get_iato_crawl_status',
		'iato/list-crawls'      => 'list_iato_crawls',
	];

	private static bool $bridge_loaded = false;

	public static function setUpBeforeClass(): void {
		if ( self::$bridge_loaded ) {
			return;
		}
		// Load the production bridge tool files exactly as iato-mcp.php does on
		// an unconnected site: catalog-only, nothing on the MCP endpoint.
		IATO_MCP_Server::set_catalog_only( true );
		foreach ( glob( IATO_MCP_DIR . 'includes/tools/bridge/tool-*.php' ) as $file ) {
			require_once $file;
		}
		IATO_MCP_Server::set_catalog_only( false );
		self::$bridge_loaded = true;
	}

	protected function setUp(): void {
		IATO_Test_Abilities_State::reset();
		IATO_Test_Abilities_State::$options['iato_mcp_api_key'] = 'test-key';
	}

	private function register_all(): array {
		IATO_MCP_Abilities::register_category();
		IATO_MCP_Abilities::register_abilities();
		return IATO_Test_Abilities_State::$registered;
	}

	// ── Catalog ───────────────────────────────────────────────────────────

	public function test_catalog_only_mode_keeps_mcp_endpoint_unchanged(): void {
		foreach ( self::EXPECTED as $tool ) {
			$this->assertNotNull( IATO_MCP_Server::get_catalog_entry( $tool ), "$tool catalogued" );
			$this->assertFalse( IATO_MCP_Server::is_tool_registered( $tool ), "$tool must not be on tools/list in catalog-only mode" );
		}
		$this->assertNotNull( IATO_MCP_Server::get_catalog_entry( 'start_iato_crawl' ), 'catalog records everything, even tools not exposed as abilities' );
	}

	public function test_register_tool_honours_toggle_and_mode(): void {
		IATO_Test_Abilities_State::$disabled_tools = [ 'unit_off' ];
		IATO_MCP_Server::register_tool( 'unit_off', [ 'inputSchema' => [ 'type' => 'object' ] ], fn() => IATO_MCP_Server::ok( [] ) );
		IATO_MCP_Server::register_tool( 'unit_on', [ 'inputSchema' => [ 'type' => 'object' ] ], fn() => IATO_MCP_Server::ok( [] ) );
		$this->assertNotNull( IATO_MCP_Server::get_catalog_entry( 'unit_off' ) );
		$this->assertFalse( IATO_MCP_Server::is_tool_registered( 'unit_off' ), 'toggled-off tool stays off the endpoint' );
		$this->assertTrue( IATO_MCP_Server::is_tool_registered( 'unit_on' ), 'normal mode still exposes enabled tools' );
	}

	public function test_invoke_tool_unwraps_envelope_and_passes_errors(): void {
		IATO_MCP_Server::register_tool( 'unit_ok', [], fn( array $a ) => IATO_MCP_Server::ok( [ 'echo' => $a, 'n' => 1 ] ) );
		IATO_MCP_Server::register_tool( 'unit_err', [], fn( array $a ) => new WP_Error( 'boom', 'It broke' ) );
		IATO_MCP_Server::register_tool( 'unit_iserror', [], fn( array $a ) => [ 'isError' => true, 'content' => [ [ 'type' => 'text', 'text' => 'nope' ] ] ] );

		$this->assertSame( [ 'echo' => [ 'x' => 1 ], 'n' => 1 ], IATO_MCP_Server::invoke_tool( 'unit_ok', [ 'x' => 1 ] ) );

		$err = IATO_MCP_Server::invoke_tool( 'unit_err', [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'boom', $err->get_error_code() );

		$err = IATO_MCP_Server::invoke_tool( 'unit_iserror', [] );
		$this->assertSame( 'tool_error', $err->get_error_code() );
		$this->assertSame( 'nope', $err->get_error_message() );

		$this->assertSame( 'tool_not_found', IATO_MCP_Server::invoke_tool( 'no_such_tool', [] )->get_error_code() );
	}

	public function test_invoke_tool_stringifies_array_error_messages_like_the_mcp_path(): void {
		// IATO error envelopes can carry an object message; handle_tools_call()
		// JSON-encodes it, so the ability path must return the same text.
		$payload = [ 'code' => 'NOT_FOUND', 'message' => 'Not found', 'details' => null ];
		IATO_MCP_Server::register_tool( 'unit_arr_err', [], fn( array $a ) => new WP_Error( 'iato_api_error', $payload, [ 'status' => 404 ] ) );
		$err = IATO_MCP_Server::invoke_tool( 'unit_arr_err', [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'iato_api_error', $err->get_error_code() );
		$this->assertSame( json_encode( $payload ), $err->get_error_message() );
		$this->assertSame( [ 'status' => 404 ], $err->get_error_data() );
	}

	// ── Registration ──────────────────────────────────────────────────────

	public function test_registers_exactly_the_eleven_abilities(): void {
		$registered = $this->register_all();
		$this->assertSame( array_keys( self::EXPECTED ), array_keys( $registered ) );
		$this->assertArrayHasKey( 'iato', IATO_Test_Abilities_State::$categories );
		$this->assertNotEmpty( IATO_Test_Abilities_State::$categories['iato']['label'] );
		$this->assertNotEmpty( IATO_Test_Abilities_State::$categories['iato']['description'] );
	}

	public function test_ability_args_shape(): void {
		foreach ( $this->register_all() as $name => $args ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name, 'core name regex' );
			$this->assertSame( 'iato', $args['category'], $name );
			$this->assertNotEmpty( $args['label'], $name );
			$this->assertStringStartsWith( 'IATO: ', $args['label'], $name );
			$this->assertLessThanOrEqual( 400, strlen( $args['description'] ), "$name description length" );
			$this->assertStringContainsStringIgnoringCase( 'crawl', $args['description'], "$name says the data comes from a crawl" );
			$this->assertIsCallable( $args['execute_callback'], $name );
			$this->assertIsCallable( $args['permission_callback'], $name );
			$this->assertSame( [ 'type' => 'object' ], $args['output_schema'], $name );
			$this->assertSame( 'object', $args['input_schema']['type'], $name );
			$this->assertSame( [], $args['input_schema']['default'], "$name input default lets null input through" );
			$expected_annotations = 'iato/get-suggestions' === $name
				? [ 'destructive' => false ]
				: [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ];
			$this->assertSame(
				[ 'public' => true, 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => $expected_annotations ],
				$args['meta'],
				$name
			);
		}
	}

	public function test_get_suggestions_is_not_annotated_readonly_or_idempotent(): void {
		// It can trigger suggestion generation on IATO, so it must not claim
		// readonly / idempotent. Core fills omitted annotations with null and the
		// MCP Adapter only emits boolean hints, so "omitted" means "no claim".
		$meta = $this->register_all()['iato/get-suggestions']['meta'];
		$this->assertArrayNotHasKey( 'readonly', $meta['annotations'] );
		$this->assertArrayNotHasKey( 'idempotent', $meta['annotations'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertSame( [ 'destructive' => false ], IATO_MCP_Abilities::annotations_for( 'get-suggestions' ) );

		// Every other ability keeps the full read-only set.
		foreach ( array_keys( self::EXPECTED ) as $name ) {
			if ( 'iato/get-suggestions' === $name ) {
				continue;
			}
			$this->assertSame( IATO_MCP_Abilities::DEFAULT_ANNOTATIONS, IATO_MCP_Abilities::annotations_for( substr( $name, 5 ) ), $name );
		}
	}

	public function test_input_schema_matches_mcp_tool_definition(): void {
		foreach ( $this->register_all() as $name => $args ) {
			$tool   = self::EXPECTED[ $name ];
			$source = IATO_MCP_Server::get_catalog_entry( $tool )['definition']['inputSchema'];
			$ability = $args['input_schema'];
			unset( $ability['default'] );

			$expected_props = $source['properties'] ?? [];
			if ( 'get_iato_sitemap' === $tool ) {
				$this->assertArrayHasKey( 'crawl_id', $ability['properties'], 'sitemap handler reads crawl_id, so the ability documents it' );
				unset( $ability['properties']['crawl_id'] );
			}
			$this->assertSame( $expected_props, $ability['properties'] ?? [], "$name properties mirror the MCP tool" );
			$this->assertArrayNotHasKey( 'required', $ability, "$name has no required args (all optional in the MCP tool)" );
		}
	}

	/** Capability sets as WordPress grants them to the default roles (subset relevant here). */
	private const ROLE_CAPS = [
		'subscriber'    => [ 'read' => true ],
		'contributor'   => [ 'read' => true, 'edit_posts' => true ],
		'editor'        => [ 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'upload_files' => true ],
		'administrator' => [ 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'upload_files' => true, 'manage_options' => true ],
	];

	public function test_permission_callback_requires_manage_options_by_default(): void {
		$registered = $this->register_all();
		foreach ( $registered as $name => $args ) {
			foreach ( [ 'subscriber', 'contributor', 'editor' ] as $role ) {
				IATO_Test_Abilities_State::$caps = self::ROLE_CAPS[ $role ];
				$this->assertFalse( $args['permission_callback']( [] ), "$name must refuse a $role by default" );
			}
			IATO_Test_Abilities_State::$caps = self::ROLE_CAPS['administrator'];
			$this->assertTrue( $args['permission_callback']( [] ), "$name allows an administrator" );
			$this->assertSame( 'manage_options', IATO_MCP_Abilities::capability_for( substr( $name, strlen( 'iato/' ) ) ) );
		}
	}

	public function test_capability_filter_can_lower_the_requirement_per_ability(): void {
		add_filter( IATO_MCP_Abilities::CAPABILITY_FILTER, function ( string $cap, string $ability, string $tool ) {
			$this->assertStringStartsWith( 'iato/', $ability );
			$this->assertSame( self::EXPECTED[ $ability ], $tool, 'filter receives the MCP tool name' );
			return 'iato/list-crawls' === $ability ? 'edit_posts' : $cap;
		}, 10, 3 );

		$registered = $this->register_all();
		IATO_Test_Abilities_State::$caps = self::ROLE_CAPS['editor'];
		$this->assertTrue( $registered['iato/list-crawls']['permission_callback']( [] ), 'lowered ability admits an editor' );
		$this->assertFalse( $registered['iato/get-seo-fixes']['permission_callback']( [] ), 'other abilities keep manage_options' );
		IATO_Test_Abilities_State::$caps = self::ROLE_CAPS['contributor'];
		$this->assertTrue( $registered['iato/list-crawls']['permission_callback']( [] ), 'edit_posts is enough once lowered' );

		$this->assertSame( 'edit_posts', IATO_MCP_Abilities::capability_for( 'list-crawls' ) );
		$this->assertSame( 'manage_options', IATO_MCP_Abilities::capability_for( 'get-seo-fixes' ) );
	}

	public function test_capability_filter_bad_values_fall_back_to_default(): void {
		add_filter( IATO_MCP_Abilities::CAPABILITY_FILTER, fn() => '' );
		$this->assertSame( 'manage_options', IATO_MCP_Abilities::capability_for( 'get-sitemap' ) );
		IATO_Test_Abilities_State::$filters = [];
		add_filter( IATO_MCP_Abilities::CAPABILITY_FILTER, fn() => [ 'not', 'a', 'cap' ] );
		$this->assertSame( 'manage_options', IATO_MCP_Abilities::capability_for( 'get-sitemap' ) );
	}

	public function test_capability_is_evaluated_at_call_time(): void {
		// A filter added after registration still applies: the callback asks
		// capability_for() on every call rather than capturing the cap.
		$registered = $this->register_all();
		IATO_Test_Abilities_State::$caps = self::ROLE_CAPS['editor'];
		$this->assertFalse( $registered['iato/get-taxonomy']['permission_callback']( [] ) );
		add_filter( IATO_MCP_Abilities::CAPABILITY_FILTER, fn( $cap ) => 'edit_posts' );
		$this->assertTrue( $registered['iato/get-taxonomy']['permission_callback']( [] ) );
	}

	public function test_disabled_tool_is_skipped(): void {
		IATO_Test_Abilities_State::$disabled_tools = [ 'get_iato_seo_fixes' ];
		$registered = $this->register_all();
		$this->assertArrayNotHasKey( 'iato/get-seo-fixes', $registered );
		$this->assertCount( 10, $registered );
	}

	public function test_is_enabled_reads_the_string_flag(): void {
		$cases = [ 'MISSING' => true, '1' => true, '0' => false, '' => false, 'false' => false, true => true, false => false, 1 => true, 0 => false ];
		foreach ( $cases as $stored => $expected ) {
			IATO_Test_Abilities_State::reset();
			if ( 'MISSING' !== $stored ) {
				IATO_Test_Abilities_State::$options[ IATO_MCP_Abilities::OPTION ] = $stored;
			}
			$this->assertSame( $expected, IATO_MCP_Abilities::is_enabled(), 'stored ' . var_export( $stored, true ) );
		}
	}

	public function test_init_is_noop_when_setting_off(): void {
		IATO_Test_Abilities_State::$options[ IATO_MCP_Abilities::OPTION ] = false;
		IATO_MCP_Abilities::init();
		$this->assertSame( [], IATO_Test_Abilities_State::$actions );
		$this->assertFalse( IATO_MCP_Abilities::is_enabled() );
	}

	public function test_init_hooks_both_actions_when_enabled(): void {
		// Option missing => enabled by default.
		IATO_MCP_Abilities::init();
		$this->assertTrue( IATO_MCP_Abilities::is_enabled() );
		$this->assertArrayHasKey( 'wp_abilities_api_categories_init', IATO_Test_Abilities_State::$actions );
		$this->assertArrayHasKey( 'wp_abilities_api_init', IATO_Test_Abilities_State::$actions );
		$this->assertSame( [ IATO_MCP_Abilities::class, 'register_category' ], IATO_Test_Abilities_State::$actions['wp_abilities_api_categories_init'][0] );
		$this->assertSame( [ IATO_MCP_Abilities::class, 'register_abilities' ], IATO_Test_Abilities_State::$actions['wp_abilities_api_init'][0] );
	}

	// ── Execution ─────────────────────────────────────────────────────────

	public function test_execute_not_connected_error(): void {
		IATO_Test_Abilities_State::$options['iato_mcp_api_key'] = '';
		$err = IATO_MCP_Abilities::execute( 'list-crawls', [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'iato_not_connected', $err->get_error_code() );
		$this->assertStringContainsString( 'Settings > IATO MCP', $err->get_error_message() );
	}

	public function test_execute_passes_handler_errors_through_unchanged(): void {
		// No crawl_id and no default => the production handler's own error.
		$err = IATO_MCP_Abilities::execute( 'get-orphan-pages', [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'missing_crawl_id', $err->get_error_code() );

		$direct = IATO_MCP_Server::get_catalog_entry( 'get_iato_orphan_pages' )['handler']( [] );
		$this->assertSame( $direct->get_error_message(), $err->get_error_message(), 'same message as the MCP tool' );
	}

	public function test_execute_unknown_slug(): void {
		$this->assertSame( 'iato_ability_unknown', IATO_MCP_Abilities::execute( 'nope', [] )->get_error_code() );
	}

	public function test_execute_converts_throwable_to_wp_error(): void {
		IATO_MCP_Server::register_tool( 'get_iato_broken_links', [ 'inputSchema' => [ 'type' => 'object' ] ], function ( array $a ) {
			throw new RuntimeException( 'simulated failure inside the handler' );
		} );
		$err = IATO_MCP_Abilities::execute( 'get-broken-links', [] );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'iato_ability_exception', $err->get_error_code() );
		$this->assertStringContainsString( 'simulated failure', $err->get_error_message() );
	}

	public function test_execute_callback_from_registration_returns_decoded_payload(): void {
		IATO_MCP_Server::register_tool( 'get_iato_taxonomy', [ 'inputSchema' => [ 'type' => 'object', 'properties' => [ 'crawl_id' => [ 'type' => 'string' ] ] ] ], fn( array $a ) => IATO_MCP_Server::ok( [ 'crawl_id' => $a['crawl_id'] ?? null, 'categories' => [] ] ) );
		$args   = $this->register_all()['iato/get-taxonomy'];
		$result = $args['execute_callback']( [ 'crawl_id' => 'c1' ] );
		$this->assertSame( [ 'crawl_id' => 'c1', 'categories' => [] ], $result );
		$this->assertSame( [ 'crawl_id' => null, 'categories' => [] ], $args['execute_callback']( null ), 'null input tolerated' );
	}
}

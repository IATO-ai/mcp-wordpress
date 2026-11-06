<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once IATO_MCP_DIR . 'includes/class-meta-policy.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-post-meta.php';

/**
 * update_post_meta and the Elementor meta allowlist (1.13.0): every
 * `_elementor_` key outside ELEMENTOR_WRITABLE is refused with
 * elementor_content_key; `_elementor_page_settings` array values are
 * sanitised by shape unless unfiltered HTML is allowed.
 */
final class MetaContentKeysTest extends TestCase {

	private const POST = 4242;

	protected function setUp(): void {
		IATO_Test_Abilities_State::reset();
		IATO_Test_Auth_State::reset();
		IATO_Test_WP::$meta   = [ self::POST => [] ];
		IATO_Test_WP::$posts  = [ self::POST => [ 'post_content' => '', 'post_type' => 'page' ] ];
		IATO_Test_WP::$writes = [];
		$GLOBALS['wpdb']->stored_keys    = [];
		$GLOBALS['wpdb']->other_spelling = null;
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		foreach ( [ 'authenticated' => true, 'authenticated_user' => null, 'site_key_full_access' => true, 'unfiltered_html_allowed' => false, 'credential' => [ 'kind' => 'site_key', 'user_id' => 0 ] ] as $name => $value ) {
			$ref->getProperty( $name )->setValue( null, $value );
		}
		IATO_MCP_Elementor_Sanitizer::set_functions( [
			'kses' => fn( string $v ) => 'K[' . strip_tags( $v ) . ']',
			'url'  => fn( string $v ) => $v,
			'css'  => fn( string $v ) => 'C[' . strip_tags( $v ) . ']',
		] );
	}

	protected function tearDown(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( null );
	}

	private function call( array $args ): array {
		$entry = IATO_MCP_Server::get_catalog_entry( 'update_post_meta' );
		$this->assertNotNull( $entry, 'tool registered' );
		$r = ( $entry['handler'] )( $args + [ 'id' => self::POST ] );
		if ( is_wp_error( $r ) ) {
			return [ 'error' => $r->get_error_code(), 'message' => $r->get_error_message(), 'data' => $r->get_error_data() ];
		}
		return json_decode( $r['content'][0]['text'], true );
	}

	/** What the handler returned, undecoded: tool_error() results keep their isError. */
	private function raw( array $args ): array {
		$r = ( IATO_MCP_Server::get_catalog_entry( 'update_post_meta' )['handler'] )( $args + [ 'id' => self::POST ] );
		return is_wp_error( $r ) ? [ 'isError' => true, 'error' => $r->get_error_code() ] : $r;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'content_keys' )]
	public function test_builder_keys_are_refused_with_and_without_force( string $key ): void {
		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ], [ 'force' => true, 'dry_run' => true ] ] as $extra ) {
			$r = $this->call( [ 'key' => $key, 'value' => [ [ 'id' => 'x', 'settings' => [ 'title' => '<script>1</script>' ] ] ] ] + $extra );
			$this->assertSame( 'elementor_content_key', $r['error'] ?? null, $key . ' ' . json_encode( $extra ) );
			$this->assertStringContainsString( 'update_elementor_data', $r['message'] );
			$this->assertStringContainsString( 'update_elementor_widget', $r['message'] );
		}
		$this->assertSame( [], IATO_Test_WP::$writes, 'nothing written' );
	}

	public static function content_keys(): array {
		return [
			[ '_elementor_data' ],
			[ '_elementor_css' ],
			[ '_elementor_inline_svg' ],
			[ '_elementor_markdown_cache' ],
			[ '_elementor_conditions' ],
			[ '_elementor_global_classes' ],
			[ '_ELEMENTOR_DATA' ],
			[ '_elementor_anything_an_addon_adds' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'keys_the_database_matches' )]
	public function test_a_key_the_database_reads_as_another_is_refused( string $key, string $row, array $extra ): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = 'stored';
		$GLOBALS['wpdb']->stored_keys = [ $row ];
		foreach ( [ [], [ 'dry_run' => true ] ] as $mode ) {
			$r = $this->call( [ 'key' => $key, 'value' => 'x' ] + $extra + $mode );
			$this->assertSame( 'meta_key_mismatch', $r['error'] ?? null, $key );
			$this->assertSame( $row, $r['data']['stored_key'] );
		}
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertSame( 'stored', IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] );
	}

	public static function keys_the_database_matches(): array {
		return [
			'look-alike first character'    => [ "\xEF\xBC\xBFelementor_data", '_elementor_data', [ 'force' => true ] ],
			'look-alike, a flag'            => [ "\xEF\xBC\xBFelementor_edit_mode", '_elementor_edit_mode', [ 'force' => true ] ],
			'trailing space'                => [ 'site-post-title ', 'site-post-title', [ 'force' => true ] ],
			'another case of an open key'   => [ 'Site-Post-Title', 'site-post-title', [] ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'keys_with_backslashes' )]
	public function test_a_backslash_in_a_key_is_refused_even_with_force( string $key ): void {
		foreach ( [ [], [ 'force' => true ], [ 'dry_run' => true ], [ 'value' => null ] ] as $mode ) {
			$r = $this->call( $mode + [ 'key' => $key, 'value' => 'x' ] );
			$this->assertSame( 'invalid_meta_key', $r['error'] ?? null, $key );
		}
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertSame( 'invalid_meta_key', IATO_MCP_Meta_Policy::check_stored_key( self::POST, $key )->get_error_code(), 'a rollback of an old receipt too' );
	}

	public static function keys_with_backslashes(): array {
		return [
			'before the underscore' => [ '\\_elementor_data' ],
			'inside'                => [ '_elementor\\_edit_mode' ],
			'a denied key'          => [ '\\_elementor\\_element_cache' ],
			'an open key'           => [ 'plain\\key' ],
			'doubled'               => [ '\\\\_thumbnail_id' ],
		];
	}

	public function test_another_case_of_an_allowed_key_is_not_that_key(): void {
		$this->assertSame( 'elementor_content_key', $this->call( [ 'key' => '_ELEMENTOR_EDIT_MODE', 'value' => 'builder' ] )['error'] ?? null );
		$this->assertSame( 'elementor_content_key', $this->call( [ 'key' => '_ELEMENTOR_EDIT_MODE', 'value' => 'builder', 'force' => true ] )['error'] ?? null );
		$this->assertSame( 'meta_requires_force', $this->call( [ 'key' => '_WP_PAGE_TEMPLATE', 'value' => 'x' ] )['error'] ?? null );
		$this->assertSame( 'meta_requires_force', $this->call( [ 'key' => '_YOAST_WPSEO_TITLE', 'value' => 'x' ] )['error'] ?? null );
		$this->assertArrayNotHasKey( 'error', $this->call( [ 'key' => '_elementor_edit_mode', 'value' => 'builder' ] ) );
		$this->assertArrayNotHasKey( 'error', $this->call( [ 'key' => '_wp_page_template', 'value' => 'x' ] ) );
	}

	public function test_a_new_key_is_refused_when_another_spelling_is_stored_elsewhere(): void {
		$GLOBALS['wpdb']->other_spelling = 'rank_math_title';
		foreach ( [ [], [ 'dry_run' => true ], [ 'force' => true ] ] as $mode ) {
			$r = $this->call( [ 'key' => 'RANK_MATH_TITLE', 'value' => 'x' ] + $mode );
			$this->assertSame( 'meta_key_mismatch', $r['error'] ?? null );
			$this->assertSame( 'rank_math_title', $r['data']['stored_key'] );
		}
		$this->assertSame( [], IATO_Test_WP::$writes );
	}

	public function test_a_row_this_post_already_has_writes_whatever_other_posts_hold(): void {
		$GLOBALS['wpdb']->stored_keys    = [ 'myKey' ];
		$GLOBALS['wpdb']->other_spelling = 'MYKEY';
		$this->assertArrayNotHasKey( 'error', $this->call( [ 'key' => 'myKey', 'value' => 'x' ] ) );
	}

	public function test_a_key_of_zero_is_refused_as_wordpress_stores_nothing(): void {
		$r = $this->call( [ 'key' => '0', 'value' => 'x' ] );
		$this->assertSame( 'invalid_meta_key', $r['error'] ?? null );
		$this->assertSame( [], IATO_Test_WP::$writes );
	}

	public function test_the_exact_stored_key_still_writes(): void {
		$GLOBALS['wpdb']->stored_keys = [ 'site-post-title' ];
		$this->assertArrayNotHasKey( 'error', $this->call( [ 'key' => 'site-post-title', 'value' => 'disabled' ] ) );
	}

	public function test_a_key_longer_than_the_column_is_refused_as_nothing_would_be_stored(): void {
		$r = $this->call( [ 'key' => str_repeat( 'k', 256 ), 'value' => 'x' ] );
		$this->assertSame( 'meta_key_too_long', $r['error'] ?? null );
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertArrayNotHasKey( 'error', $this->call( [ 'key' => str_repeat( 'k', 255 ), 'value' => 'x' ] ) );
	}

	public function test_a_new_key_outside_ascii_needs_force(): void {
		foreach ( [ "\xEF\xBC\xBFelementor_data", 'with space', "gr\xC3\xB6sse" ] as $key ) {
			$this->assertSame( 'meta_requires_force', $this->call( [ 'key' => $key, 'value' => 'x' ] )['error'] ?? null, $key );
			$this->assertArrayNotHasKey( 'error', $this->call( [ 'key' => $key, 'value' => 'x', 'force' => true ] ), $key );
		}
	}

	public function test_allowlisted_keys_are_accepted(): void {
		$r = $this->call( [ 'key' => '_elementor_edit_mode', 'value' => 'builder' ] );
		$this->assertSame( 'builder', $r['after_value'] );
		$this->assertSame( 'builder', IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] );
		$r = $this->call( [ 'key' => '_elementor_template_type', 'value' => 'wp-page' ] );
		$this->assertSame( 'wp-page', $r['after_value'] );
		$this->assertSame( [ '_elementor_page_settings', '_elementor_edit_mode', '_elementor_template_type' ], IATO_MCP_Meta_Policy::ELEMENTOR_WRITABLE );
	}

	public function test_page_settings_values_are_sanitised_only_where_they_changed(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_page_settings'] = [ 'hide_title' => 'yes', 'custom_css' => 'h1 > b { color: red }' ];
		$r = $this->call( [ 'key' => '_elementor_page_settings', 'value' => [ 'hide_title' => 'yes', 'custom_css' => 'h1 > b { color: red } </style><script>alert(1)</script>', 'note' => '<i>x</i>', 'plain' => 'a & b' ] ] );
		$this->assertTrue( $r['sanitized'] );
		$this->assertSame( [ '_elementor_page_settings/custom_css', '_elementor_page_settings/note' ], $r['sanitized_paths'] );
		$stored = IATO_Test_WP::$meta[ self::POST ]['_elementor_page_settings'];
		$this->assertSame( 'yes', $stored['hide_title'] );
		$this->assertSame( 'C[h1 > b { color: red } alert(1)]', $stored['custom_css'] );
		$this->assertSame( 'K[x]', $stored['note'] );
		$this->assertSame( 'a & b', $stored['plain'] );

		$r = $this->call( [ 'key' => '_elementor_page_settings', 'value' => $stored ] );
		$this->assertArrayNotHasKey( 'sanitized', $r, 'writing the stored value back alters nothing' );

		$r = $this->call( [ 'key' => '_elementor_page_settings', 'value' => [ 'custom_css' => 'x < y' ], 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( [ '_elementor_page_settings/custom_css' ], $r['sanitized_paths'], 'dry run reports the same' );
	}

	public function test_unfiltered_html_allowed_stores_page_settings_raw(): void {
		( new ReflectionClass( IATO_MCP_Auth::class ) )->getProperty( 'unfiltered_html_allowed' )->setValue( null, true );
		$r = $this->call( [ 'key' => '_elementor_page_settings', 'value' => [ 'note' => '<i>x</i>' ] ] );
		$this->assertArrayNotHasKey( 'sanitized', $r );
		$this->assertSame( '<i>x</i>', IATO_Test_WP::$meta[ self::POST ]['_elementor_page_settings']['note'] );
	}

	public function test_markup_in_changed_strings_of_non_elementor_arrays_is_sanitised(): void {
		// Stored by a person: markup in one field, plain text in another.
		IATO_Test_WP::$meta[ self::POST ]['my_public_key'] = [ 'human' => '<iframe src="x"></iframe>', 'plain' => 'a & b' ];
		$r = $this->call( [ 'key' => 'my_public_key', 'value' => [ 'human' => '<iframe src="x"></iframe>', 'plain' => 'a & b', 'new' => '<b>x</b><script>y</script>', 'deep' => [ 'n' => '<i>z</i>' ], 'text' => 'no markup' ] ] );
		$this->assertSame( [ 'my_public_key/new', 'my_public_key/deep/n' ], $r['sanitized_paths'] );
		$stored = IATO_Test_WP::$meta[ self::POST ]['my_public_key'];
		$this->assertSame( '<iframe src="x"></iframe>', $stored['human'], 'unchanged values are kept verbatim' );
		$this->assertSame( 'K[xy]', $stored['new'] );
		$this->assertSame( 'K[z]', $stored['deep']['n'] );
		$this->assertSame( [ 'a & b', 'no markup' ], [ $stored['plain'], $stored['text'] ], 'strings without markup are untouched' );

		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'unfiltered_html_allowed' )->setValue( null, true );
		$r = $this->call( [ 'key' => 'my_public_key', 'value' => [ 'new' => '<b>raw</b>' ] ] );
		$ref->getProperty( 'unfiltered_html_allowed' )->setValue( null, false );
		$this->assertArrayNotHasKey( 'sanitized', $r );
		$this->assertSame( [ 'new' => '<b>raw</b>' ], IATO_Test_WP::$meta[ self::POST ]['my_public_key'], 'raw when unfiltered HTML is allowed' );
	}

	public function test_template_type_needs_an_administrator(): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, false );
		$ref->getProperty( 'authenticated_user' )->setValue( null, new WP_User( 9 ) );
		IATO_Test_Roles::$users[9] = 'editor';
		IATO_Test_Roles::$posts[ self::POST ] = [ 'post_type' => 'page', 'post_author' => 9, 'post_status' => 'publish' ];
		$r = $this->call( [ 'key' => '_elementor_template_type', 'value' => 'header' ] );
		$this->assertSame( 'iato_mcp_forbidden', $r['error'] ?? null, 'an Editor may edit the page but not change its template type' );
		$this->assertArrayNotHasKey( '_elementor_template_type', IATO_Test_WP::$meta[ self::POST ] );
		IATO_Test_Roles::$users[1] = 'administrator';
		$ref->getProperty( 'authenticated_user' )->setValue( null, new WP_User( 1 ) );
		$r = $this->call( [ 'key' => '_elementor_template_type', 'value' => 'wp-page' ] );
		$this->assertSame( 'wp-page', IATO_Test_WP::$meta[ self::POST ]['_elementor_template_type'] ?? null, $r['message'] ?? '' );
	}

	public function test_backslashes_survive_meta_writes(): void {
		$b = chr( 92 );
		$v = "a{$b}b C:{$b}Users {$b}d+ quote{$b}\" esc{$b}{$b}";
		$this->call( [ 'key' => 'probe_string', 'value' => $v ] );
		$this->assertSame( $v, IATO_Test_WP::$meta[ self::POST ]['probe_string'], 'update_post_meta() unslashes: the tool slashes first' );
		$this->call( [ 'key' => 'probe_array', 'value' => [ 'v' => $v, 'n' => [ 'deep' => $v ] ] ] );
		$this->assertSame( [ 'v' => $v, 'n' => [ 'deep' => $v ] ], IATO_Test_WP::$meta[ self::POST ]['probe_array'] );
		$css = ".x:before { content: \"{$b}201C\"; }";
		$this->call( [ 'key' => '_elementor_page_settings', 'value' => [ 'custom_css' => $css ] ] );
		$this->assertStringContainsString( "{$b}201C", IATO_Test_WP::$meta[ self::POST ]['_elementor_page_settings']['custom_css'], 'CSS escapes in Elementor page settings survive' );
	}

	public function test_policy_helper_directly(): void {
		$this->assertTrue( IATO_MCP_Meta_Policy::check_elementor_meta_write( 'my_key' ) );
		$this->assertTrue( IATO_MCP_Meta_Policy::check_elementor_meta_write( '_elementor_page_settings' ) );
		$this->assertInstanceOf( WP_Error::class, IATO_MCP_Meta_Policy::check_elementor_meta_write( '_Elementor_Page_Settings' ), 'another case would be a new row' );
		$err = IATO_MCP_Meta_Policy::check_elementor_meta_write( '_elementor_data' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'elementor_content_key', $err->get_error_code() );
		$this->assertSame( IATO_MCP_Meta_Policy::ELEMENTOR_WRITABLE, $err->get_error_data()['writable_elementor_keys'] );
	}

	public function test_turning_elementor_on_for_a_page_with_elementor_data_is_refused(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = '[{"id":"a"}]';
		foreach ( [ 'builder', '1', true, 1 ] as $value ) {
			$this->assertStringContainsString( 'elementor_not_active', json_encode( $this->raw( [ 'key' => '_elementor_edit_mode', 'value' => $value ] ) ), var_export( $value, true ) );
		}
		$this->assertArrayNotHasKey( '_elementor_edit_mode', IATO_Test_WP::$meta[ self::POST ] );
	}

	public function test_elementor_can_still_be_turned_off_and_a_plain_post_on(): void {
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data']      = '[{"id":"a"}]';
		IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] = 'builder';
		$this->assertArrayNotHasKey( 'isError', $this->raw( [ 'key' => '_elementor_edit_mode', 'value' => null ] ) );
		unset( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] );
		$this->assertArrayNotHasKey( 'isError', $this->raw( [ 'key' => '_elementor_edit_mode', 'value' => 'builder' ] ) );
	}
}

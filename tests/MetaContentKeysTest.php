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
			[ '_elementor_element_cache' ],
			[ '_elementor_css' ],
			[ '_elementor_inline_svg' ],
			[ '_elementor_markdown_cache' ],
			[ '_elementor_conditions' ],
			[ '_elementor_global_classes' ],
			[ '_ELEMENTOR_DATA' ],
			[ '_elementor_anything_an_addon_adds' ],
		];
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

	public function test_non_elementor_keys_are_unchanged_by_this_part(): void {
		$r = $this->call( [ 'key' => 'my_public_key', 'value' => [ 'a' => '<b>x</b>' ] ] );
		$this->assertArrayNotHasKey( 'sanitized', $r );
		$this->assertSame( [ 'a' => '<b>x</b>' ], IATO_Test_WP::$meta[ self::POST ]['my_public_key'] );
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
		$this->assertTrue( IATO_MCP_Meta_Policy::check_elementor_meta_write( '_Elementor_Page_Settings' ) );
		$err = IATO_MCP_Meta_Policy::check_elementor_meta_write( '_elementor_data' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'elementor_content_key', $err->get_error_code() );
		$this->assertSame( IATO_MCP_Meta_Policy::ELEMENTOR_WRITABLE, $err->get_error_data()['writable_elementor_keys'] );
	}
}

<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-elementor.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-actor.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';

final class IATO_Test_Writer_State {
	/** @var array<int,int> post id => user id holding the post lock */
	public static array $locks = [];
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $id ): string|false {
		return IATO_Test_WP::$attachments[ $id ]['url'] ?? false;
	}
}
if ( ! function_exists( 'wp_check_post_lock' ) ) {
	function wp_check_post_lock( int $post_id ): int|false {
		return IATO_Test_Writer_State::$locks[ $post_id ] ?? false;
	}
}

/**
 * 1.14.0: IATO_MCP_Elementor_Atomic_Writer — plain values on Elementor
 * Atomic Editor (V4) elements converted and validated with Elementor's code
 * (here FakeAtomicEngine, modelled on Elementor 4.3.4), built from the stored
 * value, checked against the page, written, verified, rolled back.
 */
final class ElementorWriterAtomicTest extends TestCase {

	private const POST = ElementorWritePipelineHelper::POST;

	private FakeAtomicEngine $engine;

	private static function env( string $type, mixed $value ): array {
		return [ '$$type' => $type, 'value' => $value ];
	}

	/** An atomic page: heading with a link, paragraph, URL image, library image, button linking to a post, and a classic heading. */
	private function page(): array {
		return [ [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
			[ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [
				'tag'   => self::env( 'string', 'h2' ),
				'title' => self::env( 'escaped-html', 'Old title' ),
				'link'  => self::env( 'link', [ 'destination' => self::env( 'url', 'https://old.test/' ), 'isTargetBlank' => self::env( 'boolean', true ) ] ),
			], 'elements' => [] ],
			[ 'id' => 'p1', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => [ 'paragraph' => self::env( 'escaped-html', 'Tom &amp; Jerry' ) ], 'elements' => [] ],
			[ 'id' => 'i1', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => [
				'image' => self::env( 'image', [ 'src' => self::env( 'image-src', [ 'url' => self::env( 'url', 'https://cdn.test/a.jpg' ), 'alt' => self::env( 'string', 'Old alt' ) ] ), 'size' => self::env( 'string', 'full' ) ] ),
			], 'elements' => [] ],
			[ 'id' => 'i2', 'elType' => 'widget', 'widgetType' => 'e-image', 'settings' => [
				'image' => self::env( 'image', [ 'src' => self::env( 'image-src', [ 'id' => self::env( 'image-attachment-id', 5 ) ] ), 'size' => self::env( 'string', 'large' ) ] ),
			], 'elements' => [] ],
			[ 'id' => 'b1', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => [
				'text' => self::env( 'escaped-html', 'Go' ),
				'link' => self::env( 'link', [ 'destination' => self::env( 'query', [ 'id' => self::env( 'number', 42 ), 'label' => self::env( 'string', 'Sample' ) ] ) ] ),
			], 'elements' => [] ],
			[ 'id' => 'c1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => '<b>Classic</b> & co', 'header_size' => 'h2' ], 'elements' => [] ],
		] ] ];
	}

	protected function setUp(): void {
		$posts = [
			self::POST => [ 'post_type' => 'page', 'post_author' => 1, 'post_status' => 'publish', 'post_content' => '<h2>old</h2>', 'post_modified_gmt' => '2026-10-10 10:00:00' ],
			5          => [ 'post_type' => 'attachment', 'post_author' => 1, 'post_status' => 'inherit' ],
		];
		IATO_Test_WP::$posts    = $posts;
		IATO_Test_Roles::$posts = $posts;
		IATO_Test_Roles::$users = [ 1 => 'administrator', 7 => 'author' ];
		IATO_Test_WP::$meta     = [ self::POST => [ '_elementor_data' => json_encode( $this->page() ) ] ];
		IATO_Test_WP::$writes   = [];
		IATO_Test_Writer_State::$locks          = [];
		IATO_Test_OAuth_State::$transients      = [];
		$GLOBALS['wpdb']->inserts               = [];
		$GLOBALS['wpdb']->row                   = null;
		\Elementor\FakeDocument::$mode          = 'ok';
		\Elementor\FakeDocument::$received      = [];
		\Elementor\FakeDocument::$throw_message = 'Settings validation failed. simulated';
		\Elementor\FakeFilesManager::$cleared   = 0;
		\Elementor\FakeFrontend::$throw        = false;
		IATO_Test_Actor::reset();
		IATO_Test_Actor::$logins = [ 1 => 'admin', 7 => 'author' ];
		IATO_MCP_Elementor_Sanitizer::set_functions( [ 'kses' => fn( string $v ) => $v, 'url' => fn( string $v ) => $v, 'css' => fn( string $v ) => $v ] );
		$this->engine = FakeAtomicEngine::heading_site();
		IATO_MCP_Elementor_Atomic_Writer::set_engine( $this->engine );
		$this->registry();
		self::auth( true );
	}

	protected function tearDown(): void {
		IATO_MCP_Elementor_Atomic_Writer::set_engine( null );
		IATO_MCP_Elementor_Atomic_Writer::set_kses( null );
		IATO_MCP_Elementor_Atomic::set_resolver( null );
		IATO_MCP_Elementor_Sanitizer::set_functions( null );
		self::auth( false );
	}

	/** Elementor's registry as IATO_MCP_Elementor_Atomic sees it: the fake engine's schemas. */
	private function registry(): void {
		$engine = $this->engine;
		IATO_MCP_Elementor_Atomic::set_resolver( function ( string $op, ...$args ) use ( $engine ) {
			if ( 'type_info' !== $op ) {
				return null;
			}
			$type = 'widget' === $args[0] ? $args[1] : $args[0];
			if ( in_array( $type, [ 'heading', 'container', 'e-iframe' ], true ) ) {
				return [ 'registered' => true, 'schema' => null ];
			}
			if ( ! isset( $engine->schemas[ $type ] ) ) {
				return [ 'registered' => false, 'schema' => null ];
			}
			return [ 'registered' => true, 'schema' => array_map( fn( $p ) => [ 'type' => $p['type'], 'kind' => $p['kind'] ], $engine->schemas[ $type ] ) ];
		} );
	}

	/** An authenticated request; $unbound = a legacy site key with no owner (user 0). */
	private static function auth( bool $on, bool $unbound = false ): void {
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, $on );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, $on );
		$ref->getProperty( 'legacy_unbound' )->setValue( null, $on && $unbound );
		$ref->getProperty( 'credential' )->setValue( null, $on ? [ 'kind' => 'site_key', 'user_id' => $unbound ? 0 : 1 ] : [] );
	}

	private function stored(): array {
		return json_decode( (string) IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true );
	}

	private function node( string $id, ?array $tree = null ): array {
		return IATO_MCP_Elementor_Adapter::find_widget( $tree ?? $this->stored(), $id )['element'];
	}

	private function update( string $id, array $patch, bool $dry_run = false ): array|WP_Error {
		return IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => $id, 'settings_patch' => $patch, 'dry_run' => $dry_run ] );
	}

	private function revision(): string {
		return IATO_MCP_Elementor_Adapter::compute_revision( (string) IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] );
	}

	private function last_receipt(): array {
		$inserts = $GLOBALS['wpdb']->inserts;
		$row     = end( $inserts )[1];
		return $row + [ 'rolled_back_at' => null ];
	}

	// ── Plain values, converted and stored ─────────────────────────────────

	public function test_a_plain_heading_level_is_converted_validated_and_stored_live(): void {
		$before = $this->page();
		$r      = $this->update( 'h1', [ 'tag' => 'h3' ] );

		$this->assertIsArray( $r );
		$this->assertSame( [ 'iato-native', 'atomic', 'live' ], [ $r['applied_via'], $r['schema'], $r['state'] ] );
		$this->assertSame( [ [ 'key' => 'tag', 'from' => 'h2', 'to' => 'h3' ] ], $r['applied'] );
		$this->assertSame( [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => self::env( 'string', 'h3' ), 'previous_value' => self::env( 'string', 'h2' ) ] ], $r['applied_patch'] );
		$this->assertTrue( $r['elementor_save'] );
		$this->assertSame( 'elementor', $r['stored_by'] );
		$this->assertTrue( $r['verified'] );
		$this->assertSame( 'post', $r['css']['scope'] );
		$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared, 'no global CSS flush on the atomic path' );
		$this->assertCount( 1, \Elementor\FakeDocument::$received );
		$this->assertSame( 'elementor_atomic', $r['change_receipt']['target_type'] );
		$this->assertSame( 'elementor:h1:settings', $r['change_receipt']['field'] );

		$stored = $this->stored();
		$this->assertSame( self::env( 'string', 'h3' ), $this->node( 'h1', $stored )['settings']['tag'] );
		$this->assertSame( $before[0]['elements'][0]['settings']['link'], $this->node( 'h1', $stored )['settings']['link'], 'untouched keys keep their value' );
		foreach ( [ 'p1', 'i1', 'i2', 'b1', 'c1' ] as $id ) {
			$this->assertSame( IATO_MCP_Elementor_Adapter::find_widget( $before, $id )['element']['settings'], $this->node( $id, $stored )['settings'], "{$id} untouched" );
		}
	}

	public function test_the_receipt_holds_the_keys_before_and_as_read_back(): void {
		$this->update( 'h1', [ 'tag' => 'h3' ] );
		$receipt = $this->last_receipt();
		$before  = json_decode( $receipt['before_value'], true );
		$after   = json_decode( $receipt['after_value'], true );
		$this->assertSame( [ 'element_id' => 'h1', 'type' => 'e-heading', 'path' => '/0/elements/0', 'keys' => [ 'tag' => self::env( 'string', 'h2' ) ] ], $before );
		$this->assertSame( [ 'tag' => self::env( 'string', 'h3' ) ], $after['keys'] );
	}

	public function test_aliases_resolve_to_the_setting_key(): void {
		$r = $this->update( 'h1', [ 'text' => 'New title' ] );
		$this->assertSame( [ 'text' => 'title' ], $r['aliases'] );
		$this->assertSame( self::env( 'escaped-html', 'New title' ), $this->node( 'h1' )['settings']['title'] );
		$dup = $this->update( 'h1', [ 'text' => 'A', 'title' => 'B' ] );
		$this->assertSame( 'atomic_duplicate_key', $dup->get_error_code() );
	}

	public function test_a_plain_link_destination_keeps_the_stored_new_tab_flag(): void {
		$this->update( 'h1', [ 'link' => [ 'destination' => 'https://new.test/' ] ] );
		$this->assertSame(
			self::env( 'link', [ 'destination' => self::env( 'url', 'https://new.test/' ), 'isTargetBlank' => self::env( 'boolean', true ) ] ),
			$this->node( 'h1' )['settings']['link']
		);
	}

	public function test_a_post_link_keeps_its_query_when_another_field_changes(): void {
		$this->update( 'b1', [ 'link' => [ 'isTargetBlank' => true ], 'text' => 'Read more' ] );
		$link = $this->node( 'b1' )['settings']['link'];
		$this->assertSame( self::env( 'query', [ 'id' => self::env( 'number', 42 ), 'label' => self::env( 'string', 'Sample' ) ] ), $link['value']['destination'], 'query id and label byte for byte' );
		$this->assertSame( self::env( 'boolean', true ), $link['value']['isTargetBlank'] );
		$this->assertSame( self::env( 'escaped-html', 'Read more' ), $this->node( 'b1' )['settings']['text'] );
	}

	public function test_an_external_image_alt_keeps_its_url_and_size(): void {
		$r = $this->update( 'i1', [ 'image' => [ 'src' => [ 'alt' => 'A new alt' ] ] ] );
		$this->assertSame( [ [ 'key' => 'image', 'from' => [ 'src' => [ 'url' => 'https://cdn.test/a.jpg', 'alt' => 'Old alt' ], 'size' => 'full' ], 'to' => [ 'src' => [ 'url' => 'https://cdn.test/a.jpg', 'alt' => 'A new alt' ], 'size' => 'full' ] ] ], $r['applied'] );
		$this->assertSame( self::env( 'image', [ 'src' => self::env( 'image-src', [ 'url' => self::env( 'url', 'https://cdn.test/a.jpg' ), 'alt' => self::env( 'string', 'A new alt' ) ] ), 'size' => self::env( 'string', 'full' ) ] ), $this->node( 'i1' )['settings']['image'] );
	}

	public function test_setting_a_url_on_a_library_image_clears_its_attachment_id(): void {
		$this->update( 'i2', [ 'image' => [ 'src' => [ 'url' => 'https://cdn.test/b.jpg', 'alt' => 'B' ] ] ] );
		$src = $this->node( 'i2' )['settings']['image']['value']['src']['value'];
		$this->assertArrayNotHasKey( 'id', $src, 'Elementor requires an id or a url, not both' );
		$this->assertSame( self::env( 'url', 'https://cdn.test/b.jpg' ), $src['url'] );
	}

	public function test_alt_on_a_library_image_goes_to_the_attachment(): void {
		$r = $this->update( 'i2', [ 'image' => [ 'src' => [ 'alt' => 'Library alt' ] ] ] );
		$this->assertSame( 'attachment_alt', $r['routed_to'] );
		$this->assertSame( 5, $r['attachment_id'] );
		$this->assertSame( 'all uses of attachment 5', $r['scope'] );
		$this->assertSame( 'image', $r['change_receipt']['target_type'] );
		$this->assertContains( [ 5, '_wp_attachment_image_alt', 'Library alt' ], IATO_Test_WP::$writes );
		$this->assertSame( json_encode( $this->page() ), IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], 'the page is not written' );

		$both = $this->update( 'i2', [ 'image' => [ 'src' => [ 'alt' => 'X' ], 'size' => 'full' ] ] );
		$this->assertSame( 'atomic_library_alt_separate', $both->get_error_code() );
	}

	public function test_typed_values_pass_through_and_null_removes_a_key(): void {
		$this->update( 'h1', [ 'tag' => self::env( 'string', 'h4' ), 'link' => null ] );
		$settings = $this->node( 'h1' )['settings'];
		$this->assertSame( self::env( 'string', 'h4' ), $settings['tag'] );
		$this->assertArrayNotHasKey( 'link', $settings );
	}

	public function test_text_is_sanitised_by_elementor_and_reported_only_beyond_entities(): void {
		$r = $this->update( 'p1', [ 'paragraph' => 'Tom & Jerry <script>alert(1)</script><b>bold</b>' ] );
		$this->assertSame( self::env( 'escaped-html', 'Tom &amp; Jerry <b>bold</b>' ), $this->node( 'p1' )['settings']['paragraph'] );
		$this->assertSame( [ 'p1/settings/paragraph' ], $r['sanitized_paths'] );
		$plain = $this->update( 'h1', [ 'title' => 'Fish & Chips' ] );
		$this->assertArrayNotHasKey( 'sanitized_paths', $plain, '& stored as &amp; is not a change' );
	}

	public function test_a_change_that_stores_what_is_already_there_is_a_no_op(): void {
		$r = $this->update( 'h1', [ 'tag' => 'h2' ] );
		$this->assertSame( [], $r['applied_patch'] );
		$this->assertFalse( $r['content_updated'] );
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertSame( [], \Elementor\FakeDocument::$received );
	}

	// ── Refusals: nothing is written ───────────────────────────────────────

	public static function refusals(): array {
		return [
			'classic key'          => [ 'h1', [ 'header_size' => 'h3' ], 'atomic_prop_not_supported', 'the heading level is `tag`' ],
			'global classes'       => [ 'h1', [ 'classes' => [ 'g-1' ] ], 'atomic_prop_not_supported', 'global classes' ],
			'unknown link field'   => [ 'h1', [ 'link' => [ 'url' => 'https://x.test/' ] ], 'atomic_prop_not_supported', 'its fields are `destination`, `isTargetBlank`, `tag`' ],
			'invalid level'        => [ 'h1', [ 'tag' => 'h7' ], 'atomic_invalid_settings', 'tag: invalid_value' ],
			'javascript link'      => [ 'h1', [ 'link' => [ 'destination' => 'javascript:alert(1)' ] ], 'atomic_url_rejected', 'not allowed' ],
			'relative image URL'   => [ 'i1', [ 'image' => [ 'src' => [ 'url' => '/wp-content/x.jpg' ] ] ], 'atomic_url_rejected', 'absolute URL' ],
			'unconvertible value'  => [ 'h1', [ 'tag' => [ 'h3' ] ], 'atomic_value_unresolvable', 'could not convert' ],
		];
	}

	#[DataProvider( 'refusals' )]
	public function test_values_elementor_would_not_store_are_refused( string $id, array $patch, string $code, string $says ): void {
		foreach ( [ true, false ] as $dry_run ) {
			$r = $this->update( $id, $patch, $dry_run );
			$this->assertInstanceOf( WP_Error::class, $r, $dry_run ? 'dry run' : 'write' );
			$this->assertSame( $code, $r->get_error_code() );
			$this->assertStringContainsString( $says, $r->get_error_message() );
			$this->assertStringContainsString( 'Nothing was written', $r->get_error_message() );
			$this->assertSame( $id, $r->get_error_data()['element_id'] );
		}
		$this->assertSame( [], IATO_Test_WP::$writes );
		$json = json_decode( IATO_MCP_Elementor_Adapter::tool_error( $r )['content'][0]['text'], true );
		$this->assertSame( $code, $json['error'], 'tools answer with JSON text' );
	}

	public function test_a_typed_link_with_an_unsafe_url_is_refused_too(): void {
		$link = self::env( 'link', [ 'destination' => self::env( 'url', 'javascript:alert(1)' ), 'isTargetBlank' => self::env( 'boolean', true ) ] );
		$r    = $this->update( 'h1', [ 'link' => $link ] );
		$this->assertSame( 'atomic_url_rejected', $r->get_error_code() );
		$this->assertSame( [], IATO_Test_WP::$writes );
	}

	public function test_component_instances_are_not_edited(): void {
		$page                    = $this->page();
		$page[0]['elements'][]   = [ 'id' => 'k1', 'elType' => 'widget', 'widgetType' => 'e-component', 'settings' => [ 'component' => self::env( 'component', 3 ) ], 'elements' => [] ];
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $page );
		$this->assertSame( 'atomic_type_not_supported', $this->update( 'k1', [ 'component' => 4 ] )->get_error_code() );
	}

	public function test_a_stored_invalid_value_elsewhere_in_the_element_is_named(): void {
		$page = $this->page();
		$page[0]['elements'][0]['settings']['tag'] = 'h3'; // stored without its typed wrapper by IATO 1.13.0
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $page );
		$r = $this->update( 'h1', [ 'title' => 'New' ] );
		$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
		$this->assertSame( [ 'tag' ], $r->get_error_data()['stored_invalid_keys'] );
		$this->assertStringContainsString( 'send that setting again through this tool to repair the element', $r->get_error_message() );

		$repair = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertTrue( $repair['verified'] );
		$this->assertSame( self::env( 'string', 'h3' ), $this->node( 'h1' )['settings']['tag'], 'sending the key again repairs it' );
	}

	// ── Availability, permissions, concurrency ─────────────────────────────

	public function test_without_elementors_resolver_plain_values_are_refused_and_typed_values_still_go_through(): void {
		$this->engine->missing = 'Editing atomic elements with plain values needs Elementor 4.3.0 or later; this site runs Elementor 4.2.4.';
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'atomic_write_unavailable', $r->get_error_code() );
		$this->assertStringContainsString( 'this site runs Elementor 4.2.4', $r->get_error_message() );
		$ok = $this->update( 'h1', [ 'tag' => self::env( 'string', 'h3' ) ] );
		$this->assertSame( 'elementor', $ok['stored_by'], 'the 1.13.1 path' );
	}

	public function test_an_unregistered_atomic_type_names_why(): void {
		$this->engine->schemas['e-heading'] = null;
		unset( $this->engine->schemas['e-heading'] );
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'atomic_write_unavailable', $r->get_error_code() );
		$this->assertStringContainsString( 'does not have `e-heading` registered', $r->get_error_message() );
	}

	public function test_an_addon_widget_with_an_e_name_stays_classic(): void {
		$page                  = $this->page();
		$page[0]['elements'][] = [ 'id' => 'x1', 'elType' => 'widget', 'widgetType' => 'e-iframe', 'settings' => [ 'url' => self::env( 'url', 'https://a.test/' ) ], 'elements' => [] ];
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $page );
		$r = $this->update( 'x1', [ 'url' => 'https://b.test/' ] );
		$this->assertSame( 'classic', $r['schema'] );
		$this->assertSame( 'https://b.test/', $this->node( 'x1' )['settings']['url'] );
	}

	public function test_a_registered_atomic_type_without_the_prefix_or_typed_values_goes_to_the_writer(): void {
		$this->engine->schemas['custom-atomic'] = [ 'tag' => [ 'type' => 'string', 'kind' => 'plain', 'enum' => [ 'h1', 'h2', 'h3' ] ] ];
		$page                  = $this->page();
		$page[0]['elements'][] = [ 'id' => 'z1', 'elType' => 'widget', 'widgetType' => 'custom-atomic', 'settings' => [], 'elements' => [] ];
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $page );
		$this->assertFalse( IATO_MCP_Elementor_Atomic::has_atomic_type( $page[0]['elements'][6] ), 'the 1.13.1 read rule does not ask Elementor about it' );
		$r = $this->update( 'z1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'atomic', $r['schema'], 'writes ask Elementor about every element they edit (R2-J1)' );
		$this->assertSame( self::env( 'string', 'h3' ), $this->node( 'z1' )['settings']['tag'] );
	}

	public function test_the_unbound_key_is_refused_on_the_atomic_path(): void {
		self::auth( true, true );
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'iato_mcp_token_unbound', $r->get_error_code() );
		$this->assertSame( [], IATO_Test_WP::$writes );
	}

	public function test_an_editor_with_unsaved_changes_blocks_the_write(): void {
		IATO_Test_Writer_State::$locks[ self::POST ] = 7;
		$ok = $this->update( 'h1', [ 'tag' => 'h3' ], true );
		$this->assertTrue( $ok['dry_run'], 'a lock alone does not block' );
		IATO_Test_OAuth_State::$transients[ '_elementor_editor_unsaved_' . self::POST ] = [ 'value' => 7, 'expires' => 0 ];
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'elementor_editor_unsaved_changes', $r->get_error_code() );
		$this->assertSame( 409, $r->get_error_data()['status'] );
		$this->assertSame( [], IATO_Test_WP::$writes );
	}

	public function test_a_newer_autosave_is_reported_and_the_write_goes_ahead(): void {
		$GLOBALS['wpdb']->row = (object) [ 'ID' => 900, 'post_author' => 7, 'post_modified_gmt' => '2026-10-12 09:00:00' ];
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertTrue( $r['editor_autosave_pending'] );
		$this->assertSame( 'author', $r['editor_autosave']['user'] );
		$this->assertStringContainsString( 'listed under the page\'s revisions', $r['editor_autosave']['note'] );
		$this->assertTrue( $r['verified'] );
	}

	// ── Elementor's load migrations ────────────────────────────────────────

	public function test_migrations_run_first_and_a_dry_run_writes_nothing(): void {
		$migrated = $this->page();
		$migrated[0]['elements'][1]['settings']['paragraph'] = self::env( 'escaped-html', 'Migrated text' );
		$this->engine->migrated = json_encode( $migrated );

		$dry = $this->update( 'h1', [ 'tag' => 'h3' ], true );
		$this->assertSame( 'would_migrate', $dry['migrated_by_elementor'] );
		$this->assertSame( [ 'migrate', self::POST ], $this->engine->calls[0] );
		$this->assertSame( [], IATO_Test_WP::$writes );

		$refused = $this->update( 'h1', [ 'tag' => 'h7' ] );
		$this->assertSame( 'atomic_invalid_settings', $refused->get_error_code() );
		$this->assertSame( [], IATO_Test_WP::$writes, 'a refused call does not store the migration either' );

		$noop = $this->update( 'h1', [ 'tag' => 'h2' ] );
		$this->assertSame( 'would_migrate', $noop['migrated_by_elementor'] );
		$this->assertSame( [], IATO_Test_WP::$writes, 'nor does a no-op' );

		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertTrue( $r['migrated_by_elementor'] );
		$this->assertSame( self::env( 'escaped-html', 'Migrated text' ), $this->node( 'p1' )['settings']['paragraph'], 'the migration is stored with the change, in one write' );
		$this->assertCount( 1, array_filter( IATO_Test_WP::$writes, fn( $w ) => '_elementor_data' === $w[1] ) + array_filter( \Elementor\FakeDocument::$received, fn() => true ) );
		$this->assertTrue( $r['verified'] );
	}

	// ── The page check before the write ───────────────────────────────────

	public function test_an_untouched_element_elementor_would_reject_keeps_its_bytes(): void {
		$this->engine->save_fail['p1'] = 'Settings validation failed. paragraph: invalid_value';
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'skipped', $r['elementor_save'] );
		$this->assertSame( 'invalid_elements_on_page', $r['skipped_reason'] );
		$this->assertSame( 'plugin', $r['stored_by'] );
		$this->assertSame( 'p1', $r['invalid_elements'][0]['widget_id'] );
		$this->assertSame( [], \Elementor\FakeDocument::$received, "Elementor's save is not called" );
		$this->assertSame( $this->page()[0]['elements'][1], $this->node( 'p1' ) );
		$this->assertSame( self::env( 'string', 'h3' ), $this->node( 'h1' )['settings']['tag'] );
		$this->assertTrue( $r['verified'] );
	}

	public function test_an_untouched_element_elementor_would_alter_routes_to_the_plugins_write(): void {
		$this->engine->save_alter = [ 'b1' ];
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'elementor_would_alter_untouched', $r['skipped_reason'] );
		$this->assertSame( [ 'b1' ], $r['skipped_for_elements'] );
		$this->assertSame( 'plugin', $r['stored_by'] );
		$this->assertSame( $this->page()[0]['elements'][4], $this->node( 'b1' ) );
		$dry = $this->update( 'h1', [ 'tag' => 'h4' ], true );
		$this->assertSame( 'elementor_would_alter_untouched', $dry['save_check']['reason'], 'the dry run reports the same route' );
	}

	public function test_a_changed_element_elementors_save_rejects_is_refused_with_its_id(): void {
		$this->engine->save_fail['h1'] = 'Settings validation failed. link: invalid_value';
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
		$this->assertSame( 'h1', $r->get_error_data()['elements'][0]['element_id'] );
		$this->assertSame( [], IATO_Test_WP::$writes );
	}

	// ── What Elementor's save does ────────────────────────────────────────

	public function test_elementor_refusing_the_save_is_a_refusal(): void {
		\Elementor\FakeDocument::$mode = 'refuse';
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'elementor_cannot_edit', $r->get_error_code() );
		$this->assertSame( [], array_filter( IATO_Test_WP::$writes, fn( $w ) => '_elementor_data' === $w[1] ) );
	}

	public function test_a_validation_throw_at_save_is_a_refusal_and_another_throw_falls_back(): void {
		\Elementor\FakeDocument::$mode = 'throw';
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'elementor_validation_failed', $r->get_error_code() );
		$this->assertSame( [], array_filter( IATO_Test_WP::$writes, fn( $w ) => '_elementor_data' === $w[1] ) );

		\Elementor\FakeDocument::$throw_message = 'Call to a member function get_name() on null'; // an addon's save code
		$ok = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'error', $ok['elementor_save'] );
		$this->assertSame( 'plugin', $ok['stored_by'] );
		$this->assertTrue( $ok['css']['element_cache'] );
		$this->assertSame( self::env( 'string', 'h3' ), $this->node( 'h1' )['settings']['tag'] );
	}

	public function test_dry_runs_report_what_would_be_stored(): void {
		$r = $this->update( 'h1', [ 'tag' => 'h5' ], true );
		$this->assertTrue( $r['dry_run'] );
		$this->assertNull( $r['current_revision'] );
		$this->assertSame( [ [ 'key' => 'tag', 'from' => 'h2', 'to' => 'h5' ] ], $r['would_apply'] );
		$this->assertSame( [ 'tag' => self::env( 'string', 'h5' ) ], $r['would_store'] );
		$this->assertSame( 'would_run', $r['save_check']['elementor_save'] );
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertSame( [], \Elementor\FakeDocument::$received );
	}

	// ── Tools ──────────────────────────────────────────────────────────────

	private static function tool( string $name, array $args ): array|WP_Error {
		$files = [ 'set_heading_level' => 'tool-elementor-helpers.php', 'update_alt_text' => 'tool-media.php', 'update_elementor_widgets_bulk' => 'tool-elementor-bulk.php', 'update_elementor_patch' => 'tool-elementor-widgets.php' ];
		require_once IATO_MCP_DIR . 'includes/tools/wp/' . $files[ $name ];
		return ( IATO_MCP_Server::get_catalog_entry( $name )['handler'] )( $args );
	}

	private static function body( array $result ): array {
		return json_decode( $result['content'][0]['text'], true );
	}

	public function test_set_heading_level_sets_the_tag_of_an_atomic_heading(): void {
		$r = self::body( self::tool( 'set_heading_level', [ 'id' => self::POST, 'widget_id' => 'h1', 'level' => 'h4' ] ) );
		$this->assertSame( [ [ 'key' => 'tag', 'from' => 'h2', 'to' => 'h4' ] ], $r['applied'] );
		$this->assertSame( self::env( 'string', 'h4' ), $this->node( 'h1' )['settings']['tag'] );
		$other = self::tool( 'set_heading_level', [ 'id' => self::POST, 'widget_id' => 'p1', 'level' => 'h4' ] );
		$this->assertSame( 'invalid_widget_type', $other->get_error_code() );
	}

	public function test_update_alt_text_fixes_an_external_atomic_image(): void {
		$r = self::body( self::tool( 'update_alt_text', [ 'post_id' => self::POST, 'element_id' => 'i1', 'alt' => 'Shown alt' ] ) );
		$this->assertSame( 'atomic', $r['schema'] );
		$this->assertSame( self::env( 'string', 'Shown alt' ), $this->node( 'i1' )['settings']['image']['value']['src']['value']['alt'] );
		$lib = self::body( self::tool( 'update_alt_text', [ 'post_id' => self::POST, 'element_id' => 'i2', 'alt' => 'Lib' ] ) );
		$this->assertSame( 'attachment_alt', $lib['routed_to'] );
		$classic = self::tool( 'update_alt_text', [ 'post_id' => self::POST, 'element_id' => 'c1', 'alt' => 'x' ] );
		$this->assertSame( 'invalid_widget_type', $classic->get_error_code() );
	}

	public function test_bulk_applies_a_pages_atomic_rows_in_one_save_and_fails_rows_alone(): void {
		$rev = $this->revision();
		$r   = self::body( self::tool( 'update_elementor_widgets_bulk', [ 'updates' => [
			[ 'post_id' => self::POST, 'widget_id' => 'h1', 'settings_patch' => [ 'tag' => 'h3' ], 'if_revision' => $rev ],
			[ 'post_id' => self::POST, 'widget_id' => 'p1', 'settings_patch' => [ 'paragraph' => 'Bulk text' ], 'if_revision' => $rev ],
			[ 'post_id' => self::POST, 'widget_id' => 'h1', 'settings_patch' => [ 'title' => 'Bulk title' ] ],
			[ 'post_id' => self::POST, 'widget_id' => 'i1', 'settings_patch' => [ 'image' => [ 'src' => [ 'url' => 'nope' ] ] ] ],
		] ] ) );
		$this->assertSame( [ 3, 1 ], [ $r['succeeded'], $r['failed'] ] );
		$this->assertSame( [ 0, 1, 2, 3 ], array_column( $r['results'], 'index' ) );
		$this->assertSame( 'atomic_url_rejected', $r['results'][3]['error'] );
		$this->assertCount( 1, \Elementor\FakeDocument::$received, 'one save for the page' );
		$this->assertSame( $r['results'][0]['change_receipt']['change_id'], $r['results'][2]['change_receipt']['change_id'], 'one receipt per element' );
		$h1 = $this->node( 'h1' )['settings'];
		$this->assertSame( [ self::env( 'string', 'h3' ), self::env( 'escaped-html', 'Bulk title' ) ], [ $h1['tag'], $h1['title'] ] );
	}

	public function test_bulk_caps_atomic_rows_per_page(): void {
		$rows = [];
		for ( $i = 0; $i < 51; $i++ ) {
			$rows[] = [ 'post_id' => self::POST, 'widget_id' => 'h1', 'settings_patch' => [ 'title' => "T{$i}" ] ];
		}
		$r = self::body( self::tool( 'update_elementor_widgets_bulk', [ 'updates' => $rows ] ) );
		$this->assertSame( [ 50, 1 ], [ $r['succeeded'], $r['failed'] ] );
		$this->assertSame( 'batch_size_exceeded', $r['results'][50]['error'] );
	}

	public function test_bulk_rows_with_a_stale_revision_fail_alone(): void {
		$r = self::body( self::tool( 'update_elementor_widgets_bulk', [ 'updates' => [
			[ 'post_id' => self::POST, 'widget_id' => 'h1', 'settings_patch' => [ 'tag' => 'h3' ], 'if_revision' => 'sha256:stale' ],
			[ 'post_id' => self::POST, 'widget_id' => 'p1', 'settings_patch' => [ 'paragraph' => 'x' ] ],
		] ] ) );
		$this->assertSame( 'revision_conflict', $r['results'][0]['error'] );
		$this->assertTrue( $r['results'][1]['success'] );
	}

	public function test_patch_converts_plain_values_in_changed_atomic_elements(): void {
		$r = self::body( self::tool( 'update_elementor_patch', [ 'id' => self::POST, 'ops' => [
			[ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => 'h3' ],
			[ 'op' => 'replace', 'path' => '/0/elements/0/settings/link', 'value' => [ 'destination' => 'https://p.test/' ] ],
		] ] ) );
		$this->assertSame( [ 'h1' ], $r['converted_elements'] );
		$this->assertSame( 'atomic', $r['schema'] );
		$this->assertSame( 'elementor_widget', $r['change_receipt']['target_type'], 'a patch keeps its whole-document receipt' );
		$h1 = $this->node( 'h1' )['settings'];
		$this->assertSame( self::env( 'string', 'h3' ), $h1['tag'] );
		$this->assertSame( self::env( 'boolean', true ), $h1['link']['value']['isTargetBlank'], 'spliced into the stored link' );
		$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared, 'only atomic elements changed: per-post caches' );
		$bad = self::tool( 'update_elementor_patch', [ 'id' => self::POST, 'ops' => [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => 'h9' ] ] ] );
		$this->assertSame( 'atomic_invalid_settings', self::body( $bad )['error'] );
	}

	// ── Independent review fixes (1.14.0) ─────────────────────────────────

	public function test_elementors_kses_altering_an_untouched_setting_of_the_edited_element_routes_to_the_plugins_write(): void {
		// A user without unfiltered_html: Elementor's save would kses the whole
		// document, turning the & in the untouched link URL into &amp; (B1).
		IATO_MCP_Elementor_Atomic_Writer::set_kses( fn( string $v ) => preg_replace( '/&(?!amp;)/', '&amp;', $v ) );
		$page = $this->page();
		$page[0]['elements'][0]['settings']['link']['value']['destination'] = self::env( 'url', 'https://x.test/?a=1&b=2' );
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $page );
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'elementor_would_alter_untouched', $r['skipped_reason'] );
		$this->assertContains( 'h1', $r['skipped_for_elements'] );
		$this->assertSame( self::env( 'url', 'https://x.test/?a=1&b=2' ), $this->node( 'h1' )['settings']['link']['value']['destination'], 'the untouched URL keeps its bytes' );
		$this->assertSame( self::env( 'string', 'h3' ), $this->node( 'h1' )['settings']['tag'] );
	}

	public function test_update_alt_text_honours_dry_run_on_the_attachment_path(): void {
		$r = self::body( self::tool( 'update_alt_text', [ 'id' => 5, 'alt' => 'Preview only', 'dry_run' => true ] ) );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( 'Preview only', $r['alt'] );
		$this->assertSame( [], IATO_Test_WP::$writes );
		$this->assertSame( [], $GLOBALS['wpdb']->inserts, 'no receipt' );
	}

	public function test_a_typed_only_whole_tree_change_keeps_the_fallback_when_elementor_refuses(): void {
		\Elementor\FakeDocument::$mode = 'refuse'; // the unbound key: Elementor refuses user 0 (F1)
		$r = self::body( self::tool( 'update_elementor_patch', [ 'id' => self::POST, 'ops' => [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => self::env( 'string', 'h4' ) ] ] ] ) );
		$this->assertSame( 'plugin', $r['stored_by'], 'stored by the validated fallback, as in 1.13.1' );
		$this->assertSame( self::env( 'string', 'h4' ), $this->node( 'h1' )['settings']['tag'] );
	}

	public function test_a_throw_while_the_page_renders_is_reported_and_the_caches_are_still_cleared(): void {
		\Elementor\FakeDocument::$mode          = 'throw';
		\Elementor\FakeDocument::$throw_message = 'an addon failed in its save code';
		\Elementor\FakeFrontend::$throw         = true;
		$r = $this->update( 'h1', [ 'tag' => 'h3' ] );
		$this->assertSame( 'addon widget failed to render', $r['render_error'] );
		$this->assertTrue( $r['css']['element_cache'] );
		$this->assertSame( 'elementor_atomic', $r['change_receipt']['target_type'] );
		$this->assertSame( self::env( 'string', 'h3' ), $this->node( 'h1' )['settings']['tag'] );
	}

	public function test_a_routed_library_alt_is_sanitised_like_update_alt_text(): void {
		$this->update( 'i2', [ 'image' => [ 'src' => [ 'alt' => "Lib\nalt" ] ] ] );
		$alts = array_values( array_filter( IATO_Test_WP::$writes, fn( $w ) => '_wp_attachment_image_alt' === $w[1] ) );
		$this->assertSame( sanitize_text_field( "Lib\nalt" ), $alts[0][2] );
	}

	public function test_writes_to_an_atomic_type_elementor_has_not_registered_are_refused_typed_values_included(): void {
		unset( $this->engine->schemas['e-heading'] ); // the Atomic Editor off, or its plugin inactive
		foreach ( [ [ 'tag' => 'h3' ], [ 'tag' => self::env( 'string', 'h3' ) ] ] as $patch ) {
			$r = $this->update( 'h1', $patch );
			$this->assertSame( 'atomic_write_unavailable', $r->get_error_code() );
			$this->assertStringContainsString( 'can neither validate nor show this element', $r->get_error_message() );
		}
		$p = self::tool( 'update_elementor_patch', [ 'id' => self::POST, 'ops' => [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => self::env( 'string', 'h3' ) ] ] ] );
		$this->assertSame( 'atomic_write_unavailable', self::body( $p )['error'] );
		$this->assertSame( [], IATO_Test_WP::$writes );
	}

	public function test_an_edited_element_missing_from_the_tree_is_a_refusal(): void {
		$r = IATO_MCP_Elementor_Adapter::atomic_validation_error( self::POST, $this->page(), [], null, [ '/0/elements/99' ] );
		$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
		$this->assertStringContainsString( 'no element at /0/elements/99', $r->get_error_message() );
	}

	public function test_a_bulk_no_op_row_reports_the_page_revision_after_the_write(): void {
		$r = self::body( self::tool( 'update_elementor_widgets_bulk', [ 'updates' => [
			[ 'post_id' => self::POST, 'widget_id' => 'p1', 'settings_patch' => [ 'paragraph' => 'Changed' ] ],
			[ 'post_id' => self::POST, 'widget_id' => 'h1', 'settings_patch' => [ 'tag' => 'h2' ] ],
		] ] ) );
		$this->assertSame( [], $r['results'][1]['applied_patch'] );
		$this->assertSame( $r['results'][0]['current_revision'], $r['results'][1]['current_revision'] );
		$this->assertSame( $this->revision(), $r['results'][1]['current_revision'] );
	}

	public function test_an_unknown_key_refusal_where_the_writer_is_unavailable_does_not_talk_about_plain_values(): void {
		$this->engine->missing = 'Editing atomic elements with plain values needs Elementor 4.3.0 or later; this site runs Elementor 4.2.4.';
		$r = $this->update( 'h1', [ 'header_size' => self::env( 'string', 'h3' ) ] );
		$this->assertSame( 'atomic_plain_value_unsupported', $r->get_error_code() );
		$this->assertStringNotContainsString( 'later IATO release', $r->get_error_message() );
		$this->assertStringContainsString( 'is not a setting of e-heading', $r->get_error_message() );
	}

	// ── Rollback ───────────────────────────────────────────────────────────

	public function test_rollback_restores_the_recorded_values_verbatim(): void {
		$this->update( 'h1', [ 'tag' => 'h3', 'link' => [ 'destination' => 'https://new.test/' ] ] );
		$receipt = $this->last_receipt();
		$dry     = IATO_MCP_Rollback::execute( $receipt, [ 'dry_run' => true ] );
		$this->assertTrue( $dry['would_roll_back'] );
		$this->assertSame( [ 'element_id' => 'h1', 'keys' => [ 'tag' => 'h2', 'link' => [ 'destination' => 'https://old.test/', 'isTargetBlank' => true ] ] ], $dry['would_restore'] );

		$r = IATO_MCP_Rollback::execute( $receipt );
		$this->assertTrue( $r['success'] );
		$this->assertSame( 'match', $r['conflict_check']['status'] );
		$this->assertSame( $this->page()[0]['elements'][0]['settings'], $this->node( 'h1' )['settings'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->inserts, 'a rollback records no new receipt' );
	}

	public function test_rollback_removes_a_key_the_change_added(): void {
		$this->update( 'p1', [ 'link' => [ 'destination' => 'https://added.test/' ] ] );
		$this->assertArrayHasKey( 'link', $this->node( 'p1' )['settings'] );
		IATO_MCP_Rollback::execute( $this->last_receipt() );
		$this->assertArrayNotHasKey( 'link', $this->node( 'p1' )['settings'] );
	}

	public function test_rollback_after_a_later_edit_is_refused_unless_forced(): void {
		$this->update( 'h1', [ 'tag' => 'h3' ] );
		$receipt = $this->last_receipt();
		$this->update( 'h1', [ 'tag' => 'h5' ] ); // someone changed it since
		$r = IATO_MCP_Rollback::execute( $receipt );
		$this->assertSame( 'rollback_conflict', $r->get_error_code() );
		$this->assertSame( [ 'tag' => 'h5' ], $r->get_error_data()['conflict_check']['current_value'] );
		$forced = IATO_MCP_Rollback::execute( $receipt, [ 'force' => true ] );
		$this->assertTrue( $forced['conflict_check']['forced'] );
		$this->assertSame( self::env( 'string', 'h2' ), $this->node( 'h1' )['settings']['tag'] );
	}

	public function test_a_rollback_dry_run_refuses_what_the_rollback_would_refuse(): void {
		$this->update( 'h1', [ 'tag' => 'h3' ] );
		$receipt = $this->last_receipt();
		IATO_Test_Writer_State::$locks[ self::POST ] = 7;
		IATO_Test_OAuth_State::$transients[ '_elementor_editor_unsaved_' . self::POST ] = [ 'value' => 7, 'expires' => 0 ];
		$r = IATO_MCP_Rollback::execute( $receipt, [ 'dry_run' => true ] );
		$this->assertSame( 'elementor_editor_unsaved_changes', $r->get_error_code() );
	}

	public function test_rollback_of_an_element_that_is_gone_says_why(): void {
		$this->update( 'h1', [ 'tag' => 'h3' ] );
		$receipt = $this->last_receipt();
		$page    = $this->stored();
		array_shift( $page[0]['elements'] );
		IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $page );
		$r = IATO_MCP_Rollback::execute( $receipt );
		$this->assertSame( 'rollback_unavailable', $r->get_error_code() );
		$this->assertStringContainsString( 'no longer on the page', $r->get_error_message() );
	}

	public function test_rollback_caps_follow_the_post(): void {
		$receipt = [ 'target_type' => 'elementor_atomic', 'post_id' => self::POST, 'field' => 'elementor:h1:settings' ];
		$this->assertSame( 'edit_posts', IATO_MCP_Change_Receipt::cap_required_for( $receipt ) );
		$this->assertSame( [ 'cap' => 'edit_post', 'object_id' => self::POST, 'basis' => 'object', 'also' => [] ], IATO_MCP_Change_Receipt::object_cap_for( $receipt ) );
	}

	public function test_rest_rollback_compares_json_before_values_canonically(): void {
		$m = new ReflectionMethod( IATO_MCP_Rollback::class, 'values_match' );
		$this->assertTrue( $m->invoke( null, [ 'keys' => [ 'tag' => 'h2' ], 'element_id' => 'h1' ], '{"element_id":"h1","keys":{"tag":"h2"}}' ) );
		$this->assertFalse( $m->invoke( null, [ 'element_id' => 'h1', 'keys' => [ 'tag' => 'h3' ] ], '{"element_id":"h1","keys":{"tag":"h2"}}' ) );
		$this->assertTrue( $m->invoke( null, 'abc', 'abc' ), 'strings as before' );
	}
}

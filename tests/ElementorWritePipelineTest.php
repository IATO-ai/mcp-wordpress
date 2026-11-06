<?php
declare( strict_types=1 );

/**
 * What the Elementor write pipeline does when Elementor's Document::save()
 * throws or refuses during a classic-widget edit (Sprint C0 question).
 *
 * The stubs below model exactly the WordPress and Elementor calls
 * IATO_MCP_Elementor_Adapter::write_pipeline() makes.
 */

// ── Fake Elementor and the WordPress calls the pipeline makes ─────────────
namespace {
	require_once __DIR__ . '/stubs-elementor.php';

	use PHPUnit\Framework\TestCase;

	final class ElementorWritePipelineTest extends TestCase {
		private const POST = ElementorWritePipelineHelper::POST;

		#[\PHPUnit\Framework\Attributes\DataProvider( 'trees_elementor_cannot_render' )]
		public function test_a_tree_elementor_cannot_render_is_refused( string $json ): void {
			$tree = json_decode( $json, true );
			$this->assertInstanceOf( WP_Error::class, IATO_MCP_Elementor_Adapter::tree_shape_error( $tree ), $json );
			$before = IATO_Test_WP::$meta[ self::POST ]['_elementor_data'];
			$result = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $tree, 'sha256:x', [] );
			$this->assertSame( 'elementor_invalid_tree', $result->get_error_code() );
			$this->assertContains( 'elementor_invalid_tree', IATO_MCP_Elementor_Adapter::DETAILED_ERRORS, 'a client sees why' );
			$this->assertSame( $before, IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], 'nothing was written' );
			$this->assertSame( [], \Elementor\FakeDocument::$received, 'Elementor was not called' );
		}

		public static function trees_elementor_cannot_render(): array {
			$cases = [ '{"a":1}', '{"elements":[]}', '[1]', '[null]', '["x"]', '[{}]', '[[]]', '[{"id":"a","settings":[]}]', '[{"id":"a","elType":1}]',
				'[{"id":"a","elType":"widget","settings":[]}]', '[{"id":"a","elType":"container","settings":1}]', '[{"id":"a","elType":"container","settings":"x"}]',
				'[{"id":"a","elType":"widget","widgetType":"heading","settings":1}]', '[{"id":["x"],"elType":"container"}]', '[{"id":"a","elType":"container","settings":null}]', '[{"id":"a","elType":"widget","widgetType":"heading","settings":null}]',
				'[{"id":"a","elType":"container","elements":[1]}]', '[{"id":"a","elType":"container","elements":[{}]}]', '[{"id":"a","elType":"container","elements":{"k":{"elType":"widget"}}}]',
				'{"iato_no_elementor_data":true,"post_content":"x"}', '{"iato_no_elementor_data":{"elType":"container"},"post_content":{"elType":"container"}}' ];
			return array_combine( $cases, array_map( fn( $c ) => [ $c ], $cases ) );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'trees_that_stay_allowed' )]
		public function test_old_and_unusual_trees_stay_allowed( string $json ): void {
			$this->assertNull( IATO_MCP_Elementor_Adapter::tree_shape_error( json_decode( $json, true ) ), $json );
		}

		public static function trees_that_stay_allowed(): array {
			$cases = [ '[]', '[{"elType":"container"}]', '[{"id":7,"elType":"container","settings":[],"elements":[]}]', '[{"id":"a","elType":"bogus"}]',
				'[{"id":"a","elType":"widget","widgetType":"nosuch","settings":{"a":1}}]', '[{"id":"a","elType":"e-flexbox","settings":{"tag":{"$$type":"string","value":"div"}},"elements":[]}]',
				'{"0":{"id":"a","elType":"container"},"2":{"id":"b","elType":"container"}}', '[{"id":"a","elType":"container","elements":{"0":{"elType":"container"},"2":{"elType":"container"}}}]',
				'[{"id":"a","elType":"container","elements":{"k":{"elType":"container"}}}]', '[{"id":"a","elType":"container","elements":null}]', '[{"id":"a","elType":"container","elements":1}]' ];
			return array_combine( $cases, array_map( fn( $c ) => [ $c ], $cases ) );
		}

		public function test_every_fixture_page_passes_the_tree_check(): void {
			foreach ( glob( __DIR__ . '/fixtures/elementor/*.json' ) as $file ) {
				if ( ! str_contains( $file, 'expected' ) ) {
					$this->assertNull( IATO_MCP_Elementor_Adapter::tree_shape_error( json_decode( (string) file_get_contents( $file ), true ) ), basename( $file ) );
				}
			}
		}

		private function tree(): array {
			return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Edited title', 'header_size' => 'h2' ], 'elements' => [] ],
			] ] ];
		}

		protected function setUp(): void {
			IATO_Test_WP::$meta   = [ self::POST => [ '_elementor_data' => '[]', '_elementor_edit_mode' => 'builder' ] ];
			IATO_Test_WP::$posts  = [ self::POST => [ 'post_content' => '<h2>old content</h2>' ] ];
			IATO_Test_WP::$writes = [];
			\Elementor\FakeDocument::$mode          = 'ok';
			\Elementor\FakeDb::$mode                = 'ok';
			\Elementor\FakeDb::$text                = 'the text of the page';
			\Elementor\FakeDb::$rendered            = null;
			\Elementor\FakeDocument::$received      = [];
			\Elementor\FakeDocument::$throw_message = 'Settings validation failed. simulated Elementor failure';
			\Elementor\FakeDocuments::$absent       = false;
			\Elementor\FakeFrontend::$renders  = 0;
			// Marker sanitisers: the pass is proven by the markers, the real
			// wp_kses_post / esc_url_raw behaviour by the live suite.
			IATO_MCP_Elementor_Sanitizer::set_functions( [
				'kses' => fn( string $v ) => 'K[' . strip_tags( $v ) . ']',
				'url'  => fn( string $v ) => str_starts_with( strtolower( $v ), 'javascript:' ) ? '' : $v,
				'css'  => fn( string $v ) => 'C[' . strip_tags( $v ) . ']',
			] );
			self::allow_unfiltered( false );
			// 1.14.0: these tests cover the paths where IATO's atomic writer is
			// unavailable (the 1.13.1 guard); ElementorWriterAtomicTest covers the
			// writer with a fake of Elementor's resolver and parser.
			$engine          = FakeAtomicEngine::heading_site(); // the types are registered (Elementor 4.2.4)
			$engine->missing = 'Editing atomic elements with plain values needs Elementor 4.3.0 or later; this site runs Elementor 4.2.4.';
			IATO_MCP_Elementor_Atomic_Writer::set_engine( $engine );
			// What Elementor 4.3.4 registers on rest_api_init: the plain hook and
			// one _for_<post_type> variant per Elementor-enabled post type.
			IATO_Test_WP::reset_hooks();
			$kses = fn( $v ) => $v; // never invoked in these tests; identity is enough
			foreach ( self::ELEMENTOR_HOOKS as $hook ) {
				IATO_Test_WP::add_hook( $hook, $kses, 10, 1 );
			}
			IATO_Test_WP::add_hook( 'sanitize_post_meta_other_key', $kses, 10, 1 );
		}

		protected function tearDown(): void {
			IATO_MCP_Elementor_Sanitizer::set_functions( null );
			IATO_MCP_Elementor_Adapter::set_element_validator( null );
			IATO_MCP_Elementor_Adapter::set_type_checker( null );
			IATO_MCP_Elementor_Atomic::set_resolver( null );
			IATO_MCP_Elementor_Atomic_Writer::set_engine( null );
			self::authenticate( false );
			self::allow_unfiltered( false );
			unset( $GLOBALS['wp_filter'] );
		}

		private const ELEMENTOR_HOOKS = [ 'sanitize_post_meta__elementor_data', 'sanitize_post_meta__elementor_data_for_page', 'sanitize_post_meta__elementor_data_for_post', 'sanitize_post_meta__elementor_page_settings_for_page' ];

		private static function allow_unfiltered( bool $allow ): void {
			$ref = new ReflectionClass( IATO_MCP_Auth::class );
			$ref->getProperty( 'unfiltered_html_allowed' )->setValue( null, $allow );
		}

		private const PAYLOAD = '<b>Hi</b><script>alert(1)</script><span onclick="alert(1)">x</span>';

		/** The stored page: a human-authored HTML widget (iframe + script) next to the heading the request edits. */
		private function stored(): array {
			return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old title', 'header_size' => 'h2' ], 'elements' => [] ],
				[ 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<iframe src="https://maps.example.com/"></iframe><script>var human=1;</script>' ], 'elements' => [] ],
			] ] ];
		}

		/** The same page with the heading changed to the payload. */
		private function edited(): array {
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = self::PAYLOAD;
			return $t;
		}

		// ── 1.16.0: Elementor turned off for a page ─────────────────────

		private function turn_elementor_off( mixed $tree = null ): void {
			IATO_Test_WP::$meta[ self::POST ] = [ '_elementor_data' => $tree ?? json_encode( $this->stored() ) ];
			IATO_Test_WP::$posts[ self::POST ]['post_content'] = '<p>Written by hand</p>';
		}

		public function test_builder_state(): void {
			$state = fn( array $meta, string $type = 'page' ) => [ IATO_Test_WP::$meta[ self::POST ] = $meta, IATO_Test_WP::$posts[ self::POST ]['post_type'] = $type, IATO_MCP_Elementor_Adapter::builder_state( self::POST ) ][2];
			$tree  = '[{"id":"a"}]';
			$this->assertSame( 'active', $state( [ '_elementor_data' => $tree, '_elementor_edit_mode' => 'builder' ] ) );
			$this->assertSame( 'active', $state( [ '_elementor_data' => $tree, '_elementor_edit_mode' => '1' ] ), 'Elementor treats any truthy value as on' );
			$this->assertSame( 'no_tree', $state( [] ) );
			$this->assertSame( 'no_tree', $state( [ '_elementor_data' => '[]' ] ) );
			$this->assertSame( 'no_tree', $state( [ '_elementor_data' => [] ] ) );
			$this->assertSame( 'inactive_tree', $state( [ '_elementor_data' => $tree ] ) );
			$this->assertSame( 'inactive_tree', $state( [ '_elementor_data' => [ [ 'id' => 'a' ] ] ] ), 'stored as an array by an old write' );
			$this->assertSame( 'inactive_tree', $state( [ '_elementor_data' => '{broken' ] ), 'data that cannot be read might still be someone\'s' );
			$this->assertSame( 'inactive_tree', $state( [ '_elementor_data' => $tree, '_elementor_edit_mode' => '0' ] ) );
			foreach ( [ 'elementor_library', 'e-floating-buttons', 'revision' ] as $type ) {
				$this->assertSame( 'active', $state( [ '_elementor_data' => $tree ], $type ), $type );
			}
		}

		public function test_the_pipeline_refuses_a_page_with_elementor_off_and_writes_nothing(): void {
			$this->turn_elementor_off();
			$result = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'r' );
			$this->assertSame( 'elementor_not_active', $result->get_error_code() );
			$this->assertSame( [], \Elementor\FakeDocument::$received, 'Elementor\'s save never ran' );
			$this->assertSame( [], IATO_Test_WP::$writes );
			$this->assertSame( '<p>Written by hand</p>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_a_stored_empty_tree_is_refused_for_the_other_tools(): void {
			$this->turn_elementor_off( '[]' );
			$this->assertSame( 'elementor_not_active', IATO_MCP_Elementor_Adapter::inactive_tree_refusal( self::POST )->get_error_code() );
		}

		public function test_a_post_with_no_data_is_left_to_the_tools_own_answer(): void {
			IATO_Test_WP::$meta[ self::POST ] = [];
			$this->assertNull( IATO_MCP_Elementor_Adapter::inactive_tree_refusal( self::POST ) );
			$this->assertSame( 'not_elementor', IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'w1', 'settings_patch' => [] ] )->get_error_code() );
		}

		public function test_the_refusal_carries_its_details_as_tool_text(): void {
			$this->turn_elementor_off();
			$error = IATO_MCP_Elementor_Adapter::inactive_tree_refusal( self::POST );
			$this->assertStringContainsString( 'activate_builder: true', $error->get_error_message() );
			$this->assertStringContainsString( 'ask the user first', $error->get_error_message() );
			$tool = IATO_MCP_Elementor_Adapter::tool_error( $error );
			$this->assertTrue( $tool['isError'] );
			$text = json_decode( $tool['content'][0]['text'], true );
			$this->assertSame( 'elementor_not_active', $text['error'] );
			$this->assertSame( strlen( '<p>Written by hand</p>' ), $text['content_chars'] );
		}

		public function test_the_widget_tools_refuse_dry_runs_included(): void {
			$this->turn_elementor_off();
			foreach ( [ false, true ] as $dry_run ) {
				$result = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'w1', 'settings_patch' => [ 'title' => 'New' ], 'dry_run' => $dry_run ] );
				$this->assertSame( 'elementor_not_active', $result->get_error_code() );
			}
			$this->assertSame( [], IATO_Test_WP::$writes );
		}

		public function test_a_page_with_elementor_on_is_not_refused(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$result = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'w1', 'settings_patch' => [ 'title' => 'New' ], 'dry_run' => true ] );
			$this->assertFalse( is_wp_error( $result ) );
		}

		public function test_activating_sets_the_flag_after_the_tree_is_stored(): void {
			$this->turn_elementor_off();
			self::authenticate( true );
			$result = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'r', null, [ 'set_builder_mode' => true ] );
			$this->assertTrue( $result['builder_mode_set'] );
			$this->assertTrue( IATO_MCP_Elementor_Adapter::pipeline_report( $result )['builder_mode_set'] );
			$this->assertSame( 'builder', IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] );
			$this->assertSame( 'active', IATO_MCP_Elementor_Adapter::builder_state( self::POST ) );
		}

		public function test_a_refused_save_never_turns_elementor_on(): void {
			$this->turn_elementor_off();
			\Elementor\FakeDocument::$mode = 'refuse';
			$result = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'r', null, [ 'set_builder_mode' => true, 'atomic' => true ] );
			$this->assertSame( 'elementor_cannot_edit', $result->get_error_code() );
			$this->assertArrayNotHasKey( '_elementor_edit_mode', IATO_Test_WP::$meta[ self::POST ], 'visitors must not be switched to the old tree' );
		}

		/** Calls update_elementor_data; a refusal comes back as [isError, text]. */
		private function data_tool( array $args ): array {
			require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-builder.php';
			$r = ( IATO_MCP_Server::get_catalog_entry( 'update_elementor_data' )['handler'] )( $args + [ 'id' => self::POST, 'elementor_data' => json_encode( $this->tree() ) ] );
			if ( is_wp_error( $r ) ) {
				return [ 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ];
			}
			return json_decode( $r['content'][0]['text'], true );
		}

		public function test_update_elementor_data_refuses_a_page_with_elementor_off_unless_told(): void {
			self::authenticate( true );
			$this->turn_elementor_off();
			foreach ( [ [], [ 'dry_run' => true ] ] as $extra ) {
				$r = $this->data_tool( $extra );
				$this->assertSame( 'elementor_not_active', $r['error'] );
				$this->assertSame( strlen( '<p>Written by hand</p>' ), $r['content_chars'] );
			}
			$this->assertSame( [], \Elementor\FakeDocument::$received );
			$this->assertSame( '<p>Written by hand</p>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
			$this->assertArrayNotHasKey( '_elementor_edit_mode', IATO_Test_WP::$meta[ self::POST ] );
		}

		public function test_activate_builder_previews_then_switches_and_keeps_what_it_replaced(): void {
			self::authenticate( true );
			$old_tree = json_encode( $this->stored() );
			$this->turn_elementor_off( $old_tree );

			$dry = $this->data_tool( [ 'activate_builder' => true, 'dry_run' => true ] );
			$this->assertTrue( $dry['would_set_builder_mode'] );
			$this->assertSame( strlen( '<p>Written by hand</p>' ), $dry['would_replace_content_chars'] );
			$this->assertArrayNotHasKey( '_elementor_edit_mode', IATO_Test_WP::$meta[ self::POST ], 'a dry run writes nothing' );

			$GLOBALS['wpdb']->inserts = [];
			$r = $this->data_tool( [ 'activate_builder' => true ] );
			$this->assertTrue( $r['builder_mode_set'] );
			$this->assertSame( strlen( '<p>Written by hand</p>' ), $r['replaced_content_chars'] );
			$this->assertSame( 'builder', IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] );

			$envelope = IATO_MCP_Rollback::no_elementor_from( $GLOBALS['wpdb']->inserts[0][1]['before_value'] );
			$this->assertSame( '<p>Written by hand</p>', $envelope['post_content'] );
			$this->assertSame( $old_tree, $envelope['elementor_data'] );
			$this->assertSame( hash( 'sha256', '<h2>rendered by elementor</h2>' ), $envelope['content_after_sha256'] );
		}

		public function test_a_new_page_becomes_an_elementor_page(): void {
			self::authenticate( true );
			IATO_Test_WP::$meta[ self::POST ] = [];
			IATO_Test_WP::$posts[ self::POST ]['post_content'] = '<p>Draft</p>';

			$dry = $this->data_tool( [ 'dry_run' => true ] );
			$this->assertTrue( $dry['would_set_builder_mode'] );

			$GLOBALS['wpdb']->inserts = [];
			$r = $this->data_tool( [] );
			$this->assertTrue( $r['builder_mode_set'] );
			$this->assertSame( 'builder', IATO_Test_WP::$meta[ self::POST ]['_elementor_edit_mode'] );
			$envelope = IATO_MCP_Rollback::no_elementor_from( $GLOBALS['wpdb']->inserts[0][1]['before_value'] );
			$this->assertSame( '<p>Draft</p>', $envelope['post_content'] );
			$this->assertArrayNotHasKey( 'elementor_data', $envelope );
		}

		public function test_a_stored_empty_tree_gets_the_flag_and_the_full_undo_record(): void {
			self::authenticate( true );
			$this->turn_elementor_off( '[]' );
			$GLOBALS['wpdb']->inserts = [];
			$r = $this->data_tool( [] );
			$this->assertTrue( $r['builder_mode_set'] );
			$envelope = IATO_MCP_Rollback::no_elementor_from( $GLOBALS['wpdb']->inserts[0][1]['before_value'] );
			$this->assertSame( '<p>Written by hand</p>', $envelope['post_content'], 'the same loss as a full tree, so the same undo' );
			$this->assertSame( '[]', $envelope['elementor_data'] );
		}

		public function test_an_empty_tree_cannot_start_or_activate_a_page(): void {
			self::authenticate( true );
			IATO_Test_WP::$meta[ self::POST ] = [];
			$this->assertSame( 'elementor_empty_tree', $this->data_tool( [ 'elementor_data' => '[]' ] )['error'] );
			$this->turn_elementor_off();
			$this->assertSame( 'elementor_empty_tree', $this->data_tool( [ 'elementor_data' => '[]', 'activate_builder' => true ] )['error'] );
			$this->assertSame( '<p>Written by hand</p>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_an_elementor_page_can_still_be_emptied_and_keeps_its_plain_undo_record(): void {
			self::authenticate( true );
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$GLOBALS['wpdb']->inserts = [];
			$r = $this->data_tool( [ 'elementor_data' => '[]' ] );
			$this->assertArrayNotHasKey( 'error', $r );
			$this->assertArrayNotHasKey( 'builder_mode_set', $r );
			$this->assertSame( json_encode( $this->stored() ), $GLOBALS['wpdb']->inserts[0][1]['before_value'] );
		}

		public function test_the_refusal_says_when_the_content_is_only_elementor_text(): void {
			$this->turn_elementor_off();
			\Elementor\FakeDb::$text = "<h2>Written by hand</h2>\n";
			IATO_Test_WP::$posts[ self::POST ]['post_content'] = '<p>Written  by hand</p>';
			$error = IATO_MCP_Elementor_Adapter::inactive_tree_refusal( self::POST );
			$this->assertTrue( $error->get_error_data()['content_is_elementor_text'] );
			$this->assertStringContainsString( 'loses nothing', $error->get_error_message() );

			IATO_Test_WP::$posts[ self::POST ]['post_content'] = '';
			$this->assertTrue( IATO_MCP_Elementor_Adapter::inactive_tree_refusal( self::POST )->get_error_data()['content_is_elementor_text'], 'no content, nothing to lose' );

			\Elementor\FakeDb::$mode = 'throw';
			IATO_Test_WP::$posts[ self::POST ]['post_content'] = '<p>Written by hand</p>';
			$unsure = IATO_MCP_Elementor_Adapter::inactive_tree_refusal( self::POST );
			$this->assertNull( $unsure->get_error_data()['content_is_elementor_text'] );
			$this->assertStringContainsString( 'ask the user first', $unsure->get_error_message(), 'when it cannot tell, the standard warning' );
		}

		public function test_plain_text_restores_buffers_and_parsing_mode_when_a_widget_throws(): void {
			\Elementor\FakeDb::$mode = 'throw';
			$level = ob_get_level();
			$this->assertNull( IATO_MCP_Elementor_Adapter::plain_text_of( self::POST ) );
			$this->assertSame( $level, ob_get_level(), 'an open buffer would corrupt the JSON response' );
			$this->assertSame( 'all', \Elementor\Plugin::$instance->dynamic_tags->mode );
		}

		public function test_a_rollback_refresh_brings_post_content_into_line(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			IATO_Test_WP::$posts[ self::POST ]['post_content']   = 'text of the tree that was replaced';
			$this->assertSame( [ 'content_refreshed' => true ], IATO_MCP_Elementor_Adapter::refresh_post_content( self::POST ) );
			$this->assertSame( 'the text of the page', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::refresh_post_content( self::POST ), 'nothing to do the second time' );
		}

		public function test_a_dry_run_refresh_renders_the_tree_it_would_restore_and_writes_nothing(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			IATO_Test_WP::$posts[ self::POST ]['post_content']   = 'current';
			$tree = $this->tree();
			$this->assertSame( [ 'would_refresh_content' => true ], IATO_MCP_Elementor_Adapter::refresh_post_content( self::POST, $tree ) );
			$this->assertSame( $tree, \Elementor\FakeDb::$rendered );
			$this->assertSame( 'current', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_the_refresh_leaves_a_page_with_elementor_off_alone(): void {
			$this->turn_elementor_off();
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::refresh_post_content( self::POST ) );
			$this->assertSame( '<p>Written by hand</p>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_the_refresh_leaves_a_page_with_unloadable_elements_alone(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			IATO_Test_WP::$posts[ self::POST ]['post_content']   = 'current';
			IATO_MCP_Elementor_Adapter::set_type_checker( fn( string $el, string $widget ): bool => false );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::refresh_post_content( self::POST ), 'their text would go missing' );
			$this->assertSame( 'current', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_a_refresh_that_fails_is_only_a_note(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			IATO_Test_WP::$posts[ self::POST ]['post_content']   = 'current';
			\Elementor\FakeDb::$mode = 'throw';
			$result = IATO_MCP_Elementor_Adapter::refresh_post_content( self::POST );
			$this->assertStringContainsString( 'could not be brought up to date', $result['content_note'] );
			$this->assertSame( 'current', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_a_normal_write_reports_no_mode_change(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$result = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'r' );
			$this->assertArrayNotHasKey( 'builder_mode_set', $result );
			$this->assertArrayNotHasKey( 'builder_mode_set', IATO_MCP_Elementor_Adapter::pipeline_report( $result ) );
		}

		public function test_a_classic_write_never_clears_every_pages_css(): void {
			\Elementor\FakeFilesManager::$cleared = 0;
			IATO_MCP_Cache::begin();
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( 'elementor', $r['stored_by'] );
			$this->assertSame( 0, \Elementor\FakeFilesManager::$cleared );
			$this->assertTrue( IATO_MCP_Cache::has_pending(), 'this post is queued for its CSS and a purge' );
			IATO_MCP_Cache::begin();
		}

		public function test_the_report_reaches_the_result_and_the_tool_response(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( 'w1/settings/title', $r['sanitized_report'][0]['path'] );
			$this->assertSame( [ 'b', 'script', 'span' ], $r['sanitized_report'][0]['removed_tags'] );
			$this->assertSame( $r['sanitized_report'], IATO_MCP_Elementor_Adapter::pipeline_report( $r )['sanitized_report'] );
		}

		public function test_nothing_sanitised_means_no_report_key(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->stored(), 'sha256:prev', $this->stored() );
			$this->assertArrayNotHasKey( 'sanitized_report', $r );
			$this->assertArrayNotHasKey( 'sanitized_report', IATO_MCP_Elementor_Adapter::pipeline_report( $r ) );
		}

		public function test_what_elementors_own_save_strips_is_reported_for_a_user_without_the_capability(): void {
			\Elementor\FakeDocument::$kses = fn( string $v ) => strip_tags( $v, '<b>' );
			$saved = IATO_Test_Abilities_State::$caps;
			try {
				IATO_Test_Abilities_State::$caps['unfiltered_html'] = false;
				IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
				$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->stored(), 'sha256:prev', $this->stored() );
				$this->assertTrue( $r['sanitized'] );
				$this->assertSame( [ 'w2/settings/html' ], $r['sanitized_paths'], 'the widget this write never touched' );
				$this->assertSame( 'elementor', $r['sanitized_report'][0]['source'] );
				$this->assertSame( [ 'iframe', 'script' ], $r['sanitized_report'][0]['removed_tags'] );

				IATO_Test_Abilities_State::$caps['unfiltered_html'] = true;
				IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
				\Elementor\FakeDocument::$kses = null;
				$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->stored(), 'sha256:prev', $this->stored() );
				$this->assertArrayNotHasKey( 'sanitized_report', $r, 'nothing to compare for a user who holds it' );
			} finally {
				\Elementor\FakeDocument::$kses = null;
				IATO_Test_Abilities_State::$caps = $saved;
			}
		}

		private function meta_writes_of( string $key ): array {
			return array_values( array_filter( IATO_Test_WP::$writes, fn( $w ) => $w[1] === $key ) );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'fallback_modes' )]
		public function test_fallback_sanitises_only_what_changed( string $mode ): void {
			if ( 'absent' === $mode ) {
				\Elementor\FakeDocuments::$absent = true;
			} else {
				\Elementor\FakeDocument::$mode = $mode;
			}
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );

			$this->assertIsArray( $r );
			$this->assertSame( 'plugin', $r['stored_by'] );
			$this->assertSame( 'absent' === $mode ? null : ( 'throw' === $mode ? 'error' : false ), $r['elementor_save'] );
			$this->assertTrue( $r['sanitized'] );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$stored = json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true );
			$this->assertSame( 'K[Hi' . 'alert(1)' . 'x]', $stored[0]['elements'][0]['settings']['title'], 'the changed value went through the kses marker' );
			$this->assertStringContainsString( '<iframe', $stored[0]['elements'][1]['settings']['html'], 'the untouched HTML widget survives verbatim' );
			$this->assertStringContainsString( '<script>var human=1;</script>', $stored[0]['elements'][1]['settings']['html'] );
			foreach ( $this->meta_writes_of( '_elementor_data' ) as $w ) {
				$this->assertStringNotContainsString( '<script>alert(1)', (string) $w[2], 'the raw payload was never written, not even transiently' );
			}
			$this->assertTrue( $r['meta_persisted'] );
		}

		public static function fallback_modes(): array {
			return [ [ 'throw' ], [ 'refuse' ], [ 'absent' ] ];
		}

		public function test_success_path_hands_elementor_the_sanitised_tree_and_writes_nothing_itself(): void {
			\Elementor\FakeDocument::$mode = 'ok';
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( 'elementor', $r['stored_by'] );
			$this->assertTrue( $r['elementor_save'] );
			$this->assertTrue( $r['sanitized'] );
			$this->assertSame( 'K[Hialert(1)x]', \Elementor\FakeDocument::$received[0]['elements'][0]['elements'][0]['settings']['title'], 'Elementor received the sanitised value' );
			$this->assertSame( [], $this->meta_writes_of( '_elementor_data' ), 'no plugin write of _elementor_data when Elementor stored it' );
		}

		public function test_nothing_reported_when_nothing_was_altered(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$t = $this->stored();
			$t[0]['elements'][0]['settings']['title'] = 'Tom & Jerry';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $t, 'sha256:prev', $this->stored() );
			$this->assertArrayNotHasKey( 'sanitized', $r );
			$this->assertArrayNotHasKey( 'sanitized_paths', $r );
			$this->assertSame( 'Tom & Jerry', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['title'] );
		}

		public function test_stored_tree_is_read_from_meta_when_the_caller_passes_none(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->stored() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev' );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$this->assertStringContainsString( '<iframe', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][1]['settings']['html'] );
		}

		public function test_unfiltered_html_allowed_keeps_the_raw_payload(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			self::allow_unfiltered( true );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertArrayNotHasKey( 'sanitized', $r );
			$this->assertSame( self::PAYLOAD, json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['title'] );
		}

		public function test_dry_run_preview_reports_the_same_paths(): void {
			$preview = IATO_MCP_Elementor_Adapter::sanitize_preview( $this->edited(), $this->stored() );
			$this->assertTrue( $preview['sanitized'] );
			$this->assertSame( [ 'w1/settings/title' ], $preview['sanitized_paths'] );
			$this->assertSame( 'w1/settings/title', $preview['sanitized_report'][0]['path'] );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::sanitize_preview( $this->stored(), $this->stored() ) );
			self::allow_unfiltered( true );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::sanitize_preview( $this->edited(), $this->stored() ) );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'lift_modes' )]
		public function test_kses_lock_is_lifted_only_during_elementors_save( string $mode ): void {
			\Elementor\FakeDocument::$mode            = $mode;
			\Elementor\FakeDocument::$cap_during_save = [];
			// Lock as authenticate() does for a bound key / OAuth token.
			$ref = new ReflectionClass( IATO_MCP_Auth::class );
			$ref->getProperty( 'kses_locked' )->setValue( null, true );
			add_filter( 'map_meta_cap', [ IATO_MCP_Auth::class, 'filter_map_meta_cap' ], 10, 4 );
			$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'locked before' );

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );

			$this->assertSame( [ [ 'unfiltered_html' ] ], \Elementor\FakeDocument::$cap_during_save, 'inside the save the user\'s real capability shows through' );
			$this->assertSame( [ 'do_not_allow' ], apply_filters( 'map_meta_cap', [ 'unfiltered_html' ], 'unfiltered_html', 1, [] ), 'locked again after the save, also when it threw' );
			$this->assertSame( 'throw' === $mode ? 'error' : true, $r['elementor_save'] );
			$this->assertSame( 'K[Hialert(1)x]', \Elementor\FakeDocument::$received[0]['elements'][0]['elements'][0]['settings']['title'], 'Elementor still receives the sanitised tree' );
			$ref->getProperty( 'kses_locked' )->setValue( null, false );
		}

		public static function lift_modes(): array {
			return [ [ 'ok' ], [ 'throw' ] ];
		}

		// ── Elementor's REST meta sanitize callbacks are kept out of the plugin's own writes (from 1.12.2) ──

		public function test_elementor_meta_callbacks_are_removed_during_the_fallback_write_and_restored_after(): void {
			\Elementor\FakeDocument::$mode = 'refuse'; // user 0: Elementor refuses, the plugin writes
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks() );
			IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$data_writes = array_values( array_filter( IATO_Test_WP::$hooks_at_write, fn( $w ) => '_elementor_data' === $w[0] ) );
			$this->assertNotEmpty( $data_writes );
			$this->assertSame( 0, $data_writes[0][1], 'no elementor meta hook had callbacks while _elementor_data was written' );
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'every callback is back afterwards' );
			$this->assertSame( 1, IATO_Test_WP::hook_count( 'sanitize_post_meta_other_key' ), 'unrelated hooks untouched' );
		}

		public function test_callbacks_are_restored_when_the_write_throws_and_nested_calls_are_safe(): void {
			try {
				IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( function () {
					$this->assertSame( [], IATO_Test_WP::live_elementor_hooks(), 'removed inside' );
					IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( function () {
						$this->assertSame( [], IATO_Test_WP::live_elementor_hooks(), 'still removed in a nested call' );
					} );
					$this->assertSame( [], IATO_Test_WP::live_elementor_hooks(), 'the inner call did not restore early' );
					throw new RuntimeException( 'boom' );
				} );
				$this->fail( 'exception should propagate' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'boom', $e->getMessage() );
			}
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'restored after the throw' );
			$this->assertSame( 'ok', IATO_MCP_Elementor_Adapter::without_elementor_meta_sanitizing( fn() => 'ok' ) );
		}

		public function test_write_elementor_meta_lifts_the_lock_and_removes_the_callbacks_together(): void {
			$ref = new ReflectionClass( IATO_MCP_Auth::class );
			$ref->getProperty( 'kses_locked' )->setValue( null, true );
			$seen = IATO_MCP_Elementor_Adapter::write_elementor_meta( function () use ( $ref ) {
				return [ $ref->getProperty( 'kses_locked' )->getValue(), IATO_Test_WP::live_elementor_hooks() ];
			} );
			$this->assertSame( [ false, [] ], $seen, 'inside: lock lifted, callbacks gone' );
			$this->assertTrue( $ref->getProperty( 'kses_locked' )->getValue(), 'lock back' );
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'callbacks back' );
			$ref->getProperty( 'kses_locked' )->setValue( null, false );
		}

		public function test_a_throwing_elementor_save_leaves_the_callbacks_in_place_for_the_fallback_write(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( 'error', $r['elementor_save'] );
			$this->assertSame( 'plugin', $r['stored_by'] );
			$data_writes = array_values( array_filter( IATO_Test_WP::$hooks_at_write, fn( $w ) => '_elementor_data' === $w[0] ) );
			$this->assertSame( 0, $data_writes[0][1], 'the fallback write ran without the callbacks' );
			$this->assertSame( self::ELEMENTOR_HOOKS, IATO_Test_WP::live_elementor_hooks(), 'and they are back afterwards' );
		}

		public function test_without_elementor_callbacks_registered_changed_leaves_are_still_sanitised(): void {
			IATO_Test_WP::reset_hooks(); // an Elementor version that registers no meta sanitize callback
			\Elementor\FakeDocument::$mode = 'refuse';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->edited(), 'sha256:prev', $this->stored() );
			$this->assertSame( [ 'w1/settings/title' ], $r['sanitized_paths'] );
			$this->assertSame( 'K[Hialert(1)x]', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['title'] );
		}

		public function test_decode_data_accepts_a_tree_stored_as_an_array(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = $this->stored(); // a pre-1.13.0 update_post_meta write
			$r = IATO_MCP_Elementor_Adapter::decode_data( self::POST );
			$this->assertIsArray( $r );
			$this->assertSame( 'w1', $r[0][0]['elements'][0]['id'] );
		}

		public function test_elementor_save_throws_the_tool_still_completes_and_reports_it(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );

			$this->assertIsArray( $r, 'no WP_Error, no uncaught exception' );
			$this->assertSame( 'error', $r['elementor_save'] );
			$this->assertStringContainsString( 'simulated Elementor failure', $r['elementor_save_error'] );
			// The plugin's own direct write happened first and is what is stored.
			$this->assertSame( json_encode( $this->tree() ), IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] );
			$this->assertTrue( $r['meta_persisted'] );
			// post_content came from the frontend fallback, not from Elementor's save.
			$this->assertSame( 1, \Elementor\FakeFrontend::$renders );
			$this->assertSame( '<h2>frontend fallback render</h2>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
			$this->assertTrue( $r['content_updated'] );
			$this->assertSame( 'sha256:' . hash( 'sha256', json_encode( $this->tree() ) ), $r['current_revision'] );
		}

		public function test_elementor_save_refuses_same_fallback_reported_as_false(): void {
			\Elementor\FakeDocument::$mode = 'refuse';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );
			$this->assertFalse( $r['elementor_save'] );
			$this->assertArrayNotHasKey( 'elementor_save_error', $r );
			$this->assertTrue( $r['meta_persisted'] );
			$this->assertSame( 1, \Elementor\FakeFrontend::$renders );
			$this->assertSame( '<h2>frontend fallback render</h2>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
		}

		public function test_elementor_save_succeeds_reencoded_meta_still_counts_as_persisted(): void {
			\Elementor\FakeDocument::$mode = 'ok';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );
			$this->assertTrue( $r['elementor_save'] );
			$this->assertSame( 0, \Elementor\FakeFrontend::$renders, 'no fallback when Elementor rendered' );
			$this->assertSame( '<h2>rendered by elementor</h2>', IATO_Test_WP::$posts[ self::POST ]['post_content'] );
			$stored = IATO_Test_WP::$meta[ self::POST ]['_elementor_data'];
			$this->assertNotSame( json_encode( $this->tree() ), $stored, 'Elementor re-encoded the tree' );
			$this->assertTrue( $r['meta_persisted'], 'structural check: same ids, same nesting' );
			$this->assertNotSame( strlen( json_encode( $this->tree() ) ), strlen( $stored ), 'the old strlen check would have reported false here' );
			$this->assertSame( 'sha256:' . hash( 'sha256', $stored ), $r['current_revision'], 'revision is of what is actually stored' );
		}

		public function test_structural_check_detects_a_lost_write(): void {
			$this->assertFalse( IATO_MCP_Elementor_Adapter::same_element_tree( '[]', $this->tree() ) );
			$this->assertFalse( IATO_MCP_Elementor_Adapter::same_element_tree( 'not json', $this->tree() ) );
			$this->assertTrue( IATO_MCP_Elementor_Adapter::same_element_tree( json_encode( ElementorWritePipelineHelper::reencode( $this->tree() ) ), $this->tree() ) );
		}

		// ── 1.13.1: atomic (Elementor V4) guard and no-fallback rule ─────────

		private static function env( string $type, mixed $value ): array {
			return [ '$$type' => $type, 'value' => $value ];
		}

		/** An atomic page: a valid heading (a1) and, optionally, one IATO 1.13.0 damaged (a2: plain tag). */
		private function atomic_page( bool $with_damage = false ): array {
			$children = [ [ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'tag' => self::env( 'string', 'h1' ), 'title' => self::env( 'escaped-html', 'Atomic' ) ], 'elements' => [] ] ];
			if ( $with_damage ) {
				$children[] = [ 'id' => 'a2', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'tag' => 'h3', 'title' => self::env( 'escaped-html', 'Damaged' ) ], 'elements' => [] ];
			}
			return [ [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => $children ] ];
		}

		private function with_a1_tag( array $tree, mixed $tag ): array {
			$tree[0]['elements'][0]['settings']['tag'] = $tag;
			return $tree;
		}

		/** Elementor's registry as on a 4.3 site with the Atomic Editor on (the reader's resolver seam). */
		private static function atomic_site(): void {
			$schemas = [
				'e-heading'   => [ 'tag' => [ 'type' => 'string', 'kind' => 'plain' ], 'title' => [ 'type' => 'escaped-html', 'kind' => 'plain' ] ],
				'e-paragraph' => [ 'paragraph' => [ 'type' => 'escaped-html', 'kind' => 'plain' ] ],
				'e-flexbox'   => [ 'tag' => [ 'type' => 'string', 'kind' => 'plain' ] ],
				'e-iframe'    => null, // e-addons: a classic widget with an e- name
				'heading'     => null,
			];
			IATO_MCP_Elementor_Atomic::set_resolver( function ( string $op, ...$args ) use ( $schemas ) {
				if ( 'type_info' !== $op ) {
					return null;
				}
				$type = 'widget' === $args[0] ? $args[1] : $args[0];
				return array_key_exists( $type, $schemas ) ? [ 'registered' => true, 'schema' => $schemas[ $type ] ] : [ 'registered' => false, 'schema' => null ];
			} );
		}

		private static function authenticate( bool $on ): void {
			$ref = new ReflectionClass( IATO_MCP_Auth::class );
			$ref->getProperty( 'authenticated' )->setValue( null, $on );
			$ref->getProperty( 'site_key_full_access' )->setValue( null, $on );
		}

		public function test_atomic_element_rejected_by_elementor_is_not_written(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => 'a1' === $n['id'] ? 'Settings validation failed. tag: invalid_value' : null );

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $this->atomic_page(), self::env( 'string', 'h7' ) ), 'sha256:prev', $this->atomic_page(), [ 'atomic_ids' => [ 'a1' ] ] );

			$this->assertInstanceOf( WP_Error::class, $r );
			$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
			$this->assertSame( 'a1', $r->get_error_data()['elements'][0]['element_id'] );
			$this->assertStringContainsString( 'Nothing was written', $r->get_error_message() );
			$this->assertSame( [], $this->meta_writes_of( '_elementor_data' ), 'no fallback write' );
			$this->assertSame( json_encode( $this->atomic_page() ), IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] );
		}

		public function test_atomic_element_any_other_throwable_keeps_the_fallback_for_a_valid_change(): void {
			\Elementor\FakeDocument::$mode          = 'throw';
			\Elementor\FakeDocument::$throw_message = 'Call to a member function get_name() on null (an addon control)';
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => null );

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $this->atomic_page(), self::env( 'string', 'h3' ) ), 'sha256:prev', $this->atomic_page(), [ 'atomic_ids' => [ 'a1' ] ] );

			$this->assertIsArray( $r );
			$this->assertSame( 'plugin', $r['stored_by'] );
			$this->assertSame( 'error', $r['elementor_save'] );
			$this->assertSame( 'h3', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['tag']['value'] );
		}

		public function test_any_other_throwable_with_an_invalid_change_is_refused(): void {
			// An addon control throws before Elementor reaches the atomic element:
			// the changed element is still checked before the plugin stores the tree.
			\Elementor\FakeDocument::$mode          = 'throw';
			\Elementor\FakeDocument::$throw_message = 'Call to a member function get_name() on null (an addon control)';
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => 'a1' === $n['id'] ? 'Settings validation failed. tag: invalid_value' : null );
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $this->atomic_page(), self::env( 'string', 'h9' ) ), 'sha256:prev', $this->atomic_page(), [ 'atomic_ids' => [ 'a1' ] ] );
			$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
			$this->assertSame( 'Call to a member function get_name() on null (an addon control)', $r->get_error_data()['elementor_error'] );
			$this->assertSame( [], $this->meta_writes_of( '_elementor_data' ) );
		}

		public function test_every_copy_of_a_changed_id_is_validated(): void {
			// RFC 6902 copy keeps the id: the original a1 is valid, its copy is not.
			\Elementor\FakeDocument::$mode = 'throw';
			$seen = [];
			IATO_MCP_Elementor_Adapter::set_element_validator( function ( array $n ) use ( &$seen ) {
				$seen[] = $n['settings']['tag']['value'] ?? null;
				return 'h9' === ( $n['settings']['tag']['value'] ?? null ) ? 'Settings validation failed. tag: invalid_value' : null;
			} );
			$tree   = $this->atomic_page();
			$copy   = $tree[0]['elements'][0];
			$copy['settings']['tag'] = self::env( 'string', 'h9' );
			$tree[0]['elements'][] = $copy;
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $tree, 'sha256:prev', $this->atomic_page(), [ 'atomic_ids' => [ 'a1' ] ] );
			$this->assertSame( [ 'h1', 'h9' ], $seen, 'both nodes with the id were checked' );
			$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
		}

		public function test_skipped_save_with_an_invalid_change_is_refused(): void {
			// A deactivated addon widget elsewhere on the page skips Elementor's save;
			// the changed atomic element is still checked.
			self::registry_without( 'gone-addon-widget' );
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => 'a1' === $n['id'] && 'h9' === ( $n['settings']['tag']['value'] ?? null ) ? 'Settings validation failed. tag: invalid_value' : null );
			$stored   = $this->atomic_page();
			$stored[] = $this->addon_page()[0];
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $stored );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $stored, self::env( 'string', 'h9' ) ), 'sha256:prev', $stored, [ 'atomic_ids' => [ 'a1' ] ] );
			$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
			$this->assertArrayNotHasKey( 'elementor_error', $r->get_error_data(), 'Elementor\'s save never ran' );
			$this->assertSame( [], $this->meta_writes_of( '_elementor_data' ) );
			$ok = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $stored, self::env( 'string', 'h3' ) ), 'sha256:prev', $stored, [ 'atomic_ids' => [ 'a1' ] ] );
			$this->assertSame( 'skipped', $ok['elementor_save'], 'a valid change is stored' );
		}

		public function test_refused_save_for_user_0_is_checked_too(): void {
			// The unbound key runs as user 0, whom Elementor does not let save: the
			// changed element is still checked before the plugin stores the tree.
			\Elementor\FakeDocument::$mode = 'refuse';
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => 'h9' === ( $n['settings']['tag']['value'] ?? null ) ? 'Settings validation failed. tag: invalid_value' : null );
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $this->atomic_page(), self::env( 'string', 'h9' ) ), 'sha256:prev', $this->atomic_page(), [ 'atomic_ids' => [ 'a1' ] ] );
			$this->assertSame( 'atomic_invalid_settings', $r->get_error_code() );
			$this->assertSame( [], $this->meta_writes_of( '_elementor_data' ) );
			$ok = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $this->atomic_page(), self::env( 'string', 'h3' ) ), 'sha256:prev', $this->atomic_page(), [ 'atomic_ids' => [ 'a1' ] ] );
			$this->assertSame( 'plugin', $ok['stored_by'], 'a valid change is stored as before' );
			$this->assertFalse( $ok['elementor_save'] );
		}

		public function test_dry_runs_report_the_refusal_the_write_would_give(): void {
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => 'h9' === ( $n['settings']['tag']['value'] ?? null ) ? 'Settings validation failed. tag: invalid_value' : null );
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'a1', 'settings_patch' => [ 'tag' => self::env( 'string', 'h9' ) ], 'dry_run' => true ] );
			$this->assertSame( 'atomic_invalid_settings', $r->get_error_code(), 'update_elementor_widget / set_widget_setting / bulk dry run' );
			$ok = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'a1', 'settings_patch' => [ 'tag' => self::env( 'string', 'h3' ) ], 'dry_run' => true ] );
			$this->assertTrue( $ok['dry_run'] );
			$patch = $this->patch_tool( [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag/value', 'value' => 'h9' ] ] );
			$this->assertSame( 'atomic_invalid_settings', json_decode( $patch['content'][0]['text'], true )['error'], 'update_elementor_patch dry run' );
			require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-builder.php';
			$data = ( IATO_MCP_Server::get_catalog_entry( 'update_elementor_data' )['handler'] )( [ 'id' => self::POST, 'elementor_data' => json_encode( $this->with_a1_tag( $this->atomic_page(), self::env( 'string', 'h9' ) ) ), 'dry_run' => true ] );
			$this->assertSame( 'atomic_invalid_settings', json_decode( $data['content'][0]['text'], true )['error'], 'update_elementor_data dry run' );
			$this->assertSame( [], IATO_Test_WP::$writes );
		}

		public function test_dry_run_validates_what_the_write_would_send(): void {
			// The write sanitises changed leaves before Elementor validates them
			// (step 0); the dry run must validate the same tree. The marker url
			// sanitiser empties javascript: URLs.
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => str_starts_with( (string) ( $n['settings']['link']['value']['destination']['value'] ?? '' ), 'javascript:' ) ? 'Settings validation failed. link: invalid_value' : null );
			$tree = $this->atomic_page();
			$tree[0]['elements'][0]['settings']['link'] = self::env( 'link', [ 'destination' => self::env( 'url', 'javascript:alert(1)' ) ] );
			$this->assertSame( 'atomic_invalid_settings', IATO_MCP_Elementor_Adapter::atomic_validation_error( self::POST, $tree, [ 'a1' ] )->get_error_code(), 'unsanitised: rejected' );
			$this->assertNull( IATO_MCP_Elementor_Adapter::dry_run_validation_error( self::POST, $tree, $this->atomic_page(), [ 'a1' ] ), 'what the write would send passes, as on the write' );
		}

		public function test_single_element_tools_check_only_the_edited_position(): void {
			// A page that already holds two elements with the id a1, the second
			// damaged: editing a1 (the first match) checks that node only.
			\Elementor\FakeDocument::$mode = 'throw'; // Elementor rejects the damaged twin
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => is_string( $n['settings']['tag'] ?? null ) ? 'Settings validation failed. tag: invalid_value' : null );
			$tree   = $this->atomic_page();
			$twin   = $tree[0]['elements'][0];
			$twin['settings']['tag'] = 'h3';
			$tree[0]['elements'][] = $twin;
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $tree );
			$dry = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'a1', 'settings_patch' => [ 'tag' => self::env( 'string', 'h2' ) ], 'dry_run' => true ] );
			$this->assertIsArray( $dry, 'the dry run is not blocked by the twin' );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $tree, self::env( 'string', 'h2' ) ), 'sha256:prev', $tree, [ 'atomic_paths' => [ '/0/elements/0' ] ] );
			$this->assertSame( 'plugin', $r['stored_by'], 'the write is not blocked by the twin' );
			$this->assertSame( 'h2', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['tag']['value'] );
			$this->assertSame( 'atomic_invalid_settings', IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $tree, 'sha256:prev', $tree, [ 'atomic_ids' => [ 'a1' ] ] )->get_error_code(), 'whole-tree tools still check every copy' );
		}

		public function test_an_eaddons_style_widget_is_written_as_before(): void {
			self::atomic_site();
			$tree = [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'ei1', 'elType' => 'widget', 'widgetType' => 'e-iframe', 'settings' => [ 'url' => 'https://maps.example.test/' ], 'elements' => [] ],
			] ] ];
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $tree );
			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'ei1', 'settings_patch' => [ 'url' => 'https://other.example.test/' ], 'dry_run' => true ] );
			$this->assertIsArray( $r, 'registered, classic class: plain edits are not refused' );
			$this->assertSame( 'replace', $r['applied_patch'][0]['op'] );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::invalid_elements( $tree ) );

			// The same widget with its addon deactivated: not atomic, kept by the unregistered-type path.
			IATO_MCP_Elementor_Atomic::set_resolver( null );
			self::registry_without( 'e-iframe' );
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::invalid_elements( $tree ) );
			$page   = $this->addon_page();
			$page[] = $tree[0];
			$w = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_title( $page, 'Edited' ), 'sha256:prev', $page );
			$this->assertSame( 'skipped', $w['elementor_save'] );
			$this->assertContains( [ 'type' => 'e-iframe', 'element_ids' => [ 'ei1' ] ], $w['unregistered_element_types'] );
			$this->assertSame( $tree[0], json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[1], 'kept byte for byte' );
		}

		public function test_a_registry_that_throws_forces_the_skip(): void {
			IATO_MCP_Elementor_Adapter::set_type_checker( function () { throw new RuntimeException( 'addon register hook failed' ); } );
			$found = IATO_MCP_Elementor_Adapter::unregistered_element_types( $this->addon_page() );
			$this->assertSame( 'addon register hook failed', $found[0]['error'] );
			\Elementor\FakeDocument::$mode = 'ok';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_title( $this->addon_page(), 'New' ), 'sha256:prev', $this->addon_page() );
			$this->assertSame( 'skipped', $r['elementor_save'] );
			$this->assertSame( [], \Elementor\FakeDocument::$received, 'Elementor\'s save never ran on a half-built registry' );
		}

		public function test_the_posts_element_cache_is_cleared_before_every_save(): void {
			\Elementor\FakeDocument::$mode = 'ok';
			IATO_Test_WP::$meta[ self::POST ]['_elementor_element_cache'] = 'stale';
			IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );
			$this->assertArrayNotHasKey( '_elementor_element_cache', IATO_Test_WP::$meta[ self::POST ] );
		}

		public function test_update_elementor_data_refuses_plain_values_on_atomic_elements(): void {
			require_once __DIR__ . '/stubs-tools.php';
			require_once IATO_MCP_DIR . 'includes/tools/wp/tool-page-builder.php';
			self::authenticate( true );
			self::atomic_site();
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$handler = IATO_MCP_Server::get_catalog_entry( 'update_elementor_data' )['handler'];
			foreach ( [ true, false ] as $dry_run ) {
				$r = $handler( [ 'id' => self::POST, 'elementor_data' => json_encode( $this->with_a1_tag( $this->atomic_page(), 'h3' ) ), 'dry_run' => $dry_run ] );
				$this->assertTrue( $r['isError'] ?? false, $dry_run ? 'dry run' : 'write' );
				$body = json_decode( $r['content'][0]['text'], true );
				$this->assertSame( 'atomic_write_unavailable', $body['error'], 'the 1.13.1 guard, naming what is missing' );
				$this->assertStringContainsString( 'Elementor 4.3.0 or later', $body['message'] );
			}
			$this->assertSame( [], IATO_Test_WP::$writes );
			$copied = $handler( [ 'id' => self::POST, 'elementor_data' => json_encode( [ [ 'id' => 'z9', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'tag' => 'h3' ], 'elements' => [] ] ] ), 'dry_run' => true ] );
			$this->assertTrue( $copied['isError'] ?? false, 'a damaged element copied in from elsewhere is new here and refused' );
			$ok = $handler( [ 'id' => self::POST, 'elementor_data' => json_encode( $this->with_a1_tag( $this->atomic_page(), self::env( 'string', 'h3' ) ) ), 'dry_run' => true ] );
			$this->assertTrue( json_decode( $ok['content'][0]['text'], true )['dry_run'], 'typed values pass' );
		}

		public function test_validation_failure_caused_by_another_damaged_element_still_writes_and_names_it(): void {
			\Elementor\FakeDocument::$mode = 'throw'; // "Settings validation failed…" — from a2, not from the change
			IATO_MCP_Elementor_Adapter::set_element_validator( fn( array $n ) => 'a2' === $n['id'] ? 'Settings validation failed. tag: invalid_value' : null );
			$stored = $this->atomic_page( true );

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_a1_tag( $stored, self::env( 'string', 'h2' ) ), 'sha256:prev', $stored, [ 'atomic_ids' => [ 'a1' ] ] );

			$this->assertIsArray( $r, 'a damaged page can be repaired one element at a time' );
			$this->assertSame( 'plugin', $r['stored_by'] );
			$this->assertSame( [ [ 'widget_id' => 'a2', 'type' => 'e-heading', 'invalid_keys' => [ 'tag' ] ] ], $r['invalid_elements'] );
			$this->assertSame( $r['invalid_elements'], IATO_MCP_Elementor_Adapter::pipeline_report( $r )['invalid_elements'], 'copied onto tool responses' );
		}

		public function test_classic_writes_keep_the_fallback_on_a_validation_message(): void {
			\Elementor\FakeDocument::$mode = 'throw';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->tree(), 'sha256:prev' );
			$this->assertSame( 'plugin', $r['stored_by'], 'no atomic_ids: 1.13.0 behaviour' );
			$this->assertArrayNotHasKey( 'invalid_elements', $r );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'plain_patches' )]
		public function test_do_update_widget_refuses_values_an_atomic_element_cannot_store( array $patch, array $plain_keys, bool $dry_run ): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );

			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'a1', 'settings_patch' => $patch, 'dry_run' => $dry_run ] );

			$this->assertInstanceOf( WP_Error::class, $r );
			$this->assertSame( 'atomic_write_unavailable', $r->get_error_code(), 'the 1.13.1 guard where the writer is unavailable' );
			$this->assertStringContainsString( 'needs Elementor 4.3.0 or later; this site runs Elementor 4.2.4', $r->get_error_message() );
			$this->assertSame( [ [ 'element_id' => 'a1', 'type' => 'e-heading', 'plain_keys' => $plain_keys, 'unknown_keys' => [] ] ], $r->get_error_data()['elements'] );
			$this->assertStringContainsString( 'Nothing was written', $r->get_error_message() );
			$this->assertSame( [], IATO_Test_WP::$writes, 'nothing written' );
			$this->assertSame( [], \Elementor\FakeDocument::$received, 'Elementor never called' );
		}

		public static function plain_patches(): array {
			return [
				'plain tag'         => [ [ 'tag' => 'h3' ], [ 'tag' ], false ],
				'plain title'       => [ [ 'title' => 'Tom & Jerry' ], [ 'title' ], false ],
				'plain link object' => [ [ 'link' => [ 'destination' => 'https://example.com/new' ] ], [ 'link' ], false ],
				'plain image'       => [ [ 'image' => [ 'src' => [ 'url' => 'https://example.com/x.jpg', 'alt' => 'A' ] ] ], [ 'image' ], false ],
				'null removal'      => [ [ 'title' => null ], [ 'title' ], false ],
				'classic key'       => [ [ 'header_size' => 'h3' ], [ 'header_size' ], false ],
				'dry run too'       => [ [ 'tag' => 'h3' ], [ 'tag' ], true ],
			];
		}

		public function test_refusal_shows_the_typed_value_to_send(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'a1', 'settings_patch' => [ 'tag' => 'h3' ] ] );
			$this->assertSame( [ 'a1' => [ 'tag' => self::env( 'string', 'h3' ) ] ], $r->get_error_data()['examples'] );
			$this->assertStringContainsString( '{"tag":{"$$type":"string","value":"h3"}}', $r->get_error_message() );
			$this->assertStringContainsString( "Elementor's editor", $r->get_error_message() );

			$json = IATO_MCP_Elementor_Adapter::tool_error( $r );
			$this->assertTrue( $json['isError'] );
			$body = json_decode( $json['content'][0]['text'], true );
			$this->assertSame( 'atomic_write_unavailable', $body['error'] );
			$this->assertSame( 'a1', $body['elements'][0]['element_id'] );
			$this->assertArrayNotHasKey( 'status', $body );
			$other = new WP_Error( 'widget_not_found', 'x' );
			$this->assertSame( $other, IATO_MCP_Elementor_Adapter::tool_error( $other ), 'other errors pass through' );
		}

		public function test_unknown_keys_are_refused_with_the_registered_schema(): void {
			self::atomic_site();
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'a1', 'settings_patch' => [ 'header_size' => self::env( 'string', 'h3' ) ] ] );
			$this->assertSame( [ 'header_size' ], $r->get_error_data()['elements'][0]['unknown_keys'], 'an envelope for a key the element does not have' );
			$this->assertStringContainsString( 'header_size is not a setting of e-heading (its settings: tag, title)', $r->get_error_message() );
		}

		public function test_envelope_values_still_pass_the_guard(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'a1', 'settings_patch' => [ 'tag' => self::env( 'string', 'h3' ) ], 'dry_run' => true ] );
			$this->assertIsArray( $r );
			$this->assertSame( 'replace', $r['applied_patch'][0]['op'] );
		}

		public function test_addon_classic_widget_with_envelope_looking_settings_is_written_as_before(): void {
			$tree = [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'x1', 'elType' => 'widget', 'widgetType' => 'addon-heading', 'settings' => [ 'title' => self::env( 'string', 'Addon' ), 'size' => 'big' ], 'elements' => [] ],
			] ] ];
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $tree );
			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'x1', 'settings_patch' => [ 'title' => 'Plain title', 'size' => null ], 'dry_run' => true ] );
			$this->assertIsArray( $r, 'not refused' );
			$this->assertSame( [ 'replace', 'remove' ], array_column( $r['applied_patch'], 'op' ) );

			\Elementor\FakeDocument::$mode = 'throw';
			$edited = $tree;
			$edited[0]['elements'][0]['settings']['title'] = 'Plain title';
			$w = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $edited, 'sha256:prev', $tree, [] );
			$this->assertSame( 'plugin', $w['stored_by'], 'and its fallback write is unchanged' );
			$this->assertSame( 'Plain title', json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true )[0]['elements'][0]['settings']['title'] );
		}

		/** The update_elementor_patch handler itself: its guard runs on the patched copy. */
		private function patch_tool( array $ops, bool $dry_run = true ): array|WP_Error {
			require_once __DIR__ . '/stubs-tools.php';
			require_once IATO_MCP_DIR . 'includes/tools/wp/tool-elementor-widgets.php';
			self::authenticate( true );
			self::atomic_site();
			return ( IATO_MCP_Server::get_catalog_entry( 'update_elementor_patch' )['handler'] )( [ 'id' => self::POST, 'ops' => $ops, 'dry_run' => $dry_run ] );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_ops' )]
		public function test_patch_tool_refuses_ops_that_store_plain_values_on_atomic_elements( array $ops, bool $dry_run, string $code = 'atomic_write_unavailable' ): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page() );
			$r = $this->patch_tool( $ops, $dry_run );
			$this->assertTrue( $r['isError'] ?? false );
			$this->assertSame( $code, json_decode( $r['content'][0]['text'], true )['error'] );
			$this->assertSame( [], IATO_Test_WP::$writes );
		}

		public static function refused_ops(): array {
			return [
				'replace …/settings/tag with a plain value' => [ [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => 'h3' ] ], false ],
				'add a plain key'                           => [ [ [ 'op' => 'add', 'path' => '/0/elements/0/settings/link', 'value' => [ 'destination' => 'https://x.test/' ] ] ], false, 'atomic_plain_value_unsupported' ], // link is not in this schema: refused as an unknown key, whatever the writer
				'replace the whole settings object'         => [ [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings', 'value' => [ 'tag' => 'h3' ] ] ], false ],
				'a new atomic element with plain settings'  => [ [ [ 'op' => 'add', 'path' => '/0/elements/-', 'value' => [ 'id' => 'n1', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => [ 'paragraph' => 'x' ], 'elements' => [] ] ] ], false ],
				'dry run'                                   => [ [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag', 'value' => 'h3' ] ], true ],
			];
		}

		// ── 1.13.1: element types Elementor cannot load right now ────────────

		/** A section holding a heading and a widget from an addon that is no longer active (nested one level down). */
		private function addon_page(): array {
			return [ [ 'id' => 's1', 'elType' => 'section', 'settings' => [], 'elements' => [
				[ 'id' => 'col1', 'elType' => 'column', 'settings' => [], 'elements' => [
					[ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old title' ], 'elements' => [] ],
					[ 'id' => 'ad1', 'elType' => 'widget', 'widgetType' => 'gone-addon-widget', 'settings' => [ 'label' => '<em>addon</em> & co', 'html' => '<iframe src="https://x.test/"></iframe>' ], 'elements' => [] ],
				] ],
			] ] ];
		}

		private function with_title( array $tree, string $title ): array {
			$tree[0]['elements'][0]['elements'][0]['settings']['title'] = $title;
			return $tree;
		}

		private static function registry_without( string ...$missing ): void {
			IATO_MCP_Elementor_Adapter::set_type_checker( fn( string $el, string $w ) => ! in_array( 'widget' === $el ? $w : $el, $missing, true ) );
		}

		public function test_unregistered_types_are_found_anywhere_in_the_tree(): void {
			self::registry_without( 'gone-addon-widget', 'e-flexbox' );
			$tree   = $this->addon_page();
			$tree[] = [ 'id' => 'f1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [] ];
			$this->assertSame( [
				[ 'type' => 'gone-addon-widget', 'element_ids' => [ 'ad1' ] ],
				[ 'type' => 'e-flexbox', 'element_ids' => [ 'f1' ] ],
			], IATO_MCP_Elementor_Adapter::unregistered_element_types( $tree ) );
			self::registry_without();
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::unregistered_element_types( $tree ), 'everything registered' );
		}

		public function test_without_elementor_registries_nothing_is_reported(): void {
			// The fake Elementor in this file has no widgets_manager / elements_manager.
			$this->assertSame( [], IATO_MCP_Elementor_Adapter::unregistered_element_types( $this->addon_page() ) );
		}

		public function test_unregistered_type_skips_elementors_save_and_keeps_every_element(): void {
			self::registry_without( 'gone-addon-widget' );
			\Elementor\FakeDocument::$mode = 'ok';
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data']          = json_encode( $this->addon_page() );
			IATO_Test_WP::$meta[ self::POST ]['_elementor_element_cache'] = 'cached html';

			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_title( $this->addon_page(), 'New title' ), 'sha256:prev', $this->addon_page() );

			$this->assertSame( [], \Elementor\FakeDocument::$received, 'Elementor\'s save never ran' );
			$this->assertSame( 'skipped', $r['elementor_save'] );
			$this->assertSame( 'plugin', $r['stored_by'] );
			$this->assertSame( 'unregistered_element_types', $r['skipped_reason'] );
			$this->assertSame( [ [ 'type' => 'gone-addon-widget', 'element_ids' => [ 'ad1' ] ] ], $r['unregistered_element_types'] );
			$stored = json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true );
			$this->assertSame( $this->addon_page()[0]['elements'][0]['elements'][1], $stored[0]['elements'][0]['elements'][1], 'the addon widget is stored byte for byte' );
			$this->assertSame( 'New title', $stored[0]['elements'][0]['elements'][0]['settings']['title'] );
			$this->assertSame( [ 'element_count_before' => 4, 'element_count_after' => 4, 'element_count_expected' => 4, 'untouched_identical' => true ], $r['verification'] );
			$this->assertArrayNotHasKey( '_elementor_element_cache', IATO_Test_WP::$meta[ self::POST ], 'the post\'s element cache is cleared' );
			$this->assertTrue( $r['cache_cleared']['element_cache'] );
			$report = IATO_MCP_Elementor_Adapter::pipeline_report( $r );
			foreach ( [ 'skipped_reason', 'unregistered_element_types', 'cache_cleared', 'verification' ] as $key ) {
				$this->assertSame( $r[ $key ], $report[ $key ], "{$key} reaches tool responses" );
			}
		}

		public function test_the_skipped_path_still_sanitises_only_what_changed(): void {
			self::registry_without( 'gone-addon-widget' );
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_title( $this->addon_page(), self::PAYLOAD ), 'sha256:prev', $this->addon_page() );
			$this->assertSame( [ 'h1/settings/title' ], $r['sanitized_paths'] );
			$stored = json_decode( IATO_Test_WP::$meta[ self::POST ]['_elementor_data'], true );
			$this->assertStringContainsString( '<iframe', $stored[0]['elements'][0]['elements'][1]['settings']['html'], 'untouched addon HTML kept' );
		}

		public function test_everything_registered_still_goes_through_elementors_save(): void {
			self::registry_without();
			\Elementor\FakeDocument::$mode = 'ok';
			$r = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $this->with_title( $this->addon_page(), 'New title' ), 'sha256:prev', $this->addon_page() );
			$this->assertTrue( $r['elementor_save'] );
			$this->assertSame( 'elementor', $r['stored_by'] );
			$this->assertNotEmpty( \Elementor\FakeDocument::$received );
			foreach ( [ 'skipped_reason', 'unregistered_element_types', 'cache_cleared', 'verification' ] as $key ) {
				$this->assertArrayNotHasKey( $key, $r );
			}
		}

		public function test_verification_reports_an_untouched_element_that_changed(): void {
			$before  = $this->addon_page();
			$written = $this->with_title( $before, 'New title' );
			$lost    = $written;
			unset( $lost[0]['elements'][0]['elements'][1] );
			$v = IATO_MCP_Elementor_Adapter::verify_untouched( $before, $written, json_encode( $lost ) );
			$this->assertFalse( $v['untouched_identical'] );
			$this->assertSame( [ 'ad1' ], $v['mismatched_element_ids'] );
			$this->assertSame( [ 4, 3 ], [ $v['element_count_before'], $v['element_count_after'] ] );
			$this->assertTrue( IATO_MCP_Elementor_Adapter::verify_untouched( $before, $written, json_encode( $written ) )['untouched_identical'] );
		}

		public function test_dry_run_reports_the_skip_without_writing(): void {
			self::registry_without( 'gone-addon-widget' );
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->addon_page() );
			$r = IATO_MCP_Elementor_Adapter::do_update_widget( [ 'id' => self::POST, 'widget_id' => 'h1', 'settings_patch' => [ 'title' => 'New title' ], 'dry_run' => true ] );
			$this->assertTrue( $r['dry_run'] );
			$this->assertSame( 'skipped', $r['elementor_save'] );
			$this->assertSame( 'unregistered_element_types', $r['skipped_reason'] );
			$this->assertSame( [ [ 'type' => 'gone-addon-widget', 'element_ids' => [ 'ad1' ] ] ], $r['unregistered_element_types'] );
			$this->assertSame( [], IATO_Test_WP::$writes );
			$this->assertSame( 'unregistered_element_types', IATO_MCP_Elementor_Adapter::preview_report( $this->addon_page(), $this->addon_page() )['skipped_reason'], 'the patch and update_elementor_data previews report it too' );
			self::registry_without();
			$this->assertArrayNotHasKey( 'skipped_reason', IATO_MCP_Elementor_Adapter::preview_report( $this->addon_page(), $this->addon_page() ) );
		}

		public function test_atomic_elements_while_the_atomic_editor_is_off(): void {
			self::registry_without( 'e-flexbox', 'e-heading' );
			$tree   = $this->addon_page();
			$tree[] = $this->atomic_page()[0];
			$edited = $this->with_title( $tree, 'Classic edited' );
			$r      = IATO_MCP_Elementor_Adapter::write_pipeline( self::POST, $edited, 'sha256:prev', $tree );
			$this->assertSame( 'skipped', $r['elementor_save'] );
			$this->assertSame( [ [ 'type' => 'e-flexbox', 'element_ids' => [ 'f1' ] ], [ 'type' => 'e-heading', 'element_ids' => [ 'a1' ] ] ], $r['unregistered_element_types'] );
			$this->assertSame( 6, $r['verification']['element_count_after'] );
			$this->assertTrue( $r['verification']['untouched_identical'] );
		}

		public function test_patch_tool_allows_paths_inside_an_envelope_and_removals(): void {
			IATO_Test_WP::$meta[ self::POST ]['_elementor_data'] = json_encode( $this->atomic_page( true ) );
			$r = $this->patch_tool( [ [ 'op' => 'replace', 'path' => '/0/elements/0/settings/tag/value', 'value' => 'h3' ], [ 'op' => 'remove', 'path' => '/0/elements/0/settings/title' ] ] );
			$this->assertArrayNotHasKey( 'isError', $r, 'the a2 damage elsewhere on the page does not block the patch' );
			$this->assertTrue( json_decode( $r['content'][0]['text'], true )['dry_run'] );
		}
	}
}

<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * IATO_MCP_Elementor_Sanitizer: only values that differ from the stored tree
 * are touched, by shape (URL fields, custom_css, strings containing `<`,
 * everything else untouched), for known and unknown widget types alike and
 * for atomic envelopes. The WordPress functions are replaced with markers.
 */
final class ElementorSanitizerTest extends TestCase {

	protected function setUp(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( [
			'kses' => fn( string $v ) => 'K[' . $v . ']',
			'url'  => fn( string $v ) => str_starts_with( strtolower( $v ), 'javascript:' ) ? '' : $v,
			'css'  => fn( string $v ) => 'C[' . $v . ']',
		] );
	}

	protected function tearDown(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( null );
	}

	private function page(): array {
		return [ [ 'id' => 'c1', 'elType' => 'container', 'settings' => [ 'custom_css' => '.x > .y { color: red }' ], 'elements' => [
			[ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Tom & Jerry', 'header_size' => 'h2', 'link' => [ 'url' => 'https://example.com/?a=1&b=2', 'is_external' => '' ] ], 'elements' => [] ],
			[ 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<iframe src="https://maps.example.com/"></iframe><script>var human=1;</script>' ], 'elements' => [] ],
			[ 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => [
				'text' => [ '$$type' => 'string', 'value' => 'Go' ],
				'link' => [ '$$type' => 'link', 'value' => [ 'destination' => [ '$$type' => 'url', 'value' => 'https://example.com/?x=1&y=2' ], 'isTargetBlank' => [ '$$type' => 'boolean', 'value' => false ] ] ],
			], 'styles' => [ 's1' => [ 'id' => 's1', 'variants' => [ [ 'props' => [], 'custom_css' => [ 'raw' => base64_encode( 'a > b { }' ) ] ] ] ] ], 'elements' => [] ],
		] ] ];
	}

	public function test_identical_tree_is_untouched_and_reports_nothing(): void {
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $this->page(), $this->page() );
		$this->assertSame( $this->page(), $r['elements'] );
		$this->assertSame( [], $r['changed'] );
		$this->assertSame( [ 'sanitized' => false ], IATO_MCP_Elementor_Sanitizer::preview( $this->page(), $this->page() ) );
	}

	public function test_changed_string_with_markup_is_kses_passed_and_reported(): void {
		$new = $this->page();
		$new[0]['elements'][0]['settings']['title'] = '<b>Hi</b><script>x</script>';
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $this->page() );
		$this->assertSame( 'K[<b>Hi</b><script>x</script>]', $r['elements'][0]['elements'][0]['settings']['title'] );
		$this->assertSame( [ 'h1/settings/title' ], $r['changed'] );
		$this->assertStringContainsString( '<iframe', $r['elements'][0]['elements'][1]['settings']['html'], 'untouched HTML widget kept verbatim' );
	}

	public function test_plain_text_numbers_and_booleans_are_never_touched(): void {
		$new = $this->page();
		$new[0]['elements'][0]['settings']['title']       = 'Jerry & Tom';
		$new[0]['elements'][0]['settings']['header_size'] = 'h1';
		$new[0]['elements'][0]['settings']['_count']      = 3;
		$new[0]['elements'][0]['settings']['_flag']       = true;
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $this->page() );
		$this->assertSame( $new, $r['elements'] );
		$this->assertSame( [], $r['changed'], 'nothing altered, nothing reported' );
	}

	public function test_url_fields_use_the_url_rule_classic_and_atomic(): void {
		$new = $this->page();
		$new[0]['elements'][0]['settings']['link']['url'] = 'javascript:alert(1)';
		$new[0]['elements'][2]['settings']['link']['value']['destination']['value'] = 'JavaScript:alert(2)';
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $this->page() );
		$this->assertSame( '', $r['elements'][0]['elements'][0]['settings']['link']['url'] );
		$this->assertSame( '', $r['elements'][0]['elements'][2]['settings']['link']['value']['destination']['value'] );
		$this->assertSame( [ 'h1/settings/link/url', 'a1/settings/link/value/destination/value' ], $r['changed'] );

		$new = $this->page();
		$new[0]['elements'][0]['settings']['link']['url'] = 'https://example.com/?a=1&b=2&c=<3';
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $this->page() );
		$this->assertSame( 'https://example.com/?a=1&b=2&c=<3', $r['elements'][0]['elements'][0]['settings']['link']['url'], 'URL rule, not kses, even with a <' );
		$this->assertSame( [], $r['changed'] );
	}

	public function test_custom_css_uses_the_css_rule_classic_page_settings_and_atomic_base64(): void {
		$new = $this->page();
		$new[0]['settings']['custom_css'] = '.a > .b { } </style><script>alert(1)</script>';
		$new[0]['elements'][2]['styles']['s1']['variants'][0]['custom_css']['raw'] = base64_encode( 'c > d { } </style>' );
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $this->page() );
		$this->assertSame( 'C[.a > .b { } </style><script>alert(1)</script>]', $r['elements'][0]['settings']['custom_css'] );
		$this->assertSame( base64_encode( 'C[c > d { } </style>]' ), $r['elements'][0]['elements'][2]['styles']['s1']['variants'][0]['custom_css']['raw'], 'decoded, sanitised, re-encoded' );
		$this->assertSame( [ 'c1/settings/custom_css', 'a1/styles/s1/variants/0/custom_css/raw' ], $r['changed'] );

		$r = IATO_MCP_Elementor_Sanitizer::diff_settings( [ 'custom_css' => 'x > y {}', 'hide_title' => 'yes' ], [ 'hide_title' => 'yes' ], '_elementor_page_settings' );
		$this->assertSame( [ 'custom_css' => 'C[x > y {}]', 'hide_title' => 'yes' ], $r['value'] );
		$this->assertSame( [ '_elementor_page_settings/custom_css' ], $r['changed'] );
	}

	public function test_new_and_unknown_widgets_follow_the_same_rules(): void {
		$new   = $this->page();
		$new[0]['elements'][] = [ 'id' => 'z9', 'elType' => 'widget', 'widgetType' => 'addon-fancy-thing', 'settings' => [
			'caption' => '<em>new</em>', 'plain' => 'no markup & fine', 'cta_url' => 'javascript:1', 'n' => 7,
			'rows'    => [ [ 'label' => '<i>r1</i>' ], [ 'label' => 'r2' ] ],
		], 'elements' => [] ];
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $this->page() );
		$s = $r['elements'][0]['elements'][3]['settings'];
		$this->assertSame( 'K[<em>new</em>]', $s['caption'] );
		$this->assertSame( 'no markup & fine', $s['plain'] );
		$this->assertSame( '', $s['cta_url'] );
		$this->assertSame( 7, $s['n'] );
		$this->assertSame( 'K[<i>r1</i>]', $s['rows'][0]['label'] );
		$this->assertSame( 'r2', $s['rows'][1]['label'] );
		$this->assertSame( [ 'z9/settings/caption', 'z9/settings/cta_url', 'z9/settings/rows/0/label' ], $r['changed'] );

		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, null );
		$this->assertCount( 6, $r['changed'], 'with nothing stored, every markup/URL/CSS value is new: both custom_css values, the html widget, the three above' );
		$this->assertContains( 'c1/settings/custom_css', $r['changed'] );
		$this->assertContains( 'w2/settings/html', $r['changed'] );
	}

	public function test_an_element_whose_type_changed_has_every_setting_treated_as_new(): void {
		// Same id, same settings, but the widget type changes from one that
		// escapes `title` on output to one that could render it raw.
		$stored = [ [ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => '<em>kept</em>', 'plain' => 'a & b' ], 'elements' => [] ] ];
		$new    = $stored;
		$new[0]['widgetType'] = 'html';
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $stored );
		$this->assertSame( 'K[<em>kept</em>]', $r['elements'][0]['settings']['title'], 'unchanged value, but the widget type changed: sanitised' );
		$this->assertSame( 'a & b', $r['elements'][0]['settings']['plain'], 'plain text still untouched' );
		$this->assertSame( [ 'w1/settings/title' ], $r['changed'] );

		$new = $stored;
		$new[0]['elType'] = 'container';
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $stored );
		$this->assertSame( [ 'w1/settings/title' ], $r['changed'], 'elType change counts too' );

		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $stored, $stored );
		$this->assertSame( [], $r['changed'], 'same type: unchanged values stay verbatim' );

		$this->assertTrue( IATO_MCP_Elementor_Sanitizer::same_type( [ 'elType' => 'widget', 'widgetType' => 'x' ], [ 'elType' => 'widget', 'widgetType' => 'x' ] ) );
		$this->assertFalse( IATO_MCP_Elementor_Sanitizer::same_type( [ 'elType' => 'widget' ], [ 'elType' => 'widget', 'widgetType' => 'x' ] ), 'a missing widgetType is not the same as a set one' );
	}

	public function test_every_element_key_is_diffed_not_only_settings(): void {
		$stored = [ [ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'a' ], 'elements' => [] ] ];
		$new    = $stored;
		$new[0]['htmlCache']       = '<script>x</script>';
		$new[0]['editor_settings'] = [ 'note' => '<b>n</b>', 'plain' => 'ok' ];
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $stored );
		$this->assertSame( 'K[<script>x</script>]', $r['elements'][0]['htmlCache'], 'a key outside settings/styles is a leaf too' );
		$this->assertSame( 'K[<b>n</b>]', $r['elements'][0]['editor_settings']['note'] );
		$this->assertSame( 'ok', $r['elements'][0]['editor_settings']['plain'] );
		$this->assertSame( [ 'w1/htmlCache', 'w1/editor_settings/note' ], $r['changed'] );

		$new = $stored;
		$new[0]['settings'] = '<script>s</script>'; // settings given as a string
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $stored );
		$this->assertSame( 'K[<script>s</script>]', $r['elements'][0]['settings'] );

		$new = $stored;
		$new[0]['id'] = '<i>'; // structure keys are strings too; a new id has no stored counterpart
		$new[0]['widgetType'] = '<w>';
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $stored );
		$this->assertSame( 'K[<i>]', $r['elements'][0]['id'] );
		$this->assertSame( 'K[<w>]', $r['elements'][0]['widgetType'] );
		$this->assertSame( 'a', $r['elements'][0]['settings']['title'], 'its settings count as new, but plain text needs no sanitising' );
		$this->assertSame( [ '<i>/id', '<i>/widgetType' ], $r['changed'], 'paths are keyed by the element id as sent' );

		$new = $stored;
		$new[0]['elements'] = '<script>e</script>'; // children given as a string
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $stored );
		$this->assertSame( 'K[<script>e</script>]', $r['elements'][0]['elements'], 'a non-array elements key is a leaf' );
		$this->assertSame( [ 'w1/elements' ], $r['changed'] );

		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ '<script>top</script>', 7, $stored[0] ], $stored );
		$this->assertSame( 'K[<script>top</script>]', $r['elements'][0], 'a scalar where an element should be is sanitised' );
		$this->assertSame( 7, $r['elements'][1] );
		$this->assertSame( $stored[0], $r['elements'][2], 'the real element is unchanged' );
		$this->assertSame( [ '#0' ], $r['changed'] );
	}

	public function test_reported_paths_are_capped(): void {
		$settings = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$settings[ "k$i" ] = "<b>$i</b>";
		}
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ [ 'id' => 'w', 'elType' => 'widget', 'widgetType' => 'x', 'settings' => $settings, 'elements' => [] ] ], null );
		$this->assertCount( IATO_MCP_Elementor_Sanitizer::MAX_PATHS + 1, $r['changed'] );
		$this->assertSame( '… and 10 more', end( $r['changed'] ) );
		$this->assertSame( 'K[<b>59</b>]', $r['elements'][0]['settings']['k59'], 'every value is still sanitised, only the report is capped' );
	}

	public function test_value_rules_directly(): void {
		$v = fn( $value, array $path, string $hint = '' ) => IATO_MCP_Elementor_Sanitizer::sanitize_value( $value, $path, $hint );
		$this->assertSame( 'plain', $v( 'plain', [ 'title' ] ) );
		$this->assertSame( '', $v( '', [ 'title' ] ) );
		$this->assertSame( 5, $v( 5, [ 'title' ] ) );
		$this->assertSame( 'K[<p>x</p>]', $v( '<p>x</p>', [ 'editor' ] ) );
		$this->assertSame( '', $v( 'javascript:x', [ 'link', 'url' ] ) );
		$this->assertSame( '', $v( 'javascript:x', [ 'background_image', 'url' ] ) );
		$this->assertSame( '', $v( 'javascript:x', [ 'video_url' ] ) );
		$this->assertSame( '', $v( 'javascript:x', [ 'value' ], 'url' ), 'atomic url envelope by hint' );
		$this->assertSame( 'C[a > b]', $v( 'a > b', [ 'custom_css' ] ) );
		$this->assertSame( base64_encode( 'C[a > b]' ), $v( base64_encode( 'a > b' ), [ 'custom_css', 'raw' ] ) );
		$this->assertSame( '', $v( 'not base64!', [ 'custom_css', 'raw' ] ), 'a raw value that does not decode is dropped, as Elementor does' );
		$css      = 'a{} </style><script>alert(1)</script>';
		$unpadded = rtrim( base64_encode( $css ), '=' );
		$this->assertSame( base64_encode( 'C[' . $css . ']' ), $v( $unpadded, [ 'custom_css', 'raw' ] ), 'non-canonical base64 (no padding) is still decoded, sanitised and re-encoded canonically' );
		$this->assertSame( base64_encode( 'C[' . $css . ']' ), $v( base64_encode( $css ) . "\n", [ 'custom_css', 'raw' ] ), 'trailing whitespace too' );
	}
}

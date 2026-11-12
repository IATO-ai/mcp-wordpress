<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-meta.php';
require_once IATO_MCP_DIR . 'includes/class-meta-policy.php';

/**
 * sanitized_report (1.15.0): what sanitising removed, compared from input and
 * output, on the sanitising pass and the meta policy.
 */
final class SanitizeReportTest extends TestCase {

	/** What wp_kses_post does to these tags: drops the tag, keeps what is between. */
	private static function fake_kses( string $value ): string {
		return strip_tags( $value, '<p><a><b><img><br>' );
	}

	protected function setUp(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( [
			'kses'          => fn( string $v ) => self::fake_kses( $v ),
			'url'           => fn( string $v ) => str_starts_with( $v, 'javascript:' ) ? '' : $v,
			'css'           => fn( string $v ) => strip_tags( $v ),
			'inline_policy' => fn() => IATO_MCP_Inline_Markup::STRIPPED_SETTING_OFF,
		] );
	}

	protected function tearDown(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( null );
	}

	private static function html_widget( string $html, string $id = 'ab00069' ): array {
		return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => $html ], 'elements' => [] ];
	}

	// ── The comparison ────────────────────────────────────────────────────

	public function test_the_case_that_prompted_this(): void {
		$before = '<style>.a{color:red}</style><svg viewBox="0 0 1 1"><path d="M0"/><rect x="1"/></svg><p>text</p>';
		$after  = self::fake_kses( $before );
		$this->assertSame( [ 'removed_tags' => [ 'style', 'svg', 'path', 'rect' ], 'removed_attributes' => [] ], IATO_MCP_Sanitize_Report::removed( $before, $after ) );
	}

	/** @return array<string,array{0:string,1:string,2:string[],3:string[]}> */
	public static function comparisons(): array {
		return [
			'nothing removed'             => [ '<p>a</p>', '<p>a</p>', [], [] ],
			'attribute removed'           => [ '<a href="x" onclick="y">a</a>', '<a href="x">a</a>', [], [ 'onclick' ] ],
			'tag gone, attrs not listed'  => [ '<svg onload="x"></svg><p>a</p>', '<p>a</p>', [ 'svg' ], [] ],
			'case does not count'         => [ '<svg viewBox="0"></svg>', '<svg viewbox="0" />', [], [] ],
			'upper case tag'              => [ '<SCRIPT>x</SCRIPT><p>a</p>', '<p>a</p>', [ 'script' ], [] ],
			'one of two kept'             => [ '<a x="1">a</a><a x="2">b</a>', '<a x="1">a</a>', [ 'a' ], [] ],
			'attr lost beyond the tag'    => [ '<a x="1">a</a><a x="2">b</a>', '<a>a</a>', [ 'a' ], [ 'x' ] ],
			'> inside a quoted attribute' => [ '<a title="a>b" rel="x">c</a>', '<a title="a>b">c</a>', [], [ 'rel' ] ],
			'single quotes'               => [ "<a title='a>b' rel=x>c</a>", "<a title='a>b'>c</a>", [], [ 'rel' ] ],
			'tags inside a style body'    => [ '<style><b>not a tag</b></style>', '', [ 'style' ], [] ],
			'tags inside a comment'       => [ '<!-- <script>x</script> --><p>a</p>', '<p>a</p>', [], [] ],
			'not a tag at all'            => [ 'a < b and c > d', 'a &lt; b and c &gt; d', [], [] ],
			'unclosed tag is no tag'      => [ '<p>a</p><svg width="1', '<p>a</p>', [], [] ],
			'closing tags do not count'   => [ '</div><p>a</p>', '<p>a</p>', [], [] ],
			'url changed, no tags'        => [ 'javascript:alert(1)', '', [], [] ],
		];
	}

	#[DataProvider( 'comparisons' )]
	public function test_comparison( string $before, string $after, array $tags, array $attributes ): void {
		$this->assertSame( [ 'removed_tags' => $tags, 'removed_attributes' => $attributes ], IATO_MCP_Sanitize_Report::removed( $before, $after ) );
	}

	public function test_for_value_is_null_when_nothing_was_removed(): void {
		$this->assertNull( IATO_MCP_Sanitize_Report::for_value( 'c', 'html', 'a & b', 'a &amp; b' ), 'entity encoding is not a removal' );
		$this->assertNull( IATO_MCP_Sanitize_Report::for_value( 'c', 'html', '<p>x</p>', '<p>x</p>' ) );
		$this->assertNull( IATO_MCP_Sanitize_Report::for_value( 'c', 'html', null, 'x' ) );
		$this->assertSame( [ 'script' ], IATO_MCP_Sanitize_Report::for_value( 'c', 'html', '<script>x</script>', 'x' )['removed_tags'] );
	}

	public function test_the_note_warns_only_when_style_text_really_stays_visible(): void {
		$note = IATO_MCP_Sanitize_Report::entry( 'p', 'html', '<style>a{}</style>', 'a{}' )['note'];
		$this->assertStringContainsString( 'visible text', $note );
		$this->assertArrayNotHasKey( 'note', IATO_MCP_Sanitize_Report::entry( 'p', 'html', '<svg></svg>', '' ), 'nothing to warn about' );
		// 1.21.0: a removed style block also gets the set_page_css pointer, so look for the warning itself.
		$this->assertStringNotContainsString( 'visible text', IATO_MCP_Sanitize_Report::entry( 'p', 'html', '<p>x</p><style>a{}</style><script>b()</script>', '<p>x</p>' )['note'] ?? '', 'whole elements were removed' );
		$this->assertStringNotContainsString( 'visible text', IATO_MCP_Sanitize_Report::entry( 'p', 'html', '<style></style>', '' )['note'] ?? '', 'an empty element leaves nothing' );
		$this->assertArrayHasKey( 'note', IATO_MCP_Sanitize_Report::entry( 'p', 'html', '<p>x</p><style>a{}', '<p>x</p>a{}' ), 'an unclosed tag stays as text' );
		$this->assertArrayHasKey( 'note', IATO_MCP_Sanitize_Report::entry( 'p', 'html', '<script>a>b</script>', 'a&gt;b' ), 'kses encodes what it keeps' );
		$this->assertArrayNotHasKey( 'note', IATO_MCP_Sanitize_Report::entry( 'p', 'html', '<img alt="<style>x</style>" src="y">', 'x' ), 'a lookalike in an attribute is no style element' );
	}

	public function test_without_the_tokenizer_the_value_goes_to_kses_unchanged(): void {
		$this->assertFalse( method_exists( 'WP_HTML_Tag_Processor', 'next_token' ), 'PHPUnit runs without WordPress; the tokenizer path is covered live' );
		$html = '<p>x</p><style>a{}</style>';
		$this->assertSame( $html, IATO_MCP_Inline_Markup::remove_style_and_script( $html ) );
		$this->assertSame( '<p>plain</p>', IATO_MCP_Inline_Markup::remove_style_and_script( '<p>plain</p>' ) );
	}

	public function test_cap_uses_the_same_form_as_sanitized_paths(): void {
		$report = array_fill( 0, 53, [ 'path' => 'x', 'removed_tags' => [], 'removed_attributes' => [] ] );
		$capped = IATO_MCP_Sanitize_Report::cap( $report );
		$this->assertCount( 51, $capped );
		$this->assertSame( '… and 3 more', $capped[50]['path'] );
		$this->assertSame( array_slice( $report, 0, 50 ), IATO_MCP_Sanitize_Report::cap( array_slice( $report, 0, 50 ) ) );
	}

	public function test_merge_keeps_one_entry_per_value(): void {
		$a = [ [ 'path' => 'e1/settings/title', 'rule' => 'html' ] ];
		$b = [ [ 'path' => 'e1/settings/title/value', 'rule' => 'html' ], [ 'path' => 'e2/settings/x', 'rule' => 'url' ] ];
		$this->assertSame( [ 'e1/settings/title', 'e2/settings/x' ], array_column( IATO_MCP_Sanitize_Report::merge( $a, $b ), 'path' ) );
	}

	public function test_a_removed_style_block_points_at_set_page_css(): void {
		$entry = IATO_MCP_Sanitize_Report::entry( 'w/settings/html', 'html', '<p>x</p><style>a{}</style>', '<p>x</p>' );
		$this->assertStringContainsString( 'set_page_css', $entry['note'] );
		$dropped = IATO_MCP_Sanitize_Report::entry( 'w/settings/html', 'html', '<style>a</style>', '', [ 'inline_markup' => 'kept', 'removed_css' => [ 'whole <style> block: it contains <' ] ] );
		$this->assertStringContainsString( 'set_page_css', $dropped['note'], 'also when the opt-in dropped the whole block' );
		$this->assertArrayNotHasKey( 'note', IATO_MCP_Sanitize_Report::entry( 'w/settings/html', 'html', '<p onclick="x">a</p>', '<p>a</p>' ) );
		$this->assertArrayNotHasKey( 'note', IATO_MCP_Sanitize_Report::entry( 'w/settings/title', 'text', '<style>a</style>', 'a' ), 'only the markup rule' );
	}

	public function test_a_later_pass_that_removed_more_is_not_lost(): void {
		$ours      = [ [ 'path' => 'w/settings/html', 'rule' => 'html', 'removed_tags' => [], 'removed_attributes' => [] ] ];
		$elementor = [ [ 'path' => 'w/settings/html', 'rule' => 'html', 'removed_tags' => [ 'input' ], 'removed_attributes' => [ 'aria-pressed' ], 'source' => 'elementor' ] ];
		$merged    = IATO_MCP_Sanitize_Report::merge( $ours, $elementor );
		$this->assertCount( 1, $merged );
		$this->assertSame( [ 'input' ], $merged[0]['removed_tags'] );
		$this->assertSame( [ 'aria-pressed' ], $merged[0]['removed_attributes'] );
		$this->assertSame( 'elementor', $merged[0]['source'] );
		$this->assertStringContainsString( "Elementor's own save removed: input, aria-pressed.", $merged[0]['note'] );
	}

	public function test_a_later_pass_that_removed_nothing_new_changes_nothing(): void {
		$ours = [ [ 'path' => 'w/settings/html', 'rule' => 'html', 'removed_tags' => [ 'script' ], 'removed_attributes' => [] ] ];
		$same = [ [ 'path' => 'w/settings/html/value', 'rule' => 'html', 'removed_tags' => [ 'script' ], 'removed_attributes' => [], 'source' => 'elementor' ] ];
		$this->assertSame( $ours, IATO_MCP_Sanitize_Report::merge( $ours, $same ) );
	}

	public function test_add_to_marks_only_real_changes(): void {
		$r = [];
		IATO_MCP_Sanitize_Report::add_to( $r, [] );
		$this->assertSame( [], $r );

		IATO_MCP_Sanitize_Report::add_to( $r, [ [ 'path' => 'k', 'inline_markup' => 'kept', 'kept_tags' => [ 'svg' ], 'removed_tags' => [], 'removed_attributes' => [] ] ] );
		$this->assertArrayNotHasKey( 'sanitized', $r, 'kept markup changed nothing' );
		$this->assertCount( 1, $r['sanitized_report'] );

		IATO_MCP_Sanitize_Report::add_to( $r, [ [ 'path' => 'c', 'removed_tags' => [ 'script' ], 'removed_attributes' => [] ] ] );
		$this->assertTrue( $r['sanitized'] );
		$this->assertSame( [ 'c' ], $r['sanitized_paths'] );
		$this->assertCount( 2, $r['sanitized_report'] );
	}

	// ── The sanitising pass ───────────────────────────────────────────────

	public function test_html_widget_report_says_what_went_and_why(): void {
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ self::html_widget( '<style>a{}</style><svg><rect x="1"/></svg><p>x</p>' ) ], [] );
		$this->assertSame( [ 'ab00069/settings/html' ], $r['changed'] );
		$entry = $r['report'][0];
		$this->assertSame( 'ab00069/settings/html', $entry['path'] );
		$this->assertSame( 'html', $entry['rule'] );
		$this->assertSame( [ 'style', 'svg', 'rect' ], $entry['removed_tags'] );
		$this->assertSame( [], $entry['removed_attributes'] );
		$this->assertSame( 'stripped_setting_off', $entry['inline_markup'] );
		$this->assertStringContainsString( 'Allow inline SVG and style blocks', $entry['note'] );
		$this->assertStringContainsString( 'visible text', $entry['note'] );
	}

	public function test_no_capability_is_reported_as_such(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( [ 'kses' => fn( string $v ) => strip_tags( $v ), 'inline_policy' => fn() => IATO_MCP_Inline_Markup::STRIPPED_NO_CAPABILITY ] );
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ self::html_widget( '<svg></svg>' ) ], [] );
		$this->assertSame( 'stripped_no_capability', $r['report'][0]['inline_markup'] );
		$this->assertStringContainsString( 'unfiltered_html and manage_options', $r['report'][0]['note'] );
	}

	public function test_kept_markup_is_reported_though_nothing_changed(): void {
		$html = '<style>a{}</style><svg><path d="M0"/></svg>';
		IATO_MCP_Elementor_Sanitizer::set_functions( [
			'inline_policy' => fn() => IATO_MCP_Inline_Markup::KEPT,
			'inline_filter' => fn( string $v ) => [ 'ok' => true, 'html' => $v, 'kept_tags' => [ 'style', 'svg', 'path' ], 'removed_css' => [] ],
		] );
		$r = IATO_MCP_Elementor_Sanitizer::preview( [ self::html_widget( $html ) ], [] );
		$this->assertFalse( $r['sanitized'] );
		$this->assertArrayNotHasKey( 'sanitized_paths', $r );
		$this->assertSame( 'kept', $r['sanitized_report'][0]['inline_markup'] );
		$this->assertSame( [ 'style', 'svg', 'path' ], $r['sanitized_report'][0]['kept_tags'] );
		$this->assertArrayNotHasKey( 'note', $r['sanitized_report'][0] );
	}

	public function test_kept_with_some_removed_lists_both(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( [
			'inline_policy' => fn() => IATO_MCP_Inline_Markup::KEPT,
			'inline_filter' => fn( string $v ) => [ 'ok' => true, 'html' => '<style>a{}</style>x', 'kept_tags' => [ 'style' ], 'removed_css' => [ 'url(https://evil.example/a.png)' ] ],
		] );
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ self::html_widget( '<style>a{background:url(https://evil.example/a.png)}</style><script>x</script>' ) ], [] );
		$this->assertSame( '<style>a{}</style>x', $r['elements'][0]['settings']['html'] );
		$this->assertSame( [ 'ab00069/settings/html' ], $r['changed'] );
		$this->assertSame( [ 'script' ], $r['report'][0]['removed_tags'] );
		$this->assertSame( [ 'url(https://evil.example/a.png)' ], $r['report'][0]['removed_css'] );
		$this->assertArrayNotHasKey( 'note', $r['report'][0], 'style text was not left on the page' );
	}

	public function test_a_value_failing_the_safety_check_falls_back_to_the_plain_rule(): void {
		IATO_MCP_Elementor_Sanitizer::set_functions( [
			'kses'          => fn( string $v ) => 'K[' . strip_tags( $v ) . ']',
			'inline_policy' => fn() => IATO_MCP_Inline_Markup::KEPT,
			'inline_filter' => fn( string $v ) => [ 'ok' => false, 'html' => '', 'kept_tags' => [], 'removed_css' => [] ],
		] );
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ self::html_widget( '<svg></svg>x' ) ], [] );
		$this->assertSame( 'K[x]', $r['elements'][0]['settings']['html'] );
		$this->assertSame( 'stripped_unsafe', $r['report'][0]['inline_markup'] );
	}

	/** @return array<string,array{0:array}> */
	public static function not_the_html_setting(): array {
		$svg = '<svg></svg><p>x</p>';
		return [
			'heading widget'         => [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'html' => $svg ], 'elements' => [] ] ],
			'text editor'            => [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => $svg ], 'elements' => [] ] ],
			'html in styles'         => [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [], 'styles' => [ 'html' => $svg ], 'elements' => [] ] ],
			'html in editor_settings' => [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [], 'editor_settings' => [ 'html' => $svg ], 'elements' => [] ] ],
			'nested html key'        => [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'deep' => [ 'html' => $svg ] ], 'elements' => [] ] ],
			'other setting'          => [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'title' => $svg ], 'elements' => [] ] ],
			'not a widget'           => [ [ 'id' => 'a', 'elType' => 'container', 'widgetType' => 'html', 'settings' => [ 'html' => $svg ], 'elements' => [] ] ],
			'no element type'        => [ [ 'id' => 'a', 'widgetType' => 'html', 'settings' => [ 'html' => $svg ], 'elements' => [] ] ],
			'atomic element'         => [ [ 'id' => 'a', 'elType' => 'e-paragraph', 'settings' => [ 'html' => $svg ], 'elements' => [] ] ],
		];
	}

	#[DataProvider( 'not_the_html_setting' )]
	public function test_the_opt_in_touches_no_other_value( array $element ): void {
		$asked = 0;
		IATO_MCP_Elementor_Sanitizer::set_functions( [
			'kses'          => fn( string $v ) => strip_tags( $v, '<p>' ),
			'inline_policy' => function () use ( &$asked ) { ++$asked; return IATO_MCP_Inline_Markup::KEPT; },
			'inline_filter' => fn( string $v ) => [ 'ok' => true, 'html' => $v, 'kept_tags' => [ 'svg' ], 'removed_css' => [] ],
		] );
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ $element ], [] );
		$this->assertSame( 0, $asked );
		$this->assertStringNotContainsString( '<svg', (string) wp_json_encode( $r['elements'] ) );
		$this->assertArrayNotHasKey( 'inline_markup', $r['report'][0] );
	}

	public function test_page_settings_cannot_reach_the_opt_in(): void {
		$asked = 0;
		IATO_MCP_Elementor_Sanitizer::set_functions( [ 'kses' => fn( string $v ) => strip_tags( $v ), 'inline_policy' => function () use ( &$asked ) { ++$asked; return IATO_MCP_Inline_Markup::KEPT; } ] );
		IATO_MCP_Elementor_Sanitizer::diff_settings( [ 'settings' => [ 'html' => '<svg></svg>' ] ], null, 'x' );
		$this->assertSame( 0, $asked );
	}

	public function test_an_untouched_html_widget_is_not_read_at_all(): void {
		$el = self::html_widget( '<style>a{}</style>' );
		$r  = IATO_MCP_Elementor_Sanitizer::diff_tree( [ $el ], [ $el ] );
		$this->assertSame( [], $r['report'] );
		$this->assertSame( '<style>a{}</style>', $r['elements'][0]['settings']['html'] );
	}

	public function test_a_changed_widget_type_counts_as_new_and_gets_the_plain_rule_on_html_only_when_it_is_html(): void {
		$stored = [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<svg></svg>' ], 'elements' => [] ] ];
		$new    = [ [ 'id' => 'a', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'html' => '<svg></svg>' ], 'elements' => [] ] ];
		$r      = IATO_MCP_Elementor_Sanitizer::diff_tree( $new, $stored );
		$this->assertSame( '', $r['elements'][0]['settings']['html'] );
	}

	/** @return array<string,array{0:array,1:string,2:string}> */
	public static function other_rules(): array {
		return [
			'url'        => [ [ 'link' => [ 'url' => 'javascript:alert(1)' ] ], 'url', 'h/settings/link/url' ],
			'custom css' => [ [ 'custom_css' => 'a{} </style><script>x</script>' ], 'css', 'h/settings/custom_css' ],
			'markup'     => [ [ 'title' => '<script>x</script>' ], 'html', 'h/settings/title' ],
		];
	}

	#[DataProvider( 'other_rules' )]
	public function test_each_altered_value_gets_an_entry_with_its_rule( array $settings, string $rule, string $path ): void {
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( [ [ 'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => $settings, 'elements' => [] ] ], [] );
		$this->assertSame( [ $path ], array_column( $r['report'], 'path' ) );
		$this->assertSame( $rule, $r['report'][0]['rule'] );
	}

	public function test_paths_and_report_stay_in_step_and_both_cap(): void {
		$tree = [];
		for ( $i = 0; $i < 55; $i++ ) {
			$tree[] = [ 'id' => "h{$i}", 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => '<script>x</script>' ], 'elements' => [] ];
		}
		$r = IATO_MCP_Elementor_Sanitizer::diff_tree( $tree, [] );
		$this->assertCount( 51, $r['changed'] );
		$this->assertCount( 51, $r['report'] );
		$this->assertSame( '… and 5 more', $r['changed'][50] );
		$this->assertSame( '… and 5 more', $r['report'][50]['path'] );
	}

	public function test_two_passes_in_one_request_do_not_share_a_report(): void {
		$tree = [ [ 'id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => '<script>x</script>' ], 'elements' => [] ] ];
		IATO_MCP_Elementor_Sanitizer::diff_tree( $tree, [] );
		$this->assertCount( 1, IATO_MCP_Elementor_Sanitizer::diff_tree( $tree, [] )['report'] );
	}

	// ── What Elementor's own save removed ─────────────────────────────────

	public function test_compare_stored_reports_what_the_save_removed_from_untouched_widgets(): void {
		$sent   = [ [ 'id' => 's', 'elType' => 'section', 'settings' => [], 'elements' => [ self::html_widget( '<iframe src="x"></iframe><p onclick="y">a</p>', 'w1' ), [ 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Tom & Jerry' ], 'elements' => [] ] ] ] ];
		$stored = [ [ 'id' => 's', 'elType' => 'section', 'settings' => [], 'isInner' => false, 'elements' => [ self::html_widget( '<p>a</p>', 'w1' ), [ 'id' => 'w2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Tom &amp; Jerry' ], 'elements' => [] ] ] ] ];
		$report = IATO_MCP_Elementor_Sanitizer::compare_stored( $sent, $stored );
		$this->assertCount( 1, $report, 'entity encoding is not a removal' );
		$this->assertSame( 'w1/settings/html', $report[0]['path'] );
		$this->assertSame( [ 'iframe' ], $report[0]['removed_tags'] );
		$this->assertSame( [ 'onclick' ], $report[0]['removed_attributes'] );
		$this->assertSame( 'elementor', $report[0]['source'] );
	}

	public function test_compare_stored_is_quiet_when_nothing_was_lost(): void {
		$tree = [ self::html_widget( '<p>a</p>' ) ];
		$this->assertSame( [], IATO_MCP_Elementor_Sanitizer::compare_stored( $tree, $tree ) );
	}

	// ── Meta policy ───────────────────────────────────────────────────────

	public function test_array_meta_reports_what_was_removed(): void {
		$s = IATO_MCP_Meta_Policy::sanitize_array_value( 'my_key', [ 'a' => '<script>x</script>', 'deep' => [ 'b' => '<i>y</i>' ], 'same' => '<svg></svg>' ], [ 'same' => '<svg></svg>' ] );
		$this->assertSame( [ 'my_key/a', 'my_key/deep/b' ], $s['changed'] );
		$this->assertSame( [ 'my_key/a', 'my_key/deep/b' ], array_column( $s['report'], 'path' ) );
		$this->assertSame( [ 'script' ], $s['report'][0]['removed_tags'] );
	}

	public function test_page_settings_report_what_was_removed(): void {
		$s = IATO_MCP_Meta_Policy::sanitize_elementor_value( '_elementor_page_settings', [ 'custom_css' => 'a{} <script>x</script>' ], null );
		$this->assertSame( [ '_elementor_page_settings/custom_css' ], $s['changed'] );
		$this->assertSame( 'css', $s['report'][0]['rule'] );
		$this->assertSame( [ 'script' ], $s['report'][0]['removed_tags'] );
	}

	public function test_scalars_report_nothing(): void {
		$this->assertSame( [], IATO_MCP_Meta_Policy::sanitize_array_value( 'k', 'text', null )['report'] );
		$this->assertSame( [], IATO_MCP_Meta_Policy::sanitize_elementor_value( 'k', 'text', null )['report'] );
	}
}

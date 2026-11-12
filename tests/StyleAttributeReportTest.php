<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * 1.21.0: declarations kses takes out of style attributes are named in removed_css,
 * as CSS taken out of a style block already was. Before, a style attribute that kept
 * any declaration left no trace in the report.
 */
final class StyleAttributeReportTest extends TestCase {

	public function test_declarations_are_split_as_wordpress_splits_them(): void {
		$this->assertSame( [ 'color:red', 'margin:0 auto' ], IATO_MCP_Sanitize_Report::declarations( ' Color : red ;margin:0 auto; ' ) );
		$this->assertSame( [ 'background:url("a")' ], IATO_MCP_Sanitize_Report::declarations( 'background:url(&quot;a&quot;)' ), 'kses re-encodes quotes' );
		$this->assertSame( [], IATO_MCP_Sanitize_Report::declarations( '' ) );
	}

	public function test_a_dropped_declaration_is_named_with_its_tag(): void {
		$before = '<div style="color:rgb(1,2,3);margin:0">a</div><p style="mix-blend-mode:screen">b</p>';
		$after  = '<div style="margin:0">a</div><p>b</p>';
		$this->assertSame( [ 'style on <div>: color:rgb(1,2,3)', 'style on <p>: mix-blend-mode:screen' ], IATO_MCP_Sanitize_Report::removed_declarations( $before, $after ) );
	}

	public function test_a_removed_tag_takes_its_declarations_with_it(): void {
		$this->assertSame( [], IATO_MCP_Sanitize_Report::removed_declarations( '<p>x</p><form style="color:red"></form>', '<p>x</p>' ) );
	}

	public function test_reformatting_is_not_a_removal(): void {
		$this->assertSame( [], IATO_MCP_Sanitize_Report::removed_declarations( '<div style="color: red; margin: 0;">a</div>', '<div style="color: red;margin: 0">a</div>' ) );
	}

	public function test_the_entry_lists_them_in_removed_css(): void {
		$entry = IATO_MCP_Sanitize_Report::entry( 'e1/settings/html', 'html', '<div style="color:rgb(1,2,3);margin:0">a</div>', '<div style="margin:0">a</div>' );
		$this->assertSame( [ 'style on <div>: color:rgb(1,2,3)' ], $entry['removed_css'] );
		$this->assertSame( [], $entry['removed_tags'] );
		$this->assertTrue( IATO_MCP_Sanitize_Report::is_change( $entry ) );
	}

	public function test_they_join_what_a_style_block_lost(): void {
		$entry = IATO_MCP_Sanitize_Report::entry( 'e1/settings/html', 'html', '<div style="z:1;margin:0">a</div>', '<div style="margin:0">a</div>', [ 'removed_css' => [ '@import url(x.css);' ] ] );
		$this->assertSame( [ '@import url(x.css);', 'style on <div>: z:1' ], $entry['removed_css'] );
	}

	public function test_for_value_reports_a_value_that_only_lost_declarations(): void {
		$this->assertNotNull( IATO_MCP_Sanitize_Report::for_value( 'content', 'html', '<p style="color:red;position:fixed">a</p>', '<p style="color:red">a</p>' ) );
		$this->assertNull( IATO_MCP_Sanitize_Report::for_value( 'content', 'html', '<p style="color:red">a</p>', '<p style="color:red">a</p>' ) );
	}

	public function test_plain_text_rules_are_not_read_for_style_attributes(): void {
		$entry = IATO_MCP_Sanitize_Report::entry( 'e1/settings/title', 'text', '<b style="x:1">a</b>', '<b>a</b>' );
		$this->assertArrayNotHasKey( 'removed_css', $entry );
	}
}

<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once IATO_MCP_DIR . 'includes/tools-selection.php';

/**
 * Per-tool selection: the "bridge tools saved as off" fix (Sprint C0 Part 2).
 */
final class SettingsToolsTest extends TestCase {

	private const BRIDGE = [
		'get_iato_sitemap', 'get_iato_nav_audit', 'get_iato_orphan_pages', 'get_iato_taxonomy',
		'get_iato_seo_fixes', 'get_iato_content_gaps', 'get_iato_broken_links', 'get_iato_suggestions',
		'get_iato_perf_report', 'start_iato_crawl', 'get_iato_crawl_status', 'list_iato_crawls',
	];
	private const WP_TOOLS = [ 'get_site_info', 'get_posts', 'get_post', 'create_post', 'rollback' ];

	public function test_bridge_tool_names_are_the_twelve(): void {
		$this->assertSame( self::BRIDGE, iato_mcp_bridge_tool_names() );
	}

	public function test_fresh_install_means_all_on(): void {
		$this->assertTrue( iato_mcp_tool_was_enabled( 'get_iato_sitemap', null ) );
		$this->assertTrue( iato_mcp_tool_was_enabled( 'get_iato_sitemap', [] ) );
		$this->assertFalse( iato_mcp_tool_was_enabled( 'get_iato_sitemap', [ 'get_posts' ] ) );
	}

	public function test_settings_saved_before_key_keeps_bridge_tools_on(): void {
		// Fresh install (option empty), no key: the form posts only the WP tools
		// because the bridge checkboxes were disabled.
		$saved = iato_mcp_merge_gated_tools( self::WP_TOOLS, [], true );
		$this->assertSame( array_merge( self::WP_TOOLS, self::BRIDGE ), $saved );
		foreach ( self::BRIDGE as $tool ) {
			$this->assertTrue( iato_mcp_tool_was_enabled( $tool, $saved ), $tool );
		}
	}

	public function test_key_added_later_then_saved_lets_the_form_govern(): void {
		// Previous save (from the test above) has everything on. Now the key is
		// set, the bridge checkboxes are enabled, and the user unchecks one.
		$previous = array_merge( self::WP_TOOLS, self::BRIDGE );
		$posted   = array_values( array_diff( $previous, [ 'get_iato_seo_fixes' ] ) );
		$saved    = iato_mcp_merge_gated_tools( $posted, $previous, false );
		$this->assertSame( $posted, $saved );
		$this->assertFalse( iato_mcp_tool_was_enabled( 'get_iato_seo_fixes', $saved ) );
		$this->assertTrue( iato_mcp_tool_was_enabled( 'get_iato_broken_links', $saved ) );
	}

	public function test_deliberate_off_survives_a_later_gated_save(): void {
		// Key present: user switched one bridge tool off. Later the key is removed
		// and the form is saved again with the bridge checkboxes disabled: the
		// deliberate off must not be resurrected, the others must stay on.
		$previous = array_values( array_diff( array_merge( self::WP_TOOLS, self::BRIDGE ), [ 'get_iato_seo_fixes' ] ) );
		$saved    = iato_mcp_merge_gated_tools( self::WP_TOOLS, $previous, true );
		$this->assertFalse( in_array( 'get_iato_seo_fixes', $saved, true ), 'deliberately-off tool stays off' );
		foreach ( array_diff( self::BRIDGE, [ 'get_iato_seo_fixes' ] ) as $tool ) {
			$this->assertContains( $tool, $saved, "$tool preserved" );
		}
	}

	public function test_gated_save_never_adds_a_bridge_tool_that_was_off(): void {
		$previous = self::WP_TOOLS; // bridge tools all off before (broken state or deliberate)
		$saved    = iato_mcp_merge_gated_tools( self::WP_TOOLS, $previous, true );
		$this->assertSame( self::WP_TOOLS, $saved, 'merge preserves state; it does not repair' );
	}

	public function test_merge_dedupes_and_keeps_posted_order(): void {
		$saved = iato_mcp_merge_gated_tools( [ 'get_post', 'get_posts', 'get_post' ], [], false );
		$this->assertSame( [ 'get_post', 'get_posts' ], $saved );
	}

	public function test_posted_bridge_tools_are_kept_even_when_gated(): void {
		// Hidden inputs emitted for gated-but-enabled bridge tools arrive in $posted.
		$saved = iato_mcp_merge_gated_tools( array_merge( self::WP_TOOLS, [ 'list_iato_crawls' ] ), [ 'get_posts', 'list_iato_crawls' ], true );
		$this->assertContains( 'list_iato_crawls', $saved );
		$this->assertNotContains( 'get_iato_sitemap', $saved, 'was off before, not posted, stays off' );
	}

	// ── Repair of existing sites ──────────────────────────────────────────

	public function test_repair_adds_all_twelve_when_none_present(): void {
		$r = iato_mcp_repair_bridge_tools( self::WP_TOOLS );
		$this->assertTrue( $r['repaired'] );
		$this->assertSame( array_merge( self::WP_TOOLS, self::BRIDGE ), $r['tools'] );
	}

	public function test_repair_leaves_a_deliberate_selection_alone(): void {
		$list = array_merge( self::WP_TOOLS, [ 'list_iato_crawls' ] );
		$r    = iato_mcp_repair_bridge_tools( $list );
		$this->assertFalse( $r['repaired'] );
		$this->assertSame( $list, $r['tools'] );
	}

	public function test_repair_leaves_empty_all_on_alone(): void {
		$this->assertSame( [ 'tools' => [], 'repaired' => false ], iato_mcp_repair_bridge_tools( [] ) );
		$this->assertSame( [ 'tools' => [], 'repaired' => false ], iato_mcp_repair_bridge_tools( null ) );
	}

	public function test_repair_is_idempotent(): void {
		$once  = iato_mcp_repair_bridge_tools( self::WP_TOOLS )['tools'];
		$twice = iato_mcp_repair_bridge_tools( $once );
		$this->assertFalse( $twice['repaired'] );
		$this->assertSame( $once, $twice['tools'] );
	}
}

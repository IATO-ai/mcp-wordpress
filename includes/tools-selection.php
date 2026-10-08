<?php
/**
 * Per-tool enable/disable selection helpers (pure functions, no WordPress
 * dependencies) shared by the Settings page save paths and the upgrade
 * migration. Kept out of IATO_MCP_Settings so they can be unit-tested
 * without a WordPress bootstrap.
 *
 * Background — the "bridge tools saved as off" bug: the Tools card renders the
 * twelve IATO bridge tools as disabled checkboxes while no IATO API key is
 * set. Browsers never submit disabled inputs, so a save made before the key
 * exists stored `iato_mcp_tools` without the bridge tools, and once a key was
 * added they counted as switched off (on the MCP endpoint and as abilities).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * The twelve IATO bridge tools (everything under the "IATO Platform" and
 * "Crawl Management" categories). Mirrors IATO_MCP_Settings::IATO_BRIDGE_TOOLS.
 *
 * @return string[]
 */
function iato_mcp_bridge_tool_names(): array {
	return [
		'get_iato_sitemap',
		'get_iato_nav_audit',
		'get_iato_orphan_pages',
		'get_iato_taxonomy',
		'get_iato_seo_fixes',
		'get_iato_content_gaps',
		'get_iato_broken_links',
		'get_iato_suggestions',
		'get_iato_perf_report',
		'start_iato_crawl',
		'get_iato_crawl_status',
		'list_iato_crawls',
	];
}

/**
 * Was a tool enabled under a saved `iato_mcp_tools` value? An empty / missing
 * value means "all tools on" (fresh install), matching
 * IATO_MCP_Settings::is_tool_enabled().
 */
function iato_mcp_tool_was_enabled( string $tool, ?array $saved ): bool {
	if ( empty( $saved ) ) {
		return true;
	}
	return in_array( $tool, $saved, true );
}

/**
 * Merge a posted tool list with the previous selection for tools the form
 * could not submit.
 *
 * When the bridge tools were gated (rendered disabled because no IATO API key
 * existed when the form was rendered), their checkboxes are absent from the
 * POST whatever their state, so each bridge tool missing from $posted keeps
 * its previous state instead of being treated as unchecked. When the bridge
 * tools were not gated, the user could toggle them, so $posted is the truth
 * and an unchecked bridge tool stays off.
 *
 * @param string[]      $posted       Sanitised tool names from the request.
 * @param string[]|null $previous     Previously saved option value (null / [] = all on).
 * @param bool          $bridge_gated Were the bridge checkboxes disabled when the form was rendered?
 * @return string[] Final list to store (values re-indexed, order of $posted preserved, preserved bridge tools appended).
 */
function iato_mcp_merge_gated_tools( array $posted, ?array $previous, bool $bridge_gated ): array {
	$result = array_values( array_unique( $posted ) );
	if ( ! $bridge_gated ) {
		return $result;
	}
	foreach ( iato_mcp_bridge_tool_names() as $tool ) {
		if ( ! in_array( $tool, $result, true ) && iato_mcp_tool_was_enabled( $tool, $previous ) ) {
			$result[] = $tool;
		}
	}
	return $result;
}

/**
 * One-shot repair for sites already in the broken state.
 *
 * The data cannot distinguish "saved before a key existed" from "deliberately
 * switched off every IATO tool", so the heuristic is: a non-empty saved list
 * that contains NONE of the twelve bridge tools is treated as the bug and gets
 * all twelve added. A list that contains at least one bridge tool reflects a
 * form where the bridge checkboxes were enabled, so it is a deliberate
 * selection and is left alone. An empty list already means "all on".
 *
 * @param string[]|null $saved Current option value.
 * @return array{tools:string[],repaired:bool}
 */
function iato_mcp_repair_bridge_tools( ?array $saved ): array {
	if ( empty( $saved ) ) {
		return [ 'tools' => is_array( $saved ) ? $saved : [], 'repaired' => false ];
	}
	$bridge = iato_mcp_bridge_tool_names();
	if ( array_intersect( $bridge, $saved ) ) {
		return [ 'tools' => $saved, 'repaired' => false ];
	}
	return [ 'tools' => array_values( array_merge( $saved, $bridge ) ), 'repaired' => true ];
}

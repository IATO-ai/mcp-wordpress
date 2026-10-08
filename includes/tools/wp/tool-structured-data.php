<?php
/**
 * WP Tool: update_structured_data
 *
 * Stores JSON-LD structured data as post meta and outputs it in wp_head.
 * Uses a custom meta key (_iato_mcp_structured_data) to avoid SEO plugin conflicts.
 * Includes write-with-rollback support (change receipt).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON flags that make a value safe inside a <script> element: every < > & '
 * and " is emitted as a \uXXXX escape, so the text "</script>" (or "<!--")
 * can never appear in the output and the block cannot be closed early. The
 * result is still valid JSON for every JSON-LD consumer.
 */
const IATO_MCP_JSON_LD_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

/**
 * Encode stored structured data for output inside <script type="application/ld+json">.
 *
 * Applied at render time, so values stored before this encoding existed are
 * neutralised too. Returns null when the stored value is not a JSON object or
 * array (nothing is printed).
 */
function iato_mcp_structured_data_render( string $stored ): ?string {
	$decoded = json_decode( $stored );
	if ( ! is_object( $decoded ) && ! is_array( $decoded ) ) {
		return null;
	}
	$json = wp_json_encode( $decoded, IATO_MCP_JSON_LD_FLAGS );
	return is_string( $json ) ? $json : null;
}

/**
 * Validate an incoming schema_json and return the normalised string to store.
 *
 * Accepts a JSON object or array (JSON-LD is one of the two; scalars are
 * rejected) and refuses any value that, decoded, contains a "<script",
 * "</script" or "<!--" sequence. Output is already neutralised by the render
 * encoding; this keeps such payloads out of the database and the receipts.
 *
 * @return string|WP_Error The compact, <script>-safe JSON to store.
 */
function iato_mcp_structured_data_validate( string $schema_json ): string|WP_Error {
	$decoded = json_decode( $schema_json );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error( 'invalid_json', 'schema_json is not valid JSON.' );
	}
	if ( ! is_object( $decoded ) && ! is_array( $decoded ) ) {
		return new WP_Error( 'invalid_schema_json', 'schema_json must be a JSON object or an array of objects.' );
	}
	// Check the decoded content, not the raw input: "\u003c/script" in the
	// input is "</script" once decoded.
	$plain = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( ! is_string( $plain ) || preg_match( '#<\s*/?\s*script|<!--#i', $plain ) ) {
		return new WP_Error( 'unsafe_schema_json', 'schema_json must not contain <script>, </script> or <!-- sequences.' );
	}
	$normalized = wp_json_encode( $decoded, IATO_MCP_JSON_LD_FLAGS );
	if ( ! is_string( $normalized ) ) {
		return new WP_Error( 'invalid_json', 'schema_json could not be encoded.' );
	}
	return $normalized;
}

// ── Output JSON-LD in wp_head when meta exists ──────────────────────────────

add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$schema = get_post_meta( $post_id, '_iato_mcp_structured_data', true );
	if ( empty( $schema ) ) {
		return;
	}

	$json = iato_mcp_structured_data_render( (string) $schema );
	if ( null === $json ) {
		return;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with the JSON_HEX_* flags: no raw < > & ' " can reach the page, so the script block cannot be closed early.
	echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
} );

// ── update_structured_data ──────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_structured_data',
	[
		'description' => 'Set or update JSON-LD structured data for a post or page. Stored as post meta and output in wp_head.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer', 'description' => 'Post ID (required).' ],
				'schema_json' => [ 'type' => 'string',  'description' => 'Valid JSON-LD string (required).' ],
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'post_id', 'schema_json' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$post_id     = absint( $args['post_id'] ?? 0 );
		$schema_json = $args['schema_json'] ?? '';
		$dry_run     = ! empty( $args['dry_run'] );

		if ( ! $post_id ) return new WP_Error( 'missing_post_id', 'post_id is required.' );
		if ( empty( $schema_json ) ) return new WP_Error( 'missing_schema_json', 'schema_json is required.' );

		// Clients sometimes send the schema as a JSON object rather than a string.
		if ( is_array( $schema_json ) || is_object( $schema_json ) ) {
			$schema_json = wp_json_encode( $schema_json );
		}
		if ( ! is_string( $schema_json ) ) {
			return new WP_Error( 'invalid_json', 'schema_json must be a JSON string.' );
		}

		// Validate: a JSON object or array with no <script> / <!-- sequences,
		// normalised to the same <script>-safe encoding the frontend prints.
		$normalized = iato_mcp_structured_data_validate( $schema_json );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $post_id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}

		$before = get_post_meta( $post_id, '_iato_mcp_structured_data', true );
		$before = '' !== $before ? $before : null;

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'      => true,
				'post_id'      => $post_id,
				'before_value' => $before,
				'schema_json'  => $schema_json,
			] );
		}

		// wp_slash: update_post_meta() unslashes its value, which would strip the
		// backslashes of the JSON escape sequences.
		update_post_meta( $post_id, '_iato_mcp_structured_data', wp_slash( $normalized ) );

		$receipt = IATO_MCP_Change_Receipt::record( $post_id, 'page', 'structured_data', $before, $normalized );

		$data = [
			'post_id'     => $post_id,
			'schema_json' => $normalized,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

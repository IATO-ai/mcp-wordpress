<?php
/**
 * WP Tools: get_custom_css, update_custom_css
 *
 * The active theme's Additional CSS. See IATO_MCP_Custom_CSS for who may write it and
 * what is refused.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'get_custom_css',
	[
		'description' => 'Returns the active theme\'s Additional CSS and the ID of the post it is stored in (null when it has none). Requires edit_theme_options.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => (object) [],
			'required'   => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		$stylesheet = get_stylesheet();
		$stored     = IATO_MCP_Custom_CSS::stored( $stylesheet );
		return IATO_MCP_Server::ok(
			[
				'stylesheet' => $stylesheet,
				'post_id'    => $stored['post_id'] > 0 ? $stored['post_id'] : null,
				'css'        => (string) wp_get_custom_css( $stylesheet ),
				'bytes'      => strlen( $stored['css'] ),
			]
		);
	}
);

IATO_MCP_Server::register_tool(
	'update_custom_css',
	[
		'description' => 'Sets the active theme\'s Additional CSS, which prints on every page. Send css to replace it all, or append_css to add to the end. At most 200 KB. CSS that loads anything from another site (@import, or url() to another host) is refused with css_not_allowed and nothing is stored: a person can add that in the Customizer. Anything that would close the style element is removed and listed in sanitized_report. Accepts dry_run. Returns the sizes and hashes, a change_receipt (roll back with the rollback tool, which stores the full previous CSS) and a cache object: the whole site is purged. Requires an administrator with a WordPress user behind the credential.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'css'        => [ 'type' => 'string', 'description' => 'The full replacement. An empty string clears it.' ],
				'append_css' => [ 'type' => 'string', 'description' => 'Added to the end of what is there.' ],
				'dry_run'    => [ 'type' => 'boolean', 'description' => 'Report what would change without writing (default: false).' ],
			],
			'required'   => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$may = IATO_MCP_Custom_CSS::may_write();
		if ( is_wp_error( $may ) ) {
			return $may;
		}

		$has_css    = array_key_exists( 'css', $args ) && null !== $args['css'];
		$has_append = array_key_exists( 'append_css', $args ) && null !== $args['append_css'];
		if ( $has_css === $has_append ) {
			return IATO_MCP_Server::refusal( new WP_Error( 'invalid_css_input', 'Send css to replace the CSS, or append_css to add to it: one of them. Nothing was written.' ) );
		}
		$sent = $has_css ? $args['css'] : $args['append_css'];
		if ( ! is_string( $sent ) ) {
			return IATO_MCP_Server::refusal( new WP_Error( 'invalid_css_input', 'The CSS must be text. Nothing was written.' ) );
		}

		// First: judging a very large block takes a lot of memory, and a fatal would hide this.
		if ( strlen( $sent ) > IATO_MCP_Custom_CSS::MAX_BYTES ) {
			return IATO_MCP_Server::refusal(
				new WP_Error(
					'css_too_large',
					sprintf( 'This CSS is %1$d bytes, and the most is %2$d. Nothing was written.', strlen( $sent ), IATO_MCP_Custom_CSS::MAX_BYTES ),
					[ 'bytes' => strlen( $sent ), 'max_bytes' => IATO_MCP_Custom_CSS::MAX_BYTES ]
				)
			);
		}

		$stylesheet = get_stylesheet();
		$stored     = IATO_MCP_Custom_CSS::stored( $stylesheet );

		$clean = IATO_MCP_Custom_CSS::strip_style_close( $sent );
		// Only what this call adds is judged: what is there may be a person's, from the Customizer.
		$refused = IATO_MCP_Custom_CSS::refused_parts( $clean );
		if ( [] !== $refused ) {
			return IATO_MCP_Server::refusal(
				new WP_Error(
					'css_not_allowed',
					'This CSS cannot be stored here, because of what is listed in found. Remove it, or have a person add it in the Customizer under Additional CSS. Nothing was written.',
					[ 'found' => array_values( $refused ) ]
				)
			);
		}

		// What was judged has to mean the same once joined. A comment, string or url() left
		// open in what is stored would swallow part of it and bring the rest to life.
		if ( $has_append && ! IATO_MCP_Inline_Markup::css_is_readable( $stored['css'] ) ) {
			return IATO_MCP_Server::refusal(
				new WP_Error(
					'stored_css_unreadable',
					'The CSS already stored ends inside a comment, string or url(), so what is added would be read as part of it. Send the whole CSS with css instead, or have a person fix it in the Customizer. Nothing was written.'
				)
			);
		}

		$css = $has_css ? $clean : ( '' === $stored['css'] ? $clean : $stored['css'] . "\n" . $clean );
		// In case the join makes one.
		$css = IATO_MCP_Custom_CSS::strip_style_close( $css );

		if ( IATO_MCP_Custom_CSS::printed_differently( $css ) ) {
			return IATO_MCP_Server::refusal(
				new WP_Error(
					'css_not_allowed',
					'WordPress below 7.1 removes anything shaped like a tag when it prints this CSS, comments included, which would change what the rest means. Remove it, or have a person fix it in the Customizer. Nothing was written.',
					[ 'found' => [ 'text shaped like a tag, such as <a, perhaps in a comment' ] ]
				)
			);
		}

		$bytes = strlen( $css );
		if ( $bytes > IATO_MCP_Custom_CSS::MAX_BYTES ) {
			return IATO_MCP_Server::refusal(
				new WP_Error(
					'css_too_large',
					sprintf( 'This would make the CSS %1$d bytes, and the most is %2$d. Nothing was written.', $bytes, IATO_MCP_Custom_CSS::MAX_BYTES ),
					[ 'bytes' => $bytes, 'max_bytes' => IATO_MCP_Custom_CSS::MAX_BYTES ]
				)
			);
		}

		$report = $clean !== $sent ? [ IATO_MCP_Sanitize_Report::entry( $has_css ? 'css' : 'append_css', 'css', $sent, $clean, [ 'removed_css' => [ '</style' ] ] ) ] : [];
		$sizes  = [
			'stylesheet'   => $stylesheet,
			'bytes_before' => strlen( $stored['css'] ),
			'bytes_after'  => $bytes,
		];

		if ( ! empty( $args['dry_run'] ) ) {
			$data = [
				'dry_run'     => true,
				'action'      => $css === $stored['css'] ? 'no_change' : 'would_update',
				'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED,
			] + $sizes;
			IATO_MCP_Sanitize_Report::add_to( $data, $report );
			return IATO_MCP_Server::ok( $data );
		}

		$data = $sizes;
		if ( $css !== $stored['css'] ) {
			$before = IATO_MCP_Custom_CSS::before_value( $stylesheet );
			$saved  = IATO_MCP_Custom_CSS::write( $stylesheet, $css );
			if ( is_wp_error( $saved ) ) {
				return IATO_MCP_Server::refusal( $saved );
			}
			$after   = IATO_MCP_Custom_CSS::after_value( $stylesheet );
			$receipt = IATO_MCP_Change_Receipt::record( null, IATO_MCP_Custom_CSS::RECEIPT, IATO_MCP_Custom_CSS::RECEIPT, $before, $after );

			$data['post_id']       = $saved;
			$data['bytes_after']   = $after['bytes'];
			$data['sha256_before'] = hash( 'sha256', $stored['css'] );
			$data['sha256_after']  = $after['sha256'];
			// Short: the full CSS each way would swamp the transport.
			$data['change_receipt'] = IATO_MCP_Change_Receipt::slim( $receipt );
			if ( hash( 'sha256', $css ) !== $after['sha256'] ) {
				$data['warnings'] = [ 'WordPress or another plugin changed this CSS as it was saved. Read it back with get_custom_css.' ];
			}
		}
		IATO_MCP_Sanitize_Report::add_to( $data, $report );
		return IATO_MCP_Server::ok( $data );
	}
);

<?php
/**
 * WP Tools: get_post_meta, update_post_meta
 *
 * Generic post_meta read/write with a centralised key denylist/allowlist
 * (IATO_MCP_Meta_Policy). Every write records a change_receipt under the
 * `post_meta` target_type so rollback can reverse it.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_post_meta ────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_post_meta',
	[
		'description' => 'Read post meta for a given post. Pass key to read a single value; omit to read all. Credential-shaped keys are always redacted; underscore-prefixed keys outside the known-safe builder/theme/SEO allowlist are hidden unless include_protected=true.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'                => [ 'type' => 'integer', 'description' => 'WordPress post ID (required).' ],
				'key'               => [ 'type' => 'string',  'description' => 'If set, return only this key.' ],
				'include_protected' => [ 'type' => 'boolean', 'description' => 'If true, include underscore-prefixed keys outside the safe allowlist (still respects the denylist).' ],
			],
			'required' => [ 'id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id = absint( $args['id'] ?? 0 );
		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$include_protected = ! empty( $args['include_protected'] );
		$key               = isset( $args['key'] ) ? (string) $args['key'] : '';

		if ( '' !== $key ) {
			if ( IATO_MCP_Meta_Policy::is_denied( $key ) ) {
				return new WP_Error(
					'meta_redacted',
					sprintf( 'Meta key %s is in the security denylist.', $key ),
					[ 'key' => $key ]
				);
			}
			if ( ! $include_protected && '_' === ( $key[0] ?? '' ) && ! IATO_MCP_Meta_Policy::is_allowed_without_force( $key ) ) {
				return new WP_Error(
					'meta_protected',
					sprintf( 'Meta key %s is underscore-prefixed and outside the safe allowlist. Pass include_protected=true to read it.', $key ),
					[ 'key' => $key ]
				);
			}
			$value = get_post_meta( $post_id, $key, true );
			return IATO_MCP_Server::ok( [
				'id'    => $post_id,
				'key'   => $key,
				'value' => $value,
			] );
		}

		// Read all and flatten single-element arrays for ergonomic responses.
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			$all = [];
		}
		$flat = [];
		foreach ( $all as $meta_key => $values ) {
			if ( is_array( $values ) && count( $values ) === 1 ) {
				$single = $values[0];
				// Best-effort unserialize for stored arrays/objects.
				$maybe  = maybe_unserialize( $single );
				$flat[ $meta_key ] = $maybe;
			} else {
				$flat[ $meta_key ] = $values;
			}
		}

		[ $filtered, $redacted ] = IATO_MCP_Meta_Policy::redact_for_read( $flat, $include_protected );

		return IATO_MCP_Server::ok( [
			'id'             => $post_id,
			'meta'           => $filtered,
			'redacted_keys'  => $redacted,
		] );
	}
);

// ── update_post_meta ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_post_meta',
	[
		'description' => 'Update a single post meta key. value=null deletes the key. force=true is required for keys outside the known-safe theme/builder/SEO allowlist; credential-shaped keys are rejected unconditionally. Emits a change_receipt rollback-able under target_type=post_meta.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer', 'description' => 'WordPress post ID (required).' ],
				'key'     => [ 'type' => 'string',  'description' => 'Meta key (required).' ],
				'value'   => [ 'description' => 'New value. Pass null to delete. Strings, integers, booleans, arrays, and objects are accepted.' ],
				'force'   => [ 'type' => 'boolean', 'description' => 'Required to write keys outside the safe allowlist. Default false.' ],
				'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview without writing (default false).' ],
			],
			'required' => [ 'id', 'key' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_posts' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$post_id = absint( $args['id'] ?? 0 );
		$key     = isset( $args['key'] ) ? (string) $args['key'] : '';
		$force   = ! empty( $args['force'] );
		$dry_run = ! empty( $args['dry_run'] );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_id', 'id is required.' );
		}
		if ( '' === $key ) {
			return new WP_Error( 'missing_key', 'key is required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		// Lock check — refuse to stomp another editor mid-session.
		if ( function_exists( 'wp_check_post_lock' ) ) {
			$lock_user = wp_check_post_lock( $post_id );
			if ( $lock_user ) {
				return new WP_Error(
					'post_locked',
					'Post is currently being edited by another user.',
					[ 'lock_user_id' => $lock_user ]
				);
			}
		}

		$policy = IATO_MCP_Meta_Policy::check_write( $key, $force );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		// Capture before-value. Empty strings normalise to null per the receipt spec.
		$before = get_post_meta( $post_id, $key, true );
		$before = ( '' === $before ) ? null : maybe_unserialize( $before );

		$after_raw = $args['value'] ?? null;
		$delete    = array_key_exists( 'value', $args ) && null === $after_raw;

		// Sanitise the after-value by type. Strings get sanitize_text_field;
		// arrays/objects pass through (update_post_meta serialises them).
		if ( is_string( $after_raw ) ) {
			$after_val = sanitize_text_field( $after_raw );
		} elseif ( is_array( $after_raw ) || is_object( $after_raw ) ) {
			$after_val = $after_raw;
		} else {
			// bool, int, float, null pass straight through.
			$after_val = $after_raw;
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'      => true,
				'id'           => $post_id,
				'key'          => $key,
				'before_value' => $before,
				'after_value'  => $delete ? null : $after_val,
				'action'       => $delete ? 'would_delete' : 'would_update',
			] );
		}

		if ( $delete ) {
			delete_post_meta( $post_id, $key );
			$after_stored = null;
		} else {
			update_post_meta( $post_id, $key, $after_val );
			$after_stored = $after_val;
		}

		clean_post_cache( $post_id );

		// Elementor cache invalidation for any _elementor_* meta write.
		if ( 0 === stripos( $key, '_elementor_' ) ) {
			delete_post_meta( $post_id, '_elementor_css' );
			wp_cache_delete( $post_id, 'post_meta' );
			wp_cache_delete( $post_id, 'posts' );
			if ( class_exists( '\Elementor\Plugin' ) ) {
				$plugin = \Elementor\Plugin::$instance;
				if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
					$plugin->files_manager->clear_cache();
				}
			}
		}

		$receipt = IATO_MCP_Change_Receipt::record( $post_id, 'post_meta', $key, $before, $after_stored );

		$data = [
			'id'           => $post_id,
			'key'          => $key,
			'before_value' => $before,
			'after_value'  => $after_stored,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );
		return IATO_MCP_Server::ok( $data );
	}
);

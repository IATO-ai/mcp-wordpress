<?php
/**
 * Rollback conflict check (1.13.0).
 *
 * A rollback writes the receipt's before value back. If the object has
 * changed since the receipt was recorded (a person edited it in WordPress,
 * another tool call changed it, a later receipt on the same field), that
 * write would silently discard the newer change. check() reads the current
 * state of what the receipt changed and compares it with the receipt's
 * after value; IATO_MCP_Rollback refuses a rollback whose check is
 * `conflict` unless the caller passes force.
 *
 * Statuses:
 *   match          the object is as the receipt left it (or, for a create,
 *                  unchanged since; for a delete, still absent)
 *   conflict       it changed since; `current` and `expected` say how
 *   unverifiable   the current state cannot be read (redirect handlers
 *                  whose rules the plugin cannot read, unknown fields,
 *                  objects that no longer exist); the rollback proceeds
 *
 * Values are compared in a canonical form: '' and null are the same, JSON
 * text is decoded, scalars inside arrays compare as strings, object keys
 * are sorted. The record sites store what was actually written (read back
 * with the same readers this class uses), so a fresh receipt matches until
 * something changes the object.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Rollback_Conflict {

	/** Longest string returned in `current` / `expected` before it is cut. */
	public const MAX_SHOWN = 500;

	/**
	 * @return array{status:'match'|'conflict'|'unverifiable', reason:string, current?:mixed, expected?:mixed}
	 */
	public static function check( array $receipt ): array {
		$type    = (string) ( $receipt['target_type'] ?? '' );
		$field   = (string) ( $receipt['field'] ?? '' );
		$post_id = (int) ( $receipt['post_id'] ?? 0 );
		$after   = $receipt['after_value'] ?? null;

		switch ( $type ) {
			case 'post':
				if ( 'create' === $field ) {
					return self::created_object( $receipt, $post_id, 'post' );
				}
				$key = self::POST_FIELDS[ $field ] ?? null;
				if ( null === $key ) {
					return self::unverifiable( "unknown post field {$field}" );
				}
				$post = get_post( $post_id );
				if ( ! $post ) {
					return self::unverifiable( 'the post no longer exists' );
				}
				return self::compare( $post->{$key} ?? null, $after, "post {$field}" );

			case 'attachment':
				return 'create' === $field
					? self::created_object( $receipt, $post_id, 'attachment' )
					: self::unverifiable( "unknown attachment field {$field}" );

			case 'page':
				if ( ! get_post( $post_id ) ) {
					return self::unverifiable( 'the post no longer exists' );
				}
				switch ( $field ) {
					case 'title':
						return self::compare( IATO_MCP_SEO_Adapter::get_meta( $post_id )['title'], $after, 'SEO title' );
					case 'meta_description':
						return self::compare( IATO_MCP_SEO_Adapter::get_meta( $post_id )['description'], $after, 'meta description' );
					case 'canonical_url':
						return self::compare( IATO_MCP_SEO_Adapter::get_canonical( $post_id ), $after, 'canonical URL' );
					case 'structured_data':
						return self::compare( get_post_meta( $post_id, '_iato_mcp_structured_data', true ), $after, 'structured data' );
					default:
						return self::unverifiable( "unknown page field {$field}" );
				}

			case 'image':
				return 'alt_text' === $field
					? self::compare( get_post_meta( $post_id, '_wp_attachment_image_alt', true ), $after, 'alt text' )
					: self::unverifiable( "unknown image field {$field}" );

			case 'post_meta':
				if ( ! get_post( $post_id ) ) {
					return self::unverifiable( 'the post no longer exists' );
				}
				return self::compare( get_post_meta( $post_id, $field, true ), $after, "meta {$field}" );

			case 'elementor_widget':
				// The receipt's after value is the revision hash of the tree the
				// write stored; the current hash is computed the same way.
				if ( ! get_post( $post_id ) ) {
					return self::unverifiable( 'the post no longer exists' );
				}
				$raw = IATO_MCP_Elementor_Adapter::stored_raw( $post_id );
				return self::compare( IATO_MCP_Elementor_Adapter::compute_revision( $raw ), $after, 'Elementor revision' );

			case 'taxonomy':
				return self::taxonomy( $receipt, $field, $post_id );

			case 'menu_item':
				return self::menu_item( $receipt, $field, $post_id );

			case 'redirect':
				return self::redirect( $after );

			default:
				return self::unverifiable( "no conflict check for target_type {$type}" );
		}
	}

	/** Receipt field → WP_Post property for target_type post. */
	private const POST_FIELDS = [
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
		'status'  => 'post_status',
		'slug'    => 'post_name',
	];

	/**
	 * Rolling back a create deletes (or trashes) the object. Conflict when the
	 * object was modified after the receipt was written, so later edits are
	 * not thrown away with it; an object that is already gone matches.
	 */
	private static function created_object( array $receipt, int $post_id, string $what ): array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || 'trash' === ( $post->post_status ?? '' ) ) {
			return [ 'status' => 'match', 'reason' => "the {$what} is already gone" ];
		}
		$applied  = (string) ( $receipt['applied_at'] ?? '' );
		$modified = (string) ( $post->post_modified_gmt ?? '' );
		if ( '' === $applied || '' === $modified || '0000-00-00 00:00:00' === $modified ) {
			return self::unverifiable( "the {$what}'s modification time is not available" );
		}
		if ( strcmp( $modified, self::mysql_utc( $applied ) ) > 0 ) {
			return [
				'status'   => 'conflict',
				'reason'   => "the {$what} was modified after it was created",
				'current'  => [ 'modified_gmt' => $modified ],
				'expected' => [ 'not_modified_after' => self::mysql_utc( $applied ) ],
			];
		}
		return [ 'status' => 'match', 'reason' => "the {$what} is unchanged since it was created" ];
	}

	private static function taxonomy( array $receipt, string $field, int $post_id ): array {
		$before = self::json_array( $receipt['before_value'] ?? null );
		$after  = self::json_array( $receipt['after_value'] ?? null );

		switch ( $field ) {
			case 'assign':
			case 'terms':
				$taxonomy = self::taxonomy_of_ids( array_merge( $after, $before ) );
				if ( null === $taxonomy ) {
					return empty( $after ) && empty( $before )
						? [ 'status' => 'match', 'reason' => 'the receipt changed no terms' ]
						: self::unverifiable( 'none of the terms in the receipt exist any more' );
				}
				$current = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
				if ( is_wp_error( $current ) ) {
					return self::unverifiable( $current->get_error_message() );
				}
				$current = array_map( 'intval', (array) $current );
				$expect  = array_map( 'intval', $after );
				sort( $current );
				sort( $expect );
				return self::compare( $current, $expect, "{$taxonomy} terms" );

			case 'create_term':
				$term_id  = (int) ( $after['term_id'] ?? 0 );
				$taxonomy = (string) ( $after['taxonomy'] ?? '' );
				$term     = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
				if ( ! $term || is_wp_error( $term ) ) {
					return [ 'status' => 'match', 'reason' => 'the term is already gone' ];
				}
				return self::compare( self::term_snapshot( $term, array_keys( $after ) ), $after, 'term' );

			case 'update_term':
				$term_id  = (int) ( $after['term_id'] ?? $before['term_id'] ?? 0 );
				$taxonomy = (string) ( $after['taxonomy'] ?? '' );
				if ( $term_id <= 0 ) {
					return self::unverifiable( 'the receipt does not name the term' );
				}
				$term = get_term( $term_id, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					return self::unverifiable( 'the term no longer exists' );
				}
				return self::compare( self::term_snapshot( $term, array_keys( $after ) ), $after, 'term' );

			case 'delete_term':
				$slug     = (string) ( $before['slug'] ?? '' );
				$taxonomy = (string) ( $before['taxonomy'] ?? '' );
				if ( '' === $slug || '' === $taxonomy ) {
					return self::unverifiable( 'the receipt does not name the term' );
				}
				$existing = get_term_by( 'slug', $slug, $taxonomy );
				if ( $existing && ! is_wp_error( $existing ) ) {
					return [
						'status'   => 'conflict',
						'reason'   => 'a term with this slug exists again',
						'current'  => [ 'term_id' => (int) $existing->term_id, 'slug' => $slug, 'taxonomy' => $taxonomy ],
						'expected' => null,
					];
				}
				return [ 'status' => 'match', 'reason' => 'the term is still deleted' ];

			default:
				return self::unverifiable( "unknown taxonomy field {$field}" );
		}
	}

	private static function menu_item( array $receipt, string $field, int $post_id ): array {
		switch ( $field ) {
			case 'create':
				return self::created_object( $receipt, $post_id, 'menu item' );
			case 'delete':
				// Deleted posts' IDs are never reused: the item stays absent.
				return [ 'status' => 'match', 'reason' => 'the menu item is still deleted' ];
			case 'details':
				$item = get_post( $post_id );
				if ( ! $item ) {
					return self::unverifiable( 'the menu item no longer exists' );
				}
				$after   = self::json_array( $receipt['after_value'] ?? null );
				$current = [];
				foreach ( array_keys( $after ) as $key ) {
					$current[ $key ] = self::menu_item_value( $item, (string) $key );
				}
				return self::compare( $current, $after, 'menu item' );
			default:
				return self::unverifiable( "unknown menu_item field {$field}" );
		}
	}

	/** The value update_menu_item_details records, and this check reads, per key. */
	public static function menu_item_value( object $item, string $key ): mixed {
		switch ( $key ) {
			case 'title':
				// WordPress stores an empty title for an item whose title equals its
				// object's; wp_setup_nav_menu_item() resolves the effective one.
				if ( function_exists( 'wp_setup_nav_menu_item' ) ) {
					$setup = wp_setup_nav_menu_item( get_post( (int) $item->ID ) );
					if ( is_object( $setup ) && isset( $setup->title ) ) {
						return (string) $setup->title;
					}
				}
				return (string) ( $item->post_title ?? '' );
			case 'url':
				return (string) get_post_meta( (int) $item->ID, '_menu_item_url', true );
			case 'position':
				return (int) ( $item->menu_order ?? 0 );
			case 'parent_id':
				return (int) get_post_meta( (int) $item->ID, '_menu_item_menu_item_parent', true );
			default:
				return null;
		}
	}

	private static function redirect( mixed $after_value ): array {
		$after = self::json_array( $after_value );
		$from  = (string) ( $after['from_url'] ?? '' );
		if ( '' === $from || ! function_exists( 'iato_mcp_detect_redirect_handler' ) ) {
			return self::unverifiable( 'the receipt does not name the redirect' );
		}
		$handler = iato_mcp_detect_redirect_handler();
		if ( ! in_array( $handler, [ 'safe_redirect_manager', 'fallback' ], true ) ) {
			return self::unverifiable( "the rules of the {$handler} redirect handler cannot be read" );
		}
		$rule = iato_mcp_get_existing_redirect( $from, $handler );
		if ( null === $rule ) {
			return [
				'status'   => 'conflict',
				'reason'   => 'the redirect no longer exists',
				'current'  => null,
				'expected' => [ 'to_url' => $after['to_url'] ?? null, 'type' => $after['type'] ?? null ],
			];
		}
		return self::compare(
			[ 'to_url' => (string) ( $rule['to_url'] ?? '' ), 'type' => (int) ( $rule['type'] ?? 0 ) ],
			[ 'to_url' => (string) ( $after['to_url'] ?? '' ), 'type' => (int) ( $after['type'] ?? 0 ) ],
			'redirect'
		);
	}

	// ── Helpers shared with IATO_MCP_Rollback ─────────────────────────────

	/**
	 * The taxonomy of a receipt's term IDs: the first ID that still resolves
	 * to a term. assign / terms receipts store IDs only, and the assigned term
	 * is always among the after IDs, so this finds the taxonomy whenever the
	 * receipt changed anything.
	 *
	 * @param array<int|string> $ids
	 */
	public static function taxonomy_of_ids( array $ids ): ?string {
		foreach ( $ids as $id ) {
			$term = get_term( (int) $id );
			if ( $term && ! is_wp_error( $term ) && '' !== (string) ( $term->taxonomy ?? '' ) ) {
				return (string) $term->taxonomy;
			}
		}
		return null;
	}

	/** Decode a receipt value that holds JSON text into an array ([] otherwise). */
	public static function json_array( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** Canonical form for comparison (see the class comment). Null for empty. */
	public static function canonical( mixed $value ): ?string {
		if ( null === $value || '' === $value || false === $value ) {
			return null;
		}
		if ( is_object( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		if ( is_string( $value ) ) {
			$trimmed = ltrim( $value );
			if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					return (string) wp_json_encode( self::normalize( $decoded ) );
				}
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( self::normalize( $value ) );
		}
		if ( true === $value ) {
			return '1';
		}
		return (string) $value;
	}

	private static function normalize( array $value ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_object( $item ) ) {
				$item = json_decode( (string) wp_json_encode( $item ), true );
			}
			if ( is_array( $item ) ) {
				$out[ $key ] = self::normalize( $item );
			} elseif ( null === $item ) {
				$out[ $key ] = null;
			} elseif ( is_bool( $item ) ) {
				$out[ $key ] = $item ? '1' : '0';
			} else {
				$out[ $key ] = (string) $item;
			}
		}
		// array_is_list() is PHP 8.1; the plugin supports 8.0.
		if ( array_keys( $out ) !== range( 0, count( $out ) - 1 ) && [] !== $out ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	private static function compare( mixed $current, mixed $expected, string $what ): array {
		if ( self::canonical( $current ) === self::canonical( $expected ) ) {
			return [ 'status' => 'match', 'reason' => "the {$what} is as the change left it" ];
		}
		return [
			'status'   => 'conflict',
			'reason'   => "the {$what} has changed since this change was made",
			'current'  => self::shown( $current ),
			'expected' => self::shown( $expected ),
		];
	}

	private static function unverifiable( string $reason ): array {
		return [ 'status' => 'unverifiable', 'reason' => $reason ];
	}

	/** JSON text decoded, long strings cut, for responses. */
	public static function shown( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			$trimmed = ltrim( $value );
			if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) && strlen( $value ) <= self::MAX_SHOWN ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
			if ( strlen( $value ) > self::MAX_SHOWN ) {
				return substr( $value, 0, self::MAX_SHOWN ) . '…(' . strlen( $value ) . ' bytes)';
			}
		}
		return $value;
	}

	private static function term_snapshot( object $term, array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			$out[ $key ] = match ( (string) $key ) {
				'term_id'     => (int) $term->term_id,
				'parent'      => (int) ( $term->parent ?? 0 ),
				'taxonomy'    => (string) ( $term->taxonomy ?? '' ),
				default       => isset( $term->{$key} ) ? (string) $term->{$key} : null,
			};
		}
		return $out;
	}

	/** Receipt applied_at as stored (UTC "Y-m-d H:i:s"); tolerates an ISO 8601 value. */
	private static function mysql_utc( string $when ): string {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $when ) ) {
			return $when;
		}
		$ts = strtotime( $when );
		return false === $ts ? $when : gmdate( 'Y-m-d H:i:s', $ts );
	}
}

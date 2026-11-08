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

	/** @var bool|null Test seam: EMPTY_TRASH_DAYS cannot be changed once defined. */
	private static ?bool $trash_deletes = null;

	public static function set_trash_deletes( ?bool $deletes ): void {
		self::$trash_deletes = $deletes;
	}

	/** Does wp_trash_post() delete for good on this site? It does when EMPTY_TRASH_DAYS is 0. */
	public static function trash_deletes(): bool {
		return self::$trash_deletes ?? ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS );
	}

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
				if ( IATO_MCP_URL_Move::FIELD === $field ) {
					return IATO_MCP_URL_Move::conflict( $receipt );
				}
				$key = self::POST_FIELDS[ $field ] ?? null;
				if ( null === $key && 'date' !== $field ) {
					return self::unverifiable( "unknown post field {$field}" );
				}
				$post = get_post( $post_id );
				if ( ! $post ) {
					return self::unverifiable( 'the post no longer exists' );
				}
				if ( 'date' === $field ) {
					// With the status: a scheduled post that has since gone live is a change.
					return self::compare( IATO_MCP_Post_Date::snapshot( $post ), $after, 'post date' );
				}
				return self::compare( $post->{$key} ?? null, $after, "post {$field}" );

			case 'attachment':
				if ( 'create' !== $field ) {
					return self::unverifiable( "unknown attachment field {$field}" );
				}
				$used_as = IATO_MCP_Site_Image::used_as( $post_id );
				return [] !== $used_as
					? [
						'status'   => 'conflict',
						'reason'   => sprintf( 'this image is the site\'s %s, and rolling back deletes it for good, so force also clears that setting', implode( ' and ', str_replace( [ 'site_icon', 'custom_logo' ], [ 'icon', 'logo' ], $used_as ) ) ),
						'current'  => [ 'used_as' => $used_as ],
						'expected' => [ 'used_as' => [] ],
					]
					: self::created_object( $receipt, $post_id, 'attachment' );

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
				$outcome = self::compare( get_post_meta( $post_id, $field, true ), $after, "meta {$field}" );
				return 'match' === $outcome['status'] && '_elementor_edit_mode' === $field && ! empty( $receipt['before_value'] )
					? self::elementor_back_on( $receipt, $post_id, $outcome )
					: $outcome;

			case 'elementor_widget':
				// The receipt's after value is the revision hash of the tree the
				// write stored; the current hash is computed the same way.
				if ( ! get_post( $post_id ) ) {
					return self::unverifiable( 'the post no longer exists' );
				}
				$raw     = IATO_MCP_Elementor_Adapter::stored_raw( $post_id );
				$outcome = self::compare( IATO_MCP_Elementor_Adapter::compute_revision( $raw ), $after, 'Elementor revision' );
				// 1.16.0: rollback would overwrite post_content here, so an edit made
				// since (back in the WordPress editor) is a conflict too. Content that is
				// Elementor's own text of the current tree was not edited by hand.
				$envelope = IATO_MCP_Rollback::no_elementor_from( $receipt['before_value'] ?? null );
				if ( 'match' === $outcome['status'] && isset( $envelope['content_after_sha256'] ) ) {
					$now = (string) get_post_field( 'post_content', $post_id );
					if ( hash( 'sha256', $now ) !== $envelope['content_after_sha256'] && true !== IATO_MCP_Elementor_Adapter::content_is_elementor_text( $post_id, $now ) ) {
						return [ 'status' => 'conflict', 'reason' => 'the post content has changed since this change was made' ];
					}
				}
				return $outcome;

			case 'elementor_atomic':
				// 1.14.0: one atomic element's settings.
				if ( ! get_post( $post_id ) ) {
					return self::unverifiable( 'the post no longer exists' );
				}
				return IATO_MCP_Elementor_Atomic_Writer::conflict( $receipt );

			case 'taxonomy':
				return self::taxonomy( $receipt, $field, $post_id );

			case 'menu_item':
				return self::menu_item( $receipt, $field, $post_id );

			case 'menu':
				return 'create' === $field
					? self::created_menu( $receipt )
					: self::unverifiable( "unknown menu field {$field}" );

			case 'menu_location':
				return self::compare( self::menu_in_location( $field ), self::json_array( $after )['menu_id'] ?? null, "menu location {$field}" );

			case 'elementor_template':
				return self::elementor_template( $receipt, $field, $post_id );

			case 'redirect':
				return self::redirect( $after );

			case IATO_MCP_Permalinks::RECEIPT:
				return IATO_MCP_Permalinks::conflict( $receipt );

			case IATO_MCP_User_Profile::RECEIPT:
				$current = IATO_MCP_User_Profile::current( $after );
				return null === $current ? self::unverifiable( 'the user no longer exists' ) : self::compare( $current, $after, 'profile' );

			case IATO_MCP_Custom_CSS::RECEIPT:
				$stylesheet = (string) ( self::json_array( $after )['stylesheet'] ?? '' );
				if ( '' === $stylesheet ) {
					return self::unverifiable( 'the receipt does not say which theme the CSS belonged to' );
				}
				return self::compare( IATO_MCP_Custom_CSS::after_value( $stylesheet ), $after, 'custom CSS' );

			case 'site_setting':
				// "None" can be stored as 0, '' or nothing: these compare as IDs.
				if ( 'site_icon' === $field ) {
					return self::compare( (string) IATO_MCP_Site_Image::current( 'site_icon' ), $after, 'site icon' );
				}
				if ( 'custom_logo' === $field ) {
					return self::compare( [ 'id' => IATO_MCP_Site_Image::current( 'custom_logo' ), 'theme' => IATO_MCP_Site_Image::get()->theme() ], $after, 'site logo' );
				}
				return in_array( $field, self::SITE_OPTIONS, true )
					? self::compare( get_option( $field ), $after, "site setting {$field}" )
					: self::unverifiable( "unknown site setting {$field}" );

			default:
				return self::unverifiable( "no conflict check for target_type {$type}" );
		}
	}

	/** The options update_site_settings writes; a `site_setting` receipt's field names one of them. */
	public const SITE_OPTIONS = [ 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'site_icon' ];

	/** The theme mods it writes (1.18.0): a receipt's before and after values hold the theme too. */
	public const SITE_THEME_MODS = [ 'custom_logo' ];

	/** Receipt field → WP_Post property for target_type post. */
	private const POST_FIELDS = [
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
		'status'  => 'post_status',
		'slug'    => 'post_name',
		'author'  => 'post_author',
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

	/** What create_elementor_template and set_elementor_template_conditions did (1.17.0). */
	private static function elementor_template( array $receipt, string $field, int $post_id ): array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( 'conditions' === $field ) {
			if ( ! $post ) {
				return self::unverifiable( 'the template no longer exists' );
			}
			return self::compare( IATO_MCP_Theme_Builder::stored_conditions( $post_id ), self::json_array( $receipt['after_value'] ?? null ), 'template conditions' );
		}
		if ( 'create' !== $field ) {
			return self::unverifiable( "unknown elementor_template field {$field}" );
		}

		$outcome = self::created_object( $receipt, $post_id, 'template' );
		if ( ! $post || 'trash' === ( $post->post_status ?? '' ) ) {
			return $outcome;
		}
		$recorded   = self::json_array( $receipt['after_value'] ?? null );
		$conditions = IATO_MCP_Theme_Builder::stored_conditions( $post_id );
		$created    = self::json_array( $recorded['conditions'] ?? [] );
		if ( 'conflict' !== $outcome['status'] ) {
			// The content itself: a timestamp misses what writes only the meta, as most Elementor writes do.
			$hashes = IATO_MCP_Theme_Builder::content_hashes( $post_id );
			if ( $hashes['data_sha256'] !== ( $recorded['data_sha256'] ?? $hashes['data_sha256'] ) || $hashes['page_settings_sha256'] !== ( $recorded['page_settings_sha256'] ?? $hashes['page_settings_sha256'] ) ) {
				$outcome = [
					'status'   => 'conflict',
					'reason'   => 'the template\'s content was changed after it was created',
					'current'  => $hashes,
					'expected' => array_intersect_key( $recorded, $hashes ),
				];
			} elseif ( $conditions !== $created ) {
				$outcome = [
					'status'   => 'conflict',
					'reason'   => 'the template\'s conditions were changed after it was created',
					'current'  => $conditions,
					'expected' => $created,
				];
			}
		}
		if ( 'conflict' === $outcome['status'] ) {
			$outcome['reason'] .= self::live_template_note( $receipt, $post, $conditions );
		}
		if ( self::trash_deletes() ) {
			$deletes = 'this site deletes instead of trashing (EMPTY_TRASH_DAYS is 0), so rolling back would delete the template for good';
			$outcome = [
				'status' => 'conflict',
				'reason' => 'conflict' === $outcome['status'] ? $outcome['reason'] . ', and ' . $deletes : $deletes,
			] + array_intersect_key( $outcome, [ 'current' => true, 'expected' => true ] );
		}
		return $outcome;
	}

	/** Why force is a real decision: rolling back takes a live template off the site. */
	private static function live_template_note( array $receipt, object $post, array $conditions ): string {
		$includes = array_filter( $conditions, static fn( string $c ): bool => str_starts_with( $c, 'include/' ) );
		if ( 'publish' !== ( $post->post_status ?? '' ) || [] === $includes ) {
			return '';
		}
		$type = (string) ( self::json_array( $receipt['after_value'] ?? null )['template_type'] ?? 'template' );
		return in_array( 'include/general', $conditions, true )
			? sprintf( ', and it is a live site-wide %s: rolling back would remove it from every page', $type )
			: sprintf( ', and this %s is live: rolling back would remove it from the pages its conditions match', $type );
	}

	/**
	 * A menu create_menu made (1.17.0). wp_delete_nav_menu() deletes the menu's items
	 * for good, and an item counts wherever it came from, so these are the same test.
	 */
	private static function created_menu( array $receipt ): array {
		$after   = self::json_array( $receipt['after_value'] ?? null );
		$menu_id = (int) ( $after['menu_id'] ?? 0 );
		$menu    = self::menu_object( $menu_id );
		if ( null === $menu ) {
			return [ 'status' => 'match', 'reason' => 'the menu is already gone' ];
		}
		$items = get_objects_in_term( $menu_id, 'nav_menu' );
		$count = is_array( $items ) ? count( $items ) : 0;
		if ( $count > 0 ) {
			return [
				'status'   => 'conflict',
				'reason'   => sprintf( 'the menu now holds %d items, and deleting a menu deletes its items permanently', $count ),
				'current'  => [ 'item_count' => $count ],
				'expected' => [ 'item_count' => 0 ],
			];
		}
		// wp_delete_nav_menu() empties any location holding the menu, and the receipt that
		// put it there could then no longer find what to restore.
		$created_in = (string) ( $after['location'] ?? '' );
		$held       = [];
		foreach ( get_nav_menu_locations() as $location => $held_id ) {
			if ( (int) $held_id === $menu_id && (string) $location !== $created_in ) {
				$held[] = (string) $location;
			}
		}
		if ( [] !== $held ) {
			return [
				'status'   => 'conflict',
				'reason'   => sprintf( 'the menu now holds the location %s, given to it after it was created, and deleting it would empty that. Roll back the location change first, which puts back the menu that was there', implode( ', ', $held ) ),
				'current'  => [ 'locations' => $held ],
				'expected' => [ 'locations' => '' === $created_in ? [] : [ $created_in ] ],
			];
		}
		if ( (string) $menu->name !== (string) ( $after['name'] ?? '' ) ) {
			return [
				'status'   => 'conflict',
				'reason'   => 'the menu was renamed after it was created',
				'current'  => [ 'name' => (string) $menu->name ],
				'expected' => [ 'name' => (string) ( $after['name'] ?? '' ) ],
			];
		}
		return [ 'status' => 'match', 'reason' => 'the menu is empty and unchanged since it was created' ];
	}

	/**
	 * The menu with this ID, or null. wp_get_nav_menu_object() falls back to matching
	 * the value against slugs and names, so asked about 99 it can answer with the menu
	 * called "99", whatever that menu's ID is.
	 */
	public static function menu_object( int $menu_id ): ?object {
		$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
		return $menu && (int) $menu->term_id === $menu_id ? $menu : null;
	}

	/** The menu in a theme location, or null: the value the menu tools record and this check reads. */
	public static function menu_in_location( string $location ): ?int {
		$menu_id = (int) ( get_nav_menu_locations()[ $location ] ?? 0 );
		return $menu_id > 0 ? $menu_id : null;
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

	public static function compare( mixed $current, mixed $expected, string $what ): array {
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
	/**
	 * Rolling back "Elementor turned off" turns it on again (1.16.0). If the post was
	 * edited since, visitors would stop seeing that edit and the next Elementor write
	 * would replace it. Meta writes do not touch the modified time.
	 */
	private static function elementor_back_on( array $receipt, int $post_id, array $outcome ): array {
		if ( 'inactive_tree' !== IATO_MCP_Elementor_Adapter::builder_state( $post_id ) ) {
			return $outcome;
		}
		$post     = get_post( $post_id );
		$applied  = (string) ( $receipt['applied_at'] ?? '' );
		$modified = (string) ( $post->post_modified_gmt ?? '' );
		if ( '' === $applied || '' === $modified || strcmp( $modified, self::mysql_utc( $applied ) ) <= 0 ) {
			return $outcome;
		}
		return [ 'status' => 'conflict', 'reason' => 'the post was edited after Elementor was turned off, and turning it back on would hide that edit from visitors' ];
	}

	private static function mysql_utc( string $when ): string {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $when ) ) {
			return $when;
		}
		$ts = strtotime( $when );
		return false === $ts ? $when : gmdate( 'Y-m-d H:i:s', $ts );
	}
}

<?php
/**
 * WP Tools: get_menus, get_menu_items, update_menu_item, create_menu_item,
 *           delete_menu_item, update_menu_item_details, create_menu,
 *           assign_menu_location
 *
 * get_menus / get_menu_items — read only
 * update_menu_item / create_menu_item / delete_menu_item / update_menu_item_details /
 * create_menu / assign_menu_location
 *                             — require edit_theme_options (WP-canonical cap for
 *                               nav-menu structure; was manage_options pre-v1.10.0).
 *
 * Requires WordPress 6.3+ for the /wp/v2/menus REST endpoint.
 * Falls back to wp_get_nav_menus() for older versions.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * A refusal the client needs its details from. MCP clients only see a tool error's
 * text, so it is JSON: { error, message, ... } (1.17.0).
 */
function iato_mcp_menu_error( string $code, string $message, array $extra = [] ): array {
	return [
		'isError' => true,
		'content' => [ [
			'type' => 'text',
			'text' => (string) wp_json_encode( [ 'error' => $code, 'message' => $message ] + $extra ),
		] ],
	];
}

/**
 * The refusal for a location the theme does not register, or null when it does.
 * Exact match: a location's slug is whatever the theme registered.
 */
function iato_mcp_menu_location_refusal( mixed $location ): ?array {
	$registered = get_registered_nav_menus();
	if ( is_string( $location ) && array_key_exists( $location, $registered ) ) {
		return null;
	}
	$shown = is_string( $location ) ? $location : '';
	if ( [] === $registered ) {
		return iato_mcp_menu_error( 'unknown_menu_location', 'This theme registers no menu locations. A menu can still be used by a widget, by its slug.', [ 'locations' => [] ] );
	}
	$list = [];
	foreach ( $registered as $slug => $label ) {
		$list[] = [ 'slug' => (string) $slug, 'label' => (string) $label ];
	}
	return iato_mcp_menu_error(
		'unknown_menu_location',
		sprintf( 'Unknown menu location "%1$s". This theme registers: %2$s.', $shown, implode( ', ', array_column( $list, 'slug' ) ) ),
		[ 'locations' => $list ]
	);
}

// ── get_menus ─────────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_menus',
	[
		'description' => 'List all registered WordPress navigation menus with their item counts, and every menu location the theme registers with the menu in it (locations).',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => new stdClass(),
			'required'   => [],
		],
	],
	function ( array $args ): array|WP_Error {
		$menus     = wp_get_nav_menus();
		$locations = get_nav_menu_locations();
		$loc_map   = array_flip( $locations );

		$result = [];
		foreach ( $menus as $menu ) {
			$result[] = [
				'id'         => $menu->term_id,
				'name'       => $menu->name,
				'slug'       => $menu->slug,
				'item_count' => (int) $menu->count,
				'location'   => $loc_map[ $menu->term_id ] ?? null,
			];
		}

		// 1.17.0: every location the theme registers, including empty ones. `location`
		// above stays as it was: one location per menu, which a menu can hold several of.
		$registered = [];
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$registered[] = [
				'slug'    => (string) $slug,
				'label'   => (string) $label,
				'menu_id' => IATO_MCP_Rollback_Conflict::menu_in_location( (string) $slug ),
			];
		}

		return IATO_MCP_Server::ok( [ 'menus' => $result, 'locations' => $registered ] );
	}
);

// ── get_menu_items ────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_menu_items',
	[
		'description' => 'Get all items in a navigation menu by menu ID.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_id' => [ 'type' => 'integer', 'description' => 'Menu ID (required)' ],
			],
			'required' => [ 'menu_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$menu_id = absint( $args['menu_id'] ?? 0 );
		if ( ! $menu_id ) return new WP_Error( 'missing_menu_id', 'menu_id required' );

		$items = wp_get_nav_menu_items( $menu_id );
		if ( false === $items ) {
			return new WP_Error( 'not_found', 'Menu not found.' );
		}

		// Items that point at a post the caller may not read (a draft, a private
		// post) are left out, with their count, so unpublished titles and URLs
		// are not listed; custom links and archive items are public (1.13.0).
		$result  = [];
		$omitted = 0;
		foreach ( $items as $item ) {
			if ( 'post_type' === ( $item->type ?? '' ) && (int) $item->object_id > 0
				&& is_wp_error( IATO_MCP_Auth::require_cap( 'read_post', (int) $item->object_id ) ) ) {
				++$omitted;
				continue;
			}
			$result[] = [
				'id'         => (int) $item->ID,
				'title'      => $item->title,
				'url'        => $item->url,
				'slug'       => $item->post_name,
				'post_id'    => (int) $item->object_id,
				'parent_id'  => (int) $item->menu_item_parent,
				'menu_order' => (int) $item->menu_order,
			];
		}

		$data = [ 'items' => $result ];
		if ( $omitted > 0 ) {
			$data['omitted_items'] = $omitted;
		}
		return IATO_MCP_Server::ok( $data );
	}
);

// ── update_menu_item ──────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_menu_item',
	[
		'description' => 'Add a page to a navigation menu, or update an existing menu item. Supports dry_run to preview changes. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_id'   => [ 'type' => 'integer', 'description' => 'Menu ID (required)' ],
				'post_id'   => [ 'type' => 'integer', 'description' => 'Post/page ID to add' ],
				'parent_id' => [ 'type' => 'integer', 'description' => 'Parent menu item ID (0 for root)' ],
				'dry_run'   => [ 'type' => 'boolean', 'description' => 'If true, preview without saving (default: false)' ],
			],
			'required' => [ 'menu_id', 'post_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		// AUDIT v1.10.0: edit_theme_options is the WP-canonical cap for nav-menu
		// structure (matches wp-admin/nav-menus.php). Was manage_options pre-v1.10.0;
		// functionally identical on default WP (both admin-only by default), but
		// edit_theme_options is observably different for sites that grant the cap
		// to non-admin via a role-management plugin.
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$dry_run = (bool) ( $args['dry_run'] ?? false );
		$menu_id = absint( $args['menu_id'] ?? 0 );
		$post_id = absint( $args['post_id'] ?? 0 );

		if ( ! $menu_id ) {
			return new WP_Error( 'missing_menu_id', 'menu_id required.' );
		}
		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', 'post_id required.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return new WP_Error( 'not_found', 'Menu not found.' );
		}

		$parent_id = absint( $args['parent_id'] ?? 0 );

		$item_data = [
			'menu-item-object-id' => $post_id,
			'menu-item-object'    => $post->post_type,
			'menu-item-type'      => 'post_type',
			'menu-item-title'     => get_the_title( $post ),
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
		];

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'   => true,
				'menu_id'   => $menu_id,
				'post_id'   => $post_id,
				'title'     => $item_data['menu-item-title'],
				'parent_id' => $parent_id,
			] );
		}

		$result = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $item_data ) ); // it saves through wp_insert_post(), which unslashes
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$receipt = IATO_MCP_Change_Receipt::record(
			$result, 'menu_item', 'create', null,
			wp_json_encode( array_merge( $item_data, [ 'menu_id' => $menu_id ] ) )
		);

		$data = [
			'menu_item_id' => $result,
			'menu_id'      => $menu_id,
			'post_id'      => $post_id,
			'title'        => $item_data['menu-item-title'],
			'parent_id'    => $parent_id,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

// ── create_menu_item ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_menu_item',
	[
		'description' => 'Create a new menu item. Supports arbitrary URLs (custom links) and WordPress pages/posts. Preserves parent-child hierarchy via parent_id. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_id'   => [ 'type' => 'integer', 'description' => 'Menu ID to add the item to (required).' ],
				'title'     => [ 'type' => 'string',  'description' => 'Display label for the menu item (required).' ],
				'url'       => [ 'type' => 'string',  'description' => 'URL for custom link items. Omit when using post_id.' ],
				'post_id'   => [ 'type' => 'integer', 'description' => 'WP post/page ID. When provided, type is set to post_type automatically.' ],
				'position'  => [ 'type' => 'integer', 'description' => 'Menu order / position (default: 0).' ],
				'parent_id' => [ 'type' => 'integer', 'description' => 'Parent menu item ID for nesting (0 for top-level).' ],
				'dry_run'   => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'menu_id', 'title' ],
		],
	],
	function ( array $args ): array|WP_Error {
		// AUDIT v1.10.0: edit_theme_options is the WP-canonical cap for nav-menu
		// structure (matches wp-admin/nav-menus.php). Was manage_options pre-v1.10.0;
		// functionally identical on default WP (both admin-only by default), but
		// edit_theme_options is observably different for sites that grant the cap
		// to non-admin via a role-management plugin.
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$menu_id   = absint( $args['menu_id'] ?? 0 );
		$title     = sanitize_text_field( $args['title'] ?? '' );
		$url       = esc_url_raw( $args['url'] ?? '' );
		$post_id   = absint( $args['post_id'] ?? 0 );
		$position  = absint( $args['position'] ?? 0 );
		$parent_id = absint( $args['parent_id'] ?? 0 );
		$dry_run   = ! empty( $args['dry_run'] );

		if ( ! $menu_id ) {
			return new WP_Error( 'missing_menu_id', 'menu_id is required.' );
		}
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', 'title is required.' );
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu ) {
			return new WP_Error( 'not_found', 'Menu not found.' );
		}

		// Build item data — post_type link or custom URL.
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error( 'not_found', 'Post not found.' );
			}
			$item_data = [
				'menu-item-object-id' => $post_id,
				'menu-item-object'    => $post->post_type,
				'menu-item-type'      => 'post_type',
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position,
				'menu-item-parent-id' => $parent_id,
			];
		} else {
			if ( empty( $url ) ) {
				return new WP_Error( 'missing_url', 'Either url or post_id is required.' );
			}
			$item_data = [
				'menu-item-url'       => $url,
				'menu-item-type'      => 'custom',
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position,
				'menu-item-parent-id' => $parent_id,
			];
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'   => true,
				'menu_id'   => $menu_id,
				'title'     => $title,
				'url'       => $url ?: get_permalink( $post_id ),
				'post_id'   => $post_id ?: null,
				'position'  => $position,
				'parent_id' => $parent_id,
				'type'      => $post_id ? 'post_type' : 'custom',
			] );
		}

		$result = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $item_data ) ); // it saves through wp_insert_post(), which unslashes
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$receipt = IATO_MCP_Change_Receipt::record(
			$result, 'menu_item', 'create', null,
			wp_json_encode( array_merge( $item_data, [ 'menu_id' => $menu_id ] ) )
		);

		$data = [
			'menu_item_id' => $result,
			'menu_id'      => $menu_id,
			'title'        => $title,
			'url'          => $url ?: get_permalink( $post_id ),
			'post_id'      => $post_id ?: null,
			'position'     => $position,
			'parent_id'    => $parent_id,
			'type'         => $post_id ? 'post_type' : 'custom',
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

// ── delete_menu_item ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'delete_menu_item',
	[
		'description' => 'Delete a menu item by its menu item ID. Supports dry_run to preview. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_item_id' => [ 'type' => 'integer', 'description' => 'The menu item ID to delete (required).' ],
				'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview without deleting (default: false).' ],
			],
			'required' => [ 'menu_item_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		// AUDIT v1.10.0: edit_theme_options is the WP-canonical cap for nav-menu
		// structure (matches wp-admin/nav-menus.php). Was manage_options pre-v1.10.0;
		// functionally identical on default WP (both admin-only by default), but
		// edit_theme_options is observably different for sites that grant the cap
		// to non-admin via a role-management plugin.
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$menu_item_id = absint( $args['menu_item_id'] ?? 0 );
		$dry_run      = ! empty( $args['dry_run'] );

		if ( ! $menu_item_id ) {
			return new WP_Error( 'missing_menu_item_id', 'menu_item_id is required.' );
		}

		$item = get_post( $menu_item_id );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error( 'not_found', 'Menu item not found.' );
		}

		// Snapshot full item state for rollback.
		$menu_terms = wp_get_object_terms( $menu_item_id, 'nav_menu' );
		$snapshot   = [
			'menu_id'    => ! is_wp_error( $menu_terms ) && ! empty( $menu_terms ) ? $menu_terms[0]->term_id : 0,
			'title'      => $item->post_title,
			'url'        => get_post_meta( $menu_item_id, '_menu_item_url', true ),
			'type'       => get_post_meta( $menu_item_id, '_menu_item_type', true ),
			'object'     => get_post_meta( $menu_item_id, '_menu_item_object', true ),
			'object_id'  => get_post_meta( $menu_item_id, '_menu_item_object_id', true ),
			'parent_id'  => get_post_meta( $menu_item_id, '_menu_item_menu_item_parent', true ),
			'position'   => $item->menu_order,
		];

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'       => true,
				'menu_item_id'  => $menu_item_id,
				'title'         => $item->post_title,
				'action'        => 'would_delete',
			] );
		}

		$deleted = wp_delete_post( $menu_item_id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'Failed to delete menu item.' );
		}

		$receipt = IATO_MCP_Change_Receipt::record(
			$menu_item_id, 'menu_item', 'delete', wp_json_encode( $snapshot ), null
		);

		$data = [
			'menu_item_id' => $menu_item_id,
			'title'        => $item->post_title,
			'action'       => 'deleted',
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

// ── update_menu_item_details ─────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_menu_item_details',
	[
		'description' => 'Update an existing menu item\'s title, URL, position, or parent. Only provided fields are changed. Supports dry_run. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'menu_item_id' => [ 'type' => 'integer', 'description' => 'The menu item ID to update (required).' ],
				'title'        => [ 'type' => 'string',  'description' => 'New display label.' ],
				'url'          => [ 'type' => 'string',  'description' => 'New URL (only applies to custom link items).' ],
				'position'     => [ 'type' => 'integer', 'description' => 'New menu order / position.' ],
				'parent_id'    => [ 'type' => 'integer', 'description' => 'New parent menu item ID (0 for top-level).' ],
				'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'menu_item_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		// AUDIT v1.10.0: edit_theme_options is the WP-canonical cap for nav-menu
		// structure (matches wp-admin/nav-menus.php). Was manage_options pre-v1.10.0;
		// functionally identical on default WP (both admin-only by default), but
		// edit_theme_options is observably different for sites that grant the cap
		// to non-admin via a role-management plugin.
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$menu_item_id = absint( $args['menu_item_id'] ?? 0 );
		$dry_run      = ! empty( $args['dry_run'] );

		if ( ! $menu_item_id ) {
			return new WP_Error( 'missing_menu_item_id', 'menu_item_id is required.' );
		}

		$item = get_post( $menu_item_id );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error( 'not_found', 'Menu item not found.' );
		}

		// Determine which menu this item belongs to.
		$menu_terms = wp_get_object_terms( $menu_item_id, 'nav_menu' );
		if ( is_wp_error( $menu_terms ) || empty( $menu_terms ) ) {
			return new WP_Error( 'no_menu', 'Could not determine the menu for this item.' );
		}
		$menu_id = $menu_terms[0]->term_id;

		// Read current values to merge with updates.
		$current_type      = get_post_meta( $menu_item_id, '_menu_item_type', true );
		$current_object    = get_post_meta( $menu_item_id, '_menu_item_object', true );
		$current_object_id = get_post_meta( $menu_item_id, '_menu_item_object_id', true );
		$current_url       = get_post_meta( $menu_item_id, '_menu_item_url', true );
		$current_parent    = get_post_meta( $menu_item_id, '_menu_item_menu_item_parent', true );

		$item_data = [
			'menu-item-type'      => $current_type,
			'menu-item-object'    => $current_object,
			'menu-item-object-id' => $current_object_id,
			'menu-item-title'     => $item->post_title,
			'menu-item-url'       => $current_url,
			'menu-item-status'    => 'publish',
			'menu-item-position'  => $item->menu_order,
			'menu-item-parent-id' => $current_parent,
		];

		$changes = [];

		if ( isset( $args['title'] ) ) {
			$new_title = sanitize_text_field( $args['title'] );
			$changes['title'] = [ 'from' => $item->post_title, 'to' => $new_title ];
			$item_data['menu-item-title'] = $new_title;
		}
		if ( isset( $args['url'] ) ) {
			$new_url = esc_url_raw( $args['url'] );
			$changes['url'] = [ 'from' => $current_url, 'to' => $new_url ];
			$item_data['menu-item-url'] = $new_url;
		}
		if ( isset( $args['position'] ) ) {
			$new_pos = absint( $args['position'] );
			$changes['position'] = [ 'from' => $item->menu_order, 'to' => $new_pos ];
			$item_data['menu-item-position'] = $new_pos;
		}
		if ( isset( $args['parent_id'] ) ) {
			$new_parent = absint( $args['parent_id'] );
			$changes['parent_id'] = [ 'from' => (int) $current_parent, 'to' => $new_parent ];
			$item_data['menu-item-parent-id'] = $new_parent;
		}

		if ( empty( $changes ) ) {
			return new WP_Error( 'no_changes', 'No fields provided to update.' );
		}

		if ( $dry_run ) {
			return IATO_MCP_Server::ok( [
				'dry_run'       => true,
				'menu_item_id'  => $menu_item_id,
				'menu_id'       => $menu_id,
				'changes'       => $changes,
			] );
		}

		$result = wp_update_nav_menu_item( $menu_id, $menu_item_id, wp_slash( $item_data ) ); // it saves through wp_update_post(), which unslashes
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Build before/after from the tracked changes. After values are read
		// back as stored (WordPress filters titles on save and stores an empty
		// title when it equals the linked object's), the form the rollback
		// conflict check compares.
		$before_vals = [];
		$after_vals  = [];
		$stored_item = get_post( $menu_item_id );
		foreach ( $changes as $key => $change ) {
			$before_vals[ $key ] = $change['from'];
			$after_vals[ $key ]  = $stored_item ? IATO_MCP_Rollback_Conflict::menu_item_value( $stored_item, (string) $key ) : $change['to'];
		}

		$receipt = IATO_MCP_Change_Receipt::record(
			$menu_item_id, 'menu_item', 'details',
			wp_json_encode( $before_vals ),
			wp_json_encode( $after_vals )
		);

		$data = [
			'menu_item_id' => $result,
			'menu_id'      => $menu_id,
			'changes'      => $changes,
		];
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

// ── create_menu ──────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_menu',
	[
		'description' => 'Create an empty WordPress navigation menu, optionally in a theme location. Add its links with create_menu_item; Elementor Pro\'s Nav Menu widget refers to a menu by the slug this returns. Supports dry_run. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'name'     => [ 'type' => 'string', 'description' => 'Menu name (required). Must not be in use by another menu.' ],
				'location' => [ 'type' => 'string', 'description' => 'Theme location slug to put the menu in, from get_menus. A menu already there is replaced in that location and kept.' ],
				'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'name' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$name = is_string( $args['name'] ?? null ) ? trim( sanitize_text_field( $args['name'] ) ) : '';
		if ( '' === $name ) {
			return iato_mcp_menu_error( 'missing_name', 'name is required.' );
		}

		// Every check comes before the menu exists, so a refusal leaves nothing behind.
		$location = $args['location'] ?? null;
		$location = '' === $location ? null : $location;
		if ( null !== $location ) {
			$refusal = iato_mcp_menu_location_refusal( $location );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		// What wp_update_nav_menu_object() checks, slashed as it expects.
		$existing = get_term_by( 'name', wp_slash( $name ), 'nav_menu' );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return iato_mcp_menu_error(
				'menu_exists',
				sprintf( 'A menu named "%1$s" already exists (ID %2$d). Use it, or choose another name.', $name, (int) $existing->term_id ),
				[ 'menu_id' => (int) $existing->term_id ]
			);
		}

		$previous = null === $location ? null : IATO_MCP_Rollback_Conflict::menu_in_location( $location );

		if ( ! empty( $args['dry_run'] ) ) {
			$stored_name = iato_mcp_term_field_as_stored( 'name', $name, 'nav_menu' );
			return IATO_MCP_Server::ok( array_merge(
				[
					'dry_run'  => true,
					'action'   => 'would_create',
					'name'     => $stored_name,
					'slug'     => sanitize_title( $stored_name ),
					'location' => $location,
				],
				null === $previous ? [] : [ 'would_replace_menu_id' => $previous ],
				[ 'not_checked' => array_merge( IATO_MCP_Server::DRY_RUN_NOT_CHECKED, [ 'A slug already used by another menu, which WordPress would change by adding a suffix.' ] ) ]
			) );
		}

		$menu_id = wp_create_nav_menu( wp_slash( $name ) ); // wp_insert_term() unslashes
		if ( is_wp_error( $menu_id ) ) {
			return iato_mcp_menu_error( (string) $menu_id->get_error_code(), (string) $menu_id->get_error_message() );
		}
		$menu_id = (int) $menu_id;
		if ( null !== $location ) {
			IATO_MCP_Rollback::set_menu_location( $location, $menu_id );
		}

		$menu  = wp_get_nav_menu_object( $menu_id );
		$theme = get_stylesheet();
		$data  = [
			'menu_id'  => $menu_id,
			'name'     => $menu ? (string) $menu->name : $name,
			'slug'     => $menu ? (string) $menu->slug : '',
			'location' => $location,
		];
		if ( null !== $previous ) {
			$data['replaced_menu_id'] = $previous;
		}

		$receipt = IATO_MCP_Change_Receipt::record(
			null, 'menu', 'create',
			null === $location ? null : wp_json_encode( [ 'location' => $location, 'menu_id' => $previous, 'theme' => $theme ] ),
			wp_json_encode( [ 'menu_id' => $menu_id, 'name' => $data['name'], 'slug' => $data['slug'], 'location' => $location, 'theme' => $theme ] )
		);
		IATO_MCP_Change_Receipt::append( $data, $receipt );

		return IATO_MCP_Server::ok( $data );
	}
);

// ── assign_menu_location ─────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'assign_menu_location',
	[
		'description' => 'Put a menu in a theme location, or clear the location with menu_id 0. The menu that was there is kept. Supports dry_run. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'location' => [ 'type' => 'string', 'description' => 'Theme location slug, from get_menus (required).' ],
				'menu_id'  => [ 'type' => 'integer', 'description' => 'Menu ID, or 0 to clear the location (required).' ],
				'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'location', 'menu_id' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'edit_theme_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		$location = $args['location'] ?? null;
		$refusal  = iato_mcp_menu_location_refusal( $location );
		if ( null !== $refusal ) {
			return $refusal;
		}

		// Required, so that leaving it out never clears a location. absint() would
		// turn -40 into menu 40.
		$given = $args['menu_id'] ?? null;
		if ( ! ( is_int( $given ) || ( is_string( $given ) && ctype_digit( $given ) ) ) || (int) $given < 0 ) {
			return iato_mcp_menu_error( 'invalid_menu_id', 'menu_id must be a menu ID, or 0 to clear the location.' );
		}
		$menu_id = (int) $given;
		if ( $menu_id > 0 && null === IATO_MCP_Rollback_Conflict::menu_object( $menu_id ) ) {
			return iato_mcp_menu_error( 'not_found', sprintf( 'Menu %d not found.', $menu_id ) );
		}

		$before = IATO_MCP_Rollback_Conflict::menu_in_location( $location );
		$after  = $menu_id > 0 ? $menu_id : null;
		$report = [
			'location' => $location,
			'before'   => [ 'menu_id' => $before ],
			'after'    => [ 'menu_id' => $after ],
		];

		if ( ! empty( $args['dry_run'] ) ) {
			return IATO_MCP_Server::ok( array_merge(
				[
					'dry_run' => true,
					'action'  => $before === $after ? 'no_change' : ( null === $after ? 'would_clear' : 'would_assign' ),
				],
				$report,
				[ 'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED ]
			) );
		}
		if ( $before === $after ) {
			return IATO_MCP_Server::ok( [ 'action' => 'no_change' ] + $report );
		}

		IATO_MCP_Rollback::set_menu_location( $location, $menu_id );

		$theme  = get_stylesheet();
		$stored = IATO_MCP_Rollback_Conflict::menu_in_location( $location );
		$report['after']['menu_id'] = $stored;
		$receipt = IATO_MCP_Change_Receipt::record(
			null, 'menu_location', $location,
			wp_json_encode( [ 'location' => $location, 'menu_id' => $before, 'theme' => $theme ] ),
			wp_json_encode( [ 'location' => $location, 'menu_id' => $stored, 'theme' => $theme ] )
		);
		IATO_MCP_Change_Receipt::append( $report, $receipt );

		return IATO_MCP_Server::ok( $report );
	}
);

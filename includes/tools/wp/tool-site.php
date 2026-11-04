<?php
/**
 * WP Tools: get_site_info, get_site_settings, update_site_settings
 *
 * get_site_info  — read-only, any authenticated user
 * get_site_settings, update_site_settings — require manage_options
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// ── get_site_info ─────────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_site_info',
	[
		'description' => 'Returns site name, URL, WordPress version, active theme, and for administrators (manage_options) the active plugin count and the Theme Builder template count (otherwise listed in restricted_fields). The theme_builder_template_count field (added v1.11.0) counts elementor_library posts that are actually Theme Builder templates (have a non-empty _elementor_template_type meta) — excluding saved sections, reusable blocks, and condition-less popups, since those don\'t shadow URLs. Use list_elementor_templates (manage_options) for the full enumeration once you know templates exist. The cache object (added v1.15.0) lists the page cache plugins found (detected) and notes about caches IATO cannot purge, such as a CDN; write tools purge the detected ones and report the result in their own cache object.',
		'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass(), 'required' => [] ],
	],
	function ( array $args ): array|WP_Error {
		$info = [
			'name'         => sanitize_text_field( get_bloginfo( 'name' ) ),
			'url'          => site_url(),
			'wp_version'   => get_bloginfo( 'version' ),
			'active_theme' => wp_get_theme()->get( 'Name' ),
		];
		// For every caller (1.15.0): write responses report the same list, and every
		// MCP caller holds edit_posts. Read from what is known; never a blocking call.
		$info['cache'] = IATO_MCP_Cache::site_info();
		// Site-administration details for administrators only (1.13.0); the
		// fields above the cache list are visible in any page's source.
		if ( ! is_wp_error( IATO_MCP_Auth::require_cap( 'manage_options' ) ) ) {
			$info['plugin_count']                 = count( get_option( 'active_plugins', [] ) );
			$info['theme_builder_template_count'] = iato_mcp_count_theme_builder_templates();
		} else {
			$info['restricted_fields'] = [ 'plugin_count', 'theme_builder_template_count' ];
		}
		return IATO_MCP_Server::ok( $info );
	}
);

/**
 * Count elementor_library posts that are actually Theme Builder templates —
 * i.e. have a non-empty _elementor_template_type meta value. Excludes saved
 * sections, reusable blocks, and other elementor_library entries that don't
 * carry a template-type assignment (those don't shadow URLs and would
 * inflate the discovery signal).
 *
 * Returns 0 when Elementor isn't installed (post_type doesn't exist). Cheap:
 * single WP_Query with meta-exists check and fields=ids; result count via
 * found_posts. Always returns an int so the field is reliable across calls.
 */
function iato_mcp_count_theme_builder_templates(): int {
	if ( ! post_type_exists( 'elementor_library' ) ) {
		return 0;
	}
	$query = new WP_Query( [
		'post_type'              => 'elementor_library',
		'post_status'            => 'publish',
		'posts_per_page'         => 1, // we only need found_posts; no need to materialise rows
		'fields'                 => 'ids',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, single meta key, indexed in typical setups.
		'meta_query'             => [
			[
				'key'     => '_elementor_template_type',
				'compare' => 'EXISTS',
			],
		],
	] );
	return (int) $query->found_posts;
}

// ── get_site_settings ─────────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'get_site_settings',
	[
		'description' => 'Returns site title, tagline, admin email, timezone, permalink structure, and what the home page shows (show_on_front, page_on_front, page_for_posts). Requires administrator.',
		'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass(), 'required' => [] ],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) return $cap_check;

		// title, tagline, permalink_structure are returned raw — sanitize_text_field
		// runs _sanitize_text_fields which strips %[a-f0-9]{2} octets, destroying
		// legitimate placeholder/literal content in these fields (e.g. /%category%/
		// in permalink_structure). admin_email and timezone retain sanitize_text_field
		// because their value types cannot legitimately carry %xx. JSON encoding in
		// IATO_MCP_Server::ok() handles transport-level escaping. See v1.8.2 changelog.
		return IATO_MCP_Server::ok( [
			'title'               => (string) get_option( 'blogname', '' ),
			'tagline'             => (string) get_option( 'blogdescription', '' ),
			'admin_email'         => sanitize_text_field( get_option( 'admin_email', '' ) ),
			'timezone'            => sanitize_text_field( get_option( 'timezone_string', '' ) ),
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			'show_on_front'       => (string) get_option( 'show_on_front', 'posts' ),
			'page_on_front'       => (int) get_option( 'page_on_front', 0 ),
			'page_for_posts'      => (int) get_option( 'page_for_posts', 0 ),
		] );
	}
);

// ── update_site_settings ──────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_site_settings',
	[
		'description' => 'Sets the site title, the tagline, and what the home page shows. Send only what should change: title, tagline, homepage_displays ("posts" or "page"), front_page_id (a published page; required when homepage_displays is "page"), posts_page_id (a published page other than the front page, or 0 to clear). Any other key is refused. Accepts dry_run. Returns the before and after value of each option, a change receipt per option changed (roll back with the rollback tool), and a cache object: the whole site is purged. Requires administrator.',
		'inputSchema' => [
			'type'                 => 'object',
			'properties'           => [
				'title'             => [ 'type' => 'string', 'description' => 'Site title (blogname).' ],
				'tagline'           => [ 'type' => 'string', 'description' => 'Tagline (blogdescription).' ],
				'homepage_displays' => [ 'type' => 'string', 'enum' => [ 'posts', 'page' ], 'description' => 'What the home page shows (show_on_front).' ],
				'front_page_id'     => [ 'type' => 'integer', 'description' => 'The static front page (page_on_front): a published page. Required when homepage_displays is "page".' ],
				'posts_page_id'     => [ 'type' => 'integer', 'description' => 'The posts page (page_for_posts): a published page other than the front page, or 0 to clear.' ],
				'dry_run'           => [ 'type' => 'boolean', 'description' => 'Report what would change without writing.' ],
			],
			'required'             => [],
			'additionalProperties' => false,
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$planned = iato_mcp_plan_site_settings( $args );
		if ( is_wp_error( $planned ) ) {
			return $planned;
		}

		if ( ! empty( $args['dry_run'] ) ) {
			$changes = [];
			foreach ( $planned as $input => $plan ) {
				$changes[ $input ] = [ 'option' => $plan['option'], 'before' => $plan['before'], 'after' => $plan['stored'] ];
			}
			return IATO_MCP_Server::ok( [ 'dry_run' => true, 'changes' => (object) $changes ] );
		}

		$changes  = [];
		$receipts = [];
		foreach ( $planned as $input => $plan ) {
			update_option( $plan['option'], $plan['value'] ); // update_option() does not unslash
			// What WordPress stored (it runs esc_html on the title and tagline): the
			// rollback conflict check compares the stored value with the receipt's.
			$after             = (string) get_option( $plan['option'] );
			$changes[ $input ] = [ 'option' => $plan['option'], 'before' => $plan['before'], 'after' => $after ];
			$receipts[]        = IATO_MCP_Change_Receipt::record( null, 'site_setting', $plan['option'], $plan['before'], $after );
		}
		if ( isset( $planned['front_page_id'] ) ) {
			flush_rewrite_rules( false ); // wp-admin does this from a hook that is not loaded on REST requests
		}

		$data = [ 'changes' => (object) $changes ];
		if ( 1 === count( $receipts ) ) {
			IATO_MCP_Change_Receipt::append( $data, $receipts[0] );
		} elseif ( count( $receipts ) > 1 ) {
			$data['change_receipts'] = $receipts;
		}
		return IATO_MCP_Server::ok( $data );
	}
);

/**
 * Validate every requested setting before anything is written, and drop those
 * that would not change.
 *
 * @return array<string,array{option:string,value:mixed,before:string,stored:string}>|WP_Error
 */
function iato_mcp_plan_site_settings( array $args ): array|WP_Error {
	$options = [
		'title'             => 'blogname',
		'tagline'           => 'blogdescription',
		'front_page_id'     => 'page_on_front',
		'posts_page_id'     => 'page_for_posts',
		'homepage_displays' => 'show_on_front',
	];

	$unknown = array_values( array_diff( array_map( 'strval', array_keys( $args ) ), array_keys( $options ), [ 'dry_run' ] ) );
	if ( [] !== $unknown ) {
		return new WP_Error(
			'unknown_setting',
			sprintf( 'update_site_settings does not accept: %s. It accepts title, tagline, homepage_displays, front_page_id and posts_page_id.', implode( ', ', $unknown ) ),
			[ 'status' => 400 ]
		);
	}
	if ( [] === array_intersect_key( $options, $args ) ) {
		return new WP_Error( 'no_settings', 'Send at least one of title, tagline, homepage_displays, front_page_id or posts_page_id.', [ 'status' => 400 ] );
	}

	$values = [];
	foreach ( [ 'title', 'tagline' ] as $key ) {
		if ( array_key_exists( $key, $args ) ) {
			if ( ! is_string( $args[ $key ] ) ) {
				return new WP_Error( 'invalid_setting', "{$key} must be a string.", [ 'status' => 400 ] );
			}
			// Not sanitize_text_field(): it strips %xx octets out of legitimate text (see
			// get_site_settings). WordPress' sanitize_option() runs on the write.
			$values[ $key ] = wp_strip_all_tags( $args[ $key ] );
		}
	}

	if ( array_key_exists( 'homepage_displays', $args ) ) {
		if ( ! in_array( $args['homepage_displays'], [ 'posts', 'page' ], true ) ) {
			return new WP_Error( 'invalid_setting', 'homepage_displays must be "posts" or "page".', [ 'status' => 400 ] );
		}
		$values['homepage_displays'] = $args['homepage_displays'];
		if ( 'page' === $args['homepage_displays'] && ! array_key_exists( 'front_page_id', $args ) ) {
			return new WP_Error( 'front_page_required', 'front_page_id is required when homepage_displays is "page".', [ 'status' => 400 ] );
		}
	}

	foreach ( [ 'front_page_id', 'posts_page_id' ] as $key ) {
		if ( ! array_key_exists( $key, $args ) ) {
			continue;
		}
		$raw = $args[ $key ];
		if ( ! is_int( $raw ) && ! ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
			return new WP_Error( 'invalid_setting', "{$key} must be a page ID.", [ 'status' => 400 ] );
		}
		$id = (int) $raw;
		if ( ! ( 'posts_page_id' === $key && 0 === $id ) && ! iato_mcp_is_published_page( $id ) ) {
			return new WP_Error( 'invalid_page', "{$key} {$id} is not a published page.", [ 'status' => 400 ] );
		}
		$values[ $key ] = $id;
	}

	$front = $values['front_page_id'] ?? (int) get_option( 'page_on_front', 0 );
	$posts = $values['posts_page_id'] ?? (int) get_option( 'page_for_posts', 0 );
	if ( $front > 0 && $front === $posts && ( isset( $values['front_page_id'] ) || isset( $values['posts_page_id'] ) ) ) {
		return new WP_Error(
			'same_page',
			"Page {$front} cannot be both the front page and the posts page. Send a different page, or posts_page_id 0 to clear the posts page.",
			[ 'status' => 400 ]
		);
	}

	$planned = [];
	foreach ( $options as $key => $option ) {
		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}
		$before = (string) get_option( $option, '' );
		$stored = (string) sanitize_option( $option, $values[ $key ] );
		if ( $stored !== $before ) {
			$planned[ $key ] = [ 'option' => $option, 'value' => $values[ $key ], 'before' => $before, 'stored' => $stored ];
		}
	}
	return $planned;
}

/** Is this a page that exists and is published? */
function iato_mcp_is_published_page( int $page_id ): bool {
	if ( $page_id <= 0 ) {
		return false;
	}
	$page = get_post( $page_id );
	return $page && 'page' === $page->post_type && 'publish' === $page->post_status;
}

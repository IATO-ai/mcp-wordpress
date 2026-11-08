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
		'description' => 'Returns site name, URL, WordPress version, the IATO MCP plugin version running on the site (plugin_version, added v1.19.2: one call confirms a deploy), active theme, and for administrators (manage_options) the active plugin count and the Theme Builder template count (otherwise listed in restricted_fields). The theme_builder_template_count field (added v1.11.0) counts elementor_library posts that are actually Theme Builder templates (have a non-empty _elementor_template_type meta) — excluding saved sections, reusable blocks, and condition-less popups, since those don\'t shadow URLs. Use list_elementor_templates (manage_options) for the full enumeration once you know templates exist. The cache object (added v1.15.0) lists the page cache plugins found (detected) and notes about caches IATO cannot purge, such as a CDN; write tools purge the detected ones and report the result in their own cache object.',
		'inputSchema' => [ 'type' => 'object', 'properties' => new stdClass(), 'required' => [] ],
	],
	function ( array $args ): array|WP_Error {
		$info = [
			'name'           => sanitize_text_field( get_bloginfo( 'name' ) ),
			'url'            => site_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			// The code PHP has loaded, so it answers whether an update is live. Not
			// sensitive: the plugin's readme.txt names it to anyone.
			'plugin_version' => IATO_MCP_VERSION,
			'active_theme'   => wp_get_theme()->get( 'Name' ),
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
		'description' => 'Returns site title, tagline, admin email, timezone, permalink structure, the category and tag bases (category_base, tag_base: "" means WordPress\'s default, "category" and "tag"), what the home page shows (show_on_front, page_on_front, page_for_posts), the site icon (site_icon: id and a 512px url, or null) and the logo (custom_logo: id and url, or null). A null url means the image no longer exists. From 512px up WordPress uses the full image, so an icon larger than that has a larger url. Requires administrator.',
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
			'category_base'       => (string) get_option( 'category_base', '' ),
			'tag_base'            => (string) get_option( 'tag_base', '' ),
			'show_on_front'       => (string) get_option( 'show_on_front', 'posts' ),
			'page_on_front'       => (int) get_option( 'page_on_front', 0 ),
			'page_for_posts'      => (int) get_option( 'page_for_posts', 0 ),
			'site_icon'           => IATO_MCP_Site_Image::report( 'site_icon' ),
			'custom_logo'         => IATO_MCP_Site_Image::report( 'custom_logo' ),
		] );
	}
);

// ── update_site_settings ──────────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'update_site_settings',
	[
		'description' => 'Sets the site title, the tagline, what the home page shows, the site icon, the logo, and the permalink settings. Send only what should change: title, tagline, homepage_displays ("posts" or "page"), front_page_id (a published page; required when homepage_displays is "page"), posts_page_id (a published page other than the front page, or 0 to clear), site_icon, custom_logo. site_icon sets the favicon (the browser tab, bookmark and app icon): a Media Library attachment ID, PNG, JPEG, GIF or WebP. The image must be square and should be 512x512 or larger; it is not cropped. 0 clears it and does not delete the attachment. It also covers Site Favicon in Elementor\'s Site Settings. custom_logo sets the theme\'s logo the same way, with the same image types, and does not have to be square. permalink_structure (such as /blog/%postname%/: starts and ends with /, contains %postname% or %post_id%), category_base and tag_base ("" for the default) move the URL of every post and of the category, tag and author archives, so they need confirm_url_break=true, and 301 redirects from the old URLs are created unless redirect_old_urls is false (at most 1,000; with more, none are created and a warning says so). dry_run lists what moves (moved_count, url_changes). The permalink change has one receipt, whose rollback restores the settings and removes those redirects. Any other key is refused. Accepts dry_run. Returns the before and after value of each setting, a change receipt per setting changed (roll back with the rollback tool), warnings, and a cache object: the whole site is purged. For site_icon it also returns icon_url_32 and icon_url_192. Requires administrator.',
		'inputSchema' => [
			'type'                 => 'object',
			'properties'           => [
				'title'             => [ 'type' => 'string', 'description' => 'Site title (blogname).' ],
				'tagline'           => [ 'type' => 'string', 'description' => 'Tagline (blogdescription).' ],
				'homepage_displays' => [ 'type' => 'string', 'enum' => [ 'posts', 'page' ], 'description' => 'What the home page shows (show_on_front).' ],
				'front_page_id'     => [ 'type' => 'integer', 'description' => 'The static front page (page_on_front): a published page. Required when homepage_displays is "page".' ],
				'posts_page_id'     => [ 'type' => 'integer', 'description' => 'The posts page (page_for_posts): a published page other than the front page, or 0 to clear.' ],
				'site_icon'         => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'The favicon (browser tab, bookmark and app icon): an attachment ID. PNG, JPEG, GIF or WebP; must be square; should be 512x512 or larger. 0 clears it.' ],
				'custom_logo'       => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'The theme\'s logo: an attachment ID. PNG, JPEG, GIF or WebP. 0 clears it.' ],
				'permalink_structure' => [ 'type' => 'string', 'description' => 'The post URL structure, such as /%postname%/ or /blog/%postname%/: starts and ends with /, contains %postname% or %post_id%, and uses only WordPress\'s tags (%year% %monthnum% %day% %hour% %minute% %second% %post_id% %postname% %category% %author%). Plain (empty) is refused. Needs confirm_url_break.' ],
				'category_base'     => [ 'type' => 'string', 'description' => 'The start of category archive URLs, such as "topics" (lowercase letters, numbers, hyphens, slashes), or "" for WordPress\'s default, "category". Needs confirm_url_break.' ],
				'tag_base'          => [ 'type' => 'string', 'description' => 'The start of tag archive URLs, or "" for WordPress\'s default, "tag". Needs confirm_url_break.' ],
				'confirm_url_break' => [ 'type' => 'boolean', 'description' => 'Required true with any permalink change: it moves live URLs.' ],
				'redirect_old_urls' => [ 'type' => 'boolean', 'description' => 'With a permalink change: create a 301 redirect from each moved URL to its new one (default true). At most 1,000; with more, none are created and a warning says so.' ],
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

		$images  = [];
		$planned = iato_mcp_plan_site_settings( $args, $images );
		if ( is_wp_error( $planned ) ) {
			return in_array( $planned->get_error_code(), IATO_MCP_Site_Image::REFUSALS, true ) ? IATO_MCP_Site_Image::refusal( $planned ) : $planned;
		}

		// 1.20.0: the permalink settings. Everything that moves is worked out before any
		// setting is written, so a refusal or a site too large to check changes nothing.
		$move = iato_mcp_plan_permalink_change( $args );
		if ( is_wp_error( $move ) ) {
			return IATO_MCP_Server::refusal( $move );
		}

		if ( ! empty( $args['dry_run'] ) ) {
			$changes = [];
			foreach ( $planned as $input => $plan ) {
				$changes[ $input ] = isset( $images[ $input ] )
					? iato_mcp_site_image_change( $input, (int) $plan['value'], $images[ $input ]['before_id'] )
					: [ 'option' => $plan['option'], 'before' => $plan['before'], 'after' => $plan['stored'] ];
			}
			$preview = iato_mcp_site_image_preview( $images );
			if ( null !== $move ) {
				$changes += iato_mcp_permalink_changes( $move['before'], $move['after'] );
				$preview += IATO_MCP_Permalinks::preview( $move );
				if ( [] !== $move['warnings'] ) {
					$preview['warnings'] = array_merge( $preview['warnings'] ?? [], $move['warnings'] );
				}
			}
			return IATO_MCP_Server::ok( [ 'dry_run' => true, 'changes' => (object) $changes ] + $preview );
		}

		$changes  = [];
		$receipts = [];
		// Before any option: a failure in here leaves every setting as it was.
		$report = iato_mcp_prepare_site_images( $images );
		foreach ( $planned as $input => $plan ) {
			if ( isset( $images[ $input ] ) ) {
				$receipts[]        = iato_mcp_write_site_image( $input, (int) $plan['value'], $plan['before'] );
				$changes[ $input ] = iato_mcp_site_image_change( $input, IATO_MCP_Site_Image::current( $input ), $images[ $input ]['before_id'] );
				continue;
			}
			$warning = IATO_MCP_Elementor_Adapter::update_site_option( $plan['option'], $plan['value'] );
			if ( null !== $warning ) {
				$report['warnings'][] = $warning;
			}
			// What WordPress stored (it runs esc_html on the title and tagline): the
			// rollback conflict check compares the stored value with the receipt's.
			$after             = (string) get_option( $plan['option'] );
			$changes[ $input ] = [ 'option' => $plan['option'], 'before' => $plan['before'], 'after' => $after ];
			$receipts[]        = IATO_MCP_Change_Receipt::record( null, 'site_setting', $plan['option'], $plan['before'], $after );
		}
		if ( isset( $planned['front_page_id'] ) ) {
			flush_rewrite_rules( false ); // wp-admin does this from a hook that is not loaded on REST requests
		}
		// Last, after the flush above: from here no rules are stored in this request.
		$permalinks = null;
		if ( null !== $move ) {
			$permalinks = IATO_MCP_Permalinks::commit( $move );
			$changes   += iato_mcp_permalink_changes( $move['before'], $move['after'] );
			$receipts[] = $permalinks['receipt'];
		}

		$data = [ 'changes' => (object) $changes ] + iato_mcp_finish_site_images( $images, $report );
		if ( null !== $permalinks ) {
			$data += $permalinks['report'];
			if ( [] !== $permalinks['warnings'] ) {
				$data['warnings'] = array_merge( $data['warnings'] ?? [], $permalinks['warnings'] );
			}
		}
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
 * @param array $images Filled in: site_icon and custom_logo as sent, whether or not they change.
 * @return array<string,array{option:string,value:mixed,before:mixed,stored:string}>|WP_Error
 */
function iato_mcp_plan_site_settings( array $args, array &$images = [] ): array|WP_Error {
	$options = [
		'title'             => 'blogname',
		'tagline'           => 'blogdescription',
		'front_page_id'     => 'page_on_front',
		'posts_page_id'     => 'page_for_posts',
		'homepage_displays' => 'show_on_front',
		'site_icon'         => 'site_icon',
		'custom_logo'       => 'custom_logo',
	];

	$permalink_keys = array_merge( IATO_MCP_Permalinks::KEYS, IATO_MCP_Permalinks::FLAGS );
	$unknown        = array_values( array_diff( array_map( 'strval', array_keys( $args ) ), array_keys( $options ), $permalink_keys, [ 'dry_run' ] ) );
	if ( [] !== $unknown ) {
		return new WP_Error(
			'unknown_setting',
			sprintf( 'update_site_settings does not accept: %s. It accepts title, tagline, homepage_displays, front_page_id, posts_page_id, site_icon, custom_logo, permalink_structure, category_base and tag_base, with confirm_url_break and redirect_old_urls for the last three.', implode( ', ', $unknown ) ),
			[ 'status' => 400 ]
		);
	}
	if ( [] === array_intersect_key( $options, $args ) && [] === array_intersect_key( array_flip( IATO_MCP_Permalinks::KEYS ), $args ) ) {
		return new WP_Error( 'no_settings', 'Send at least one of title, tagline, homepage_displays, front_page_id, posts_page_id, site_icon, custom_logo, permalink_structure, category_base or tag_base.', [ 'status' => 400 ] );
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

	foreach ( array_keys( IATO_MCP_Site_Image::KIT_SETTINGS ) as $key ) {
		if ( array_key_exists( $key, $args ) ) {
			$checked = IATO_MCP_Site_Image::check( $key, $args[ $key ] );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$images[ $key ] = $checked + [ 'before_id' => IATO_MCP_Site_Image::current( $key ) ];
		}
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
	// Last: another plugin's hook on an option can throw after that value is stored.
	// An image written before it would have a receipt no one sees.
	foreach ( $images as $key => $image ) {
		if ( $image['id'] !== $image['before_id'] ) {
			$planned[ $key ] = [
				'option' => $key,
				'value'  => $image['id'],
				// A logo belongs to the theme it was set on (see the rollback).
				'before' => 'custom_logo' === $key
					? [ 'id' => $image['before_id'], 'theme' => IATO_MCP_Site_Image::get()->theme() ]
					: (string) $image['before_id'],
				'stored' => (string) $image['id'],
			];
		}
	}
	return $planned;
}

/**
 * The permalink change a call asks for (1.20.0), worked out in full: null when it names
 * none of the settings or they would not change.
 *
 * @return array|WP_Error|null
 */
function iato_mcp_plan_permalink_change( array $args ): array|WP_Error|null {
	$plan = IATO_MCP_Permalinks::plan( $args );
	if ( null === $plan || is_wp_error( $plan ) ) {
		return $plan;
	}
	if ( ! $plan['changes'] ) {
		return null;
	}
	if ( empty( $args['confirm_url_break'] ) ) {
		return new WP_Error(
			'url_change_requires_confirmation',
			'Changing the permalink settings moves the URL of every post, and of the category, tag and author archives. Pass confirm_url_break=true to proceed (a dry run with it lists what moves). Redirects from the old URLs are created unless redirect_old_urls is false. Nothing was written.',
			[ 'status' => 400 ] + iato_mcp_permalink_changes( $plan['before'], $plan['after'] )
		);
	}
	$redirect = ! array_key_exists( 'redirect_old_urls', $args ) || ! empty( $args['redirect_old_urls'] );
	return IATO_MCP_Permalinks::prepare( $plan['before'], $plan['after'], $redirect );
}

/** The permalink settings' entries in `changes`: those that differ. */
function iato_mcp_permalink_changes( array $before, array $after ): array {
	$changes = [];
	foreach ( IATO_MCP_Permalinks::KEYS as $key ) {
		if ( $before[ $key ] !== $after[ $key ] ) {
			$changes[ $key ] = [ 'option' => $key, 'before' => $before[ $key ], 'after' => $after[ $key ] ];
		}
	}
	return $changes;
}

/** Is this a page that exists and is published? */
function iato_mcp_is_published_page( int $page_id ): bool {
	if ( $page_id <= 0 ) {
		return false;
	}
	$page = get_post( $page_id );
	return $page && 'page' === $page->post_type && 'publish' === $page->post_status;
}

/** A setting's entry in `changes`: the attachment ID and its URL on both sides. */
function iato_mcp_site_image_change( string $setting, int $after, int $before ): array {
	return [
		'option' => $setting,
		'before' => IATO_MCP_Site_Image::describe( $before ),
		'after'  => IATO_MCP_Site_Image::describe( $after ),
	];
}

/** What a dry run can say about the images. Also what the real write would do with the same IDs. */
function iato_mcp_site_image_preview( array $images ): array {
	if ( [] === $images ) {
		return [];
	}
	$report = [ 'warnings' => [] ];
	$synced = [];
	foreach ( $images as $setting => $image ) {
		$report['warnings'] = array_merge( $report['warnings'], $image['warnings'] );
		if ( [] !== $image['missing_sizes'] ) {
			$report['would_create_sizes'] = $image['missing_sizes'];
		}
		if ( IATO_MCP_Site_Image::kit_differs( $setting, $image['id'] ) ) {
			$synced[] = IATO_MCP_Site_Image::KIT_SETTINGS[ $setting ];
		}
	}
	if ( [] !== $synced ) {
		$report['would_sync_elementor_settings'] = $synced;
	}
	if ( isset( $report['would_create_sizes'] ) ) {
		$report['not_checked'] = [ 'whether this server can create the image sizes' ];
	}
	return $report;
}

/**
 * Create the icon's missing sizes, even when the setting itself does not change:
 * Elementor's Site Favicon sets the option and creates none.
 *
 * @return array{sizes_created?:string[],warnings?:string[]}
 */
function iato_mcp_prepare_site_images( array $images ): array {
	if ( [] === $images ) {
		return [];
	}
	$report = [ 'warnings' => [] ];
	foreach ( $images as $image ) {
		$report['warnings'] = array_merge( $report['warnings'], $image['warnings'] );
		if ( [] === $image['missing_sizes'] ) {
			continue;
		}
		$created                 = IATO_MCP_Site_Image::get()->make_sizes( $image['id'], $image['missing_sizes'] );
		$report['sizes_created'] = $created;
		if ( [] !== $created && $image['id'] === $image['before_id'] ) {
			// No receipt will say so, and cached pages still link to the larger images.
			IATO_MCP_Cache::note_write( 'site_setting', null, 'site_icon', null, $created );
		}
		$failed = array_values( array_diff( $image['missing_sizes'], $created ) );
		if ( [] !== $failed ) {
			$report['warnings'][] = sprintf( 'WordPress could not create these sizes of attachment %1$d: %2$s. The image is still set, and browsers will get a larger size.', $image['id'], implode( ', ', $failed ) );
		}
	}
	return $report;
}

/** Write one setting and return its receipt, which holds what was stored. */
function iato_mcp_write_site_image( string $setting, int $id, mixed $before ): array {
	if ( 'custom_logo' === $setting ) {
		IATO_MCP_Site_Image::get()->set_logo( $id );
		return IATO_MCP_Change_Receipt::record(
			null,
			'site_setting',
			'custom_logo',
			$before,
			[ 'id' => IATO_MCP_Site_Image::current( 'custom_logo' ), 'theme' => IATO_MCP_Site_Image::get()->theme() ]
		);
	}
	update_option( 'site_icon', $id );
	return IATO_MCP_Change_Receipt::record( null, 'site_setting', 'site_icon', $before, (string) IATO_MCP_Site_Image::current( 'site_icon' ) );
}

/** After the writes: Elementor's Site Settings brought in line, and the icon's URLs as WordPress now gives them. */
function iato_mcp_finish_site_images( array $images, array $report ): array {
	if ( [] === $images ) {
		return $report;
	}
	$synced = [];
	foreach ( $images as $setting => $image ) {
		$sync = IATO_MCP_Site_Image::sync_kit( $setting, $image['id'] );
		if ( $sync['synced'] ) {
			$synced[] = IATO_MCP_Site_Image::KIT_SETTINGS[ $setting ];
		}
		if ( null !== $sync['warning'] ) {
			$report['warnings'][] = $sync['warning'];
		}
	}
	if ( isset( $images['site_icon'] ) ) {
		$report['icon_url_32']  = IATO_MCP_Site_Image::get()->icon_url( 32 );
		$report['icon_url_192'] = IATO_MCP_Site_Image::get()->icon_url( 192 );
	}
	if ( [] !== $synced ) {
		$report['elementor_settings_synced'] = $synced;
	}
	return $report;
}

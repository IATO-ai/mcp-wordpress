<?php
/**
 * WP Tools: list_elementor_templates, create_elementor_template,
 *           set_elementor_template_conditions.
 *
 * Enumerates Elementor Theme Builder templates (`elementor_library` posts)
 * with their Display Conditions surfaced as both raw condition strings and
 * structured / human-readable form. Closes the discovery gap that motivated
 * Layers 1 and 2: without this tool, a template like Portfolio Archieve
 * (post 309 on garennebigby.dev) could only be found by brute-forcing post
 * IDs until a revision slug leaked the parent.
 *
 * Gated behind `manage_options`. The disclosure level — full structural map
 * of every Theme Builder construct on the site and its targeting rules — is
 * comparable to or greater than `get_site_settings`, which is the existing
 * `manage_options`-gated structural read. Per-resource template questions
 * ("what renders THIS URL") are answered by `resolve_url` at the existing
 * authenticated-only level. Enumeration deserves a strictly higher gate
 * than per-resource lookup.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

IATO_MCP_Server::register_tool(
	'list_elementor_templates',
	[
		'description' => 'Enumerate Elementor Theme Builder templates (elementor_library posts) with their Display Conditions surfaced. Each result includes the template ID, title, type (single/archive/header/footer/404/popup/etc.), edit URL, and conditions array — each condition shown both as the raw stored string (e.g. include/archive/category/1) and a structured parsed shape with resolved target labels (e.g. the category named "Build"). Use this when you need to find a template by what it targets rather than by ID, or to audit the full theme-builder skeleton of a site. Requires manage_options. Per-URL template lookup ("what renders this URL") is handled by resolve_url at the authenticated-only level; this tool exists specifically for the enumeration access pattern.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'template_type'      => [ 'type' => 'string',  'description' => 'Optional filter on _elementor_template_type meta (single|archive|header|footer|404|popup|<other location>).' ],
				'status'             => [ 'type' => 'string',  'description' => 'publish|draft|pending|private|any (default: publish)' ],
				'per_page'           => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default: 50)' ],
				'page'               => [ 'type' => 'integer', 'description' => 'Page number (default: 1)' ],
				'include_conditions' => [ 'type' => 'boolean', 'description' => 'Include the Display Conditions array on each template (default: true). Set false for a cheaper ID/title-only listing.' ],
			],
			'required' => [],
		],
	],
	function ( array $args ): array|WP_Error {
		// Gate. Use the Bearer-auth-compatible helper, not current_user_can() —
		// current_user_can() returns false under Bearer auth because
		// wp_get_current_user() is 0 in that context. Same pattern as v1.3.1 /
		// v1.6.4 / v1.8.x.
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		if ( ! post_type_exists( 'elementor_library' ) ) {
			return IATO_MCP_Server::ok( [
				'templates'   => [],
				'total'       => 0,
				'total_pages' => 0,
				'page'        => 1,
			] );
		}

		$template_type      = isset( $args['template_type'] ) ? sanitize_text_field( (string) $args['template_type'] ) : '';
		$status_in          = isset( $args['status'] )        ? sanitize_text_field( (string) $args['status'] ) : 'publish';
		$per_page           = isset( $args['per_page'] )      ? min( max( absint( $args['per_page'] ), 1 ), 100 ) : 50;
		$page               = isset( $args['page'] )          ? max( absint( $args['page'] ), 1 ) : 1;
		$include_conditions = isset( $args['include_conditions'] ) ? (bool) $args['include_conditions'] : true;

		$query_args = [
			'post_type'      => 'elementor_library',
			'post_status'    => 'any' === $status_in
				? [ 'publish', 'draft', 'pending', 'private' ]
				: $status_in,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];

		if ( '' !== $template_type ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, single meta key, indexed in typical setups.
			$query_args['meta_query'] = [
				[
					'key'   => '_elementor_template_type',
					'value' => $template_type,
				],
			];
		}

		$query = new WP_Query( $query_args );

		$templates = [];
		foreach ( $query->posts as $post ) {
			$template_type_meta = get_post_meta( $post->ID, '_elementor_template_type', true );

			$entry = [
				'id'            => (int) $post->ID,
				'title'         => get_the_title( $post ),
				'slug'          => $post->post_name,
				'status'        => $post->post_status,
				'template_type' => ( is_string( $template_type_meta ) && '' !== $template_type_meta ) ? $template_type_meta : null,
				'modified'      => $post->post_modified_gmt,
				'edit_url'      => admin_url( 'post.php?post=' . $post->ID . '&action=elementor' ),
			];

			if ( $include_conditions ) {
				$entry['conditions'] = iato_mcp_format_template_conditions( $post->ID );
				// 1.17.0: for if_revision on set_elementor_template_conditions.
				$entry['conditions_revision'] = IATO_MCP_Theme_Builder::conditions_revision( IATO_MCP_Theme_Builder::stored_conditions( (int) $post->ID ) );
			}

			$templates[] = $entry;
		}

		return IATO_MCP_Server::ok( [
			'templates'   => $templates,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
		] );
	}
);

/**
 * Read the `_elementor_conditions` meta for a template and return it as the
 * tool's `conditions` array shape: each entry has `raw` (the literal stored
 * string) and `parsed` (the structured representation, or null when the
 * parser doesn't recognize the format).
 *
 * Implemented as a file-local helper rather than an additional public method
 * on the router class — keeps the response-shape concerns owned by the tool
 * file. The actual condition parsing lives in
 * IATO_MCP_Elementor_Router::parse_condition_string().
 *
 * @return list<array{raw:string,parsed:?array}>
 */
function iato_mcp_format_template_conditions( int $template_id ): array {
	$raw_conditions = get_post_meta( $template_id, '_elementor_conditions', true );
	if ( ! is_array( $raw_conditions ) ) {
		return [];
	}

	$out = [];
	foreach ( $raw_conditions as $condition_string ) {
		$cs = (string) $condition_string;
		$out[] = [
			'raw'    => $cs,
			'parsed' => IATO_MCP_Elementor_Router::parse_condition_string( $cs ),
		];
	}
	return $out;
}

/**
 * The overlap warning, for a template's response. It only reads, so a dry run can use it.
 *
 * @param string[] $conditions As stored, or as they would be.
 */
function iato_mcp_template_overlap_warning( int $template_id, string $location, array $conditions ): array {
	$overlaps = IATO_MCP_Theme_Builder::overlaps( $template_id, $location, $conditions );
	if ( [] === $overlaps ) {
		return [];
	}
	return [
		'overlap_warnings' => $overlaps,
		'overlap_note'     => 'Another published template may show on some of the same pages. Elementor Pro shows one per URL: on the pages both cover, the narrower condition wins, and on a tie whichever comes first in its cache, which can change. Change or remove one of them if that is not what you want.',
	];
}

/** Add warnings to a response, only when there are some. */
function iato_mcp_template_warnings( array $warnings ): array {
	return [] === $warnings ? [] : [ 'warnings' => array_values( $warnings ) ];
}

// ── create_elementor_template ────────────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'create_elementor_template',
	[
		'description' => 'Create an Elementor library template the way Add New Template does: header, footer, single-post, single-page, archive, search-results, error-404, popup, section, container or page. Theme Builder types need Elementor Pro. It goes live last: a template is built as a draft, filled, given its conditions, and only then published, and a failure on the way leaves nothing showing. A header or footer cannot be published empty with conditions, because it would replace the theme\'s with nothing: create a draft, fill it, then publish. Supports dry_run. Requires administrator.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'title'          => [ 'type' => 'string', 'description' => 'Template title (required).' ],
				'template_type'  => [ 'type' => 'string', 'description' => 'header, footer, single-post, single-page, archive, search-results, error-404, popup, section, container or page (required). A refusal lists what this site registers.' ],
				'status'         => [ 'type' => 'string', 'description' => 'draft (default) or publish. A draft shows nowhere, whatever its conditions.' ],
				'elementor_data' => [ 'type' => 'string', 'description' => 'Optional full Elementor JSON, stored through the same path as update_elementor_data.' ],
				'conditions'     => [ 'type' => 'array', 'items' => IATO_MCP_Theme_Builder::CONDITION_ITEMS, 'description' => 'Optional display conditions: rules such as {"type":"include","name":"general"} or {"type":"exclude","name":"singular","sub_name":"page","sub_id":42}, or strings such as "include/general". Not for section, container or page.' ],
				'dry_run'        => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'title', 'template_type' ],
		],
	],
	function ( array $args ): array|WP_Error {
		// Theme Builder affects the whole site.
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		// Elementor's Document::save() refuses user 0: the post would have no version and no type term.
		$bound = IATO_MCP_Auth::require_bound_user();
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		$builder = IATO_MCP_Theme_Builder::get();
		$types   = $builder->registered_types();
		if ( null === $types ) {
			return IATO_MCP_Theme_Builder::refusal( 'elementor_required', 'Elementor is not active on this site.' );
		}

		$title = is_string( $args['title'] ?? null ) ? sanitize_text_field( $args['title'] ) : '';
		if ( '' === $title ) {
			return IATO_MCP_Theme_Builder::refusal( 'missing_title', 'title is required.' );
		}

		$type = $args['template_type'] ?? '';
		$type = is_string( $type ) ? $type : '';
		$pro_missing = $builder->missing_pro();
		if ( in_array( $type, IATO_MCP_Theme_Builder::THEME_TYPES, true ) && null !== $pro_missing ) {
			return IATO_MCP_Theme_Builder::refusal( 'elementor_pro_required', $pro_missing, [ 'template_type' => $type ] );
		}
		$allowed = array_values( array_intersect( array_merge( IATO_MCP_Theme_Builder::THEME_TYPES, IATO_MCP_Theme_Builder::LIBRARY_TYPES ), array_keys( $types ) ) );
		if ( ! in_array( $type, $allowed, true ) ) {
			return IATO_MCP_Theme_Builder::refusal(
				'unknown_template_type',
				sprintf( 'Unknown template_type "%1$s". This site offers: %2$s.', $type, implode( ', ', $allowed ) ),
				[ 'allowed' => $allowed ]
			);
		}

		$status = $args['status'] ?? 'draft';
		if ( 'draft' !== $status && 'publish' !== $status ) {
			return IATO_MCP_Theme_Builder::refusal( 'invalid_status', 'status must be draft or publish.' );
		}
		if ( 'publish' === $status ) {
			$publish_check = IATO_MCP_Auth::require_publish_cap( 'elementor_library' );
			if ( is_wp_error( $publish_check ) ) {
				return $publish_check;
			}
		}

		// Everything that can be refused is, before the post exists. Only Elementor's own
		// save has to wait for it, and a failure there trashes the post.
		$tree = null;
		if ( isset( $args['elementor_data'] ) && '' !== $args['elementor_data'] ) {
			if ( ! is_string( $args['elementor_data'] ) ) {
				return new WP_Error( 'invalid_json', 'elementor_data must be a string holding JSON, not an array or object. Nothing was written.' );
			}
			$decoded = json_decode( $args['elementor_data'], true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'invalid_json', 'Invalid JSON: ' . json_last_error_msg() );
			}
			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'invalid_json', 'elementor_data must be a JSON array of elements.' );
			}
			$tree  = IATO_MCP_Elementor_Adapter::force_arrays( $decoded );
			$shape = IATO_MCP_Elementor_Adapter::tree_shape_error( $tree );
			if ( null !== $shape ) {
				return IATO_MCP_Elementor_Adapter::tool_error( $shape );
			}
		}

		$location   = $types[ $type ]['location'];
		$conditions = [];
		if ( array_key_exists( 'conditions', $args ) ) {
			if ( null === $location && [] !== $args['conditions'] ) {
				return IATO_MCP_Theme_Builder::refusal( 'conditions_not_supported', sprintf( 'A %s has no display location, so display conditions would do nothing. It is placed by hand or with the Template widget.', $type ) );
			}
			$registered = null === $location ? [] : (array) $builder->conditions_config();
			$conditions = IATO_MCP_Theme_Builder::normalize_conditions( $args['conditions'], $registered );
			if ( is_wp_error( $conditions ) ) {
				return IATO_MCP_Theme_Builder::refusal( (string) $conditions->get_error_code(), (string) $conditions->get_error_message(), (array) $conditions->get_error_data() );
			}
		}

		// An empty header or footer still replaces the theme's on the pages it matches.
		$has_include = [] !== array_filter( $conditions, static fn( string $c ): bool => str_starts_with( $c, 'include/' ) );
		if ( 'publish' === $status && $has_include && in_array( $location, [ 'header', 'footer' ], true ) && empty( $tree ) ) {
			return IATO_MCP_Theme_Builder::refusal(
				'empty_template',
				sprintf( "Publishing an empty %1\$s with conditions would remove the theme's %1\$s from those pages. Create it as a draft, fill it with update_elementor_data, then publish it, or pass elementor_data here.", $type )
			);
		}

		$preview = [];
		if ( null !== $tree ) {
			$preview = $builder->preview_tree( $tree );
			if ( is_wp_error( $preview ) ) {
				return IATO_MCP_Elementor_Adapter::tool_error( $preview );
			}
		}

		$overlap = null !== $location && [] !== $conditions ? iato_mcp_template_overlap_warning( 0, $location, $conditions ) : [];

		if ( ! empty( $args['dry_run'] ) ) {
			return IATO_MCP_Server::ok( array_merge(
				[
					'dry_run'       => true,
					'action'        => 'would_create',
					'title'         => $title,
					'template_type' => $type,
					'status'        => $status,
					'conditions'    => $conditions,
				],
				$preview,
				$overlap,
				null === $location ? [] : iato_mcp_template_warnings( IATO_MCP_Theme_Builder::warnings( 0, $location, $conditions, $status, empty( $tree ) ) ),
				[ 'not_checked' => array_merge( IATO_MCP_Server::DRY_RUN_NOT_CHECKED, [ "What Elementor's own save makes of elementor_data, which needs the template to exist." ] ) ]
			) );
		}

		// A draft first: nothing shows until the last step.
		$id = $builder->create_document( $type, $title );
		if ( is_wp_error( $id ) ) {
			return IATO_MCP_Theme_Builder::refusal( (string) $id->get_error_code(), (string) $id->get_error_message() );
		}

		$warnings = [];
		$report   = [];
		$stale    = false;
		try {
			if ( null !== $tree ) {
				$report = $builder->store_tree( $id, $tree );
				if ( is_wp_error( $report ) ) {
					return iato_mcp_template_take_down( $id, $report );
				}
			}
			if ( [] !== $conditions ) {
				$saved = $builder->save_conditions( $id, $conditions );
				if ( is_wp_error( $saved ) ) {
					if ( 'cache_not_regenerated' !== $saved->get_error_code() ) {
						return iato_mcp_template_take_down( $id, $saved );
					}
					$stale      = true;
					$warnings[] = $saved->get_error_message() . ' It will not show until that is done: send the same conditions to set_elementor_template_conditions.';
				}
			}
			if ( 'publish' === $status ) {
				// Another plugin's save_post hook runs in here, after the row says published.
				$published = IATO_MCP_Auth::update_post_fields( [ 'ID' => $id, 'post_status' => 'publish' ] );
				if ( is_wp_error( $published ) ) {
					return iato_mcp_template_take_down( $id, $published );
				}
			}
		} catch ( \Throwable $e ) {
			return iato_mcp_template_take_down( $id, new WP_Error( 'template_create_failed', 'Another plugin failed while the template was being made: ' . $e->getMessage() ) );
		}

		$stored_title  = (string) get_post_field( 'post_title', $id );
		$stored_status = (string) get_post_status( $id );
		$response      = [
			'id'                  => $id,
			'title'               => $stored_title,
			'edit_url'            => admin_url( 'post.php?post=' . $id . '&action=elementor' ),
			'template_type'       => $type,
			'status'              => $stored_status,
			'conditions'          => $conditions,
			'conditions_revision' => IATO_MCP_Theme_Builder::conditions_revision( $conditions ),
		] + $report + $overlap;
		if ( null !== $location ) {
			$response += $stale ? [ 'verified' => false, 'verified_reason' => 'cache_not_regenerated' ] : IATO_MCP_Theme_Builder::verify( $id, $location, $conditions );
			$warnings  = array_merge( $warnings, IATO_MCP_Theme_Builder::warnings( $id, $location, $conditions, $stored_status ) );
		}
		$response += iato_mcp_template_warnings( $warnings );

		// Last: rollback compares the template with what this records.
		$receipt = IATO_MCP_Change_Receipt::record(
			$id, 'elementor_template', 'create', null,
			wp_json_encode( [ 'template_type' => $type, 'title' => $stored_title, 'status' => $stored_status, 'location' => $location, 'conditions' => $conditions ] + IATO_MCP_Theme_Builder::content_hashes( $id ) )
		);
		IATO_MCP_Change_Receipt::append( $response, $receipt );

		return IATO_MCP_Server::ok( $response );
	}
);

/**
 * A step failed: nothing half-made may stay on the site. Each part here may fail too, because
 * what failed may be another plugin's hook, which runs again on the way out, and the caller
 * must learn what is left: a template still published is on the site now.
 */
function iato_mcp_template_take_down( int $id, WP_Error $error ): array {
	$data    = $error->get_error_data();
	$extra   = is_array( $data ) ? $data : [];
	$message = (string) $error->get_error_message();

	$was_live = 'publish' === get_post_status( $id );
	try {
		IATO_MCP_Auth::keeping_post_columns( $id, static fn() => wp_trash_post( $id ) );
	} catch ( \Throwable $e ) {
		unset( $e ); // what matters is the status below
	}

	$builder = IATO_MCP_Theme_Builder::get();
	try {
		if ( null === $builder->missing_pro() ) {
			$rebuilt = $builder->regenerate();
			if ( is_wp_error( $rebuilt ) ) {
				$message .= " Elementor Pro's conditions cache could not be rebuilt: " . $rebuilt->get_error_message();
			}
		}
	} catch ( \Throwable $e ) {
		$message .= " Elementor Pro's conditions cache could not be rebuilt: " . $e->getMessage();
	}
	if ( $was_live ) {
		IATO_MCP_Cache::note_write( 'elementor_template', $id, 'create', null, 'taken down' ); // it was on the site for a moment
	}

	$status = get_post_status( $id );
	if ( false === $status || 'trash' === $status ) {
		$message .= ' The new template was moved to the trash.';
		$extra   += [ 'trashed_template_id' => $id ];
	} elseif ( 'publish' === $status ) {
		$message .= sprintf( ' The new template, ID %d, could not be taken down and is still live on the site. Trash it under Elementor > Templates.', $id );
		$extra   += [ 'template_id' => $id, 'still_live' => true ];
	} else {
		$message .= sprintf( ' The new template, ID %d, could not be trashed. It is still a draft, so it shows nowhere.', $id );
		$extra   += [ 'template_id' => $id ];
	}
	return IATO_MCP_Theme_Builder::refusal( (string) $error->get_error_code(), $message, $extra );
}

// ── set_elementor_template_conditions ────────────────────────────────────────

IATO_MCP_Server::register_tool(
	'set_elementor_template_conditions',
	[
		'description' => 'Set or replace a template\'s Display Conditions through Elementor Pro, which also rebuilds the cache that decides what renders. Returns before, after, conditions_revision and verified, and warns when another published template may show on the same URLs. An empty list removes the conditions. The same conditions again rebuild the cache if it has gone stale (cache_repaired). Supports if_revision and dry_run. Requires administrator and Elementor Pro.',
		'inputSchema' => [
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer', 'description' => 'An elementor_library post, from list_elementor_templates (required).' ],
				'conditions'  => [ 'type' => 'array', 'items' => IATO_MCP_Theme_Builder::CONDITION_ITEMS, 'description' => 'Rules such as {"type":"include","name":"general"} or {"type":"exclude","name":"singular","sub_name":"page","sub_id":42}, or strings such as "include/general" (required). They replace what is there. A refusal lists what Pro registers.' ],
				'if_revision' => [ 'type' => 'string', 'description' => 'Optional. Reject the write with revision_conflict unless this is the current conditions_revision, from list_elementor_templates or an earlier write.' ],
				'dry_run'     => [ 'type' => 'boolean', 'description' => 'Preview without saving (default: false).' ],
			],
			'required' => [ 'id', 'conditions' ],
		],
	],
	function ( array $args ): array|WP_Error {
		$cap_check = IATO_MCP_Auth::require_cap( 'manage_options' );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}
		$bound = IATO_MCP_Auth::require_bound_user();
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		$builder     = IATO_MCP_Theme_Builder::get();
		$pro_missing = $builder->missing_pro();
		if ( null !== $pro_missing ) {
			return IATO_MCP_Theme_Builder::refusal( 'elementor_pro_required', $pro_missing );
		}

		$id   = absint( $args['id'] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'elementor_library' !== (string) ( $post->post_type ?? '' ) ) {
			return IATO_MCP_Theme_Builder::refusal( 'not_a_template', 'id must be an elementor_library post, from list_elementor_templates.' );
		}
		if ( 'trash' === (string) ( $post->post_status ?? '' ) ) {
			return IATO_MCP_Theme_Builder::refusal( 'template_in_trash', 'This template is in the trash. Restore it first.' );
		}
		$object_check = IATO_MCP_Auth::require_cap( 'edit_post', $id );
		if ( is_wp_error( $object_check ) ) {
			return $object_check;
		}
		$location = $builder->location_of( $id );
		if ( null === $location ) {
			return IATO_MCP_Theme_Builder::refusal( 'conditions_not_supported', 'This template has no display location, so display conditions would do nothing. A section or container is placed by hand or with the Template widget.' );
		}
		if ( ! array_key_exists( 'conditions', $args ) ) {
			return IATO_MCP_Theme_Builder::refusal( 'missing_conditions', 'conditions is required. An empty list removes them.' );
		}

		$after = IATO_MCP_Theme_Builder::normalize_conditions( $args['conditions'], (array) $builder->conditions_config() );
		if ( is_wp_error( $after ) ) {
			return IATO_MCP_Theme_Builder::refusal( (string) $after->get_error_code(), (string) $after->get_error_message(), (array) $after->get_error_data() );
		}

		$before  = IATO_MCP_Theme_Builder::stored_conditions( $id );
		$current = IATO_MCP_Theme_Builder::conditions_revision( $before );
		$given   = isset( $args['if_revision'] ) ? (string) $args['if_revision'] : '';
		if ( '' !== $given && $given !== $current ) {
			return IATO_MCP_Theme_Builder::refusal( 'revision_conflict', 'The stored conditions do not match if_revision. Read them again.', [ 'conditions_revision' => $current ] );
		}

		$overlap  = iato_mcp_template_overlap_warning( $id, $location, $after );
		$report   = [ 'id' => $id, 'location' => $location, 'before' => $before, 'after' => $after ];
		$warnings = IATO_MCP_Theme_Builder::warnings( $id, $location, $after, (string) ( $post->post_status ?? '' ) );
		// What decides rendering is Pro's cache, which can fall out of step with the meta.
		$cached   = array_values( array_map( 'strval', (array) ( $builder->cached( $location )[ $id ] ?? [] ) ) );
		$is_stale = $before === $after && $cached !== $after;

		if ( ! empty( $args['dry_run'] ) ) {
			$action = $is_stale ? 'would_repair_cache' : ( $before === $after ? 'no_change' : 'would_update' );
			return IATO_MCP_Server::ok( array_merge(
				[ 'dry_run' => true, 'action' => $action ],
				$is_stale ? [ 'would_repair_cache' => true ] : [],
				$report,
				[ 'conditions_revision' => $current ],
				$overlap,
				iato_mcp_template_warnings( $warnings ),
				[ 'not_checked' => IATO_MCP_Server::DRY_RUN_NOT_CHECKED ]
			) );
		}
		if ( $before === $after ) {
			$repaired = [];
			if ( $is_stale ) {
				$rebuilt = $builder->regenerate();
				if ( is_wp_error( $rebuilt ) ) {
					return IATO_MCP_Theme_Builder::refusal( (string) $rebuilt->get_error_code(), (string) $rebuilt->get_error_message() );
				}
				// No receipt, there is nothing to undo, but what visitors see may change.
				IATO_MCP_Cache::note_write( 'elementor_template', $id, 'conditions', 'stale cache', 'repaired cache' );
				$repaired = [ 'cache_repaired' => true ];
			}
			return IATO_MCP_Server::ok( [ 'action' => $is_stale ? 'cache_repaired' : 'no_change' ] + $repaired + $report + [ 'conditions_revision' => $current ] + $overlap + IATO_MCP_Theme_Builder::verify( $id, $location, $after ) + iato_mcp_template_warnings( $warnings ) );
		}

		$saved = $builder->save_conditions( $id, $after );
		$stale = false;
		if ( is_wp_error( $saved ) ) {
			if ( 'cache_not_regenerated' !== $saved->get_error_code() ) {
				return IATO_MCP_Theme_Builder::refusal( (string) $saved->get_error_code(), (string) $saved->get_error_message() );
			}
			$stale = true;
			array_unshift( $warnings, $saved->get_error_message() . ' It will not show until that is done: send the same conditions again.' );
		}

		$stored   = IATO_MCP_Theme_Builder::stored_conditions( $id );
		$response = [ 'id' => $id, 'location' => $location, 'before' => $before, 'after' => $stored, 'conditions_revision' => IATO_MCP_Theme_Builder::conditions_revision( $stored ) ]
			+ $overlap + ( $stale ? [ 'verified' => false, 'verified_reason' => 'cache_not_regenerated' ] : IATO_MCP_Theme_Builder::verify( $id, $location, $stored ) ) + iato_mcp_template_warnings( $warnings );
		$receipt  = IATO_MCP_Change_Receipt::record( $id, 'elementor_template', 'conditions', (string) wp_json_encode( $before ), (string) wp_json_encode( $stored ) );
		IATO_MCP_Change_Receipt::append( $response, $receipt );

		return IATO_MCP_Server::ok( $response );
	}
);

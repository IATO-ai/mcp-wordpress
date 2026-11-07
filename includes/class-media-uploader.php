<?php
/**
 * Media uploader — handles the create_media tool's heavy lifting:
 *   - base64 decoding and URL ingestion (with SSRF guards)
 *   - MIME / extension / size / dimension validation
 *   - sideload via wp_handle_sideload()
 *   - intermediate-size generation
 *   - per-user rate limit
 *
 * Returns WP_Error on any rejection; on success returns the rich payload
 * the tool surfaces back to the agent (attachment_id, url, width, height,
 * intermediate_sizes, etc.).
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Media_Uploader {

	private const DEFAULT_MAX_BYTES      = 10485760; // 10 MB
	private const DEFAULT_MAX_DIMENSION  = 8000;
	private const DEFAULT_RATE_LIMIT     = 20; // per minute
	private const URL_FETCH_TIMEOUT      = 10;
	private const URL_FETCH_MAX_REDIRECT = 3;

	/** Image MIME allowlist. SVG is intentionally excluded in this release. */
	private const ALLOWED_MIME_TYPES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	];

	/** How far structure_problem() walks a file. Real images are nowhere near this, and a crafted one must not take seconds. */
	private const MAX_WALK_STEPS   = 200000;
	private const MAX_JPEG_MARKERS = 4096;

	/** Filename substrings that indicate a smuggled script. */
	private const FILENAME_DENYLIST = [ '.php', '.phtml', '.phar', '.pl', '.cgi', '.htaccess', '.htpasswd' ];

	/**
	 * Cron hook fired by self::ingest() when defer_subsizes=true.
	 * Generates intermediate sizes + alt text on a non-blocking cron tick so
	 * the original MCP response can return before wp_generate_attachment_metadata
	 * (which can take many seconds on sites with image-optimisation pipelines).
	 *
	 * Wired up in iato-mcp.php via add_action( 'iato_mcp_generate_subsizes', ... ).
	 */
	public static function generate_subsizes_async( int $attachment_id, ?string $alt_text = null, ?string $req_id = null ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			error_log( sprintf( '[iato-mcp create_media] async-subsizes: attachment %d has no file on disk', $attachment_id ) );
			if ( $req_id && class_exists( 'IATO_MCP_Media_Phase_Log' ) ) {
				IATO_MCP_Media_Phase_Log::append_async_phase(
					$req_id,
					[ 'p' => 'async-subsizes-missing-file', 'e' => 0, 'x' => [ 'attachment_id' => $attachment_id ] ]
				);
			}
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$start = microtime( true );
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		if ( null !== $alt_text && '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt_text ) ) );
		}
		$sizes_count = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0;
		$duration    = microtime( true ) - $start;
		error_log( sprintf(
			'[iato-mcp create_media] async-subsizes done attachment=%d sizes=%d duration=%.2fs',
			$attachment_id,
			$sizes_count,
			$duration
		) );
		if ( $req_id && class_exists( 'IATO_MCP_Media_Phase_Log' ) ) {
			IATO_MCP_Media_Phase_Log::append_async_phase(
				$req_id,
				[
					'p' => 'async-subsizes-done',
					'e' => round( $duration, 4 ),
					'x' => [ 'attachment_id' => $attachment_id, 'sizes' => $sizes_count ],
				],
				[ 'outcome' => 'success' ]
			);
		}
	}

	/**
	 * Main entrypoint. Validates and ingests the source, sideloads into the
	 * media library, and returns the response payload or WP_Error.
	 *
	 * Per-phase timings are emitted to error_log under the prefix
	 * `[iato-mcp create_media]` so live debugging of slow paths (typically
	 * wp_generate_attachment_metadata on sites with many image_sizes) can be
	 * done without re-instrumenting code. Logs are tagged with a short request
	 * id so concurrent calls can be distinguished. Pass defer_subsizes=true
	 * to skip the synchronous metadata generation; subsizes will be created
	 * via cron on the next request.
	 *
	 * @param array       $args Tool arguments. Expected keys: filename, mime_type,
	 *                          source ({type,data|url}), sha256?, alt_text?, caption?, title?,
	 *                          description?, attach_to_post?, dry_run?, defer_subsizes?
	 * @param int         $user_id Current user ID for rate-limit accounting.
	 * @param string|null $assembled A finished chunked upload: the file to use in place
	 *                               of source, which this function then consumes. A
	 *                               parameter rather than a source type, so no tool
	 *                               argument can ever name a path.
	 * @param callable|null $on_created Told the attachment's ID as soon as it exists, because
	 *                                  making its image sizes can end the request.
	 * @return array|WP_Error
	 */
	public static function ingest( array $args, int $user_id, ?string $assembled = null, ?callable $on_created = null ): array|WP_Error {
		$req_id   = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		$t_start  = microtime( true );
		$log_prefix = '[iato-mcp create_media:' . $req_id . ']';
		$phases   = [];
		$log = static function ( string $phase, array $extra = [] ) use ( $log_prefix, $t_start, &$phases ): void {
			$elapsed = microtime( true ) - $t_start;
			$pairs   = [];
			foreach ( $extra as $k => $v ) {
				$pairs[] = $k . '=' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
			}
			error_log( sprintf(
				'%s phase=%s elapsed=%.3fs%s',
				$log_prefix,
				$phase,
				$elapsed,
				$pairs ? ' ' . implode( ' ', $pairs ) : ''
			) );
			$phases[] = [
				'p' => $phase,
				'e' => round( $elapsed, 4 ),
				'x' => $extra ? $extra : null,
			];
		};

		try {
		$log( 'enter', [ 'user' => $user_id ] );

		// A chunked upload was checked when it started, and a client that has sent the
		// whole file should not lose it to the limit now.
		$rate_check = null === $assembled ? self::check_rate_limit( $user_id ) : true;
		if ( is_wp_error( $rate_check ) ) {
			$log( 'rejected_rate_limit' );
			return $rate_check;
		}

		$declared_sha = isset( $args['sha256'] ) ? strtolower( trim( (string) $args['sha256'] ) ) : '';
		if ( '' !== $declared_sha && ! self::is_sha256( $declared_sha ) ) {
			$log( 'rejected_invalid_sha256' );
			self::cleanup_temp( (string) $assembled );
			return new WP_Error( 'invalid_sha256', 'sha256 must be the 64-character hex digest of the file. Nothing was created.' );
		}

		// 1. Filename.
		$filename_raw = isset( $args['filename'] ) ? (string) $args['filename'] : '';
		if ( '' === $filename_raw ) {
			$log( 'rejected_missing_filename' );
			return new WP_Error( 'missing_filename', 'filename is required.' );
		}
		if ( self::filename_looks_dangerous( $filename_raw ) ) {
			$log( 'rejected_unsafe_filename', [ 'name' => $filename_raw ] );
			return new WP_Error( 'unsafe_filename', 'Filename contains a disallowed extension or sequence.' );
		}
		$filename = sanitize_file_name( $filename_raw );
		if ( '' === $filename ) {
			$log( 'rejected_invalid_filename' );
			return new WP_Error( 'invalid_filename', 'Sanitized filename is empty.' );
		}

		// 2. Resolve the bytes.
		if ( null !== $assembled ) {
			$source_type = 'chunks';
			$tmp_path    = $assembled;
		} else {
			$source = $args['source'] ?? null;
			if ( ! is_array( $source ) ) {
				$log( 'rejected_missing_source' );
				return new WP_Error( 'missing_source', 'source object is required.' );
			}
			// BC with v1.6.x: infer type from presence of data/url when omitted.
			// v1.7.1 hard-rejected payloads without source.type; restore inference.
			if ( empty( $source['type'] ) ) {
				if ( isset( $source['data'] ) && '' !== $source['data'] ) {
					$source['type'] = 'base64';
				} elseif ( isset( $source['url'] ) && '' !== $source['url'] ) {
					$source['type'] = 'url';
				} else {
					$log( 'rejected_missing_source' );
					return new WP_Error( 'missing_source', 'source must include either data (for base64) or url (for url ingestion).' );
				}
			}
			$source_type = (string) $source['type'];
			$max_bytes   = self::max_upload_bytes();
			$log( 'source_dispatch', [ 'type' => $source_type, 'max_bytes' => $max_bytes ] );

			$tmp_path = match ( $source_type ) {
				'base64' => self::write_base64_to_temp( (string) ( $source['data'] ?? '' ), $filename, $max_bytes ),
				'url'    => self::fetch_url_to_temp( (string) ( $source['url'] ?? '' ), $filename, $max_bytes ),
				default  => new WP_Error( 'invalid_source_type', 'source.type must be base64 or url.' ),
			};
			if ( is_wp_error( $tmp_path ) ) {
				$log( 'rejected_source_resolve', [ 'code' => $tmp_path->get_error_code() ] );
				return $tmp_path;
			}
		}
		$tmp_size = filesize( $tmp_path );
		$log( 'source_resolved', [ 'tmp_size' => $tmp_size ] );

		// The transport can alter bytes on the way here, so say what arrived, and
		// compare it with what was sent when the caller gave a digest.
		$digest = (string) hash_file( 'sha256', $tmp_path );
		$sizes  = [ 'sha256' => $digest, 'decoded_bytes' => (int) $tmp_size ];
		if ( '' !== $declared_sha && ! hash_equals( $declared_sha, $digest ) ) {
			$log( 'rejected_checksum', [ 'code' => 'checksum_mismatch' ] );
			self::cleanup_temp( $tmp_path );
			return new WP_Error(
				'checksum_mismatch',
				sprintf( 'The bytes that arrived (%d, digest %s) are not the file that was sent. Nothing was created. Send it again.', (int) $tmp_size, $digest ),
				[ 'expected_sha256' => $declared_sha ] + $sizes
			);
		}

		// 3. MIME + extension verification.
		$mime_check = self::verify_mime_and_extension( $tmp_path, $filename );
		if ( is_wp_error( $mime_check ) ) {
			$log( 'rejected_mime', [ 'code' => $mime_check->get_error_code() ] );
			self::cleanup_temp( $tmp_path );
			return $mime_check;
		}
		$log( 'mime_ok', [ 'type' => $mime_check ] );

		// 4. Is it the image it says it is, whole, and within the dimension cap?
		$dim_info = @wp_getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a damaged file warns, and output here would corrupt the response
		if ( ! is_array( $dim_info ) || empty( $dim_info[0] ) || empty( $dim_info[1] ) ) {
			$log( 'rejected_unreadable_image' );
			self::cleanup_temp( $tmp_path );
			return new WP_Error( 'invalid_image', 'File does not appear to be a readable image.', $sizes );
		}
		$actual_mime = (string) ( $dim_info['mime'] ?? $mime_check );
		// Not for URLs: a caller naming a URL may not know what it serves.
		$declared_mime = 'url' === $source_type ? '' : self::normalize_mime( (string) ( $args['mime_type'] ?? '' ) );
		if ( '' !== $declared_mime && $declared_mime !== $actual_mime ) {
			$log( 'rejected_declared_mime', [ 'code' => 'invalid_image' ] );
			self::cleanup_temp( $tmp_path );
			return new WP_Error(
				'invalid_image',
				sprintf( 'mime_type says %1$s, but the bytes that arrived are %2$s. Nothing was created.', $declared_mime, $actual_mime ),
				[ 'declared_mime' => $declared_mime, 'actual_mime' => $actual_mime ] + $sizes
			);
		}
		// The size above comes from the header alone, which a damaged file can still have.
		$damage = self::structure_problem( $tmp_path, $actual_mime );
		if ( null !== $damage ) {
			$log( 'rejected_damaged_image', [ 'code' => 'invalid_image', 'reason' => $damage ] );
			self::cleanup_temp( $tmp_path );
			return new WP_Error(
				'invalid_image',
				sprintf( 'The image is damaged: %s. It may have been altered on the way here. Nothing was created.', $damage ),
				[ 'reason' => $damage ] + $sizes
			);
		}
		$max_dim = (int) apply_filters( 'iato_mcp_max_image_dimension', self::DEFAULT_MAX_DIMENSION );
		if ( $dim_info[0] > $max_dim || $dim_info[1] > $max_dim ) {
			$log( 'rejected_dimensions', [ 'w' => $dim_info[0], 'h' => $dim_info[1], 'max' => $max_dim ] );
			self::cleanup_temp( $tmp_path );
			return new WP_Error(
				'image_too_large',
				sprintf( 'Image dimensions %dx%d exceed the %dpx cap.', $dim_info[0], $dim_info[1], $max_dim ),
				[ 'width' => $dim_info[0], 'height' => $dim_info[1] ]
			);
		}
		$log( 'dim_ok', [ 'w' => $dim_info[0], 'h' => $dim_info[1] ] );

		// 5. Dry-run preview.
		if ( ! empty( $args['dry_run'] ) ) {
			$log( 'dry_run_returned' );
			self::cleanup_temp( $tmp_path );
			return [
				'dry_run'   => true,
				'filename'  => $filename,
				'mime_type' => $mime_check,
				'width'     => $dim_info[0],
				'height'    => $dim_info[1],
				'file_size_bytes' => $tmp_size ?: null,
			] + $sizes;
		}

		// 6. Sideload.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = [ 'name' => $filename, 'tmp_name' => $tmp_path ];
		$sideloaded = wp_handle_sideload(
			$file_array,
			[ 'test_form' => false, 'mimes' => self::ALLOWED_MIME_TYPES ]
		);
		if ( isset( $sideloaded['error'] ) ) {
			$log( 'rejected_sideload', [ 'error' => $sideloaded['error'] ] );
			self::cleanup_temp( $tmp_path );
			return new WP_Error( 'sideload_failed', $sideloaded['error'] );
		}
		$log( 'sideload_ok', [ 'file' => basename( $sideloaded['file'] ) ] );

		// 7. Attachment record.
		$attach_to_post = isset( $args['attach_to_post'] ) ? absint( $args['attach_to_post'] ) : 0;
		$title          = isset( $args['title'] ) && '' !== $args['title']
			? sanitize_text_field( (string) $args['title'] )
			: pathinfo( $filename, PATHINFO_FILENAME );

		$attach_data = [
			'post_mime_type' => $sideloaded['type'],
			'post_title'     => $title,
			'post_content'   => isset( $args['description'] ) ? IATO_MCP_Elementor_Sanitizer::clean_markup( (string) $args['description'] ) : '',
			'post_excerpt'   => isset( $args['caption'] ) ? sanitize_text_field( (string) $args['caption'] ) : '',
			'post_status'    => 'inherit',
			'post_parent'    => $attach_to_post,
		];
		// Slashed: wp_insert_attachment() unslashes title, description and caption.
		$attachment_id = wp_insert_attachment( wp_slash( $attach_data ), $sideloaded['file'], $attach_to_post, true );
		if ( is_wp_error( $attachment_id ) ) {
			$log( 'rejected_insert', [ 'code' => $attachment_id->get_error_code() ] );
			// wp_handle_sideload already moved the file into uploads — try to clean it.
			@unlink( $sideloaded['file'] );
			return $attachment_id;
		}
		$log( 'attachment_inserted', [ 'attachment_id' => $attachment_id ] );
		// Counted now: it exists, whatever happens to the image sizes.
		self::increment_rate_counter( $user_id );
		if ( null !== $on_created ) {
			$on_created( (int) $attachment_id );
		}

		$alt_text       = isset( $args['alt_text'] ) ? (string) $args['alt_text'] : '';
		$defer_subsizes = ! empty( $args['defer_subsizes'] );

		if ( $defer_subsizes ) {
			// Schedule subsize generation on the next cron tick. Set the alt
			// text now (cheap) and let the cron handler replay it after the
			// subsizes finish. wp_schedule_single_event is idempotent on
			// duplicate ($timestamp, $hook, $args) tuples.
			if ( '' !== $alt_text ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt_text ) ) );
			}
			wp_schedule_single_event( time(), 'iato_mcp_generate_subsizes', [ $attachment_id, $alt_text, $req_id ] );
			$metadata = [];
			$log( 'subsizes_deferred' );
		} else {
			// Synchronous path: generate intermediate sizes inline. This is the
			// step that historically pushed past Anthropic's MCP gateway timeout
			// on sites with image-optimisation pipelines (ShortPixel, Imagify,
			// etc.) intercepting wp_generate_attachment_metadata.
			$t_meta_start = microtime( true );
			$metadata = wp_generate_attachment_metadata( $attachment_id, $sideloaded['file'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );
			$log( 'subsizes_generated', [
				'duration' => sprintf( '%.3fs', microtime( true ) - $t_meta_start ),
				'count'    => isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0,
			] );

			if ( '' !== $alt_text ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt_text ) ) );
			}
		}

		// A small image legitimately has no sizes, so ask WordPress which it expected.
		$missing_sizes = $defer_subsizes ? [] : array_keys( wp_get_missing_image_subsizes( $attachment_id ) );

		// Build response.
		$intermediates = $defer_subsizes ? [] : self::intermediates_of( $attachment_id, $metadata );

		$log( 'returning', [ 'total' => sprintf( '%.3fs', microtime( true ) - $t_start ), 'deferred' => $defer_subsizes ? 1 : 0 ] );

		$sanitized = [];
		if ( isset( $args['description'] ) ) {
			$entry = IATO_MCP_Sanitize_Report::for_value( 'description', 'html', (string) $args['description'], (string) get_post_field( 'post_content', $attachment_id ) );
			if ( null !== $entry ) {
				IATO_MCP_Sanitize_Report::add_to( $sanitized, [ $entry ] );
			}
		}

		$warning = [];
		if ( [] !== $missing_sizes ) {
			$log( 'subsizes_missing', [ 'sizes' => $missing_sizes ] );
			$warning = [
				'warnings'      => [ sprintf( 'WordPress could not create %d image size(s) for this file (%s), so it may be damaged. Check that it displays correctly.', count( $missing_sizes ), implode( ', ', $missing_sizes ) ) ],
				'missing_sizes' => $missing_sizes,
			];
		}

		return $sanitized + $warning + $sizes + [
			'attachment_id'      => $attachment_id,
			'url'                => wp_get_attachment_url( $attachment_id ),
			'filename'           => basename( $sideloaded['file'] ),
			'mime_type'          => $sideloaded['type'],
			'file_size_bytes'    => filesize( $sideloaded['file'] ) ?: null,
			'width'              => $metadata['width']  ?? $dim_info[0],
			'height'             => $metadata['height'] ?? $dim_info[1],
			'alt_text'           => $alt_text,
			'intermediate_sizes' => $intermediates,
			'subsizes_deferred'  => $defer_subsizes,
		];
		} finally {
			self::flush_phase_log( $req_id, $phases, $t_start, $user_id );
		}
	}

	/** The sizes in an attachment's metadata, as a response gives them. */
	private static function intermediates_of( int $attachment_id, mixed $metadata ): array {
		$intermediates = [];
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_url = trailingslashit( dirname( (string) wp_get_attachment_url( $attachment_id ) ) );
			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				$intermediates[ $size_name ] = [
					'url'    => $base_url . ( $size_info['file'] ?? '' ),
					'width'  => $size_info['width'] ?? null,
					'height' => $size_info['height'] ?? null,
				];
			}
		}
		return $intermediates;
	}

	/**
	 * What ingest() would have returned for an attachment an earlier request created
	 * before it stopped, usually while making image sizes. Those it could not make go
	 * back to WP-Cron, so that doing it again here does not stop this request too.
	 */
	public static function describe_attachment( int $attachment_id, array $args ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$alt_text = isset( $args['alt_text'] ) ? (string) $args['alt_text'] : '';
		if ( '' !== $alt_text && '' === (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt_text ) ) );
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$missing  = array_keys( wp_get_missing_image_subsizes( $attachment_id ) );
		if ( [] !== $missing ) {
			wp_schedule_single_event( time(), 'iato_mcp_generate_subsizes', [ $attachment_id, $alt_text, substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) ] );
		}
		$file = (string) get_attached_file( $attachment_id );
		$size = @wp_getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$size = is_array( $size ) ? $size : [];
		return [
			'recovered'          => true,
			'warnings'           => [ 'An earlier attempt to finish this upload stopped part way. The attachment it created is used.' . ( [] !== $missing ? ' Its image sizes are being created in the background.' : '' ) ],
			'attachment_id'      => $attachment_id,
			'url'                => wp_get_attachment_url( $attachment_id ),
			'filename'           => basename( $file ),
			'mime_type'          => (string) get_post_mime_type( $attachment_id ),
			'file_size_bytes'    => file_exists( $file ) ? filesize( $file ) : null,
			'width'              => $metadata['width'] ?? ( $size[0] ?? null ),
			'height'             => $metadata['height'] ?? ( $size[1] ?? null ),
			'alt_text'           => $alt_text,
			'intermediate_sizes' => self::intermediates_of( $attachment_id, $metadata ),
			'subsizes_deferred'  => [] !== $missing,
		];
	}

	/**
	 * Flush the captured phase trace from a create_media call into the
	 * IATO_MCP_Media_Phase_Log ring buffer. Derives outcome, attachment id,
	 * filename, mime, dims and error_code from the phase stream so the
	 * existing $log call sites stay untouched.
	 *
	 * Wrapped on the storage side in try/catch so a DB failure here cannot
	 * regress create_media itself.
	 */
	private static function flush_phase_log( string $req_id, array $phases, float $t_start, int $user_id ): void {
		if ( empty( $phases ) || ! class_exists( 'IATO_MCP_Media_Phase_Log' ) ) {
			return;
		}

		$attachment_id = 0;
		$filename      = null;
		$mime_type     = null;
		$width         = null;
		$height        = null;
		$bytes         = null;
		$error_code    = null;
		$outcome       = 'unknown';

		foreach ( $phases as $entry ) {
			$name  = (string) ( $entry['p'] ?? '' );
			$extra = isset( $entry['x'] ) && is_array( $entry['x'] ) ? $entry['x'] : [];

			if ( 'attachment_inserted' === $name && isset( $extra['attachment_id'] ) ) {
				$attachment_id = (int) $extra['attachment_id'];
			}
			if ( 'rejected_unsafe_filename' === $name && isset( $extra['name'] ) ) {
				$filename = (string) $extra['name'];
			}
			if ( 'sideload_ok' === $name && isset( $extra['file'] ) ) {
				$filename = (string) $extra['file'];
			}
			if ( 'source_resolved' === $name && isset( $extra['tmp_size'] ) ) {
				$bytes = (int) $extra['tmp_size'];
			}
			if ( 'mime_ok' === $name && isset( $extra['type'] ) ) {
				$mime_type = (string) $extra['type'];
			}
			if ( ( 'dim_ok' === $name || 'rejected_dimensions' === $name ) && isset( $extra['w'], $extra['h'] ) ) {
				$width  = (int) $extra['w'];
				$height = (int) $extra['h'];
			}
			if ( str_starts_with( $name, 'rejected_' ) && isset( $extra['code'] ) ) {
				$error_code = (string) $extra['code'];
			}

			if ( str_starts_with( $name, 'rejected_' ) ) {
				$outcome = 'rejected';
				if ( null === $error_code ) {
					$error_code = $name;
				}
			} elseif ( 'dry_run_returned' === $name ) {
				$outcome = 'dry_run';
			} elseif ( 'subsizes_deferred' === $name ) {
				$outcome = 'deferred';
			} elseif ( 'returning' === $name && 'deferred' !== $outcome ) {
				$outcome = 'success';
			}
		}

		$total_ms = (int) round( ( microtime( true ) - $t_start ) * 1000 );

		IATO_MCP_Media_Phase_Log::record( [
			'req_id'        => $req_id,
			'auth_user_id'  => $user_id,
			'attachment_id' => $attachment_id,
			'filename'      => $filename,
			'mime_type'     => $mime_type,
			'width'         => $width,
			'height'        => $height,
			'bytes'         => $bytes,
			'outcome'       => $outcome,
			'error_code'    => $error_code,
			'total_ms'      => $total_ms,
			'phases'        => $phases,
		] );
	}

	// ── Source resolvers ────────────────────────────────────────────────────────

	private static function write_base64_to_temp( string $b64, string $filename, int $max_bytes ): string|WP_Error {
		if ( '' === $b64 ) {
			return new WP_Error( 'missing_base64', 'source.data is required for base64 source.' );
		}
		// Strip optional data: URI prefix.
		if ( 0 === strncmp( $b64, 'data:', 5 ) ) {
			$comma_pos = strpos( $b64, ',' );
			if ( false !== $comma_pos ) {
				$b64 = substr( $b64, $comma_pos + 1 );
			}
		}
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes ) {
			return new WP_Error( 'invalid_base64', 'source.data is not valid base64.' );
		}
		if ( strlen( $bytes ) > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					'Decoded payload (%d bytes) exceeds the %d-byte cap. For larger uploads, switch to URL ingestion: set source.type=\'url\' and configure the host under Settings > IATO MCP > Media Uploads.',
					strlen( $bytes ),
					$max_bytes
				)
			);
		}
		// wp_tempnam() lives in wp-admin/includes/file.php, which is not loaded on
		// REST requests; without this the base64 path fatals.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'tempfile_failed', 'Could not create temporary file.' );
		}
		$written = file_put_contents( $tmp, $bytes );
		if ( false === $written ) {
			@unlink( $tmp );
			return new WP_Error( 'tempfile_write_failed', 'Could not write decoded bytes to temp file.' );
		}
		return $tmp;
	}

	private static function fetch_url_to_temp( string $url, string $filename, int $max_bytes ): string|WP_Error {
		if ( '' === $url ) {
			return new WP_Error( 'missing_url', 'source.url is required for url source.' );
		}
		if ( ! get_option( 'iato_mcp_media_url_source_enabled', false ) ) {
			return new WP_Error( 'url_source_disabled', 'URL source ingestion is disabled. Enable it in Settings > IATO MCP > Media uploads and add the host to the allowlist.' );
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'invalid_url', 'URL must include scheme and host.' );
		}
		$scheme = strtolower( $parsed['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return new WP_Error( 'invalid_url_scheme', 'Only http(s) URLs are accepted.' );
		}

		$host = strtolower( $parsed['host'] );
		// The site's own host is implicitly trusted — ingesting from the site's
		// own media library is not the SSRF threat the allowlist defends against,
		// and forcing every admin to allowlist their own domain is the most common
		// papercut on this tool. The IP guard at check_host_resolves_publicly()
		// still runs below, so this bypass only skips the *manual* allowlist.
		if ( ! self::is_own_host( $host ) ) {
			$allowlist = (array) get_option( 'iato_mcp_media_url_host_allowlist', [] );
			if ( ! in_array( $host, array_map( 'strtolower', $allowlist ), true ) ) {
				return new WP_Error(
					'host_not_allowed',
					sprintf( 'Host %s is not in the URL ingestion allowlist.', $host )
				);
			}
		}

		$ip_check = self::check_host_resolves_publicly( $host );
		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

		$response = wp_safe_remote_get( $url, [
			'timeout'     => self::URL_FETCH_TIMEOUT,
			'redirection' => self::URL_FETCH_MAX_REDIRECT,
			'httpversion' => '1.1',
			'stream'      => true,
			'filename'    => wp_tempnam( $filename ),
		] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Re-validate every redirect hop's destination — wp_safe_remote_get already
		// blocks loopback / RFC1918 by default, but cloud-metadata IPs (169.254.x)
		// historically slipped through some PHP builds. Belt-and-suspenders check.
		$history = wp_remote_retrieve_header( $response, 'x-redirected-from' );
		if ( $history ) {
			foreach ( (array) $history as $prior_url ) {
				$prior_host = wp_parse_url( $prior_url, PHP_URL_HOST );
				if ( $prior_host ) {
					$re = self::check_host_resolves_publicly( strtolower( $prior_host ) );
					if ( is_wp_error( $re ) ) {
						return $re;
					}
				}
			}
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error( 'fetch_failed', sprintf( 'Upstream returned HTTP %d.', $status ) );
		}

		$tmp = isset( $response['filename'] ) ? (string) $response['filename'] : '';
		if ( '' === $tmp || ! file_exists( $tmp ) ) {
			return new WP_Error( 'fetch_failed', 'Stream destination missing after fetch.' );
		}
		$size = filesize( $tmp ) ?: 0;
		if ( $size > $max_bytes ) {
			@unlink( $tmp );
			return new WP_Error(
				'file_too_large',
				sprintf( 'Fetched payload (%d bytes) exceeds the %d-byte cap.', $size, $max_bytes )
			);
		}
		return $tmp;
	}

	/**
	 * True when $host matches the site's own host (home_url or site_url).
	 * Used to bypass the manual URL-ingestion allowlist for the site itself —
	 * the allowlist defends against arbitrary external hosts, not the site's
	 * own media library.
	 */
	private static function is_own_host( string $host ): bool {
		$self_hosts = array_filter( array_map(
			static fn( $u ) => strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ),
			[ home_url(), site_url() ]
		) );
		return in_array( strtolower( $host ), $self_hosts, true );
	}

	/**
	 * Resolve the host and reject if the IP falls in a private / loopback /
	 * link-local / cloud-metadata range.
	 */
	private static function check_host_resolves_publicly( string $host ): bool|WP_Error {
		$ip = gethostbyname( $host );
		if ( $ip === $host || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'dns_resolution_failed', sprintf( 'Could not resolve %s to an IP address.', $host ) );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new WP_Error(
				'private_ip_rejected',
				sprintf( 'Host %s resolved to a non-public IP (%s) — rejected to prevent SSRF.', $host, $ip )
			);
		}
		// Explicit cloud-metadata range guard — some PHP builds let 169.254.0.0/16 through.
		if ( str_starts_with( $ip, '169.254.' ) ) {
			return new WP_Error(
				'link_local_ip_rejected',
				sprintf( 'Host %s resolved to a link-local IP (%s).', $host, $ip )
			);
		}
		return true;
	}

	// ── MIME + filename guards ──────────────────────────────────────────────────

	private static function verify_mime_and_extension( string $path, string $filename ): string|WP_Error {
		// SVG hard-reject regardless of claimed type.
		if ( false !== stripos( $filename, '.svg' ) ) {
			return new WP_Error( 'svg_not_supported', 'SVG uploads are not supported in this release.' );
		}

		$check = wp_check_filetype_and_ext( $path, $filename, self::ALLOWED_MIME_TYPES );
		if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
			return new WP_Error(
				'mime_mismatch',
				'File contents do not match an allowed image type, or the extension is not recognised.'
			);
		}
		if ( ! in_array( $check['type'], self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error( 'mime_not_allowed', sprintf( 'MIME type %s is not in the allowlist.', $check['type'] ) );
		}
		return (string) $check['type'];
	}

	private static function filename_looks_dangerous( string $filename ): bool {
		$lower = strtolower( $filename );
		foreach ( self::FILENAME_DENYLIST as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Integrity ───────────────────────────────────────────────────────────────

	public static function is_sha256( string $value ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/Di', $value );
	}

	private static function normalize_mime( string $mime ): string {
		$mime = strtolower( trim( $mime ) );
		return 'image/jpg' === $mime ? 'image/jpeg' : $mime;
	}

	/**
	 * What is wrong with an image's structure, or null. getimagesize() reads only the
	 * header, so a file altered in transit passes it and then renders black with no
	 * intermediate sizes. Valid files often carry trailing data or unusual chunks, so
	 * this answers only when sure: anything it cannot follow is left to pass. Pixel
	 * damage inside a JPEG cannot be seen here at all; only a digest finds that.
	 */
	public static function structure_problem( string $path, string $mime ): ?string {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return null;
		}
		try {
			return match ( $mime ) {
				'image/png'  => self::png_problem( $handle ),
				'image/jpeg' => self::jpeg_problem( $handle ),
				'image/gif'  => self::gif_problem( $handle ),
				'image/webp' => self::webp_problem( $handle, (int) filesize( $path ) ),
				default      => null,
			};
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/** Every required chunk's checksum, and the end marker. Optional chunks are not checked: browsers skip a bad one. */
	private static function png_problem( $handle ): ?string {
		if ( "\x89PNG\r\n\x1a\n" !== fread( $handle, 8 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return null;
		}
		$saw_data = false;
		for ( $steps = 0; $steps < self::MAX_WALK_STEPS; $steps++ ) {
			$head = fread( $handle, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $head || 8 !== strlen( $head ) ) {
				return 'the PNG ends before its end marker, so it is cut short';
			}
			$length = unpack( 'N', substr( $head, 0, 4 ) )[1];
			$type   = substr( $head, 4 );
			if ( $length > 0x7FFFFFFF || 1 !== preg_match( '/^[A-Za-z]{4}$/D', $type ) ) {
				return 'the PNG has a chunk that cannot be read';
			}
			$required = ctype_upper( $type[0] );
			if ( ! $required ) {
				if ( 0 !== fseek( $handle, $length + 4, SEEK_CUR ) ) {
					return 'the PNG is cut short';
				}
				continue;
			}
			$hash = hash_init( 'crc32b' );
			hash_update( $hash, $type );
			$left = $length;
			while ( $left > 0 ) {
				$part = fread( $handle, min( $left, 65536 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $part || '' === $part ) {
					return sprintf( 'the PNG is cut short inside its %s chunk', $type );
				}
				hash_update( $hash, $part );
				$left -= strlen( $part );
			}
			$stored = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $stored || 4 !== strlen( $stored ) ) {
				return sprintf( 'the PNG is cut short inside its %s chunk', $type );
			}
			if ( bin2hex( $stored ) !== hash_final( $hash ) ) {
				return sprintf( 'the checksum of the PNG\'s %s chunk does not match its contents', $type );
			}
			$saw_data = $saw_data || 'IDAT' === $type;
			if ( 'IEND' === $type ) {
				return $saw_data ? null : 'the PNG holds no image data';
			}
		}
		return null; // Not a file worth working through.
	}

	/** Walks past the headers, so a thumbnail's end marker in the metadata does not count. */
	private static function jpeg_problem( $handle ): ?string {
		if ( "\xFF\xD8" !== fread( $handle, 2 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return null;
		}
		for ( $steps = 0; ; $steps++ ) {
			if ( $steps >= self::MAX_JPEG_MARKERS ) {
				return null; // Not a file worth working through.
			}
			$marker = fread( $handle, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $marker || 2 !== strlen( $marker ) ) {
				return 'the JPEG is cut short before its image data';
			}
			if ( "\xFF" !== $marker[0] ) {
				return null; // Not a layout this follows.
			}
			$code = ord( $marker[1] );
			if ( 0xDA === $code ) {
				break;
			}
			if ( 0xFF === $code ) {
				fseek( $handle, -1, SEEK_CUR );
				continue;
			}
			if ( 0x01 === $code || ( $code >= 0xD0 && $code <= 0xD9 ) ) {
				continue;
			}
			$size = fread( $handle, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $size || 2 !== strlen( $size ) ) {
				return 'the JPEG is cut short before its image data';
			}
			fseek( $handle, unpack( 'n', $size )[1] - 2, SEEK_CUR );
		}
		$carry = '';
		while ( ! feof( $handle ) ) {
			$part = (string) fread( $handle, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false !== strpos( $carry . $part, "\xFF\xD9" ) ) {
				return null;
			}
			$carry = substr( $part, -1 );
		}
		return 'the JPEG ends before its end marker, so it is cut short';
	}

	/** Walks the blocks to the trailer: the byte for it can appear anywhere in the data. */
	private static function gif_problem( $handle ): ?string {
		$header = fread( $handle, 13 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $header || 13 !== strlen( $header ) || ! in_array( substr( $header, 0, 6 ), [ 'GIF87a', 'GIF89a' ], true ) ) {
			return null;
		}
		$flags = ord( $header[10] );
		if ( $flags & 0x80 ) {
			fseek( $handle, 3 * ( 2 << ( $flags & 7 ) ), SEEK_CUR );
		}
		$cut_short = 'the GIF ends before its end marker, so it is cut short';
		$steps     = 0;
		while ( true ) {
			$block = fread( $handle, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $block || '' === $block ) {
				return $cut_short;
			}
			if ( ';' === $block ) {
				return null;
			}
			if ( '!' === $block ) {
				fseek( $handle, 1, SEEK_CUR );
			} elseif ( ',' === $block ) {
				$descriptor = fread( $handle, 9 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $descriptor || 9 !== strlen( $descriptor ) ) {
					return $cut_short;
				}
				$flags = ord( $descriptor[8] );
				if ( $flags & 0x80 ) {
					fseek( $handle, 3 * ( 2 << ( $flags & 7 ) ), SEEK_CUR );
				}
				fseek( $handle, 1, SEEK_CUR );
			} else {
				return null; // Not a layout this follows.
			}
			while ( true ) {
				if ( ++$steps > self::MAX_WALK_STEPS ) {
					return null; // Not a file worth working through.
				}
				$size = fread( $handle, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $size || '' === $size ) {
					return $cut_short;
				}
				if ( "\0" === $size ) {
					break;
				}
				fseek( $handle, ord( $size ), SEEK_CUR );
			}
		}
	}

	private static function webp_problem( $handle, int $file_size ): ?string {
		$head = fread( $handle, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $head || 12 !== strlen( $head ) || 'RIFF' !== substr( $head, 0, 4 ) || 'WEBP' !== substr( $head, 8, 4 ) ) {
			return null;
		}
		$declared = unpack( 'V', substr( $head, 4, 4 ) )[1] + 8;
		return $declared > $file_size ? sprintf( 'the WebP says it is %1$d bytes but only %2$d arrived, so it is cut short', $declared, $file_size ) : null;
	}

	/**
	 * Checks on a chunked upload before any of it is sent, so a bad one fails first.
	 *
	 * @return string|WP_Error The filename to store.
	 */
	public static function check_new_upload( string $filename_raw, string $mime ): string|WP_Error {
		if ( '' === $filename_raw ) {
			return new WP_Error( 'missing_filename', 'filename is required.' );
		}
		if ( strlen( $filename_raw ) > 200 ) {
			return new WP_Error( 'invalid_filename', 'filename can be at most 200 characters.' );
		}
		if ( self::filename_looks_dangerous( $filename_raw ) ) {
			return new WP_Error( 'unsafe_filename', 'Filename contains a disallowed extension or sequence.' );
		}
		if ( false !== stripos( $filename_raw, '.svg' ) ) {
			return new WP_Error( 'svg_not_supported', 'SVG uploads are not supported in this release.' );
		}
		$filename = sanitize_file_name( $filename_raw );
		if ( '' === $filename ) {
			return new WP_Error( 'invalid_filename', 'Sanitized filename is empty.' );
		}
		$mime = self::normalize_mime( $mime );
		if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'mime_not_allowed',
				sprintf( 'MIME type %s is not in the allowlist.', $mime ),
				[ 'allowed' => array_values( self::ALLOWED_MIME_TYPES ) ]
			);
		}
		$check = wp_check_filetype( $filename, self::ALLOWED_MIME_TYPES );
		if ( $mime !== $check['type'] ) {
			return new WP_Error( 'mime_mismatch', sprintf( 'The filename %1$s does not suit the MIME type %2$s.', $filename, $mime ) );
		}
		return $filename;
	}

	// ── Rate limiting ───────────────────────────────────────────────────────────

	private static function rate_key( int $user_id ): string {
		return 'iato_mcp_upload_count_' . $user_id;
	}

	public static function check_rate_limit( int $user_id ): bool|WP_Error {
		$limit = (int) get_option( 'iato_mcp_media_upload_rate_limit', self::DEFAULT_RATE_LIMIT );
		if ( $limit <= 0 ) {
			return true;
		}
		$count = (int) get_transient( self::rate_key( $user_id ) );
		if ( $count >= $limit ) {
			return new WP_Error(
				'rate_limited',
				sprintf( 'Upload rate limit reached (%d uploads/min). Wait a minute and try again.', $limit ),
				[ 'limit_per_minute' => $limit ]
			);
		}
		return true;
	}

	private static function increment_rate_counter( int $user_id ): void {
		$key   = self::rate_key( $user_id );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	public static function max_upload_bytes(): int {
		$opt = (int) get_option( 'iato_mcp_media_max_upload_size', self::DEFAULT_MAX_BYTES );
		if ( $opt <= 0 ) {
			$opt = self::DEFAULT_MAX_BYTES;
		}
		return (int) apply_filters( 'iato_mcp_max_upload_size', $opt );
	}

	private static function cleanup_temp( string $path ): void {
		if ( '' !== $path && file_exists( $path ) ) {
			@unlink( $path );
		}
	}
}

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
	public static function generate_subsizes_async( int $attachment_id, ?string $alt_text = null ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			error_log( sprintf( '[iato-mcp create_media] async-subsizes: attachment %d has no file on disk', $attachment_id ) );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$start = microtime( true );
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		if ( null !== $alt_text && '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
		}
		error_log( sprintf(
			'[iato-mcp create_media] async-subsizes done attachment=%d sizes=%d duration=%.2fs',
			$attachment_id,
			isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0,
			microtime( true ) - $start
		) );
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
	 * @param array $args Tool arguments. Expected keys: filename, mime_type,
	 *                    source ({type,data|url}), alt_text?, caption?, title?,
	 *                    description?, attach_to_post?, dry_run?, defer_subsizes?
	 * @param int   $user_id Current user ID for rate-limit accounting.
	 * @return array|WP_Error
	 */
	public static function ingest( array $args, int $user_id ): array|WP_Error {
		$req_id   = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		$t_start  = microtime( true );
		$log_prefix = '[iato-mcp create_media:' . $req_id . ']';
		$log = static function ( string $phase, array $extra = [] ) use ( $log_prefix, $t_start ): void {
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
		};
		$log( 'enter', [ 'user' => $user_id ] );

		$rate_check = self::check_rate_limit( $user_id );
		if ( is_wp_error( $rate_check ) ) {
			$log( 'rejected_rate_limit' );
			return $rate_check;
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
		$source = $args['source'] ?? null;
		if ( ! is_array( $source ) || empty( $source['type'] ) ) {
			$log( 'rejected_missing_source' );
			return new WP_Error( 'missing_source', 'source.type is required (base64 or url).' );
		}
		$max_bytes = self::max_upload_bytes();
		$log( 'source_dispatch', [ 'type' => $source['type'], 'max_bytes' => $max_bytes ] );

		$tmp_path = match ( $source['type'] ) {
			'base64' => self::write_base64_to_temp( (string) ( $source['data'] ?? '' ), $filename, $max_bytes ),
			'url'    => self::fetch_url_to_temp( (string) ( $source['url'] ?? '' ), $filename, $max_bytes ),
			default  => new WP_Error( 'invalid_source_type', 'source.type must be base64 or url.' ),
		};
		if ( is_wp_error( $tmp_path ) ) {
			$log( 'rejected_source_resolve', [ 'code' => $tmp_path->get_error_code() ] );
			return $tmp_path;
		}
		$tmp_size = filesize( $tmp_path );
		$log( 'source_resolved', [ 'tmp_size' => $tmp_size ] );

		// 3. MIME + extension verification.
		$mime_check = self::verify_mime_and_extension( $tmp_path, $filename );
		if ( is_wp_error( $mime_check ) ) {
			$log( 'rejected_mime', [ 'code' => $mime_check->get_error_code() ] );
			self::cleanup_temp( $tmp_path );
			return $mime_check;
		}
		$log( 'mime_ok', [ 'type' => $mime_check ] );

		// 4. Dimension cap.
		$dim_info = @getimagesize( $tmp_path );
		if ( ! is_array( $dim_info ) || empty( $dim_info[0] ) || empty( $dim_info[1] ) ) {
			$log( 'rejected_unreadable_image' );
			self::cleanup_temp( $tmp_path );
			return new WP_Error( 'invalid_image', 'File does not appear to be a readable image.' );
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
			];
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
			'post_content'   => isset( $args['description'] ) ? wp_kses_post( (string) $args['description'] ) : '',
			'post_excerpt'   => isset( $args['caption'] ) ? sanitize_text_field( (string) $args['caption'] ) : '',
			'post_status'    => 'inherit',
			'post_parent'    => $attach_to_post,
		];
		$attachment_id = wp_insert_attachment( $attach_data, $sideloaded['file'], $attach_to_post, true );
		if ( is_wp_error( $attachment_id ) ) {
			$log( 'rejected_insert', [ 'code' => $attachment_id->get_error_code() ] );
			// wp_handle_sideload already moved the file into uploads — try to clean it.
			@unlink( $sideloaded['file'] );
			return $attachment_id;
		}
		$log( 'attachment_inserted', [ 'attachment_id' => $attachment_id ] );

		$alt_text       = isset( $args['alt_text'] ) ? (string) $args['alt_text'] : '';
		$defer_subsizes = ! empty( $args['defer_subsizes'] );

		if ( $defer_subsizes ) {
			// Schedule subsize generation on the next cron tick. Set the alt
			// text now (cheap) and let the cron handler replay it after the
			// subsizes finish. wp_schedule_single_event is idempotent on
			// duplicate ($timestamp, $hook, $args) tuples.
			if ( '' !== $alt_text ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
			}
			wp_schedule_single_event( time(), 'iato_mcp_generate_subsizes', [ $attachment_id, $alt_text ] );
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
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
			}
		}

		self::increment_rate_counter( $user_id );

		// Build response.
		$intermediates = [];
		if ( ! $defer_subsizes && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_url = trailingslashit( dirname( wp_get_attachment_url( $attachment_id ) ) );
			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				$intermediates[ $size_name ] = [
					'url'    => $base_url . ( $size_info['file'] ?? '' ),
					'width'  => $size_info['width'] ?? null,
					'height' => $size_info['height'] ?? null,
				];
			}
		}

		$log( 'returning', [ 'total' => sprintf( '%.3fs', microtime( true ) - $t_start ), 'deferred' => $defer_subsizes ? 1 : 0 ] );

		return [
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
				sprintf( 'Decoded payload (%d bytes) exceeds the %d-byte cap.', strlen( $bytes ), $max_bytes )
			);
		}
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

		$allowlist = (array) get_option( 'iato_mcp_media_url_host_allowlist', [] );
		$host      = strtolower( $parsed['host'] );
		if ( ! in_array( $host, array_map( 'strtolower', $allowlist ), true ) ) {
			return new WP_Error(
				'host_not_allowed',
				sprintf( 'Host %s is not in the URL ingestion allowlist.', $host )
			);
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

	// ── Rate limiting ───────────────────────────────────────────────────────────

	private static function rate_key( int $user_id ): string {
		return 'iato_mcp_upload_count_' . $user_id;
	}

	private static function check_rate_limit( int $user_id ): bool|WP_Error {
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

	private static function max_upload_bytes(): int {
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

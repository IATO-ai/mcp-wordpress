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
	 * Main entrypoint. Validates and ingests the source, sideloads into the
	 * media library, and returns the response payload or WP_Error.
	 *
	 * @param array $args Tool arguments. Expected keys: filename, mime_type,
	 *                    source ({type,data|url}), alt_text?, caption?, title?,
	 *                    description?, attach_to_post?, dry_run?
	 * @param int   $user_id Current user ID for rate-limit accounting.
	 * @return array|WP_Error
	 */
	public static function ingest( array $args, int $user_id ): array|WP_Error {
		$rate_check = self::check_rate_limit( $user_id );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// 1. Filename.
		$filename_raw = isset( $args['filename'] ) ? (string) $args['filename'] : '';
		if ( '' === $filename_raw ) {
			return new WP_Error( 'missing_filename', 'filename is required.' );
		}
		if ( self::filename_looks_dangerous( $filename_raw ) ) {
			return new WP_Error( 'unsafe_filename', 'Filename contains a disallowed extension or sequence.' );
		}
		$filename = sanitize_file_name( $filename_raw );
		if ( '' === $filename ) {
			return new WP_Error( 'invalid_filename', 'Sanitized filename is empty.' );
		}

		// 2. Resolve the bytes.
		$source = $args['source'] ?? null;
		if ( ! is_array( $source ) || empty( $source['type'] ) ) {
			return new WP_Error( 'missing_source', 'source.type is required (base64 or url).' );
		}
		$max_bytes = self::max_upload_bytes();

		$tmp_path = match ( $source['type'] ) {
			'base64' => self::write_base64_to_temp( (string) ( $source['data'] ?? '' ), $filename, $max_bytes ),
			'url'    => self::fetch_url_to_temp( (string) ( $source['url'] ?? '' ), $filename, $max_bytes ),
			default  => new WP_Error( 'invalid_source_type', 'source.type must be base64 or url.' ),
		};
		if ( is_wp_error( $tmp_path ) ) {
			return $tmp_path;
		}

		// 3. MIME + extension verification.
		$mime_check = self::verify_mime_and_extension( $tmp_path, $filename );
		if ( is_wp_error( $mime_check ) ) {
			self::cleanup_temp( $tmp_path );
			return $mime_check;
		}

		// 4. Dimension cap.
		$dim_info = @getimagesize( $tmp_path );
		if ( ! is_array( $dim_info ) || empty( $dim_info[0] ) || empty( $dim_info[1] ) ) {
			self::cleanup_temp( $tmp_path );
			return new WP_Error( 'invalid_image', 'File does not appear to be a readable image.' );
		}
		$max_dim = (int) apply_filters( 'iato_mcp_max_image_dimension', self::DEFAULT_MAX_DIMENSION );
		if ( $dim_info[0] > $max_dim || $dim_info[1] > $max_dim ) {
			self::cleanup_temp( $tmp_path );
			return new WP_Error(
				'image_too_large',
				sprintf( 'Image dimensions %dx%d exceed the %dpx cap.', $dim_info[0], $dim_info[1], $max_dim ),
				[ 'width' => $dim_info[0], 'height' => $dim_info[1] ]
			);
		}

		// 5. Dry-run preview.
		if ( ! empty( $args['dry_run'] ) ) {
			self::cleanup_temp( $tmp_path );
			return [
				'dry_run'   => true,
				'filename'  => $filename,
				'mime_type' => $mime_check,
				'width'     => $dim_info[0],
				'height'    => $dim_info[1],
				'file_size_bytes' => filesize( $tmp_path ) ?: null,
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
			self::cleanup_temp( $tmp_path );
			return new WP_Error( 'sideload_failed', $sideloaded['error'] );
		}

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
			// wp_handle_sideload already moved the file into uploads — try to clean it.
			@unlink( $sideloaded['file'] );
			return $attachment_id;
		}

		// 8. Intermediate sizes.
		$metadata = wp_generate_attachment_metadata( $attachment_id, $sideloaded['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// 9. Alt text (optional).
		if ( isset( $args['alt_text'] ) && '' !== $args['alt_text'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt_text'] ) );
		}

		self::increment_rate_counter( $user_id );

		// 10. Build response.
		$intermediates = [];
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$base_url = trailingslashit( dirname( wp_get_attachment_url( $attachment_id ) ) );
			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				$intermediates[ $size_name ] = [
					'url'    => $base_url . ( $size_info['file'] ?? '' ),
					'width'  => $size_info['width'] ?? null,
					'height' => $size_info['height'] ?? null,
				];
			}
		}

		return [
			'attachment_id'      => $attachment_id,
			'url'                => wp_get_attachment_url( $attachment_id ),
			'filename'           => basename( $sideloaded['file'] ),
			'mime_type'          => $sideloaded['type'],
			'file_size_bytes'    => filesize( $sideloaded['file'] ) ?: null,
			'width'              => $metadata['width']  ?? $dim_info[0],
			'height'             => $metadata['height'] ?? $dim_info[1],
			'alt_text'           => isset( $args['alt_text'] ) ? (string) $args['alt_text'] : '',
			'intermediate_sizes' => $intermediates,
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
	private static function check_host_resolves_publicly( string $host ): true|WP_Error {
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

	private static function check_rate_limit( int $user_id ): true|WP_Error {
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

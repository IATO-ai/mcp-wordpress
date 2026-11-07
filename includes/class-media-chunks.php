<?php
/**
 * Chunked image uploads (1.19.0): media_upload_start, media_upload_chunk and
 * media_upload_finish. The transport cannot carry more than a few KB of base64 in one
 * call reliably, so a client sends the file in pieces and the server checks the whole.
 *
 * Each upload is two files in uploads/iato-mcp-tmp/: <id>.json, written when it starts,
 * and <id>.part, the bytes so far. The ID is 128 random bits and the client's filename
 * is never part of a path. .htaccess denies the folder on Apache and LiteSpeed; nginx
 * ignores it, so what protects these files there is that nobody can guess a name and
 * neither extension runs as code.
 *
 * Every chunk but the last is the size of the first, so what has arrived can be read
 * from the size of the file: nothing grows or is rewritten as an upload goes on.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- These files live in uploads and need locking, which WP_Filesystem cannot do.

final class IATO_MCP_Media_Chunks {

	public const MAX_BYTES       = 15728640; // 15 MB.
	public const MAX_CHUNK_BYTES = 4096;
	public const MAX_OPEN        = 5;
	public const IDLE_SECONDS    = 3600;
	public const HOOK            = 'iato_mcp_media_chunks_cleanup';
	public const FOLDER          = 'iato-mcp-tmp';

	/** Failures a new attempt with these same bytes cannot fix. */
	private const FINAL_CODES = [ 'checksum_mismatch', 'invalid_image', 'mime_mismatch', 'mime_not_allowed', 'svg_not_supported', 'image_too_large', 'file_too_large', 'unsafe_filename', 'invalid_filename' ];

	/** alt_text, title and caption are kept in the session until the upload finishes. */
	private const MAX_FIELD_BYTES = 10000;

	/** @var callable|null Stands in for IATO_MCP_Media_Uploader::ingest() in tests. */
	private static $ingest = null;

	/** @var callable|null Stands in for IATO_MCP_Media_Uploader::describe_attachment() in tests. */
	private static $describe = null;

	public static function set_ingest( ?callable $ingest, ?callable $describe = null ): void {
		self::$ingest   = $ingest;
		self::$describe = $describe;
	}

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'cleanup' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ] );
	}

	public static function ensure_scheduled(): void {
		if ( function_exists( 'wp_next_scheduled' ) && false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::IDLE_SECONDS, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/** The largest file an upload can be: 15 MB, or less if the site says so. */
	public static function limit(): int {
		return min( self::MAX_BYTES, (int) wp_max_upload_size(), IATO_MCP_Media_Uploader::max_upload_bytes() );
	}

	// ── Start ───────────────────────────────────────────────────────────────────

	public static function start( array $args ): array|WP_Error {
		$filename = IATO_MCP_Media_Uploader::check_new_upload( self::text( $args, 'filename' ), self::text( $args, 'mime_type' ) );
		if ( is_wp_error( $filename ) ) {
			return $filename;
		}
		foreach ( [ 'alt_text', 'title', 'caption' ] as $field ) {
			if ( isset( $args[ $field ] ) && ( ! is_string( $args[ $field ] ) || strlen( $args[ $field ] ) > self::MAX_FIELD_BYTES ) ) {
				return new WP_Error(
					'invalid_field',
					sprintf( '%1$s must be text of at most %2$d bytes.', $field, self::MAX_FIELD_BYTES ),
					[ 'field' => $field, 'max_bytes' => self::MAX_FIELD_BYTES ]
				);
			}
		}
		$total = self::whole_number( $args['total_bytes'] ?? null );
		if ( null === $total || $total <= 0 ) {
			return new WP_Error( 'invalid_total_bytes', 'total_bytes must be the size of the file, a whole number above zero.' );
		}
		$limit = self::limit();
		if ( $total > $limit ) {
			return new WP_Error(
				'file_too_large',
				sprintf( 'This file is %1$d bytes, and the most this site takes is %2$d.', $total, $limit ),
				[ 'total_bytes' => $total, 'max_bytes' => $limit ]
			);
		}
		$sha256 = strtolower( trim( self::text( $args, 'sha256' ) ) );
		if ( ! IATO_MCP_Media_Uploader::is_sha256( $sha256 ) ) {
			return new WP_Error( 'invalid_sha256', 'sha256 must be the 64-character hex digest of the file.' );
		}
		$user_id = (int) get_current_user_id();
		$rate    = IATO_MCP_Media_Uploader::check_rate_limit( $user_id );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		self::cleanup();
		$open = self::open_for( $user_id );
		if ( count( $open ) >= self::MAX_OPEN ) {
			return new WP_Error(
				'too_many_uploads',
				sprintf( 'This user has %d unfinished uploads. Finish one, or wait: they are removed after an hour with no activity.', count( $open ) ),
				[ 'open_uploads' => $open ]
			);
		}

		$info = [
			'total_bytes'     => $total,
			'max_chunk_bytes' => self::MAX_CHUNK_BYTES,
			'idle_seconds'    => self::IDLE_SECONDS,
		];
		if ( ! empty( $args['dry_run'] ) ) {
			return [ 'dry_run' => true, 'filename' => $filename, 'max_bytes' => $limit ] + $info;
		}

		$folder = self::folder();
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		$upload_id = bin2hex( random_bytes( 16 ) );
		$session   = [
			'user_id'        => $user_id,
			'filename'       => $filename,
			'mime_type'      => (string) $args['mime_type'],
			'total_bytes'    => $total,
			'sha256'         => $sha256,
			'alt_text'       => isset( $args['alt_text'] ) ? (string) $args['alt_text'] : null,
			'title'          => isset( $args['title'] ) ? (string) $args['title'] : null,
			'caption'        => isset( $args['caption'] ) ? (string) $args['caption'] : null,
			'attach_to_post' => isset( $args['attach_to_post'] ) ? absint( $args['attach_to_post'] ) : 0,
			'chunk_bytes'    => 0,
			'state'          => 'open',
		];
		if ( ! self::save( $folder, $upload_id, $session ) || false === @file_put_contents( self::path( $folder, $upload_id, 'part' ), '' ) ) {
			self::remove( $folder, $upload_id );
			return new WP_Error( 'upload_store_failed', 'Could not store this upload on the server.' );
		}
		return [ 'upload_id' => $upload_id, 'filename' => $filename ] + $info;
	}

	// ── Chunk ───────────────────────────────────────────────────────────────────

	public static function chunk( array $args ): array|WP_Error {
		$found = self::find( $args['upload_id'] ?? null );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		[ $folder, $upload_id, $session ] = $found;
		if ( 'open' !== $session['state'] ) {
			return new WP_Error( 'upload_finished', 'This upload has been finished. Call media_upload_finish again for its result.' );
		}
		$index = self::whole_number( $args['index'] ?? null );
		if ( null === $index || $index < 0 ) {
			return new WP_Error( 'invalid_chunk_index', 'index must be the chunk\'s position, counting from 0.' );
		}
		$raw   = $args['data'] ?? null;
		$bytes = is_string( $raw ) ? base64_decode( $raw, true ) : false;
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'invalid_base64', 'data must be this chunk\'s bytes in base64. Nothing was stored.', [ 'index' => $index ] );
		}
		$length = strlen( $bytes );
		if ( $length > self::MAX_CHUNK_BYTES ) {
			return new WP_Error(
				'chunk_too_large',
				sprintf( 'This chunk is %1$d bytes, and the most is %2$d. Nothing was stored.', $length, self::MAX_CHUNK_BYTES ),
				[ 'index' => $index, 'max_chunk_bytes' => self::MAX_CHUNK_BYTES ]
			);
		}
		if ( isset( $args['chunk_sha256'] ) ) {
			$sent = strtolower( trim( self::text( $args, 'chunk_sha256' ) ) );
			if ( ! IATO_MCP_Media_Uploader::is_sha256( $sent ) ) {
				return new WP_Error( 'invalid_sha256', 'chunk_sha256 must be a 64-character hex digest. Nothing was stored.', [ 'index' => $index ] );
			}
			$actual = hash( 'sha256', $bytes );
			if ( ! hash_equals( $sent, $actual ) ) {
				return new WP_Error(
					'checksum_mismatch',
					sprintf( 'Chunk %d arrived altered. Nothing was stored. Send it again.', $index ),
					[ 'index' => $index, 'expected_sha256' => $sent, 'sha256' => $actual, 'decoded_bytes' => $length ]
				);
			}
		}

		$handle = @fopen( self::path( $folder, $upload_id, 'part' ), 'c+b' );
		if ( false === $handle ) {
			return new WP_Error( 'upload_store_failed', 'Could not read this upload on the server.' );
		}
		flock( $handle, LOCK_EX );
		try {
			// Under the lock, in case another request has just changed it.
			$session = self::read( $folder, $upload_id );
			if ( null === $session ) {
				return self::not_found();
			}
			if ( 'open' !== $session['state'] ) {
				return new WP_Error( 'upload_finished', 'This upload has been finished. Call media_upload_finish again for its result.' );
			}
			return self::place_chunk( $handle, $folder, $upload_id, $session, $index, $bytes, ! empty( $args['dry_run'] ) );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	private static function place_chunk( $handle, string $folder, string $upload_id, array $session, int $index, string $bytes, bool $dry_run ): array|WP_Error {
		$length = strlen( $bytes );
		$total  = (int) $session['total_bytes'];
		$size   = (int) fstat( $handle )['size'];
		$every  = (int) $session['chunk_bytes'];
		$next   = $every > 0 ? (int) ceil( $size / $every ) : 0;
		$report = static fn( int $received, int $following ): array => [
			'upload_id'      => $upload_id,
			'index'          => $index,
			'bytes_received' => $received,
			'total_bytes'    => $total,
			'next_index'     => $following,
			'complete'       => $received === $total,
		];

		// A retry of a chunk that arrived.
		if ( $index < $next ) {
			fseek( $handle, $index * $every );
			if ( (string) fread( $handle, $every ) !== $bytes ) {
				return new WP_Error(
					'chunk_conflict',
					sprintf( 'Chunk %d has already arrived with different contents. Nothing was stored.', $index ),
					[ 'index' => $index, 'next_index' => $next, 'bytes_received' => $size ]
				);
			}
			return $report( $size, $next ) + [ 'already_received' => true ];
		}
		if ( $index > $next ) {
			return new WP_Error(
				'chunk_out_of_order',
				sprintf( 'Chunk %1$d is out of order: the next one is %2$d. Nothing was stored.', $index, $next ),
				[ 'index' => $index, 'next_index' => $next, 'bytes_received' => $size ]
			);
		}
		if ( $size + $length > $total ) {
			return new WP_Error(
				'upload_too_long',
				sprintf( 'This chunk would take the upload past its %d bytes. Nothing was stored.', $total ),
				[ 'index' => $index, 'bytes_received' => $size, 'total_bytes' => $total ]
			);
		}
		if ( $every > 0 && $length !== $every && $size + $length !== $total ) {
			return new WP_Error(
				'chunk_size_mismatch',
				sprintf( 'Every chunk but the last must be %1$d bytes, the size of the first. This one is %2$d. Nothing was stored.', $every, $length ),
				[ 'index' => $index, 'chunk_bytes' => $every ]
			);
		}

		if ( $dry_run ) {
			return [ 'dry_run' => true ] + $report( $size, $next ) + [ 'would_receive' => $size + $length ];
		}

		// The size first: if the request ends between the two, a retry of this chunk fits.
		if ( 0 === $every ) {
			$session['chunk_bytes'] = $length;
			if ( ! self::save( $folder, $upload_id, $session ) ) {
				return new WP_Error( 'upload_store_failed', sprintf( 'Could not store chunk %d on the server. Send it again.', $index ), [ 'index' => $index ] );
			}
		}
		fseek( $handle, $size );
		$written = fwrite( $handle, $bytes );
		fflush( $handle );
		if ( $length !== $written ) {
			ftruncate( $handle, $size );
			return new WP_Error( 'upload_store_failed', sprintf( 'Could not store chunk %d on the server. Send it again.', $index ), [ 'index' => $index ] );
		}
		return $report( $size + $length, $index + 1 );
	}

	// ── Finish ──────────────────────────────────────────────────────────────────

	/**
	 * @param array    $args   The tool's arguments.
	 * @param callable $record Turns what the uploader returned into the tool's response.
	 */
	public static function finish( array $args, callable $record ): array|WP_Error {
		$found = self::find( $args['upload_id'] ?? null );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		[ $folder, $upload_id, $session ] = $found;
		$dry_run = ! empty( $args['dry_run'] );
		if ( 'done' === $session['state'] ) {
			return (array) $session['result'] + [ 'already_finished' => true ];
		}

		$part   = self::path( $folder, $upload_id, 'part' );
		$handle = @fopen( $part, 'c+b' );
		if ( false === $handle ) {
			return new WP_Error( 'upload_store_failed', 'Could not read this upload on the server.' );
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return new WP_Error( 'upload_in_progress', 'This upload is being finished by another request. Try again shortly for the result.' );
		}
		$remove = false;
		try {
			$session = self::read( $folder, $upload_id );
			if ( null === $session ) {
				return self::not_found();
			}
			if ( 'done' === $session['state'] ) {
				return (array) $session['result'] + [ 'already_finished' => true ];
			}
			// An earlier request made the attachment, then stopped, most likely while making
			// its image sizes. Doing it all again would make another each time.
			$made = (int) ( $session['attachment_id'] ?? 0 );
			if ( $made > 0 && get_post( $made ) ) {
				if ( $dry_run ) {
					return [ 'dry_run' => true, 'attachment_id' => $made, 'already_created' => true ];
				}
				$result           = null !== self::$describe ? ( self::$describe )( $made, $session ) : IATO_MCP_Media_Uploader::describe_attachment( $made, $session );
				$response         = $record( $result ) + [ 'upload_id' => $upload_id ];
				$session['state'] = 'done';
				$session['result'] = $response;
				self::save( $folder, $upload_id, $session );
				@ftruncate( $handle, 0 );
				return $response;
			}

			$attach = (int) $session['attach_to_post'];
			if ( $attach > 0 ) {
				if ( ! get_post( $attach ) ) {
					return new WP_Error( 'not_found', 'attach_to_post references a post that does not exist.' );
				}
				$edit_check = IATO_MCP_Auth::require_cap( 'edit_post', $attach );
				if ( is_wp_error( $edit_check ) ) {
					return $edit_check;
				}
			}

			$size  = (int) fstat( $handle )['size'];
			$total = (int) $session['total_bytes'];
			if ( $size !== $total ) {
				$every = (int) $session['chunk_bytes'];
				return new WP_Error(
					'upload_incomplete',
					sprintf( 'Only %1$d of %2$d bytes have arrived. Send the rest, then finish.', $size, $total ),
					[ 'bytes_received' => $size, 'total_bytes' => $total, 'next_index' => $every > 0 ? (int) ceil( $size / $every ) : 0 ]
				);
			}

			// The uploader consumes what it is given. The chunks stay until an attachment
			// exists, so a request that dies part way can be sent again.
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php'; // Not loaded on REST requests.
			}
			$copy = wp_tempnam( (string) $session['filename'] );
			// Through the handle that holds the lock: Windows refuses anything else.
			$into   = $copy ? @fopen( $copy, 'wb' ) : false;
			$copied = false;
			if ( false !== $into ) {
				fseek( $handle, 0 );
				$copied = stream_copy_to_stream( $handle, $into );
				fclose( $into );
			}
			if ( $size !== $copied ) {
				if ( $copy ) {
					@unlink( $copy );
				}
				return new WP_Error( 'upload_store_failed', 'Could not read this upload on the server.' );
			}

			$ingest_args = array_filter(
				[
					'filename'       => $session['filename'],
					'mime_type'      => $session['mime_type'],
					'sha256'         => $session['sha256'],
					'alt_text'       => $session['alt_text'],
					'title'          => $session['title'],
					'caption'        => $session['caption'],
					'attach_to_post' => $attach ?: null,
					'dry_run'        => $dry_run ?: null,
					'defer_subsizes' => ! empty( $args['defer_subsizes'] ) ?: null,
				],
				static fn( mixed $value ): bool => null !== $value
			);
			$user_id = (int) get_current_user_id();
			$created = static function ( int $attachment_id ) use ( $folder, $upload_id, &$session ): void {
				$session['attachment_id'] = $attachment_id;
				self::save( $folder, $upload_id, $session );
			};
			$result  = null !== self::$ingest
				? ( self::$ingest )( $ingest_args, $user_id, $copy, $created )
				: IATO_MCP_Media_Uploader::ingest( $ingest_args, $user_id, $copy, $created );
			if ( file_exists( $copy ) ) {
				@unlink( $copy );
			}

			if ( is_wp_error( $result ) ) {
				if ( ! $dry_run && in_array( $result->get_error_code(), self::FINAL_CODES, true ) ) {
					$remove = true;
					$data   = $result->get_error_data();
					return new WP_Error( $result->get_error_code(), $result->get_error_message(), ( is_array( $data ) ? $data : [] ) + [ 'upload_removed' => true ] );
				}
				return $result;
			}
			if ( $dry_run ) {
				return $result;
			}

			$response = $record( $result ) + [ 'upload_id' => $upload_id ];
			// Kept until the cleanup, so a client that timed out gets this and does not upload twice.
			$session['state']  = 'done';
			$session['result'] = $response;
			self::save( $folder, $upload_id, $session );
			@ftruncate( $handle, 0 );
			return $response;
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			if ( $remove ) {
				self::remove( $folder, $upload_id );
			}
		}
	}

	// ── Cleanup ─────────────────────────────────────────────────────────────────

	/**
	 * Remove uploads with no activity for an hour. Idle time, not age: 15 MB at 3 KB a
	 * chunk is 5,000 calls, which could take longer than an hour.
	 *
	 * @return int How many were removed.
	 */
	public static function cleanup( ?int $now = null ): int {
		$folder = self::existing_folder();
		if ( null === $folder ) {
			return 0;
		}
		$now     = $now ?? time();
		$removed = 0;
		foreach ( (array) scandir( $folder ) as $file ) {
			$upload_id = strtok( (string) $file, '.' ); // <id>.json, <id>.part, <id>.json.tmp
			if ( ! self::is_upload_id( $upload_id ) ) {
				continue;
			}
			clearstatcache();
			$times = array_filter( array_map( static fn( string $ext ): int => (int) @filemtime( self::path( $folder, $upload_id, $ext ) ), [ 'json', 'part', 'json.tmp' ] ) );
			if ( [] === $times || $now - max( $times ) < self::IDLE_SECONDS ) {
				continue;
			}
			self::remove( $folder, $upload_id );
			++$removed;
		}
		return $removed;
	}

	/** @return string[] The unfinished uploads a user has. */
	private static function open_for( int $user_id ): array {
		$folder = self::existing_folder();
		$open   = [];
		foreach ( null === $folder ? [] : (array) scandir( $folder ) as $file ) {
			$upload_id = substr( (string) $file, -5 ) === '.json' ? substr( (string) $file, 0, -5 ) : '';
			$session   = self::is_upload_id( $upload_id ) ? self::read( $folder, $upload_id ) : null;
			if ( null !== $session && 'open' === $session['state'] && (int) $session['user_id'] === $user_id ) {
				$open[] = $upload_id;
			}
		}
		return $open;
	}

	// ── Storage ─────────────────────────────────────────────────────────────────

	private static function is_upload_id( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $value );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'upload_not_found', 'There is no such upload for this user. They are removed after an hour with no activity. Start again with media_upload_start.', [ 'status' => 404 ] );
	}

	/**
	 * An upload belongs to the user who started it. Anyone else gets the answer a
	 * made-up ID gets.
	 *
	 * @return array{0:string,1:string,2:array}|WP_Error
	 */
	private static function find( mixed $upload_id ): array|WP_Error {
		$folder  = self::existing_folder();
		$session = null !== $folder && self::is_upload_id( $upload_id ) ? self::read( $folder, $upload_id ) : null;
		if ( null === $session || (int) $session['user_id'] !== (int) get_current_user_id() ) {
			return self::not_found();
		}
		return [ $folder, $upload_id, $session ];
	}

	private static function read( string $folder, string $upload_id ): ?array {
		$raw     = @file_get_contents( self::path( $folder, $upload_id, 'json' ) );
		$session = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $session ) ? $session : null;
	}

	private static function save( string $folder, string $upload_id, array $session ): bool {
		$file = self::path( $folder, $upload_id, 'json' );
		$temp = $file . '.tmp';
		if ( false === @file_put_contents( $temp, (string) wp_json_encode( $session ) ) ) {
			return false;
		}
		return @rename( $temp, $file );
	}

	private static function remove( string $folder, string $upload_id ): void {
		foreach ( [ 'part', 'json', 'json.tmp' ] as $ext ) {
			@unlink( self::path( $folder, $upload_id, $ext ) );
		}
	}

	private static function path( string $folder, string $upload_id, string $ext ): string {
		return $folder . '/' . $upload_id . '.' . $ext;
	}

	private static function existing_folder(): ?string {
		$uploads = wp_upload_dir( null, false );
		$folder  = (string) ( $uploads['basedir'] ?? '' ) . '/' . self::FOLDER;
		return is_dir( $folder ) ? $folder : null;
	}

	/** The folder, made and locked down the first time. */
	private static function folder(): string|WP_Error {
		$uploads = wp_upload_dir( null, false );
		$folder  = (string) ( $uploads['basedir'] ?? '' ) . '/' . self::FOLDER;
		if ( ! wp_mkdir_p( $folder ) ) {
			return new WP_Error( 'upload_store_failed', 'Could not create the folder for uploads in progress.' );
		}
		$guards = [
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
		];
		foreach ( $guards as $name => $contents ) {
			if ( ! file_exists( $folder . '/' . $name ) && false === @file_put_contents( $folder . '/' . $name, $contents ) ) {
				return new WP_Error( 'upload_store_failed', 'Could not protect the folder for uploads in progress, so nothing was stored there.' );
			}
		}
		return $folder;
	}

	/** An argument that should be text. Anything else is treated as empty, and refused as that. */
	private static function text( array $args, string $key ): string {
		return is_string( $args[ $key ] ?? null ) ? $args[ $key ] : '';
	}

	/** A whole number as JSON sends it, or null. */
	private static function whole_number( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		return is_string( $value ) && 1 === preg_match( '/^-?\d+$/D', $value ) ? (int) $value : null;
	}
}

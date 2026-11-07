<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-media-chunks.php';
require_once IATO_MCP_DIR . 'includes/class-media-uploader.php';
require_once IATO_MCP_DIR . 'includes/class-media-chunks.php';

/**
 * Chunked uploads (1.19.0): sessions on a real file system, with the uploader replaced.
 * tests/wp-env/media-chunks-live.py covers the whole path on WordPress.
 */
final class MediaChunksTest extends TestCase {

	private const USER = 7, OTHER_USER = 8, CHUNK = 3000;

	private string $file;
	private array $ingested = [];
	private array $caps     = [];

	protected function setUp(): void {
		IATO_Test_Chunks::$base = sys_get_temp_dir() . '/iato-chunks-' . bin2hex( random_bytes( 6 ) );
		mkdir( IATO_Test_Chunks::$base );
		IATO_Test_Chunks::$max_upload = 100000000;
		$this->file     = random_bytes( 7500 ); // Two chunks of 3,000 and one of 1,500.
		$this->ingested = [];
		IATO_MCP_Media_Chunks::set_ingest(
			function ( array $args, int $user, string $path ): array|WP_Error {
				$this->ingested[] = [ 'args' => $args, 'bytes' => (string) file_get_contents( $path ) ];
				@unlink( $path );
				return [ 'attachment_id' => 42, 'url' => 'https://x.test/a.png', 'filename' => 'a.png' ];
			}
		);
		$this->as_user( self::USER );
	}

	protected function tearDown(): void {
		IATO_MCP_Media_Chunks::set_ingest( null );
		$this->as_user( 0 );
		$this->remove_tree( IATO_Test_Chunks::$base );
	}

	private function remove_tree( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			is_dir( "$dir/$name" ) ? $this->remove_tree( "$dir/$name" ) : unlink( "$dir/$name" );
		}
		rmdir( $dir );
	}

	private function as_user( int $id ): void {
		wp_set_current_user( $id );
	}

	private function folder(): string {
		return IATO_Test_Chunks::$base . '/' . IATO_MCP_Media_Chunks::FOLDER;
	}

	private function start( array $overrides = [] ): array|WP_Error {
		return IATO_MCP_Media_Chunks::start(
			$overrides + [
				'filename'    => 'photo.png',
				'mime_type'   => 'image/png',
				'total_bytes' => strlen( $this->file ),
				'sha256'      => hash( 'sha256', $this->file ),
			]
		);
	}

	private function piece( int $index ): string {
		return substr( $this->file, $index * self::CHUNK, self::CHUNK );
	}

	private function send( string $id, int $index, ?string $bytes = null, array $extra = [] ): array|WP_Error {
		return IATO_MCP_Media_Chunks::chunk( $extra + [ 'upload_id' => $id, 'index' => $index, 'data' => base64_encode( $bytes ?? $this->piece( $index ) ) ] );
	}

	private function send_all( string $id ): void {
		foreach ( [ 0, 1, 2 ] as $index ) {
			$this->assertIsArray( $this->send( $id, $index ) );
		}
	}

	private function finish( string $id, array $extra = [] ): array|WP_Error {
		return IATO_MCP_Media_Chunks::finish( $extra + [ 'upload_id' => $id ], static fn( array $result ): array => $result + [ 'change_receipt' => [ 'change_id' => 'wr_1' ] ] );
	}

	private function code( mixed $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result->get_error_code();
	}

	// ── Start ───────────────────────────────────────────────────────────────────

	public function test_start_gives_an_id_no_one_can_guess_and_a_locked_folder(): void {
		$r = $this->start();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $r['upload_id'] );
		$this->assertFileExists( $this->folder() . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $this->folder() . '/.htaccess' ) );
		$this->assertFileExists( $this->folder() . '/' . $r['upload_id'] . '.part' );
	}

	public function test_the_clients_filename_never_reaches_a_path(): void {
		$r     = $this->start( [ 'filename' => '../../evil.png' ] );
		$names = array_values( array_diff( (array) scandir( $this->folder() ), [ '.', '..', 'index.php', '.htaccess' ] ) );
		sort( $names );
		$this->assertSame( [ $r['upload_id'] . '.json', $r['upload_id'] . '.part' ], $names );
		$this->assertFileDoesNotExist( IATO_Test_Chunks::$base . '/evil.png' );
	}

	public function test_a_dry_run_start_stores_nothing(): void {
		$r = $this->start( [ 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertArrayNotHasKey( 'upload_id', $r );
		$this->assertDirectoryDoesNotExist( $this->folder() );
	}

	/** @return array<string,array{0:array,1:string}> */
	public static function bad_starts(): array {
		return [
			'no filename'     => [ [ 'filename' => '' ], 'missing_filename' ],
			'a script'        => [ [ 'filename' => 'x.php.png' ], 'unsafe_filename' ],
			'an svg'          => [ [ 'filename' => 'x.svg', 'mime_type' => 'image/svg+xml' ], 'svg_not_supported' ],
			'not an image'    => [ [ 'filename' => 'x.pdf', 'mime_type' => 'application/pdf' ], 'mime_not_allowed' ],
			'wrong extension' => [ [ 'filename' => 'x.jpg', 'mime_type' => 'image/png' ], 'mime_mismatch' ],
			'no size'         => [ [ 'total_bytes' => 0 ], 'invalid_total_bytes' ],
			'negative size'   => [ [ 'total_bytes' => -1 ], 'invalid_total_bytes' ],
			'text size'       => [ [ 'total_bytes' => 'big' ], 'invalid_total_bytes' ],
			'over 15 MB'      => [ [ 'total_bytes' => 15728641 ], 'file_too_large' ],
			'a bad digest'    => [ [ 'sha256' => 'abc' ], 'invalid_sha256' ],
			'a list filename' => [ [ 'filename' => [ 'x.png' ] ], 'missing_filename' ],
			'a list digest'   => [ [ 'sha256' => [ 'abc' ] ], 'invalid_sha256' ],
			'a long filename' => [ [ 'filename' => str_repeat( 'x', 201 ) . '.png' ], 'invalid_filename' ],
			'a long title'    => [ [ 'title' => str_repeat( 'x', 10001 ) ], 'invalid_field' ],
			'a long alt text' => [ [ 'alt_text' => str_repeat( 'x', 10001 ) ], 'invalid_field' ],
			'a list caption'  => [ [ 'caption' => [ 'x' ] ], 'invalid_field' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_starts' )]
	public function test_a_bad_start_is_refused( array $overrides, string $code ): void {
		$this->assertSame( $code, $this->code( $this->start( $overrides ) ) );
	}

	public function test_the_sites_lower_limit_wins(): void {
		IATO_Test_Chunks::$max_upload = 5000;
		$r = $this->start();
		$this->assertSame( 'file_too_large', $this->code( $r ) );
		$this->assertSame( 5000, $r->get_error_data()['max_bytes'] );
	}

	public function test_a_user_can_have_only_so_many_open(): void {
		for ( $i = 0; $i < IATO_MCP_Media_Chunks::MAX_OPEN; $i++ ) {
			$this->assertIsArray( $this->start() );
		}
		$r = $this->start();
		$this->assertSame( 'too_many_uploads', $this->code( $r ) );
		$this->assertCount( IATO_MCP_Media_Chunks::MAX_OPEN, $r->get_error_data()['open_uploads'] );
		$this->as_user( self::OTHER_USER );
		$this->assertIsArray( $this->start(), 'someone else is not affected' );
	}

	// ── Chunks ──────────────────────────────────────────────────────────────────

	public function test_chunks_arrive_in_order_and_say_how_far_it_has_got(): void {
		$id = $this->start()['upload_id'];
		$r  = $this->send( $id, 0 );
		$this->assertSame( [ 3000, 1, false ], [ $r['bytes_received'], $r['next_index'], $r['complete'] ] );
		$this->send( $id, 1 );
		$r = $this->send( $id, 2 );
		$this->assertSame( [ 7500, 3, true ], [ $r['bytes_received'], $r['next_index'], $r['complete'] ] );
		$this->assertSame( $this->file, file_get_contents( $this->folder() . "/$id.part" ) );
	}

	public function test_a_chunk_out_of_order_is_refused_and_says_which_is_next(): void {
		$id = $this->start()['upload_id'];
		$r  = $this->send( $id, 1 );
		$this->assertSame( 'chunk_out_of_order', $this->code( $r ) );
		$this->assertSame( 0, $r->get_error_data()['next_index'] );
		$this->send( $id, 0 );
		$r = $this->send( $id, 2 );
		$this->assertSame( 1, $r->get_error_data()['next_index'] );
	}

	public function test_a_chunk_sent_again_with_the_same_contents_is_fine(): void {
		$id = $this->start()['upload_id'];
		$this->send( $id, 0 );
		$this->send( $id, 1 );
		$r = $this->send( $id, 0 );
		$this->assertTrue( $r['already_received'] );
		$this->assertSame( 6000, $r['bytes_received'] );
		$this->assertSame( 6000, filesize( $this->folder() . "/$id.part" ), 'nothing added' );
	}

	public function test_the_last_chunk_sent_again_is_fine_too(): void {
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		$r = $this->send( $id, 2 );
		$this->assertTrue( $r['already_received'] );
		$this->assertTrue( $r['complete'] );
	}

	public function test_a_chunk_sent_again_with_different_contents_is_refused(): void {
		$id = $this->start()['upload_id'];
		$this->send( $id, 0 );
		$this->assertSame( 'chunk_conflict', $this->code( $this->send( $id, 0, random_bytes( self::CHUNK ) ) ) );
		$this->assertSame( $this->piece( 0 ), file_get_contents( $this->folder() . "/$id.part" ) );
	}

	public function test_chunks_must_be_the_same_size_except_the_last(): void {
		$id = $this->start()['upload_id'];
		$this->send( $id, 0 );
		$r = $this->send( $id, 1, random_bytes( 2000 ) );
		$this->assertSame( 'chunk_size_mismatch', $this->code( $r ) );
		$this->assertSame( self::CHUNK, $r->get_error_data()['chunk_bytes'] );
	}

	public function test_an_upload_cannot_go_past_its_size(): void {
		$id = $this->start( [ 'total_bytes' => 4000, 'sha256' => hash( 'sha256', 'x' ) ] )['upload_id'];
		$this->send( $id, 0 );
		$this->assertSame( 'upload_too_long', $this->code( $this->send( $id, 1 ) ) );
	}

	public function test_a_chunk_over_the_limit_is_refused(): void {
		$id = $this->start()['upload_id'];
		$this->assertSame( 'chunk_too_large', $this->code( $this->send( $id, 0, random_bytes( IATO_MCP_Media_Chunks::MAX_CHUNK_BYTES + 1 ) ) ) );
	}

	public function test_base64_that_is_not_is_refused(): void {
		$id = $this->start()['upload_id'];
		foreach ( [ '***', '', 123, [ 'x' ], null ] as $data ) {
			$this->assertSame( 'invalid_base64', $this->code( IATO_MCP_Media_Chunks::chunk( [ 'upload_id' => $id, 'index' => 0, 'data' => $data ] ) ) );
		}
	}

	public function test_a_bad_index_is_refused(): void {
		$id = $this->start()['upload_id'];
		foreach ( [ -1, 'one', 1.5, null, [ 0 ] ] as $index ) {
			$this->assertSame( 'invalid_chunk_index', $this->code( IATO_MCP_Media_Chunks::chunk( [ 'upload_id' => $id, 'index' => $index, 'data' => base64_encode( 'x' ) ] ) ) );
		}
	}

	public function test_a_chunk_digest_is_checked_when_given_and_nothing_is_stored_on_a_miss(): void {
		$id = $this->start()['upload_id'];
		$r  = $this->send( $id, 0, null, [ 'chunk_sha256' => hash( 'sha256', 'something else' ) ] );
		$this->assertSame( 'checksum_mismatch', $this->code( $r ) );
		$this->assertSame( 0, $r->get_error_data()['index'] );
		$this->assertSame( 0, filesize( $this->folder() . "/$id.part" ) );
		$this->assertSame( 'invalid_sha256', $this->code( $this->send( $id, 0, null, [ 'chunk_sha256' => 'abc' ] ) ) );
		$this->assertIsArray( $this->send( $id, 0, null, [ 'chunk_sha256' => strtoupper( hash( 'sha256', $this->piece( 0 ) ) ) ] ) );
	}

	public function test_a_dry_run_chunk_stores_nothing(): void {
		$id = $this->start()['upload_id'];
		$r  = $this->send( $id, 0, null, [ 'dry_run' => true ] );
		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( 3000, $r['would_receive'] );
		$this->assertSame( 0, filesize( $this->folder() . "/$id.part" ) );
		$this->assertIsArray( $this->send( $id, 0, random_bytes( 2000 ) ), 'nor does it set the chunk size' );
	}

	// ── Whose it is ─────────────────────────────────────────────────────────────

	public function test_someone_elses_upload_looks_like_no_upload(): void {
		$id = $this->start()['upload_id'];
		$this->send( $id, 0 );
		$this->as_user( self::OTHER_USER );
		$this->assertSame( 'upload_not_found', $this->code( $this->send( $id, 1 ) ) );
		$this->assertSame( 'upload_not_found', $this->code( $this->finish( $id ) ) );
		$this->assertSame( 3000, filesize( $this->folder() . "/$id.part" ) );
	}

	public function test_an_id_that_is_a_path_gets_nowhere(): void {
		$this->start();
		foreach ( [ '../../../etc/passwd', 'x', str_repeat( 'g', 32 ), [ 'a' ], null, 123 ] as $id ) {
			$this->assertSame( 'upload_not_found', $this->code( IATO_MCP_Media_Chunks::chunk( [ 'upload_id' => $id, 'index' => 0, 'data' => 'eA==' ] ) ) );
			$this->assertSame( 'upload_not_found', $this->code( IATO_MCP_Media_Chunks::finish( [ 'upload_id' => $id ], static fn( array $r ): array => $r ) ) );
		}
	}

	// ── Finish ──────────────────────────────────────────────────────────────────

	public function test_finish_hands_the_whole_file_to_the_uploader(): void {
		$id = $this->start( [ 'alt_text' => 'Alt', 'title' => 'Title', 'caption' => 'Caption' ] )['upload_id'];
		$this->send_all( $id );
		$r = $this->finish( $id );
		$this->assertSame( 42, $r['attachment_id'] );
		$this->assertSame( 'wr_1', $r['change_receipt']['change_id'] );
		$this->assertSame( $id, $r['upload_id'] );
		$this->assertSame( $this->file, $this->ingested[0]['bytes'] );
		$this->assertSame( 'Alt', $this->ingested[0]['args']['alt_text'] );
		$this->assertSame( hash( 'sha256', $this->file ), $this->ingested[0]['args']['sha256'] );
		$this->assertSame( 0, filesize( $this->folder() . "/$id.part" ), 'the bytes are gone' );
	}

	public function test_finish_again_gives_the_same_answer_and_makes_nothing_more(): void {
		$id    = $this->start()['upload_id'];
		$this->send_all( $id );
		$first = $this->finish( $id );
		$again = $this->finish( $id );
		$this->assertTrue( $again['already_finished'] );
		$this->assertSame( $first['attachment_id'], $again['attachment_id'] );
		$this->assertSame( 'wr_1', $again['change_receipt']['change_id'] );
		$this->assertCount( 1, $this->ingested );
		$this->assertSame( 'upload_finished', $this->code( $this->send( $id, 0 ) ) );
	}

	public function test_finish_before_it_has_all_arrived_says_how_far_it_got(): void {
		$id = $this->start()['upload_id'];
		$this->send( $id, 0 );
		$r = $this->finish( $id );
		$this->assertSame( 'upload_incomplete', $this->code( $r ) );
		$this->assertSame( [ 3000, 7500, 1 ], [ $r->get_error_data()['bytes_received'], $r->get_error_data()['total_bytes'], $r->get_error_data()['next_index'] ] );
		$this->assertSame( [], $this->ingested );
		$this->assertIsArray( $this->send( $id, 1 ), 'the upload carries on' );
	}

	public function test_a_refusal_that_cannot_be_fixed_removes_the_upload(): void {
		IATO_MCP_Media_Chunks::set_ingest( static fn( array $a, int $u, string $p ): WP_Error => new WP_Error( 'checksum_mismatch', 'altered', [ 'sha256' => 'x' ] ) );
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		$r = $this->finish( $id );
		$this->assertSame( 'checksum_mismatch', $this->code( $r ) );
		$this->assertTrue( $r->get_error_data()['upload_removed'] );
		$this->assertSame( 'x', $r->get_error_data()['sha256'] );
		$this->assertFileDoesNotExist( $this->folder() . "/$id.part" );
		$this->assertFileDoesNotExist( $this->folder() . "/$id.json" );
	}

	public function test_a_failure_that_can_be_retried_keeps_the_upload(): void {
		IATO_MCP_Media_Chunks::set_ingest( static fn( array $a, int $u, string $p ): WP_Error => new WP_Error( 'sideload_failed', 'disk full' ) );
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		$this->assertSame( 'sideload_failed', $this->code( $this->finish( $id ) ) );
		$this->assertSame( strlen( $this->file ), filesize( $this->folder() . "/$id.part" ) );
		IATO_MCP_Media_Chunks::set_ingest( static fn( array $a, int $u, string $p ): array => [ 'attachment_id' => 43 ] );
		$this->assertSame( 43, $this->finish( $id )['attachment_id'] );
	}

	public function test_a_dry_run_finish_keeps_the_upload_whatever_it_finds(): void {
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		IATO_MCP_Media_Chunks::set_ingest( static fn( array $a, int $u, string $p ): WP_Error => new WP_Error( 'invalid_image', 'damaged' ) );
		$this->assertSame( 'invalid_image', $this->code( $this->finish( $id, [ 'dry_run' => true ] ) ) );
		$this->assertSame( strlen( $this->file ), filesize( $this->folder() . "/$id.part" ) );
		IATO_MCP_Media_Chunks::set_ingest( static fn( array $a, int $u, string $p ): array => [ 'dry_run' => true ] );
		$this->assertTrue( $this->finish( $id, [ 'dry_run' => true ] )['dry_run'] );
		$this->assertSame( strlen( $this->file ), filesize( $this->folder() . "/$id.part" ) );
	}

	public function test_a_finish_that_is_running_is_not_run_twice(): void {
		$id   = $this->start()['upload_id'];
		$this->send_all( $id );
		$lock = fopen( $this->folder() . "/$id.part", 'c+b' );
		flock( $lock, LOCK_EX );
		$this->assertSame( 'upload_in_progress', $this->code( $this->finish( $id ) ) );
		flock( $lock, LOCK_UN );
		fclose( $lock );
		$this->assertSame( [], $this->ingested );
	}

	public function test_a_request_that_stopped_after_making_the_attachment_does_not_make_another(): void {
		IATO_Test_WP::$posts[ 42 ] = [ 'post_type' => 'attachment', 'post_status' => 'inherit' ];
		$calls                     = 0;
		IATO_MCP_Media_Chunks::set_ingest(
			static function ( array $a, int $u, string $path, callable $created ) use ( &$calls ): array {
				++$calls;
				$created( 42 );
				@unlink( $path );
				throw new \RuntimeException( 'the time limit, while making image sizes' );
			},
			static fn( int $id, array $session ): array => [ 'attachment_id' => $id, 'recovered' => true ]
		);
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		try {
			$this->finish( $id );
			$this->fail( 'it stopped' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'time limit', $e->getMessage() );
		}
		$dry = $this->finish( $id, [ 'dry_run' => true ] );
		$this->assertTrue( $dry['already_created'] );
		$r = $this->finish( $id );
		$this->assertSame( 42, $r['attachment_id'] );
		$this->assertTrue( $r['recovered'] );
		$this->assertSame( 'wr_1', $r['change_receipt']['change_id'], 'it gets the receipt it never had' );
		$this->assertSame( 1, $calls );
		$again = $this->finish( $id );
		$this->assertTrue( $again['already_finished'] );
		unset( IATO_Test_WP::$posts[42] );
	}

	public function test_an_attachment_deleted_since_is_made_again(): void {
		unset( IATO_Test_WP::$posts[ 42 ] );
		$calls = 0;
		IATO_MCP_Media_Chunks::set_ingest(
			function ( array $a, int $u, string $path, callable $created ) use ( &$calls ): array {
				if ( 1 === ++$calls ) {
					$created( 42 );
					@unlink( $path );
					throw new \RuntimeException( 'stopped' );
				}
				@unlink( $path );
				return [ 'attachment_id' => 43 ];
			}
		);
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		try {
			$this->finish( $id );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$this->assertSame( 43, $this->finish( $id )['attachment_id'] );
	}

	public function test_the_chunk_size_is_kept_before_the_chunk_so_a_retry_fits(): void {
		$id = $this->start()['upload_id'];
		$this->send( $id, 0 );
		// As if the request ended between saving the size and writing the bytes.
		file_put_contents( $this->folder() . "/$id.part", '' );
		$r = $this->send( $id, 0 );
		$this->assertSame( 3000, $r['bytes_received'] );
		$this->assertSame( $this->piece( 0 ), file_get_contents( $this->folder() . "/$id.part" ) );
	}

	public function test_the_uploaders_copy_is_gone_whatever_happens(): void {
		$copy = null;
		IATO_MCP_Media_Chunks::set_ingest(
			static function ( array $a, int $u, string $p ) use ( &$copy ): WP_Error {
				$copy = $p;
				return new WP_Error( 'sideload_failed', 'left the file behind' );
			}
		);
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		$this->finish( $id );
		$this->assertFileDoesNotExist( (string) $copy );
	}

	// ── Cleanup ─────────────────────────────────────────────────────────────────

	public function test_cleanup_goes_by_idle_time(): void {
		$old   = $this->start()['upload_id'];
		$fresh = $this->start()['upload_id'];
		$this->send( $old, 0 );
		$hour_ago = time() - IATO_MCP_Media_Chunks::IDLE_SECONDS - 5;
		touch( $this->folder() . "/$old.part", $hour_ago );
		touch( $this->folder() . "/$old.json", $hour_ago );
		// Started long ago, but a chunk arrived just now: still in use.
		touch( $this->folder() . "/$fresh.json", $hour_ago );

		$this->assertSame( 1, IATO_MCP_Media_Chunks::cleanup() );

		$this->assertFileDoesNotExist( $this->folder() . "/$old.part" );
		$this->assertFileDoesNotExist( $this->folder() . "/$old.json" );
		$this->assertFileExists( $this->folder() . "/$fresh.part" );
		$this->assertFileExists( $this->folder() . '/index.php', 'only uploads go' );
		$this->assertFileExists( $this->folder() . '/.htaccess' );
	}

	public function test_a_part_with_no_session_goes_too(): void {
		$id = $this->start()['upload_id'];
		unlink( $this->folder() . "/$id.json" );
		touch( $this->folder() . "/$id.part", time() - IATO_MCP_Media_Chunks::IDLE_SECONDS - 5 );
		$this->assertSame( 1, IATO_MCP_Media_Chunks::cleanup() );
		$this->assertFileDoesNotExist( $this->folder() . "/$id.part" );
	}

	public function test_cleanup_with_no_folder_does_nothing(): void {
		$this->assertSame( 0, IATO_MCP_Media_Chunks::cleanup() );
	}

	public function test_a_finished_upload_is_cleaned_up_as_well(): void {
		$id = $this->start()['upload_id'];
		$this->send_all( $id );
		$this->finish( $id );
		$hour_ago = time() - IATO_MCP_Media_Chunks::IDLE_SECONDS - 5;
		touch( $this->folder() . "/$id.part", $hour_ago );
		touch( $this->folder() . "/$id.json", $hour_ago );
		$this->assertSame( 1, IATO_MCP_Media_Chunks::cleanup() );
		$this->assertSame( 'upload_not_found', $this->code( $this->finish( $id ) ) );
	}
}

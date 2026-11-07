<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-media-chunks.php';
require_once IATO_MCP_DIR . 'includes/class-media-uploader.php';

/**
 * The structure check behind invalid_image (1.19.0). getimagesize() reads only the
 * header, so a file altered in transit passes it and then renders black.
 */
final class MediaIntegrityTest extends TestCase {

	/** @var string[] */
	private array $files = [];

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file );
		}
	}

	private function problem( string $bytes, string $mime ): ?string {
		$file          = (string) tempnam( sys_get_temp_dir(), 'iato' );
		$this->files[] = $file;
		file_put_contents( $file, $bytes );
		return IATO_MCP_Media_Uploader::structure_problem( $file, $mime );
	}

	private static function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . hex2bin( hash( 'crc32b', $type . $data ) );
	}

	private static function png( string $extra = '' ): string {
		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 0, 0, 0, 0 ) )
			. $extra
			. self::png_chunk( 'IDAT', (string) gzcompress( "\0\xFF" ) )
			. self::png_chunk( 'IEND', '' );
	}

	public function test_a_whole_png_passes(): void {
		$this->assertNull( $this->problem( self::png(), 'image/png' ) );
	}

	public function test_a_real_png_from_gd_passes(): void {
		if ( ! function_exists( 'imagepng' ) ) {
			$this->markTestSkipped( 'GD is not available' );
		}
		$image = imagecreatetruecolor( 40, 40 );
		ob_start();
		imagepng( $image );
		$this->assertNull( $this->problem( (string) ob_get_clean(), 'image/png' ) );
	}

	public function test_one_changed_byte_in_the_image_data_of_a_png_is_found(): void {
		$png = self::png();
		$at  = strpos( $png, 'IDAT' ) + 5;
		$png[ $at ] = chr( ord( $png[ $at ] ) ^ 1 );
		$this->assertStringContainsString( 'IDAT', (string) $this->problem( $png, 'image/png' ) );
	}

	public function test_a_cut_short_png_is_found_wherever_it_stops(): void {
		$png = self::png();
		$this->assertNotNull( $this->problem( substr( $png, 0, strpos( $png, 'IEND' ) - 4 ), 'image/png' ), 'no end marker' );
		$this->assertNotNull( $this->problem( substr( $png, 0, strpos( $png, 'IDAT' ) + 6 ), 'image/png' ), 'inside the data' );
		$this->assertNotNull( $this->problem( substr( $png, 0, -2 ), 'image/png' ), 'inside the last checksum' );
	}

	public function test_a_bad_checksum_on_an_optional_png_chunk_passes(): void {
		$bad = pack( 'N', 3 ) . 'tEXt' . 'abc' . 'XXXX';
		$this->assertNull( $this->problem( self::png( $bad ), 'image/png' ), 'browsers skip these, so it is not damage worth refusing' );
	}

	public function test_bytes_after_the_end_of_a_png_pass(): void {
		$this->assertNull( $this->problem( self::png() . 'trailing data', 'image/png' ) );
	}

	public function test_a_png_with_no_image_data_is_found(): void {
		$png = "\x89PNG\r\n\x1a\n" . self::png_chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 0, 0, 0, 0 ) ) . self::png_chunk( 'IEND', '' );
		$this->assertStringContainsString( 'no image data', (string) $this->problem( $png, 'image/png' ) );
	}

	public function test_a_png_whose_chunk_header_is_garbage_is_found(): void {
		$png = "\x89PNG\r\n\x1a\n" . pack( 'N', 3 ) . "\x01\x02\x03\x04abcdefg";
		$this->assertStringContainsString( 'cannot be read', (string) $this->problem( $png, 'image/png' ) );
	}

	private static function jpeg( string $metadata = '' ): string {
		$app = "\xFF\xE0" . pack( 'n', strlen( $metadata ) + 2 ) . $metadata;
		return "\xFF\xD8" . $app . "\xFF\xDA" . pack( 'n', 4 ) . "\x00\x00" . 'imagedata' . "\xFF\xD9";
	}

	public function test_a_whole_jpeg_passes(): void {
		$this->assertNull( $this->problem( self::jpeg(), 'image/jpeg' ) );
		$this->assertNull( $this->problem( self::jpeg() . 'trailing data', 'image/jpeg' ) );
	}

	public function test_a_cut_short_jpeg_is_found(): void {
		$this->assertStringContainsString( 'end marker', (string) $this->problem( substr( self::jpeg(), 0, -2 ), 'image/jpeg' ) );
	}

	public function test_a_thumbnails_end_marker_does_not_hide_a_cut_short_jpeg(): void {
		$jpeg = self::jpeg( "thumbnail\xFF\xD9here" );
		$this->assertNull( $this->problem( $jpeg, 'image/jpeg' ) );
		$this->assertNotNull( $this->problem( substr( $jpeg, 0, -2 ), 'image/jpeg' ) );
	}

	public function test_a_jpeg_end_marker_across_a_read_boundary_is_found(): void {
		$jpeg = "\xFF\xD8\xFF\xDA" . pack( 'n', 2 ) . str_repeat( 'x', 65535 ) . "\xFF\xD9";
		$this->assertNull( $this->problem( $jpeg, 'image/jpeg' ) );
	}

	public function test_a_jpeg_this_cannot_follow_passes(): void {
		$this->assertNull( $this->problem( "\xFF\xD8NOTAMARKER", 'image/jpeg' ) );
	}

	private static function gif( string $trailer = ';' ): string {
		return "GIF89a\x01\x00\x01\x00\x80\x00\x00" . "\x00\x00\x00\xFF\xFF\xFF"
			. '!' . "\xF9" . "\x04" . "\x00\x00\x00\x00" . "\x00"
			. ',' . "\x00\x00\x00\x00\x01\x00\x01\x00\x00" . "\x02" . "\x03;;;" . "\x00"
			. $trailer;
	}

	public function test_a_whole_gif_passes(): void {
		$this->assertNull( $this->problem( self::gif(), 'image/gif' ) );
		$this->assertNull( $this->problem( self::gif() . 'trailing', 'image/gif' ) );
	}

	public function test_a_cut_short_gif_is_found_though_its_data_holds_the_marker_byte(): void {
		$this->assertStringContainsString( 'end marker', (string) $this->problem( self::gif( '' ), 'image/gif' ) );
		$this->assertNotNull( $this->problem( substr( self::gif(), 0, -4 ), 'image/gif' ) );
	}

	public function test_a_gif_this_cannot_follow_passes(): void {
		$this->assertNull( $this->problem( "GIF89a\x01\x00\x01\x00\x00\x00\x00" . 'Z', 'image/gif' ) );
	}

	public function test_a_webp_that_fits_passes_and_one_cut_short_is_found(): void {
		$body = 'WEBP' . str_repeat( 'x', 100 );
		$webp = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
		$this->assertNull( $this->problem( $webp, 'image/webp' ) );
		$this->assertNull( $this->problem( $webp . 'trailing', 'image/webp' ) );
		$this->assertStringContainsString( 'cut short', (string) $this->problem( substr( $webp, 0, 50 ), 'image/webp' ) );
	}

	public function test_a_type_with_no_check_passes(): void {
		$this->assertNull( $this->problem( 'anything', 'image/avif' ) );
	}

	public function test_a_missing_file_passes_to_the_checks_that_report_it(): void {
		$this->assertNull( IATO_MCP_Media_Uploader::structure_problem( '/nonexistent/file', 'image/png' ) );
	}
}

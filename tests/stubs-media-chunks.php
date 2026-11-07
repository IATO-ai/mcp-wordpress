<?php
declare( strict_types=1 );

final class IATO_Test_Chunks {
	public static string $base = '';
	public static int $max_upload = 100000000;
	public static int $user = 7;
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( mixed $time = null, bool $create = true ): array { return [ 'basedir' => IATO_Test_Chunks::$base ]; }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $dir ): bool { return is_dir( $dir ) || @mkdir( $dir, 0777, true ); }
}
if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size(): int { return IATO_Test_Chunks::$max_upload; }
}
if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( string $name = '' ): string { return (string) tempnam( sys_get_temp_dir(), 'iato' ); }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string { return preg_replace( '/[^A-Za-z0-9._-]/', '', $name ); }
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( string $filename, ?array $mimes = null ): array {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		foreach ( (array) $mimes as $pattern => $mime ) {
			if ( in_array( $ext, explode( '|', $pattern ), true ) ) {
				return [ 'ext' => $ext, 'type' => $mime ];
			}
		}
		return [ 'ext' => false, 'type' => false ];
	}
}

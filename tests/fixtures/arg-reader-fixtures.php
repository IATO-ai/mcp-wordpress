<?php
declare( strict_types=1 );

/** Helpers the argument reader follows in tests/ToolArgReaderTest.php. */

function iato_mcp_fixture_reads_second( string $name, array $input ): string {
	return (string) ( $input['from_helper'] ?? $name );
}

function iato_mcp_fixture_returns( array $input ): array {
	return $input;
}

final class IATO_MCP_Fixture_Args {

	public const FIELDS = [ 'const_a' => 'x', 'const_b' => 'y' ];

	public static function entry( array $opts ): void {
		self::text( $opts, 'bound_literal' );
		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( array_key_exists( $field, $opts ) ) {
				continue;
			}
		}
	}

	private static function text( array $opts, string $key ): string {
		return (string) ( $opts[ $key ] ?? '' );
	}
}

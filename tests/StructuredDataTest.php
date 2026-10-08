<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once IATO_MCP_DIR . 'includes/class-auth.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-structured-data.php';

/**
 * JSON-LD output must never be able to close its own <script> element, and
 * payloads that try are refused on write.
 */
final class StructuredDataTest extends TestCase {

	private const PAYLOAD = '{"@context":"https://schema.org","@type":"Article","name":"x</script><script>alert(1)</script>"}';

	/** JSON escape sequences as they appear in the encoded output. */
	private const LT = '\u003C';
	private const GT = '\u003E';
	private const SL = '\/';

	// ── Render time (covers values already stored) ───────────────────────

	public function test_render_neutralises_a_stored_script_breakout(): void {
		$out = iato_mcp_structured_data_render( self::PAYLOAD );
		$this->assertIsString( $out );
		$this->assertStringNotContainsStringIgnoringCase( '</script', $out );
		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsString( '>', $out );
		$this->assertStringContainsString( self::LT . self::SL . 'script' . self::GT . self::LT . 'script' . self::GT . 'alert(1)', $out );
		// Still the same data for a JSON-LD consumer.
		$this->assertSame( json_decode( self::PAYLOAD, true ), json_decode( $out, true ) );
	}

	public function test_render_neutralises_comment_openers_and_ampersands(): void {
		$out = iato_mcp_structured_data_render( '{"d":"<!-- x --> a & b \' \""}' );
		$this->assertStringNotContainsString( '<!--', $out );
		$this->assertStringNotContainsString( '&', $out );
		$this->assertStringContainsString( self::LT . '!-- x --' . self::GT . ' a ' . '\u0026' . ' b', $out );
	}

	public function test_render_keeps_unicode_and_valid_json(): void {
		$out = iato_mcp_structured_data_render( '{"name":"Café – ünïcode","url":"https://example.com/a/b"}' );
		$this->assertStringContainsString( 'Café – ünïcode', $out );
		$this->assertSame( 'https://example.com/a/b', json_decode( $out )->url );
	}

	public function test_render_prints_nothing_for_scalars_or_garbage(): void {
		$this->assertNull( iato_mcp_structured_data_render( '"just a string"' ) );
		$this->assertNull( iato_mcp_structured_data_render( '42' ) );
		$this->assertNull( iato_mcp_structured_data_render( 'not json' ) );
		$this->assertNull( iato_mcp_structured_data_render( '' ) );
	}

	// ── Write time ───────────────────────────────────────────────────────

	public function test_validate_rejects_a_script_breakout_payload(): void {
		$err = iato_mcp_structured_data_validate( self::PAYLOAD );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_schema_json', $err->get_error_code() );
	}

	public function test_validate_rejects_the_payload_when_it_is_json_escaped(): void {
		// "\u003c/script" in the input decodes to "</script": the check runs on the decoded value.
		$escaped = '{"name":"' . self::LT . self::SL . 'script' . self::GT . self::LT . 'script' . self::GT . 'alert(1)"}';
		$this->assertStringNotContainsString( '<', $escaped, 'the raw input carries no literal angle bracket' );
		$err = iato_mcp_structured_data_validate( $escaped );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_schema_json', $err->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, iato_mcp_structured_data_validate( '{"name":"< / SCRIPT >"}' ) );
		$this->assertInstanceOf( WP_Error::class, iato_mcp_structured_data_validate( '{"name":"<!-- x"}' ) );
	}

	public function test_validate_rejects_scalars_and_invalid_json(): void {
		$this->assertSame( 'invalid_schema_json', iato_mcp_structured_data_validate( '"string"' )->get_error_code() );
		$this->assertSame( 'invalid_schema_json', iato_mcp_structured_data_validate( 'null' )->get_error_code() );
		$this->assertSame( 'invalid_json', iato_mcp_structured_data_validate( '{nope' )->get_error_code() );
	}

	public function test_validate_stores_safe_encoding_for_valid_schema(): void {
		$in  = '{"@context":"https://schema.org","@type":"Organization","name":"Acme & Sons","url":"https://acme.example/"}';
		$out = iato_mcp_structured_data_validate( $in );
		$this->assertIsString( $out );
		$this->assertStringNotContainsString( '&', $out, 'ampersand is hex-escaped in storage too' );
		$this->assertSame( json_decode( $in, true ), json_decode( $out, true ) );
		// An array of objects is JSON-LD too.
		$this->assertIsString( iato_mcp_structured_data_validate( '[{"@type":"Thing"}]' ) );
	}
}

<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

require_once IATO_MCP_DIR . 'includes/class-oauth.php';

/**
 * PKCE verification at the token endpoint (IATO_MCP_OAuth::verify_pkce).
 */
final class OAuthPkceTest extends TestCase {

	private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	private static function s256( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private static function pkce( string $method = 'S256', ?string $challenge = null ): array {
		return [
			'code_challenge'        => $challenge ?? self::s256( self::VERIFIER ),
			'code_challenge_method' => $method,
			'client_id'             => 'client-a',
			'redirect_uri'          => 'https://client.example/cb',
		];
	}

	public function test_correct_verifier_passes(): void {
		$this->assertNull( IATO_MCP_OAuth::verify_pkce( self::pkce(), 'client-a', 'https://client.example/cb', self::VERIFIER ) );
	}

	public function test_wrong_verifier_fails(): void {
		$this->assertSame( 'PKCE verification failed', IATO_MCP_OAuth::verify_pkce( self::pkce(), 'client-a', 'https://client.example/cb', 'wrong-verifier' ) );
	}

	public function test_empty_verifier_fails_when_a_challenge_was_issued(): void {
		$r = IATO_MCP_OAuth::verify_pkce( self::pkce(), 'client-a', 'https://client.example/cb', '' );
		$this->assertIsString( $r );
		$this->assertStringContainsString( 'code_verifier is required', $r );
	}

	public function test_client_and_redirect_must_match_the_authorization(): void {
		$this->assertStringContainsString( 'client_id', IATO_MCP_OAuth::verify_pkce( self::pkce(), 'client-b', 'https://client.example/cb', self::VERIFIER ) );
		$this->assertStringContainsString( 'redirect_uri', IATO_MCP_OAuth::verify_pkce( self::pkce(), 'client-a', 'https://evil.example/cb', self::VERIFIER ) );
	}

	public function test_plain_method_is_rejected_even_with_a_matching_verifier(): void {
		$this->assertSame( 'unsupported code_challenge_method', IATO_MCP_OAuth::verify_pkce( self::pkce( 'plain', self::VERIFIER ), 'client-a', 'https://client.example/cb', self::VERIFIER ) );
	}

	public function test_unknown_method_fails_closed(): void {
		$this->assertSame( 'unsupported code_challenge_method', IATO_MCP_OAuth::verify_pkce( self::pkce( 'md5' ), 'client-a', 'https://client.example/cb', self::VERIFIER ) );
	}
}

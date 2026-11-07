<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_user_field' ) ) {
	function sanitize_user_field( string $field, mixed $value, int $user_id, string $context ): mixed {
		// What core's save filters do to these, roughly: & is encoded and tags go.
		return 'user_url' === $field ? $value : str_replace( '&', '&amp;', strip_tags( (string) $value ) );
	}
}

/**
 * The rules of update_user_profile (1.19.0). What it does on real WordPress is covered
 * by tests/wp-env/user-profile-live.py.
 */
final class UserProfileTest extends TestCase {

	private static function user(): object {
		return (object) [
			'ID'           => 5,
			'user_login'   => 'jsmith',
			'display_name' => 'jsmith',
			'nickname'     => 'Jay',
			'first_name'   => 'Jane',
			'last_name'    => 'Smith',
			'description'  => '',
			'user_url'     => '',
		];
	}

	private function refusal( array $args ): string {
		$r = IATO_MCP_User_Profile::plan( $args, self::user() );
		$this->assertInstanceOf( WP_Error::class, $r );
		return $r->get_error_code();
	}

	public function test_the_names_wordpress_offers(): void {
		$values = [ 'nickname' => 'Jay', 'first_name' => 'Jane', 'last_name' => 'Smith' ];
		$this->assertSame(
			[ 'Current', 'Jay', 'jsmith', 'Jane', 'Smith', 'Jane Smith', 'Smith Jane' ],
			IATO_MCP_User_Profile::offered_names( $values, 'jsmith', 'Current' )
		);
	}

	public function test_a_missing_first_or_last_name_offers_no_pair(): void {
		$values = [ 'nickname' => 'Jay', 'first_name' => 'Jane', 'last_name' => '' ];
		$this->assertSame( [ 'Jay', 'jsmith', 'Jane' ], IATO_MCP_User_Profile::offered_names( $values, 'jsmith', 'Jay' ) );
	}

	public function test_each_offered_name_is_accepted(): void {
		foreach ( [ 'Jay', 'jsmith', 'Jane', 'Smith', 'Jane Smith', 'Smith Jane' ] as $name ) {
			$r = IATO_MCP_User_Profile::plan( [ 'display_name' => $name ], self::user() );
			if ( 'jsmith' === $name ) {
				$this->assertSame( [], $r, 'the one it already has' );
				continue;
			}
			$this->assertSame( [ 'display_name' => $name ], $r, $name );
		}
	}

	public function test_another_name_is_refused_with_the_list(): void {
		$r = IATO_MCP_User_Profile::plan( [ 'display_name' => 'Somebody Else' ], self::user() );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'display_name_not_offered', $r->get_error_code() );
		$this->assertContains( 'Jane Smith', $r->get_error_data()['offered'] );
	}

	public function test_setting_the_nickname_to_the_same_value_allows_it(): void {
		$r = IATO_MCP_User_Profile::plan( [ 'display_name' => 'Build Co', 'nickname' => 'Build Co' ], self::user() );
		$this->assertSame( [ 'display_name' => 'Build Co', 'nickname' => 'Build Co' ], $r );
	}

	public function test_it_compares_the_values_as_they_would_be_after_this_call(): void {
		$r = IATO_MCP_User_Profile::plan( [ 'display_name' => 'Pat Lee', 'first_name' => 'Pat', 'last_name' => 'Lee' ], self::user() );
		$this->assertSame( [ 'display_name' => 'Pat Lee', 'first_name' => 'Pat', 'last_name' => 'Lee' ], $r );
	}

	public function test_it_compares_what_will_be_stored(): void {
		$r = IATO_MCP_User_Profile::plan( [ 'display_name' => 'A & B', 'nickname' => 'A & B' ], self::user() );
		$this->assertSame( [ 'display_name' => 'A &amp; B', 'nickname' => 'A &amp; B' ], $r );
	}

	public function test_only_what_changes_is_planned(): void {
		$this->assertSame( [ 'last_name' => 'Jones' ], IATO_MCP_User_Profile::plan( [ 'first_name' => 'Jane', 'last_name' => 'Jones' ], self::user() ) );
	}

	public function test_refusals(): void {
		$this->assertSame( 'empty_field', $this->refusal( [ 'nickname' => '' ] ) );
		$this->assertSame( 'empty_field', $this->refusal( [ 'nickname' => '<b></b>' ] ), 'empty as stored' );
		$this->assertSame( 'empty_field', $this->refusal( [ 'display_name' => '   ' ] ) );
		$this->assertSame( 'invalid_field', $this->refusal( [ 'nickname' => [ 'x' ] ] ) );
		$this->assertSame( 'invalid_field', $this->refusal( [ 'first_name' => 5 ] ) );
		$this->assertSame( 'invalid_url', $this->refusal( [ 'url' => 'javascript:alert(1)' ] ) );
		$this->assertSame( 'invalid_url', $this->refusal( [ 'url' => 'example.com' ] ) );
		$this->assertSame( 'field_too_long', $this->refusal( [ 'url' => 'https://example.com/' . str_repeat( 'x', 100 ) ] ) );
		$this->assertSame( 'field_too_long', $this->refusal( [ 'display_name' => str_repeat( 'x', 251 ), 'nickname' => str_repeat( 'x', 251 ) ] ) );
	}

	public function test_a_url_can_be_cleared_or_set(): void {
		$this->assertSame( [ 'url' => 'https://example.com/' ], IATO_MCP_User_Profile::plan( [ 'url' => 'https://example.com/' ], self::user() ) );
		$this->assertSame( [], IATO_MCP_User_Profile::plan( [ 'url' => '' ], self::user() ) );
	}

	public function test_empty_first_and_last_names_and_description_are_fine(): void {
		$this->assertSame( [ 'first_name' => '', 'last_name' => '' ], IATO_MCP_User_Profile::plan( [ 'first_name' => '', 'last_name' => '' ], self::user() ) );
	}

	public function test_the_receipt_is_checked_against_the_user_it_is_about(): void {
		$this->assertSame( 'edit_posts', IATO_MCP_Change_Receipt::cap_required_for( [ 'target_type' => 'user_profile', 'field' => 'profile' ] ) );
	}
}

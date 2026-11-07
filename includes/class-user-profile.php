<?php
/**
 * The public fields of a user's profile, for update_user_profile and rollback (1.19.0).
 * Email, password, roles and capabilities are never reachable from here.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

final class IATO_MCP_User_Profile {

	public const RECEIPT = 'user_profile';

	/** What a call may send, and the name WordPress knows it by. */
	public const FIELDS = [
		'display_name' => 'display_name',
		'nickname'     => 'nickname',
		'first_name'   => 'first_name',
		'last_name'    => 'last_name',
		'description'  => 'description',
		'url'          => 'user_url',
	];

	/** Fields kept in the users table rather than as meta. */
	private const COLUMNS = [ 'display_name', 'url' ];

	/** Past this, the database refuses the whole save with no error. */
	private const MAX_LENGTH = [ 'display_name' => 250, 'url' => 100 ];

	/** A user's fields as stored. */
	public static function read( object $user ): array {
		$values = [];
		foreach ( self::FIELDS as $field => $key ) {
			$values[ $field ] = (string) $user->{$key};
		}
		return $values;
	}

	/** What WordPress will store for this value: its save filters encode & as &amp;, for one. */
	public static function as_stored( string $field, string $value, int $user_id ): string {
		return (string) wp_unslash( sanitize_user_field( self::FIELDS[ $field ], wp_slash( $value ), $user_id, 'db' ) );
	}

	/**
	 * What Users > Profile offers under "Display name publicly as".
	 *
	 * @param array  $values The user's fields, as they would be.
	 * @param string $login  The user's login.
	 * @param string $current The display name stored now.
	 * @return string[]
	 */
	public static function offered_names( array $values, string $login, string $current ): array {
		$first  = (string) $values['first_name'];
		$last   = (string) $values['last_name'];
		$names  = [ $current, (string) $values['nickname'], $login, $first, $last ];
		$names[] = '' !== $first && '' !== $last ? "{$first} {$last}" : '';
		$names[] = '' !== $first && '' !== $last ? "{$last} {$first}" : '';
		return array_values( array_unique( array_filter( array_map( 'trim', $names ), static fn( string $name ): bool => '' !== $name ) ) );
	}

	/**
	 * Check what a call sends.
	 *
	 * @return array|WP_Error The fields that change, field => value as it will be stored.
	 */
	public static function plan( array $args, object $user ): array|WP_Error {
		$user_id = (int) $user->ID;
		$values  = self::read( $user );
		$changes = [];
		$after   = $values;

		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( ! array_key_exists( $field, $args ) ) {
				continue;
			}
			$sent = $args[ $field ];
			if ( ! is_string( $sent ) ) {
				return new WP_Error( 'invalid_field', sprintf( '%s must be text. Nothing was written.', $field ), [ 'field' => $field ] );
			}
			if ( 'url' === $field && '' !== $sent && ! in_array( strtolower( (string) wp_parse_url( $sent, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) ) {
				return new WP_Error( 'invalid_url', 'url must be an http or https address, or empty to clear it. Nothing was written.', [ 'field' => $field ] );
			}
			$stored = self::as_stored( $field, $sent, $user_id );
			if ( in_array( $field, [ 'display_name', 'nickname' ], true ) && '' === trim( $stored ) ) {
				return new WP_Error( 'empty_field', sprintf( '%s cannot be empty. Nothing was written.', $field ), [ 'field' => $field ] );
			}
			if ( isset( self::MAX_LENGTH[ $field ] ) && strlen( $stored ) > self::MAX_LENGTH[ $field ] ) {
				return new WP_Error(
					'field_too_long',
					sprintf( '%1$s can be at most %2$d characters as stored. Nothing was written.', $field, self::MAX_LENGTH[ $field ] ),
					[ 'field' => $field, 'max_length' => self::MAX_LENGTH[ $field ] ]
				);
			}
			$after[ $field ] = $stored;
			if ( $stored !== $values[ $field ] ) {
				$changes[ $field ] = $stored;
			}
		}

		if ( isset( $changes['display_name'] ) ) {
			$offered = self::offered_names( [ 'display_name' => '' ] + $after, (string) $user->user_login, $values['display_name'] );
			if ( ! in_array( $changes['display_name'], $offered, true ) ) {
				return new WP_Error(
					'display_name_not_offered',
					'display_name must be one of the names WordPress offers for this user, which come from their nickname, login, and first and last names. To use another, set nickname to the same value in this call. Nothing was written.',
					[ 'field' => 'display_name', 'offered' => $offered ]
				);
			}
		}
		return $changes;
	}

	/**
	 * Save these fields as WordPress saves a profile. wp_update_user() saves every field
	 * again, through the save filters: changing a nickname would take an iframe out of a
	 * description written by someone with more rights. So what this call does not name
	 * goes back as stored, and with $verbatim so do the values it names.
	 */
	public static function write( int $user_id, array $changes, bool $verbatim = false ): int|WP_Error {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', sprintf( 'There is no user %d.', $user_id ) );
		}
		$data = [ 'ID' => $user_id ];
		foreach ( $changes as $field => $value ) {
			$data[ self::FIELDS[ $field ] ] = $value;
		}
		$stored  = self::read( $user );
		$exactly = $verbatim ? array_merge( $stored, $changes ) : array_diff_key( $stored, $changes );

		$keep_columns = static function ( array $columns, mixed $update, mixed $id ) use ( $user_id, $exactly ): array {
			if ( (int) $id === $user_id ) {
				foreach ( array_intersect_key( $exactly, array_flip( self::COLUMNS ) ) as $field => $value ) {
					$columns[ self::FIELDS[ $field ] ] = $value; // Unslashed, as $columns is by now.
				}
			}
			return $columns;
		};
		$keep_meta = static function ( array $meta, mixed $who ) use ( $user_id, $exactly ): array {
			if ( (int) ( $who->ID ?? 0 ) === $user_id ) {
				foreach ( array_diff_key( $exactly, array_flip( self::COLUMNS ) ) as $field => $value ) {
					$meta[ self::FIELDS[ $field ] ] = wp_slash( (string) $value ); // update_user_meta() unslashes.
				}
			}
			return $meta;
		};
		add_filter( 'wp_pre_insert_user_data', $keep_columns, PHP_INT_MAX, 3 );
		add_filter( 'insert_user_meta', $keep_meta, PHP_INT_MAX, 2 );
		try {
			$result = wp_update_user( wp_slash( $data ) ); // Slashed: wp_insert_user() unslashes.
		} finally {
			remove_filter( 'wp_pre_insert_user_data', $keep_columns, PHP_INT_MAX );
			remove_filter( 'insert_user_meta', $keep_meta, PHP_INT_MAX );
		}
		return is_wp_error( $result ) ? $result : (int) $result;
	}

	/** The user a receipt's value is about. */
	public static function user_id_of( mixed $value ): int {
		return (int) ( IATO_MCP_Rollback_Conflict::json_array( $value )['user_id'] ?? 0 );
	}

	/** Back as it was, past the save filters: what was there may be a person's with more rights. */
	public static function restore( mixed $before ): bool|WP_Error {
		$value  = IATO_MCP_Rollback_Conflict::json_array( $before );
		$fields = array_intersect_key( array_map( 'strval', (array) ( $value['fields'] ?? [] ) ), self::FIELDS );
		$saved  = self::write( (int) ( $value['user_id'] ?? 0 ), $fields, true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		clean_user_cache( (int) $value['user_id'] );
		return true;
	}

	/** Why this receipt cannot be rolled back, or null. */
	public static function rollback_unavailable( mixed $before ): ?WP_Error {
		$user_id = self::user_id_of( $before );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'rollback_unavailable', sprintf( 'User %d no longer exists.', $user_id ), [ 'status' => 409 ] );
		}
		return null;
	}

	/** The current values of the fields in a receipt, for the conflict check. */
	public static function current( mixed $after ): ?array {
		$value = IATO_MCP_Rollback_Conflict::json_array( $after );
		$user  = get_userdata( (int) ( $value['user_id'] ?? 0 ) );
		if ( ! $user ) {
			return null;
		}
		return [
			'user_id' => (int) $value['user_id'],
			'fields'  => array_intersect_key( self::read( $user ), (array) ( $value['fields'] ?? [] ) ),
		];
	}
}

<?php
/**
 * The two images behind a site's identity, for update_site_settings and its
 * rollback (1.18.0): the icon (option `site_icon`) and the logo (theme mod
 * `custom_logo`).
 *
 * The instance methods call WordPress, so tests can replace them with
 * set_instance(), as IATO_MCP_Theme_Builder's do. The static methods are the rules.
 *
 * WordPress's own route to a site icon (wp_ajax_crop_image) makes a cropped copy in
 * a new attachment. This one uses the attachment it is given, so an image that is
 * not square is refused rather than cropped, and an image larger than 512 pixels
 * stays that size: get_site_icon_url() returns the full image from 512 up.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

class IATO_MCP_Site_Image {

	public const ALLOWED_TYPES = [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ];

	/** WordPress asks for at least this much, and uses the full image from here up. */
	public const ICON_SIZE = 512;

	/**
	 * Each setting's control in Elementor's Site Settings. When the active kit has a
	 * value saved under it, publishing Site Settings writes that value back over the
	 * WordPress setting (Settings_Site_Identity::on_save()), so the two are kept equal.
	 */
	public const KIT_SETTINGS = [ 'site_icon' => 'site_favicon', 'custom_logo' => 'site_logo' ];

	/** Refusals that carry a code and details for the client (refusal()). */
	public const REFUSALS = [
		'attachment_not_found',
		'not_an_attachment',
		'attachment_in_trash',
		'unsupported_image_type',
		'attachment_not_ready',
		'attachment_unreadable',
		'image_not_square',
	];

	private const KIT_META = '_elementor_page_settings';

	private static ?self $instance = null;

	private ?WP_Site_Icon $site_icon = null;

	public static function get(): self {
		return self::$instance ??= new self();
	}

	public static function set_instance( ?self $instance ): void {
		self::$instance = $instance;
	}

	// ── The rules ─────────────────────────────────────────────────────────────

	/**
	 * Validate a value for `site_icon` or `custom_logo`. Writes nothing.
	 *
	 * @return array{id:int,warnings:string[],missing_sizes:string[]}|WP_Error
	 */
	public static function check( string $setting, mixed $raw ): array|WP_Error {
		if ( ( ! is_int( $raw ) || $raw < 0 ) && ! ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
			return new WP_Error( 'invalid_setting', "{$setting} must be an attachment ID, or 0 to clear it.", [ 'status' => 400 ] );
		}
		$id = (int) $raw;
		if ( 0 === $id ) {
			return [ 'id' => 0, 'warnings' => [], 'missing_sizes' => [] ];
		}

		$why = self::attachment_refusal( $setting, $id );
		if ( null !== $why ) {
			return $why;
		}
		$cap = IATO_MCP_Auth::require_cap( 'edit_post', $id ); // the write changes the attachment's metadata
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$image   = self::get()->read_image( $id );
		$allowed = 'PNG, JPEG, GIF or WebP';
		if ( ! in_array( $image['stored_type'], self::ALLOWED_TYPES, true ) ) {
			return new WP_Error(
				'unsupported_image_type',
				sprintf( 'Attachment %d is %s. %s must be %s.', $id, '' === $image['stored_type'] ? 'not an image' : $image['stored_type'], $setting, $allowed ),
				[ 'status' => 400, 'mime_type' => $image['stored_type'], 'allowed' => self::ALLOWED_TYPES ]
			);
		}
		// The stored type is what the upload claimed. The file decides.
		if ( $image['has_file'] && ! in_array( $image['real_type'], self::ALLOWED_TYPES, true ) ) {
			return new WP_Error(
				'unsupported_image_type',
				sprintf( 'Attachment %1$d says it is %2$s, but its file is not a %3$s image. Upload the image again.', $id, $image['stored_type'], $allowed ),
				[ 'status' => 400, 'mime_type' => (string) $image['real_type'], 'allowed' => self::ALLOWED_TYPES ]
			);
		}
		if ( ! $image['has_metadata'] && $image['has_file'] ) {
			// create_media with defer_subsizes. Its background job overwrites the metadata
			// when it finishes, which would drop any size made for the icon before that.
			return new WP_Error(
				'attachment_not_ready',
				sprintf( 'Attachment %d has no image metadata yet, because its sizes are still being generated. Try again in a minute. If this keeps happening, upload the image again.', $id ),
				[ 'status' => 409 ]
			);
		}
		if ( $image['width'] <= 0 || $image['height'] <= 0 ) {
			return new WP_Error(
				'attachment_unreadable',
				sprintf( 'The dimensions of attachment %d cannot be read: it has no image metadata and its file is not on this server.', $id ),
				[ 'status' => 400 ]
			);
		}

		$warnings = [];
		$missing  = [];
		if ( 'site_icon' === $setting ) {
			if ( $image['width'] !== $image['height'] ) {
				return new WP_Error(
					'image_not_square',
					sprintf( 'Attachment %1$d is %2$dx%3$d pixels. A site icon must be square. This tool does not crop: crop the image, upload it and send its new ID.', $id, $image['width'], $image['height'] ),
					[ 'status' => 400, 'width' => $image['width'], 'height' => $image['height'] ]
				);
			}
			if ( $image['width'] < self::ICON_SIZE ) {
				$warnings[] = sprintf(
					'Attachment %1$d is %2$dx%2$d pixels, smaller than the %3$dx%3$d WordPress recommends. It is set, but browsers and devices that ask for a larger icon will scale it up and it can look blurry.',
					$id,
					$image['width'],
					self::ICON_SIZE
				);
			}
			foreach ( self::get()->icon_sizes() as $name => $size ) {
				if ( (int) ( $size['height'] ?? 0 ) < $image['width'] && ! in_array( (string) $name, $image['sizes'], true ) ) {
					$missing[] = (string) $name;
				}
			}
			if ( [] !== $missing && ! $image['has_file'] ) {
				$warnings[] = sprintf( 'The file of attachment %d is not on this server, so the icon sizes cannot be created. Browsers will get a larger image.', $id );
				$missing    = [];
			}
		}
		return [ 'id' => $id, 'warnings' => $warnings, 'missing_sizes' => $missing ];
	}

	/** Why this ID cannot be used at all, or null. Also what rollback asks of a previous image. */
	public static function attachment_refusal( string $setting, int $id ): ?WP_Error {
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'attachment_not_found', "{$setting}: there is no attachment {$id} on this site.", [ 'status' => 404 ] );
		}
		if ( 'attachment' !== ( $post->post_type ?? '' ) ) {
			return new WP_Error( 'not_an_attachment', "{$setting}: {$id} is not a Media Library item.", [ 'status' => 400 ] );
		}
		if ( 'trash' === ( $post->post_status ?? '' ) ) {
			return new WP_Error( 'attachment_in_trash', "{$setting}: attachment {$id} is in the trash.", [ 'status' => 400 ] );
		}
		return null;
	}

	/** The setting's current attachment ID. "None" can be stored as 0, '' or nothing, and is always 0 here. */
	public static function current( string $setting ): int {
		return 'custom_logo' === $setting ? self::get()->logo_id() : (int) get_option( 'site_icon', 0 );
	}

	/** {id, url} for before and after values. */
	public static function describe( int $id ): array {
		return [ 'id' => $id, 'url' => $id > 0 ? self::get()->image_url( $id ) : null ];
	}

	/** What get_site_settings reports: null with nothing set, a null url when the image has gone. */
	public static function report( string $setting ): ?array {
		$id = self::current( $setting );
		if ( $id <= 0 ) {
			return null;
		}
		return [ 'id' => $id, 'url' => 'site_icon' === $setting ? self::get()->icon_url( self::ICON_SIZE ) : self::get()->image_url( $id ) ];
	}

	/** Which of the two settings use this attachment. */
	public static function used_as( int $id ): array {
		if ( $id <= 0 ) {
			return [];
		}
		return array_values( array_filter( array_keys( self::KIT_SETTINGS ), static fn( string $setting ): bool => self::current( $setting ) === $id ) );
	}

	/** Does Elementor's Site Settings hold another value for this setting? */
	public static function kit_differs( string $setting, int $id ): bool {
		$saved = self::get()->saved_in_kit( self::KIT_SETTINGS[ $setting ] );
		return null !== $saved && $saved !== $id;
	}

	/**
	 * Put the same image in Elementor's Site Settings, only when it already holds a
	 * saved value: with none, its control follows the WordPress setting by itself.
	 *
	 * @return array{synced:bool,warning:?string}
	 */
	public static function sync_kit( string $setting, int $id ): array {
		if ( ! self::kit_differs( $setting, $id ) ) {
			return [ 'synced' => false, 'warning' => null ];
		}
		if ( self::get()->write_kit_media( self::KIT_SETTINGS[ $setting ], $id ) ) {
			return [ 'synced' => true, 'warning' => null ];
		}
		return [
			'synced'  => false,
			'warning' => sprintf( 'Elementor\'s Site Settings still holds another image for %s, and publishing Site Settings would put it back. Change it there too.', $setting ),
		];
	}

	/** An attachment in use is being deleted: nothing should keep pointing at it. */
	public static function forget( int $id, array $settings ): void {
		if ( in_array( 'site_icon', $settings, true ) ) {
			update_option( 'site_icon', 0 );
			self::sync_kit( 'site_icon', 0 );
		}
		if ( in_array( 'custom_logo', $settings, true ) ) {
			self::get()->set_logo( 0 ); // WordPress removes it on delete too, when its hook is loaded
			self::sync_kit( 'custom_logo', 0 );
		}
		if ( [] !== $settings ) {
			// Every cached page links to the image that has gone.
			IATO_MCP_Cache::note_write( 'site_setting', null, implode( ',', $settings ), (string) $id, '0' );
		}
	}

	/** The refusal text for a client: MCP clients see only the text, so the code and details go in it. */
	public static function refusal( WP_Error $error ): array {
		$data = $error->get_error_data();
		return [
			'isError' => true,
			'content' => [ [
				'type' => 'text',
				'text' => (string) wp_json_encode(
					[ 'error' => $error->get_error_code(), 'message' => $error->get_error_message() ]
					+ array_diff_key( is_array( $data ) ? $data : [], [ 'status' => true ] )
				),
			] ],
		];
	}

	// ── WordPress ─────────────────────────────────────────────────────────────

	/**
	 * What the attachment really is: the file over its metadata, when it is here.
	 *
	 * @return array{stored_type:string,real_type:string|false,has_file:bool,has_metadata:bool,width:int,height:int,sizes:string[]}
	 */
	public function read_image( int $id ): array {
		$file     = (string) get_attached_file( $id );
		$has_file = '' !== $file && file_exists( $file );
		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : [];
		$width    = (int) ( $meta['width'] ?? 0 );
		$height   = (int) ( $meta['height'] ?? 0 );
		$real     = false;
		if ( $has_file ) {
			$real = wp_get_image_mime( $file );
			$size = wp_getimagesize( $file );
			if ( is_array( $size ) ) {
				$width  = (int) $size[0];
				$height = (int) $size[1];
			}
		}
		return [
			'stored_type'  => (string) get_post_mime_type( $id ),
			'real_type'    => $real,
			'has_file'     => $has_file,
			'has_metadata' => [] !== $meta,
			'width'        => $width,
			'height'       => $height,
			'sizes'        => isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? array_map( 'strval', array_keys( $meta['sizes'] ) ) : [],
		];
	}

	/** WordPress's own list for this version and site (`site_icon_image_sizes`), so never hard-coded. */
	public function icon_sizes(): array {
		return $this->wp_site_icon()->additional_sizes( [] );
	}

	/**
	 * Create these site icon sizes and nothing else. wp_generate_attachment_metadata()
	 * would rewrite every size and run image optimisers over all of them, which is
	 * what can push create_media past a gateway's timeout.
	 *
	 * @param string[] $names
	 * @return string[] Those that now exist.
	 */
	public function make_sizes( int $id, array $names ): array {
		$wanted = array_intersect_key( $this->icon_sizes(), array_flip( $names ) );
		$only   = static function ( array $missing, array $meta, int $attachment_id ) use ( $id, $wanted ): array {
			return $attachment_id === $id ? array_diff_key( $wanted, $meta['sizes'] ?? [] ) : $missing;
		};
		require_once ABSPATH . 'wp-admin/includes/image.php';
		add_filter( 'wp_get_missing_image_subsizes', $only, 10, 3 );
		try {
			$meta = wp_update_image_subsizes( $id );
		} catch ( \Throwable $e ) {
			$meta = null;
		} finally {
			remove_filter( 'wp_get_missing_image_subsizes', $only, 10 );
		}
		if ( ! is_array( $meta ) ) {
			$meta = wp_get_attachment_metadata( $id ); // a size made before a failure is still there
		}
		$sizes = is_array( $meta ) && isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : [];
		return array_values( array_filter( $names, static fn( string $name ): bool => isset( $sizes[ $name ] ) ) );
	}

	public function image_url( int $id ): ?string {
		return wp_get_attachment_url( $id ) ?: null;
	}

	public function icon_url( int $size ): ?string {
		return get_site_icon_url( $size ) ?: null;
	}

	public function logo_id(): int {
		return (int) get_theme_mod( 'custom_logo', 0 );
	}

	public function set_logo( int $id ): void {
		set_theme_mod( 'custom_logo', $id );
	}

	public function theme(): string {
		return get_stylesheet();
	}

	/** The ID saved under this control in the active kit, or null with nothing saved. */
	public function saved_in_kit( string $control ): ?int {
		$settings = $this->kit_settings();
		if ( ! isset( $settings[ $control ] ) ) {
			return null;
		}
		return is_array( $settings[ $control ] ) ? (int) ( $settings[ $control ]['id'] ?? 0 ) : 0;
	}

	/**
	 * The meta directly, so it works for every credential: Elementor's
	 * update_settings() refuses a request with no user.
	 */
	public function write_kit_media( string $control, int $id ): bool {
		$settings = $this->kit_settings();
		if ( ! isset( $settings[ $control ] ) ) {
			return false;
		}
		$value        = is_array( $settings[ $control ] ) ? $settings[ $control ] : [];
		$value['id']  = $id > 0 ? $id : '';
		$value['url'] = $id > 0 ? (string) wp_get_attachment_url( $id ) : '';

		$settings[ $control ] = $value;

		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		try {
			return (bool) IATO_MCP_Elementor_Adapter::write_elementor_meta(
				static fn() => update_post_meta( $kit_id, self::KIT_META, wp_slash( $settings ) )
			);
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Not gated on Elementor being active: its saved value comes back when it is reactivated. */
	private function kit_settings(): array {
		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( $kit_id <= 0 ) {
			return [];
		}
		$settings = get_post_meta( $kit_id, self::KIT_META, true );
		return is_array( $settings ) ? $settings : [];
	}

	private function wp_site_icon(): WP_Site_Icon {
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-icon.php';
		return $this->site_icon ??= new WP_Site_Icon();
	}
}

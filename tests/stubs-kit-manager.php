<?php
declare( strict_types=1 );

namespace Elementor\Core\Kits;

if ( ! class_exists( Manager::class, false ) ) {
	/**
	 * Elementor's kits manager, as far as the title and tagline go. Its callbacks are
	 * closures on update_option_{option}, as in core/kits/manager.php, and copying
	 * throws for a user who cannot edit the kit or with no usable kit, as
	 * Document::update_settings() does.
	 */
	class Manager {
		public int $copies = 0;
		public bool $get_active_kit_throws = false;

		public function __construct( public int $kit_id ) {}

		public function register(): void {
			\IATO_Test_WP::add_hook(
				'update_option_blogname',
				function ( $old_value, $value ): void {
					$this->copy( 'site_name', $value );
				},
				10,
				2
			);
			\IATO_Test_WP::add_hook(
				'update_option_blogdescription',
				function ( $old_value, $value ): void {
					$this->copy( 'site_description', $value );
				},
				10,
				2
			);
		}

		public function copy( string $setting, mixed $value ): void {
			if ( $this->kit_id <= 0 ) {
				throw new \Exception( 'Invalid post.' );
			}
			if ( ! current_user_can( 'edit_post', $this->kit_id ) ) {
				throw new \Exception( 'Access denied.' );
			}
			++$this->copies;
		}

		public function get_active_kit(): object {
			if ( $this->get_active_kit_throws ) {
				throw new \RuntimeException( 'from another plugin' );
			}
			return new class( $this->kit_id ) {
				public function __construct( private int $id ) {}
				public function get_main_id(): int {
					return $this->id;
				}
			};
		}
	}
}

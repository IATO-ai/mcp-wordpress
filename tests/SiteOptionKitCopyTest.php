<?php
declare( strict_types=1 );

use Elementor\Core\Kits\Manager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs-cache.php';
require_once __DIR__ . '/stubs-rollback.php';
require_once __DIR__ . '/stubs-tools.php';
require_once __DIR__ . '/stubs-meta.php';
require_once __DIR__ . '/stubs-elementor.php';
require_once __DIR__ . '/stubs-kit-manager.php';
require_once IATO_MCP_DIR . 'includes/class-change-receipt.php';
require_once IATO_MCP_DIR . 'includes/class-rollback.php';
require_once IATO_MCP_DIR . 'includes/class-rollback-conflict.php';
require_once IATO_MCP_DIR . 'includes/tools/wp/tool-site.php';

/**
 * The title and tagline, which Elementor copies into its kit (1.18.1). Its copy
 * throws after the value is stored when the user cannot edit the kit or no kit is
 * usable, so those writes take it off the hook and make the copy themselves.
 */
final class SiteOptionKitCopyTest extends TestCase {

	private const ADMIN = 1, KIT = 77, META = '_elementor_page_settings';

	private Manager $manager;
	private array $caps = [];

	protected function setUp(): void {
		IATO_Test_Cache_State::reset();
		IATO_Test_Roles::$users = [ self::ADMIN => 'administrator' ];
		foreach ( [ 'blogname' => 'Old name', 'blogdescription' => 'Old tagline', 'show_on_front' => 'posts' ] as $option => $value ) {
			IATO_Test_Abilities_State::$options[ $option ] = $value;
		}
		IATO_Test_WP::reset_hooks();
		IATO_Test_WP::$meta_write_fails = false;
		IATO_Test_WP::$meta             = [ self::KIT => [ self::META => [ 'site_name' => 'Old name', 'site_description' => 'Old tagline', 'note' => 'C:\\temp' ] ] ];

		$this->caps                           = IATO_Test_Abilities_State::$caps;
		IATO_Test_Abilities_State::$caps['edit_post'] = false;
		$this->manager                        = new Manager( self::KIT );
		$this->manager->register();
		\Elementor\Plugin::$instance->kits_manager = $this->manager;

		$GLOBALS['wpdb']->inserts = [];
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, true );
		$ref->getProperty( 'authenticated_user' )->setValue( null, new WP_User( self::ADMIN ) );
		$ref->getProperty( 'site_key_full_access' )->setValue( null, false );
		$ref->getProperty( 'credential' )->setValue( null, [ 'kind' => 'application_password', 'user_id' => self::ADMIN ] );
		IATO_MCP_Cache::begin();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance->kits_manager = null;
		IATO_Test_Abilities_State::$caps           = $this->caps;
		IATO_Test_WP::$meta_write_fails            = false;
		IATO_Test_WP::$meta                        = [];
		unset( $GLOBALS['wp_filter'] );
		$ref = new ReflectionClass( IATO_MCP_Auth::class );
		$ref->getProperty( 'authenticated' )->setValue( null, false );
		$ref->getProperty( 'authenticated_user' )->setValue( null, null );
		$ref->getProperty( 'credential' )->setValue( null, [] );
		IATO_MCP_Cache::begin();
	}

	private function call( array $args ): array {
		$r = ( IATO_MCP_Server::get_catalog_entry( 'update_site_settings' )['handler'] )( $args );
		$this->assertNotInstanceOf( WP_Error::class, $r );
		return json_decode( $r['content'][0]['text'], true );
	}

	private function kit(): array {
		return IATO_Test_WP::$meta[ self::KIT ][ self::META ];
	}

	private function option( string $name ): mixed {
		return IATO_Test_Abilities_State::$options[ $name ] ?? null;
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function copied(): array {
		return [
			'title'   => [ 'title', 'blogname', 'site_name' ],
			'tagline' => [ 'tagline', 'blogdescription', 'site_description' ],
		];
	}

	#[DataProvider( 'copied' )]
	public function test_a_user_who_cannot_edit_the_kit_gets_a_receipt_and_the_copy( string $input, string $option, string $setting ): void {
		$r = $this->call( [ $input => 'New text' ] );

		$this->assertSame( 'New text', $this->option( $option ) );
		$this->assertSame( $option, $r['change_receipt']['field'] );
		$this->assertSame( 'New text', $r['changes'][ $input ]['after'] );
		$this->assertArrayNotHasKey( 'warnings', $r );
		$this->assertSame( 'New text', $this->kit()[ $setting ], 'or publishing Site Settings writes the old text back' );
		$this->assertSame( 0, $this->manager->copies );
	}

	public function test_the_other_kit_settings_are_kept_backslash_included(): void {
		$this->call( [ 'title' => 'New text' ] );

		$this->assertSame( 'C:\\temp', $this->kit()['note'] );
		$this->assertSame( 'Old tagline', $this->kit()['site_description'] );
	}

	public function test_a_kit_with_no_settings_yet_gets_the_copy(): void {
		unset( IATO_Test_WP::$meta[ self::KIT ][ self::META ] );

		$this->call( [ 'title' => 'New text' ] );

		$this->assertSame( [ 'site_name' => 'New text' ], $this->kit() );
	}

	public function test_a_user_who_can_edit_the_kit_leaves_the_copy_to_elementor(): void {
		IATO_Test_Abilities_State::$caps['edit_post'] = true;

		$r = $this->call( [ 'title' => 'New text' ] );

		$this->assertSame( 1, $this->manager->copies );
		$this->assertSame( 'Old name', $this->kit()['site_name'], 'the plugin made no copy of its own' );
		$this->assertSame( 'blogname', $r['change_receipt']['field'] );
	}

	public function test_elementors_callback_is_back_afterwards(): void {
		$this->call( [ 'title' => 'New text' ] );
		$this->assertSame( 1, IATO_Test_WP::hook_count( 'update_option_blogname' ) );

		IATO_Test_Abilities_State::$caps['edit_post'] = true;
		$this->call( [ 'title' => 'Newer text' ] );
		$this->assertSame( 1, $this->manager->copies, 'and it still works' );
	}

	public function test_other_callbacks_on_the_hook_still_run(): void {
		$ran = [];
		IATO_Test_WP::add_hook(
			'update_option_blogname',
			static function ( $old, $value ) use ( &$ran ): void {
				$ran[] = $value;
			},
			20,
			2
		);

		$this->call( [ 'title' => 'New text' ] );

		$this->assertSame( [ 'New text' ], $ran );
		$this->assertSame( 2, IATO_Test_WP::hook_count( 'update_option_blogname' ) );
	}

	public function test_another_plugins_throw_still_puts_elementors_callback_back(): void {
		IATO_Test_WP::add_hook(
			'update_option_blogname',
			static function (): void {
				throw new \RuntimeException( 'from another plugin' );
			},
			20,
			2
		);

		try {
			IATO_MCP_Elementor_Adapter::update_site_option( 'blogname', 'New text' );
			$this->fail( 'it is not the fix\'s to hide' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'from another plugin', $e->getMessage() );
		}
		$this->assertSame( 2, IATO_Test_WP::hook_count( 'update_option_blogname' ) );
	}

	public function test_a_callback_that_is_a_method_of_the_manager_counts_too(): void {
		IATO_Test_WP::reset_hooks();
		IATO_Test_WP::add_hook( 'update_option_blogname', [ $this->manager, 'copy' ], 10, 2 );

		IATO_MCP_Elementor_Adapter::update_site_option( 'blogname', 'New text' );

		$this->assertSame( 'New text', $this->kit()['site_name'] );
		$this->assertSame( 1, IATO_Test_WP::hook_count( 'update_option_blogname' ) );
	}

	/** Missing or trashed: Elementor's copy throws whoever asks, and there is nothing to copy to. */
	#[DataProvider( 'can_edit' )]
	public function test_no_usable_kit( bool $can_edit ): void {
		IATO_Test_Abilities_State::$caps['edit_post'] = $can_edit;
		$this->manager->kit_id = 0;

		$r = $this->call( [ 'title' => 'New text' ] );

		$this->assertSame( 'New text', $this->option( 'blogname' ) );
		$this->assertSame( 'blogname', $r['change_receipt']['field'] );
		$this->assertArrayNotHasKey( 'warnings', $r );
		$this->assertSame( 'Old name', $this->kit()['site_name'] );
		$this->assertSame( 1, IATO_Test_WP::hook_count( 'update_option_blogname' ) );
	}

	/** @return array<string,array{0:bool}> */
	public static function can_edit(): array {
		return [ 'user who could edit a kit' => [ true ], 'user who could not' => [ false ] ];
	}

	public function test_elementor_not_running_is_a_plain_write(): void {
		\Elementor\Plugin::$instance->kits_manager = null;
		IATO_Test_WP::reset_hooks();

		$this->assertNull( IATO_MCP_Elementor_Adapter::update_site_option( 'blogname', 'New text' ) );

		$this->assertSame( 'New text', $this->option( 'blogname' ) );
		$this->assertSame( 'Old name', $this->kit()['site_name'] );
	}

	public function test_a_throw_while_finding_the_kit_is_a_plain_write(): void {
		$this->manager->get_active_kit_throws = true;
		IATO_Test_WP::reset_hooks();

		$this->assertNull( IATO_MCP_Elementor_Adapter::update_site_option( 'blogname', 'New text' ) );

		$this->assertSame( 'New text', $this->option( 'blogname' ) );
	}

	public function test_a_value_that_does_not_change_copies_nothing(): void {
		IATO_Test_WP::$meta[ self::KIT ][ self::META ]['site_name'] = 'Something else';

		IATO_MCP_Elementor_Adapter::update_site_option( 'blogname', 'Old name' );

		$this->assertSame( 'Something else', $this->kit()['site_name'] );
	}

	public function test_an_option_elementor_does_not_copy_is_left_alone(): void {
		$called = 0;
		IATO_Test_WP::add_hook(
			'update_option_show_on_front',
			[ new class( $called ) extends Manager {
				public function __construct( private int &$count ) {
					parent::__construct( 0 );
				}
				public function note(): void {
					++$this->count;
				}
			}, 'note' ],
			10,
			2
		);

		IATO_MCP_Elementor_Adapter::update_site_option( 'show_on_front', 'page' );

		$this->assertSame( 'page', $this->option( 'show_on_front' ) );
		$this->assertSame( 1, $called );
	}

	public function test_a_failed_copy_is_a_warning_and_the_receipt_stands(): void {
		IATO_Test_WP::$meta_write_fails = true;

		$r = $this->call( [ 'title' => 'New text' ] );

		$this->assertSame( 'New text', $this->option( 'blogname' ) );
		$this->assertSame( 'blogname', $r['change_receipt']['field'] );
		$this->assertCount( 1, $r['warnings'] );
		$this->assertStringContainsString( 'publishing Site Settings would put it back', $r['warnings'][0] );
	}

	public function test_a_dry_run_writes_nothing(): void {
		$r = $this->call( [ 'title' => 'New text', 'dry_run' => true ] );

		$this->assertTrue( $r['dry_run'] );
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
		$this->assertSame( 'Old name', $this->kit()['site_name'] );
	}

	public function test_both_at_once_get_a_receipt_each(): void {
		$r = $this->call( [ 'title' => 'New text', 'tagline' => 'New tagline' ] );

		$this->assertCount( 2, $r['change_receipts'] );
		$this->assertSame( 'New text', $this->kit()['site_name'] );
		$this->assertSame( 'New tagline', $this->kit()['site_description'] );
	}

	public function test_rollback_restores_the_option_and_the_copy(): void {
		$receipt = $this->call( [ 'title' => 'New text' ] )['change_receipt'];

		$result = IATO_MCP_Rollback::execute(
			[
				'change_id'    => $receipt['change_id'],
				'post_id'      => null,
				'target_type'  => 'site_setting',
				'field'        => 'blogname',
				'before_value' => $receipt['before_value'],
				'after_value'  => $receipt['after_value'],
				'applied_at'   => '2026-11-03 10:00:00',
			]
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'match', $result['conflict_check']['status'] );
		$this->assertSame( 'Old name', $this->option( 'blogname' ) );
		$this->assertSame( 'Old name', $this->kit()['site_name'] );
		$this->assertSame( 1, IATO_Test_WP::hook_count( 'update_option_blogname' ) );
	}
}

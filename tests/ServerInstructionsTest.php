<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.19.3: every update_post field the server instructions name is in update_post's inputSchema.
 *
 * The Elementor section lists the fields update_post may change on Elementor posts. That list
 * still read "status, title, excerpt" after 1.19.0 added date, date_gmt, allow_future and author,
 * so clients were told not to use them. The instructions are built here by the plugin's own
 * functions, taken from iato-mcp.php, once for each combination of page builders, because each
 * builder's section appears only when that builder is active. Each case runs in a fresh process,
 * because the builders are detected from constants, functions and classes.
 *
 * When this fails: name the field as the schema does, or add it to update_post's inputSchema.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ServerInstructionsTest extends TestCase {

	/** The sentence that introduces a list of update_post's fields. */
	private const LIST_SENTENCE = '/update_post is only permitted for:\s*([^;.\n]+)/';

	/** @return array<string, array{list<string>}> */
	public static function builders(): array {
		return [
			'WordPress alone' => [ [] ],
			'Elementor'       => [ [ 'elementor' ] ],
			'Divi'            => [ [ 'divi' ] ],
			'WPBakery'        => [ [ 'wpbakery' ] ],
			'Beaver Builder'  => [ [ 'beaver' ] ],
			'all of them'     => [ [ 'elementor', 'divi', 'wpbakery', 'beaver' ] ],
		];
	}

	/** Make the builders look active, then build the instructions with the plugin's own code. */
	private static function instructions( array $builders ): string {
		foreach ( $builders as $builder ) {
			match ( $builder ) {
				'elementor' => define( 'ELEMENTOR_VERSION', '4.3.6' ),
				'divi'      => eval( 'function et_setup_theme(): void {}' ),
				'wpbakery'  => define( 'WPB_VC_VERSION', '8.0' ),
				'beaver'    => eval( 'class FLBuilder {}' ),
			};
		}
		// The main file cannot be loaded in a unit test, so its two functions are taken from it as they are.
		$source = (string) file_get_contents( IATO_MCP_DIR . 'iato-mcp.php' );
		foreach ( [ 'iato_mcp_detect_active_builders', 'iato_mcp_build_server_instructions' ] as $function ) {
			self::assertSame( 1, preg_match( '/^function ' . $function . '\(.*?^}$/ms', $source, $m ), "{$function} is in iato-mcp.php" );
			eval( $m[0] );
		}
		return iato_mcp_build_server_instructions();
	}

	/** @return list<string> update_post's inputSchema properties, as registered. */
	private static function update_post_properties(): array {
		require_once IATO_MCP_DIR . 'includes/tools/wp/tool-posts.php';
		$entry = IATO_MCP_Server::get_catalog_entry( 'update_post' );
		self::assertNotNull( $entry, 'update_post is registered' );
		return array_map( 'strval', array_keys( (array) ( $entry['definition']['inputSchema']['properties'] ?? [] ) ) );
	}

	#[DataProvider( 'builders' )]
	public function test_every_update_post_field_the_instructions_name_is_in_its_schema( array $builders ): void {
		$text = self::instructions( $builders );
		preg_match_all( self::LIST_SENTENCE, $text, $lists );
		if ( in_array( 'elementor', $builders, true ) ) {
			$this->assertNotEmpty( $lists[1], 'the Elementor section lists the fields update_post may change' );
		}

		$properties = self::update_post_properties();
		foreach ( $lists[1] as $list ) {
			$fields = preg_split( '/\s*,\s*|\s+and\s+/', trim( $list ) );
			$this->assertNotEmpty( $fields );
			foreach ( $fields as $field ) {
				$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $field, "'{$list}' lists field names only" );
				$this->assertContains( $field, $properties, "{$field} is named in the server instructions as an update_post field" );
			}
		}
	}

	public function test_the_list_is_read_as_a_client_receives_it(): void {
		$text = self::instructions( [ 'elementor' ] );
		$this->assertStringContainsString(
			'update_post is only permitted for: status, title, excerpt, slug, parent, menu_order, date, date_gmt, allow_future and author; content edits go through the Elementor tools.',
			$text
		);
		preg_match_all( self::LIST_SENTENCE, $text, $lists );
		$this->assertSame( [ 'status, title, excerpt, slug, parent, menu_order, date, date_gmt, allow_future and author' ], $lists[1] );
	}
}

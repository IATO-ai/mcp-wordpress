<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 1.19.1: each tool's inputSchema lists exactly the arguments its handler reads.
 *
 * Clients only send what tools/list advertises, so an argument the handler reads but
 * the schema leaves out is one no client knows about, and one the schema lists but the
 * handler never reads is silently ignored. Every tool file is loaded, the schema is
 * taken from what the server registered, and the handler's reads come from its source
 * (tests/tool-arg-reader.php). A fresh process, because loading every tool file
 * registers every tool.
 *
 * When this fails: add the property to the schema, read it in the handler, or, for a
 * name kept only for older callers, add it to ALIASES. When the reader cannot follow
 * a new read, make the read plain ($args['key']) or explain it in UNDERSTOOD.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ToolSchemaParityTest extends TestCase {

	/** Names a handler also accepts for an advertised property, kept for IATO's Autopilot: alias => property. */
	private const ALIASES = [
		'update_post'     => [ 'post_id' => 'id' ],
		'update_seo_data' => [ 'post_id' => 'id', 'seo_title' => 'title', 'meta_description' => 'description' ],
		'update_alt_text' => [ 'attachment_id' => 'id', 'alt_text' => 'alt' ],
	];

	/** What the reader reports it cannot follow, each checked by hand: "file:function | what" => why it adds no argument. */
	private const UNDERSTOOD = [
		'class-elementor-adapter.php:IATO_MCP_Elementor_Concurrency::canonicalize | foreach over $data' => 'Hashes every argument into the idempotency key and reads none by name.',
		"class-elementor-adapter.php:IATO_MCP_Elementor_Concurrency::canonicalize | \$data in 'return \$data ;'" => 'Returns that canonical form to be hashed.',
		'tool-users.php:handler | $field loops over $changes' => 'The fields IATO_MCP_User_Profile::plan() accepted, which it read by name from FIELDS.',
	];

	/** @var array<string, array{definition: array, handler: callable}>|null */
	private static ?array $catalog = null;

	/** @return array<string, array{definition: array, handler: callable}> */
	private static function catalog(): array {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}
		require_once __DIR__ . '/tool-arg-reader.php';
		// The plugin's classes the tests have not already loaded or stubbed. A class whose
		// parent is not here (the HTML token scanner extends WordPress's tag processor) is
		// left out: no handler hands it $args.
		foreach ( (array) glob( IATO_MCP_DIR . 'includes/class-*.php' ) as $file ) {
			$source = (string) file_get_contents( (string) $file );
			preg_match_all( '/^(?:final |abstract )?class (\w+)(?: extends (\w+))?/m', $source, $m, PREG_SET_ORDER );
			$declared = array_filter( $m, static fn( array $c ): bool => class_exists( $c[1], false ) );
			$orphaned = array_filter( $m, static fn( array $c ): bool => isset( $c[2] ) && ! class_exists( $c[2] ) );
			if ( [] === $declared && [] === $orphaned ) {
				require_once (string) $file;
			}
		}
		foreach ( self::tool_files() as $file ) {
			require_once $file;
		}
		self::$catalog = ( new ReflectionProperty( IATO_MCP_Server::class, 'catalog' ) )->getValue();
		return self::$catalog;
	}

	/** @return list<string> */
	private static function tool_files(): array {
		return array_merge( (array) glob( IATO_MCP_DIR . 'includes/tools/wp/*.php' ), (array) glob( IATO_MCP_DIR . 'includes/tools/bridge/*.php' ) );
	}

	/** @return array<string, array{schema: list<string>, read: list<string>, unresolved: array}> */
	private static function report(): array {
		$out = [];
		foreach ( self::catalog() as $name => $entry ) {
			$properties = $entry['definition']['inputSchema']['properties'] ?? [];
			$reader     = IATO_Test_Arg_Reader::of( $entry['handler'], IATO_MCP_DIR . 'includes/' );
			$out[ $name ] = [
				'schema'     => array_map( 'strval', array_keys( (array) $properties ) ),
				'read'       => array_map( 'strval', array_keys( $reader->keys ) ),
				'unresolved' => $reader->unresolved,
			];
		}
		return $out;
	}

	public function test_every_registered_tool_is_checked(): void {
		$names = [];
		foreach ( self::tool_files() as $file ) {
			preg_match_all( "/register_tool\(\s*'([a-z_]+)'/", (string) file_get_contents( $file ), $m );
			$names = array_merge( $names, $m[1] );
		}
		$this->assertNotEmpty( $names );
		$this->assertSame( [], array_values( array_diff( $names, array_keys( self::catalog() ) ) ), 'every tool file loaded and registered its tools' );
		$this->assertGreaterThan( 60, count( self::catalog() ) );
	}

	public function test_every_argument_a_handler_reads_is_advertised(): void {
		$missing = [];
		foreach ( self::report() as $name => $tool ) {
			$extra = array_diff( $tool['read'], $tool['schema'], array_keys( self::ALIASES[ $name ] ?? [] ) );
			if ( [] !== $extra ) {
				$missing[ $name ] = array_values( $extra );
			}
		}
		$this->assertSame( [], $missing, 'read by the handler but missing from inputSchema' );
	}

	public function test_every_advertised_argument_is_read(): void {
		$unread = [];
		foreach ( self::report() as $name => $tool ) {
			$extra = array_diff( $tool['schema'], $tool['read'] );
			if ( [] !== $extra ) {
				$unread[ $name ] = array_values( $extra );
			}
		}
		$this->assertSame( [], $unread, 'in inputSchema but never read by the handler' );
	}

	public function test_aliases_are_read_and_stand_for_an_advertised_property(): void {
		$report = self::report();
		foreach ( self::ALIASES as $name => $aliases ) {
			$this->assertArrayHasKey( $name, $report );
			foreach ( $aliases as $alias => $property ) {
				$this->assertContains( $alias, $report[ $name ]['read'], "{$name} still reads {$alias}" );
				$this->assertNotContains( $alias, $report[ $name ]['schema'], "{$name}: {$alias} is advertised, so it is not an alias" );
				$this->assertContains( $property, $report[ $name ]['schema'], "{$name}: {$alias} stands for the advertised {$property}" );
			}
		}
	}

	public function test_every_read_the_reader_cannot_follow_is_explained(): void {
		$seen  = [];
		$unexplained = [];
		foreach ( self::report() as $name => $tool ) {
			foreach ( $tool['unresolved'] as $key => $entry ) {
				$seen[ $key ] = true;
				if ( ! isset( self::UNDERSTOOD[ $key ] ) ) {
					$unexplained[] = "{$name}: {$entry['where']} line {$entry['line']}: {$entry['what']}";
				}
			}
		}
		$this->assertSame( [], $unexplained, 'reads the reader cannot follow: make them plain or explain them in UNDERSTOOD' );
		$this->assertSame( [], array_values( array_diff( array_keys( self::UNDERSTOOD ), array_keys( $seen ) ) ), 'UNDERSTOOD entries that no longer occur' );
	}
}

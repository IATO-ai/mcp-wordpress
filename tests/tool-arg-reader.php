<?php
declare( strict_types=1 );

/**
 * Reads, from source, the argument keys a tool handler uses, for
 * tests/ToolSchemaParityTest.php.
 *
 * A key counts when the handler, or plugin code it hands $args to, reads it as
 * $args['key'] or array_key_exists( 'key', $args ). $args[ $x ] counts when $x comes
 * from the innermost foreach around the read over a literal list, a class constant or
 * array_keys() of one, or is a literal the caller passed for that parameter. $args is
 * followed into the plugin's own functions and static methods under the parameter
 * name it arrives as there, and through plain copies ($copy = $args). PHP's own
 * functions and WordPress's only look at the array and are not followed.
 *
 * Anything else is reported in $unresolved rather than guessed, so a new way of
 * reading arguments fails the test until it is understood. Only top-level keys are
 * read: nested object properties (create_media's source) are not compared.
 */
final class IATO_Test_Arg_Reader {

	/** @var array<string, PhpToken[]> */
	private static array $files = [];

	/** @var array<string, array<string, true>> key => the functions that read it */
	public array $keys = [];

	/** @var array<string, array{where: string, what: string, line: int}> */
	public array $unresolved = [];

	/** @var array<string, true> */
	private array $visited = [];

	private function __construct( private string $root ) {}

	public static function of( callable $handler, string $root ): self {
		$reader = new self( $root );
		$fn     = is_array( $handler ) ? new ReflectionMethod( $handler[0], $handler[1] ) : new ReflectionFunction( Closure::fromCallable( $handler ) );
		$reader->read( $fn, 0, [] );
		return $reader;
	}

	/**
	 * @param array<int, string> $literals String literals the caller passed, by position.
	 */
	private function read( ReflectionFunctionAbstract $fn, int $position, array $literals ): void {
		$params = $fn->getParameters();
		if ( ! isset( $params[ $position ] ) ) {
			return;
		}
		$id = $this->name_of( $fn ) . '@' . $fn->getStartLine() . '#' . $position . json_encode( $literals );
		if ( isset( $this->visited[ $id ] ) ) {
			return;
		}
		$this->visited[ $id ] = true;

		$bound = [];
		foreach ( $literals as $at => $literal ) {
			if ( isset( $params[ $at ] ) ) {
				$bound[ '$' . $params[ $at ]->getName() ] = $literal;
			}
		}
		$class = $fn instanceof ReflectionMethod ? $fn->class : null;
		$where = basename( (string) $fn->getFileName() ) . ':' . $this->name_of( $fn );
		$this->scan( self::body( $fn ), [ '$' . $params[ $position ]->getName() => true ], $bound, $class, $where );
	}

	private function name_of( ReflectionFunctionAbstract $fn ): string {
		if ( $fn instanceof ReflectionMethod ) {
			return $fn->class . '::' . $fn->getName();
		}
		return $fn->isClosure() ? 'handler' : $fn->getName();
	}

	/** The significant tokens between a function's braces. */
	private static function body( ReflectionFunctionAbstract $fn ): array {
		$file   = (string) $fn->getFileName();
		$tokens = self::$files[ $file ] ??= array_values( array_filter(
			PhpToken::tokenize( (string) file_get_contents( $file ) ),
			static fn( PhpToken $t ): bool => ! $t->is( [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG ] )
		) );
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $tokens[ $i ]->line >= $fn->getStartLine() && $tokens[ $i ]->is( [ T_FUNCTION, T_FN ] ) ) {
				break;
			}
		}
		// Past the parameter list and the return type to the first brace at depth 0.
		$depth = 0;
		for ( ; $i < $count; $i++ ) {
			$text = $tokens[ $i ]->text;
			if ( '(' === $text ) {
				$depth++;
			} elseif ( ')' === $text ) {
				$depth--;
			} elseif ( '{' === $text && 0 === $depth ) {
				break;
			}
		}
		$start = $i + 1;
		$end   = self::closing( $tokens, $i );
		return array_slice( $tokens, $start, $end - $start );
	}

	/** The index of the brace that closes the one at $open. */
	private static function closing( array $t, int $open ): int {
		$depth = 0;
		$count = count( $t );
		for ( $i = $open; $i < $count; $i++ ) {
			if ( '{' === $t[ $i ]->text || $t[ $i ]->is( [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ] ) ) {
				$depth++;
			} elseif ( '}' === $t[ $i ]->text && 0 === --$depth ) {
				return $i;
			}
		}
		return $count;
	}

	/** The index of the bracket that closes the one at $open: ( or [. */
	private static function matching( array $t, int $open ): int {
		$depth = 0;
		$count = count( $t );
		for ( $i = $open; $i < $count; $i++ ) {
			$text = $t[ $i ]->text;
			if ( '(' === $text || '[' === $text ) {
				$depth++;
			} elseif ( ( ')' === $text || ']' === $text ) && 0 === --$depth ) {
				return $i;
			}
		}
		return $count;
	}

	/**
	 * @param array<string, true>   $tracked Variables holding the arguments.
	 * @param array<string, string> $bound   Parameters the caller gave a literal.
	 */
	private function scan( array $t, array $tracked, array $bound, ?string $class, string $where ): void {
		$n = count( $t );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( ! $t[ $i ]->is( T_VARIABLE ) || ! isset( $tracked[ $t[ $i ]->text ] ) ) {
				continue;
			}
			$var  = $t[ $i ]->text;
			$next = $t[ $i + 1 ]->text ?? '';
			$prev = $t[ $i - 1 ]->text ?? '';

			if ( '[' === $next ) {
				$inner = $t[ $i + 2 ] ?? null;
				$after = $t[ $i + 3 ]->text ?? '';
				if ( $inner && $inner->is( T_CONSTANT_ENCAPSED_STRING ) && ']' === $after ) {
					$this->add( self::unquote( $inner->text ), $where );
				} elseif ( $inner && $inner->is( T_VARIABLE ) && ']' === $after ) {
					$this->dynamic( $t, $i, $inner->text, $bound, $class, $where );
				} else {
					$this->unresolve( $where, "{$var}[ an expression ]", $t[ $i ]->line );
				}
				continue;
			}

			// $copy = $args;
			if ( '=' === $prev && ';' === $next && ( $t[ $i - 2 ] ?? null )?->is( T_VARIABLE ) ) {
				$tracked[ $t[ $i - 2 ]->text ] = true;
				continue;
			}

			if ( '(' === $prev && ( $t[ $i - 2 ] ?? null )?->is( T_FOREACH ) ) {
				$this->unresolve( $where, "foreach over {$var}", $t[ $i ]->line );
				continue;
			}

			if ( ( '(' === $prev || ',' === $prev ) && ( ',' === $next || ')' === $next ) ) {
				$this->argument( $t, $i, $class, $where );
				continue;
			}

			$this->unresolve( $where, "{$var} in '{$prev} {$var} {$next}'", $t[ $i ]->line );
		}
	}

	/** $args passed whole as an argument: read it in the plugin code it goes to. */
	private function argument( array $t, int $i, ?string $class, string $where ): void {
		$depth    = 0;
		$position = 0;
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$text = $t[ $j ]->text;
			if ( ')' === $text || ']' === $text ) {
				$depth++;
			} elseif ( '(' === $text || '[' === $text ) {
				if ( 0 === $depth ) {
					break;
				}
				$depth--;
			} elseif ( ',' === $text && 0 === $depth ) {
				$position++;
			}
		}
		if ( $j < 1 || '(' !== $t[ $j ]->text ) {
			$this->unresolve( $where, "{$t[ $i ]->text} inside an array literal", $t[ $i ]->line );
			return;
		}
		$name = $t[ $j - 1 ];
		$via  = $t[ $j - 2 ]->text ?? '';
		if ( $name->is( [ T_ISSET, T_EMPTY ] ) ) {
			return;
		}
		if ( ! $name->is( T_STRING ) ) {
			$this->unresolve( $where, "{$t[ $i ]->text} passed to an expression", $t[ $i ]->line );
			return;
		}
		$literals = self::literal_arguments( $t, $j );

		if ( '::' === $via ) {
			$owner = $t[ $j - 3 ]->text ?? '';
			$owner = in_array( $owner, [ 'self', 'static' ], true ) ? (string) $class : ltrim( $owner, '\\' );
			if ( ! method_exists( $owner, $name->text ) ) {
				$this->unresolve( $where, "{$owner}::{$name->text}() is not loaded", $name->line );
				return;
			}
			$this->follow( new ReflectionMethod( $owner, $name->text ), $position, $literals, $where );
			return;
		}
		if ( '->' === $via || '?->' === $via ) {
			if ( '$this' === ( $t[ $j - 3 ]->text ?? '' ) && $class && method_exists( $class, $name->text ) ) {
				$this->follow( new ReflectionMethod( $class, $name->text ), $position, $literals, $where );
				return;
			}
			$this->unresolve( $where, "{$t[ $i ]->text} passed to ->{$name->text}() on an object", $name->line );
			return;
		}

		if ( 'array_key_exists' === strtolower( $name->text ) && 1 === $position ) {
			$key = $t[ $j + 1 ];
			if ( $key->is( T_CONSTANT_ENCAPSED_STRING ) && ',' === ( $t[ $j + 2 ]->text ?? '' ) ) {
				$this->add( self::unquote( $key->text ), $where );
			} elseif ( $key->is( T_VARIABLE ) && ',' === ( $t[ $j + 2 ]->text ?? '' ) ) {
				$this->dynamic( $t, $j, $key->text, [], $class, $where );
			} else {
				$this->unresolve( $where, 'array_key_exists( an expression, ... )', $name->line );
			}
			return;
		}
		if ( ! function_exists( $name->text ) ) {
			$this->unresolve( $where, "{$name->text}() is not loaded", $name->line );
			return;
		}
		$this->follow( new ReflectionFunction( $name->text ), $position, $literals, $where );
	}

	/** @return array<int, string> The call's arguments that are a single string literal, by position. */
	private static function literal_arguments( array $t, int $open ): array {
		$close    = self::matching( $t, $open );
		$out      = [];
		$position = 0;
		$start    = $open + 1;
		$depth    = 0;
		for ( $k = $open + 1; $k <= $close; $k++ ) {
			$text = $t[ $k ]->text;
			if ( $k < $close && ( '(' === $text || '[' === $text ) ) {
				$depth++;
			} elseif ( $k < $close && ( ')' === $text || ']' === $text ) ) {
				$depth--;
			} elseif ( $k === $close || ( ',' === $text && 0 === $depth ) ) {
				if ( 1 === $k - $start && $t[ $start ]->is( T_CONSTANT_ENCAPSED_STRING ) ) {
					$out[ $position ] = self::unquote( $t[ $start ]->text );
				}
				$position++;
				$start = $k + 1;
			}
		}
		return $out;
	}

	/** Into the plugin's own code. PHP's and WordPress's functions only look at the array. */
	private function follow( ReflectionFunctionAbstract $fn, int $position, array $literals, string $where ): void {
		if ( $fn->isInternal() ) {
			return;
		}
		$plugin = $fn instanceof ReflectionMethod ? str_starts_with( $fn->class, 'IATO_MCP_' ) : str_starts_with( $fn->getName(), 'iato_mcp_' );
		if ( ! str_starts_with( (string) $fn->getFileName(), $this->root ) ) {
			if ( $plugin ) {
				$this->unresolve( $where, $this->name_of( $fn ) . '() is a test stub here, not the plugin code', $fn->getStartLine() );
			}
			return;
		}
		$this->read( $fn, $position, $literals );
	}

	/**
	 * $args[ $x ] or array_key_exists( $x, $args ): $x from the innermost foreach
	 * around position $at that binds it, or a literal the caller passed.
	 */
	private function dynamic( array $t, int $at, string $var, array $bound, ?string $class, string $where ): void {
		foreach ( self::loops_around( $t, $at ) as [ $f, $as ] ) {
			$key   = ( $t[ $as + 1 ]->text ?? '' ) === $var && '=>' === ( $t[ $as + 2 ]->text ?? '' );
			$value = ( ( $t[ $as + 1 ]->text ?? '' ) === $var && ')' === ( $t[ $as + 2 ]->text ?? '' ) )
				|| ( '=>' === ( $t[ $as + 2 ]->text ?? '' ) && ( $t[ $as + 3 ]->text ?? '' ) === $var );
			if ( ! $key && ! $value ) {
				continue;
			}
			$listed = self::listed( array_slice( $t, $f + 2, $as - $f - 2 ), $key, $class );
			if ( null === $listed ) {
				$this->unresolve( $where, "{$var} loops over " . implode( ' ', array_map( static fn( PhpToken $x ): string => $x->text, array_slice( $t, $f + 2, $as - $f - 2 ) ) ), $t[ $f ]->line );
				return;
			}
			foreach ( $listed as $listed_key ) {
				$this->add( $listed_key, $where );
			}
			return;
		}
		if ( isset( $bound[ $var ] ) ) {
			$this->add( $bound[ $var ], $where );
			return;
		}
		$this->unresolve( $where, "\$args[ {$var} ] with {$var} from no foreach or literal argument", $t[ $at ]->line );
	}

	/** @return list<array{int, int}> The foreach loops whose body holds $at, innermost first: [foreach index, as index]. */
	private static function loops_around( array $t, int $at ): array {
		$loops = [];
		for ( $f = $at - 1; $f >= 0; $f-- ) {
			if ( ! $t[ $f ]->is( T_FOREACH ) ) {
				continue;
			}
			$header_end = self::matching( $t, $f + 1 );
			if ( $header_end >= $at ) {
				continue;
			}
			if ( '{' === ( $t[ $header_end + 1 ]->text ?? '' ) ) {
				$body_end = self::closing( $t, $header_end + 1 );
			} else {
				for ( $body_end = $header_end + 1; isset( $t[ $body_end ] ) && ';' !== $t[ $body_end ]->text; $body_end++ );
			}
			if ( $at > $body_end ) {
				continue;
			}
			$depth = 0;
			for ( $as = $f + 2; $as < $header_end; $as++ ) {
				$text = $t[ $as ]->text;
				if ( '(' === $text || '[' === $text ) {
					$depth++;
				} elseif ( ')' === $text || ']' === $text ) {
					$depth--;
				} elseif ( 0 === $depth && $t[ $as ]->is( T_AS ) ) {
					break;
				}
			}
			$loops[] = [ $f, $as ];
		}
		return $loops;
	}

	/** The keys or values of a literal array, a class constant, or array_keys() of one. */
	private static function listed( array $expr, bool $keys, ?string $class ): ?array {
		$texts = array_map( static fn( PhpToken $t ): string => $t->text, $expr );
		if ( 'array_keys' === ( $texts[0] ?? '' ) && '(' === ( $texts[1] ?? '' ) && ')' === end( $texts ) ) {
			return $keys ? null : self::listed( array_slice( $expr, 2, -1 ), true, $class );
		}
		if ( 3 === count( $expr ) && '::' === $texts[1] && $expr[2]->is( T_STRING ) ) {
			$owner = in_array( $texts[0], [ 'self', 'static' ], true ) ? (string) $class : ltrim( $texts[0], '\\' );
			$value = defined( $owner . '::' . $texts[2] ) ? constant( $owner . '::' . $texts[2] ) : null;
			return is_array( $value ) ? array_map( 'strval', $keys ? array_keys( $value ) : array_values( $value ) ) : null;
		}
		if ( '[' !== ( $texts[0] ?? '' ) || ']' !== end( $texts ) ) {
			return null;
		}
		$out   = [];
		$depth = 0;
		$start = 1;
		$last  = count( $expr ) - 1;
		for ( $i = 1; $i <= $last; $i++ ) {
			$text = $texts[ $i ];
			if ( $i < $last && ( '(' === $text || '[' === $text ) ) {
				$depth++;
			} elseif ( $i < $last && ( ')' === $text || ']' === $text ) ) {
				$depth--;
			} elseif ( $i === $last || ( ',' === $text && 0 === $depth ) ) {
				$element = array_slice( $expr, $start, $i - $start );
				$start   = $i + 1;
				if ( [] === $element ) {
					continue;
				}
				$arrow = '=>' === ( $element[1]->text ?? '' );
				if ( ! $element[0]->is( T_CONSTANT_ENCAPSED_STRING ) || ( $keys ? ! $arrow : ( $arrow || 1 !== count( $element ) ) ) ) {
					return null;
				}
				$out[] = self::unquote( $element[0]->text );
			}
		}
		return $out;
	}

	private function add( string $key, string $where ): void {
		$this->keys[ $key ][ $where ] = true;
	}

	private function unresolve( string $where, string $what, int $line ): void {
		$this->unresolved[ $where . ' | ' . $what ] = [ 'where' => $where, 'what' => $what, 'line' => $line ];
	}

	private static function unquote( string $literal ): string {
		return stripcslashes( substr( $literal, 1, -1 ) );
	}
}

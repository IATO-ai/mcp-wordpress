<?php
/**
 * 1.21.0 must not narrow the markup rule: whatever 1.20.0 kept, it keeps. Run by
 * sanitizer-compat-live.sh through `wp eval-file`, which first writes 1.20.0's
 * sanitizer, with its classes renamed, into a folder of its own under
 * tests/wp-env/.compat-1.20.0/ (gitignored) and passes its name as the argument.
 *
 * Every fixture goes through both versions, three ways:
 *   plain   the markup rule, as for a value with the inline setting off;
 *   inline  an HTML widget's value with the opt-in applying (filter(), falling back to
 *           the markup rule as IATO_MCP_Elementor_Sanitizer does);
 *   input   both again, with a filter that allows <input> as some plugins do (one on a
 *           live site let radio inputs through with type, name, value and checked).
 * Asserted for each: every tag, attribute and style declaration 1.20.0 kept, 1.21.0
 * keeps at least as often; and a value 1.20.0 stored unchanged, 1.21.0 stores unchanged.
 */

defined( 'ABSPATH' ) || exit;

// The folder the shell script made for this run, so runs on several sites at once do not
// share (and delete) one copy.
$run = isset( $args[0] ) && 1 === preg_match( '/^run-[0-9]+-[0-9]+$/', (string) $args[0] ) ? (string) $args[0] : '';
if ( '' === $run ) {
	echo "Run this through sanitizer-compat-live.sh.\n";
	exit( 1 );
}
$old_dir = WP_PLUGIN_DIR . '/mcp-wordpress/tests/wp-env/.compat-1.20.0/' . $run;
require_once $old_dir . '/class-inline-markup.php';
require_once $old_dir . '/class-elementor-sanitizer.php';

$fixtures = [
	'headings and text'   => '<h2 class="title" id="t">Title</h2><p>Text with <strong>bold</strong>, <em>em</em>, <a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener">a link</a> and <code>code</code>.</p>',
	'lists'               => '<ul class="l"><li>One</li><li data-x="1">Two</li></ul><ol start="3"><li>Three</li></ol>',
	'image and figure'    => '<figure class="wp-block-image"><img src="https://example.com/a.png" alt="A" width="10" height="10" loading="lazy" srcset="a.png 1x, b.png 2x" sizes="10px"><figcaption>Cap</figcaption></figure>',
	'table'               => '<table><thead><tr><th scope="col">A</th></tr></thead><tbody><tr><td colspan="2" style="text-align:center">1</td></tr></tbody></table>',
	'details'             => '<details open><summary>More</summary><p>Hidden</p></details>',
	'buttons'             => '<button type="button" class="b" aria-expanded="false" aria-controls="m" disabled>Menu</button><button type="submit" name="go" value="1">Go</button>',
	'aria already allowed' => '<div role="region" aria-label="R" aria-labelledby="t" aria-describedby="d" aria-hidden="true" aria-live="polite" aria-current="page" hidden>x</div>',
	'inline styles'       => '<div style="color:#123456;background:linear-gradient(red,blue);margin:0 auto;padding:1rem;display:grid;grid-template-columns:1fr 1fr;gap:4px;position:relative;top:1px;z-index:2">x</div><span style="font-family:\'Inter\',sans-serif;font-weight:700">y</span>',
	'styles WordPress drops' => '<div style="color:rgb(1,2,3);mix-blend-mode:screen;behavior:url(x)">x</div>',
	'data attributes'     => '<div data-id="1" data-settings=\'{"a":1}\' class="elementor-x">x</div>',
	'media'               => '<video src="https://example.com/v.mp4" controls muted loop playsinline poster="p.png"></video><audio src="a.mp3" controls></audio>',
	'iframe'              => '<iframe src="https://www.youtube.com/embed/x" width="560" height="315" allowfullscreen></iframe>',
	'old svg subset'      => '<svg class="i" viewBox="0 0 24 24" width="24" height="24" role="img" aria-label="Icon"><title>Icon</title><g fill="none" stroke="currentColor"><path d="M0 0L24 24"/><rect x="1" y="1" width="2" height="2" rx="1"/><circle cx="5" cy="5" r="1"/><line x1="0" y1="0" x2="1" y2="1"/><polyline points="0,0 1,1"/><polygon points="0,0 1,1 2,0"/></g></svg>',
	'style block'         => '<style>.a > .b{color:red}@media (min-width:1px){.c{display:block}}@keyframes k{from{opacity:0}to{opacity:1}}</style><div class="a"><span class="b">x</span></div>',
	'style with url'      => '<style>.a{background:url(/img/a.png)}.b{background:url(https://evil.example/b.png)}</style><p>x</p>',
	'script'              => '<p>a</p><script>alert(1)</script><p>b</p>',
	'event handler'       => '<img src="x.png" onerror="alert(1)"><a href="javascript:alert(1)">x</a>',
	'radio inputs'        => '<label><input class="r" type="radio" name="plan" id="p1" value="a" checked> A</label><label><input class="r" type="radio" name="plan" id="p2" value="b"> B</label>',
	'text input'          => "<input type='text' value='x' readonly='readonly' aria-hidden='true'>",
	'spec svg'            => "<svg width='16' height='16' viewBox='0 0 16 16' role='img' aria-hidden='true'><title>t</title><defs><linearGradient id='g1'><stop offset='0' stop-color='#fff'></stop></linearGradient><marker id='m1' orient='auto' markerWidth='5' markerHeight='5'><path d='M0 0L4 2L0 4Z'></path></marker></defs><g transform='rotate(-14 8 8)'><path d='M3 8h10' fill='none' stroke='currentColor' stroke-width='1.75' marker-end='url(#m1)'></path><circle cx='8' cy='8' r='5'></circle><rect x='1' y='5' width='8' height='6'></rect><ellipse cx='8' cy='8' rx='6' ry='4'></ellipse><line x1='0' y1='0' x2='16' y2='16'></line><text x='2' y='12' text-anchor='middle' font-size='8'>A</text><use href='#g1'></use></g></svg><button type='button' aria-pressed='true' disabled='disabled'>B</button><input type='text' value='x' readonly='readonly' aria-hidden='true'>",
	'spec style'          => "<style>\n@property --p{syntax:'<number>';inherits:true;initial-value:84}\n.a>b{color:red}\n#x:checked~.y [for=x]{content:'x'}\n@media (min-width:1440px){.z{display:block}}\n@keyframes k{from{opacity:0}to{opacity:1}}\n</style>",
	'spec style without the descriptor' => "<style>\n.a>b{color:red}\n#x:checked~.y [for=x]{content:'x'}\n@media (min-width:1440px){.z{display:block}}\n@keyframes k{from{opacity:0}to{opacity:1}}\n</style>",
	'form'                => '<form action="https://evil.example/" method="post"><input type="password" name="p"><select name="s"><option value="1">1</option></select><textarea name="t" rows="2" readonly>x</textarea></form>',
	'entities'            => '<p>A &amp; B &lt;c&gt; &quot;d&quot; &#8217; &nbsp;</p>',
	'comments'            => '<!-- note --><p>x</p>',
	'an unclosed tag'     => '<p>x<div class="a">y',
];

$modes = [
	'plain'  => [
		'old' => static fn( string $v ): string => IATO_MCP_Elementor_Sanitizer_120::clean_markup( $v ),
		'new' => static fn( string $v ): string => IATO_MCP_Elementor_Sanitizer::clean_markup( $v ),
	],
	'inline' => [
		'old' => static function ( string $v ): string {
			if ( ! IATO_MCP_Inline_Markup_120::has_inline_markup( $v ) ) {
				return IATO_MCP_Elementor_Sanitizer_120::clean_markup( $v );
			}
			$r = IATO_MCP_Inline_Markup_120::filter( $v );
			return empty( $r['ok'] ) ? IATO_MCP_Elementor_Sanitizer_120::clean_markup( $v ) : (string) $r['html'];
		},
		'new' => static function ( string $v ): string {
			if ( ! IATO_MCP_Inline_Markup::has_inline_markup( $v ) ) {
				return IATO_MCP_Elementor_Sanitizer::clean_markup( $v );
			}
			$r = IATO_MCP_Inline_Markup::filter( $v );
			return empty( $r['ok'] ) ? IATO_MCP_Elementor_Sanitizer::clean_markup( $v ) : (string) $r['html'];
		},
	],
];

$passes = 0;
$fails  = [];
$record = static function ( string $name, bool $ok, string $detail = '' ) use ( &$passes, &$fails ): void {
	if ( $ok ) {
		++$passes;
		echo "  PASS  {$name}\n";
	} else {
		$fails[] = $name;
		echo "  FAIL  {$name}: " . substr( $detail, 0, 900 ) . "\n";
	}
};

/** What one inventory holds fewer of than another, as readable strings. */
$fewer = static function ( array $was, array $now ): array {
	$out = [];
	foreach ( $was['tags'] as $tag => $count ) {
		if ( ( $now['tags'][ $tag ] ?? 0 ) < $count ) {
			$out[] = "<{$tag}>";
		}
	}
	foreach ( [ 'attributes', 'styles' ] as $kind ) {
		foreach ( $was[ $kind ] as $tag => $names ) {
			foreach ( $names as $name => $count ) {
				if ( ( $now[ $kind ][ $tag ][ $name ] ?? 0 ) < $count ) {
					$out[] = "{$tag} {$name}";
				}
			}
		}
	}
	return $out;
};

$input_filter = static function ( $tags, $context ) {
	if ( 'post' === $context && is_array( $tags ) && ! isset( $tags['input'] ) ) {
		$tags['input'] = [ 'type' => true, 'name' => true, 'value' => true, 'checked' => true, 'id' => true, 'class' => true ];
	}
	return $tags;
};

echo 'WordPress ' . get_bloginfo( 'version' ) . ( defined( 'ELEMENTOR_VERSION' ) ? ', Elementor ' . ELEMENTOR_VERSION : '' ) . "\n";
foreach ( [ false, true ] as $with_input ) {
	if ( $with_input ) {
		add_filter( 'wp_kses_allowed_html', $input_filter, 10, 2 );
	}
	foreach ( $modes as $mode => $run ) {
		$label = $mode . ( $with_input ? ' + a plugin allowing <input>' : '' );
		echo "# {$label}\n";
		foreach ( $fixtures as $name => $value ) {
			$old  = $run['old']( $value );
			$new  = $run['new']( $value );
			$lost = $fewer( IATO_MCP_Sanitize_Report::inventory( $old ), IATO_MCP_Sanitize_Report::inventory( $new ) );
			$record( "{$label}: {$name}: nothing 1.20.0 kept is lost", [] === $lost, implode( ', ', $lost ) . "\n    1.20.0: {$old}\n    1.21.0: {$new}" );
			if ( $old === $value ) {
				$record( "{$label}: {$name}: still stored unchanged", $new === $value, "1.21.0: {$new}" );
			}
			if ( $new !== $old ) {
				echo "  note  {$label}: {$name}: 1.21.0 keeps more: " . implode( ', ', $fewer( IATO_MCP_Sanitize_Report::inventory( $new ), IATO_MCP_Sanitize_Report::inventory( $old ) ) ) . "\n";
			}
		}
	}
	remove_filter( 'wp_kses_allowed_html', $input_filter, 10 );
}

// The two spec fixtures, with the opt-in applying: stored with nothing removed.
$svg = $fixtures['spec svg'];
$r   = IATO_MCP_Inline_Markup::filter( $svg );
$record( 'the spec SVG passes the opt-in', ! empty( $r['ok'] ), wp_json_encode( $r ) );
$lost = $fewer( IATO_MCP_Sanitize_Report::inventory( $svg ), IATO_MCP_Sanitize_Report::inventory( (string) $r['html'] ) );
$record( 'the spec SVG loses nothing', [] === $lost, implode( ', ', $lost ) . ' ' . $r['html'] );
$outside = str_replace( "<use href='#g1'></use>", "<use href=\"https://example.com/x.svg#a\"></use>", $svg );
$r       = IATO_MCP_Inline_Markup::filter( $outside );
$record( 'an outside <use> loses that element and nothing else', ! empty( $r['ok'] ) && ! str_contains( (string) $r['html'], '<use' ) && str_contains( (string) $r['html'], '<ellipse' ), wp_json_encode( $r ) );
$style = $fixtures['spec style'];
$r     = IATO_MCP_Inline_Markup::filter( $style );
$record( 'the spec style block is stored unchanged', ! empty( $r['ok'] ) && str_contains( (string) $r['html'], "syntax:'<number>'" ) && [] === $r['removed_css'], wp_json_encode( $r ) );
$in_svg = "<svg><style>@property --p{syntax:'<number>'}</style></svg>";
$r      = IATO_MCP_Inline_Markup::filter( $in_svg );
$record( 'inside <svg> the old rule stands', empty( $r['ok'] ) || ! str_contains( (string) $r['html'], '<number>' ), wp_json_encode( $r ) );
$breakout = "<style>@property --p{syntax:'</style><script>alert(1)</script>'}</style>";
$r        = IATO_MCP_Inline_Markup::filter( $breakout );
$record( 'a breakout in a descriptor keeps nothing live', ! str_contains( (string) ( $r['html'] ?? '' ), '<script' ), wp_json_encode( $r ) );

echo '# ' . $passes . ' passed, ' . count( $fails ) . " failed\n";
if ( $fails ) {
	echo 'failed: ' . substr( implode( '; ', $fails ), 0, 800 ) . "\n";
}

<?php
/**
 * Elementor atomic (Editor V4) element defaults.
 *
 * Elementor does not persist untouched defaults in _elementor_data: on save,
 * Props_Parser::validate() drops null props and sanitize() skips values that
 * should not persist (elementor/modules/atomic-widgets/parsers/props-parser.php
 * :49-51, :70-72). A missing `tag` on an e-heading therefore means the schema
 * default `h2`. IATO_MCP_Elementor_Atomic::plain_settings() fills the keys
 * listed here and reports them in `defaulted_keys`.
 *
 * Values are copied from each element's define_props_schema() in Elementor 4.3.4:
 *   e-heading    modules/atomic-widgets/elements/atomic-heading/atomic-heading.php:59-78
 *   e-paragraph  modules/atomic-widgets/elements/atomic-paragraph/atomic-paragraph.php:60-79
 *   e-button     modules/atomic-widgets/elements/atomic-button/atomic-button.php:52-72
 *   e-image      modules/atomic-widgets/elements/atomic-image/atomic-image.php:58-73
 *   e-flexbox    modules/atomic-widgets/elements/flexbox/flexbox.php:81-91
 *   e-div-block  modules/atomic-widgets/elements/div-block/div-block.php:81-91
 *   e-grid       modules/atomic-widgets/elements/grid/grid.php:85-95
 *   e-list       modules/atomic-widgets/elements/atomic-list/atomic-list/atomic-list.php:61-77
 *   e-tabs       modules/atomic-widgets/elements/atomic-tabs/atomic-tabs/atomic-tabs.php:74-83
 *   e-accordion  modules/atomic-widgets/elements/atomic-accordion/atomic-accordion.php:78-104
 *
 * The placeholder strings are Elementor's untranslated English defaults.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

return [
	// ── Widgets (elType: widget, widgetType: e-*) ─────────────────────────
	'e-heading'   => [
		'tag'   => 'h2',
		'title' => 'This is a title',
	],
	'e-paragraph' => [
		'tag'       => 'p',
		'paragraph' => 'Type your paragraph here',
	],
	'e-button'    => [
		'tag'  => 'button',
		'text' => 'Click here',
	],
	'e-image'     => [
		// `image.src.url` defaults to Elementor's placeholder image and
		// `image.size` to 'full'; the size default is applied inside
		// IATO_MCP_Elementor_Atomic::image_view() rather than here because the
		// value is nested.
	],

	// ── Containers (elType: e-*) ─────────────────────────────────────────
	'e-flexbox'   => [ 'tag' => 'div' ],
	'e-div-block' => [ 'tag' => 'div' ],
	'e-grid'      => [ 'tag' => 'div' ],
	'e-list'      => [ 'tag' => 'ul', 'show_markers' => true ],
	'e-tabs'      => [ 'default-active-tab' => 0 ],
	'e-accordion' => [
		'default_state' => 'first_expanded',
		'max_expanded'  => 'one',
		'show_icon'     => true,
		'faq_schema'    => false,
	],
];

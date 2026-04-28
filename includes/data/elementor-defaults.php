<?php
/**
 * Hand-curated per-widget settings defaults for the top ~20 Elementor widget types.
 *
 * Used by IATO_MCP_Elementor_Adapter::compact() to strip default-valued fields
 * from the 'compact' format response. Widget types not present in this table
 * are returned with full settings — fail-soft is better than misclassification.
 *
 * Source of truth: manual inspection of an Elementor 3.20 install. Update by
 * eye when Elementor changes a default; never introspect, those defaults vary
 * by version and theme registration order.
 *
 * @package IATO_MCP
 */

defined( 'ABSPATH' ) || exit;

return [
	'heading' => [
		'title'              => '',
		'header_size'        => 'h2',
		'align'              => '',
		'size'               => 'default',
		'view'               => 'traditional',
		'_element_width'     => '',
		'_element_vertical_align' => '',
	],
	'text-editor' => [
		'editor'             => '',
		'drop_cap'           => '',
		'align'              => '',
		'text_columns'       => '',
		'column_gap'         => '',
	],
	'button' => [
		'text'               => 'Click here',
		'link'               => [ 'url' => '', 'is_external' => '', 'nofollow' => '' ],
		'align'              => '',
		'size'               => 'sm',
		'icon_align'         => 'left',
		'icon_indent'        => '',
		'view'               => 'traditional',
		'button_type'        => 'default',
	],
	'image' => [
		'image'              => [ 'url' => '', 'id' => '' ],
		'image_size'         => 'large',
		'caption_source'     => 'none',
		'align'              => '',
		'link_to'            => 'none',
		'view'               => 'traditional',
	],
	'spacer' => [
		'space'              => [ 'unit' => 'px', 'size' => 50 ],
		'view'               => 'traditional',
	],
	'divider' => [
		'style'              => 'solid',
		'weight'             => [ 'unit' => 'px', 'size' => 1 ],
		'width'              => [ 'unit' => '%',  'size' => 100 ],
		'align'              => 'center',
		'gap'                => [ 'unit' => 'px', 'size' => 15 ],
		'view'               => 'traditional',
		'look'               => 'line',
	],
	'icon' => [
		'selected_icon'      => [ 'value' => '', 'library' => '' ],
		'view'               => 'default',
		'shape'              => 'circle',
		'align'              => 'center',
		'link'               => [ 'url' => '', 'is_external' => '', 'nofollow' => '' ],
	],
	'icon-box' => [
		'selected_icon'      => [ 'value' => '', 'library' => '' ],
		'view'               => 'default',
		'shape'              => 'circle',
		'title_text'         => 'This is the heading',
		'description_text'   => '',
		'link'               => [ 'url' => '', 'is_external' => '', 'nofollow' => '' ],
		'position'           => 'top',
		'title_size'         => 'h3',
	],
	'icon-list' => [
		'icon_list'          => [],
		'view'               => 'traditional',
		'layout'             => 'traditional',
	],
	'image-box' => [
		'image'              => [ 'url' => '', 'id' => '' ],
		'image_size'         => 'medium',
		'title_text'         => 'This is the heading',
		'description_text'   => '',
		'link'               => [ 'url' => '', 'is_external' => '', 'nofollow' => '' ],
		'position'           => 'top',
		'title_size'         => 'h3',
		'view'               => 'traditional',
	],
	'video' => [
		'video_type'         => 'youtube',
		'youtube_url'         => '',
		'vimeo_url'          => '',
		'dailymotion_url'    => '',
		'autoplay'           => '',
		'mute'               => '',
		'loop'               => '',
		'controls'           => 'yes',
		'aspect_ratio'       => '169',
	],
	'html' => [
		'html'               => '',
	],
	'shortcode' => [
		'shortcode'          => '',
	],
	'menu-anchor' => [
		'anchor'             => '',
	],
	'social-icons' => [
		'social_icon_list'   => [],
		'shape'              => 'rounded',
		'columns'            => '0',
		'align'              => 'center',
	],
	'tabs' => [
		'tabs'               => [],
		'type'               => 'horizontal',
		'view'               => 'traditional',
	],
	'accordion' => [
		'tabs'               => [],
		'view'               => 'traditional',
		'selected_icon'      => [ 'value' => '', 'library' => '' ],
		'selected_active_icon' => [ 'value' => '', 'library' => '' ],
	],
	'toggle' => [
		'tabs'               => [],
		'view'               => 'traditional',
		'selected_icon'      => [ 'value' => '', 'library' => '' ],
	],
	'progress' => [
		'title'              => 'My Skill',
		'percent'            => [ 'unit' => '%', 'size' => 50 ],
		'display_percentage' => 'show',
		'progress_type'      => 'default',
		'inner_text'         => '',
		'view'               => 'traditional',
	],
	'counter' => [
		'starting_number'    => 0,
		'ending_number'      => 100,
		'prefix'             => '',
		'suffix'             => '',
		'duration'           => 2000,
		'thousand_separator' => 'yes',
		'thousand_separator_char' => '',
		'title'              => 'Cool Number',
		'view'               => 'traditional',
	],
];

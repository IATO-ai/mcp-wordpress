<?php
/**
 * Plugin Name: IATO MCP test addon widget
 * Description: TEST-ONLY plugin for tests/wp-env/unregistered-types-live.sh. Registers two classic Elementor widgets: iato-test-addon-widget, and e-iframe, named like e-addons' classic widgets (whose names start with e-). The suite puts them on pages, deactivates this plugin and checks that IATO edits keep them and never treat e-iframe as atomic. Never ship (tests/ is excluded from the release zip).
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
	if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
		return;
	}
	if ( ! class_exists( 'IATO_Test_Addon_Widget' ) ) {
		class IATO_Test_Addon_Widget extends \Elementor\Widget_Base {
			public function get_name() {
				return 'iato-test-addon-widget';
			}
			public function get_title() {
				return 'IATO test addon widget';
			}
			protected function register_controls() {
				$this->start_controls_section( 'content', [ 'label' => 'Content' ] );
				$this->add_control( 'label', [ 'label' => 'Label', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Addon' ] );
				$this->end_controls_section();
			}
			protected function render() {
				echo '<div class="iato-test-addon-marker">' . esc_html( (string) $this->get_settings_for_display( 'label' ) ) . '</div>';
			}
		}
	}
	if ( ! class_exists( 'IATO_Test_Eaddons_Iframe' ) ) {
		class IATO_Test_Eaddons_Iframe extends \Elementor\Widget_Base {
			public function get_name() {
				return 'e-iframe';
			}
			public function get_title() {
				return 'IATO test e-addons-style iframe';
			}
			protected function register_controls() {
				$this->start_controls_section( 'content', [ 'label' => 'Content' ] );
				$this->add_control( 'url', [ 'label' => 'URL', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '' ] );
				$this->end_controls_section();
			}
			protected function render() {
				echo '<div class="iato-test-eiframe">' . esc_html( (string) $this->get_settings_for_display( 'url' ) ) . '</div>';
			}
		}
	}
	$widgets_manager->register( new IATO_Test_Addon_Widget() );
	$widgets_manager->register( new IATO_Test_Eaddons_Iframe() );
} );

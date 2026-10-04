<?php
/**
 * Test-only classic theme for the parity matrix: the legacy layout renderer, the custom home page and
 * one widget area.
 */

add_action(
	'after_setup_theme',
	function () {
		add_theme_support(
			'siteorigin-panels',
			array(
				'legacy-layout' => 'always',
				'home-page'     => true,
			)
		);
		add_theme_support( 'title-tag' );
	}
);

add_action(
	'widgets_init',
	function () {
		register_sidebar(
			array(
				'id'   => 'panels-parity-legacy-sidebar',
				'name' => 'Legacy Sidebar',
			)
		);
	}
);

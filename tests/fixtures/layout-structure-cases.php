<?php
/**
 * Layouts for the structural rule of SiteOrigin_Panels_Admin::validate_layout_structure().
 *
 * Two suites read this file. tests/save-post/ValidateLayoutStructureTest.php
 * runs the cases against the real method. tests/AbilitiesTest.php runs them
 * against its SiteOrigin_Panels_Admin stand-in, which holds a copy of the
 * rule because the real class cannot load in that suite. So the copy and the
 * real method must agree on every case here.
 *
 * 'valid'   => layouts the rule returns (with `widgets` filled in when absent).
 * 'refused' => values the rule answers with null.
 */

$row    = array( 'cells' => 1 );
$cell   = array( 'grid' => 0, 'weight' => 1 );
$widget = array(
	'text'        => 'Hello',
	'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0 ),
);

return array(
	'valid'   => array(
		'one row, one cell, one widget'             => array(
			'widgets'    => array( $widget ),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'rows and cells, no widgets'                => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'no widgets key'                            => array(
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'a row without cells'                       => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array(),
		),
		'two rows, three cells, widgets in each'    => array(
			'widgets'    => array(
				$widget,
				array( 'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 1, 'cell' => 1 ) ),
			),
			'grids'      => array( $row, array( 'cells' => 2 ) ),
			'grid_cells' => array(
				$cell,
				array( 'grid' => 1, 'weight' => 0.5 ),
				array( 'grid' => 1, 'weight' => 0.5 ),
			),
		),
		'older layout: placement under info'        => array(
			'widgets'    => array(
				array(
					'text' => 'Hello',
					'info' => array( 'class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0 ),
				),
			),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'numeric string references'                 => array(
			'widgets'    => array(
				array( 'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => '0', 'cell' => '0' ) ),
			),
			'grids'      => array( $row ),
			'grid_cells' => array( array( 'grid' => '0', 'weight' => 1 ) ),
		),
		'extra top-level and style keys'            => array(
			'widgets'    => array( $widget ),
			'grids'      => array( array( 'cells' => 1, 'style' => array( 'padding' => '10px' ) ) ),
			'grid_cells' => array( array( 'grid' => 0, 'weight' => 1, 'style' => array() ) ),
			'layout'     => 'kept',
		),
	),
	'refused' => array(
		'cell row reference is a word'              => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( array( 'grid' => 'x', 'weight' => 1 ) ),
		),
		'cell row reference holds other characters' => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( array( 'grid' => '0 {} *', 'weight' => 1 ) ),
		),
		'cell row reference is past the last row'   => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( array( 'grid' => 1, 'weight' => 1 ) ),
		),
		'cell row reference is negative'            => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( array( 'grid' => -1, 'weight' => 1 ) ),
		),
		'cell has no row reference'                 => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( array( 'weight' => 1 ) ),
		),
		'cell is not an object'                     => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( 'cell' ),
		),
		'row is not an object'                      => array(
			'widgets'    => array(),
			'grids'      => array( 'row' ),
			'grid_cells' => array(),
		),
		'widgets only'                              => array(
			'widgets' => array( $widget ),
		),
		'widgets and rows, no cells key'            => array(
			'widgets' => array( $widget ),
			'grids'   => array( $row ),
		),
		'widgets, rows and an empty cell list'      => array(
			'widgets'    => array( $widget ),
			'grids'      => array( $row ),
			'grid_cells' => array(),
		),
		'widget without a placement'                => array(
			'widgets'    => array( array( 'panels_info' => array( 'class' => 'WP_Widget_Text' ) ) ),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'widget without panels_info'                => array(
			'widgets'    => array( array( 'text' => 'Hello' ) ),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'widget row reference is a word'            => array(
			'widgets'    => array( array( 'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 'x', 'cell' => 0 ) ) ),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'widget cell reference is past the last cell' => array(
			'widgets'    => array( array( 'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 1 ) ) ),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'widget is not an object'                   => array(
			'widgets'    => array( 'widget' ),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'rows keyed from 1'                         => array(
			'widgets'    => array(),
			'grids'      => array( 1 => $row ),
			'grid_cells' => array(),
		),
		'cells keyed by name'                       => array(
			'widgets'    => array(),
			'grids'      => array( $row ),
			'grid_cells' => array( 'first' => $cell ),
		),
		'widgets keyed from 1'                      => array(
			'widgets'    => array( 1 => $widget ),
			'grids'      => array( $row ),
			'grid_cells' => array( $cell ),
		),
		'rows is a string'                          => array(
			'widgets'    => array(),
			'grids'      => 'rows',
			'grid_cells' => array(),
		),
		'a list, not an object'                     => array( $row, $cell ),
		'empty array'                               => array(),
		'string'                                    => 'layout',
		'integer'                                   => 0,
		'null'                                      => null,
		'false'                                     => false,
	),
);

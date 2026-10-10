<?php

use SiteOrigin\Tests\SiteOriginTests;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

// In the default suite tests/AbilitiesTest.php supplies these first; the
// stand-ins below only apply when this file runs alone. Both kses_deep()
// stand-ins remove on* attributes, which is what these tests rely on.
if ( ! class_exists( 'SiteOrigin_Panels_Admin' ) ) {
	class SiteOrigin_Panels_Admin {
		public static function kses_deep( $value ) {
			if ( is_array( $value ) ) {
				return array_map( array( __CLASS__, 'kses_deep' ), $value );
			}

			if ( ! is_string( $value ) ) {
				return $value;
			}

			return preg_replace( '/\s*on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value );
		}
	}
}

if ( ! function_exists( 'map_deep' ) ) {
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = map_deep( $item, $callback );
			}
		} elseif ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $property_name => $property_value ) {
				$value->$property_name = map_deep( $property_value, $callback );
			}
		} else {
			$value = call_user_func( $callback, $value );
		}

		return $value;
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Aborted' ) ) {
	require __DIR__ . '/../inc/layout-update-aborted.php';
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Pre_Write' ) ) {
	require __DIR__ . '/../inc/layout-update-pre-write.php';
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Unchanged' ) ) {
	require_once __DIR__ . '/../inc/layout-update-unchanged.php';
}

/**
 * An object that is not a plain object.
 */
class Unchanged_Test_Holder {
	public $value = 'held';
}

/**
 * Unit tests for SiteOrigin_Panels_Layout_Update_Unchanged. Row names (T1 ...)
 * refer to the decision table for issue #1409.
 */
class LayoutUpdateUnchangedTest extends SiteOriginTests {

	private function widget( $content, $grid = 0, $cell = 0, $id = 0, array $extra = array() ) {
		return array_merge(
			array(
				'title'       => '',
				'content'     => $content,
				'panels_info' => array(
					'class'     => 'WP_Widget_Custom_HTML',
					'grid'      => $grid,
					'cell'      => $cell,
					'id'        => $id,
					'widget_id' => 'w-' . md5( $content ),
				),
			),
			$extra
		);
	}

	private function match( array $incoming, array $stored, array $member_only = array() ) {
		return SiteOrigin_Panels_Layout_Update_Unchanged::match( $incoming, $stored, $member_only );
	}

	// --- walkable() / comparable() ------------------------------------------

	public static function cyclic_values() {
		$self         = array( 't' => 'x' );
		$self['self'] = &$self;

		$object     = new stdClass();
		$object->me = $object;

		$deep                         = array( 't' => 'y' );
		$deep['kids']                 = array( 'a' => array( 'up' => null ) );
		$deep['kids']['a']['up']      = &$deep;

		$holder       = new stdClass();
		$list         = array( 'x' => 1 );
		$list['obj']  = $holder;
		$holder->list = &$list;

		return array(
			'self-referencing array'               => array( $self ),
			'self-referencing array, unserialized' => array( unserialize( serialize( $self ) ) ),
			'R:1 two levels down'                  => array( unserialize( 'a:2:{s:1:"t";s:1:"z";s:1:"c";a:1:{s:1:"d";R:1;}}' ) ),
			'R:2 three levels down'                => array( unserialize( 'a:1:{s:1:"c";a:1:{s:1:"d";a:1:{s:1:"e";R:2;}}}' ) ),
			'cycle two levels down, live'          => array( $deep ),
			'self-referencing stdClass'            => array( array( $object ) ),
			'self-referencing stdClass, unserialized' => array( unserialize( serialize( array( $object ) ) ) ),
			'array, object, reference, array'      => array( $list ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'cyclic_values' )]
	public function test_cyclic_values_are_not_walkable( $value ) {
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( $value ) );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::comparable( is_array( $value ) ? $value : array( $value ) ) );
	}

	public function test_acyclic_values_with_shared_references_and_objects_are_walkable() {
		$widgets = array( array( 'a' => 1 ), array( 'a' => 2 ) );
		foreach ( $widgets as &$widget ) {
			$widget['b'] = 1;
		}
		unset( $widget );

		$shared = 'v';
		$plain  = new stdClass();
		$plain->html = '<b>';

		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( $widgets ), 'foreach by reference leaves a reference slot' );
		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( array( 'p' => &$shared, 'q' => &$shared ) ) );
		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( array( $plain, $plain ) ) );
		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( array( 'Recursion detected' ) ) );
	}

	public function test_deep_values_are_walkable_without_a_depth_limit() {
		$object = '<img src=x onerror=alert(1)>';
		for ( $i = 0; $i < 1000; $i++ ) {
			$next    = new stdClass();
			$next->c = $object;
			$object  = $next;
		}

		$array = 'x';
		for ( $i = 0; $i < 20000; $i++ ) {
			$array = array( 'n' => $array );
		}

		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( $object ) );
		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( $array ) );
	}

	public function test_objects_other_than_plain_objects_are_not_comparable() {
		$widget = $this->widget( 'x', 0, 0, 0, array( 'setting' => array( 'nested' => new Unchanged_Test_Holder() ) ) );

		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::walkable( $widget ) );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::comparable( $widget ) );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::comparable( 'scalar' ) );
	}

	// --- same() (T8) ----------------------------------------------------------

	public function test_same_compares_by_value() {
		$a = new stdClass();
		$a->x = 1;
		$a->y = 2;
		$b = new stdClass();
		$b->y = 2;
		$b->x = 1;
		$other = new Unchanged_Test_Holder();

		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::same( array( 'a' => 1, 'b' => 2 ), array( 'b' => 2, 'a' => 1 ) ), 'associative key order' );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::same( array( 1, 2 ), array( 2, 1 ) ), 'list order' );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::same( array( 'n' => '1' ), array( 'n' => 1 ) ), 'scalar type' );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::same( $a, array( 'x' => 1, 'y' => 2 ) ), 'stdClass versus array' );
		$this->assertTrue( SiteOrigin_Panels_Layout_Update_Unchanged::same( $a, $b ), 'stdClass property order' );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::same( array( 'k' => null ), array() ), 'present null versus absent' );
		$this->assertFalse( SiteOrigin_Panels_Layout_Update_Unchanged::same( $other, $other ), 'another class equals nothing' );
	}

	// --- match() --------------------------------------------------------------

	public function test_unchanged_widget_at_its_placement_matches_unmoved() { // T1, T2
		$stored   = array( $this->widget( 'A', 0, 0, 0 ) );
		$incoming = $stored;
		$incoming[0]['panels_info']['cell_index'] = 0;

		$this->assertSame( array( 0 => array( 'stored' => 0, 'moved' => false ) ), $this->match( $incoming, $stored ) );
	}

	public function test_moved_unique_widget_matches_moved() { // T3
		$stored   = array( $this->widget( 'A', 0, 0, 0 ), $this->widget( 'B', 0, 0, 1 ) );
		$incoming = array( $this->widget( 'B', 0, 0, 0 ), $this->widget( 'A', 0, 1, 0 ) );

		$this->assertSame(
			array(
				0 => array( 'stored' => 1, 'moved' => true ),
				1 => array( 'stored' => 0, 'moved' => true ),
			),
			$this->match( $incoming, $stored )
		);
	}

	public function test_equal_stored_widgets_match_by_placement() { // T4
		$p1 = $this->widget( 'Same', 0, 0, 0 );
		$p2 = $this->widget( 'Same', 0, 0, 1 );

		$this->assertSame(
			array( 0 => array( 'stored' => 0, 'moved' => false ), 1 => array( 'stored' => 1, 'moved' => false ) ),
			$this->match( array( $p1, $p2 ), array( $p1, $p2 ) ),
			'both returned in place'
		);
		$this->assertSame(
			array( 0 => array( 'stored' => 1, 'moved' => false ) ),
			$this->match( array( $p2 ), array( $p1, $p2 ) ),
			'first deleted, second returned in place'
		);
		$this->assertSame(
			array( 0 => array( 'stored' => 1, 'moved' => false ), 1 => array( 'stored' => 0, 'moved' => false ) ),
			$this->match( array( $p2, $p1 ), array( $p1, $p2 ) ),
			'the two swapped'
		);
	}

	public function test_ambiguous_groups_match_nothing_in_any_order() { // T5
		$p1 = $this->widget( 'Same', 0, 0, 0 );
		$p2 = $this->widget( 'Same', 0, 0, 1 );
		$q  = $this->widget( 'Same', 0, 1, 0 );

		$this->assertSame( array(), $this->match( array( $q ), array( $p1, $p2 ) ), 'two equal stored, one returned elsewhere' );
		$this->assertSame( array(), $this->match( array( $p1, $p1 ), array( $p1 ) ), 'two equal incoming at one placement' );

		// One stored widget at P, two copies at P and one at Q, in both orders:
		// the P group is ambiguous and consumes the stored widget, so Q has no
		// candidate left.
		$this->assertSame( array(), $this->match( array( $p1, $p1, $q ), array( $p1 ) ) );
		$this->assertSame( array(), $this->match( array( $q, $p1, $p1 ), array( $p1 ) ) );
	}

	public function test_a_copy_elsewhere_does_not_match() { // T6
		$a = $this->widget( 'A', 0, 0, 0 );

		$this->assertSame(
			array( 0 => array( 'stored' => 0, 'moved' => false ) ),
			$this->match( array( $a, $this->widget( 'A', 0, 1, 0 ) ), array( $a ) )
		);
	}

	public static function changes() {
		return array(
			'field'          => array( function ( $w ) { $w['content'] = 'B'; return $w; } ),
			'class'          => array( function ( $w ) { $w['panels_info']['class'] = 'Other'; return $w; } ),
			'widget_id'      => array( function ( $w ) { $w['panels_info']['widget_id'] = 'other'; return $w; } ),
			'style'          => array( function ( $w ) { $w['panels_info']['style'] = array( 'padding' => '1px' ); return $w; } ),
			'label'          => array( function ( $w ) { $w['panels_info']['label'] = 'L'; return $w; } ),
			'emulator id'    => array( function ( $w ) { $w['so_sidebar_emulator_id'] = 'custom_html-1'; return $w; } ),
			'option name'    => array( function ( $w ) { $w['option_name'] = 'widget_x'; return $w; } ),
			'raw added'      => array( function ( $w ) { $w['panels_info']['raw'] = true; return $w; } ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'changes' )]
	public function test_any_other_change_does_not_match( $change ) { // T7
		$stored = array( $this->widget( 'A' ) );

		$this->assertSame( array(), $this->match( array( $change( $stored[0] ) ), $stored ) );
	}

	public function test_member_only_entries_count_but_never_match() { // T17, D42
		$a = $this->widget( 'A', 0, 0, 0 );

		$this->assertSame( array(), $this->match( array( $a ), array( $a ), array( 0 ) ) );
		$this->assertSame( array(), $this->match( array( $a, $this->widget( 'A', 0, 1, 0 ) ), array( $a ), array( 0 ) ), 'the copy elsewhere gets no candidate' );
	}

	// --- restore() --------------------------------------------------------------

	public function test_unmoved_widget_keeps_its_stored_position_fields() { // T2, T24
		$stored = $this->widget( 'A' );
		$stored['panels_info']['raw'] = true;
		$incoming = $this->widget( 'A' );
		$incoming['panels_info']['cell_index'] = 7;

		$restored = SiteOrigin_Panels_Layout_Update_Unchanged::restore(
			array( $incoming ),
			array( $stored ),
			array( 0 => array( 'stored' => 0, 'moved' => false ) )
		);

		$this->assertSame( $stored, $restored[0] );
	}

	public function test_moved_widget_takes_the_callers_position_fields_filtered() { // T3, T36
		$stored = $this->widget( 'A' );
		$stored['panels_info']['cell_index'] = 3;
		$id     = new stdClass();
		$id->v  = '<img src=x onerror=alert(1)>';
		$incoming = $this->widget( 'A', 0, 1 );
		$incoming['panels_info']['id']    = $id;
		unset( $incoming['panels_info']['cell_index'] );
		$incoming['panels_info']['grid']  = null;

		$restored = SiteOrigin_Panels_Layout_Update_Unchanged::restore(
			array( $incoming ),
			array( $stored ),
			array( 0 => array( 'stored' => 0, 'moved' => true ) )
		);
		$info = $restored[0]['panels_info'];

		$this->assertSame( 1, $info['cell'] );
		$this->assertNull( $info['grid'], 'present null is copied' );
		$this->assertArrayNotHasKey( 'cell_index', $info, 'absent is removed' );
		$this->assertInstanceOf( stdClass::class, $info['id'] );
		$this->assertStringNotContainsString( 'onerror', $info['id']->v );
		$this->assertSame( $stored['content'], $restored[0]['content'] );
	}

	// --- floor() ------------------------------------------------------------------

	public function test_kept_widget_is_stored_as_its_snapshot() { // T1, T14
		$snapshot = $this->widget( '<iframe src="https://example.com"></iframe>' );
		$output   = array_reverse( $snapshot, true );

		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $output ), array( $snapshot ) );

		$this->assertSame( $snapshot, $floored[0], 'stored bytes equal the snapshot, in its key order' );
	}

	public function test_emulator_values_must_be_the_recorded_ones() { // T15
		$snapshot = $this->widget( 'A <img src=x onerror=1>' );
		$recorded = array( 'so_sidebar_emulator_id' => 'custom_html-280001', 'option_name' => 'widget_custom_html' );
		$output   = array_merge( $snapshot, $recorded );

		$kept = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $output ), array( $snapshot ), array( 0 => $recorded ) );
		$this->assertSame( array_merge( $snapshot, $recorded ), $kept[0] );

		$output['so_sidebar_emulator_id'] = '<img src=x onerror=1>';
		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $output ), array( $snapshot ), array( 0 => $recorded ) );
		$this->assertStringNotContainsString( 'onerror', $floored[0]['content'], 'a changed emulator value floors the whole widget' );

		$with_null = $snapshot;
		$with_null['option_name'] = null;
		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $with_null ), array( $snapshot ) );
		$this->assertStringNotContainsString( 'onerror', $floored[0]['content'], 'present null is not absent' );
	}

	public function test_changed_widgets_are_filtered() { // T13, T19
		$snapshot = $this->widget( 'A' );
		$setting  = new stdClass();
		$setting->inner = new stdClass();
		$setting->inner->html = '<img src=x onerror=alert(1)>';
		$changed  = array_merge( $snapshot, array( 'setting' => $setting ) );
		$swapped  = array( $this->widget( 'B <img src=x onerror=2>' ), $snapshot );

		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $changed ), array( $snapshot ) );
		$this->assertInstanceOf( stdClass::class, $floored[0]['setting'] );
		$this->assertStringNotContainsString( 'onerror', $floored[0]['setting']->inner->html );
		$this->assertNotSame( $setting, $floored[0]['setting'] );

		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( $swapped, array( $snapshot, $this->widget( 'B <img src=x onerror=2>' ) ) );
		$this->assertStringNotContainsString( 'onerror', $floored[0]['content'], 'a swap floors both keys' );
	}

	public function test_deep_markup_inside_plain_objects_is_filtered() { // T31, T34
		$value = '<img src=x onerror=alert(1)>';
		for ( $i = 0; $i < 1000; $i++ ) {
			$next    = new stdClass();
			$next->c = $value;
			$value   = $next;
		}

		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( array( 'setting' => $value ) ), array() );

		$leaf = $floored[0]['setting'];
		for ( $i = 0; $i < 1000; $i++ ) {
			$leaf = $leaf->c;
		}
		$this->assertStringNotContainsString( 'onerror', $leaf );
	}

	public function test_deep_identical_widget_is_kept() { // T32, T34
		$value = 'deep';
		for ( $i = 0; $i < 1000; $i++ ) {
			$value = array( 'n' => $value );
		}
		$snapshot = array_merge( $this->widget( 'A <img src=x onerror=1>' ), array( 'setting' => $value ) );

		$kept = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $snapshot ), array( $snapshot ) );

		$this->assertSame( $snapshot, $kept[0] );
	}

	public function test_values_holding_other_objects_get_todays_floor() { // T20
		$holder  = new Unchanged_Test_Holder();
		$widget  = array( 'content' => 'x', 'setting' => $holder );

		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $widget ), array() );

		$this->assertSame( $holder, $floored[0]['setting'], 'kses_deep() leaves the object to the pre-write check' );
		$this->assertSame( 'scalar', SiteOrigin_Panels_Layout_Update_Unchanged::floor( 'scalar', array() ) );
	}

	public function test_floor_result_holds_no_reference() { // T22
		$shared = 'safe';
		$widget = array( 'content' => &$shared );

		$floored = SiteOrigin_Panels_Layout_Update_Unchanged::floor( array( $widget ), array() );
		$shared  = '<img src=x onerror=alert(1)>';

		$this->assertSame( 'safe', $floored[0]['content'] );
	}
}

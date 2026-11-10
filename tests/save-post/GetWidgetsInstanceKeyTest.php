<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A widget registered under its own class name.
 */
class GetWidgetsClassKeyWidget {
	public $name = 'Class Key Widget';
	public $widget_options = array();
}

/**
 * A widget a plugin registers as an object, the way Polylang 3.9 registers its
 * Language switcher. Namespaced, so its class name carries backslashes.
 */
class GetWidgetsInstanceWidget {
	public $name;
	public $widget_options = array();

	public function __construct( $name ) {
		$this->name = $name;
	}
}

/**
 * Pins how SiteOrigin_Panels_Admin::get_widgets() names each widget in the
 * WordPress widget factory.
 *
 * WordPress keys a widget registered by class name under that class, and a
 * widget registered as an object under a key built from the object:
 * spl_object_hash() before 7.1, the integer spl_object_id() in 7.1.0 and
 * 'spl_object_id:' . spl_object_id() from 7.1.1. Those object keys change
 * between requests, so Page Builder lists the object's class instead. Any other
 * key is a plugin's own name for its widget and is kept, even when several
 * widgets share one class.
 *
 * The real get_widgets() runs against a stub factory. Plain named classes and
 * closures keep this file parseable by the build toolchain's php-parser.
 */
class GetWidgetsInstanceKeyTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $factory_before;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value = null ) {
				return $value;
			}
		);

		$this->factory_before = isset( $GLOBALS['wp_widget_factory'] ) ? $GLOBALS['wp_widget_factory'] : null;
	}

	protected function tearDown(): void {
		$GLOBALS['wp_widget_factory'] = $this->factory_before;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run the real get_widgets() over a factory holding these entries.
	 *
	 * @param array $entries Factory key => widget object.
	 *
	 * @return array The listed widgets, keyed by the class Page Builder uses.
	 */
	private function list_widgets( array $entries ) {
		$factory = new \stdClass();
		$factory->widgets = $entries;
		$GLOBALS['wp_widget_factory'] = $factory;

		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Admin::class );
		$admin = $reflection->newInstanceWithoutConstructor();

		return $admin->get_widgets();
	}

	private function assert_listed_as( array $widgets, $class, $title ) {
		$this->assertArrayHasKey( $class, $widgets );
		$this->assertSame( $class, $widgets[ $class ]['class'] );
		$this->assertSame( $title, $widgets[ $class ]['title'] );
	}

	public function test_class_key_is_kept() {
		$widgets = $this->list_widgets(
			array( 'Foo_Widget' => new GetWidgetsClassKeyWidget() )
		);

		$this->assert_listed_as( $widgets, 'Foo_Widget', 'Class Key Widget' );
	}

	public function test_hash_key_from_before_wordpress_7_1_uses_the_object_class() {
		$widget = new GetWidgetsInstanceWidget( 'Hash Keyed' );
		$widgets = $this->list_widgets(
			array( spl_object_hash( $widget ) => $widget )
		);

		$this->assertCount( 1, $widgets );
		$this->assert_listed_as( $widgets, GetWidgetsInstanceWidget::class, 'Hash Keyed' );
	}

	public function test_integer_object_id_key_from_wordpress_7_1_0_uses_the_object_class() {
		$widget = new GetWidgetsInstanceWidget( 'Integer Keyed' );
		$widgets = $this->list_widgets(
			array( spl_object_id( $widget ) => $widget )
		);

		$this->assertCount( 1, $widgets );
		$this->assert_listed_as( $widgets, GetWidgetsInstanceWidget::class, 'Integer Keyed' );
	}

	public function test_prefixed_object_id_key_from_wordpress_7_1_1_uses_the_object_class() {
		$widget = new GetWidgetsInstanceWidget( 'Prefix Keyed' );
		$widgets = $this->list_widgets(
			array( 'spl_object_id:' . spl_object_id( $widget ) => $widget )
		);

		$this->assertCount( 1, $widgets );
		$this->assert_listed_as( $widgets, GetWidgetsInstanceWidget::class, 'Prefix Keyed' );
	}

	public function test_custom_keys_sharing_one_class_keep_their_keys() {
		$widgets = $this->list_widgets(
			array(
				'My_Plugin_Widget_One' => new GetWidgetsInstanceWidget( 'One' ),
				'My_Plugin_Widget_Two' => new GetWidgetsInstanceWidget( 'Two' ),
			)
		);

		$this->assertCount( 2, $widgets );
		$this->assert_listed_as( $widgets, 'My_Plugin_Widget_One', 'One' );
		$this->assert_listed_as( $widgets, 'My_Plugin_Widget_Two', 'Two' );
	}

	public function test_keys_that_only_look_like_object_keys_are_kept() {
		$widget = new GetWidgetsInstanceWidget( 'Look-alike' );
		$other = new GetWidgetsInstanceWidget( 'Other' );
		$own_id = spl_object_id( $widget );
		$other_id = spl_object_id( $other );

		$entries = array(
			'spl_object_id:custom'              => new GetWidgetsInstanceWidget( 'Custom' ),
			'spl_object_id:' . $own_id . "\n"   => $widget,
			'spl_object_id:' . $other_id        => new GetWidgetsInstanceWidget( 'Another Id' ),
			$other_id                           => new GetWidgetsInstanceWidget( 'Numeric' ),
		);

		$widgets = $this->list_widgets( $entries );

		$this->assertCount( 4, $widgets );
		$this->assert_listed_as( $widgets, 'spl_object_id:custom', 'Custom' );
		$this->assert_listed_as( $widgets, 'spl_object_id:' . $own_id . "\n", 'Look-alike' );
		$this->assert_listed_as( $widgets, 'spl_object_id:' . $other_id, 'Another Id' );
		$this->assertArrayHasKey( $other_id, $widgets );
		$this->assertSame( $other_id, $widgets[ $other_id ]['class'] );
		$this->assertArrayNotHasKey( GetWidgetsInstanceWidget::class, $widgets );
	}
}

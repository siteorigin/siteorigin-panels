<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Aborted' ) ) {
	require __DIR__ . '/../inc/layout-update-aborted.php';
}

if ( ! class_exists( 'SiteOrigin_Panels_Layout_Update_Pre_Write' ) ) {
	require __DIR__ . '/../inc/layout-update-pre-write.php';
}

/**
 * A widget setting object that keeps a nested object in a private property.
 */
class Pre_Write_Private_Holder {
	private $setting;

	public function __construct( $setting ) {
		$this->setting = $setting;
	}

	public function setting() {
		return $this->setting;
	}
}

/**
 * A widget setting object that keeps a nested object in a protected property.
 */
class Pre_Write_Protected_Holder {
	protected $setting;

	public function __construct( $setting ) {
		$this->setting = $setting;
	}
}

/**
 * A custom class with only public properties.
 */
class Pre_Write_Public_Holder {
	public $setting;

	public function __construct( $setting ) {
		$this->setting = $setting;
	}
}

/**
 * A subclass of stdClass; only stdClass itself is a plain object.
 */
class Pre_Write_Plain_Subclass extends stdClass {
}

/**
 * Unit tests for SiteOrigin_Panels_Layout_Update_Pre_Write::run(): the
 * arguments the pre-write filter receives, which results continue or stop the
 * write, and that the payload is a copy the listener cannot use to change the
 * stored value.
 */
class LayoutUpdatePreWriteTest extends SiteOriginTests {
	/**
	 * Arguments of every apply_filters() call for the pre-write tag.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * The listener standing in for the filter. Null passes $result through.
	 *
	 * @var callable|null
	 */
	private $listener = null;

	protected function setUp(): void {
		parent::setUp();

		$this->calls    = array();
		$this->listener = null;

		$test = $this;
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) use ( $test ) {
				return $test->dispatch( func_get_args() );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
	}

	/**
	 * The apply_filters() stand-in. Public so the alias closure can reach it.
	 */
	public function dispatch( array $args ) {
		if ( $args[0] !== 'siteorigin_panels_layout_update_pre_write' ) {
			return $args[1];
		}

		$this->calls[] = $args;

		if ( $this->listener === null ) {
			return $args[1];
		}

		return call_user_func_array( $this->listener, array_slice( $args, 1 ) );
	}

	private function layout() {
		return array(
			'widgets'    => array(
				array(
					'text'        => 'Hello',
					'panels_info' => array( 'class' => 'WP_Widget_Text' ),
				),
			),
			'grids'      => array( array( 'cells' => 1 ) ),
			'grid_cells' => array( array( 'grid' => 0 ) ),
		);
	}

	private function listener_returns( $value ) {
		$this->listener = function () use ( $value ) {
			return $value;
		};
	}

	private function run_hook( $panels_data = null ) {
		SiteOrigin_Panels_Layout_Update_Pre_Write::run(
			$panels_data === null ? $this->layout() : $panels_data,
			42,
			'block',
			2
		);
	}

	public function test_true_result_continues() {
		$this->run_hook();

		$this->assertCount( 1, $this->calls );
	}

	public function test_filter_receives_the_documented_arguments() {
		SiteOrigin_Panels_Layout_Update_Pre_Write::run( $this->layout(), '42', 'meta', null );

		$this->assertCount( 1, $this->calls );
		$this->assertSame(
			array( 'siteorigin_panels_layout_update_pre_write', true, $this->layout(), 42, 'meta', null ),
			$this->calls[0]
		);
	}

	public function test_block_index_is_passed_through() {
		$this->run_hook();

		$this->assertSame( 'block', $this->calls[0][4] );
		$this->assertSame( 2, $this->calls[0][5] );
	}

	public static function continue_results() {
		return array(
			'null'        => array( null ),
			'zero'        => array( 0 ),
			'empty'       => array( '' ),
			'string'      => array( 'no' ),
			'empty array' => array( array() ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'continue_results' )]
	public function test_other_results_continue( $result ) {
		$this->listener_returns( $result );

		$this->run_hook();

		$this->assertCount( 1, $this->calls );
	}

	public function test_false_stops_the_write_with_the_aborted_code() {
		$this->listener_returns( false );

		try {
			$this->run_hook();
			$this->fail( 'A false result must stop the write.' );
		} catch ( SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertInstanceOf( WP_Error::class, $e->get_error() );
			$this->assertSame( 'siteorigin_panels_layout_update_aborted', $e->get_error()->get_error_code() );
			$this->assertSame( 'The layout update was stopped before it was saved.', $e->getMessage() );
			$this->assertSame( array(), $e->get_error()->get_error_data() );
		}
	}

	public function test_wp_error_stops_the_write_with_that_error() {
		$error = new WP_Error( 'addon_blocked', 'Blocked by the add-on.', array( 'status' => 409 ) );
		$this->listener_returns( $error );

		try {
			$this->run_hook();
			$this->fail( 'A WP_Error result must stop the write.' );
		} catch ( SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( $error, $e->get_error() );
			$this->assertSame( 'addon_blocked', $e->get_error()->get_error_code() );
			$this->assertSame( 'Blocked by the add-on.', $e->get_error()->get_error_message() );
			$this->assertSame( array( 'status' => 409 ), $e->get_error()->get_error_data() );
			$this->assertSame( 'Blocked by the add-on.', $e->getMessage() );
		}
	}

	public function test_listener_cannot_change_a_nested_object_in_the_callers_layout() {
		$setting        = new stdClass();
		$setting->color = 'red';
		$layout         = $this->layout();
		$layout['widgets'][0]['style'] = $setting;
		$before         = serialize( $layout );

		$this->listener = function ( $result, $panels_data ) {
			$panels_data['widgets'][0]['style']->color = 'blue';
			$panels_data['widgets'][0]['style']->added = true;

			return $result;
		};

		SiteOrigin_Panels_Layout_Update_Pre_Write::run( $layout, 42, 'meta', null );

		$this->assertSame( $before, serialize( $layout ) );
		$this->assertSame( 'red', $setting->color );
	}

	public function test_private_property_object_stops_the_write_before_the_hook() {
		$setting        = new stdClass();
		$setting->color = 'red';
		$holder         = new Pre_Write_Private_Holder( $setting );
		$layout         = $this->layout();
		$layout['widgets'][0]['style'] = $holder;

		// A listener that reaches the private property, if it were called.
		$this->listener = function ( $result, $panels_data ) {
			$reach = Closure::bind(
				function ( $object ) {
					$object->setting->color = 'blue';
				},
				null,
				Pre_Write_Private_Holder::class
			);
			$reach( $panels_data['widgets'][0]['style'] );

			return $result;
		};

		try {
			SiteOrigin_Panels_Layout_Update_Pre_Write::run( $layout, 42, 'meta', null );
			$this->fail( 'An object with a private property must stop the write.' );
		} catch ( SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( 'siteorigin_panels_layout_update_unsupported_value', $e->get_error()->get_error_code() );
		}

		$this->assertCount( 0, $this->calls, 'The hook does not fire.' );
		$this->assertSame( 'red', $holder->setting()->color, 'The caller\'s layout keeps its value.' );
	}

	public static function unsupported_objects() {
		$setting = new stdClass();

		return array(
			'private property'      => array( new Pre_Write_Private_Holder( $setting ) ),
			'protected property'    => array( new Pre_Write_Protected_Holder( $setting ) ),
			'custom public class'   => array( new Pre_Write_Public_Holder( $setting ) ),
			'internal storage'      => array( new ArrayObject( array( 'setting' => $setting ) ) ),
			'plain object subclass' => array( new Pre_Write_Plain_Subclass() ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsupported_objects' )]
	public function test_objects_other_than_plain_objects_stop_the_write( $value ) {
		$layout = $this->layout();
		$layout['widgets'][0]['settings'] = array( 'nested' => $value );

		try {
			SiteOrigin_Panels_Layout_Update_Pre_Write::run( $layout, 42, 'block', 0 );
			$this->fail( 'The write must stop.' );
		} catch ( SiteOrigin_Panels_Layout_Update_Aborted $e ) {
			$this->assertSame( 'siteorigin_panels_layout_update_unsupported_value', $e->get_error()->get_error_code() );
			$this->assertSame( 'The layout contains a value that cannot be saved by a layout update.', $e->getMessage() );
		}

		$this->assertCount( 0, $this->calls );
	}

	public function test_plain_objects_nested_in_plain_objects_are_copied() {
		$inner        = new stdClass();
		$inner->color = 'red';
		$outer        = new stdClass();
		$outer->inner = $inner;
		$layout       = $this->layout();
		$layout['widgets'][0]['style'] = $outer;

		$this->listener = function ( $result, $panels_data ) {
			$panels_data['widgets'][0]['style']->inner->color = 'blue';

			return $result;
		};

		SiteOrigin_Panels_Layout_Update_Pre_Write::run( $layout, 42, 'meta', null );

		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'red', $inner->color );
	}

	public function test_listener_exception_propagates_unchanged() {
		$thrown = new RuntimeException( 'listener failed' );
		$this->listener = function () use ( $thrown ) {
			throw $thrown;
		};

		try {
			$this->run_hook();
			$this->fail( 'A listener exception must propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( $thrown, $e );
		}
	}
}

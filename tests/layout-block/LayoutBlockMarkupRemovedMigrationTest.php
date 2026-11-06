<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The markup-removed report around sanitize_all()'s data migration filter,
 * with the REAL SiteOrigin_Panels_Styles_Admin (#1454).
 *
 * Each test runs in a separate process: other Layout Block tests define a
 * stand-in SiteOrigin_Panels_Styles_Admin, which would otherwise claim the
 * class name before this test could load the real one.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
class LayoutBlockMarkupRemovedMigrationTest extends TestCase {
	use MockeryPHPUnitIntegration;

	public $callbacks = array();

	public $reports = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$test = $this;
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			function () use ( $test ) {
				$args = func_get_args();
				if ( isset( $test->callbacks[ $args[0] ] ) ) {
					return call_user_func_array( $test->callbacks[ $args[0] ], array_slice( $args, 1 ) );
				}

				return $args[1];
			}
		);
		Functions\when( 'wp_kses_post' )->alias(
			function ( $value ) {
				return preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', (string) $value );
			}
		);
		Functions\when( 'wp_kses_no_null' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_the_ID' )->justReturn( 123 );
		// A capable user: no widget update() or floor removes anything, so
		// every loss below comes from the migration filter.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'serialize_blocks' )->alias(
			function ( $blocks ) {
				return json_encode( array_values( $blocks ) );
			}
		);
		Functions\when( 'parse_blocks' )->alias(
			function ( $content ) {
				$blocks = json_decode( (string) $content, true );

				return is_array( $blocks ) ? $blocks : array();
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $text ) {
				if ( $text === 'THROW' ) {
					throw new \RuntimeException( 'style sanitizer failed' );
				}

				return $text;
			}
		);

		eval(
			'class SiteOrigin_Panels {'
			. ' public static function get_widget_instance( $class ) { return null; }'
			. ' public static function renderer() { return new \SiteOrigin\Tests\MigrationRendererStub(); }'
			. '}'
		);
		foreach ( array( 'SiteOrigin_Panels_Admin_Widget_Dialog', 'SiteOrigin_Panels_Admin_Widgets_Bundle', 'SiteOrigin_Panels_Admin_Layouts', 'SiteOrigin_Panels_Admin_Dashboard', 'SiteOrigin_Panels_Styles' ) as $collaborator ) {
			if ( ! class_exists( $collaborator, false ) ) {
				eval(
					'class ' . $collaborator . ' {'
					. ' public static function single() {'
					. '   static $single;'
					. '   return empty( $single ) ? $single = new self() : $single;'
					. ' }'
					. '}'
				);
			}
		}
		if ( ! class_exists( 'SiteOrigin_Installer', false ) ) {
			eval( 'class SiteOrigin_Installer {}' );
		}

		$root = dirname( dirname( __DIR__ ) );
		require_once $root . '/inc/styles-admin.php';
		require_once $root . '/compat/layout-block.php';
		require_once $root . '/inc/markup-removal.php';

		$this->assertTrue(
			method_exists( 'SiteOrigin_Panels_Styles_Admin', 'observe_next_sanitize_all' ),
			'Expected the REAL SiteOrigin_Panels_Styles_Admin.'
		);

		Actions\expectDone( \SiteOrigin_Panels_Markup_Removal::ACTION )->zeroOrMoreTimes()->whenHappen(
			function ( $report ) use ( $test ) {
				$test->reports[] = $report;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function compat() {
		$reflection = new \ReflectionClass( \SiteOrigin_Panels_Compat_Layout_Block::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	private function widget( $id, $content, $label = '' ) {
		$info = array( 'class' => 'NoSuchWidget', 'grid' => 0, 'cell' => 0, 'id' => 0, 'widget_id' => $id );
		if ( $label !== '' ) {
			$info['label'] = $label;
		}

		return array( 'content' => $content, 'panels_info' => $info );
	}

	private function save( array $widgets ) {
		$block = array(
			'blockName'    => 'siteorigin-panels/layout-block',
			'attrs'        => array(
				'panelsData' => array(
					'widgets'    => $widgets,
					'grids'      => array( array( 'cells' => 1 ) ),
					'grid_cells' => array( array( 'grid' => 0, 'weight' => 1 ) ),
				),
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		return $this->compat()->server_side_validation( (object) array( 'ID' => 9, 'post_content' => json_encode( array( $block ) ) ), null );
	}

	const IFRAME = '<iframe src="https://example.com/x"></iframe>';

	public function test_a_migration_loss_is_reported_when_a_later_style_sanitizer_throws() {
		$this->callbacks['siteorigin_panels_data_migration'] = function ( $panels_data ) {
			$panels_data['widgets'][0]['content'] = 'stripped';

			return $panels_data;
		};

		try {
			$this->save( array( $this->widget( 'w1', self::IFRAME ), $this->widget( 'w2', 'x', 'THROW' ) ) );
			$this->fail( 'The style sanitizer exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'style sanitizer failed', $e->getMessage() );
		}

		$this->assertCount( 1, $this->reports );
		$this->assertSame( 'w1', $this->reports[0]['widgets'][0]['widget_id'] );
	}

	public function test_a_migration_that_moves_markup_between_widgets_does_not_report() {
		$this->callbacks['siteorigin_panels_data_migration'] = function ( $panels_data ) {
			$panels_data['widgets'][1]['content'] = $panels_data['widgets'][0]['content'];
			$panels_data['widgets'][0]['content'] = '';

			return $panels_data;
		};

		$this->save( array( $this->widget( 'a', self::IFRAME ), $this->widget( 'b', 'x' ) ) );

		$this->assertSame( array(), $this->reports );
	}

	public function test_a_migration_that_swaps_widget_keys_does_not_report() {
		$this->callbacks['siteorigin_panels_data_migration'] = function ( $panels_data ) {
			$panels_data['widgets'] = array( $panels_data['widgets'][1], $panels_data['widgets'][0] );

			return $panels_data;
		};

		$this->save( array( $this->widget( 'a', self::IFRAME ), $this->widget( 'b', 'x' ) ) );

		$this->assertSame( array(), $this->reports );
	}

	public function test_a_migration_that_moves_and_strips_reports_without_naming_a_widget() {
		$this->callbacks['siteorigin_panels_data_migration'] = function ( $panels_data ) {
			// A's iframe moves to B, and B's own iframe is removed.
			$panels_data['widgets'][1]['content'] = $panels_data['widgets'][0]['content'];
			$panels_data['widgets'][0]['content'] = '';

			return $panels_data;
		};

		$this->save( array( $this->widget( 'a', self::IFRAME ), $this->widget( 'b', '<iframe src="https://example.com/b"></iframe>' ) ) );

		$this->assertCount( 1, $this->reports );
		$this->assertSame( array( null ), array_column( $this->reports[0]['widgets'], 'widget_id' ) );
	}

	public function test_sanitize_all_from_another_caller_never_calls_the_observer() {
		$calls  = 0;
		$styles = \SiteOrigin_Panels_Styles_Admin::single();
		$styles->observe_next_sanitize_all(
			function () use ( &$calls ) {
				$calls++;
			}
		);
		$styles->sanitize_all( array( 'widgets' => array() ) );
		$this->assertSame( 1, $calls, 'The next call takes the observer.' );

		$styles->sanitize_all( array( 'widgets' => array() ) );
		$this->assertSame( 1, $calls, 'Later calls do not see it.' );
	}
}

class MigrationRendererStub {
	public function render( $post_id = false, $enqueue_css = true, $panels_data = false, &$layout_data = array(), $is_preview = false ) {
		return '<div>rendered</div>';
	}
}

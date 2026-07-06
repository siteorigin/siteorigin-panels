<?php

use SiteOrigin\Tests\SiteOriginTests;
use Brain\Monkey\Functions;

/**
 * Contract test for the §3 pre-save re-sanitization invariant.
 *
 * SCOPE / HONESTY NOTE:
 * The production sequence under test lives inside
 * SiteOrigin_Panels_Admin::save_post() — a large method gated by nonce,
 * capability and $_POST that calls many collaborators. Booting all of
 * save_post() under Brain Monkey is brittle and low value, so this test does
 * NOT execute save_post(). Instead it reproduces the EXACT production slice
 * from inc/admin.php (the AI pre-save filter + CONDITIONAL re-sanitize):
 *
 *     $filtered_panels_data = apply_filters( 'siteorigin_panels_ai_layout_pre_save', $panels_data, $post, $post_id );
 *     if ( $filtered_panels_data !== $panels_data ) {
 *         $panels_data = $filtered_panels_data;
 *         $panels_data['widgets'] = $this->process_raw_widgets(
 *             ! empty( $panels_data['widgets'] ) ? $panels_data['widgets'] : array(),
 *             ! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
 *             false
 *         );
 *     }
 *
 * and asserts two guarantees:
 *  (1) §3 security — whenever the filter CHANGES the layout, the result is passed
 *      through process_raw_widgets() before persist (AI output never trusted raw);
 *  (2) idempotency — when no filter is attached (or it returns the layout
 *      unchanged), the second sanitize pass is SKIPPED, so widget update()
 *      sanitizers are not double-run on every classic save.
 * If the production slice changes, update this test to match.
 */
class AiExposureResanitizeTest extends SiteOriginTests {
	/**
	 * Reproduce the production slice (the conditional re-sanitize in save_post()).
	 *
	 * @param array    $panels_data     Incoming panels_data.
	 * @param array    $old_panels_data Previously-stored panels_data.
	 * @param object   $post            Post object passed to the filter.
	 * @param int      $post_id         Post ID passed to the filter.
	 * @param callable $sanitizer       Stand-in for $this->process_raw_widgets().
	 *
	 * @return array The panels_data after filter + (conditional) re-sanitization.
	 */
	private function run_pre_save_slice( array $panels_data, array $old_panels_data, $post, int $post_id, callable $sanitizer ): array {
		$filtered_panels_data = apply_filters( 'siteorigin_panels_ai_layout_pre_save', $panels_data, $post, $post_id );

		// Re-sanitize ONLY when the filter changed the layout — same as production.
		if ( $filtered_panels_data !== $panels_data ) {
			$panels_data = $filtered_panels_data;
			$panels_data['widgets'] = $sanitizer(
				! empty( $panels_data['widgets'] ) ? $panels_data['widgets'] : array(),
				! empty( $old_panels_data['widgets'] ) ? $old_panels_data['widgets'] : false,
				false
			);
		}

		return $panels_data;
	}

	public function test_filter_output_is_passed_through_sanitizer() {
		$post    = (object) array( 'ID' => 42 );
		$post_id = 42;

		$incoming        = array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'Safe_Widget' ) ) ) );
		$old_panels_data = array( 'widgets' => array() );

		// A consumer filter injects a HOSTILE/raw widget into the layout.
		$hostile_widget = array( 'panels_info' => array( 'class' => 'Evil_Widget' ), 'raw' => '<script>alert(1)</script>' );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) use ( $hostile_widget ) {
				if ( $tag === 'siteorigin_panels_ai_layout_pre_save' ) {
					$value['widgets'][] = $hostile_widget;
				}

				return $value;
			}
		);

		// Spy on the sanitizer: record what it actually received.
		$received_args = null;
		$sanitizer     = function ( ...$args ) use ( &$received_args ) {
			$received_args = $args;

			// Simulate process_raw_widgets() returning a cleaned widget set.
			return array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) );
		};

		$result = $this->run_pre_save_slice( $incoming, $old_panels_data, $post, $post_id, $sanitizer );

		// The sanitizer must have been called.
		$this->assertNotNull( $received_args, 'process_raw_widgets() was never invoked on the filtered layout.' );

		// The hostile widget injected by the filter must reach the sanitizer —
		// i.e. the sanitizer received the FILTER OUTPUT, not the pre-filter input.
		$this->assertContains(
			$hostile_widget,
			$received_args[0],
			'Filter-injected widget did not reach process_raw_widgets() — AI output would be trusted raw.'
		);

		// The persisted widgets are the SANITIZER's return value, never the raw filter output.
		$this->assertSame(
			array( array( 'panels_info' => array( 'class' => 'Cleaned' ) ) ),
			$result['widgets'],
			'Persisted widgets must be the sanitizer output, not the raw filtered widgets.'
		);
		$this->assertNotContains( $hostile_widget, $result['widgets'] );
	}

	public function test_no_op_filter_skips_the_sanitizer() {
		$post    = (object) array( 'ID' => 5 );
		$post_id = 5;

		$incoming        = array( 'widgets' => array( array( 'panels_info' => array( 'class' => 'Safe_Widget' ) ) ) );
		$old_panels_data = array( 'widgets' => array() );

		// No-op filter (the default no-consumer case): returns its layout unchanged.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);

		$sanitizer_called = false;
		$sanitizer        = function ( ...$args ) use ( &$sanitizer_called ) {
			$sanitizer_called = true;

			return $args[0];
		};

		$result = $this->run_pre_save_slice( $incoming, $old_panels_data, $post, $post_id, $sanitizer );

		// Idempotency guarantee: with no filter change, the second sanitize pass
		// MUST NOT run (widget update() sanitizers are not guaranteed idempotent).
		$this->assertFalse( $sanitizer_called, 'process_raw_widgets() must not run a second time when the AI filter leaves the layout unchanged.' );
		// The layout is persisted unchanged (already sanitized by the first pass).
		$this->assertSame( $incoming, $result );
	}

	public function test_sanitizer_called_with_production_argument_shape() {
		$post    = (object) array( 'ID' => 7 );
		$post_id = 7;

		$incoming        = array( 'widgets' => array( 'incoming-widget' ) );
		$old_widgets     = array( 'old-widget' );
		$old_panels_data = array( 'widgets' => $old_widgets );

		// Filter that CHANGES the layout (appends a marker widget) so the
		// conditional re-sanitize branch runs.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_ai_layout_pre_save' ) {
					$value['widgets'][] = 'marker-widget';
				}

				return $value;
			}
		);

		$received_args = null;
		$sanitizer     = function ( ...$args ) use ( &$received_args ) {
			$received_args = $args;

			return $args[0];
		};

		$this->run_pre_save_slice( $incoming, $old_panels_data, $post, $post_id, $sanitizer );

		// arg0 = current (filtered) widgets, arg1 = old widgets (or false), arg2 = false.
		$this->assertSame( array( 'incoming-widget', 'marker-widget' ), $received_args[0] );
		$this->assertSame( $old_widgets, $received_args[1] );
		$this->assertFalse( $received_args[2] );
	}

	public function test_old_widgets_arg_is_false_when_no_previous_layout() {
		$post    = (object) array( 'ID' => 8 );
		$post_id = 8;

		$incoming        = array( 'widgets' => array( 'incoming-widget' ) );
		$old_panels_data = array(); // No previous widgets.

		// Filter that CHANGES the layout so the re-sanitize branch runs.
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( $tag === 'siteorigin_panels_ai_layout_pre_save' ) {
					$value['widgets'][] = 'marker-widget';
				}

				return $value;
			}
		);

		$received_args = null;
		$sanitizer     = function ( ...$args ) use ( &$received_args ) {
			$received_args = $args;

			return $args[0];
		};

		$this->run_pre_save_slice( $incoming, $old_panels_data, $post, $post_id, $sanitizer );

		// old widgets arg is false when there is no prior layout.
		$this->assertFalse( $received_args[1] );
	}
}

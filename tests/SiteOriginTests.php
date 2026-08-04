<?php

namespace SiteOrigin\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as FrameworkTestCase;
use Brain\Monkey\Functions;

/**
 * Class SiteOriginTests
 *
 * Base test class for SiteOrigin functionality.
 * Provides common setup and teardown methods for mocking WordPress functions.
 * This class uses Brain Monkey to mock WordPress functions and PHPUnit
 * for testing.
 */
class SiteOriginTests extends FrameworkTestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Set up the test environment.
	 *
	 * Initializes Brain Monkey and mocks common WordPress functions.
	 * This method is called before each test is executed.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->mock_general_wp_functions();
	}

	/**
	 * Mock general WordPress functions.
	 */
	private function mock_general_wp_functions() {
		// Mock the '__' function to return its argument.
		Functions\when( '__' )->returnArg();

		// Mock the 'shortcode_atts' function to merge attributes.
		Functions\when( 'shortcode_atts' )
			->alias(
				function ( $pairs, $atts, $shortcode ) {
					return array_merge( $pairs, $atts );
				}
			);

		// Add basic escaping functions. These aren't equivalent to
		// WordPress's functions, but they will suffice for testing
		// purposes.
		Functions\when( 'esc_attr' )
			->alias(
				function ( $value ) {
					return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
				}
			);

		Functions\when( 'esc_html' )
			->alias(
				function ( $value ) {
					return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
				}
			);
	}

	/**
	 * Mock the logged-in state of the user.
	 *
	 * @param bool $logged_in Whether the user is logged in.
	 */
	public function mock_logged_in( $logged_in = false ) {
		Functions\expect( 'is_user_logged_in' )
			->once()
			->andReturn( $logged_in );
	}

	/**
	 * Mock the roles of the current user.
	 *
	 * @param array $roles Array of roles assigned to the user.
	 * @param bool  $logged_in Whether the user is logged in.
	 */
	public function mock_user_roles( $roles = array(), $logged_in = true ) {
		$this->mock_logged_in( $logged_in );

		Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( (object) array( 'roles' => $roles ) );
	}

	/**
	 * Tear down the test environment.
	 *
	 * Cleans up Brain Monkey and calls the parent teardown method.
	 * This method is called after each test is executed.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

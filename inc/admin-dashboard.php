<?php

class SiteOrigin_Panels_Admin_Dashboard {

	function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widgets' ), 15 );
		add_action( 'admin_print_styles', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * @return SiteOrigin_Panels_Admin_Dashboard
	 */
	public static function single() {
		static $single;
		return empty( $single ) ? $single = new self() : $single;
	}

	/**
	 * Register the dashboard widget
	 */
	public function register_dashboard_widgets(){
		if( function_exists( 'wp_dashboard_primary_output' ) ) {
			wp_add_dashboard_widget( 'so-dashboard-news', __( 'SiteOrigin Page Builder News', 'siteorigin-panels' ), array(
				$this,
				'dashboard_overview_widget'
			) );

			// Move Page Builder widget to the top
			global $wp_meta_boxes;

			$dashboard = $wp_meta_boxes['dashboard']['normal']['core'];
			$ours      = array( 'so-dashboard-news' => $dashboard['so-dashboard-news'] );

			$wp_meta_boxes['dashboard']['normal']['core'] = array_merge( $ours, $dashboard ); // WPCS: override ok.
		}
	}

	/**
	 * Enqueue the dashboard styles
	 */
	public function enqueue_admin_styles( $page ){
		$screen = get_current_screen();
		if( ! empty( $screen ) && $screen->id == 'dashboard' ) {
			wp_enqueue_style(
				'so-panels-dashboard',
				siteorigin_panels_url( 'css/dashboard.css' ),
				array( 'wp-color-picker' ),
				SITEORIGIN_PANELS_VERSION
			);
		}
	}

	/**
	 * Display the actual widget
	 */
	public function dashboard_overview_widget(){
		$feeds = array(
			array(
				'url'          => 'https://siteorigin.com/feed/',
				'items'        => 4,
				'show_summary' => 0,
				'show_author'  => 0,
				'show_date'    => 1,
			),
		);

		wp_dashboard_primary_output( 'so_dashboard_widget_news', $feeds );

		if( function_exists( 'wp_print_community_events_markup' ) ) {
			?>
			<p class="community-events-footer">
				<?php
				printf(
					'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
					esc_url( 'https://siteorigin.com/blog/' ),
					__( 'Blog', 'siteorigin-panels' ),
					/* translators: accessibility text */
					__( '(opens in a new window)', 'siteorigin-panels' )
				);
				echo ' | ';

				if( class_exists( 'SiteOrigin_Premium' ) ) {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-email-alt"></span></a>',
						esc_url( 'mailto:support@siteorigin.com' ),
						__( 'Email Support', 'siteorigin-panels' ),
						/* translators: accessibility text */
						__( '(email SiteOrigin support)', 'siteorigin-panels' )
					);
				}
				else {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
						esc_url( 'https://siteorigin.com/thread/' ),
						__( 'Support Forum', 'siteorigin-panels' ),
						/* translators: accessibility text */
						__( '(opens in a new window)', 'siteorigin-panels' )
					);
				}

				if ( SiteOrigin_Panels::display_premium_teaser() ) {
					echo ' | ';
					printf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer" style="color: #2ebd59">%2$s <span class="screen-reader-text">%3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>',
						/* translators: If a Rosetta site exists (e.g. https://es.wordpress.org/news/), then use that. Otherwise, leave untranslated. */
						esc_url( 'https://siteorigin.com/downloads/premium/' ),
						__( 'Get Premium', 'siteorigin-panels' ),
						/* translators: accessibility text */
						__( '(opens in a new window)', 'siteorigin-panels' )
					);
				}
				?>
			</p>
			<?php
		}
	}
}

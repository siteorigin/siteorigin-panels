<?php
class SiteOrigin_Panels_Compat_ACF_Widgets {
	public function __construct() {
		add_action( 'admin_print_scripts-post-new.php', array( $this, 'enqueue_assets' ), 100 );
		add_action( 'admin_print_scripts-post.php', array( $this, 'enqueue_assets' ), 100 );

		// Widget Form Rendering.
		add_action( 'siteorigin_panels_before_widget_form', array( $this, 'store_acf_widget_fields_instance' ), 10, 3 );
		add_filter( 'acf/pre_load_value', array( $this, 'load_panels_widget_field_data' ), 10, 3 );

		// Widget Saving inside of Page Builder.
		add_filter( 'widget_update_callback', array( $this, 'acf_override_instance' ), 10, 4 );
	}

	public static function single() {
		static $single;
		
		return empty( $single ) ? $single = new self() : $single;
	}

	public function enqueue_assets() {
		if ( SiteOrigin_Panels_Admin::is_admin() ) {
			wp_enqueue_script(
				'so-panels-acf-widgets-compat',
				siteorigin_panels_url( 'compat/js/acf-widgets' . SITEORIGIN_PANELS_JS_SUFFIX . '.js' ),
				array(
					'jquery',
					'so-panels-admin',
				),
				SITEORIGIN_PANELS_VERSION,
				true
			);
		}
	}

	/**
	 * Extracts the widgets ACF fields, and ACF stores them and the instance.
	 *
	 * @param $the_widget The WP Widget Object.
	 * @param $instance The Panels widget instance.
	 */
	public function store_acf_widget_fields_instance( $the_widget, $instance ) {
		if ( ! empty( $instance['acf'] ) ) {
			$field_groups = acf_get_field_groups( array(
				'widget' => $the_widget->id_base,
			) );

			if ( ! empty( $field_groups ) ) {
				// Get all fields, and merge them into a single array.
				foreach( $field_groups as $field_group ) {
					$fields[] = acf_get_fields( $field_group );
				}
				$fields = call_user_func_array('array_merge', $fields );

				acf_register_store( 'so_fields', $fields );
				acf_register_store( 'so_widget_instance', $instance['acf'] );
			}
		}
	}

	/**
	 * Sets the ACF Widget Field values based on instance data.
	 *
	 * @param $value
	 * @param $post_id
	 * @param $widget_field The ACF object for the field being processed.
	 *
	 * @return string if set, the user defined field value.
	 */
	public function load_panels_widget_field_data( $value, $post_id, $widget_field ) {
		$fields = acf_get_store( 'so_fields' );
		$instance = acf_get_store( 'so_widget_instance' );

		if ( ! empty( $fields ) ) {
			foreach ( $fields->data as $field ) {
				if ( $field['key'] == $widget_field['key'] && ! empty( $instance->data[ $field['key'] ] ) ) {
					return $instance->data[ $field['key'] ];
				}
			}
		}
	}

	/**
	 * Restores initial ACF form data to prevent saving issue in non SOWB widgets.
	 *
	 * @param $instance The updated widget settings.
	 * @param $new_instance An array of new settings.
	 * @param $old_instance An array of old settings.
	 * @param $this The current widget instance.
	 *
	 */
	public function acf_override_instance( $instance, $widget, $old_widget, $the_widget ) {
		// Ensure widget update is from Page Builder and there's ACF data present.
		if ( ! empty( $widget['panels_info'] ) && ! empty( $instance['acf'] ) ) {
			$instance['acf'] = $widget['acf'];
		}

		return $instance;
	}
}

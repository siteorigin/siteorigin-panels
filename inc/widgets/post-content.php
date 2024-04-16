<?php

/**
 * Widget for displaying content from a post
 *
 * Class SiteOrigin_Panels_Widgets_PostContent
 */
class SiteOrigin_Panels_Widgets_PostContent extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'siteorigin-panels-post-content',
			__( 'Post Content', 'siteorigin-panels' ),
			array(
				'description' => __( 'Displays content from the current post.', 'siteorigin-panels' ),
			)
		);
	}

	public function widget( $args, $instance ) {
		if ( is_admin() ) {
			return;
		}

		echo $args['before_widget'];
		$content = apply_filters( 'siteorigin_panels_widget_post_content', $this->default_content( $instance['type'] ) );
		echo wp_kses_post( $content );
		echo $args['after_widget'];
	}

	/**
	 * The default content for post types
	 *
	 * @return string
	 */
	public function default_content( $type ) {
		global $post;

		if ( empty( $post ) ) {
			return;
		}

		switch( $type ) {
			case 'title':
				return '<h1 class="entry-title">' . wp_kses_post( $post->post_title ) . '</h1>';

			case 'featured':
				if ( ! has_post_thumbnail() ) {
					return '';
				}

				return '<div class="featured-image">' .
					get_the_post_thumbnail( $post->ID )
					. '</div>';

			case 'post_content':
				if ( in_the_loop() ) {
					esc_html_e( 'This widget should not be used in the main post area.', 'siteorigin-panels' );
					return;
				}

				if ( get_post_meta( $post->ID, 'panels_data', true ) ) {
					$content = SiteOrigin_Panels::renderer()->render( $post->ID );
				} else {
					$content = wp_kses_post( apply_filters( 'the_content', $post->post_content ) );
				}

				return '<div class="entry-content">' . $content . '</div>';
		}
	}

	public function update( $new, $old ) {
		return $new;
	}

	public function form( $instance ) {
		$instance = wp_parse_args( $instance, array(
			'type' => '',
		) );

		$types = apply_filters( 'siteorigin_panels_widget_post_content_types', array(
			'' => __( 'None', 'siteorigin-panels' ),
			'title' => __( 'Title', 'siteorigin-panels' ),
			'featured' => __( 'Featured Image', 'siteorigin-panels' ),
			'post_content' => __( 'Content', 'siteorigin-panels' ),
		) );

		?>
		<div class="siteorigin-widget-content">
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'type' ) ); ?>"><?php esc_html_e( 'Display Content', 'siteorigin-panels' ); ?></label>
				<select id="<?php echo esc_attr( $this->get_field_id( 'type' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'type' ) ); ?>" class="siteorigin-widget-field">
					<?php foreach ( $types as $type_id => $title ) { ?>
						<option
							value="<?php echo esc_attr( $type_id ); ?>"
							<?php selected( $type_id, $instance['type'] ); ?>
						><?php echo esc_html( $title ); ?></option>
					<?php } ?>
				</select>
			</p>
		</div>
		<?php
	}
}

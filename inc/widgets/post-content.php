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
		echo $content;
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
				return '<h1 class="entry-title">' . $post->post_title . '</h1>';

			case 'content':
				return '<div class="entry-content">' . wpautop( $post->post_content ) . '</div>';

			case 'featured':
				if ( !has_post_thumbnail() ) {
					return '';
				}

				return '<div class="featured-image">' . get_the_post_thumbnail( $post->ID ) . '</div>';
			default:
				return '';
		}
	}

	public function update( $new, $old ) {
		return $new;
	}

	public function form( $instance ) {
		$instance = wp_parse_args( $instance, array(
			'type' => 'content',
		) );

		$types = apply_filters( 'siteorigin_panels_widget_post_content_types', array(
			'' => __( 'None', 'siteorigin-panels' ),
			'title' => __( 'Title', 'siteorigin-panels' ),
			'featured' => __( 'Featured Image', 'siteorigin-panels' ),
		) );

		?>
		<div class="siteorigin-widget-content">
			<p>
				<label for="<?php echo $this->get_field_id( 'type' ); ?>"><?php _e( 'Display Content', 'siteorigin-panels' ); ?></label>
				<select id="<?php echo $this->get_field_id( 'type' ); ?>" name="<?php echo $this->get_field_name( 'type' ); ?>" class="siteorigin-widget-field">
					<?php foreach ( $types as $type_id => $title ) { ?>
						<option value="<?php echo esc_attr( $type_id ); ?>" <?php selected( $type_id, $instance['type'] ); ?>><?php echo esc_html( $title ); ?></option>
					<?php } ?>
				</select>
			</p>
		</div>
		<?php
	}
}

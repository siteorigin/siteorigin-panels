<?php

/**
 * This widget give you the full Page Builder interface inside a widget. Fully nestable.
 *
 * Class SiteOrigin_Panels_Widgets_Builder
 */
class SiteOrigin_Panels_Widgets_Layout extends WP_Widget {
	function __construct() {
		parent::__construct(
			'siteorigin-panels-builder',
			// TRANSLATORS: This is the name of a widget
			__( 'Layout Builder', 'siteorigin-panels' ),
			array(
				'description' => __( 'A complete SiteOrigin Page Builder layout as a widget.', 'siteorigin-panels' ),
				'panels_title' => false,
			),
			array(
			)
		);
	}

	function widget($args, $instance) {
		if( empty($instance['panels_data']) ) return;

		if( is_string( $instance['panels_data'] ) )
			$instance['panels_data'] = json_decode( $instance['panels_data'], true );
		if(empty($instance['panels_data']['widgets'])) return;

		if( empty( $instance['builder_id'] ) ) $instance['builder_id'] = uniqid();

		echo $args['before_widget'];
		echo siteorigin_panels_render( 'w'.$instance['builder_id'], true, $instance['panels_data'] );
		echo $args['after_widget'];
	}

	function update($new, $old) {
		$new['builder_id'] = uniqid();
		return $new;
	}

	function form($instance){
		$instance = wp_parse_args($instance, array(
			'panels_data' => '',
			'builder_id' => uniqid(),
		) );

		if( !is_string( $instance['panels_data'] ) ) $instance['panels_data'] = json_encode( $instance['panels_data'] );

		?>
		<div class="siteorigin-page-builder-widget siteorigin-panels-builder" id="siteorigin-page-builder-widget-<?php echo esc_attr( $instance['builder_id'] ) ?>" data-builder-id="<?php echo esc_attr( $instance['builder_id'] ) ?>">
			<p>
				<a href="#" class="button-secondary siteorigin-panels-display-builder" ><?php _e('Open Builder', 'siteorigin-panels') ?></a>
			</p>

			<input type="hidden" data-panels-filter="json_parse" value="" class="panels-data" name="<?php echo $this->get_field_name('panels_data') ?>" id="<?php echo $this->get_field_id('panels_data') ?>" />
			<script type="text/javascript">
				document.getElementById('<?php echo $this->get_field_id('panels_data') ?>').value = decodeURIComponent("<?php echo rawurlencode( $instance['panels_data'] ); ?>");
			</script>

			<input type="hidden" value="<?php echo esc_attr( $instance['builder_id'] ) ?>" name="<?php echo $this->get_field_name('builder_id') ?>" />
		</div>
		<script type="text/javascript">
			if(typeof jQuery.fn.soPanelsSetupBuilderWidget != 'undefined' && !jQuery('body').hasClass('wp-customizer')) {
				jQuery( "#siteorigin-page-builder-widget-<?php echo esc_attr( $instance['builder_id'] ) ?>").soPanelsSetupBuilderWidget();
			}
		</script>
		<?php
	}

}

/**
 * Widget for displaying content from a post
 *
 * Class SiteOrigin_Panels_Widgets_PostContent
 */
class SiteOrigin_Panels_Widgets_PostContent extends WP_Widget {
	function __construct() {
		parent::__construct(
			'siteorigin-panels-post-content',
			__( 'Post Content', 'siteorigin-panels' ),
			array(
				'description' => __( 'Displays content from the current post.', 'siteorigin-panels' ),
			)
		);
	}

	function widget( $args, $instance ) {
		if( is_admin() ) return;

		echo $args['before_widget'];
		$content = apply_filters('siteorigin_panels_widget_post_content', $this->default_content($instance['type']));
		echo $content;
		echo $args['after_widget'];
	}

	/**
	 * The default content for post types
	 * @param $type
	 * @return string
	 */
	function default_content($type){
		global $post;
		if(empty($post)) return;

		switch($type) {
			case 'title' :
				return '<h1 class="entry-title">' . $post->post_title . '</h1>';
			case 'content' :
				return '<div class="entry-content">' . wpautop($post->post_content) . '</div>';
			case 'featured' :
				if(!has_post_thumbnail()) return '';
				return '<div class="featured-image">' . get_the_post_thumbnail($post->ID) . '</div>';
			default :
				return '';
		}
	}

	function update($new, $old){
		return $new;
	}

	function form( $instance ) {
		$instance = wp_parse_args($instance, array(
			'type' => 'content',
		));

		$types = apply_filters('siteorigin_panels_widget_post_content_types', array(
			'' => __('None', 'siteorigin-panels'),
			'title' => __('Title', 'siteorigin-panels'),
			'featured' => __('Featured Image', 'siteorigin-panels'),
		));

		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'type' ) ?>"><?php _e( 'Display Content', 'siteorigin-panels' ) ?></label>
			<select id="<?php echo $this->get_field_id( 'type' ) ?>" name="<?php echo $this->get_field_name( 'type' ) ?>">
				<?php foreach ($types as $type_id => $title) : ?>
					<option value="<?php echo esc_attr($type_id) ?>" <?php selected($type_id, $instance['type']) ?>><?php echo esc_html($title) ?></option>
				<?php endforeach ?>
			</select>
		</p>
	<?php
	}
}

/**
 * Display a loop of posts.
 *
 * Class SiteOrigin_Panels_Widgets_PostLoop
 */
class SiteOrigin_Panels_Widgets_PostLoop extends WP_Widget{
	function __construct() {
		parent::__construct(
			'siteorigin-panels-postloop',
			__( 'Post Loop', 'siteorigin-panels' ),
			array(
				'description' => __( 'Displays a post loop.', 'siteorigin-panels' ),
			)
		);
	}

	/**
	 * @param array $args
	 * @param array $instance
	 */
	function widget( $args, $instance ) {
		if( empty( $instance['template'] ) ) return;
		if( is_admin() ) return;

		static $depth = 0;
		$depth++;
		if( $depth > 1 ) {
			// Because of infinite loops, don't render this post loop if its inside another
			$depth--;
			echo $args['before_widget'].$args['after_widget'];
			return;
		}

		$query_args = $instance;
		//If Widgets Bundle post selector is available and a posts query has been saved using it.
		if ( function_exists( 'siteorigin_widget_post_selector_process_query' ) && ! empty( $instance['posts'] ) ) {
			$query_args = siteorigin_widget_post_selector_process_query($instance['posts']);
			$instance['additional'] = $query_args['additional'];
		}
		else {
			if ( ! empty( $instance['posts'] ) ) {
				$query_args = wp_parse_args( $instance['posts'], $query_args );
			}

			switch($query_args['sticky']){
				case 'ignore' :
					$query_args['ignore_sticky_posts'] = 1;
					break;
				case 'only' :
					$query_args['post__in'] = get_option( 'sticky_posts' );
					break;
				case 'exclude' :
					$query_args['post__not_in'] = get_option( 'sticky_posts' );
					break;
			}
			unset($query_args['template']);
			unset($query_args['title']);
			unset($query_args['sticky']);
		}
		$query_args = wp_parse_args($query_args['additional'], $query_args);
		unset($query_args['additional']);

		global $wp_rewrite;

		if( $wp_rewrite->using_permalinks() ) {

			if( get_query_var('paged') ) {
				// When the widget appears on a sub page.
				$query_args['paged'] = get_query_var('paged');
			}
			elseif( strpos( $_SERVER['REQUEST_URI'], '/page/' ) !== false ) {
				// When the widget appears on the home page.
				preg_match('/\/page\/([0-9]+)\//', $_SERVER['REQUEST_URI'], $matches);
				if(!empty($matches[1])) $query_args['paged'] = intval($matches[1]);
				else $query_args['paged'] = 1;
			}
			else $query_args['paged'] = 1;
		}
		else {
			// Get current page number when we're not using permalinks
			$paged = filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_NUMBER_INT );
			$query_args['paged'] = $paged !== false ? $paged : 1;
		}

		// Exclude the current post to prevent possible infinite loop

		global $siteorigin_panels_current_post;

		if( !empty($siteorigin_panels_current_post) ){
			if(!empty($query_args['post__not_in'])){
				$query_args['post__not_in'][] = $siteorigin_panels_current_post;
			}
			else {
				$query_args['post__not_in'] = array( $siteorigin_panels_current_post );
			}
		}

		if( !empty($query_args['post__in']) && !is_array($query_args['post__in']) ) {
			$query_args['post__in'] = explode(',', $query_args['post__in']);
			$query_args['post__in'] = array_map('intval', $query_args['post__in']);
		}

		// Create the query
		query_posts($query_args);
		echo $args['before_widget'];

		// Filter the title
		$instance['title'] = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);
		if ( !empty( $instance['title'] ) ) {
			echo $args['before_title'] . $instance['title'] . $args['after_title'];
		}

		global $more; $old_more = $more; $more = empty($instance['more']);

		if(strpos('/'.$instance['template'], '/content') !== false) {
			while( have_posts() ) {
				the_post();
				locate_template($instance['template'], true, false);
			}
		}
		else {
			locate_template($instance['template'], true, false);
		}

		echo $args['after_widget'];

		// Reset everything
		wp_reset_query();
		$depth--;
	}

	/**
	 * Update the widget
	 *
	 * @param array $new
	 * @param array $old
	 * @return array
	 */
	function update($new, $old){
		$new['more'] = !empty( $new['more'] );
		return $new;
	}

	/**
	 * Get all the existing files
	 *
	 * @return array
	 */
	function get_loop_templates(){
		$templates = array();

		$template_files = array(
			'loop*.php',
			'*/loop*.php',
			'content*.php',
			'*/content*.php',
		);

		$template_dirs = array(get_template_directory(), get_stylesheet_directory());
		$template_dirs = array_unique($template_dirs);
		foreach($template_dirs  as $dir ){
			foreach($template_files as $template_file) {
				foreach((array) glob($dir.'/'.$template_file) as $file) {
					if( file_exists( $file ) ) $templates[] = str_replace($dir.'/', '', $file);
				}
			}
		}

		$templates = array_unique($templates);
		$templates = apply_filters('siteorigin_panels_postloop_templates', $templates);
		sort($templates);

		return $templates;
	}

	/**
	 * Display the form for the post loop.
	 *
	 * @param array $instance
	 * @return string|void
	 */
	function form( $instance ) {
		$instance = wp_parse_args($instance, array(
			'title' => '',
			'template' => 'loop.php',

			// Query args
			'post_type' => 'post',
			'posts_per_page' => '',

			'order' => 'DESC',
			'orderby' => 'date',

			'sticky' => '',

			'additional' => '',
			'more' => false,
		));

		$templates = $this->get_loop_templates();
		if( empty($templates) ) {
			?><p><?php _e("Your theme doesn't have any post loops.", 'siteorigin-panels') ?></p><?php
			return;
		}

		?>
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ) ?>"><?php _e( 'Title', 'siteorigin-panels' ) ?></label>
			<input type="text" class="widefat" name="<?php echo $this->get_field_name( 'title' ) ?>" id="<?php echo $this->get_field_id( 'title' ) ?>" value="<?php echo esc_attr( $instance['title'] ) ?>">
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('template') ?>"><?php _e('Template', 'siteorigin-panels') ?></label>
			<select id="<?php echo $this->get_field_id( 'template' ) ?>" name="<?php echo $this->get_field_name( 'template' ) ?>">
				<?php foreach($templates as $template) : ?>
					<option value="<?php echo esc_attr($template) ?>" <?php selected($instance['template'], $template) ?>>
						<?php
						$headers = get_file_data( locate_template($template), array(
							'loop_name' => 'Loop Name',
						) );
						echo esc_html(!empty($headers['loop_name']) ? $headers['loop_name'] : $template);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('more') ?>"><?php _e('More Link ', 'siteorigin-panels') ?></label>
			<input type="checkbox" class="widefat" id="<?php echo $this->get_field_id( 'more' ) ?>" name="<?php echo $this->get_field_name( 'more' ) ?>" <?php checked( $instance['more'] ) ?> /><br/>
			<small><?php _e('If the template supports it, cut posts and display the more link.', 'siteorigin-panels') ?></small>
		</p>
		<?php

		// If the Widgets Bundle is installed and the post selector is available, use that.
		// Otherwise revert back to our own form fields.
		if ( function_exists( 'siteorigin_widget_post_selector_enqueue_admin_scripts' ) ) {
			siteorigin_widget_post_selector_enqueue_admin_scripts();
			$value = '';
			if ( ! empty( $instance['posts'] ) && ! is_array( $instance['posts'] ) ) {
				$value = $instance['posts'];
			}
			else if ( ! empty( $instance['post_type'] ) ) {
				$value .= 'post_type=' . $instance['post_type'];
				$value .= '&posts_per_page=' . $instance['posts_per_page'];
				$value .= '&order=' . $instance['order'];
				$value .= '&orderby=' . $instance['orderby'];
				$value .= '&sticky=' . $instance['sticky'];
				$value .= '&additional=' . $instance['additional'];
			}
			siteorigin_widget_post_selector_admin_form_field( $value, $this->get_field_name( 'posts' ) );
		}
		else {
			if ( ! empty( $instance['posts'] ) ) {
				$instance = wp_parse_args( $instance['posts'] , $instance );
				unset( $instance['posts'] );
				//unset post__in and taxonomies?
			}
			// Get all the loop template files
			$post_types = get_post_types(array('public' => true));
			$post_types = array_values($post_types);
			$post_types = array_diff($post_types, array('attachment', 'revision', 'nav_menu_item'));
			?>
			<p>
				<label for="<?php echo $this->get_field_id('post_type') ?>"><?php _e('Post Type', 'siteorigin-panels') ?></label>
				<select id="<?php echo $this->get_field_id( 'post_type' ) ?>" name="<?php echo $this->get_field_name( 'post_type' ) ?>" value="<?php echo esc_attr($instance['post_type']) ?>">
					<?php foreach($post_types as $type) : ?>
						<option value="<?php echo esc_attr($type) ?>" <?php selected($instance['post_type'], $type) ?>><?php echo esc_html($type) ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('posts_per_page') ?>"><?php _e('Posts Per Page', 'siteorigin-panels') ?></label>
				<input type="text" class="small-text" id="<?php echo $this->get_field_id( 'posts_per_page' ) ?>" name="<?php echo $this->get_field_name( 'posts_per_page' ) ?>" value="<?php echo esc_attr($instance['posts_per_page']) ?>" />
			</p>

			<p>
				<label <?php echo $this->get_field_id('orderby') ?>><?php _e('Order By', 'siteorigin-panels') ?></label>
				<select id="<?php echo $this->get_field_id( 'orderby' ) ?>" name="<?php echo $this->get_field_name( 'orderby' ) ?>" value="<?php echo esc_attr($instance['orderby']) ?>">
					<option value="none" <?php selected($instance['orderby'], 'none') ?>><?php esc_html_e('None', 'siteorigin-panels') ?></option>
					<option value="ID" <?php selected($instance['orderby'], 'ID') ?>><?php esc_html_e('Post ID', 'siteorigin-panels') ?></option>
					<option value="author" <?php selected($instance['orderby'], 'author') ?>><?php esc_html_e('Author', 'siteorigin-panels') ?></option>
					<option value="name" <?php selected($instance['orderby'], 'name') ?>><?php esc_html_e('Name', 'siteorigin-panels') ?></option>
					<option value="name" <?php selected($instance['orderby'], 'name') ?>><?php esc_html_e('Name', 'siteorigin-panels') ?></option>
					<option value="date" <?php selected($instance['orderby'], 'date') ?>><?php esc_html_e('Date', 'siteorigin-panels') ?></option>
					<option value="modified" <?php selected($instance['orderby'], 'modified') ?>><?php esc_html_e('Modified', 'siteorigin-panels') ?></option>
					<option value="parent" <?php selected($instance['orderby'], 'parent') ?>><?php esc_html_e('Parent', 'siteorigin-panels') ?></option>
					<option value="rand" <?php selected($instance['orderby'], 'rand') ?>><?php esc_html_e('Random', 'siteorigin-panels') ?></option>
					<option value="comment_count" <?php selected($instance['orderby'], 'comment_count') ?>><?php esc_html_e('Comment Count', 'siteorigin-panels') ?></option>
					<option value="menu_order" <?php selected($instance['orderby'], 'menu_order') ?>><?php esc_html_e('Menu Order', 'siteorigin-panels') ?></option>
					<option value="post__in" <?php selected($instance['orderby'], 'post__in') ?>><?php esc_html_e('Post In Order', 'siteorigin-panels') ?></option>
				</select>
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('order') ?>"><?php _e('Order', 'siteorigin-panels') ?></label>
				<select id="<?php echo $this->get_field_id( 'order' ) ?>" name="<?php echo $this->get_field_name( 'order' ) ?>" value="<?php echo esc_attr($instance['order']) ?>">
					<option value="DESC" <?php selected($instance['order'], 'DESC') ?>><?php esc_html_e('Descending', 'siteorigin-panels') ?></option>
					<option value="ASC" <?php selected($instance['order'], 'ASC') ?>><?php esc_html_e('Ascending', 'siteorigin-panels') ?></option>
				</select>
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('sticky') ?>"><?php _e('Sticky Posts', 'siteorigin-panels') ?></label>
				<select id="<?php echo $this->get_field_id( 'sticky' ) ?>" name="<?php echo $this->get_field_name( 'sticky' ) ?>" value="<?php echo esc_attr($instance['sticky']) ?>">
					<option value="" <?php selected($instance['sticky'], '') ?>><?php esc_html_e('Default', 'siteorigin-panels') ?></option>
					<option value="ignore" <?php selected($instance['sticky'], 'ignore') ?>><?php esc_html_e('Ignore Sticky', 'siteorigin-panels') ?></option>
					<option value="exclude" <?php selected($instance['sticky'], 'exclude') ?>><?php esc_html_e('Exclude Sticky', 'siteorigin-panels') ?></option>
					<option value="only" <?php selected($instance['sticky'], 'only') ?>><?php esc_html_e('Only Sticky', 'siteorigin-panels') ?></option>
				</select>
			</p>

			<p>
				<label for="<?php echo $this->get_field_id('additional') ?>"><?php _e('Additional ', 'siteorigin-panels') ?></label>
				<input type="text" class="widefat" id="<?php echo $this->get_field_id( 'additional' ) ?>" name="<?php echo $this->get_field_name( 'additional' ) ?>" value="<?php echo esc_attr($instance['additional']) ?>" />
				<small>
					<?php
					echo preg_replace(
						'/1\{ *(.*?) *\}/',
						'<a href="http://codex.wordpress.org/Function_Reference/query_posts">$1</a>',
						__('Additional query arguments. See 1{query_posts}.', 'siteorigin-panels')
					)
					?>
				</small>
			</p>
			<?php
		}
	}
}

/**
 * Register the widgets.
 */
function siteorigin_panels_basic_widgets_init(){
	register_widget('SiteOrigin_Panels_Widgets_PostContent');
	register_widget('SiteOrigin_Panels_Widgets_PostLoop');
	register_widget('SiteOrigin_Panels_Widgets_Layout');
}
add_action('widgets_init', 'siteorigin_panels_basic_widgets_init');
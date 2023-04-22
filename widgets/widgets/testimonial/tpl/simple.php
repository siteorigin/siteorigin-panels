<div class="testimonial-image-wrapper">
	<img src="<?php echo esc_url( $instance['image'] ); ?>" />
</div>

<div class="text">
	<?php echo wpautop( wp_kses_post( $instance['text'] ) ); ?>
	<h5 class="testimonial-name">
		<?php if ( ! empty( $instance['url'] ) ) { ?><a href="<?php echo esc_url( $instance['url'] ); ?>"><?php } ?>
			<?php echo esc_html( $instance['name'] ); ?>
		<?php if ( ! empty( $instance['url'] ) ) { ?></a><?php } ?>
	</h5>
	<small class="testimonial-location"><?php echo esc_html( $instance['location'] ); ?></small>
</div>

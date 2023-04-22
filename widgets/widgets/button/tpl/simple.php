<a href="<?php echo esc_url( $instance['url'] ); ?>" <?php if ( ! empty( $instance['new_window'] ) ) {
	echo 'target="_blank" rel="noopener noreferrer"';
} ?>>
	<?php echo esc_html( $instance['text'] ); ?>
</a>

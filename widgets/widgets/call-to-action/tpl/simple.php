<h2 class="title"><?php echo esc_html( $instance['title'] ); ?></h2>
<h5 class="subtitle"><?php echo esc_html( $instance['subtitle'] ); ?></h5>
<?php $this->sub_widget( 'button', array( 'text' => $instance['button_text'], 'url' => $instance['button_url'], 'new_window' => $instance['button_new_window'], 'origin_style' => $instance['origin_style_button'] ) ); ?>
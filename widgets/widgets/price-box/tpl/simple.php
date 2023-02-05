<h2><?php echo esc_html( $instance['title'] ); ?></h2>
<h4><?php echo esc_html( $instance['price'] ); ?><span> /<?php echo esc_html( $instance['per'] ); ?></span></h4>
<p class="information"><?php echo wp_kses_post( $instance['information'] ); ?></p>

<?php

$this->sub_widget( 'list', array( 'title' => '', 'text' => $instance['features'], 'origin_style' => $instance['origin_style_list'] ) );
$this->sub_widget( 'button', array(
	'text' => $instance['button_text'],
	'url' => $instance['button_url'],
	'align' => 'center',
	'origin_style' => $instance['origin_style_button'],
	'new_window' => ! empty( $instance['button_new_window'] ),
) );

?>

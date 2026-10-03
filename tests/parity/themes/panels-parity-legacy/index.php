<?php
/**
 * The one template of the test theme.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<main id="content">
		<?php
		while ( have_posts() ) {
			the_post();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<h1><?php the_title(); ?></h1>
				<?php the_content(); ?>
			</article>
			<?php
		}
		?>
	</main>
	<aside id="sidebar">
		<?php dynamic_sidebar( 'panels-parity-legacy-sidebar' ); ?>
	</aside>
	<?php wp_footer(); ?>
</body>
</html>

<?php
/**
 * Export every Page Builder layout of a site for a private parity corpus run.
 *
 *   wp eval-file tests/parity/tools/export-corpus.php <out-dir>
 *
 * Read only: nothing on the site changes. Writes one JSON file per layout:
 *   { key, storage: 'meta'|'block'|'widget', serialized_b64 | post_content_b64 }
 * Meta rows and post content are the stored bytes, base64 encoded as they are. A widget is its
 * instance from the stored widget option.
 *
 * The output holds site content. It refuses a directory inside a git work tree, so a corpus is never
 * added to a repository by mistake. Keep corpora outside the repository and outside CI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$out_dir = isset( $args[0] ) ? $args[0] : '';

if ( $out_dir === '' ) {
	fwrite( STDERR, "Usage: wp eval-file export-corpus.php <out-dir>\n" );
	exit( 1 );
}

// Refuse a directory inside a git work tree. Checked before the directory is made.
$check = $out_dir;

while ( ! file_exists( $check ) && dirname( $check ) !== $check ) {
	$check = dirname( $check );
}

for ( $dir = realpath( $check ); $dir && $dir !== dirname( $dir ); $dir = dirname( $dir ) ) {
	if ( file_exists( $dir . '/.git' ) ) {
		fwrite( STDERR, "$out_dir is inside the git work tree $dir. Choose a directory outside any repository.\n" );
		exit( 1 );
	}
}

if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0700, true ) ) {
	fwrite( STDERR, "Cannot make $out_dir\n" );
	exit( 1 );
}
$out_dir = realpath( $out_dir );

global $wpdb;
$written = array(
	'meta'   => 0,
	'block'  => 0,
	'widget' => 0,
);

$write = function ( $key, $record ) use ( $out_dir, &$written ) {
	file_put_contents( $out_dir . '/' . $key . '.json', wp_json_encode( $record ) );
	$written[ $record['storage'] ]++;
};

// Layouts in post meta (the classic editor builder, the custom home page). Revisions are left out.
$rows = $wpdb->get_results(
	"SELECT m.meta_id, m.post_id, m.meta_value FROM {$wpdb->postmeta} m
	JOIN {$wpdb->posts} p ON p.ID = m.post_id
	WHERE m.meta_key = 'panels_data' AND p.post_type <> 'revision'
	ORDER BY m.meta_id",
	ARRAY_A
);

foreach ( $rows as $row ) {
	$write(
		'meta-' . $row['post_id'] . '-' . $row['meta_id'],
		array(
			'key'            => 'meta-' . $row['post_id'] . '-' . $row['meta_id'],
			'storage'        => 'meta',
			'serialized_b64' => base64_encode( $row['meta_value'] ),
		)
	);
}

// Layout Blocks in post content.
$rows = $wpdb->get_results(
	"SELECT ID, post_content FROM {$wpdb->posts}
	WHERE post_type <> 'revision' AND post_content LIKE '%wp:siteorigin-panels/layout-block%'
	ORDER BY ID",
	ARRAY_A
);

foreach ( $rows as $row ) {
	$write(
		'block-' . $row['ID'],
		array(
			'key'              => 'block-' . $row['ID'],
			'storage'          => 'block',
			'post_content_b64' => base64_encode( $row['post_content'] ),
		)
	);
}

// Layout Builder widgets in widget areas.
$instances = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'widget_siteorigin-panels-builder'" ) );

if ( is_array( $instances ) ) {
	foreach ( $instances as $number => $instance ) {
		if ( is_numeric( $number ) && is_array( $instance ) ) {
			$write(
				'widget-' . $number,
				array(
					'key'            => 'widget-' . $number,
					'storage'        => 'widget',
					'serialized_b64' => base64_encode( serialize( $instance ) ),
				)
			);
		}
	}
}

fwrite( STDOUT, sprintf( "Wrote %d meta, %d block and %d widget layouts to %s\n", $written['meta'], $written['block'], $written['widget'], $out_dir ) );

<?php
/**
 * Compare real-data snapshots taken by real-data-snapshot.php.
 *
 * Usage: php real-data-compare.php <base-1.json> <base-2.json> [<base-3.json> ...] <change.json>
 *
 * Every file but the last is a snapshot of the base checkout; the last is the change. Renders whose
 * hash differs between any two base snapshots are noise and are left out. More base snapshots catch
 * rarer noise. Everything
 * else that differs between base-1 and the change is reported: renders, the editor's widget list,
 * how each stored widget class resolves, whether its form says it is missing, and errors.
 * Exits 1 when anything outside the noise changed, 0 when nothing did.
 *
 * @package siteorigin-panels
 */

if ( PHP_SAPI !== 'cli' || $argc < 4 ) {
	fwrite( STDERR, "usage: php real-data-compare.php <base-1.json> <base-2.json> [<base-3.json> ...] <change.json>\n" );
	exit( 2 );
}

$snapshots = array_map(
	function ( $path ) {
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			fwrite( STDERR, "not a snapshot: $path\n" );
			exit( 2 );
		}
		return $data;
	},
	array_slice( $argv, 1 )
);
$change = array_pop( $snapshots );
$base   = $snapshots[0];

$hash = function ( $snapshot, $id ) {
	return isset( $snapshot['renders'][ $id ]['hash'] ) ? $snapshot['renders'][ $id ]['hash'] : null;
};

$noise = array();
foreach ( array_slice( $snapshots, 1 ) as $again ) {
	foreach ( array_keys( $base['renders'] + $again['renders'] ) as $id ) {
		if ( $hash( $base, $id ) !== $hash( $again, $id ) ) {
			$noise[ $id ] = true;
		}
	}
}

$changes = array();
foreach ( array_keys( $base['renders'] + $change['renders'] ) as $id ) {
	if ( ! isset( $noise[ $id ] ) && $hash( $base, $id ) !== $hash( $change, $id ) ) {
		$changes[] = "render $id: " . siteorigin_rd_json( isset( $base['renders'][ $id ] ) ? $base['renders'][ $id ] : null ) . ' -> ' . siteorigin_rd_json( isset( $change['renders'][ $id ] ) ? $change['renders'][ $id ] : null );
	}
}

foreach ( array( 'listed', 'stored' ) as $section ) {
	foreach ( array_keys( $base[ $section ] + $change[ $section ] ) as $key ) {
		$before = isset( $base[ $section ][ $key ] ) ? $base[ $section ][ $key ] : null;
		$after  = isset( $change[ $section ][ $key ] ) ? $change[ $section ][ $key ] : null;
		if ( $before !== $after ) {
			$changes[] = "$section " . siteorigin_rd_json( (string) $key ) . ': ' . siteorigin_rd_json( $before ) . ' -> ' . siteorigin_rd_json( $after );
		}
	}
}

if ( $base['errors'] !== $change['errors'] ) {
	$changes[] = 'errors: ' . siteorigin_rd_json( $base['errors'] ) . ' -> ' . siteorigin_rd_json( $change['errors'] );
}

/**
 * JSON for a report line.
 *
 * @param mixed $value Any value.
 *
 * @return string
 */
function siteorigin_rd_json( $value ) {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

$object_keys = array_filter(
	$base['factory'],
	function ( $entry ) {
		return (string) $entry['key'] !== $entry['class'];
	}
);

printf(
	"%s: renders=%d noise=%d changes=%d keys-not-class=%s\n%s -> %s\n",
	$base['site'],
	count( $base['renders'] ),
	count( $noise ),
	count( $changes ),
	siteorigin_rd_json( array_map( function ( $e ) { return $e['key'] . ' => ' . $e['class']; }, array_values( $object_keys ) ) ),
	$base['panels_dir'],
	$change['panels_dir']
);
foreach ( $changes as $line ) {
	echo "  $line\n";
}

exit( $changes ? 1 : 0 );

<?php
/**
 * Compare real-data snapshots taken by real-data-snapshot.php.
 *
 * Usage: php real-data-compare.php [--same-checkout] <base-1.json> <base-2.json> [<base-3.json> ...] <change.json>
 *
 * Every file but the last is a snapshot of the base checkout; the last is the change. The base
 * snapshots must come from one checkout and the change from another (its directory or its git
 * revision differs), so a change snapshot that silently loaded the base checkout is an error, not a
 * clean result. --same-checkout lifts that check for a control run, where the change snapshot is the
 * base checkout with something added on purpose.
 *
 * Renders whose hash differs between any two base snapshots are noise and are left out; more base
 * snapshots catch rarer noise. Everything else that differs between base-1 and the change is
 * reported: renders, widget factory entries, the editor's widget list, how each stored widget class
 * resolves, whether its form says it is missing, and errors. A factory key WordPress made from the
 * widget object (the snapshot checks it against the object) is compared by its type only, as it
 * changes from one request to the next.
 *
 * Exits 1 when anything outside the noise changed, 0 when nothing did, 2 when the snapshots can't be
 * compared.
 *
 * @package siteorigin-panels
 */

$args          = array_slice( $argv, 1 );
$same_checkout = in_array( '--same-checkout', $args, true );
$args          = array_values( array_diff( $args, array( '--same-checkout' ) ) );

if ( PHP_SAPI !== 'cli' || count( $args ) < 3 ) {
	fwrite( STDERR, "usage: php real-data-compare.php [--same-checkout] <base-1.json> <base-2.json> [<base-3.json> ...] <change.json>\n" );
	exit( 2 );
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

/**
 * Stop: the snapshots can't be compared.
 *
 * @param string $message Why.
 */
function siteorigin_rd_refuse( $message ) {
	fwrite( STDERR, "$message\n" );
	exit( 2 );
}

$snapshots = array_map(
	function ( $path ) {
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || ! isset( $data['renders'], $data['factory'], $data['listed'], $data['stored'], $data['errors'] ) || ( $data['factory'] && ! array_key_exists( 'object_key', $data['factory'][0] ) ) ) {
			siteorigin_rd_refuse( "not a snapshot: $path" );
		}
		return $data;
	},
	$args
);
$change = array_pop( $snapshots );
$base   = $snapshots[0];

// Which checkout each snapshot ran.
$checkout = function ( $snapshot ) {
	return array(
		'dir' => isset( $snapshot['panels_dir'] ) ? $snapshot['panels_dir'] : null,
		'rev' => isset( $snapshot['panels_rev'] ) ? $snapshot['panels_rev'] : null,
	);
};
foreach ( array_merge( $snapshots, array( $change ) ) as $snapshot ) {
	if ( $snapshot['site'] !== $base['site'] ) {
		siteorigin_rd_refuse( 'snapshots are of different sites: ' . $base['site'] . ' and ' . $snapshot['site'] );
	}
	if ( empty( $snapshot['panels_dir'] ) ) {
		siteorigin_rd_refuse( 'a snapshot ran without Page Builder: ' . $snapshot['site'] );
	}
}
foreach ( array_slice( $snapshots, 1 ) as $again ) {
	if ( $checkout( $again ) !== $checkout( $base ) ) {
		siteorigin_rd_refuse( 'base snapshots ran different checkouts: ' . siteorigin_rd_json( $checkout( $base ) ) . ' and ' . siteorigin_rd_json( $checkout( $again ) ) );
	}
}
if ( ! $same_checkout && $checkout( $change ) === $checkout( $base ) ) {
	siteorigin_rd_refuse( 'the change snapshot ran the base checkout ' . siteorigin_rd_json( $checkout( $base ) ) . '; check SO_PANELS_DIR and the loader, or pass --same-checkout for a control' );
}

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

// Factory entries, grouped by class and id_base. A key the snapshot found WordPress made from the
// widget object changes with the objects built before it, so only its type is compared; a class name
// or a plugin's own key is compared as it is.
$factory = function ( $snapshot ) {
	$entries = array();
	foreach ( $snapshot['factory'] as $entry ) {
		$entries[ $entry['class'] . ' ' . $entry['id_base'] ][] = $entry['key_type'] . ' ' . ( empty( $entry['object_key'] ) ? (string) $entry['key'] : '(object key)' );
	}
	foreach ( $entries as &$list ) {
		sort( $list );
	}
	ksort( $entries );
	return $entries;
};
$base_factory   = $factory( $base );
$change_factory = $factory( $change );
foreach ( array_keys( $base_factory + $change_factory ) as $id ) {
	$before = isset( $base_factory[ $id ] ) ? $base_factory[ $id ] : null;
	$after  = isset( $change_factory[ $id ] ) ? $change_factory[ $id ] : null;
	if ( $before !== $after ) {
		$changes[] = 'factory ' . siteorigin_rd_json( $id ) . ': ' . siteorigin_rd_json( $before ) . ' -> ' . siteorigin_rd_json( $after );
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

printf(
	"%s: renders=%d noise=%d changes=%d\n%s -> %s%s\n",
	$base['site'],
	count( $base['renders'] ),
	count( $noise ),
	count( $changes ),
	siteorigin_rd_json( $checkout( $base ) ),
	siteorigin_rd_json( $checkout( $change ) ),
	$same_checkout ? ' (control: same checkout allowed)' : ''
);
foreach ( $changes as $line ) {
	echo "  $line\n";
}

exit( $changes ? 1 : 0 );

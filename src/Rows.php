<?php
/**
 * Helpers for the rows that `modules` and `datapoints` print.
 *
 * @package SiteKitData
 */

namespace SiteKitData;

use InvalidArgumentException;

/**
 * Sorts rows of associative arrays. No WordPress dependency.
 */
final class Rows {

	/**
	 * Sorts rows by a field.
	 *
	 * Numbers and booleans compare numerically (false before true), strings naturally and
	 * case-insensitively, and arrays by their JSON. Rows missing the field, or with null,
	 * go last in either order. Ties keep their original order.
	 *
	 * @param array[] $rows    Rows.
	 * @param string  $orderby Field to sort by.
	 * @param string  $order   asc or desc.
	 * @return array[] Sorted rows, reindexed.
	 * @throws InvalidArgumentException If no row has the field, or the order is invalid.
	 */
	public static function sort( array $rows, $orderby, $order = 'asc' ) {
		$order = strtolower( (string) $order );
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid order "%s". Use asc or desc.', $order ) );
		}
		if ( ! $rows ) {
			return array();
		}

		$fields = array();
		foreach ( $rows as $row ) {
			$fields += array_flip( array_keys( (array) $row ) );
		}
		if ( ! isset( $fields[ $orderby ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Invalid orderby field "%s". Available fields: %s.',
					$orderby,
					implode( ', ', array_keys( $fields ) )
				)
			);
		}

		$direction = 'desc' === $order ? -1 : 1;
		$indexed   = array();
		foreach ( array_values( $rows ) as $index => $row ) {
			$indexed[] = array( $index, $row );
		}

		usort(
			$indexed,
			function ( $a, $b ) use ( $orderby, $direction ) {
				$value_a = isset( $a[1][ $orderby ] ) ? $a[1][ $orderby ] : null;
				$value_b = isset( $b[1][ $orderby ] ) ? $b[1][ $orderby ] : null;

				if ( null === $value_a || null === $value_b ) {
					$result = ( null === $value_a ) - ( null === $value_b );
				} else {
					$result = $direction * self::compare( $value_a, $value_b );
				}

				// usort isn't stable before PHP 8.
				return 0 !== $result ? $result : $a[0] - $b[0];
			}
		);

		return array_column( $indexed, 1 );
	}

	/**
	 * Compares two non-null values.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return int
	 */
	private static function compare( $a, $b ) {
		$numeric_a = is_bool( $a ) || is_int( $a ) || is_float( $a );
		$numeric_b = is_bool( $b ) || is_int( $b ) || is_float( $b );
		if ( $numeric_a && $numeric_b ) {
			return ( (float) $a ) <=> ( (float) $b );
		}

		$string_a = is_scalar( $a ) ? (string) $a : json_encode( $a );
		$string_b = is_scalar( $b ) ? (string) $b : json_encode( $b );
		return strnatcasecmp( $string_a, $string_b );
	}
}

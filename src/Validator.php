<?php
/**
 * Argument validation for `wp site-kit-data`. Has no WordPress or WP-CLI dependencies so it
 * can be unit-tested on its own.
 *
 * @package SiteKitData
 */

namespace SiteKitData;

use InvalidArgumentException;

/**
 * Validates and normalizes command arguments.
 */
final class Validator {

	/**
	 * Matches the `slug` segment of Site Kit's module data route.
	 * `\z` (not `$`) so a trailing newline is rejected.
	 */
	const MODULE_PATTERN = '/^[a-z0-9-]+\z/';

	/**
	 * Matches the `datapoint` segment of Site Kit's module data route.
	 */
	const DATAPOINT_PATTERN = '/^[a-z-]+\z/';

	/**
	 * Query param keys that would override the route's URL params. WordPress gives query
	 * params precedence over URL params in `WP_REST_Request::get_param()`, and Site Kit reads
	 * `$request['slug']` and `$request['datapoint']`, so these would redirect the request to
	 * a different module or datapoint than the one validated.
	 */
	const RESERVED_PARAMS = array( 'slug', 'datapoint' );

	/**
	 * Output formats.
	 */
	const FORMATS = array( 'json', 'compact' );

	/**
	 * Validates a module slug.
	 *
	 * @param mixed $module Module slug.
	 * @return string The slug.
	 * @throws InvalidArgumentException If invalid.
	 */
	public static function module( $module ) {
		if ( ! is_string( $module ) || ! preg_match( self::MODULE_PATTERN, $module ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid module slug %s. Must match ^[a-z0-9-]+$.', self::quote( $module ) )
			);
		}
		return $module;
	}

	/**
	 * Validates a datapoint name.
	 *
	 * @param mixed $datapoint Datapoint name.
	 * @return string The datapoint.
	 * @throws InvalidArgumentException If invalid.
	 */
	public static function datapoint( $datapoint ) {
		if ( ! is_string( $datapoint ) || ! preg_match( self::DATAPOINT_PATTERN, $datapoint ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid datapoint %s. Must match ^[a-z-]+$.', self::quote( $datapoint ) )
			);
		}
		return $datapoint;
	}

	/**
	 * Decodes `--params` JSON into an associative array.
	 *
	 * @param mixed $json JSON text, or null for none.
	 * @return array Request options.
	 * @throws InvalidArgumentException If not a JSON object, or it uses a reserved key.
	 */
	public static function params( $json ) {
		if ( null === $json ) {
			return array();
		}
		if ( ! is_string( $json ) ) {
			throw new InvalidArgumentException( '--params must be a JSON object.' );
		}

		$json = trim( $json );
		if ( '' === $json ) {
			throw new InvalidArgumentException( '--params is empty. Pass a JSON object, e.g. {}.' );
		}

		// Decode as objects first, so `[]` (array) and `{}` (object) can be told apart.
		$decoded = json_decode( $json, false, 512, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new InvalidArgumentException( '--params is not valid JSON: ' . json_last_error_msg() );
		}
		if ( ! is_object( $decoded ) ) {
			throw new InvalidArgumentException(
				sprintf( '--params must be a JSON object, got %s.', self::json_type( $decoded ) )
			);
		}

		$params = json_decode( $json, true, 512, JSON_BIGINT_AS_STRING );

		$reserved = array_values( array_intersect( array_keys( $params ), self::RESERVED_PARAMS ) );
		if ( $reserved ) {
			throw new InvalidArgumentException(
				sprintf(
					'--params must not contain %s: it would override the <module> or <datapoint> argument.',
					implode( ' or ', array_map( array( __CLASS__, 'quote' ), $reserved ) )
				)
			);
		}

		return $params;
	}

	/**
	 * Validates an output format.
	 *
	 * @param mixed $format Format, or null for the default.
	 * @return string The format.
	 * @throws InvalidArgumentException If unknown.
	 */
	public static function format( $format ) {
		if ( null === $format ) {
			return 'json';
		}
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid --format %s. Use json or compact.', self::quote( $format ) )
			);
		}
		return $format;
	}

	/**
	 * Quotes a value for an error message.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function quote( $value ) {
		return is_string( $value ) ? '"' . addcslashes( $value, "\"\\\0..\37" ) . '"' : gettype( $value );
	}

	/**
	 * Names the JSON type of a decoded value.
	 *
	 * @param mixed $value Decoded value.
	 * @return string
	 */
	private static function json_type( $value ) {
		if ( is_array( $value ) ) {
			return 'an array';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return 'a boolean';
		}
		if ( is_string( $value ) ) {
			return 'a string';
		}
		return 'a number';
	}
}

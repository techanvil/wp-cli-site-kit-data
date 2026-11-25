<?php
/**
 * Lists a Site Kit module's GET datapoints.
 *
 * @package SiteKitData
 */

namespace SiteKitData;

use InvalidArgumentException;
use ReflectionMethod;

/**
 * Reads datapoint definitions from Site Kit modules.
 *
 * Site Kit has no public API for this: `Module::get_datapoints()` drops the `GET:`/`POST:`
 * prefix, so the protected `get_datapoint_definitions()` is called by reflection on a fresh
 * module instance. Constructing modules has no side effects (nothing is registered or hooked).
 */
final class Datapoints {

	/**
	 * Lists a module's GET datapoints, using Site Kit's classes.
	 *
	 * @param string $slug Module slug.
	 * @return array[] Rows of `datapoint` and `shareable`.
	 * @throws InvalidArgumentException If the module isn't available.
	 * @throws \RuntimeException If Site Kit's internals have changed.
	 */
	public static function for_module( $slug ) {
		$modules_class = 'Google\\Site_Kit\\Core\\Modules\\Modules';
		$context_class = 'Google\\Site_Kit\\Context';
		if ( ! class_exists( $modules_class ) || ! class_exists( $context_class ) || ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
			throw new \RuntimeException( 'Could not load Site Kit\'s module classes.' );
		}

		$modules   = new $modules_class( new $context_class( GOOGLESITEKIT_PLUGIN_MAIN_FILE ) );
		$available = $modules->get_available_modules();
		if ( ! isset( $available[ $slug ] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Unknown module "%s". Available modules: %s.',
					$slug,
					implode( ', ', array_keys( $available ) )
				)
			);
		}

		$module = $available[ $slug ];
		if ( ! method_exists( $module, 'get_datapoint_definitions' ) ) {
			throw new \RuntimeException( 'This Site Kit version has no get_datapoint_definitions() method.' );
		}

		$method = new ReflectionMethod( $module, 'get_datapoint_definitions' );
		$method->setAccessible( true );

		return self::from_definitions( (array) $method->invoke( $module ) );
	}

	/**
	 * Extracts GET datapoints from a module's definitions.
	 *
	 * @param array $definitions Map of `METHOD:name` to a definition array or object.
	 * @return array[] Rows of `datapoint` and `shareable`, sorted by name.
	 */
	public static function from_definitions( array $definitions ) {
		$rows = array();
		foreach ( $definitions as $key => $definition ) {
			if ( ! is_string( $key ) || ! preg_match( '/^GET:([a-z-]+)\z/', $key, $matches ) ) {
				continue;
			}
			$rows[ $matches[1] ] = array(
				'datapoint' => $matches[1],
				'shareable' => self::is_shareable( $definition ),
			);
		}
		ksort( $rows );
		return array_values( $rows );
	}

	/**
	 * Whether a definition is shareable (usable by view-only dashboard users).
	 *
	 * @param mixed $definition Definition array, or a Datapoint object.
	 * @return bool
	 */
	private static function is_shareable( $definition ) {
		if ( is_array( $definition ) ) {
			return ! empty( $definition['shareable'] );
		}
		if ( is_object( $definition ) && method_exists( $definition, 'is_shareable' ) ) {
			return (bool) $definition->is_shareable();
		}
		return false;
	}
}

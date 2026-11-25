<?php
/**
 * Sets the current user early enough for Site Kit.
 *
 * Site Kit builds its `User_Options` on `init` from `get_current_user_id()`, which runs before
 * WP-CLI applies `--user`. Filtering `determine_current_user` from before WordPress loads makes
 * the user current from the start, so Site Kit's permission checks and OAuth tokens apply as
 * they would in the dashboard.
 *
 * @package SiteKitData
 */

namespace SiteKitData;

use WP_CLI;

/**
 * Resolves `--user` and returns it from `determine_current_user`.
 */
final class User_Hook {

	/**
	 * The `--user` value, as given.
	 *
	 * @var string|null
	 */
	private static $user;

	/**
	 * Resolved user ID: null until resolved, 0 if it didn't resolve.
	 *
	 * @var int|null
	 */
	private static $user_id;

	/**
	 * Registers the hook if the command being run is `site-kit-data`. Call at package load
	 * time, before WordPress loads.
	 */
	public static function register() {
		$runner = WP_CLI::get_runner();
		if ( ! isset( $runner->arguments[0] ) || 'site-kit-data' !== $runner->arguments[0] ) {
			return;
		}
		if ( ! isset( $runner->config['user'] ) || '' === (string) $runner->config['user'] ) {
			// The command itself reports the missing --user, before making any request.
			return;
		}

		self::$user = (string) $runner->config['user'];

		WP_CLI::add_wp_hook( 'determine_current_user', array( __CLASS__, 'filter' ), 99 );
	}

	/**
	 * `determine_current_user` callback.
	 *
	 * @param int|false $user_id User ID determined so far.
	 * @return int|false
	 */
	public static function filter( $user_id ) {
		$resolved = self::resolve();
		return $resolved ? $resolved : $user_id;
	}

	/**
	 * Resolves the `--user` value to an ID: numeric ID, then login, then email.
	 * Pluggable functions are loaded by the time `determine_current_user` fires.
	 *
	 * @return int User ID, or 0 if it doesn't resolve (or no `--user` was given).
	 */
	public static function resolve() {
		if ( null !== self::$user_id ) {
			return self::$user_id;
		}
		if ( null === self::$user || ! function_exists( 'get_user_by' ) ) {
			return 0;
		}

		$user = false;
		if ( ctype_digit( self::$user ) ) {
			$user = get_user_by( 'id', (int) self::$user );
		}
		if ( ! $user ) {
			$user = get_user_by( 'login', self::$user );
		}
		if ( ! $user && is_email( self::$user ) ) {
			$user = get_user_by( 'email', self::$user );
		}

		self::$user_id = $user ? (int) $user->ID : 0;
		return self::$user_id;
	}
}

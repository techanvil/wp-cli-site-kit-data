<?php
/**
 * `wp site-kit-data`: read-only Site Kit report queries from WP-CLI.
 *
 * @package SiteKitData
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/User_Hook.php';
require_once __DIR__ . '/src/Datapoints.php';
require_once __DIR__ . '/src/Command.php';

WP_CLI::add_command( 'site-kit-data', 'SiteKitData\\Command' );

// Runs at package load time, before WordPress loads, and only for this command.
SiteKitData\User_Hook::register();

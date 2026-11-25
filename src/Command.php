<?php
/**
 * The `wp site-kit-data` command.
 *
 * @package SiteKitData
 */

namespace SiteKitData;

use InvalidArgumentException;
use WP_CLI;
use WP_REST_Request;

/**
 * Read-only Site Kit by Google data queries.
 *
 * Requests module datapoints through Site Kit's own REST routes, as a given user, and prints
 * the response as JSON. Only ever issues GET requests.
 */
final class Command {

	/**
	 * Requests a module datapoint and prints the response.
	 *
	 * Issues a GET request to google-site-kit/v1/modules/<module>/data/<datapoint> as the
	 * user given with --user. Site Kit's permission checks and the user's OAuth tokens apply
	 * as they would in the dashboard. Every call is a real Google API request.
	 *
	 * ## OPTIONS
	 *
	 * <module>
	 * : Module slug, e.g. analytics-4, search-console, adsense.
	 *
	 * <datapoint>
	 * : Datapoint name, e.g. report, searchanalytics.
	 *
	 * [--params=<json>]
	 * : Request options as a JSON object. Use - to read the JSON from stdin.
	 * ---
	 * default: {}
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - compact
	 * ---
	 *
	 * The global --user flag is required: it is the user to request as.
	 *
	 * ## EXAMPLES
	 *
	 *     # First day with Search Console data in the last 16 months.
	 *     $ wp site-kit-data get search-console searchanalytics --user=1 \
	 *       --params='{"startDate":"2025-07-24","endDate":"2026-11-23","dimensions":"date","limit":1}'
	 *
	 *     # Analytics report, one line, piped to jq.
	 *     $ wp site-kit-data get analytics-4 report --user=admin --format=compact --params='{
	 *       "startDate": "2026-10-01", "endDate": "2026-10-31",
	 *       "dimensions": ["date"], "metrics": [{"name": "totalUsers"}]
	 *     }' | jq '.rows | length'
	 *
	 *     # Request options from stdin.
	 *     $ echo '{"startDate":"2026-10-01","endDate":"2026-10-31"}' \
	 *       | wp site-kit-data get search-console searchanalytics --user=1 --params=-
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( $args, $assoc_args ) {
		list( $module, $datapoint ) = $args;

		try {
			$module    = Validator::module( $module );
			$datapoint = Validator::datapoint( $datapoint );
			$format    = Validator::format( isset( $assoc_args['format'] ) ? $assoc_args['format'] : null );

			$json = isset( $assoc_args['params'] ) ? $assoc_args['params'] : null;
			if ( '-' === $json ) {
				$json = stream_get_contents( STDIN );
			}
			$params = Validator::params( $json );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$this->check_environment();
		$this->check_user();

		$request = new WP_REST_Request( 'GET', "/google-site-kit/v1/modules/{$module}/data/{$datapoint}" );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );

		$status = $response->get_status();
		// Round-trip through JSON: some datapoints return Google client objects. Decoding
		// to objects (not assoc arrays) keeps `{}` and `[]` distinct in the output.
		$data = json_decode( wp_json_encode( $response->get_data() ) );

		if ( 200 !== $status ) {
			WP_CLI::error( $this->format_error( $status, $data ) );
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
		if ( 'json' === $format ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		WP_CLI::line( wp_json_encode( $data, $flags ) );
	}

	/**
	 * Errors unless the REST API and Site Kit are available.
	 */
	private function check_environment() {
		if ( ! function_exists( 'rest_do_request' ) ) {
			WP_CLI::error( 'The WordPress REST API is not available.' );
		}
		if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
			WP_CLI::error( 'Site Kit by Google is not active on this site.' );
		}
	}

	/**
	 * Errors unless --user was given, resolved, and is the current user.
	 */
	private function check_user() {
		$config = WP_CLI::get_runner()->config;
		if ( ! isset( $config['user'] ) || '' === (string) $config['user'] ) {
			WP_CLI::error( 'Missing --user. Pass the ID, login or email of the user to request as.' );
		}

		$user_id = User_Hook::resolve();
		if ( ! $user_id ) {
			WP_CLI::error( sprintf( 'User "%s" not found.', $config['user'] ) );
		}
		if ( get_current_user_id() !== $user_id ) {
			// The determine_current_user hook didn't take effect, so Site Kit would act as
			// someone else.
			WP_CLI::error(
				sprintf(
					'Could not act as user %d (current user is %d). Load this package with `wp package install` or --require.',
					$user_id,
					get_current_user_id()
				)
			);
		}
	}

	/**
	 * Formats an error response.
	 *
	 * @param int   $status HTTP status.
	 * @param mixed $data   Decoded response body.
	 * @return string
	 */
	private function format_error( $status, $data ) {
		$message = (string) $status;
		if ( is_object( $data ) ) {
			if ( isset( $data->code ) && is_scalar( $data->code ) ) {
				$message .= ' ' . $data->code;
			}
			if ( isset( $data->message ) && is_scalar( $data->message ) ) {
				$message .= ': ' . $data->message;
			}
			if ( isset( $data->data->reason ) && is_scalar( $data->data->reason ) && '' !== (string) $data->data->reason ) {
				$message .= ' (reason: ' . $data->data->reason . ')';
			}
		}
		return $message;
	}
}

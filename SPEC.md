# `wp site-kit-data`: read-only Site Kit report queries from WP-CLI

A WP-CLI package that requests Site Kit by Google data (Analytics, Search Console, AdSense
reports and other `GET` datapoints) through Site Kit's own REST routes, as a given WordPress
user, and prints the response as JSON.

It exists so that a developer, or a coding agent, can check real report output against a
local site with real Google connections without writing a throwaway PHP script each time, and
without being able to touch anything else on the site.

## Goals

- **One command for any `GET` module datapoint**, with the request options passed as JSON.
- **Read-only by construction.** It only issues `GET` requests to
  `google-site-kit/v1/modules/{slug}/data/{datapoint}`. It never reads or writes options, user
  meta, transients or credentials directly, and never sends `POST`.
- **Act as the right user.** Site Kit's permission checks and the user's OAuth tokens apply
  exactly as they would in the dashboard.
- **Narrow enough to allowlist.** A fixed command prefix that an agent's permission rules can
  auto-approve, unlike `wp eval` or `wp eval-file`, which run arbitrary code.
- **No code on the site.** Distributed as a WP-CLI package, so it works on any site without
  installing a plugin or mu-plugin.

Non-goals: writing data, managing modules or settings, bypassing Site Kit's permission checks,
working without Site Kit active.

## Usage

```
wp site-kit-data get <module> <datapoint> [--params=<json>] [--user=<id|login|email>] [--format=<format>]
```

| Argument | Description |
| --- | --- |
| `<module>` | Module slug, e.g. `analytics-4`, `search-console`, `adsense`. Must match `^[a-z0-9-]+$`. |
| `<datapoint>` | Datapoint name, e.g. `report`, `searchanalytics`. Must match `^[a-z-]+$`. |
| `--params=<json>` | Request options as a JSON object. `-` reads the JSON from stdin. Defaults to `{}`. |
| `--user` | WP-CLI's global flag. The user to request as. Required: the command errors without it. |
| `--format` | `json` (default, pretty-printed) or `compact` (one line, for piping to `jq`). |

Examples:

```sh
# First day with Search Console data in the last 16 months.
wp site-kit-data get search-console searchanalytics --user=1 \
  --params='{"startDate":"2025-07-24","endDate":"2026-11-23","dimensions":"date","limit":1}'

# Analytics report, sorted by date.
wp site-kit-data get analytics-4 report --user=1 --params='{
  "startDate": "2024-01-01", "endDate": "2026-11-23",
  "dimensions": ["date"], "metrics": [{"name": "totalUsers"}],
  "orderby": [{"dimension": {"dimensionName": "date"}}], "limit": 1
}'

# AdSense, earliest day with impressions.
wp site-kit-data get adsense report --user=1 --format=compact --params='{
  "startDate": "2023-11-24", "endDate": "2026-11-23",
  "dimensions": ["DATE"], "metrics": ["IMPRESSIONS"],
  "orderby": [{"fieldName": "DATE", "sortOrder": "ASCENDING"}], "limit": 1
}' | jq '.rows[0]'
```

On success it prints the response body and exits 0. On a non-200 response it prints the
status, the error `code` and `message` (and `data.reason` when present) via `WP_CLI::error()`,
which exits 1.

## Behavior

### Acting as the user {#acting-as-the-user}

WP-CLI's `--user` isn't enough on its own. Site Kit builds its `User_Options` on `init` with
`get_current_user_id()` (`includes/Plugin.php`), and in practice that ran before WP-CLI's
`--user` took effect: requests came back as `403 missing_required_scopes` even though the user
had the scopes. Calling `wp_set_current_user()` inside the command is too late for the same
reason.

What worked was hooking `determine_current_user` before WordPress loads:

```php
WP_CLI::add_wp_hook( 'determine_current_user', function () { return 1; }, 99 );
```

The package should do the same, generalized:

- At package load time (before WordPress), if the command being run is `site-kit-data`
  (`WP_CLI::get_runner()->arguments[0]`), read the global `user` value from
  `WP_CLI::get_runner()->config`.
- Register a `determine_current_user` hook at priority 99 that resolves it to an ID inside the
  callback (numeric ID, then `get_user_by( 'login' )`, then `get_user_by( 'email' )`;
  pluggable functions are loaded by the time the filter fires) and returns it.
- Only hook for this command, so other WP-CLI commands are unaffected.
- If the user doesn't resolve, error before making any request.

### Making the request

```php
$request = new WP_REST_Request( 'GET', "/google-site-kit/v1/modules/{$module}/data/{$datapoint}" );
$request->set_query_params( $params );
$response = rest_do_request( $request );
```

- `rest_do_request()` keeps arrays and objects as PHP values, so nested options such as
  `metrics` or `orderby` need no query-string encoding.
- The route's own permission callback runs (`resolve_datapoint_permission()` in
  `REST_Modules_Controller`), so a user without access gets Site Kit's normal 403.
- Normalize the response with `json_decode( wp_json_encode( $response->get_data() ), true )`
  before printing: some datapoints return Google client objects rather than arrays.
- Check `function_exists( 'rest_do_request' )` and that Site Kit is active
  (`defined( 'GOOGLESITEKIT_VERSION' )`), and give a clear error if not.

### Validation

- `<module>` and `<datapoint>` must match the route's own patterns (above). Reject anything
  else before building the request, so no input can reach a different route.
- `--params` must decode to a JSON object (an associative array). Reject arrays, scalars and
  invalid JSON with the `json_last_error_msg()`.

## Repository layout

```
wp-cli-site-kit-data/
├── composer.json        # "type": "wp-cli-package", autoload.files: ["command.php"]
├── command.php          # Registers the command and the determine_current_user hook
├── src/Command.php      # The `get` subcommand, with WP-CLI docblock synopsis
├── README.md            # Install, usage, examples, safety notes, agent allowlisting
└── LICENSE              # Apache-2.0, matching Site Kit
```

`command.php` should return early unless `class_exists( 'WP_CLI' )`.

Install:

```sh
wp package install https://github.com/<owner>/wp-cli-site-kit-data.git
# or, without installing:
wp --require=/path/to/wp-cli-site-kit-data/command.php site-kit-data get …
```

## Using it from an agent

The README should cover:

- **Allowlisting.** For example, in Claude Code's `settings.json`:
  `"permissions": { "allow": ["Bash(wp site-kit-data get:*)"] }`. Leave `wp eval*` out of the
  allowlist.
- **Local by WP Engine.** `wp` needs the site's shell environment (MySQL socket, PHP binary).
  Local writes it to `~/.config/Local/ssh-entry/<site-id>.sh`. Sourcing it as part of the same
  shell command makes the command compound, which can defeat a prefix allowlist, so document a
  small wrapper script (e.g. `sk-data <site-id> <module> <datapoint> …`) that sources the
  environment, `cd`s to the site root and runs the command, and allowlist the wrapper instead.
  Local's MCP `wp_cli` tool can also run the command, but allowlisting that tool allows every
  `wp` command.

## Testing

- Unit-test argument validation (slug and datapoint patterns, `--params` decoding) without
  WordPress.
- Manual checks against a site with Site Kit connected:
  - A valid report as an admin with the scopes returns 200 and rows.
  - The same request without `--user` errors before any request.
  - An unknown user, module or datapoint gives a clear error.
  - A user without Site Kit access gets Site Kit's 403.
  - A `POST`-only datapoint such as `data-available` gets Site Kit's "invalid datapoint" error,
    not a write.
  - Other WP-CLI commands run with `--user` behave as before (the hook only applies to this
    command).
- Optionally, Behat functional tests via `wp-cli/wp-cli-tests`, as other WP-CLI packages do.

## Open questions

- **Side effects of `GET` datapoints.** Most are pure reads, but some may refresh stored
  settings or caches as a side effect. Audit the `GET:` datapoint definitions in each module
  and either document the exceptions or keep a denylist.
- **Quota.** Every call is a real Google API request against the site's project. Worth a note
  in the README; no rate limiting planned.
- **More subcommands later**, both `GET`-only:
  - `wp site-kit-data modules`: active and connected modules, from
    `core/modules/data/list`.
  - `wp site-kit-data datapoints <module>`: list a module's `GET` datapoints. This needs a
    public way to enumerate them, which may mean reflection.
- **An MCP server** could wrap this command later to give typed per-module tools and work
  outside Claude Code. The command stays the single implementation either way.

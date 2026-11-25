# wp site-kit-data

Read-only [Site Kit by Google](https://sitekit.withgoogle.com/) report queries from WP-CLI.

`wp site-kit-data get` requests any `GET` module datapoint (Analytics, Search Console, AdSense
reports and so on) through Site Kit's own REST routes, as a given WordPress user, and prints the
response as JSON. Use it to check real report output against a site with real Google
connections, without writing a throwaway PHP script and without access to anything else on the
site.

## Install

```sh
wp package install https://github.com/techanvil/wp-cli-site-kit-data.git
```

Or, without installing:

```sh
wp --require=/path/to/wp-cli-site-kit-data/command.php site-kit-data get …
```

Requires Site Kit to be active on the site. Nothing is installed on the site itself.

## Usage

```
wp site-kit-data get <module> <datapoint> --user=<id|login|email> [--params=<json>] [--format=<format>]
```

| Argument | Description |
| --- | --- |
| `<module>` | Module slug, e.g. `analytics-4`, `search-console`, `adsense`. Must match `^[a-z0-9-]+$`. |
| `<datapoint>` | Datapoint name, e.g. `report`, `searchanalytics`. Must match `^[a-z-]+$`. |
| `--params=<json>` | Request options as a JSON object. `-` reads the JSON from stdin. Defaults to `{}`. May not contain the top-level keys `slug` or `datapoint`. |
| `--user` | WP-CLI's global flag: the user to request as. **Required.** |
| `--format` | `json` (default, pretty-printed) or `compact` (one line, for piping to `jq`). |

On success it prints the response body and exits 0. On a non-200 response it prints the status,
the error code and message (and `data.reason` when present) and exits 1, e.g.:

```
Error: 403 missing_required_scopes: You'll need to grant Site Kit permission to do this. (reason: …)
```

### Finding modules and datapoints

```
wp site-kit-data modules --user=<id|login|email> [--fields=<fields>] [--format=table|json|csv|yaml]
wp site-kit-data datapoints <module> [--fields=<fields>] [--format=table|json|csv|yaml]
```

- `modules` lists Site Kit's modules with whether each is active, connected and shareable,
  from Site Kit's `core/modules/data/list` route (`GET`, as `--user`).
- `datapoints` lists a module's `GET` datapoints, the names `get` accepts, and whether each is
  shareable (available to users with view-only dashboard access). It doesn't need `--user`.
  Site Kit has no public API for this, so it reads the module's datapoint definitions by
  reflection; a future Site Kit release could break it without breaking `get`.

Neither makes Google API requests.

### Examples

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

# Options from a file.
wp site-kit-data get analytics-4 report --user=admin --params=- < report.json
```

## How it works

- It only sends `GET` requests to `google-site-kit/v1/modules/{module}/data/{datapoint}`, via
  `rest_do_request()`. It never reads or writes options, user meta, transients or credentials
  directly, and never sends `POST`. A `POST`-only datapoint gets Site Kit's "invalid datapoint"
  error.
- The route's own permission callback runs, so a user without Site Kit access gets Site Kit's
  normal 403.
- `<module>` and `<datapoint>` are checked against the route's own patterns before the request
  is built, and `--params` may not set `slug` or `datapoint` (WordPress gives query params
  precedence over URL params, so they would redirect the request).
- **Acting as the user.** Site Kit reads the current user on `init`, before WP-CLI applies
  `--user`. So when (and only when) the command being run is `site-kit-data`, the package
  hooks `determine_current_user` before WordPress loads and returns the `--user` ID. Other
  WP-CLI commands are unaffected.

## Safety notes

- **Quota.** Every call is a real Google API request against the site's Google Cloud project
  and counts toward its quotas. There is no rate limiting.
- **Side effects of `GET` datapoints.** Most are pure reads, but a few may refresh stored
  settings or caches as a side effect, as they would when the dashboard loads them.
- The responses contain real site data. Treat output accordingly.

## Using it from an agent

### Claude Code skill

This repo is also a Claude Code plugin with a `site-kit-data` skill. The skill tells agents
when to use the command, what options the common datapoints take, what the errors mean, and to
keep API calls small. Install it with:

```
/plugin install site-kit-data --marketplace techanvil/wp-cli-site-kit-data
```

On older Claude Code versions, add the marketplace first:

```
/plugin marketplace add techanvil/wp-cli-site-kit-data
/plugin install site-kit-data@wp-cli-site-kit-data
```

Or copy or symlink `skills/site-kit-data` into `~/.claude/skills/`. The skill doesn't install
the WP-CLI package; install that with `wp package install` as above.

For agents that don't load skills, add a line to the project's `AGENTS.md` or `CLAUDE.md`, e.g.
"For real Site Kit report data on a local site, use `wp site-kit-data get` (read-only), never
`wp eval`."

### Allowlisting

The command has a fixed prefix that permission rules can auto-approve, unlike `wp eval` or
`wp eval-file`, which run arbitrary code. In Claude Code's `settings.json`:

```json
{
  "permissions": {
    "allow": ["Bash(wp site-kit-data:*)"]
  }
}
```

Leave `wp eval*` out of the allowlist.

### Local by WP Engine

`wp` needs the site's shell environment (MySQL socket, PHP binary). Local writes it to
`~/.config/Local/ssh-entry/<site-id>.sh`. Sourcing that in the same shell command makes the
command compound, which can defeat a prefix allowlist, so use a small wrapper script instead
and allowlist the wrapper. For example, `~/bin/sk-data`:

```sh
#!/usr/bin/env bash
# Usage: sk-data <site-id> <get|modules|datapoints> [arguments and options…]
set -euo pipefail

site_id="${1:?Usage: sk-data <site-id> <get|modules|datapoints> [arguments]}"
shift
[[ "$site_id" =~ ^[A-Za-z0-9_-]+$ ]] || { echo "Invalid site id" >&2; exit 1; }

entry="$HOME/.config/Local/ssh-entry/${site_id}.sh"
[[ -f "$entry" ]] || { echo "No Local site $site_id" >&2; exit 1; }

# The entry script sets up PATH, MYSQL_HOME etc. and cds to the site root; it ends by
# starting an interactive shell, so take only the exports and cd from it.
eval "$(grep -E '^(export |cd )' "$entry")"

exec wp site-kit-data "$@"
```

Then allow `Bash(sk-data:*)`. Check your site's entry script first: the wrapper assumes it
sets the environment with `export` lines and a `cd` to the site root, which is how current Local
versions write it.

Local's MCP `wp_cli` tool can also run the command, but allowlisting that tool allows every `wp`
command.

## Development

```sh
composer install
composer test
```

Unit tests cover argument validation and run without WordPress.

Manual checks against a site with Site Kit connected:

- A valid report as an admin with the scopes returns 200 and rows.
- The same request without `--user` errors before any request.
- An unknown user, module or datapoint gives a clear error.
- A user without Site Kit access gets Site Kit's 403.
- A `POST`-only datapoint such as `data-available` gets Site Kit's "invalid datapoint" error.
- Other WP-CLI commands run with `--user` behave as before.

## License

Apache-2.0, matching Site Kit.

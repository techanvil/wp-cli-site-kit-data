---
name: site-kit-data
description: Fetch real Site Kit by Google data (Analytics 4, Search Console, AdSense and other modules) from a WordPress site with `wp site-kit-data get`, read-only, as a given user. Use when you need actual report output from a site with live Google connections, e.g. checking what a Site Kit widget or datapoint returns, finding the first or last date with data, comparing numbers against the dashboard, or debugging a report request. Use this instead of `wp eval`, `wp eval-file` or a throwaway PHP script.
---

# Site Kit data from WP-CLI

`wp site-kit-data get` sends a `GET` request to Site Kit's own REST route
`google-site-kit/v1/modules/<module>/data/<datapoint>` as a WordPress user, and prints the
response as JSON. Site Kit's permission checks and the user's Google OAuth tokens apply as they
would in the dashboard. It cannot write anything.

```
wp site-kit-data modules --user=<id|login|email>   # module slugs, active/connected
wp site-kit-data datapoints <module>                # GET datapoints get accepts
wp site-kit-data get <module> <datapoint> --user=<id|login|email> [--params=<json>] [--format=json|compact]
```

Start with `modules` and `datapoints` instead of guessing names; neither calls Google.

- `--user` is required. Use an administrator who connected Site Kit (often `1`). To find one:
  `wp user list --role=administrator --fields=ID,user_login`.
- `--params` is a JSON object of request options (default `{}`). Nested arrays and objects are
  passed through as-is. `--params=-` reads it from stdin. Don't put `slug` or `datapoint` in it.
- `--format=compact` prints one line, for piping to `jq`.
- Exit 0 prints the response body. Exit 1 prints `Error: <status> <code>: <message> (reason: …)`.

If `wp site-kit-data` is an unknown command, the package isn't installed:
`wp package install https://github.com/techanvil/wp-cli-site-kit-data.git`.

## Before you run it

- **Every `get` call is a real Google API request** and counts against the site's quota. Use the
  smallest date range and `limit` that answer the question. Don't loop over many days or pages
  when one request with a `date` dimension would do.
- Responses contain real site data. Quote only what the task needs.
- Dates are `YYYY-MM-DD`. Search Console data lags by about 2–3 days, and keeps about 16 months.

## Local by WP Engine sites

`wp` needs the site's shell environment, from `~/.config/Local/ssh-entry/<site-id>.sh`. Don't
`source` that file: it ends with `exec $SHELL` and will hang. If an `sk-data` wrapper is on
`PATH` (see the package README), use it:

```sh
sk-data <site-id> datapoints analytics-4
sk-data <site-id> get analytics-4 report --user=1 --params='…'
```

Otherwise run the `export` and `cd` lines from the entry script, then `wp`. The site ID is the
entry script's filename. Each script has a `cd` line that shows which site it belongs to.

## Report options

`datapoints` gives names, not options. These are the options for the main reports.

### Analytics 4: `analytics-4 report`

`metrics` is required. Options: `startDate`, `endDate`, `compareStartDate`, `compareEndDate`,
`dimensions`, `metrics`, `dimensionFilters`, `metricFilters`, `orderby`, `limit`, `url` (limit
to one page).

```sh
wp site-kit-data get analytics-4 report --user=1 --params='{
  "startDate": "2026-10-01", "endDate": "2026-10-31",
  "dimensions": ["date"],
  "metrics": [{"name": "totalUsers"}, {"name": "sessions"}],
  "orderby": [{"dimension": {"dimensionName": "date"}, "desc": false}],
  "limit": 31
}'
```

- `dimensions`: `["date"]` or `[{"name": "pagePath"}]`. `metrics`: `[{"name": "screenPageViews"}]`,
  or a comma-separated string such as `"totalUsers,sessions"`.
- `orderby`: `{"metric": {"metricName": "…"}}` or `{"dimension": {"dimensionName": "…"}}`,
  with `"desc": true` for descending.
- `dimensionFilters`: `{"sessionDefaultChannelGrouping": ["Organic Search"]}` (exact match,
  OR for a list), or `{"pagePath": {"filterType": "stringFilter", "matchType": "BEGINS_WITH",
  "value": "/blog"}}`.
- The response has `dimensionHeaders`, `metricHeaders`, `rows[].dimensionValues[].value`,
  `rows[].metricValues[].value` (values are strings), `totals` and `rowCount`.
- Metric and dimension names are GA4 Data API names (`totalUsers`, `sessions`,
  `screenPageViews`, `engagementRate`, `pagePath`, `sessionDefaultChannelGrouping`,
  `country`, …).

### Search Console: `search-console searchanalytics`

Options: `startDate`, `endDate`, `dimensions` (comma-separated string: `date`, `query`,
`page`, `country`, `device`), `limit`, `url` (limit to one page).

```sh
# First day with data in the last 16 months.
wp site-kit-data get search-console searchanalytics --user=1 \
  --params='{"startDate":"2025-07-24","endDate":"2026-11-23","dimensions":"date","limit":1}'

# Top queries.
wp site-kit-data get search-console searchanalytics --user=1 --format=compact \
  --params='{"startDate":"2026-10-01","endDate":"2026-10-31","dimensions":"query","limit":10}'
```

The response is a list of rows with `keys`, `clicks`, `impressions`, `ctr` and `position`.

### AdSense: `adsense report`

Options: `startDate`, `endDate`, `metrics`, `dimensions` (AdSense Management API names,
uppercase), `orderby`, `limit`.

```sh
wp site-kit-data get adsense report --user=1 --params='{
  "startDate": "2026-10-01", "endDate": "2026-10-31",
  "dimensions": ["DATE"], "metrics": ["ESTIMATED_EARNINGS", "IMPRESSIONS"],
  "orderby": [{"fieldName": "DATE", "sortOrder": "ASCENDING"}], "limit": 31
}'
```

### PageSpeed Insights: `pagespeed-insights pagespeed`

`{"url": "https://example.com/", "strategy": "mobile"}` (`strategy` is `mobile` or
`desktop`; required).

## Errors

| Error | Meaning |
| --- | --- |
| `Missing --user` / `User "…" not found` | Pass an existing user's ID, login or email. |
| `Site Kit by Google is not active` | Activate Site Kit, or you're on the wrong site. |
| `404 invalid_module_slug` | Wrong module slug. Check `wp site-kit-data modules`. |
| `403 module_not_active` | The module isn't active on this site (see `modules`). |
| `400 invalid_datapoint` | No `GET` datapoint by that name. Check `wp site-kit-data datapoints <module>`. |
| `403 missing_required_scopes` | The user hasn't granted the OAuth scopes. Try the admin who connected Site Kit. |
| `403 rest_forbidden` / `rest_cannot_*` | The user can't view Site Kit data. |
| `400 missing_required_param` | A required option is missing, e.g. `metrics` for an Analytics report. |
| `500 missing_required_setting` | The module isn't fully set up (e.g. no Analytics property). |
| `invalid_analytics_4_report_metrics` | An unknown metric name. A user with view-only (shared) access can only request a limited set of metrics, and only `shareable` datapoints. |

## Don't

- Don't use `wp eval`, `wp eval-file`, `wp option` or `wp user meta` to get at Site Kit data or
  tokens. This command is the read-only route.
- Don't try to write through it (activate modules, change settings). It's `GET`-only on purpose.

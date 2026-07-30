# fu_cloudflare

Bypass Cloudflare protection for RSS feeds using FlareSolverr.

[![Challenges Solved](https://img.shields.io/github/downloads/P6g9YHK6/fu_cloudflare/usage-counter/total?label=challenges%20solved)](https://github.com/P6g9YHK6/fu_cloudflare/releases/tag/usage-counter)

<img width="803" height="847" alt="image" src="https://github.com/user-attachments/assets/01953e77-b860-46d9-8628-35d52aef9a50" />
<img width="493" height="279" alt="image" src="https://github.com/user-attachments/assets/68c01ae6-2a2c-41ac-89cb-ddba9a7ded92" />

## Features

- Routes feed fetching through FlareSolverr to bypass Cloudflare JS challenges
- Per-feed toggle: enable only for specific feeds in the feed editor
- FlareSolverr session management: persistent browser context for multi-step PoW challenges
- Retry on challenge: detects if FlareSolverr returned a challenge page and retries with session

## Installation

1. Clone this plugin to the TT-RSS plugin directory:

```
cd /path/to/tt-rss/plugins.local
git clone https://github.com/P6g9YHK6/fu_cloudflare.git
```

2. Enable the plugin in Preferences → Plugins.
3. Deploy FlareSolverr (Docker):

```
docker run -d --name flaresolverr -p 8191:8191 ghcr.io/flaresolverr/flaresolverr:latest
```

## Configuration

Configure the following in Preferences → Feeds → Plugins → Cloudflare Bypass:

- **Plugin**: enable/disable the plugin globally
- **FlareSolverr URL**: address of your FlareSolverr instance (default: `http://localhost:8191`)
- **Max timeout**: maximum wait time in milliseconds (default: `60000`)
- **Test Feed Fetch**: enter any feed URL and click "Test Fetch" to verify FlareSolverr can bypass Cloudflare for that URL

## Usage

1. Right-click a feed → Edit → set FlareSolverr mode to "Always bypass via FlareSolverr".
2. If FlareSolverr returns a challenge page, the plugin retries once after 3s using the persistent session. If it still fails, a warning is logged to the Event Log.

## Troubleshooting

If feeds are still failing:

1. Check FlareSolverr is reachable via **Health Check** in plugin settings.
2. Check the **Event Log** for `fu_cloudflare:` warning messages.
3. Use the **feed debugger** at Verbose level (`?debug=1` in URL) to see `fu_cloudflare:` log lines.
4. Click **Reset Session** to create a fresh browser context on FlareSolverr.
5. Increase **Max timeout** if the PoW computation takes longer (try 120000ms).
6. Check FlareSolverr logs: `docker logs flaresolverr`.

## For Plugin Developers: Inter-Plugin API

Other tt-rss plugins that fetch arbitrary URLs (for example, full-text article extraction)
can reuse this plugin's FlareSolverr connection instead of fetching directly and getting
blocked by Cloudflare. Grab the plugin instance from the host and call its public methods:

```php
$fu_cloudflare = PluginHost::getInstance()->get_plugin('fu_cloudflare');

if ($fu_cloudflare && method_exists($fu_cloudflare, 'is_flaresolverr_enabled') && $fu_cloudflare->is_flaresolverr_enabled()) {
    $result = $fu_cloudflare->fetch_url_via_flaresolverr($url);

    if ($result !== false && !$fu_cloudflare->is_cloudflare_challenge($result)) {
        // $result is the fetched body, bypassed via FlareSolverr if needed
    } else {
        // still blocked or fu_cloudflare unavailable — fall back to your own normal fetch
    }
} else {
    // fu_cloudflare not installed/enabled — fall back to your own normal fetch
}
```

Available methods:

- **`is_flaresolverr_enabled(): bool`** — whether fu_cloudflare is configured and not
  globally disabled. Check this before calling the others.
- **`fetch_url_via_flaresolverr(string $url): string|false`** — fetches `$url`, routing
  through FlareSolverr with the configured retries if needed. Falls back to a plain direct
  fetch if FlareSolverr isn't reachable or can't solve the challenge, so the return value
  may still be a challenge page — always check it with `is_cloudflare_challenge()` before
  trusting it. This method is independent of the per-feed include/exclude lists in the
  Feeds prefs tab (those only affect the feed-fetching hook); it's meant for one-off URLs.
- **`is_url_cloudflare_challenged(string $url): bool`** — lightweight check (a plain HTTP
  request, no FlareSolverr) for whether `$url` is currently behind a Cloudflare challenge.
  Useful to decide whether it's worth calling `fetch_url_via_flaresolverr()` at all.
- **`is_cloudflare_challenge(string $data): bool`** — checks whether a response body looks
  like an unsolved Cloudflare challenge page. Use it on the result of
  `fetch_url_via_flaresolverr()` (or your own fetch) to tell a real response apart from a
  block page.

## Version History

| Tag | Range | Description |
|---|---|---|
| **v1** | `d3c76c9` → `8e368e3` | initial commit, bare minimum plugin scaffold |
| **v2** | `6c211af` → `6f8fd53` | first feature-complete: Test Feed Fetch, per-feed sessions, stats, scan feeds, retry |
| **v3** | `806e557` → `8d339be` | major rewrite: 3 connection modes, 4 feed selectors, per-feed include/exclude, probe_cloudflare, cookie passthrough |
| **v3.5** | `6a70aa5` → `6cf5ccb` | UI card layout, per-feed challenge counters, help tooltips, URL validation, JSON save response, Notify.close fixes, session reset creates new session |
| **v3.6** | `f57fe92` → | inter-plugin API: `is_flaresolverr_enabled()`, `is_url_cloudflare_challenged()`, `fetch_url_via_flaresolverr()`, public `is_cloudflare_challenge()` so other plugins can reuse the FlareSolverr connection |

## Acknowledgments

- [Tiny Tiny RSS](https://tt-rss.org/)
- [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr)

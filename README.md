# fu_cloudflare

Bypass Cloudflare protection for RSS feeds using FlareSolverr.

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

## Version History

| Tag | Range | Description |
|---|---|---|
| **v1** | `d3c76c9` → `8e368e3` | initial commit, bare minimum plugin scaffold |
| **v2** | `6c211af` → `6f8fd53` | first feature-complete: Test Feed Fetch, per-feed sessions, stats, scan feeds, retry |
| **v3** | `806e557` → `8d339be` | major rewrite: 3 connection modes, 4 feed selectors, per-feed include/exclude, probe_cloudflare, cookie passthrough |
| **v3.5** | `6a70aa5` → `6cf5ccb` | UI card layout, per-feed challenge counters, help tooltips, URL validation, JSON save response, Notify.close fixes, session reset creates new session |

## Acknowledgments

- [Tiny Tiny RSS](https://tt-rss.org/)
- [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr)

# fu_cloudflare

Bypass Cloudflare protection for RSS feeds using FlareSolverr.

## Features

- Routes feed fetching through FlareSolverr to bypass Cloudflare JS challenges
- Global mode: all feeds through FlareSolverr
- Per-feed mode: enable only for specific feeds in the feed editor
- Built-in connection tester to validate FlareSolverr is working
- Configurable timeout per request

## Requirements

- [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr) running and accessible from your tt-rss server
- PHP curl extension

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

- **FlareSolverr URL**: address of your FlareSolverr instance (default: `http://localhost:8191`)
- **Max timeout**: maximum wait time in milliseconds (default: `60000`)
- **Mode**: `Per feed` or `All feeds through FlareSolverr`

Use the **Test Connection** button to verify FlareSolverr is reachable and can fetch a feed URL.

## Usage

### 1. Per-feed mode (default)

Right-click a feed → Edit → check "Use FlareSolverr to fetch this feed".

### 2. Global mode

Enable "All feeds through FlareSolverr" in the plugin settings. Every feed update will be routed through FlareSolverr.

## Troubleshooting

If feeds are still failing:

1. Check FlareSolverr is running: `curl http://localhost:8191/v1`
2. Verify the FlareSolverr URL in plugin settings
3. Test with a known Cloudflare-protected feed URL using the built-in tester
4. Check tt-rss logs for error messages
5. Increase timeout if feeds are slow

## Acknowledgments

- [Tiny Tiny RSS](https://tt-rss.org/)
- [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr)

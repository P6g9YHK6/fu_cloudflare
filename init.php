<?php
class Fu_Cloudflare extends Plugin {

	private $host;
	private $last_fetch_error = null;

	function about() {
		return array(null,
			"Bypass Cloudflare protection for RSS feeds using FlareSolverr",
			"P6g9YHK6",
			false,
			"https://github.com/P6g9YHK6/fu_cloudflare/");
	}

	function flags() {
		return array("needs_curl" => true);
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
		$host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
	}

	private function get_git_commit(): string {
		$git_dir = __DIR__ . '/.git';
		if (!file_exists($git_dir)) return '';

		$head_file = is_dir($git_dir) ? $git_dir . '/HEAD' : $git_dir;
		$head = @file_get_contents($head_file);
		if ($head === false) return '';

		$head = trim($head);

		if (str_starts_with($head, 'ref: ')) {
			$ref_path = __DIR__ . '/.git/' . substr($head, 5);
			$hash = @file_get_contents($ref_path);
			return $hash ? substr(trim($hash), 0, 7) : '';
		}

		return substr($head, 0, 7);
	}

	private function get_git_branch(): string {
		$git_dir = __DIR__ . '/.git';
		if (!file_exists($git_dir)) return '';

		$head_file = is_dir($git_dir) ? $git_dir . '/HEAD' : $git_dir;
		$head = @file_get_contents($head_file);
		if ($head === false) return '';

		if (preg_match('#refs/heads/(.+)#', $head, $m)) {
			return trim($m[1]);
		}
		return '';
	}

	private function check_version(): array {
		$local = $this->get_git_commit();
		if (!$local) return ['local' => '', 'latest' => '', 'up_to_date' => true];

		$cache = $this->host->get($this, 'version_cache', '');
		$time = (int)$this->host->get($this, 'version_cache_ts', 0);

		if ($cache && (time() - $time) < 3600) {
			$cached = json_decode($cache, true);
			$cached['local'] = $local;
			return $cached;
		}

		$branch = $this->get_git_branch();
		if (!$branch) $branch = 'master';

		$latest = '';
		$ctx = stream_context_create(['http' => [
			'timeout' => 5,
			'user_agent' => 'fu_cloudflare',
		]]);
		$res = @file_get_contents("https://api.github.com/repos/P6g9YHK6/fu_cloudflare/commits/$branch", false, $ctx);
		if ($res) {
			$data = json_decode($res, true);
			if (isset($data['sha'])) {
				$latest = substr($data['sha'], 0, 7);
			}
		}

		$result = [
			'local' => $local,
			'latest' => $latest,
			'up_to_date' => $latest ? $local === $latest : true,
		];

		$this->host->set($this, 'version_cache', json_encode($result));
		$this->host->set($this, 'version_cache_ts', (string)time());

		return $result;
	}

	function resetVersionCheck(): void {
		$this->host->set($this, 'version_cache', '');
		$this->host->set($this, 'version_cache_ts', '0');
		$ver = $this->check_version();
		$ver['branch'] = $this->get_git_branch();
		echo json_encode($ver);
	}

	private function load_feed_cookies($feed): array {
		$mode = $this->host->get($this, "connection_mode", "persistent");
		if ($mode !== 'cookies') return [[], ''];

		$cookies_json = $this->host->get($this, "cookies_$feed", '');
		$cookies = $cookies_json ? json_decode($cookies_json, true) : [];
		$ua = $this->host->get($this, "ua_$feed", '');
		return [$cookies, $ua];
	}

	private function store_feed_cookies($feed, array $result): void {
		$mode = $this->host->get($this, "connection_mode", "persistent");
		if ($mode !== 'cookies') return;
		$this->host->set($this, "cookies_$feed", json_encode($result['cookies'] ?? []));
		$this->host->set($this, "ua_$feed", $result['user_agent'] ?? '');
	}

	function get_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	function get_prefs_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	private function help_icon($text) {
		return "<span class='fu-help'><span class='fu-help-icon'>?</span><span class='fu-help-text'>" . htmlspecialchars($text) . "</span></span>";
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$mode = $this->host->get($this, "mode", "auto");
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "http://localhost:8191");
		$max_timeout = (int)$this->host->get($this, "max_timeout", 60000);
		$max_concurrent = (int)$this->host->get($this, "max_concurrent", 2);
		$connection_mode = $this->host->get($this, "connection_mode", "cookies");
		$per_feed = $this->host->get($this, "per_feed_sessions", "0");
		$retry_on_failure = $this->host->get($this, "retry_on_failure", "1");
		$retry_count = (int)$this->host->get($this, "retry_count", 5);
		$retry_base_delay = (float)$this->host->get($this, "retry_base_delay", 1);
		$retry_delay_factor = (int)$this->host->get($this, "retry_delay_factor", 2);
		$usage_ping = $this->host->get($this, "usage_ping_enabled", "1");
		$stats = $this->get_stats();

		$session_active = $this->host->get($this, "session_id", "");
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$excluded_feeds = $this->host->get_array($this, "excluded_feeds");
		$challenge_map = json_decode($this->host->get($this, "challenges_per_feed", "{}"), true) ?: [];
		?>
		<div dojoType='dijit.layout.AccordionPane'
			title="<i class='material-icons'>flash_on</i> <?= __('Cloudflare Bypass (fu_cloudflare)') ?>">

			<div class='fu-card'>
				<h3><?= __('Plugin Configuration') ?></h3>
				<form dojoType='dijit.form.Form'>
					<?= \Controls\pluginhandler_tags($this, "save") ?>
					<script type="dojo/method" event="onSubmit" args="evt">
						evt.preventDefault();
						if (this.validate()) {
							Notify.progress('Saving data...', true);
							xhr.post("backend.php", this.getValues(), (reply) => {
								Notify.close();
								try {
									var r = JSON.parse(reply);
									if (r.success) {
										Notify.info(r.message);
									} else {
										Notify.error(r.error);
									}
								} catch(e) {
									Notify.info(reply);
								}
							});
						}
					</script>

					<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 8px'>
						<fieldset>
							<label><?= __('Mode:') ?> <?= $this->help_icon('Controls which feeds go through FlareSolverr. per_feed: only included feeds. auto: quick probe then FS or bypass. all: every feed. disabled: off.') ?></label>
							<select dojoType='dijit.form.Select' name='mode'>
								<option value='per_feed' <?= $mode == 'per_feed' ? 'selected="selected"' : '' ?>>
									<?= __('Per-feed toggle') ?>
								</option>
								<option value='auto' <?= $mode == 'auto' ? 'selected="selected"' : '' ?>>
									<?= __('Auto-detect Cloudflare') ?>
								</option>
								<option value='all' <?= $mode == 'all' ? 'selected="selected"' : '' ?>>
									<?= __('All feeds') ?>
								</option>
								<option value='disabled' <?= $mode == 'disabled' ? 'selected="selected"' : '' ?>>
									<?= __('Disabled') ?>
								</option>
							</select>
						</fieldset>

						<fieldset>
							<label><?= __('Connection mode:') ?> <?= $this->help_icon('persistent: browser kept alive for JS challenges (100-200MB). cookies: carry cf_clearance between requests, no persistent browser. stateless: fresh browser each request, zero extra RAM.') ?></label>
							<select dojoType='dijit.form.Select' name='connection_mode'>
								<option value='persistent' <?= $connection_mode == 'persistent' ? 'selected="selected"' : '' ?>>
									<?= __('Persistent session') ?>
								</option>
								<option value='cookies' <?= $connection_mode == 'cookies' ? 'selected="selected"' : '' ?>>
									<?= __('Cookie passthrough') ?>
								</option>
								<option value='stateless' <?= $connection_mode == 'stateless' ? 'selected="selected"' : '' ?>>
									<?= __('Stateless') ?>
								</option>
							</select>
						</fieldset>

						<fieldset>
							<label><?= __('Max timeout (ms):') ?> <?= $this->help_icon('How long FlareSolverr waits for a page to load. Increase for slow PoW challenges. Default 60000, max 300000.') ?></label>
							<input dojoType='dijit.form.NumberSpinner' name='max_timeout'
								value='<?= $max_timeout ?>' smallDelta='5000' min='5000' max='300000'>
						</fieldset>

						<fieldset>
							<label><?= __('Max concurrent:') ?> <?= $this->help_icon('Parallel FlareSolverr requests. Each uses ~200-400MB RAM on the FS server. 0 = unlimited. Default 3.') ?></label>
							<input dojoType='dijit.form.NumberSpinner' name='max_concurrent'
								value='<?= $max_concurrent ?>' smallDelta='1' min='0' max='20'
								title='<?= __('0 = unlimited') ?>'>
						</fieldset>
					</div>

					<fieldset style='margin-top: 8px'>
						<label><?= __('FlareSolverr URL:') ?> <?= $this->help_icon('Full URL including protocol and port. Default http://localhost:8191. Must be reachable from this server.') ?></label>
						<input dojoType='dijit.form.TextBox' name='flaresolverr_url'
							value='<?= htmlspecialchars($flaresolverr_url) ?>' style='width: 100%'
							placeholder='http://localhost:8191'>
					</fieldset>

					<fieldset style='margin-top: 4px'>
						<label class='checkbox'>
							<?= \Controls\checkbox_tag("per_feed_sessions", $per_feed === "1") ?>
							<?= __('Per-feed sessions') ?>
							<?= $this->help_icon('Each feed gets its own isolated browser context. Prevents cookie/session leakage between feeds. Persistent mode only. Adds RAM overhead.') ?>
						</label>
					</fieldset>
					<fieldset style='margin-top: 4px'>
						<label class='checkbox'>
							<?= \Controls\checkbox_tag("retry_on_failure", $retry_on_failure === "1") ?>
							<?= __('Retry on transient failure') ?>
							<?= $this->help_icon('When enabled, creates a warmup request after session creation and retries once on any transient failure. Fixes first-request failures with cold browser sessions.') ?>
						</label>
					</fieldset>
					<fieldset style='margin-top: 4px'>
						<label class='checkbox'>
							<?= \Controls\checkbox_tag("usage_ping_enabled", $usage_ping === "1") ?>
							<?= __('Anonymous usage counter') ?>
							<?= $this->help_icon('Sends a single anonymous HTTPS request to GitHub when a Cloudflare challenge is solved. Counts total challenges solved across all users. No personal data is sent.') ?>
						</label>
					</fieldset>

					<div style='display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-top: 8px'>
						<fieldset>
							<label><?= __('Retry count:') ?> <?= $this->help_icon('Number of retries after initial attempt. Set to 0 to disable retries. Next delays: ' . $this->get_retry_delay_string(5)) ?></label>
							<input dojoType='dijit.form.NumberSpinner' name='retry_count'
								value='<?= $retry_count ?>' smallDelta='1' min='0' max='10'>
						</fieldset>

						<fieldset>
							<label><?= __('Base delay (s):') ?> <?= $this->help_icon('Initial delay before first retry. Next delays: ' . $this->get_retry_delay_string(5)) ?></label>
							<input dojoType='dijit.form.NumberSpinner' name='retry_base_delay'
								value='<?= $retry_base_delay ?>' smallDelta='0.5' min='0.1' max='60' constraints='{places:1}'>
						</fieldset>

						<fieldset>
							<label><?= __('Delay factor:') ?> <?= $this->help_icon('Each retry delay is multiplied by this factor (must be >= 2). Next delays: ' . $this->get_retry_delay_string(5)) ?></label>
							<input dojoType='dijit.form.NumberSpinner' name='retry_delay_factor'
								value='<?= $retry_delay_factor ?>' smallDelta='1' min='2' max='10'>
						</fieldset>
					</div>

					<div style='margin-top: 8px'><?= \Controls\submit_tag(__("Save Configuration")) ?></div>
				</form>
			</div>

			<div class='fu-card'>
				<h3><?= __('FlareSolverr') ?></h3>
				<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 16px'>
					<div>
						<h4><?= __('Health Check') ?> <?= $this->help_icon('Sends a request to FlareSolverr API to verify it is reachable. Shows version and response time.') ?></h4>
						<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.testFlareSolverr()'>
							<?= __('Test Connection') ?>
						</button>
						<div id='fu_flaresolverr_result' style='margin-top: 8px'></div>
					</div>
					<div>
						<h4><?= __('Session') ?> <?= $this->help_icon('Persistent session status. Reset clears all sessions and creates a fresh one on the next fetch.') ?></h4>
						<?php if ($connection_mode === 'persistent'): ?>
							<p><strong><?= __('Status:') ?></strong> <span id='fu_session_status'><?= $session_active ? __('Active') : __('None') ?></span></p>
							<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.resetFlareSolverrSession()'>
								<?= __('Reset Session') ?>
							</button>
							<div id='fu_session_result' style='margin-top: 8px'></div>
						<?php elseif ($connection_mode === 'cookies'): ?>
							<p class='text-muted'><?= __('Cookies stored from previous fetches are passed to the next request.') ?></p>
						<?php else: ?>
							<p class='text-muted'><?= __('Each request uses a fresh browser context.') ?></p>
						<?php endif; ?>
					</div>
				</div>
				<hr style='margin: 12px 0'>
				<h4><?= __('Test Feed Fetch') ?> <?= $this->help_icon('Enter any feed URL to test if FlareSolverr can bypass Cloudflare for it. Shows HTTP code, challenge status, and response body.') ?></h4>
				<div style='display: flex; gap: 8px; align-items: center; flex-wrap: wrap'>
					<input dojoType='dijit.form.TextBox' id='fu_test_url'
						value='https://www.flyer.co.uk/feed/' style='width: 400px'
						placeholder='https://www.flyer.co.uk/feed/'>
					<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.testFetchFeed()'>
						<?= __('Fetch') ?>
					</button>
				</div>
				<div id='fu_test_result' style='margin-top: 8px'></div>
			</div>

			<div class='fu-card'>
				<h3><?= __('Feeds & Statistics') ?></h3>
				<div style='display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-bottom: 12px'>
					<span><strong><?= __('OK:') ?></strong> <?= $stats['requests_ok'] ?> <?= $this->help_icon('Successful fetches since last reset.') ?></span>
					<span><strong><?= __('Challenges:') ?></strong> <span class='text-warning'><?= $stats['requests_challenge'] ?></span> <?= $this->help_icon('FlareSolverr returned a challenge page — it could not solve the challenge.') ?></span>
					<span><strong><?= __('Errors:') ?></strong> <span class='text-error'><?= $stats['requests_failed'] ?></span> <?= $this->help_icon('Network or server errors during fetch.') ?></span>
					<span><strong><?= __('Ratelimited:') ?></strong> <?= $stats['requests_ratelimited'] ?> <?= $this->help_icon('Requests skipped due to rate limiter.') ?></span>
					<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.resetStats()'>
						<?= __('Reset') ?>
					</button>
				</div>

				<h4><?= __('Scan All Feeds') ?> <?= $this->help_icon('Checks every feed in the database for Cloudflare via a quick HTTP request. Shows HTTP code, challenge detection, historical challenge count, and override status.') ?></h4>
				<p class='text-muted'><?= __('Check all feeds for Cloudflare challenges. Feeds returning a challenge can then be enabled in their feed editor.') ?></p>
				<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.scanFeeds()'>
					<?= __('Scan Now') ?>
				</button>
				<div id='fu_scan_result' style='margin-top: 8px'></div>

				<h4 style='margin-top: 16px'><?= __('Configured Feeds') ?> <?= $this->help_icon('Feeds with a per-feed override. [✓] = bypass via FlareSolverr, [✗] = excluded from FlareSolverr. Number = challenge count since last reset.') ?></h4>
				<?php
					$all_feed_ids = array_merge($enabled_feeds, $excluded_feeds);
					$all_feed_ids = array_map('intval', $all_feed_ids);

					if ($all_feed_ids) {
						$placeholders = implode(',', array_fill(0, count($all_feed_ids), '?'));
						$sth = $this->pdo->prepare(
							"SELECT id, title, feed_url FROM ttrss_feeds WHERE id IN ($placeholders) ORDER BY title"
						);
						$sth->execute($all_feed_ids);

						echo "<ul class='panel panel-scrollable' style='max-height: 200px; overflow-y: auto'>";
						foreach ($sth as $f) {
							$icon = '';
							if (in_array($f['id'], $enabled_feeds)) {
								$icon = ' <span class=\"text-success\">[✓]</span>';
							} elseif (in_array($f['id'], $excluded_feeds)) {
								$icon = ' <span class=\"text-warning\">[✗]</span>';
							}
							$cc = $challenge_map[(string)$f['id']] ?? 0;
							$challenge_tag = $cc > 0 ? " <span class='text-warning'>({$cc} challenged)</span>" : '';
							echo "<li><a href='prefs.php?op=prefFeeds' target='_blank'>" . htmlspecialchars($f['title']) . "</a>" .
								$icon .
								$challenge_tag .
								" <span class='text-muted'>(" . htmlspecialchars($f['feed_url']) . ")</span></li>";
						}
						echo "</ul>";
						echo "<p class='text-muted'>" . count($enabled_feeds) . " " . __('included,') . " " . count($excluded_feeds) . " " . __('excluded') . "</p>";
					} else {
						echo "<p class='text-muted'>" . __('No feeds configured. Open a feed\'s editor to set per-feed FlareSolverr behavior.') . "</p>";
					}
				?>
			</div>

			<div style='display: flex; justify-content: space-between; align-items: center; font-size: 0.85em; margin-top: 12px'>
				<span class='text-muted' id='fu_version_info'>
					<?php
						$commit = $this->get_git_commit();
						$branch = $this->get_git_branch();
						if ($commit) {
							echo __('Version:') . " <code>$commit</code>";
							if ($branch) echo " ($branch)";
							echo ' ';
							$ver = $this->check_version();
							if ($ver['up_to_date']) {
								echo "<span class='text-success'>✓ " . __('up to date') . "</span>";
							} elseif ($ver['latest']) {
								echo "<span class='text-warning'>⚠ " . __('New version available:') . " <code>{$ver['latest']}</code></span>";
							}
						} else {
							echo __('Version:') . " <code>" . __('unknown') . "</code>";
						}
					?>
				</span>
				<span><a href='#' onclick='Plugins.Fu_Cloudflare.checkVersion(); return false;'><?= __('Check now') ?></a></span>
			</div>
		</div>

		<style>
			.fu-card { border: 1px solid rgba(128,128,128,0.3); border-radius: 6px; padding: 16px; margin-bottom: 16px; }
			.fu-card h3 { margin: 0 0 12px 0; font-size: 1.1em; }
			.fu-card h4 { margin: 0 0 6px 0; font-size: 0.95em; }
			.fu-card fieldset { margin: 0; }
			.fu-help { position: relative; cursor: help; }
			.fu-help-icon { display: inline-flex; width: 15px; height: 15px; border-radius: 50%; background: rgba(128,128,128,0.4); color: inherit; align-items: center; justify-content: center; font-size: 10px; font-weight: bold; margin-left: 4px; vertical-align: middle; }
			.fu-help-text { display: none; position: absolute; left: 0; top: 22px; z-index: 1000; background: #333; color: #fff; padding: 10px 14px; border-radius: 6px; font-size: 12px; white-space: normal; width: 320px; line-height: 1.5; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
			.fu-help:hover .fu-help-text { display: block; }
		</style>
		<?php
	}

	function save() : void {
		$mode = clean($_POST["mode"] ?? "per_feed");
		$flaresolverr_url = clean($_POST["flaresolverr_url"] ?? "");
		$max_timeout = (int)($_POST["max_timeout"] ?? 60000);
		$max_concurrent = (int)($_POST["max_concurrent"] ?? 3);
		$connection_mode = clean($_POST["connection_mode"] ?? "persistent");
		$per_feed = checkbox_to_sql_bool($_POST["per_feed_sessions"] ?? "") ? "1" : "0";
		$retry_on_failure = checkbox_to_sql_bool($_POST["retry_on_failure"] ?? "") ? "1" : "0";
		$retry_count = max(0, (int)($_POST["retry_count"] ?? 1));
		$retry_base_delay = max(0.1, (float)($_POST["retry_base_delay"] ?? 1));
		$retry_delay_factor = max(2, (int)($_POST["retry_delay_factor"] ?? 2));
		$usage_ping = checkbox_to_sql_bool($_POST["usage_ping_enabled"] ?? "") ? "1" : "0";

		if (!$flaresolverr_url || !filter_var($flaresolverr_url, FILTER_VALIDATE_URL)) {
			echo json_encode(["success" => false, "error" => __("Invalid FlareSolverr URL.")]);
			return;
		}

		$this->host->set($this, "mode", $mode);
		$this->host->set($this, "flaresolverr_url", $flaresolverr_url);
		$this->host->set($this, "max_timeout", $max_timeout);
		$this->host->set($this, "max_concurrent", $max_concurrent);
		$this->host->set($this, "connection_mode", $connection_mode);
		$this->host->set($this, "per_feed_sessions", $per_feed);
		$this->host->set($this, "retry_on_failure", $retry_on_failure);
		$this->host->set($this, "retry_count", $retry_count);
		$this->host->set($this, "retry_base_delay", $retry_base_delay);
		$this->host->set($this, "retry_delay_factor", $retry_delay_factor);
		$this->host->set($this, "usage_ping_enabled", $usage_ping);

		echo json_encode(["success" => true, "message" => __("Data saved.")]);
	}

	function testFlareSolverr() : void {
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "http://localhost:8191");
		if (!$flaresolverr_url) {
			echo json_encode(["success" => false, "error" => __("FlareSolverr URL is not configured.")]);
			return;
		}

		$start = microtime(true);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, rtrim($flaresolverr_url, '/') . '/v1');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
			'cmd' => 'request.get',
			'url' => 'https://example.com',
			'maxTimeout' => 10000,
		]));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		$elapsed = round(microtime(true) - $start, 2);

		if ($http_code == 200 && $response) {
			$data = json_decode($response, true);
			if (isset($data['solution']['response'])) {
				$version = $data['version'] ?? 'unknown';
				$body_size = strlen($data['solution']['response']);
				echo json_encode([
					"success" => true,
					"time" => $elapsed,
					"version" => $version,
					"body_size" => $body_size,
				]);
				return;
			}
			$error = $data['error'] ?? "HTTP 200 but no solution returned";
			echo json_encode(["success" => false, "error" => $error]);
			return;
		}

		if ($curl_error) {
			echo json_encode(["success" => false, "error" => "cURL error: $curl_error"]);
			return;
		}

		echo json_encode(["success" => false, "error" => "FlareSolverr returned HTTP $http_code"]);
	}

	function testFetchFeed() : void {
		$url = clean($_POST['test_url'] ?? '');
		if (!$url) {
			echo json_encode(["success" => false, "error" => "Please enter a URL."]);
			return;
		}

		$steps = [];
		$start = microtime(true);
		$t = fn() => round(microtime(true) - $start, 2);

		$mode = $this->host->get($this, "mode", "auto");
		$connection_mode = $this->host->get($this, "connection_mode", "cookies");
		$retry_on_failure = $this->host->get($this, "retry_on_failure", "1");
		$retry_count = (int)$this->host->get($this, "retry_count", 5);
		$retry_base_delay = (float)$this->host->get($this, "retry_base_delay", 1);
		$retry_delay_factor = (int)$this->host->get($this, "retry_delay_factor", 2);
		$max_timeout = (int)$this->host->get($this, "max_timeout", 60000);
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");

		$steps[] = [
			"step" => "Configuration",
			"detail" => "target=" . $url . ", flaresolverr=" . $flaresolverr_url . ", mode=" . ($mode ?: "per_feed") . ", connection_mode=" . ($connection_mode ?: "persistent") . ", retry=" . ($retry_on_failure === "1" ? "yes(" . $retry_count . "x, base=" . $retry_base_delay . "s, factor=" . $retry_delay_factor . ")" : "no") . ", max_timeout=" . $max_timeout . "ms",
			"time" => $t(),
		];

		if (!$flaresolverr_url) {
			$steps[] = ["step" => "Error", "detail" => "FlareSolverr URL is not configured.", "time" => $t()];
			echo json_encode(["success" => false, "steps" => $steps, "time" => $t()]);
			return;
		}

		if ($mode === "disabled") {
			$steps[] = ["step" => "Skipped", "detail" => "Plugin is disabled (mode=disabled). Feed not fetched via FlareSolverr.", "time" => $t()];
			echo json_encode(["success" => true, "steps" => $steps, "time" => $t()]);
			return;
		}

		if ($mode === "per_feed") {
			$steps[] = ["step" => "Feed", "detail" => "mode=per_feed: feed ID must be in enabled_feeds list. This test URL is always fetched regardless for diagnostics.", "time" => $t()];
		} elseif ($mode === "all") {
			$steps[] = ["step" => "Feed", "detail" => "mode=all: all feeds routed through FlareSolverr.", "time" => $t()];
		} elseif ($mode === "auto") {
			$steps[] = ["step" => "Feed", "detail" => "mode=auto: probing URL for Cloudflare detection...", "time" => $t()];
			$probe_body = $this->probe_cloudflare($url);
			if ($probe_body === false) {
				$steps[] = ["step" => "Probe", "detail" => "Probe failed (network error or timeout). Would fall through to tt-rss direct fetch. Attempting FlareSolverr anyway for diagnostics.", "time" => $t()];
			} elseif ($this->is_cloudflare_challenge($probe_body)) {
				$steps[] = ["step" => "Probe", "detail" => "Cloudflare challenge detected. Would route to FlareSolverr.", "time" => $t()];
			} else {
				$probe_title = '';
				if (preg_match('/<title>(.*?)<\/title>/is', $probe_body, $m)) {
					$probe_title = trim(strip_tags($m[1]));
				}
				$steps[] = ["step" => "Probe", "detail" => "No Cloudflare detected, probe returned " . strlen($probe_body) . " bytes" . ($probe_title ? " (title: \"$probe_title\")" : "") . ". Would return directly without FlareSolverr.", "time" => $t()];
				echo json_encode([
					"success" => true,
					"steps" => $steps,
					"time" => $t(),
					"title" => $probe_title,
					"body_size" => strlen($probe_body),
					"note" => "Probe returned clean HTML — FlareSolverr not needed for this URL",
				]);
				return;
			}
		}

		$session = null;
		$cookies = [];
		$ua = '';

		if ($connection_mode === "persistent") {
			$steps[] = ["step" => "Session", "detail" => "connection_mode=persistent: creating session...", "time" => $t()];
			$session = $this->get_session($flaresolverr_url);
			if ($session) {
				$steps[] = ["step" => "Session", "detail" => "Session $session" . ($retry_on_failure === "1" ? " (warmed up)" : ""), "time" => $t()];
			} else {
				$steps[] = ["step" => "Session", "detail" => "Failed to create session. Proceeding without session.", "time" => $t()];
			}
		} elseif ($connection_mode === "cookies") {
			$steps[] = ["step" => "Cookies", "detail" => "connection_mode=cookies: creating session for cookie passthrough...", "time" => $t()];
			$session = $this->get_session($flaresolverr_url);
			if ($session) {
				$steps[] = ["step" => "Cookies", "detail" => "Session $session created for cookie passthrough.", "time" => $t()];
			} else {
				$steps[] = ["step" => "Cookies", "detail" => "Failed to create session for cookie passthrough.", "time" => $t()];
			}
		} else {
			$steps[] = ["step" => "Stateless", "detail" => "connection_mode=stateless: fresh browser each request, no session.", "time" => $t()];
		}

		$initial_fetch_detail = "Fetching via FlareSolverr (timeout=" . ($max_timeout / 1000) . "s, session=" . ($session ?: "none") . ", cookies=" . count($cookies) . ")...";
		$steps[] = ["step" => "Fetch", "detail" => $initial_fetch_detail, "time" => $t()];
		$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url, $session, $cookies, $ua);

		if ($retry_on_failure === "1" && $retry_count > 0) {
			for ($attempt = 1; $attempt <= $retry_count; $attempt++) {
				if ($result['success'] && !$this->is_cloudflare_challenge($result['data'])) {
					$steps[] = ["step" => "Fetch", "detail" => "Attempt $attempt: HTTP " . ($result['http_code'] ?? '?') . ", " . strlen($result['data']) . " bytes, cookies=" . count($result['cookies'] ?? []) . " — OK", "time" => $t()];
					break;
				}

				$delay = $this->get_retry_delay($attempt);
				$cf_status = isset($result['data']) && $this->is_cloudflare_challenge($result['data']) ? "CF-CHALLENGE" : "FAILED";
				$steps[] = ["step" => "Fetch", "detail" => "Attempt $attempt: HTTP " . ($result['http_code'] ?? '?') . ", " . strlen($result['data'] ?? '') . " bytes, cookies=" . count($result['cookies'] ?? []) . " — $cf_status. Retrying after {$delay}s...", "time" => $t()];
				sleep($delay);
				$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url, $session, $cookies, $ua);
			}
		}

		if (!$result['success']) {
			$steps[] = ["step" => "Fetch", "detail" => "Fetch failed: {$result['error']}", "time" => $t()];
			echo json_encode([
				"success" => false,
				"steps" => $steps,
				"time" => $t(),
				"error" => $result['error'],
			]);
			return;
		}

		$is_cf = $this->is_cloudflare_challenge($result['data']);
		if ($is_cf && $connection_mode === "persistent") {
			$steps[] = ["step" => "Session", "detail" => "Still challenged (HTTP " . ($result['http_code'] ?? '?') . ", " . strlen($result['data']) . " bytes, " . count($result['cookies'] ?? []) . " cookies), trying fresh session...", "time" => $t()];
			$this->host->set($this, $this->get_session_key(), "");
			$session = $this->get_session($flaresolverr_url);
			$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url, $session, $cookies, $ua);
			if ($result['success'] && !$this->is_cloudflare_challenge($result['data'])) {
				$is_cf = false;
				$steps[] = ["step" => "Fetch", "detail" => "Fresh session: HTTP " . ($result['http_code'] ?? '?') . ", " . strlen($result['data']) . " bytes, cookies=" . count($result['cookies'] ?? []) . " — SUCCESS", "time" => $t()];
			}
		}

		$http_code = $result['http_code'] ?? '?';
		if ($is_cf) {
			$steps[] = ["step" => "Result", "detail" => "HTTP $http_code, " . strlen($result['data']) . " bytes, cookies=" . count($result['cookies'] ?? []) . ", UA=" . ($result['user_agent'] ?? 'none') . " — FAILED: Cloudflare challenge not solved", "time" => $t()];
		} else {
			$title = '';
			if (preg_match('/<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/title>/is', $result['data'], $m)) {
				$title = trim(strip_tags(html_entity_decode($m[1])));
			}
			if (!$title && preg_match('/<channel>.*?<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/title>/is', $result['data'], $m)) {
				$title = trim(strip_tags(html_entity_decode($m[1])));
			}
			$detail = "HTTP $http_code, " . strlen($result['data']) . " bytes, cookies=" . count($result['cookies'] ?? []) . ", UA=" . ($result['user_agent'] ?? 'none');
			if ($title) $detail .= ", title=\"$title\"";
			$detail .= " — SUCCESS";
			$steps[] = ["step" => "Result", "detail" => $detail, "time" => $t()];
		}

		echo json_encode([
			"success" => !$is_cf,
			"steps" => $steps,
			"time" => $t(),
			"http_code" => $http_code,
			"body_size" => strlen($result['data']),
			"cookies_count" => count($result['cookies'] ?? []),
		]);
	}

	function hook_prefs_edit_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$excluded_feeds = $this->host->get_array($this, "excluded_feeds");
		$current = 'default';
		if (in_array($feed_id, $enabled_feeds)) $current = 'include';
		if (in_array($feed_id, $excluded_feeds)) $current = 'exclude';
		?>
		<header><?= __('Cloudflare Bypass') ?></header>
		<section>
			<fieldset>
				<label><?= __('FlareSolverr:') ?></label>
				<select dojoType='dijit.form.Select' name='fu_cloudflare_mode'>
					<option value='' <?= $current == 'default' ? 'selected="selected"' : '' ?>>
						<?= __('Default (use global mode)') ?>
					</option>
					<option value='include' <?= $current == 'include' ? 'selected="selected"' : '' ?>>
						<?= __('Always bypass via FlareSolverr') ?>
					</option>
					<option value='exclude' <?= $current == 'exclude' ? 'selected="selected"' : '' ?>>
						<?= __('Never use FlareSolverr') ?>
					</option>
				</select>
			</fieldset>
		</section>
		<?php
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$excluded_feeds = $this->host->get_array($this, "excluded_feeds");
		$val = $_POST["fu_cloudflare_mode"] ?? '';

		$ek = array_search($feed_id, $enabled_feeds);
		if ($ek !== false) unset($enabled_feeds[$ek]);
		$xk = array_search($feed_id, $excluded_feeds);
		if ($xk !== false) unset($excluded_feeds[$xk]);

		if ($val === 'include') {
			array_push($enabled_feeds, $feed_id);
		} elseif ($val === 'exclude') {
			array_push($excluded_feeds, $feed_id);
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
		$this->host->set($this, "excluded_feeds", $excluded_feeds);
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass) {
		$mode = $this->host->get($this, "mode", "auto");

		if ($mode === "disabled") {
			Debug::log("fu_cloudflare: disabled", Debug::LOG_VERBOSE);
			return $feed_data;
		}

		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if (!$flaresolverr_url) {
			Debug::log("fu_cloudflare: FlareSolverr URL not configured", Debug::LOG_VERBOSE);
			return $feed_data;
		}

		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$excluded_feeds = $this->host->get_array($this, "excluded_feeds");

		if (in_array($feed, $excluded_feeds)) {
			Debug::log("fu_cloudflare: feed $feed excluded", Debug::LOG_VERBOSE);
			return $feed_data;
		}

		if ($mode === "per_feed") {
			if (!in_array($feed, $enabled_feeds)) {
				Debug::log("fu_cloudflare: feed $feed not in enabled list", Debug::LOG_VERBOSE);
				return $feed_data;
			}
		}

		if ($mode === "auto") {
			if (in_array($feed, $enabled_feeds)) {
				Debug::log("fu_cloudflare: feed $feed manually enabled", Debug::LOG_VERBOSE);
			} else {
				$probe_body = $this->probe_cloudflare($fetch_url);
				if ($probe_body === false) {
					Debug::log("fu_cloudflare: probe failed for feed $feed, letting tt-rss handle it", Debug::LOG_VERBOSE);
					return $feed_data;
				}
				if ($this->is_cloudflare_challenge($probe_body)) {
					$this->increment_challenge_count($feed);
					Debug::log("fu_cloudflare: probe detected Cloudflare on feed $feed", Debug::LOG_VERBOSE);
				} else {
					Debug::log("fu_cloudflare: probe clean for feed $feed, returning directly", Debug::LOG_VERBOSE);
					return $probe_body;
				}
			}
		}

		$fs_mode = $this->host->get($this, "connection_mode", "cookies");
		$session = $this->get_session($flaresolverr_url, $feed);
		if ($session) {
			Debug::log("fu_cloudflare: using session $session", Debug::LOG_VERBOSE);
		}

		$cookies = [];
		$ua = '';
		if ($fs_mode === 'cookies') {
			list($cookies, $ua) = $this->load_feed_cookies($feed);
			if ($cookies) {
				Debug::log("fu_cloudflare: using " . count($cookies) . " stored cookies for feed $feed", Debug::LOG_VERBOSE);
			}
		}

		Debug::log("fu_cloudflare: fetching feed $feed via FlareSolverr...", Debug::LOG_VERBOSE);
		$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session, $cookies, $ua);

		$retry_count = (int)$this->host->get($this, "retry_count", 5);
		$retry_on_failure = $this->host->get($this, "retry_on_failure", "1") === "1";

		if ($retry_on_failure && $retry_count > 0) {
			for ($attempt = 1; $attempt <= $retry_count; $attempt++) {
				$should_retry = false;
				if (empty($result['success'])) {
					$should_retry = true;
					Debug::log("fu_cloudflare: attempt $attempt failed, retrying for feed $feed...", Debug::LOG_VERBOSE);
				} elseif ($this->is_cloudflare_challenge($result['data'])) {
					$should_retry = true;
					$backup_timeout = (int)$this->host->get($this, "max_timeout", 60000);
					$doubled = min($backup_timeout * 2, 300000);
					$this->host->set($this, "max_timeout", $doubled);
					Debug::log("fu_cloudflare: challenge present, retry $attempt for feed $feed (timeout: {$doubled}ms)...", Debug::LOG_VERBOSE);
				}

				if (!$should_retry) break;

				$delay = $this->get_retry_delay($attempt);
				sleep($delay);
				$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session, $cookies, $ua);
				$this->host->set($this, "max_timeout", $backup_timeout);

				if (!empty($result['success']) && !$this->is_cloudflare_challenge($result['data'])) {
					break;
				}
			}
		}

		if (!empty($result['success']) && !$this->is_cloudflare_challenge($result['data'])) {
			$this->increment_stat('stats_requests_ok');
			$this->ping_usage_counter();
			Debug::log("fu_cloudflare: FlareSolverr OK (" . strlen($result['data']) . " bytes) for feed $feed", Debug::LOG_VERBOSE);
			$this->store_feed_cookies($feed, $result);
			return $result['data'];
		}

		if ($this->is_cloudflare_challenge($result['data'])) {
			$this->increment_stat('stats_requests_challenge');
			$this->increment_challenge_count($feed);
			Debug::log("fu_cloudflare: FlareSolverr returned a challenge for feed $feed", Debug::LOG_VERBOSE);
		} else {
			$this->increment_stat('stats_requests_failed');
			Debug::log("fu_cloudflare: FlareSolverr failed for feed $feed", Debug::LOG_VERBOSE);
		}

		if (($fs_mode === 'persistent' || $fs_mode === 'cookies') && !empty($this->last_fetch_error['session_error'])) {
			Debug::log("fu_cloudflare: session error, trying fresh session...", Debug::LOG_VERBOSE);
			$this->host->set($this, $this->get_session_key($feed), "");
			$session = $this->get_session($flaresolverr_url, $feed);
			$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session, $cookies, $ua);
			if (!empty($result['success']) && !$this->is_cloudflare_challenge($result['data'])) {
				$this->increment_stat('stats_requests_ok');
				$this->ping_usage_counter();
				$this->store_feed_cookies($feed, $result);
				return $result['data'];
			}
		}

		return $feed_data;
	}

	private function probe_cloudflare($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; tt-rss)');
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		curl_close($ch);
		if ($errno) return false;
		return $body;
	}

	private function get_retry_delays($count = 5) {
		$base = (float)$this->host->get($this, "retry_base_delay", 1);
		$factor = (int)$this->host->get($this, "retry_delay_factor", 2);
		$delays = [];
		for ($i = 0; $i < $count; $i++) {
			$delays[] = $base * pow($factor, $i);
		}
		return $delays;
	}

	private function get_retry_delay($attempt) {
		$base = (float)$this->host->get($this, "retry_base_delay", 1);
		$factor = (int)$this->host->get($this, "retry_delay_factor", 2);
		return $base * pow($factor, $attempt - 1);
	}

	private function get_retry_delay_string($count = 5) {
		$delays = $this->get_retry_delays($count);
		$parts = [];
		foreach ($delays as $i => $d) {
			$parts[] = ($i + 1) . ": " . $this->format_delay($d);
		}
		return implode(", ", $parts);
	}

	private function format_delay($seconds) {
		if ($seconds >= 60) {
			return round($seconds / 60, 1) . "m";
		}
		return $seconds . "s";
	}

	private function is_cloudflare_challenge($data) {
		if (preg_match('/<(title|h1|head)>.*(Checking your browser|Just a moment\.\.\.)/is', $data)) return true;
		if (preg_match('/\/__challenge/', $data)) return true;
		if (preg_match('/Attention Required.*Cloudflare/i', $data)) return true;
		return false;
	}

	private function increment_stat($key) {
		$val = (int)$this->host->get($this, $key, 0);
		$this->host->set($this, $key, $val + 1);
	}

	private function ping_usage_counter() {
		if ($this->host->get($this, "usage_ping_enabled", "1") !== "1") return;

		$url = "https://github.com/P6g9YHK6/fu_cloudflare/releases/download/usage-counter/ping.txt";
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 3,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_HTTPHEADER => ["User-Agent: fu_cloudflare/usage-counter"],
		]);
		curl_exec($ch);
		curl_close($ch);
	}

	private function increment_challenge_count($feed) {
		$map = json_decode($this->host->get($this, "challenges_per_feed", "{}"), true) ?: [];
		$map[(string)$feed] = ($map[(string)$feed] ?? 0) + 1;
		$this->host->set($this, "challenges_per_feed", json_encode($map));
	}

	private function get_stats() {
		return [
			'requests_ok' => (int)$this->host->get($this, 'stats_requests_ok', 0),
			'requests_challenge' => (int)$this->host->get($this, 'stats_requests_challenge', 0),
			'requests_failed' => (int)$this->host->get($this, 'stats_requests_failed', 0),
			'requests_ratelimited' => (int)$this->host->get($this, 'stats_requests_ratelimited', 0),
		];
	}

	function resetStats() : void {
		foreach (['stats_requests_ok', 'stats_requests_challenge', 'stats_requests_failed', 'stats_requests_ratelimited'] as $k) {
			$this->host->set($this, $k, 0);
		}
		$this->host->set($this, "challenges_per_feed", "{}");
		echo json_encode(["success" => true]);
	}

	private function get_session_key($feed = null) {
		if ($this->host->get($this, "per_feed_sessions", "0") === "1" && $feed !== null) {
			return "session_id_$feed";
		}
		return "session_id";
	}

	private function get_session($flaresolverr_url, $feed = null) {
		$mode = $this->host->get($this, "connection_mode", "cookies");
		if ($mode !== 'persistent' && $mode !== 'cookies') return null;

		$key = $this->get_session_key($feed);
		$session = $this->host->get($this, $key, "");
		if ($session) return $session;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, rtrim($flaresolverr_url, '/') . '/v1');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['cmd' => 'sessions.create']));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($http_code == 200 && $response) {
			$data = json_decode($response, true);
			if (isset($data['session'])) {
				$this->host->set($this, $key, $data['session']);
				Debug::log("fu_cloudflare: created session {$data['session']} (mode=$mode)", Debug::LOG_VERBOSE);

				if ($this->host->get($this, "retry_on_failure", "1") === "1") {
					$warmup = curl_init();
					curl_setopt_array($warmup, [
						CURLOPT_URL => rtrim($flaresolverr_url, '/') . '/v1',
						CURLOPT_POST => 1,
						CURLOPT_POSTFIELDS => json_encode(['cmd' => 'request.get', 'url' => 'about:blank', 'session' => $data['session'], 'maxTimeout' => 5000]),
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
						CURLOPT_TIMEOUT => 10,
					]);
					curl_exec($warmup);
					curl_close($warmup);
					Debug::log("fu_cloudflare: session warmed up", Debug::LOG_VERBOSE);
				}

				return $data['session'];
			}
		}
		return null;
	}

	function resetSession() : void {
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		$mode = $this->host->get($this, "connection_mode", "cookies");
		$session = null;

		if ($mode === 'persistent') {
			if ($this->host->get($this, "per_feed_sessions", "0") === "1") {
				$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
				foreach ($enabled_feeds as $fid) {
					$this->host->set($this, "session_id_$fid", "");
				}
			}
			$this->host->set($this, "session_id", "");

			if ($flaresolverr_url) {
				$session = $this->get_session($flaresolverr_url);
			}
		}

		if ($mode === 'cookies') {
			$all = array_merge(
				$this->host->get_array($this, "enabled_feeds"),
				$this->host->get_array($this, "excluded_feeds")
			);
			foreach ($all as $fid) {
				$this->host->set($this, "cookies_$fid", "");
				$this->host->set($this, "ua_$fid", "");
			}
			if ($this->host->get($this, "per_feed_sessions", "0") === "1") {
				$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
				foreach ($enabled_feeds as $fid) {
					$this->host->set($this, "session_id_$fid", "");
				}
			}
			$this->host->set($this, "session_id", "");

			if ($flaresolverr_url) {
				$session = $this->get_session($flaresolverr_url);
			}
		}

		echo json_encode([
			"success" => true,
			"session" => $session,
			"message" => __("Session/cookies cleared."),
		]);
	}

	function scanFeeds() : void {
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if (!$flaresolverr_url) {
			echo json_encode(["success" => false, "error" => __("Configure FlareSolverr URL first.")]);
			return;
		}

		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$excluded_feeds = $this->host->get_array($this, "excluded_feeds");
		$challenge_map = json_decode($this->host->get($this, "challenges_per_feed", "{}"), true) ?: [];
		$sth = $this->pdo->query("SELECT id, title, feed_url FROM ttrss_feeds ORDER BY title");

		$multi = curl_multi_init();
		$handles = [];
		$feed_map = [];

		foreach ($sth as $row) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $row['feed_url']);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; tt-rss)');
			curl_multi_add_handle($multi, $ch);
			$handles[(int)$ch] = $ch;
			$feed_map[(int)$ch] = [
				'id' => $row['id'],
				'title' => $row['title'],
				'already_enabled' => in_array($row['id'], $enabled_feeds),
				'excluded' => in_array($row['id'], $excluded_feeds),
				'challenge_count' => $challenge_map[(string)$row['id']] ?? 0,
			];
		}

		$running = null;
		do {
			curl_multi_exec($multi, $running);
			if ($running > 0) curl_multi_select($multi, 5);
		} while ($running > 0);

		$results = [];
		foreach ($handles as $id => $ch) {
			$info = $feed_map[$id];
			$body = curl_multi_getcontent($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_multi_remove_handle($multi, $ch);
			curl_close($ch);

			$results[] = [
				'id' => $info['id'],
				'title' => $info['title'],
				'http_code' => $http_code,
				'is_cloudflare' => $body ? $this->is_cloudflare_challenge($body) : false,
				'already_enabled' => $info['already_enabled'],
				'excluded' => $info['excluded'],
				'challenge_count' => $info['challenge_count'],
			];
		}

		curl_multi_close($multi);
		echo json_encode(["success" => true, "feeds" => $results]);
	}

	private function fetch_with_rate_limit($url, $flaresolverr_url, $session = null, $cookies = [], $ua = '') {
		$this->last_fetch_error = null;
		if ($this->acquire_flaresolverr_slot()) {
			$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url, $session, $cookies, $ua);
			$this->release_flaresolverr_slot();
			if ($result['success']) {
				if (!empty($result['user_agent']) || !empty($result['cookies'])) {
					Debug::log(sprintf(
						"fu_cloudflare: meta — solved=%s, ua=%s, cookies=%d",
						!empty($result['challenge_solved']) ? 'true' : 'false',
						$result['user_agent'] ?? '-',
						count($result['cookies'] ?? [])
					), Debug::LOG_VERBOSE);
				}
				return $result;
			}
			$this->last_fetch_error = $result;
		} else {
			$this->last_fetch_error = ['session_error' => false, 'error' => 'rate_limit'];
			$this->increment_stat('stats_requests_ratelimited');
		}
		return ['success' => false];
	}

	private function acquire_flaresolverr_slot() {
		$max_concurrent = (int)$this->host->get($this, "max_concurrent", 2);
		if ($max_concurrent < 1) return true;

		$file = sys_get_temp_dir() . '/fu_cloudflare_semaphore';
		$fp = @fopen($file, 'c+');
		if (!$fp) return true;

		flock($fp, LOCK_EX);
		$count = (int)trim(fread($fp, 1024));

		if ($count >= $max_concurrent) {
			flock($fp, LOCK_UN);
			fclose($fp);
			return false;
		}

		ftruncate($fp, 0);
		fwrite($fp, (string)($count + 1));
		fflush($fp);
		flock($fp, LOCK_UN);
		fclose($fp);

		return true;
	}

	private function release_flaresolverr_slot() {
		$file = sys_get_temp_dir() . '/fu_cloudflare_semaphore';
		$fp = @fopen($file, 'c+');
		if (!$fp) return;

		flock($fp, LOCK_EX);
		$count = (int)trim(fread($fp, 1024));
		if ($count > 0) {
			ftruncate($fp, 0);
			fwrite($fp, (string)($count - 1));
			fflush($fp);
		}
		flock($fp, LOCK_UN);
		fclose($fp);
	}

	private function fetch_via_flaresolverr($url, $flaresolverr_url, $session = null, $cookies = [], $ua = '') {
		$timeout = (int)$this->host->get($this, "max_timeout", 60000);

		$body = [
			'cmd' => 'request.get',
			'url' => $url,
			'maxTimeout' => $timeout,
		];
		if ($session) $body['session'] = $session;
		if ($cookies) $body['cookies'] = $cookies;
		if ($ua) $body['userAgent'] = $ua;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, rtrim($flaresolverr_url, '/') . '/v1');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, (int)ceil($timeout / 1000) + 10);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($http_code == 200 && $response) {
			$data = json_decode($response, true);
			if (isset($data['solution']['response'])) {
				return [
					'success' => true,
					'data' => $data['solution']['response'],
					'http_code' => $http_code,
					'challenge_solved' => !empty($data['solution']['challenge']),
					'user_agent' => $data['solution']['userAgent'] ?? '',
					'cookies' => $data['solution']['cookies'] ?? [],
				];
			}
			if (isset($data['error'])) {
				$is_session_error = str_contains($data['error'], 'Session not found');
				return ['success' => false, 'error' => $data['error'], 'http_code' => $http_code, 'session_error' => $is_session_error];
			}
			return ['success' => false, 'error' => "HTTP $http_code: FlareSolverr returned no solution", 'http_code' => $http_code];
		}

		if ($curl_error) {
			return ['success' => false, 'error' => "cURL error: $curl_error", 'http_code' => $http_code];
		}

		return ['success' => false, 'error' => "FlareSolverr returned HTTP $http_code", 'http_code' => $http_code];
	}

	function api_version() {
		return 2;
	}
}

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

	function get_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	function get_prefs_js() {
		return file_get_contents(__DIR__ . "/init.js");
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$enabled = $this->host->get($this, "enabled", "1");
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "http://localhost:8191");
		$max_timeout = (int)$this->host->get($this, "max_timeout", 60000);
		$max_concurrent = (int)$this->host->get($this, "max_concurrent", 3);
		$per_feed = $this->host->get($this, "per_feed_sessions", "0");
		$stats = $this->get_stats();
		?>
		<div dojoType='dijit.layout.AccordionPane'
			title="<i class='material-icons'>flash_on</i> <?= __('Cloudflare Bypass (fu_cloudflare)') ?>">

			<form dojoType='dijit.form.Form'>
				<?= \Controls\pluginhandler_tags($this, "save") ?>
				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Saving data...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						});
					}
				</script>

				<fieldset>
					<label><?= __('Plugin:') ?></label>
					<select dojoType='dijit.form.Select' name='enabled'>
						<option value='1' <?= $enabled == '1' ? 'selected="selected"' : '' ?>>
							<?= __('Enabled') ?>
						</option>
						<option value='0' <?= $enabled == '0' ? 'selected="selected"' : '' ?>>
							<?= __('Disabled (plugin does nothing)') ?>
						</option>
					</select>
				</fieldset>

				<fieldset>
					<label><?= __('FlareSolverr URL:') ?></label>
					<input dojoType='dijit.form.TextBox' name='flaresolverr_url'
						value='<?= htmlspecialchars($flaresolverr_url) ?>' style='width: 400px'
						placeholder='http://localhost:8191'>
				</fieldset>

				<fieldset>
					<label><?= __('Max timeout (ms):') ?></label>
					<input dojoType='dijit.form.NumberSpinner' name='max_timeout'
						value='<?= $max_timeout ?>' smallDelta='5000' min='5000' max='300000'>
				</fieldset>

				<fieldset>
					<label><?= __('Max concurrent requests:') ?></label>
					<input dojoType='dijit.form.NumberSpinner' name='max_concurrent'
						value='<?= $max_concurrent ?>' smallDelta='1' min='0' max='20'
						title='<?= __('0 = unlimited') ?>'>
				</fieldset>

				<fieldset>
					<label class='checkbox'>
						<?= \Controls\checkbox_tag("per_feed_sessions", $per_feed === "1") ?>
						<?= __('Per-feed sessions (each feed gets its own browser context)') ?>
					</label>
				</fieldset>

				<?= \Controls\submit_tag(__("Save")) ?>
			</form>

			<hr/>

			<h3><?= __('Statistics') ?></h3>
			<table class='prefFeedList' style='width: auto'>
				<tr><td><?= __('Successful fetches:') ?></td><td><strong><?= $stats['requests_ok'] ?></strong></td></tr>
				<tr><td><?= __('Challenge pages returned:') ?></td><td><strong><?= $stats['requests_challenge'] ?></strong></td></tr>
				<tr><td><?= __('Errors:') ?></td><td><strong><?= $stats['requests_failed'] ?></strong></td></tr>
				<tr><td><?= __('Rate-limited:') ?></td><td><strong><?= $stats['requests_ratelimited'] ?></strong></td></tr>
			</table>
			<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.resetStats()'>
				<?= __('Reset Statistics') ?>
			</button>

			<hr/>

			<h3><?= __('FlareSolverr Session') ?></h3>
			<p class='text-muted'><?= __('A persistent browser session allows JavaScript PoW to complete across requests.') ?></p>
			<p><strong><?= __('Session:') ?></strong> <span id='fu_session_status'>
				<?= $this->host->get($this, "session_id", "") ? __('Active') : __('None') ?>
			</span></p>
			<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.resetFlareSolverrSession()'>
				<?= __('Reset Session') ?>
			</button>
			<div id='fu_session_result' style='margin-top: 8px'></div>

			<hr/>

			<h3><?= __('FlareSolverr Health Check') ?></h3>
			<p class='text-muted'><?= __('Verify that FlareSolverr is reachable and responding.') ?></p>
			<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.testFlareSolverr()'>
				<?= __('Test FlareSolverr') ?>
			</button>
			<div id='fu_flaresolverr_result' style='margin-top: 8px'></div>

			<hr/>

			<h3><?= __('Test Feed Fetch') ?></h3>
			<p class='text-muted'><?= __('Fetch a feed URL through FlareSolverr to test if it can bypass Cloudflare.') ?></p>
			<div style='margin-bottom: 8px'>
				<input dojoType='dijit.form.TextBox' id='fu_test_url'
					value='https://sarahcandersen.com/rss' style='width: 400px'
					placeholder='https://sarahcandersen.com/rss'>
			</div>
			<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.testFetchFeed()'>
				<?= __('Test Fetch') ?>
			</button>
			<div id='fu_test_result' style='margin-top: 8px'></div>

			<hr/>

			<h3><?= __('Enabled Feeds') ?></h3>
			<?php
				$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
				if ($enabled_feeds) {
					$ids = array_map('intval', $enabled_feeds);
					$placeholders = implode(',', array_fill(0, count($ids), '?'));
					$sth = $this->pdo->prepare(
						"SELECT id, title, feed_url FROM ttrss_feeds WHERE id IN ($placeholders) ORDER BY title"
					);
					$sth->execute($ids);
					$feeds = $sth->fetchAll();

					if ($feeds) {
						echo "<ul class='panel panel-scrollable' style='max-height: 300px; overflow-y: auto'>";
						foreach ($feeds as $f) {
							$session_info = '';
							if ($per_feed === "1") {
								$sk = $this->host->get($this, "session_id_" . $f['id'], "");
								$session_info = $sk ? ' <span class=\"text-success\">[session]</span>' : ' <span class=\"text-muted\">[no session]</span>';
							}
							echo "<li><a href='prefs.php?op=prefFeeds' target='_blank'>" . htmlspecialchars($f['title']) . "</a>" .
								$session_info .
								" <span class='text-muted'>(" . htmlspecialchars($f['feed_url']) . ")</span></li>";
						}
						echo "</ul>";
						echo "<p class='text-muted'>" . count($feeds) . " " . __('feed(s) enabled.') . "</p>";
					} else {
						echo "<p class='text-muted'>" . __('Feed IDs found but no matching feeds in database.') . "</p>";
					}
				} else {
					echo "<p class='text-muted'>" . __('No feeds enabled yet. Open a feed\'s editor and check "Fetch this feed via FlareSolverr".') . "</p>";
				}
			?>

			<hr/>

			<h3><?= __('Scan Feeds') ?></h3>
			<p class='text-muted'><?= __('Check all feeds for Cloudflare challenges. Feeds returning a challenge can then be enabled in their feed editor.') ?></p>
			<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.scanFeeds()'>
				<?= __('Scan All Feeds') ?>
			</button>
			<div id='fu_scan_result' style='margin-top: 8px'></div>

			<hr/>
			<div style='display: flex; justify-content: space-between; align-items: center; font-size: 0.85em'>
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
		<?php
	}

	function save() : void {
		$enabled = clean($_POST["enabled"] ?? "1");
		$flaresolverr_url = clean($_POST["flaresolverr_url"] ?? "");
		$max_timeout = (int)($_POST["max_timeout"] ?? 60000);
		$max_concurrent = (int)($_POST["max_concurrent"] ?? 3);
		$per_feed = checkbox_to_sql_bool($_POST["per_feed_sessions"] ?? "") ? "1" : "0";

		$prev_enabled = $this->host->get($this, "enabled", "1");

		$this->host->set($this, "enabled", $enabled);
		$this->host->set($this, "flaresolverr_url", $flaresolverr_url);
		$this->host->set($this, "max_timeout", $max_timeout);
		$this->host->set($this, "max_concurrent", $max_concurrent);
		$this->host->set($this, "per_feed_sessions", $per_feed);

		if ($prev_enabled !== $enabled) {
			Logger::log(E_USER_NOTICE, "fu_cloudflare: " . ($enabled === "1" ? "enabled" : "disabled"));
		}

		echo __("Data saved.");
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
			$url = 'https://sarahcandersen.com/rss';
		}

		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if (!$flaresolverr_url) {
			echo json_encode(["success" => false, "error" => __("FlareSolverr URL is not configured.")]);
			return;
		}

		$start = microtime(true);
		$session = $this->get_session($flaresolverr_url);
		$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url, $session);
		$elapsed = round(microtime(true) - $start, 2);

		if (!$result['success']) {
			echo json_encode([
				"success" => false,
				"time" => $elapsed,
				"error" => $result['error'],
			]);
			return;
		}

		if ($this->is_cloudflare_challenge($result['data'])) {
			echo json_encode([
				"success" => false,
				"time" => $elapsed,
				"error" => "Cloudflare challenge page returned — FlareSolverr could not solve it",
				"body_size" => strlen($result['data']),
			]);
			return;
		}

		$title = '';
		if (preg_match('/<title>(.*?)<\/title>/is', $result['data'], $m)) {
			$title = trim(strip_tags($m[1]));
		}

		if (!$title) {
			echo json_encode([
				"success" => false,
				"time" => $elapsed,
				"error" => "No <title> tag found in response",
				"body_size" => strlen($result['data']),
			]);
			return;
		}

		echo json_encode([
			"success" => true,
			"time" => $elapsed,
			"title" => $title,
			"body_size" => strlen($result['data']),
			"user_agent" => $result['user_agent'],
			"cookies_count" => $result['cookies_count'],
		]);
	}

	function hook_prefs_edit_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		?>
		<header><?= __('Cloudflare Bypass') ?></header>
		<section>
			<fieldset>
				<label class='checkbox'>
					<?= \Controls\checkbox_tag("fu_cloudflare_enabled", in_array($feed_id, $enabled_feeds)) ?>
					<?= __('Fetch this feed via FlareSolverr (bypasses Cloudflare)') ?>
				</label>
			</fieldset>
		</section>
		<?php
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$enable = checkbox_to_sql_bool($_POST["fu_cloudflare_enabled"] ?? "");
		$key = array_search($feed_id, $enabled_feeds);

		if ($enable) {
			if ($key === false) array_push($enabled_feeds, $feed_id);
		} else {
			if ($key !== false) unset($enabled_feeds[$key]);
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
	}

	function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass) {
		$enabled = $this->host->get($this, "enabled", "1");
		if ($enabled !== "1") {
			Debug::log("fu_cloudflare: plugin disabled", Debug::LOG_VERBOSE);
			return $feed_data;
		}

		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if (!$flaresolverr_url) {
			Debug::log("fu_cloudflare: FlareSolverr URL not configured", Debug::LOG_VERBOSE);
			return $feed_data;
		}

		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		if (!in_array($feed, $enabled_feeds)) {
			Debug::log("fu_cloudflare: feed $feed not in enabled list", Debug::LOG_VERBOSE);
			return $feed_data;
		}

		$session = $this->get_session($flaresolverr_url, $feed);
		if ($session) {
			Debug::log("fu_cloudflare: using session $session", Debug::LOG_VERBOSE);
		}

		Debug::log("fu_cloudflare: fetching feed $feed via FlareSolverr...", Debug::LOG_VERBOSE);
		$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session);

		if ($result !== false) {
			if ($this->is_cloudflare_challenge($result)) {
				$backup_timeout = (int)$this->host->get($this, "max_timeout", 60000);
				$doubled = min($backup_timeout * 2, 300000);
				$this->host->set($this, "max_timeout", $doubled);
				Debug::log("fu_cloudflare: challenge present, retry with session after 3s (timeout: {$doubled}ms)...", Debug::LOG_VERBOSE);
				sleep(3);
				$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session);
				$this->host->set($this, "max_timeout", $backup_timeout);

				if ($result !== false && !$this->is_cloudflare_challenge($result)) {
					$this->increment_stat('stats_requests_ok');
					Debug::log("fu_cloudflare: retry OK (" . strlen($result) . " bytes) for feed $feed", Debug::LOG_VERBOSE);
					return $result;
				}

				Debug::log("fu_cloudflare: still challenge, trying fresh session...", Debug::LOG_VERBOSE);
				$this->host->set($this, $this->get_session_key($feed), "");
				$session = $this->get_session($flaresolverr_url, $feed);
				$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session);

				if ($result !== false && !$this->is_cloudflare_challenge($result)) {
					$this->increment_stat('stats_requests_ok');
					Debug::log("fu_cloudflare: fresh session OK (" . strlen($result) . " bytes) for feed $feed", Debug::LOG_VERBOSE);
					return $result;
				}

				$this->increment_stat('stats_requests_challenge');
				$msg = "fu_cloudflare: FlareSolverr returned a Cloudflare challenge page — it could not solve this challenge";
				Debug::log($msg, Debug::LOG_VERBOSE);
				return $result;
			}

			$this->increment_stat('stats_requests_ok');
			Debug::log("fu_cloudflare: FlareSolverr OK (" . strlen($result) . " bytes) for feed $feed", Debug::LOG_VERBOSE);
			return $result;
		}

		if (!empty($this->last_fetch_error['session_error'])) {
			Debug::log("fu_cloudflare: session expired, creating fresh session...", Debug::LOG_VERBOSE);
			$this->host->set($this, $this->get_session_key($feed), "");
			$session = $this->get_session($flaresolverr_url, $feed);
			$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session);
			if ($result !== false) {
				$label = $this->is_cloudflare_challenge($result) ? "challenge" : "OK";
				Debug::log("fu_cloudflare: fresh session $label (" . strlen($result) . " bytes) for feed $feed", Debug::LOG_VERBOSE);
				if (!$this->is_cloudflare_challenge($result)) $this->increment_stat('stats_requests_ok');
				return $result;
			}
		}

		$this->increment_stat('stats_requests_failed');
		Debug::log("fu_cloudflare: FlareSolverr failed for feed $feed, returning original data", Debug::LOG_VERBOSE);
		return $feed_data;
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
		echo json_encode(["success" => true]);
	}

	private function get_session_key($feed = null) {
		if ($this->host->get($this, "per_feed_sessions", "0") === "1" && $feed !== null) {
			return "session_id_$feed";
		}
		return "session_id";
	}

	private function get_session($flaresolverr_url, $feed = null) {
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
				Debug::log("fu_cloudflare: created session {$data['session']}", Debug::LOG_VERBOSE);
				return $data['session'];
			}
		}
		return null;
	}

	function resetSession() : void {
		if ($this->host->get($this, "per_feed_sessions", "0") === "1") {
			$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
			foreach ($enabled_feeds as $fid) {
				$this->host->set($this, "session_id_$fid", "");
			}
		}
		$this->host->set($this, "session_id", "");
		echo json_encode(["success" => true, "message" => __("Session(s) cleared. New sessions created on next feed fetch.")]);
	}

	function scanFeeds() : void {
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if (!$flaresolverr_url) {
			echo json_encode(["success" => false, "error" => __("Configure FlareSolverr URL first.")]);
			return;
		}

		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
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
			];
		}

		curl_multi_close($multi);
		echo json_encode(["success" => true, "feeds" => $results]);
	}

	private function fetch_with_rate_limit($url, $flaresolverr_url, $session = null) {
		$this->last_fetch_error = null;
		if ($this->acquire_flaresolverr_slot()) {
			$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url, $session);
			$this->release_flaresolverr_slot();
			if ($result['success']) {
				if (!empty($result['user_agent']) || !empty($result['challenge_solved'])) {
					Debug::log(sprintf(
						"fu_cloudflare: meta — solved=%s, ua=%s, cookies=%d",
						!empty($result['challenge_solved']) ? 'true' : 'false',
						$result['user_agent'] ?? '-',
						$result['cookies_count'] ?? 0
					), Debug::LOG_VERBOSE);
				}
				return $result['data'];
			}
			$this->last_fetch_error = $result;
		} else {
			$this->last_fetch_error = ['session_error' => false, 'error' => 'rate_limit'];
			$this->increment_stat('stats_requests_ratelimited');
		}
		return false;
	}

	private function acquire_flaresolverr_slot() {
		$max_concurrent = (int)$this->host->get($this, "max_concurrent", 3);
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

	private function fetch_via_flaresolverr($url, $flaresolverr_url, $session = null) {
		$timeout = (int)$this->host->get($this, "max_timeout", 60000);

		$body = [
			'cmd' => 'request.get',
			'url' => $url,
			'maxTimeout' => $timeout,
		];
		if ($session) $body['session'] = $session;

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
					'challenge_solved' => !empty($data['solution']['challenge']),
					'user_agent' => $data['solution']['userAgent'] ?? '',
					'cookies_count' => count($data['solution']['cookies'] ?? []),
				];
			}
			if (isset($data['error'])) {
				$is_session_error = str_contains($data['error'], 'Session not found');
				return ['success' => false, 'error' => $data['error'], 'session_error' => $is_session_error];
			}
			return ['success' => false, 'error' => "HTTP $http_code: FlareSolverr returned no solution"];
		}

		if ($curl_error) {
			return ['success' => false, 'error' => "cURL error: $curl_error"];
		}

		return ['success' => false, 'error' => "FlareSolverr returned HTTP $http_code"];
	}

	function api_version() {
		return 2;
	}
}

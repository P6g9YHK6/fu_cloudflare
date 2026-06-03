<?php
class Fu_Cloudflare extends Plugin {

	private $host;

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

				<?= \Controls\submit_tag(__("Save")) ?>
			</form>

			<hr/>

			<h3><?= __('FlareSolverr Session') ?></h3>
			<p class='text-muted'><?= __('FlareSolverr uses a browser session to solve multi-step challenges. A persistent session allows the JavaScript PoW to complete in the background.') ?></p>
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

			<h3><?= __('Test Connection') ?></h3>
			<form dojoType='dijit.form.Form'>
				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					Plugins.Fu_Cloudflare.testConnection();
				</script>
				<fieldset>
					<label><?= __('Feed URL to test:') ?></label>
					<input dojoType='dijit.form.TextBox' id='fu_test_url'
						value='' style='width: 400px'
						placeholder='https://example.com/rss'>
				</fieldset>
				<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.testConnection()'>
					<?= __('Test') ?>
				</button>
			</form>
			<div id='fu_test_result'></div>
		</div>
		<?php
	}

	function save() : void {
		$enabled = clean($_POST["enabled"] ?? "1");
		$flaresolverr_url = clean($_POST["flaresolverr_url"] ?? "");
		$max_timeout = (int)($_POST["max_timeout"] ?? 60000);
		$max_concurrent = (int)($_POST["max_concurrent"] ?? 3);

		$prev_enabled = $this->host->get($this, "enabled", "1");

		$this->host->set($this, "enabled", $enabled);
		$this->host->set($this, "flaresolverr_url", $flaresolverr_url);
		$this->host->set($this, "max_timeout", $max_timeout);
		$this->host->set($this, "max_concurrent", $max_concurrent);

		if ($prev_enabled !== $enabled) {
			Logger::log(E_USER_NOTICE, "fu_cloudflare: " . ($enabled === "1" ? "enabled" : "disabled"));
		}

		echo __("Data saved.");
	}

	function testConnection() : void {
		$test_url = clean($_REQUEST["test_url"] ?? "");
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "http://localhost:8191");

		if (!$test_url) {
			echo json_encode(["error" => __("No URL provided.")]);
			return;
		}

		$start = microtime(true);
		$result = $this->fetch_via_flaresolverr($test_url, $flaresolverr_url);
		$elapsed = round(microtime(true) - $start, 2);

		if ($result['success']) {
			$dom = new DOMDocument();
			$feed_title = '';

			if (@$dom->loadXML(mb_substr($result['data'], 0, 10000))) {
				$xpath = new DOMXPath($dom);
				$xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
				$channel = $xpath->query('/rss/channel/title');
				$feed_title = $channel->length > 0 ? trim($channel->item(0)->textContent) : '';
				if (!$feed_title) {
					$feed_title = $xpath->query('//atom:feed/atom:title')->length > 0
						? trim($xpath->query('//atom:feed/atom:title')->item(0)->textContent) : '';
				}
			}

			if (!$feed_title) {
				if (preg_match('/<channel>.*?<title>(.*?)<\/title>/is', $result['data'], $m)) {
					$feed_title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
				} elseif (preg_match('/<feed.*?>.*?<title[^>]*>(.*?)<\/title>/is', $result['data'], $m)) {
					$feed_title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
				}
			}

			$size = strlen($result['data']);

			Logger::log(E_USER_NOTICE, "fu_cloudflare: connection test OK — {$elapsed}s, {$size}B", $test_url);

			echo json_encode([
				"success" => true,
				"time" => $elapsed,
				"size" => $size,
				"title" => $feed_title ?: __('(feed parsed, no title found)'),
			]);
		} else {
			$error_msg = $result['error'] ?? __('Unknown error');
			Logger::log(E_USER_WARNING, "fu_cloudflare: connection test FAILED — $error_msg", $test_url);

			echo json_encode([
				"success" => false,
				"error" => $error_msg,
			]);
		}
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

		$session = $this->get_session($flaresolverr_url);
		if ($session) {
			Debug::log("fu_cloudflare: using FlareSolverr session $session", Debug::LOG_VERBOSE);
		}

		Debug::log("fu_cloudflare: fetching feed $feed via FlareSolverr...", Debug::LOG_VERBOSE);
		$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session);
		if ($result !== false) {
			if ($this->is_cloudflare_challenge($result)) {
				Debug::log("fu_cloudflare: challenge present, retrying with session after 3s...", Debug::LOG_VERBOSE);
				sleep(3);
				$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url, $session);

				if ($result !== false && !$this->is_cloudflare_challenge($result)) {
					Debug::log("fu_cloudflare: retry OK (" . strlen($result) . " bytes) for feed $feed", Debug::LOG_VERBOSE);
					return $result;
				}

				$msg = "fu_cloudflare: FlareSolverr returned a Cloudflare challenge page — it could not solve this challenge";
				Debug::log($msg, Debug::LOG_VERBOSE);
				Logger::log(E_USER_WARNING, $msg, $fetch_url);
			} else {
				Debug::log("fu_cloudflare: FlareSolverr OK (" . strlen($result) . " bytes) for feed $feed", Debug::LOG_VERBOSE);
			}
			return $result;
		}

		Debug::log("fu_cloudflare: FlareSolverr failed for feed $feed, returning original data", Debug::LOG_VERBOSE);
		return $feed_data;
	}

	private function is_cloudflare_challenge($data) {
		if (preg_match('/<(title|h1|head)>.*(Checking your browser|Just a moment\.\.\.)/is', $data)) return true;
		if (preg_match('/\/__challenge/', $data)) return true;
		if (preg_match('/Attention Required.*Cloudflare/i', $data)) return true;
		return false;
	}

	private function get_session($flaresolverr_url) {
		$session = $this->host->get($this, "session_id", "");
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
				$this->host->set($this, "session_id", $data['session']);
				Debug::log("fu_cloudflare: created session {$data['session']}", Debug::LOG_VERBOSE);
				return $data['session'];
			}
		}
		return null;
	}

	function resetSession() : void {
		$this->host->set($this, "session_id", "");
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if ($flaresolverr_url) {
			$session = $this->get_session($flaresolverr_url);
			echo json_encode(["success" => true, "session" => $session ?: ""]);
		} else {
			echo json_encode(["success" => false, "error" => __("FlareSolverr URL not configured")]);
		}
	}

	private function fetch_with_rate_limit($url, $flaresolverr_url, $session = null) {
		if ($this->acquire_flaresolverr_slot()) {
			$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url, $session);
			$this->release_flaresolverr_slot();
			if ($result['success']) {
				return $result['data'];
			}
			Logger::log(E_USER_WARNING, "fu_cloudflare: FlareSolverr error for $url — " . ($result['error'] ?? 'unknown'));
		} else {
			Logger::log(E_USER_WARNING, "fu_cloudflare: rate limit reached, skipped feed", $url);
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
				return ['success' => true, 'data' => $data['solution']['response']];
			}
			if (isset($data['error'])) {
				return ['success' => false, 'error' => $data['error']];
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

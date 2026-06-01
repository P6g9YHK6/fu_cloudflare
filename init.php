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

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$enabled = $this->host->get($this, "enabled", "1");
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "http://localhost:8191");
		$max_timeout = (int)$this->host->get($this, "max_timeout", 60000);
		$max_concurrent = (int)$this->host->get($this, "max_concurrent", 3);
		$mode = $this->host->get($this, "mode", "per_feed");
		$smart_expiry_hours = (int)$this->host->get($this, "smart_expiry_hours", 720);
		$enabled_feeds = $this->filter_unknown_feeds($this->host->get_array($this, "enabled_feeds"));
		$smart_added = json_decode($this->host->get($this, "smart_added_feeds", "{}"), true) ?: [];
		$this->host->set($this, "enabled_feeds", $enabled_feeds);
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
					<label><?= __('Mode:') ?></label>
					<select dojoType='dijit.form.Select' id='fu_mode_select' name='mode'>
						<option value='per_feed' <?= $mode == 'per_feed' ? 'selected="selected"' : '' ?>>
							<?= __('Per feed (configure in feed editor)') ?>
						</option>
						<option value='global' <?= $mode == 'global' ? 'selected="selected"' : '' ?>>
							<?= __('All feeds through FlareSolverr') ?>
						</option>
						<option value='smart' <?= $mode == 'smart' ? 'selected="selected"' : '' ?>>
							<?= __('Smart mode (auto-detect Cloudflare block)') ?>
						</option>
					</select>
				</fieldset>

				<fieldset id='fu_smart_settings' style='<?= $mode != 'smart' ? 'display:none' : '' ?>'>
					<label><?= __('Smart mode: retry normal fetch after (hours):') ?></label>
					<input dojoType='dijit.form.NumberSpinner' name='smart_expiry_hours'
						value='<?= $smart_expiry_hours ?>' smallDelta='24' min='0' max='87600'
						title='<?= __('0 = never retry') ?>'>
				</fieldset>

				<?= \Controls\submit_tag(__("Save")) ?>
			</form>

			<script type="dojo/method">
				require(["dojo/query", "dojo/on", "dojo/dom-form"], function(query, on) {
					var modeSelect = dijit.byId("fu_mode_select");
					if (modeSelect) {
						on(modeSelect, "change", function(val) {
							dijit.byId("fu_smart_settings").domNode.style.display =
								val == "smart" ? "" : "none";
						});
					}
				});

				window.Plugins = window.Plugins || {};
				window.Plugins.Fu_Cloudflare = {
					testConnection: function() {
						var url = dijit.byId("fu_test_url").get("value");
						if (!url) { alert("Enter a URL to test."); return; }
						Notify.progress('Testing FlareSolverr connection...', true);
						xhr.post("backend.php", {
							op: "PluginHandler", plugin: "fu_cloudflare", method: "testConnection", test_url: url
						}, function(reply) {
							var div = document.getElementById("fu_test_result");
							try {
								var result = JSON.parse(reply);
								if (result.success) {
									div.innerHTML = "<div class='notice alert alert-info'><strong>Success!</strong> " +
										result.time + "s, " + result.size + " bytes" +
										(result.title ? "<br>Feed: " + result.title : "") + "</div>";
								} else {
									div.innerHTML = "<div class='notice alert alert-warning'><strong>Error:</strong> " + result.error + "</div>";
								}
							} catch(e) { div.innerHTML = "<div class='notice alert alert-warning'>" + reply + "</div>"; }
						});
					},
					testFlareSolverr: function() {
						Notify.progress('Testing FlareSolverr...', true);
						xhr.post("backend.php", {
							op: "PluginHandler", plugin: "fu_cloudflare", method: "testFlareSolverr"
						}, function(reply) {
							var div = document.getElementById("fu_flaresolverr_result");
							try {
								var result = JSON.parse(reply);
								if (result.success) {
									div.innerHTML = "<div class='notice alert alert-info'><strong>FlareSolverr is reachable!</strong> " +
										"v" + result.version + ", " + result.time + "s response time</div>";
								} else {
									div.innerHTML = "<div class='notice alert alert-warning'><strong>Error:</strong> " + result.error + "</div>";
								}
							} catch(e) { div.innerHTML = "<div class='notice alert alert-warning'>" + reply + "</div>"; }
						});
					},
					purgeSmartList: function() {
						Notify.progress('Purging smart-added feeds...', true);
						xhr.post("backend.php", {
							op: "PluginHandler", plugin: "fu_cloudflare", method: "clearSmartList"
						}, function(reply) {
							var div = document.getElementById("fu_purge_result");
							try {
								var result = JSON.parse(reply);
								if (result.success) {
									div.innerHTML = "<div class='notice alert alert-info'>" + result.message + "</div>";
									Plugins.reload();
								} else {
									div.innerHTML = "<div class='notice alert alert-warning'><strong>Error:</strong> " + result.error + "</div>";
								}
							} catch(e) { div.innerHTML = "<div class='notice alert alert-warning'>" + reply + "</div>"; }
						});
					}
				};
			</script>

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

			<hr/>

			<h3><?= __('Smart Mode') ?></h3>
			<p class='text-muted'><?= __('Feeds auto-added by smart mode will be re-tried without FlareSolverr after the configured expiry period.') ?></p>
			<button dojoType='dijit.form.Button' onclick='Plugins.Fu_Cloudflare.purgeSmartList()'>
				<?= __('Purge smart-added feeds') ?>
			</button>
			<div id='fu_purge_result' style='margin-top: 8px'></div>

			<?php if (count($enabled_feeds) > 0) { ?>
				<hr/>
				<h3><?= __('Currently enabled for:') ?></h3>
				<ul class='panel panel-scrollable list list-unstyled'>
					<?php foreach ($enabled_feeds as $f) { ?>
						<li>
							<?php if (Feeds::_has_icon($f)) { ?>
								<img src='<?= Feeds::_get_icon_url($f) ?>' style="max-height: 20px" />
							<?php } else { ?> <i class='material-icons'>rss_feed</i> <?php } ?>
							<a href='#' onclick="CommonDialogs.editFeed(<?= $f ?>)">
								<?= Feeds::_get_title($f, $this->host->get_owner_uid()) ?>
							</a>
							<?php if (isset($smart_added[(string)$f])) { ?>
								<i class='material-icons text-info' style='font-size: 14px; vertical-align: middle'
									title='<?= __('Auto-enabled by smart mode') ?>'>psychology</i>
							<?php } ?>
						</li>
					<?php } ?>
				</ul>
			<?php } ?>
		</div>
		<?php
	}

	function save() : void {
		$enabled = clean($_POST["enabled"] ?? "1");
		$flaresolverr_url = clean($_POST["flaresolverr_url"] ?? "");
		$max_timeout = (int)($_POST["max_timeout"] ?? 60000);
		$max_concurrent = (int)($_POST["max_concurrent"] ?? 3);
		$mode = clean($_POST["mode"] ?? "per_feed");
		$smart_expiry_hours = (int)($_POST["smart_expiry_hours"] ?? 720);

		$prev_mode = $this->host->get($this, "mode", "per_feed");
		$prev_enabled = $this->host->get($this, "enabled", "1");

		$this->host->set($this, "enabled", $enabled);
		$this->host->set($this, "flaresolverr_url", $flaresolverr_url);
		$this->host->set($this, "max_timeout", $max_timeout);
		$this->host->set($this, "max_concurrent", $max_concurrent);
		$this->host->set($this, "mode", $mode);
		$this->host->set($this, "smart_expiry_hours", $smart_expiry_hours);

		if ($prev_enabled !== $enabled) {
			Logger::log(E_USER_NOTICE, "fu_cloudflare: " . ($enabled === "1" ? "enabled" : "disabled"));
		}
		if ($prev_mode !== $mode) {
			Logger::log(E_USER_NOTICE, "fu_cloudflare: mode changed to $mode");
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

	function clearSmartList() : void {
		$smart_added = json_decode($this->host->get($this, "smart_added_feeds", "{}"), true) ?: [];
		if (empty($smart_added)) {
			echo json_encode(["success" => true, "message" => __("No smart-added feeds to purge.")]);
			return;
		}

		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		$removed = 0;

		foreach (array_keys($smart_added) as $feed_id) {
			$key = array_search((int)$feed_id, $enabled_feeds);
			if ($key !== false) {
				unset($enabled_feeds[$key]);
				$removed++;
			}
		}

		$this->host->set($this, "enabled_feeds", array_values($enabled_feeds));
		$this->host->set($this, "smart_added_feeds", "{}");

		Logger::log(E_USER_NOTICE, "fu_cloudflare: purged $removed smart-added feed(s)");

		echo json_encode([
			"success" => true,
			"message" => __("Removed $removed feed(s) from the smart list."),
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
					<?= __('Use FlareSolverr to fetch this feed') ?>
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
		if ($enabled !== "1") return $feed_data;

		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if (!$flaresolverr_url) return $feed_data;

		$mode = $this->host->get($this, "mode", "per_feed");

		if ($mode == "global") {
			$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url);
			return $result !== false ? $result : $feed_data;
		}

		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");

		if ($mode == "smart") {
			$smart_added = json_decode($this->host->get($this, "smart_added_feeds", "{}"), true) ?: [];
			$smart_expiry_hours = (int)$this->host->get($this, "smart_expiry_hours", 720);
			$feed_id = (string)$feed;

			if (in_array($feed, $enabled_feeds)) {
				if (isset($smart_added[$feed_id]) && $smart_expiry_hours > 0) {
					$added_ts = $smart_added[$feed_id];
					if (time() > $added_ts + ($smart_expiry_hours * 3600)) {
						unset($smart_added[$feed_id]);
						$key = array_search($feed, $enabled_feeds);
						if ($key !== false) unset($enabled_feeds[$key]);
						$this->host->set($this, "enabled_feeds", array_values($enabled_feeds));
						$this->host->set($this, "smart_added_feeds", json_encode($smart_added));
						Logger::log(E_USER_NOTICE, "fu_cloudflare: feed $feed expired, removed for retry", $fetch_url);
						return $feed_data;
					}
				}

				$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url);
				return $result !== false ? $result : $feed_data;
			}

			if ($this->is_cloudflare_blocked($feed_data)) {
				$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url);
				if ($result !== false) {
					$smart_added[$feed_id] = time();
					if (!in_array($feed, $enabled_feeds)) {
						$enabled_feeds[] = $feed;
					}
					$this->host->set($this, "enabled_feeds", $enabled_feeds);
					$this->host->set($this, "smart_added_feeds", json_encode($smart_added));
					Logger::log(E_USER_NOTICE, "fu_cloudflare: Cloudflare blocked, auto-enabled feed $feed", $fetch_url);
					return $result;
				}
			}

			return $feed_data;
		}

		if (in_array($feed, $enabled_feeds)) {
			$result = $this->fetch_with_rate_limit($fetch_url, $flaresolverr_url);
			return $result !== false ? $result : $feed_data;
		}

		return $feed_data;
	}

	private function fetch_with_rate_limit($url, $flaresolverr_url) {
		if ($this->acquire_flaresolverr_slot()) {
			$result = $this->fetch_via_flaresolverr($url, $flaresolverr_url);
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

	private function fetch_via_flaresolverr($url, $flaresolverr_url) {
		$timeout = (int)$this->host->get($this, "max_timeout", 60000);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, rtrim($flaresolverr_url, '/') . '/v1');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
			'cmd' => 'request.get',
			'url' => $url,
			'maxTimeout' => $timeout,
		]));
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

	private function is_cloudflare_blocked($data) {
		if (!$data || trim($data) === '') return false;

		$dom = new DOMDocument();
		if (@$dom->loadXML(mb_substr($data, 0, 5000))) {
			return false;
		}

		$indicators = [
			'Just a moment',
			'Checking your browser',
			'Attention Required',
			'cf-browser-verification',
			'challenge-platform',
			'DDoS protection',
			'Enable JavaScript',
		];

		$lower = mb_strtolower($data);
		foreach ($indicators as $indicator) {
			if (mb_strpos($lower, mb_strtolower($indicator)) !== false) {
				return true;
			}
		}

		return false;
	}

	private function filter_unknown_feeds(array $enabled_feeds) : array {
		$tmp = array();
		foreach ($enabled_feeds as $feed) {
			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);
			if ($sth->fetch()) {
				$tmp[] = $feed;
			}
		}
		return $tmp;
	}

	function api_version() {
		return 2;
	}
}

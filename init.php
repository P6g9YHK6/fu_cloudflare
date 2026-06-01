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

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "http://localhost:8191");
		$max_timeout = (int)$this->host->get($this, "max_timeout", 60000);
		$mode = $this->host->get($this, "mode", "per_feed");
		$enabled_feeds = $this->filter_unknown_feeds($this->host->get_array($this, "enabled_feeds"));
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
					<label><?= __('Mode:') ?></label>
					<select dojoType='dijit.form.Select' name='mode'>
						<option value='per_feed' <?= $mode == 'per_feed' ? 'selected="selected"' : '' ?>>
							<?= __('Per feed (configure in feed editor)') ?>
						</option>
						<option value='global' <?= $mode == 'global' ? 'selected="selected"' : '' ?>>
							<?= __('All feeds through FlareSolverr') ?>
						</option>
					</select>
				</fieldset>

				<?= \Controls\submit_tag(__("Save")) ?>
			</form>

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
						</li>
					<?php } ?>
				</ul>
			<?php } ?>
		</div>
		<?php
	}

	function save() : void {
		$flaresolverr_url = clean($_POST["flaresolverr_url"] ?? "");
		$max_timeout = (int)($_POST["max_timeout"] ?? 60000);
		$mode = clean($_POST["mode"] ?? "per_feed");

		$this->host->set($this, "flaresolverr_url", $flaresolverr_url);
		$this->host->set($this, "max_timeout", $max_timeout);
		$this->host->set($this, "mode", $mode);

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

		if ($result) {
			$dom = new DOMDocument();
			$title = '';
			$feed_title = '';

			if (@$dom->loadXML(mb_substr($result, 0, 10000))) {
				$xpath = new DOMXPath($dom);
				$xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
				$channel = $xpath->query('/rss/channel/title');
				$feed_title = $channel->length > 0 ? trim($channel->item(0)->textContent) : '';
				if (!$feed_title) {
					$feed_title = $xpath->query('//atom:feed/atom:title')->length > 0
						? trim($xpath->query('//atom:feed/atom:title')->item(0)->textContent) : '';
				}
			}

			$size = strlen($result);

			echo json_encode([
				"success" => true,
				"time" => $elapsed,
				"size" => $size,
				"title" => $feed_title ?: __('(feed parsed, no title found)'),
			]);
		} else {
			echo json_encode([
				"success" => false,
				"error" => __("Failed to fetch URL through FlareSolverr. Check the FlareSolverr URL and logs."),
			]);
		}
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
		$flaresolverr_url = $this->host->get($this, "flaresolverr_url", "");
		if (!$flaresolverr_url) return $feed_data;

		$mode = $this->host->get($this, "mode", "per_feed");

		if ($mode == "global") {
			$result = $this->fetch_via_flaresolverr($fetch_url, $flaresolverr_url);
			return $result ?: $feed_data;
		}

		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		if (in_array($feed, $enabled_feeds)) {
			$result = $this->fetch_via_flaresolverr($fetch_url, $flaresolverr_url);
			return $result ?: $feed_data;
		}

		return $feed_data;
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
		curl_close($ch);

		if ($http_code == 200 && $response) {
			$data = json_decode($response, true);
			if (isset($data['solution']['response'])) {
				return $data['solution']['response'];
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

Plugins.Fu_Cloudflare = {

	resetFlareSolverrSession: function() {
		Notify.progress('Resetting FlareSolverr session...', true);

		xhr.post("backend.php", {
			op: "PluginHandler",
			plugin: "fu_cloudflare",
			method: "resetSession"
		}, function(reply) {
			var div = document.getElementById("fu_session_result");

			try {
				var result = JSON.parse(reply);

				Notify.close();

				if (result.success) {
					document.getElementById("fu_session_status").innerText = result.session ? "Active" : "None";
					div.innerHTML = "<div class='notice alert alert-info'>" +
						(result.session
							? "<strong>Session created:</strong> " + result.session
							: "<strong>" + result.message + "</strong>") +
						"</div>";
				} else {
					div.innerHTML = "<div class='notice alert alert-warning'>" +
						"<strong>Error:</strong> " + result.error +
						"</div>";
				}
			} catch(e) {
				div.innerHTML = "<div class='notice alert alert-warning'>" + reply + "</div>";
			}
		});
	},

	testFetchFeed: function() {
		Notify.progress('Fetching feed via FlareSolverr...', true);

		var url = document.getElementById("fu_test_url").value;
		if (!url) {
			Notify.close();
			document.getElementById("fu_test_result").innerHTML = "<div class='notice alert alert-warning'>Please enter a URL.</div>";
			return;
		}

		xhr.post("backend.php", {
			op: "PluginHandler",
			plugin: "fu_cloudflare",
			method: "testFetchFeed",
			test_url: url
		}, function(reply) {
			var div = document.getElementById("fu_test_result");

			Notify.close();

			try {
				var result = JSON.parse(reply);
				var borderColor = "#ddd";
				var hasError = false;

				if (!result.success) {
					borderColor = "#d9534f";
					hasError = true;
				}

				var html = "<div style='font-family: monospace; font-size: 11px; line-height: 1.6; background: #fafafa; border: 1px solid " + borderColor + "; border-radius: 4px; padding: 8px 10px; max-height: 350px; overflow-y: auto'>";

				if (result.steps) {
					for (var i = 0; i < result.steps.length; i++) {
						var s = result.steps[i];
						var color = "#333";
						if (s.step === "Error") color = "#d9534f";
						else if (s.step === "Skipped") color = "#888";
						else if (s.step === "Validation" && s.detail.indexOf("WARNING") !== -1) color = "#f0ad4e";
						else if (s.step === "Fetch" && s.detail.indexOf("failed") !== -1) color = "#d9534f";
						html += "<div style='color: " + color + "'>" +
							"<strong>◆ " + s.step + ":</strong> " + s.detail +
							"</div>";
					}
				}

				var summaryParts = [];
				if (result.time !== undefined) summaryParts.push(result.time + "s");
				if (result.body_size !== undefined) summaryParts.push(result.body_size + " bytes");
				if (result.title) summaryParts.push('"' + result.title + '"');

				html += "<div style='border-top: 1px solid #ddd; margin-top: 6px; padding-top: 6px; color: #555'>" +
					"<strong>◇ Summary:</strong> " + summaryParts.join(", ");

				if (result.user_agent) html += "<br/><strong>◇ User-Agent:</strong> " + result.user_agent;
				if (result.cookies_count !== undefined) html += "<br/><strong>◇ Cookies:</strong> " + result.cookies_count;

				if (result.error && hasError) html += "<br/><strong>◇ Error:</strong> " + result.error;

				if (result.warning) html += "<br/><strong>◇ Warning:</strong> " + result.warning;
				if (result.note) html += "<br/><strong>◇ Note:</strong> " + result.note;

				html += "</div></div>";

				div.innerHTML = html;
			} catch(e) {
				div.innerHTML = "<div class='notice alert alert-warning'>" + reply + "</div>";
			}
		});
	},

	scanFeeds: function() {
		Notify.progress('Scanning all feeds for Cloudflare challenges...', true);

		xhr.post("backend.php", {
			op: "PluginHandler",
			plugin: "fu_cloudflare",
			method: "scanFeeds"
		}, function(reply) {
			var div = document.getElementById("fu_scan_result");

			Notify.close();

			try {
				var result = JSON.parse(reply);

				if (result.success && result.feeds) {
					var html = "<table class='prefFeedList' style='width: auto'>" +
						"<tr><th>Feed</th><th>HTTP</th><th>Challenge</th><th>Challenged</th><th>Override</th></tr>";

					for (var i = 0; i < result.feeds.length; i++) {
						var f = result.feeds[i];
						var challenge = f.is_cloudflare ?
							"<span class='text-warning'>Yes</span>" :
							"<span class='text-success'>No</span>";
						var challenged = f.challenge_count > 0 ?
							"<span class='text-warning'>" + f.challenge_count + "</span>" :
							"<span class='text-muted'>0</span>";
						var status;
						if (f.excluded) {
							status = "<span class='text-warning'>Excluded</span>";
						} else if (f.already_enabled) {
							status = "<span class='text-success'>Include</span>";
						} else {
							status = "<span class='text-muted'>Default</span>";
						}
						html += "<tr>" +
							"<td>" + f.title + "</td>" +
							"<td>" + f.http_code + "</td>" +
							"<td>" + challenge + "</td>" +
							"<td>" + challenged + "</td>" +
							"<td>" + status + "</td>" +
							"</tr>";
					}

					html += "</table>" +
						"<p class='text-muted'>Scanned " + result.feeds.length + " feeds.</p>";
					div.innerHTML = html;
				} else {
					div.innerHTML = "<div class='notice alert alert-warning'>" +
						(result.error || "Scan failed.") +
						"</div>";
				}
			} catch(e) {
				div.innerHTML = "<div class='notice alert alert-warning'>" + reply + "</div>";
			}
		});
	},

	checkVersion: function() {
		Notify.progress('Checking for updates...', true);

		xhr.post("backend.php", {
			op: "PluginHandler",
			plugin: "fu_cloudflare",
			method: "resetVersionCheck"
		}, function(reply) {
			try {
				var result = JSON.parse(reply);
				var html = 'Version: <code>' + result.local + '</code>';
				if (result.branch) html += ' (' + result.branch + ')';
				html += ' ';
				if (result.up_to_date) {
					html += "<span class='text-success'>✓ up to date</span>";
				} else if (result.latest) {
					html += "<span class='text-warning'>⚠ New version available: <code>" + result.latest + "</code></span>";
				}
				document.getElementById("fu_version_info").innerHTML = html;
				Notify.close();
			} catch(e) {
				document.getElementById("fu_version_info").innerHTML = reply;
			}
		});
	},

	testFlareSolverr: function() {
		Notify.progress('Testing FlareSolverr...', true);

		xhr.post("backend.php", {
			op: "PluginHandler",
			plugin: "fu_cloudflare",
			method: "testFlareSolverr"
		}, function(reply) {
			var div = document.getElementById("fu_flaresolverr_result");

			Notify.close();

			try {
				var result = JSON.parse(reply);

				if (result.success) {
					div.innerHTML = "<div class='notice alert alert-info'>" +
						"<strong>FlareSolverr is reachable!</strong> " +
						"v" + result.version + ", " +
						result.time + "s response time" +
						"</div>";
				} else {
					div.innerHTML = "<div class='notice alert alert-warning'>" +
						"<strong>Error:</strong> " + result.error +
						"</div>";
				}
			} catch(e) {
				div.innerHTML = "<div class='notice alert alert-warning'>" + reply + "</div>";
			}
		});
	}
};

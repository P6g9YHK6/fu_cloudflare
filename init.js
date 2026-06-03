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

				if (result.success) {
					document.getElementById("fu_session_status").innerText = "Active";
					div.innerHTML = "<div class='notice alert alert-info'>" +
						"<strong>Session created:</strong> " + result.session +
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

			try {
				var result = JSON.parse(reply);

				if (result.success) {
					div.innerHTML = "<div class='notice alert alert-info'>" +
						"<strong>Title:</strong> " + result.title + "<br/>" +
						"<strong>Time:</strong> " + result.time + "s<br/>" +
						"<strong>Response size:</strong> " + result.body_size + " bytes<br/>" +
						(result.user_agent ? "<strong>User-Agent:</strong> " + result.user_agent + "<br/>" : "") +
						(result.cookies_count !== undefined ? "<strong>Cookies:</strong> " + result.cookies_count : "") +
						"</div>";
				} else {
					div.innerHTML = "<div class='notice alert alert-warning'>" +
						"<strong>Error:</strong> " + result.error +
						" (" + result.time + "s)" +
						"</div>";
				}
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

			try {
				var result = JSON.parse(reply);

				if (result.success && result.feeds) {
					var html = "<table class='prefFeedList' style='width: auto'>" +
						"<tr><th>Feed</th><th>HTTP</th><th>Challenge</th><th>Status</th></tr>";

					for (var i = 0; i < result.feeds.length; i++) {
						var f = result.feeds[i];
						var challenge = f.is_cloudflare ?
							"<span class='text-warning'>Yes</span>" :
							"<span class='text-success'>No</span>";
						var status = f.already_enabled ?
							"<span class='text-success'>Enabled</span>" :
							"<span class='text-muted'>-</span>";
						html += "<tr>" +
							"<td>" + f.title + "</td>" +
							"<td>" + f.http_code + "</td>" +
							"<td>" + challenge + "</td>" +
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

	testFlareSolverr: function() {
		Notify.progress('Testing FlareSolverr...', true);

		xhr.post("backend.php", {
			op: "PluginHandler",
			plugin: "fu_cloudflare",
			method: "testFlareSolverr"
		}, function(reply) {
			var div = document.getElementById("fu_flaresolverr_result");

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

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

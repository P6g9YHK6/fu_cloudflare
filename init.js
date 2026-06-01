Plugins.Fu_Cloudflare = {

	testConnection: function() {
		var url = dijit.byId("fu_test_url").get("value");
		if (!url) {
			alert("Enter a URL to test.");
			return;
		}

		Notify.progress('Testing FlareSolverr connection...', true);

		xhr.post("backend.php", {
			op: "PluginHandler",
			plugin: "fu_cloudflare",
			method: "testConnection",
			test_url: url
		}, function(reply) {
			var div = document.getElementById("fu_test_result");

			try {
				var result = JSON.parse(reply);

				if (result.success) {
					div.innerHTML = "<div class='notice alert alert-info'>" +
						"<strong>Success!</strong> " +
						result.time + "s, " + result.size + " bytes" +
						(result.title ? "<br>Feed: " + result.title : "") +
						"</div>";
				} else {
					div.innerHTML = "<div class='notice alert alert-warning'>" +
						"<strong>Error:</strong> " + result.error +
						"</div>";
				}
			} catch(e) {
				div.innerHTML = "<div class='notice alert alert-warning'>" +
					reply +
					"</div>";
			}
		});
	}
};

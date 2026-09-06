// Centralized OIDC reauth handler (OIDC Session Authority v2, WP9 / issue #159).
//
// When an OIDC session is past its back-channel coverage boundary the server
// refuses protected XHR/REST calls with a 401 whose JSON body carries
// { success: false, code: "oidc_revalidation_required", revalidation_url: ... }.
// Rather than leave every caller to notice a 401 and guess what to do, ONE place
// watches every fetch and XMLHttpRequest response for that code and, on seeing
// it, navigates the browser to the revalidation URL — the silent prompt=none
// round-trip. The mutating request that hit the wall is NOT replayed: the browser
// simply leaves for the round-trip, and the user's client may repeat the action
// once access is restored (§21).
//
// It wraps fetch and XHR globally, so it must load before page scripts make
// their calls; header.php includes it right after common.js for that reason.

(function () {
  var REVALIDATION_CODE = "oidc_revalidation_required";
  var redirecting = false;

  function redirectTo(url) {
    // Guarded so two near-simultaneous 401s do not both navigate.
    if (redirecting) {
      return;
    }
    if (typeof url !== "string" || url === "") {
      return;
    }
    redirecting = true;
    window.location.assign(url);
  }

  function inspectBody(status, bodyText) {
    if (status !== 401 || !bodyText) {
      return;
    }
    var data;
    try {
      data = JSON.parse(bodyText);
    } catch (error) {
      // Not JSON, or not ours — leave it for the caller's own error handling.
      return;
    }
    if (data && data.code === REVALIDATION_CODE && data.revalidation_url) {
      redirectTo(data.revalidation_url);
    }
  }

  if (typeof window.fetch === "function") {
    var originalFetch = window.fetch;
    window.fetch = function () {
      return originalFetch.apply(this, arguments).then(function (response) {
        if (response && response.status === 401) {
          // Read a CLONE so the original response body stays available to the
          // caller that made the request.
          try {
            response
              .clone()
              .text()
              .then(function (text) {
                inspectBody(401, text);
              })
              .catch(function () {});
          } catch (error) {
            // ignore
          }
        }
        return response;
      });
    };
  }

  if (typeof window.XMLHttpRequest === "function") {
    var originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function () {
      this.addEventListener("load", function () {
        try {
          if (this.status !== 401) {
            return;
          }
          // responseText throws for a binary responseType; guarded above and here.
          var text = "";
          if (this.responseType === "" || this.responseType === "text") {
            text = this.responseText;
          }
          inspectBody(401, text);
        } catch (error) {
          // ignore
        }
      });
      return originalSend.apply(this, arguments);
    };
  }
})();

<?php
// A signed-in account is required, and nothing else.
//
// includes/validate_endpoint.php is the guard for endpoints that write: it
// insists on POST and on a CSRF token, because those requests change something
// and the caller has to prove they meant to. Ten endpoints under endpoints/
// read instead, over GET, and so could use neither — which is how they ended up
// with no guard at all (issue #97).
//
// What that looked like from outside: `endpoints/subscriptions/get.php` with no
// cookie answered HTTP 200 and 755 bytes, having run the page-building code
// with no user. Nothing leaked — the body held no subscription data — but three
// PHP warnings did, naming absolute paths and line numbers. And the warning
// that matters most is the fourth one:
//
//     http_response_code(): Cannot set response code - headers already sent
//     (output started at includes/list_subscriptions.php:402)
//
// The endpoint does try to refuse. It cannot, because the warnings its own
// page-building emitted had already sent the headers. The refusal existed; the
// output beat it to the wire. This file is included before any of that runs, so
// the refusal is the first thing that happens rather than the last.
//
// No CSRF check here on purpose. A token cannot be required of a request the
// browser makes as a plain GET, and pretending otherwise would mean either
// breaking these endpoints or writing a check that always passes. Their
// protection is the session; where one of them grows a side effect worth
// protecting, it belongs behind validate_endpoint.php instead.

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => translate('session_expired', $i18n)]);
    exit;
}

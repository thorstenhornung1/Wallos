<?php
/*
  Who may reach an endpoint, and what a refusal looks like on the wire.

  Two shapes of the same defect (issue #97).

  Every refusal answered HTTP 200. `includes/validate_endpoint.php` and its
  admin variant wrote a JSON body explaining what was wrong and exited without
  ever calling http_response_code(), so a request with no session, an expired
  session or a bad CSRF token was refused with a success status. The refusal was
  correct; the status code said the opposite. Anything that reads status codes
  rather than parsing bodies — a reverse proxy, a monitoring probe, `curl -f`, a
  rate limiter counting 401s — was told the request had worked.

  And ten endpoints under endpoints/ had no guard at all, because they read over
  GET and the only guard available insisted on POST and a CSRF token. Requested
  with no cookie, `endpoints/subscriptions/get.php` answered 200 and ran the
  page-building code with no user. Nothing leaked, but three PHP warnings did,
  naming absolute paths and line numbers — and a fourth said the interesting
  part:

      http_response_code(): Cannot set response code - headers already sent
      (output started at includes/list_subscriptions.php:402)

  The endpoint did try to refuse. Its own output had already sent the headers.

  These are source-level cases because the guards `exit`, which a test in the
  same process cannot survive. The behaviour was checked against a running
  instance instead: 401 and 70 bytes with no cookie where there were 200 and 755
  bytes with four warnings, and 200 for the same endpoint when signed in — the
  second half being what tells a working guard from one that refuses everybody.
*/

/**
 * Endpoints that legitimately carry no session guard.
 *
 * Both run during setup, before any account exists, and check a one-time token
 * of their own instead. Listed rather than pattern-matched, so that adding to
 * this list is a decision somebody makes on purpose.
 *
 * @return string[]
 */
function endpoint_guard_exempt()
{
    return ['endpoints/db/import.php', 'endpoints/db/migrate.php'];
}

/**
 * Every endpoint that includes the shared bootstrap.
 *
 * @return string[]
 */
function endpoint_guard_endpoints()
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(WALLOS_ROOT . '/endpoints', RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(WALLOS_ROOT . '/', '', $file->getPathname());
        $source = file_get_contents($file->getPathname());

        // The cron jobs run from the command line and have no session to check.
        if (strpos($path, 'endpoints/cronjobs/') === 0) {
            continue;
        }

        if (strpos($source, 'connect_endpoint.php') !== false) {
            $found[] = $path;
        }
    }

    sort($found);

    return $found;
}

wallos_test('every refusal sets a status code before it writes a body', function () {
    $guards = [
        'includes/validate_endpoint.php' => ['405', '403', '401'],
        'includes/validate_endpoint_admin.php' => ['403'],
        'includes/validate_endpoint_session.php' => ['401'],
    ];

    foreach ($guards as $path => $codes) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        // As many status codes as there are ways to be refused. Counting rather
        // than matching one: the file used to have three refusals and zero
        // codes, and a single http_response_code() anywhere in it would satisfy
        // a looser assertion while two paths still answered 200.
        $refusals = substr_count($source, 'exit;') + substr_count($source, 'die(json_encode');
        // A three-digit argument, so that the file quoting the warning
        // "http_response_code(): Cannot set response code" in its header is not
        // counted as calling it — the mistake a plain substring count makes,
        // and one this very file walked into.
        $set = preg_match_all('/http_response_code\(\d{3}\)/', $source);

        assert_same($refusals, $set,
            $path . ' sets a status code on each of its ' . $refusals . ' refusals');

        foreach ($codes as $code) {
            assert_contains('http_response_code(' . $code . ')', $source,
                $path . ' answers ' . $code);
        }

        // 200 is what these used to answer. Nothing here should ever set it.
        assert_not_contains('http_response_code(200)', $source, $path . ' never reports success');
    }
});

wallos_test('no endpoint reaches the application without a guard', function () {
    $exempt = endpoint_guard_exempt();
    $endpoints = endpoint_guard_endpoints();
    $unguarded = [];

    foreach ($endpoints as $path) {
        if (in_array($path, $exempt, true)) {
            continue;
        }

        $source = file_get_contents(WALLOS_ROOT . '/' . $path);

        if (preg_match('/validate_endpoint(_admin|_session)?\.php/', $source) !== 1) {
            $unguarded[] = $path;
        }
    }

    assert_same([], $unguarded, 'every endpoint includes one of the three guards');

    // The negative control: a search that found nothing would pass the line
    // above and prove nothing at all.
    assert_true(count($endpoints) > 50, 'the endpoints were actually read');
});

wallos_test('the read guard runs before anything can produce output', function () {
    // The fourth warning in the report is what this is for: the endpoint could
    // not set a status code, because its own page-building had already sent the
    // headers. A guard included after that work would refuse just as correctly
    // and just as uselessly.
    foreach (endpoint_guard_endpoints() as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);
        $guard = strpos($source, 'validate_endpoint_session.php');

        if ($guard === false) {
            continue;
        }

        $bootstrap = strpos($source, 'connect_endpoint.php');

        assert_true($guard < $bootstrap + 200,
            $path . ' includes the guard immediately after the bootstrap');
    }
});

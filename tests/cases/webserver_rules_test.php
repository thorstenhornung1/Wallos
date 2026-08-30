<?php
/*
  What the web server refuses to execute.

  The 5.8.0 ownership split made the application code unwritable by www-data and
  left three directories writable: db/, images/uploads/ and everything under it.
  Those are exactly the three that executed PHP (issue #94). Nothing stood
  between that and remote code execution except the application never writing an
  attacker-controlled name or type into them — an invariant that holds today,
  was tested rather than assumed, and has no business being the only thing
  holding.

  The rules that existed named directories one at a time:
  images/uploads/logos/ was refused and images/uploads/icons/ was not. Nothing
  writes to icons/ at all, which is how it went unnoticed — a rule that has to
  be extended every time a directory is added is a rule that is out of date
  again the next time one is.

  Two things this checks, and the second is the one a reader will not think of:
  the rule exists, and it comes before the handler that would execute the file.
  nginx tries regex locations in order and takes the first match, so a deny rule
  after `\.php$` is a deny rule that never runs. The comment above them says so;
  this makes it fail rather than say so.

  Both files, because both ship: nginx.conf carries the server block the
  container runs, and nginx.default.conf goes to http.d. They have drifted
  before.
*/

/**
 * @param string $path
 * @return string
 */
function webserver_rules_source($path)
{
    return file_get_contents(WALLOS_ROOT . '/' . $path);
}

wallos_test('php is refused in every directory the web server user can write to', function () {
    foreach (['nginx.conf', 'nginx.default.conf'] as $path) {
        $source = webserver_rules_source($path);

        // The writable set, by prefix rather than one entry per directory.
        assert_contains('^/(db|images/uploads)/', $source,
            $path . ' refuses by prefix, so a new directory under uploads is covered');

        foreach (['php', 'phtml', 'phar'] as $extension) {
            assert_contains($extension, $source, $path . ' names ' . $extension);
        }
    }
});

wallos_test('the refusal comes before the handler that would run the file', function () {
    // nginx uses the first matching regex location. A deny rule placed after
    // the \.php$ handler is never reached, and the configuration still passes
    // `nginx -t` — it just executes everything.
    foreach (['nginx.conf', 'nginx.default.conf'] as $path) {
        $source = webserver_rules_source($path);

        $deny = strpos($source, '^/(db|images/uploads)/');
        $handler = strpos($source, 'fastcgi_pass');

        assert_true($deny !== false && $handler !== false, $path . ' has both');
        assert_true($deny < $handler,
            $path . ' refuses the writable directories before the fastcgi handler');
    }
});

wallos_test('the database directory is denied whole, not by extension', function () {
    // It used to deny \.db$, which protects the database and serves anything
    // else somebody puts there — a .sql, a .bak, a .tar.gz. Nothing writes such
    // a file today, and that is the dependency #94 was about: an invariant
    // carrying the whole safety margin in a layer with no reason to depend on
    // it. The 2026-08-21 test run quoted that reasoning back at the release
    // that made it.
    foreach (['nginx.conf', 'nginx.default.conf'] as $path) {
        $source = webserver_rules_source($path);

        assert_contains('location ^~ /db/', $source, $path . ' denies the directory by prefix');
        assert_not_contains('location ~ \.db$', $source,
            $path . ' no longer relies on the extension');
    }
});

wallos_test('php-fpm is told which extensions it may run', function () {
    // The second layer. nginx decides what it hands to php-fpm; this decides
    // what php-fpm agrees to run when something else asks — and the whole
    // finding was that one of the two layers had fallen behind.
    $dockerfile = webserver_rules_source('Dockerfile');

    assert_contains('security.limit_extensions', $dockerfile,
        'the pool restricts what php-fpm will execute');
});

wallos_test('the directories the container makes writable are the ones refused', function () {
    // The two lists have to agree. If the Dockerfile hands www-data a fourth
    // directory, the rules above stop covering the writable set, and the next
    // person to notice will be whoever finds the file in it.
    $dockerfile = webserver_rules_source('Dockerfile');

    preg_match('/chown -R www-data:www-data ([^&\n]+)/', $dockerfile, $match);
    assert_true(isset($match[1]), 'the Dockerfile hands ownership to www-data somewhere');

    $writable = [];
    foreach (preg_split('/\s+/', trim($match[1])) as $path) {
        $path = trim(str_replace('/var/www/html/', '', $path));

        if ($path !== '') {
            $writable[] = $path;
        }
    }

    sort($writable);

    // .tmp left the webroot with #86 — restore staging lives under the system
    // temp directory now, so the writable set shrank to the two data mounts.
    // The nginx prefix rule denying /.tmp/ stays for images that predate the
    // move.
    assert_same(['db', 'images/uploads'], $writable,
        'the writable set is db/ and images/uploads/ — and nothing else');
});

<?php
/*
  Running the container as an unprivileged user (#86).

  Upstream #955 asks for `user: uid:gid` and a read-only root filesystem.
  The pieces measured in the issue: dcron will not run jobs unless it is
  root and has to be replaced; nginx needs the bind capability and its
  writable paths pointed at /tmp; startup.sh used to die on its first line
  under `user:` because it wrote a log file before anything could explain
  why; and the whole tree has to follow the gid-0 convention so an
  arbitrary uid can be granted access by group.

  These are source gates: the behaviour itself is container-level and was
  verified against the built image (root mode, `user: 1000:0` with a
  read-only root and one tmpfs, and the loud preflight refusal under
  `user: 1000:1000` against an unprepared volume).
*/

wallos_test('startup decides privilege before it writes anything', function () {
    $startup = file_get_contents(WALLOS_ROOT . '/startup.sh');

    assert_true(strpos($startup, '/var/log/startup.log') === false,
        'the first line no longer writes a file an unprivileged user cannot');
    assert_true(strpos($startup, 'startup.txt') === false,
        'the dead marker file is gone');

    // The uid check has to come before every privileged call, or set -e
    // kills the script half-configured.
    $check = strpos($startup, 'id -u');
    assert_true($check !== false, 'the uid check exists');
    foreach (['groupmod', 'usermod', 'chown'] as $privileged) {
        $call = strpos($startup, $privileged);
        assert_true($call === false || $check < $call,
            $privileged . ' runs only after the uid check');
    }
});

wallos_test('cron jobs are run by something that does not need root', function () {
    // dcron maps crontab filename to username and calls setuid(): measured
    // in the issue, it synchronises, tests jobs, and runs nothing at all
    // unless it is root. supercronic runs them as the current uid.
    $dockerfile = file_get_contents(WALLOS_ROOT . '/Dockerfile');
    $startup = file_get_contents(WALLOS_ROOT . '/startup.sh');

    assert_true(strpos($dockerfile, 'dcron') === false, 'dcron is gone from the image');
    assert_true(strpos($dockerfile, 'supercronic') !== false, 'supercronic replaces it');
    assert_true(strpos($startup, 'crond') === false, 'startup no longer launches crond');
    assert_true(strpos($startup, 'supercronic') !== false, 'startup launches supercronic');
});

wallos_test('nginx can bind its port and write its state without root', function () {
    $dockerfile = file_get_contents(WALLOS_ROOT . '/Dockerfile');
    $nginx = file_get_contents(WALLOS_ROOT . '/nginx.conf');

    assert_true(strpos($dockerfile, 'cap_net_bind_service') !== false,
        'the binary carries the bind capability, so existing compose files keep port 80');
    assert_true(strpos($nginx, 'pid        /tmp/') !== false || strpos($nginx, 'pid /tmp/') !== false,
        'the pid file lives under /tmp');
    foreach (['client_body_temp_path', 'proxy_temp_path', 'fastcgi_temp_path'] as $directive) {
        assert_true(strpos($nginx, $directive) !== false,
            $directive . ' is pointed away from /var/lib/nginx');
    }
});

wallos_test('everything ephemeral lives under one tmpfs', function () {
    // The issue's end state: one tmpfs at /tmp and the two volumes. Cron
    // logs moved there, and the restore staging left the webroot — which
    // also removes the last writable path inside it.
    foreach (file(WALLOS_ROOT . '/cronjobs') as $line) {
        assert_true(strpos($line, '/var/log/cron') === false,
            'a job still logs outside /tmp: ' . trim($line));
    }

    foreach (['endpoints/db/restore.php', 'endpoints/db/import.php'] as $path) {
        $source = file_get_contents(WALLOS_ROOT . '/' . $path);
        assert_true(strpos($source, "'../../.tmp") === false,
            $path . ' no longer stages inside the webroot');
        assert_true(strpos($source, 'sys_get_temp_dir') !== false,
            $path . ' stages under the system temp directory');
    }
});

wallos_test('a developer database cannot ride the build into the image', function () {
    // Found by the mode gate's first real run: built from a working tree
    // that held a local db/wallos.db, COPY . . shipped it, and a fresh named
    // volume then inherited it — an instance born with somebody else's data
    // and "No migrations to run" on first boot. CI builds from a clean
    // checkout; a developer's tree is not, and the ignore file is what makes
    // the two builds the same.
    $ignored = file_get_contents(WALLOS_ROOT . '/.dockerignore');

    foreach (['db/wallos.db', 'db/setup_token.db'] as $localState) {
        assert_true(strpos($ignored, $localState) !== false,
            $localState . ' stays out of the build context');
    }
});

wallos_test('an arbitrary uid with gid 0 can run the image', function () {
    // The OpenShift convention: group 0 owns what the runtime user must
    // write, with group permissions equal to the owner's. Measured caveat
    // from the issue: user 1000:1000 still cannot write — that is what the
    // startup preflight refuses loudly, naming the chown to run.
    $dockerfile = file_get_contents(WALLOS_ROOT . '/Dockerfile');

    assert_true(strpos($dockerfile, 'chgrp -R 0') !== false, 'group 0 owns the data directories');
    assert_true(strpos($dockerfile, 'g=u') !== false, 'and may do what the owner may');
});

wallos_test('the four modes are pinned by an executable check', function () {
    // The comment at the top of this file used to be the only record that the
    // four modes were verified against the built image — by hand, once. A
    // manual verification decays the day someone edits startup.sh; this case
    // pins the executable one: dev/container-modes.sh boots the image in all
    // four modes, and CI runs it on every push and pull request.
    $script = WALLOS_ROOT . '/dev/container-modes.sh';
    assert_true(is_file($script), 'dev/container-modes.sh exists');

    $source = (string) @file_get_contents($script);
    foreach (['1000:0', '1000:1000', 'cap-drop', 'WALLOS_HTTP_PORT'] as $marker) {
        assert_true(strpos($source, $marker) !== false,
            'the script exercises the mode marked by ' . $marker);
    }

    $workflowPath = WALLOS_ROOT . '/.github/workflows/container-modes.yaml';
    assert_true(is_file($workflowPath), 'the container-modes workflow exists');
    assert_true(strpos((string) @file_get_contents($workflowPath), 'container-modes.sh') !== false,
        'the workflow actually calls the script');
});

wallos_test('the port and the healthcheck agree, and both are configurable', function () {
    // setcap keeps port 80 for existing deployments, but the audience that
    // drops ALL capabilities loses it — so the listen port follows
    // WALLOS_HTTP_PORT, and the healthcheck asks the same variable instead
    // of a hardcoded URL.
    $dockerfile = file_get_contents(WALLOS_ROOT . '/Dockerfile');
    $startup = file_get_contents(WALLOS_ROOT . '/startup.sh');

    assert_true(strpos($startup, 'WALLOS_HTTP_PORT') !== false,
        'startup rewrites the listen port when asked');
    assert_true(strpos($dockerfile, 'WALLOS_HTTP_PORT') !== false,
        'the healthcheck follows the same variable');
});

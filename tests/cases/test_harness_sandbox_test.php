<?php
/*
  The harness's own sandbox is a cache that used to record nothing about the
  tree it was built from. Its boundary files are symlinks back into WALLOS_ROOT,
  so a sandbox built inside an agent worktree that then survived into a run
  against the main checkout took the run down — a "Cannot redeclare …" while the
  worktree still existed, a "Failed opening required …/cron_run.php" once it had
  been removed. Either way, dozens of unrelated-looking failures far from the
  cause (#146).

  These cases hold the invalidation that replaced that guesswork: the sandbox
  now stamps the tree it was built from, a dangling symlink is caught directly,
  and the rebuild's rmtree refuses to reach outside the harness temp tree. This
  is a defect in the test harness, so it gets a test in the test harness.
*/

wallos_test('the sandbox stamps the tree it was built from', function () {
    if (wallos_test_skip_unless_sqlite('the sandbox template is the SQLite fixture')) {
        return;
    }

    // Building or reusing the sandbox leaves a stamp naming this tree — the one
    // thing it never recorded, and whose absence let a stale one look valid.
    $copy = wallos_test_database();
    assert_true(file_exists($copy), 'a throwaway database copy is produced');
    @unlink($copy);

    $stamp = WALLOS_TEST_TMP . '/sandbox/.built-from';
    assert_true(is_file($stamp), 'the sandbox stamps the tree it was built from');
    assert_same(WALLOS_ROOT, trim((string) file_get_contents($stamp)),
        'and the stamp is this tree, so a run against another one rebuilds');
});

wallos_test('a sandbox from another tree, a removed one, or an unstamped one is stale', function () {
    $probe = WALLOS_TEST_TMP . '/sandbox-probe-' . uniqid('', true);
    mkdir($probe . '/includes/database/sqlite', 0700, true);

    // A live sandbox from this tree, its boundary symlink resolvable.
    symlink(WALLOS_ROOT . '/includes/cron_run.php', $probe . '/includes/cron_run.php');
    file_put_contents($probe . '/.built-from', WALLOS_ROOT);
    assert_true(!wallos_test_sandbox_stale($probe),
        'a sandbox from this tree with a live symlink is usable');

    // Built from a different tree — the redeclare case: the symlink would
    // resolve to a second copy of a file already loaded from WALLOS_ROOT.
    file_put_contents($probe . '/.built-from', '/var/www/html/.claude/worktrees/agent-OTHER');
    assert_true(wallos_test_sandbox_stale($probe),
        'a sandbox stamped with a different tree is stale');

    // Built from a removed tree — the dangling-symlink case, the one that
    // produced eighteen "Failed opening required" failures.
    file_put_contents($probe . '/.built-from', WALLOS_ROOT);
    unlink($probe . '/includes/cron_run.php');
    symlink('/var/www/html/.claude/worktrees/agent-REMOVED/includes/cron_run.php',
        $probe . '/includes/cron_run.php');
    assert_true(wallos_test_sandbox_stale($probe),
        'a symlink dangling into a removed worktree is stale');

    // No stamp at all — a sandbox from before this was recorded. Only the
    // missing stamp is the fault now; the link resolves again.
    unlink($probe . '/.built-from');
    unlink($probe . '/includes/cron_run.php');
    symlink(WALLOS_ROOT . '/includes/cron_run.php', $probe . '/includes/cron_run.php');
    assert_true(wallos_test_sandbox_stale($probe),
        'a sandbox that records nothing about its tree is treated as stale');

    wallos_test_rmtree($probe);
    assert_true(!is_dir($probe), 'the probe is removed — it lived under the temp tree');
});

wallos_test('the rebuild refuses to reach outside the harness temp tree', function () {
    // A rebuild deletes the stale sandbox with wallos_test_rmtree(). That it can
    // never be aimed at the source is the whole safety of doing so: point it at a
    // directory outside WALLOS_TEST_TMP and it must leave it untouched.
    $outside = sys_get_temp_dir() . '/wallos-not-harness-' . uniqid('', true);
    mkdir($outside, 0700, true);
    file_put_contents($outside . '/keep.txt', 'x');

    wallos_test_rmtree($outside);

    assert_true(is_dir($outside) && file_exists($outside . '/keep.txt'),
        'rmtree refused a path outside WALLOS_TEST_TMP and removed nothing');

    // Clean up ourselves — with the tools that will, since rmtree correctly will
    // not.
    unlink($outside . '/keep.txt');
    rmdir($outside);
});

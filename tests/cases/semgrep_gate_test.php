<?php
/*
  The SQLite-boundary gate wraps Semgrep, and Semgrep can run, fail internally,
  print its complaint and still return 0 — a worktree's .git file trips its
  safe-directory check, and that is where it was seen (#145). Exit code alone
  then cannot tell "checked everything, all clean" from "checked nothing", and
  every agent running the gate in its worktree got a green that meant silence.

  This is a gate about a gate, so it needs the same treatment every other gate
  here gets: a test that drives the "scanned nothing" case and fails if the
  guard is removed. Semgrep itself is not a dependency of the suite and is not
  present in the throwaway test container, so these cases stand a stub `semgrep`
  on PATH that reports a chosen number of scanned files — the one input the guard
  turns on — and check that the gate's exit code follows it.
*/

/**
 * A fake `semgrep` on PATH that writes the JSON Semgrep would, with exactly
 * $scanned entries in paths.scanned, and exits 0 — the "ran, returned success"
 * the guard must not trust blindly.
 *
 * @param int $scanned
 * @return string directory to prepend to PATH
 */
function semgrep_gate_stub($scanned)
{
    $dir = WALLOS_TEST_TMP . '/semgrep-stub-' . uniqid('', true);
    mkdir($dir, 0700, true);

    $stub = "#!/bin/sh\n"
        . "out=\n"
        . "for a in \"\$@\"; do\n"
        . "  case \"\$a\" in --json-output=*) out=\${a#--json-output=} ;; esac\n"
        . "done\n"
        . "n=\${WALLOS_FAKE_SCANNED:-0}\n"
        . "arr=\n"
        . "i=0\n"
        . "while [ \"\$i\" -lt \"\$n\" ]; do\n"
        . "  if [ -n \"\$arr\" ]; then arr=\"\$arr,\"; fi\n"
        . "  arr=\"\$arr\\\"file\$i.php\\\"\"\n"
        . "  i=\$((i + 1))\n"
        . "done\n"
        . "if [ -n \"\$out\" ]; then\n"
        . "  printf '{\"results\":[],\"errors\":[],\"paths\":{\"scanned\":[%s]}}' \"\$arr\" > \"\$out\"\n"
        . "fi\n"
        . "exit 0\n";

    file_put_contents($dir . '/semgrep', $stub);
    chmod($dir . '/semgrep', 0755);

    return $dir;
}

/**
 * Runs the gate with the stub on PATH, telling it how many files to report as
 * scanned. The gate's runner is invoked in local mode so it uses the stub and
 * needs no container engine.
 *
 * @param string $stubDir
 * @param int    $scanned
 * @return array{output: string, status: int}
 */
function semgrep_gate_run($stubDir, $scanned)
{
    $command = 'PATH=' . escapeshellarg($stubDir) . ':$PATH '
        . 'WALLOS_FAKE_SCANNED=' . (int) $scanned . ' '
        . 'sh ' . escapeshellarg(WALLOS_ROOT . '/dev/semgrep/run.sh') . ' --local 2>&1';

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    return ['output' => implode("\n", $output), 'status' => $status];
}

wallos_test('the gate fails when the scan examined no files', function () {
    $stub = semgrep_gate_stub(0);

    $run = semgrep_gate_run($stub, 0);

    assert_true($run['status'] !== 0,
        'a scan of zero files is not a pass — the gate must exit non-zero (got '
        . $run['status'] . ': ' . $run['output'] . ')');
    assert_contains('scanned no files', $run['output'],
        'and it says why, rather than failing mutely');

    wallos_test_rmtree($stub);
});

wallos_test('the gate passes when the scan examined files and found nothing', function () {
    $stub = semgrep_gate_stub(9);

    $run = semgrep_gate_run($stub, 9);

    assert_same(0, $run['status'],
        'a scan that examined files and is clean passes (got ' . $run['status']
        . ': ' . $run['output'] . ')');

    wallos_test_rmtree($stub);
});

<?php
/*
  dev/write-audit.php — the ratchet on writes whose outcome nobody reads.

  Issue #87: a statement whose return value is discarded, followed by a response
  that says it worked. Four defects across three releases were that one shape,
  and none of them was found by anything in this repository — two came from
  external review, one from running the suite against a second backend, one from
  an audit.

  The audit does not fix them. It counts them and holds the number, so the next
  one is a failing test rather than the fifth coat. That only works if the
  counting is itself tested: a gate that quietly stops recognising the pattern
  passes forever, which is the same failure mode it was written to catch.

  So the cases below are in two halves — what the scanner sees in source it is
  handed, both directions, and whether the checked-in baseline still describes
  this working tree.
*/

require_once WALLOS_ROOT . '/dev/write-audit.php';

/**
 * Runs the scanner over a snippet.
 *
 * @param string $body PHP without its opening tag
 * @return array{discarded: int[], unchecked: int[], writes: int}
 */
function write_audit_scan_snippet($body)
{
    return write_audit_scan("<?php\n" . $body);
}

wallos_test('a discarded execute is counted, and a used one is not', function () {
    $discarded = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM tokens WHERE id = 1");
        if ($statement === false) { return false; }
        $statement->execute();
    ');

    assert_same(1, count($discarded['discarded']), 'the result went nowhere');

    // The negative control, and the reason this is parsed rather than searched:
    // the two lines differ by six characters a text search cannot act on.
    $used = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM tokens WHERE id = 1");
        if ($statement === false) { return false; }
        if ($statement->execute() === false) { return false; }
        return $db->changes();
    ');

    assert_same(0, count($used['discarded']), 'a checked result is not a finding');
});

wallos_test('an unchecked prepare is counted, and a checked one is not', function () {
    $unchecked = write_audit_scan_snippet('
        $statement = $db->prepare("UPDATE user SET email = :email");
        $statement->bindValue(":email", $email);
        $ok = $statement->execute();
    ');

    assert_same(1, count($unchecked['unchecked']), 'prepare() can return false and nothing looked');
    assert_same(1, $unchecked['writes'], 'and the statement changes data');

    foreach (['if ($statement === false) { return false; }',
              'if (!$statement) { return false; }'] as $guard) {
        $checked = write_audit_scan_snippet('
            $statement = $db->prepare("UPDATE user SET email = :email");
            ' . $guard . '
            $ok = $statement->execute();
        ');

        assert_same(0, count($checked['unchecked']), 'checked by: ' . $guard);
    }
});

wallos_test('a read is told from a write, and an unreadable query counts as one', function () {
    // The split the measurement exists for: the decision in issue #87 is about
    // writes, and a number covering both would answer a different question.
    $read = write_audit_scan_snippet('$statement = $db->prepare("SELECT id FROM user");');
    assert_same(1, count($read['unchecked']), 'still unchecked');
    assert_same(0, $read['writes'], 'but it changes nothing');

    $built = write_audit_scan_snippet('$statement = $db->prepare($sql);');
    assert_same(1, $built['writes'],
        'a query this cannot read is counted as a write, because that is the safe way to be wrong');
});

wallos_test('prose about execute() is not a call to it', function () {
    // What a text search gets wrong, and why this file can describe the pattern
    // it counts without counting itself.
    $comments = write_audit_scan_snippet('
        // $statement->execute();
        /** Calls $statement->execute(); and ignores the result. */
        $sql = "$statement->execute();";
    ');

    assert_same(0, count($comments['discarded']), 'comments and strings are not code');
});

wallos_test('the committed baseline matches this working tree', function () {
    $measured = write_audit_measure(WALLOS_ROOT);
    $baseline = write_audit_read_baseline(WALLOS_ROOT . '/dev/write-audit-baseline.txt');

    assert_true($baseline !== [], 'the baseline exists');

    $comparison = write_audit_compare($measured, $baseline);

    foreach ($comparison['regressions'] as $regression) {
        wallos_test_fail($regression);
    }

    assert_same([], $comparison['regressions'],
        'no file gained a write whose result nobody reads');

    // An improvement is not a failure, but an unrecorded one means the next
    // regression is measured against a number that is no longer true.
    assert_same([], $comparison['improvements'],
        'run dev/write-audit.php --update and commit the diff');
});

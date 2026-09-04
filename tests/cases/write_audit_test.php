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

wallos_test('the middle of a ternary is not the start of a statement', function () {
    // What this got wrong for a day. The colon is a statement boundary in
    // `case 1:` and in the alternative syntax, and it is also the middle of a
    // ternary, where what follows is an expression whose value the assignment
    // on the left receives. Thirteen checked writes were reported as unchecked
    // — and reporting correct code as a defect is worse for a ratchet than
    // missing one, because it asks for the correct code to be rewritten.
    $ternary = write_audit_scan_snippet(
        '$ok = $stmt === false ? false : $stmt->execute();');

    assert_same(0, count($ternary['discarded']), 'the result is assigned, whatever the colon suggests');

    // The colon still ends a statement where it really does.
    $inCase = write_audit_scan_snippet('
        switch ($x) {
            case 1:
                $stmt->execute();
                break;
        }');

    assert_same(1, count($inCase['discarded']), 'a discarded result after a case label is still one');
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

wallos_test('the query is followed to where it was written', function () {
    // Almost no call passes a literal: this codebase writes $sql = "..." and
    // then prepares $sql. A classifier that reads only the first token inside
    // prepare() answers "unknown" for nearly all of them, and unknown counts as
    // a write — safe for the ratchet, and the wrong number for a decision. It
    // had the report claiming almost every unchecked prepare changes data when
    // most of them only read.
    $reading = write_audit_scan_snippet('
        $sql = "SELECT * FROM subscriptions WHERE user_id = :id";
        $statement = $db->prepare($sql);
        $statement->bindValue(":id", 1);
    ');

    assert_same(0, $reading['writes'], 'a SELECT reached through a variable only reads');

    $writing = write_audit_scan_snippet('
        $sql = "UPDATE subscriptions SET name = :name WHERE id = :id";
        $statement = $db->prepare($sql);
        $statement->bindValue(":id", 1);
    ');

    assert_same(1, $writing['writes'], 'and an UPDATE reached the same way still counts');

    // Concatenation is the ordinary shape for a query with a variable column
    // list, so the classification cannot depend on the whole query being one
    // literal.
    $assembled = write_audit_scan_snippet('
        $sql = "UPDATE settings SET " . implode(", ", $fields) . " WHERE user_id = :id";
        $statement = $db->prepare($sql);
        $statement->bindValue(":id", 1);
    ');

    assert_same(1, $assembled['writes'], 'the first literal of the query is enough');

    // Unknown still counts as a write. The direction matters: this number
    // decides nothing on its own, but guessing "read" for something unreadable
    // would understate the one thing issue #87 is about.
    $unknown = write_audit_scan_snippet('
        $statement = $db->prepare(build_query($table));
        $statement->bindValue(":id", 1);
    ');

    assert_same(1, $unknown['writes'], 'a query this cannot read is assumed to write');
});

wallos_test('a write nobody read, and a response that says it worked', function () {
    $found = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM css WHERE user_id = 1");
        $statement->execute();
        echo json_encode(["success" => true]);
    ');

    assert_same(1, count($found['unreported']),
        'the response claims what the write never confirmed');

    // The negative control the whole third number rests on: the same two
    // statements, one of them asked.
    $checked = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM css WHERE user_id = 1");
        if ($statement->execute() === false) { exit(1); }
        echo json_encode(["success" => true]);
    ');

    assert_same(0, count($checked['unreported']), 'a checked write is not a finding');
});

wallos_test('an assignment nobody reads is not a check', function () {
    // Forty of the eighty findings in the upstream tree are this exact shape,
    // almost all of them in the two account deletion paths. Accepting an
    // assignment as consultation loses the case the number exists for.
    $ignored = write_audit_scan_snippet('
        $statement = $db->prepare("UPDATE user SET name = :n WHERE id = 1");
        $result = $statement->execute();
        echo json_encode(["success" => true]);
    ');

    assert_same(1, count($ignored['unreported']), 'assigned and never looked at again');

    $read = write_audit_scan_snippet('
        $statement = $db->prepare("UPDATE user SET name = :n WHERE id = 1");
        $result = $statement->execute();
        if (!$result) { exit(1); }
        echo json_encode(["success" => true]);
    ');

    assert_same(0, count($read['unreported']), 'assigned and read is a check');
});

wallos_test('the other arm of a conditional is not reachable from this one', function () {
    // Why the scope is the nesting path rather than the file: a write in one
    // arm and a success response in the other never run together, and a
    // file-wide rule cannot tell that from the shape it is looking for.
    $siblings = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM css WHERE user_id = 1");
        if ($wanted) {
            $statement->execute();
        } else {
            echo json_encode(["success" => true]);
        }
    ');

    assert_same(0, count($siblings['unreported']),
        'the response is in the branch the write did not take');
});

wallos_test('control leaving before the response cuts the pair', function () {
    $left = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM css WHERE user_id = 1");
        $statement->execute();
        exit(1);
        echo json_encode(["success" => true]);
    ');

    assert_same(0, count($left['unreported']), 'the response is never reached');

    // And the trap that made every such response cut itself off while this was
    // being built: the interrupt has to count at the end of its statement, not
    // at the keyword, because die(json_encode(["success" => true])) *is* the
    // response.
    $inside = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM css WHERE user_id = 1");
        $statement->execute();
        die(json_encode(["success" => true]));
    ');

    assert_same(1, count($inside['unreported']), 'a response inside a die() still counts');
});

wallos_test('asking changes() is asking', function () {
    // Not the execute() result, but a genuine outcome check. Without this two
    // correct files in this tree are reported.
    $asked = write_audit_scan_snippet('
        $statement = $db->prepare("DELETE FROM css WHERE user_id = 1");
        $statement->execute();
        if ($db->changes() === 0) { exit(1); }
        echo json_encode(["success" => true]);
    ');

    assert_same(0, count($asked['unreported']), 'the outcome was checked another way');
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

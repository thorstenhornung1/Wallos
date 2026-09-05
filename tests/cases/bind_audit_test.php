<?php
/*
  dev/bind-audit.php — the gate on prepared statements whose binds and
  placeholders disagree.

  Issue #157 (the #147 class). A bind for a parameter the SQL does not name, or a
  :placeholder no bind supplies, works on SQLite and is a fatal 500 on PostgreSQL:
  PDO rejects the odd parameter, execute() returns false, and the fetchArray after
  it dies. get_admin_settings.php bound :userId to SELECT * FROM "admin" and did
  exactly that.

  The gate does not fix those. It recognises the shape statically, so the next one
  is a failing test rather than a production incident on one backend. That only
  works if the recognising is itself tested — a scanner that quietly stops seeing
  the pattern passes forever, the same failure mode it exists to catch. And, just
  as important for a gate that draws a line it will not cross, the correct shapes
  it must NOT flag are tested too: the branch-dependent query, the reused handle,
  the loop that builds placeholders and binds together. Reporting one of those as
  a defect asks for correct code to be rewritten, which is how a gate gets
  switched off.
*/

require_once WALLOS_ROOT . '/dev/bind-audit.php';

/**
 * Runs the scanner over a snippet and counts its findings by kind.
 *
 * @param string $body PHP without its opening tag
 * @return array{stray: int, missing: int}
 */
function bind_audit_kinds($body)
{
    $scan = bind_audit_scan("<?php\n" . $body);
    $kinds = ['stray' => 0, 'missing' => 0];

    foreach ($scan['findings'] as $finding) {
        $kinds[$finding['kind']]++;
    }

    return $kinds;
}

wallos_test('a stray bind is flagged, a matching one is not', function () {
    // The #147 shape exactly: a parameter bound to a query that names none.
    $stray = bind_audit_kinds('
        $stmt = $db->prepare("SELECT * FROM admin");
        $stmt->bindValue(":userId", $userId);
    ');

    assert_same(1, $stray['stray'], 'the bind names a parameter the SQL does not');
    assert_same(0, $stray['missing'], 'and there is no missing one');

    $matched = bind_audit_kinds('
        $stmt = $db->prepare("SELECT * FROM \"user\" WHERE id = :userId");
        $stmt->bindValue(":userId", $userId);
    ');

    assert_same(0, $matched['stray'], 'a bind that matches its placeholder is not a finding');
    assert_same(0, $matched['missing'], 'and nothing is unbound');
});

wallos_test('a placeholder no bind supplies is flagged', function () {
    $missing = bind_audit_kinds('
        $stmt = $db->prepare("UPDATE \"user\" SET email = :email WHERE id = :userId");
        $stmt->bindValue(":email", $email);
    ');

    assert_same(1, $missing['missing'], ':userId is declared and never bound');
    assert_same(0, $missing['stray'], 'and :email is not stray');
});

wallos_test('a colon that is a cast or lives inside a string is not a placeholder', function () {
    // PostgreSQL's value::type and a colon inside a SQL string literal both look
    // like :name to a naive reader. Treating either as a placeholder would report
    // a missing bind for something that is not a parameter at all.
    $notParameters = bind_audit_kinds('
        $stmt = $db->prepare("SELECT id::text FROM \"user\" WHERE note = \'a:b\' AND id = :userId");
        $stmt->bindValue(":userId", 1);
    ');

    assert_same(0, $notParameters['missing'], '::text and \'a:b\' are not unbound placeholders');
    assert_same(0, $notParameters['stray'], 'and :userId matches');
});

wallos_test('a branch-dependent query is judged against the union of its branches', function () {
    // set_currencies.php and save_user.php: the query is written one way in an if
    // and another in the else, and a parameter present in only one branch is
    // bound under the same condition. Reading only the last branch would call the
    // conditional bind stray; the union sees it, and the branch it cannot follow
    // stands the missing-bind check down.
    $branched = bind_audit_kinds('
        if ($withRate) {
            $sql = "UPDATE currencies SET name = :name, rate = :rate WHERE id = :id";
        } else {
            $sql = "UPDATE currencies SET name = :name WHERE id = :id";
        }
        $stmt = $db->prepare($sql);
        $stmt->bindValue(":name", $name);
        if ($withRate) {
            $stmt->bindValue(":rate", $rate);
        }
        $stmt->bindValue(":id", $id);
    ');

    assert_same(0, $branched['stray'], ':rate belongs to the branch that declares it');
    assert_same(0, $branched['missing'], 'and the branch decides what must be bound');
});

wallos_test('a handle reused across mutually exclusive prepares is not misread', function () {
    // cron_run.php: two prepares on one $stmt in an if/else, then one bind block
    // that satisfies whichever ran. Attaching every bind to the last prepare —
    // the naive segmentation — reported the first prepare wholly unbound and the
    // extra binds stray. The union of the handle's prepares dissolves both.
    $reused = bind_audit_kinds('
        if ($full) {
            $stmt = $db->prepare("INSERT INTO t (a, b) VALUES (:a, :b)");
        } else {
            $stmt = $db->prepare("INSERT INTO t (a) VALUES (:a)");
        }
        $stmt->bindValue(":a", $a);
        if ($full) {
            $stmt->bindValue(":b", $b);
        }
        $stmt->execute();
    ');

    assert_same(0, $reused['stray'], 'the extra bind matches the branch that declares it');
    assert_same(0, $reused['missing'], 'and neither prepare is judged in isolation');
});

wallos_test('placeholders and binds built together in a loop are not flagged', function () {
    // The IN-list and column-map shape: the same loop writes ":$k" into the SQL
    // and binds ":" . $k. Neither set is statically enumerable, so the scanner
    // counts the statement as dynamic and does not guess.
    $loop = bind_audit_kinds('
        $fields = [];
        foreach ($cols as $k => $v) {
            $fields[] = "$k = :$k";
            $params[$k] = $v;
        }
        $sql = "UPDATE t SET " . implode(", ", $fields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":" . $k, $v);
        }
        $stmt->bindValue(":id", $id);
    ');

    assert_same(0, $loop['stray'], 'a query assembled from variables has placeholders this cannot see');
    assert_same(0, $loop['missing'], 'and binds built from variables cannot be enumerated');
});

wallos_test('a bindValue in a comment or a string is not a bind', function () {
    // What a text search gets wrong, and the reason this is tokenised: the
    // scanner must describe the shape it looks for without matching its own prose.
    $prose = bind_audit_kinds('
        // $stmt->bindValue(":ghost", 1);
        /** binds :ghost, allegedly */
        $sql = "SELECT * FROM admin";
        $stmt = $db->prepare($sql);
        $note = \'$stmt->bindValue(":ghost", 1)\';
    ');

    assert_same(0, $prose['stray'], 'a commented or quoted bind is not a call');
    assert_same(0, $prose['missing'], 'and nothing real was left unbound');
});

wallos_test('the committed baseline matches this working tree', function () {
    // The intended state is empty: the real bugs are fixed, not recorded. This
    // fails the moment a mismatch appears that the baseline does not already
    // account for — which is what makes it a gate rather than a report.
    $measured = bind_audit_measure(WALLOS_ROOT);
    $baseline = bind_audit_read_baseline(WALLOS_ROOT . '/dev/bind-audit-baseline.txt');

    $comparison = bind_audit_compare($measured, $baseline);

    foreach ($comparison['regressions'] as $regression) {
        wallos_test_fail($regression);
    }

    assert_same([], $comparison['regressions'],
        'no statement gained a bind/placeholder mismatch');

    // An improvement left unrecorded means the next regression is measured
    // against a number that is no longer true.
    assert_same([], $comparison['improvements'],
        'run dev/bind-audit.php --update and commit the diff');
});

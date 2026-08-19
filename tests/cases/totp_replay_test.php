<?php
/*
  Two-factor verification: replay rejection and credential consumption.

  The replay guard shipped as dead code. totp.php compared the submitted code's
  time-step against $row['last_totp_used'] — a column its SELECT never asked
  for. The value was therefore always null, the comparison always ran against 0,
  and since a time-step is a number in the tens of millions, no code was ever
  rejected as reused. A captured code stayed valid for the whole leeway window.

  Nothing failed and nothing was logged, which is why it survived: the feature
  looked present in the code and in the comments, and only the SELECT list knew
  otherwise. So the first case here asserts the column arrives, and the rest
  assert what is done with it.
*/

require_once WALLOS_ROOT . '/includes/totp_state.php';

/**
 * @param  WallosDatabase $db
 * @param  int            $userId
 * @param  array          $columns
 */
function totp_enrol($db, $userId, $columns = [])
{
    $columns = array_merge([
        'totp_secret' => 'JBSWY3DPEHPK3PXP',
        'backup_codes' => json_encode(['aaa-111', 'bbb-222']),
        'failed_attempts' => 0,
        'lockout_until' => 0,
        'last_totp_used' => 0,
    ], $columns);

    $stmt = $db->prepare('INSERT INTO totp (user_id, totp_secret, backup_codes, failed_attempts,
                          lockout_until, last_totp_used)
                          VALUES (:id, :secret, :codes, :attempts, :lockout, :last)');
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':secret', $columns['totp_secret'], SQLITE3_TEXT);
    $stmt->bindValue(':codes', $columns['backup_codes'], SQLITE3_TEXT);
    $stmt->bindValue(':attempts', $columns['failed_attempts'], SQLITE3_INTEGER);
    $stmt->bindValue(':lockout', $columns['lockout_until'], SQLITE3_INTEGER);
    $stmt->bindValue(':last', $columns['last_totp_used'], SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * A stand-in for the OTPHP object, so these cases do not depend on real time.
 */
class TotpStub
{
    /** @var array timestamp => code */
    private $codes;

    public function __construct($codes)
    {
        $this->codes = $codes;
    }

    public function at($timestamp)
    {
        return $this->codes[$timestamp] ?? 'nomatch';
    }
}

// ------------------------------------------------------------ the state read

wallos_test('the replay column is actually read', function () {
    // The whole defect in one assertion. Remove last_totp_used from the SELECT
    // in wallos_totp_load_state() and this fails; before the fix, nothing did.
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId, ['last_totp_used' => 58000123]);

    $state = wallos_totp_load_state($db, $userId);

    assert_true(array_key_exists('last_totp_used', $state),
        'the column the replay check depends on is present');
    assert_same(58000123, (int) $state['last_totp_used'], 'with its stored value');

    $db->close();
});

wallos_test('an account with no enrolment reads as nothing', function () {
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');

    assert_true(wallos_totp_load_state($db, $userId) === null, 'no row, no state');
    assert_true(wallos_totp_load_state($db, 0) === null, 'no user either');

    $db->close();
});

// --------------------------------------------------------- the replay verdict

wallos_test('a code from a spent step is a replay', function () {
    assert_true(wallos_totp_step_is_replay(58000100, 58000100), 'the same step');
    assert_true(wallos_totp_step_is_replay(58000099, 58000100), 'an older step');
});

wallos_test('a code from a later step is not a replay', function () {
    assert_true(!wallos_totp_step_is_replay(58000101, 58000100), 'the next step');
});

wallos_test('a real step is never a replay against an unenrolled zero', function () {
    // This is what the broken version compared against forever. Stated as a
    // case so the shape of the original defect stays visible: against 0, every
    // genuine step passes, which is why nothing ever looked wrong.
    assert_true(!wallos_totp_step_is_replay(58000100, 0), 'passes, as it always did');
});

// ----------------------------------------------------------- legacy timestamps

wallos_test('a legacy unix timestamp is converted, not trusted', function () {
    // Older installs stored time() here. Compared directly it exceeds every
    // current step, so every code would be rejected and the account locked out
    // of its own second factor.
    $now = 1755000000;
    $currentStep = intdiv($now, 30);

    $converted = wallos_totp_last_used_step($now, $currentStep);

    assert_same($currentStep, $converted, 'read as the step it represents');
    assert_true(!wallos_totp_step_is_replay($currentStep + 1, $converted),
        'so the next code still works');
});

wallos_test('a value already in steps is left alone', function () {
    $currentStep = intdiv(1755000000, 30);

    assert_same($currentStep - 2, wallos_totp_last_used_step($currentStep - 2, $currentStep), 'unchanged');
    assert_same(0, wallos_totp_last_used_step(null, $currentStep), 'and null is zero');
});

// -------------------------------------------------------------- step matching

wallos_test('a code is matched to the step it belongs to', function () {
    $now = 1755000000;
    $totp = new TotpStub([$now => '123456']);

    assert_same(intdiv($now, 30), wallos_totp_matched_step($totp, '123456', $now), 'the current step');
    assert_true(wallos_totp_matched_step($totp, '999999', $now) === null, 'a wrong code matches nothing');
});

wallos_test('the leeway window is searched, and reports the step it matched', function () {
    // The recorded step has to be the one that was accepted, not the current
    // one — otherwise a code accepted from the previous step is recorded as the
    // current step, and the genuine next code is rejected as a replay.
    $now = 1755000000;
    $totp = new TotpStub([$now - 15 => '111111', $now + 15 => '222222']);

    assert_same(intdiv($now - 15, 30), wallos_totp_matched_step($totp, '111111', $now), 'the earlier step');
    assert_same(intdiv($now + 15, 30), wallos_totp_matched_step($totp, '222222', $now), 'the later step');
});

// ------------------------------------------------------ consuming a time-step

wallos_test('a consumed step is stored, and rejects the same code afterwards', function () {
    // End to end: verify, consume, verify again.
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);

    $now = 1755000000;
    $totp = new TotpStub([$now => '123456']);

    $first = wallos_totp_matched_step($totp, '123456', $now);
    $state = wallos_totp_load_state($db, $userId);
    $lastUsed = wallos_totp_last_used_step($state['last_totp_used'], intdiv($now, 30));
    assert_true(!wallos_totp_step_is_replay($first, $lastUsed), 'accepted the first time');

    assert_true(wallos_totp_consume_step($db, $userId, $first), 'and the step is recorded');

    $state = wallos_totp_load_state($db, $userId);
    $lastUsed = wallos_totp_last_used_step($state['last_totp_used'], intdiv($now, 30));
    $second = wallos_totp_matched_step($totp, '123456', $now);
    assert_true(wallos_totp_step_is_replay($second, $lastUsed), 'and refused the second time');

    $db->close();
});

// --------------------------------------------------------------- backup codes

wallos_test('a backup code is spent exactly once', function () {
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);

    $codes = ['aaa-111', 'bbb-222'];

    assert_true(wallos_totp_consume_backup_code($db, $userId, $codes, 'aaa-111'), 'accepted');

    $state = wallos_totp_load_state($db, $userId);
    $remaining = json_decode($state['backup_codes'], true);

    assert_same(['bbb-222'], $remaining, 'and struck off');
    assert_true(!wallos_totp_consume_backup_code($db, $userId, $remaining, 'aaa-111'),
        'so it is refused the second time');

    $db->close();
});

wallos_test('a code that was never issued is refused', function () {
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);

    assert_true(!wallos_totp_consume_backup_code($db, $userId, ['aaa-111'], 'zzz-999'), 'not ours');
    assert_true(!wallos_totp_consume_backup_code($db, $userId, [], 'aaa-111'), 'none left');
    assert_true(!wallos_totp_consume_backup_code($db, $userId, null, 'aaa-111'), 'nothing stored');

    $db->close();
});

wallos_test('a backup code is not honoured when it could not be struck off', function () {
    // The reason these functions return bool rather than void. If the row
    // cannot be written the code has not been used up, and honouring it anyway
    // turns a one-time code into a permanent password.
    //
    // The write is made to fail for real rather than simulated: a trigger that
    // aborts the UPDATE is the only way to prove the return value is consulted.
    // An earlier version of this case asserted in both branches of an if, which
    // meant it passed whatever the function did — it did not notice when the
    // check was removed.
    if (wallos_test_skip_unless_sqlite('needs a RAISE(ABORT) trigger')) {
        return;
    }

    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);

    $db->exec('CREATE TRIGGER block_backup_write BEFORE UPDATE OF backup_codes ON totp
               BEGIN SELECT RAISE(ABORT, \'blocked\'); END');

    $accepted = @wallos_totp_consume_backup_code($db, $userId, ['aaa-111', 'bbb-222'], 'aaa-111');

    assert_true($accepted === false, 'a code that could not be struck off is not accepted');

    $db->exec('DROP TRIGGER block_backup_write');
    $state = wallos_totp_load_state($db, $userId);
    assert_same(['aaa-111', 'bbb-222'], json_decode($state['backup_codes'], true),
        'and it is still on the account, unspent');

    $db->close();
});

wallos_test('a step that could not be recorded is reported', function () {
    // The caller lets the login through in this case — the credential was
    // genuine — but it has to know, because the replay window is then
    // unguarded and that is worth a log line.
    if (wallos_test_skip_unless_sqlite('needs a RAISE(ABORT) trigger')) {
        return;
    }

    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);

    $db->exec('CREATE TRIGGER block_step_write BEFORE UPDATE OF last_totp_used ON totp
               BEGIN SELECT RAISE(ABORT, \'blocked\'); END');

    assert_true(@wallos_totp_consume_step($db, $userId, 58000100) === false, 'reported as not stored');

    $db->exec('DROP TRIGGER block_step_write');
    $db->close();
});

wallos_test('a failed attempt that could not be counted is reported', function () {
    // Same shape, and the one with the quietest consequence: brute-force
    // protection stops working with nothing visible to anyone.
    if (wallos_test_skip_unless_sqlite('needs a RAISE(ABORT) trigger')) {
        return;
    }

    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);

    $db->exec('CREATE TRIGGER block_counter_write BEFORE UPDATE OF failed_attempts ON totp
               BEGIN SELECT RAISE(ABORT, \'blocked\'); END');

    $result = @wallos_totp_record_failure($db, $userId, 2, 5, 30);
    assert_true($result['stored'] === false, 'the caller is told the count did not move');

    $db->exec('DROP TRIGGER block_counter_write');
    $db->close();
});

// ------------------------------------------------------- brute-force counters

wallos_test('failures are counted, and trip a lockout', function () {
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);

    $result = wallos_totp_record_failure($db, $userId, 3, 5, 30);
    assert_true(!$result['locked'], 'below the threshold');
    assert_true($result['stored'], 'and recorded');
    assert_same(3, (int) wallos_totp_load_state($db, $userId)['failed_attempts'], 'the count is kept');

    $result = wallos_totp_record_failure($db, $userId, 5, 5, 30);
    assert_true($result['locked'], 'at the threshold the account locks');
    assert_true($result['stored'], 'and that is recorded too');

    $state = wallos_totp_load_state($db, $userId);
    assert_same(0, (int) $state['failed_attempts'], 'the counter restarts');
    assert_true((int) $state['lockout_until'] > time(), 'and the lockout is in the future');

    $db->close();
});

wallos_test('a successful verification clears the counter', function () {
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId, ['failed_attempts' => 4, 'lockout_until' => time() + 30]);

    assert_true(wallos_totp_reset_attempts($db, $userId), 'cleared');

    $state = wallos_totp_load_state($db, $userId);
    assert_same(0, (int) $state['failed_attempts'], 'no failures');
    assert_same(0, (int) $state['lockout_until'], 'and no lockout');

    $db->close();
});

// ------------------------------------------------------------- the call sites

wallos_test('totp.php verifies through these functions', function () {
    // As calls, not as text: a require_once or a comment naming the function
    // satisfied an earlier version of this check while the page still had its
    // own inline copy.
    foreach ([
        'wallos_totp_load_state',
        'wallos_totp_matched_step',
        'wallos_totp_step_is_replay',
        'wallos_totp_consume_step',
        'wallos_totp_consume_backup_code',
        'wallos_totp_record_failure',
    ] as $function) {
        assert_true(wallos_test_file_calls('totp.php', $function), 'totp.php calls ' . $function);
    }
});

wallos_test('the page no longer builds these statements itself', function () {
    // A second inline copy would drift from the tested one, which is how the
    // original defect stayed invisible.
    $source = file_get_contents(WALLOS_ROOT . '/totp.php');

    assert_true(strpos($source, 'SET last_totp_used') === false, 'no inline step write');
    assert_true(strpos($source, 'SET backup_codes') === false, 'no inline backup-code write');
    assert_true(strpos($source, 'SET failed_attempts') === false, 'no inline counter write');
});

// ------------------------------------------------------------- disabling 2FA

wallos_test('disabling clears both the flag and the enrolment', function () {
    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);
    $db->exec('UPDATE "user" SET totp_enabled = 1 WHERE id = ' . $userId);

    assert_true(wallos_totp_disable($db, $userId), 'reported as done');

    assert_same(0, (int) $db->scalar('SELECT totp_enabled FROM "user" WHERE id = ' . $userId),
        'the flag is cleared');
    assert_true(wallos_totp_load_state($db, $userId) === null, 'and the enrolment is gone');

    $db->close();
});

wallos_test('a half-completed disable leaves nothing behind', function () {
    // The state this prevents: totp_enabled still set with no enrolment row.
    // login.php sends such an account to totp.php, which finds no secret and no
    // backup codes — no credential in existence can get in. Both call sites
    // reported success unconditionally, so the user was told 2FA was off.
    if (wallos_test_skip_unless_sqlite('needs a RAISE(ABORT) trigger')) {
        return;
    }

    $db = wallos_test_open_database();
    $userId = 1;
    wallos_test_create_user($db, $userId, 'alice');
    totp_enrol($db, $userId);
    $db->exec('UPDATE "user" SET totp_enabled = 1 WHERE id = ' . $userId);

    // Let the flag clear, then block the enrolment delete.
    $db->exec('CREATE TRIGGER block_totp_delete BEFORE DELETE ON totp
               BEGIN SELECT RAISE(ABORT, \'blocked\'); END');

    assert_true(@wallos_totp_disable($db, $userId) === false, 'reported as failed');

    $db->exec('DROP TRIGGER block_totp_delete');

    assert_same(1, (int) $db->scalar('SELECT totp_enabled FROM "user" WHERE id = ' . $userId),
        'the flag was rolled back with it');
    assert_true(wallos_totp_load_state($db, $userId) !== null,
        'so the account still has a credential that works');

    $db->close();
});

wallos_test('the endpoint disables through the checked path', function () {
    assert_true(wallos_test_file_calls('endpoints/user/disable_totp.php', 'wallos_totp_disable'),
        'the endpoint calls it');

    $source = file_get_contents(WALLOS_ROOT . '/endpoints/user/disable_totp.php');
    assert_true(strpos($source, 'DELETE FROM totp') === false,
        'and has no inline copy of its own — there were two, and both were unchecked');
});

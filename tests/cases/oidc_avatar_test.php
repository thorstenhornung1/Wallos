<?php
/*
  Importing an OIDC provider's `picture` claim into a Wallos avatar.

  The picture is untrusted input (a hostile or misconfigured provider, or a user
  who hand-sets the attribute), so the cases below exercise both directions of
  the feature and the whole validation chain: a raster is decoded, stored and
  becomes the avatar on creation; an svg — the stored-XSS vector the raster
  allowlist exists to refuse — is skipped; an oversized payload, a URL and a
  magic-byte mismatch are all skipped without ever failing the login; and on a
  returning login the override policy is honoured — a user's own avatar is never
  clobbered, a changed provider picture is taken up, an unchanged one is not
  re-written.

  The helpers are called exactly as includes/oidc/oidc_create_user.php and
  includes/oidc/handle_oidc_callback.php call them; a base directory is injected
  so nothing is written into the working tree. The wiring case proves the two
  callback files actually call the helpers, so deleting a call fails a case.
*/

require_once WALLOS_ROOT . '/includes/oidc/oidc_avatar.php';

/**
 * Real 1x1 rasters, as `picture` claims arrive: base64 in a data-URI.
 *
 * @return array<string, string>
 */
function oidc_av_samples()
{
    return [
        'png' => 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAIAAAUAAen63NgAAAAASUVORK5CYII=',
        'jpg' => 'data:image/jpeg;base64,'
            . '/9j/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/'
            . 'wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AfwD/2Q==',
        'webp' => 'data:image/webp;base64,UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==',
    ];
}

/**
 * A throwaway target directory, so imported files never touch the working tree.
 *
 * @return string
 */
function oidc_av_tmpdir()
{
    $dir = WALLOS_TEST_TMP . '/oidc-avatar-' . uniqid('', true);
    mkdir($dir, 0700, true);

    return $dir;
}

/**
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $avatar
 */
function oidc_av_set_avatar($db, $userId, $avatar)
{
    $statement = $db->prepare('UPDATE "user" SET avatar = :avatar WHERE id = :id');
    $statement->bindValue(':avatar', $avatar);
    $statement->bindValue(':id', $userId);
    $statement->execute();
}

/**
 * @param WallosDatabase $db
 * @param int            $userId
 * @return string|null
 */
function oidc_av_get_avatar($db, $userId)
{
    return $db->scalar('SELECT avatar FROM "user" WHERE id = :id', [':id' => $userId]);
}

/**
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $path
 * @return int
 */
function oidc_av_row_count($db, $userId, $path)
{
    return (int) $db->scalar(
        'SELECT COUNT(*) FROM uploaded_avatars WHERE user_id = :userId AND path = :path',
        [':userId' => $userId, ':path' => $path]
    );
}

wallos_test('a raster picture is decoded, stored and becomes the avatar on creation', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'alice');
    $tmp = oidc_av_tmpdir();
    $sub = 'sub-alice';
    $samples = oidc_av_samples();

    // The three decisions oidc_create_user.php makes, through the same helpers.
    $decoded = wallos_oidc_decode_picture($samples['jpg']);
    assert_true($decoded !== null, 'a jpeg data-URI decodes');
    assert_same('jpg', $decoded['ext'], 'and is recognised as a jpeg');

    $stored = wallos_oidc_write_avatar($sub, $decoded, $tmp);
    assert_true($stored !== null, 'the decoded image is written');
    assert_true(strpos(basename((string) $stored), 'oidc-') === 0, 'the stored avatar is marked oidc-origin');
    assert_true(is_file($tmp . '/' . basename((string) $stored)), 'the file is on disk');

    // The create path sets user.avatar to the stored path and records ownership.
    oidc_av_set_avatar($db, 1, $stored);
    assert_true(wallos_oidc_register_avatar($db, 1, $stored), 'ownership is recorded');

    assert_same($stored, oidc_av_get_avatar($db, 1), 'the account avatar is the imported picture');
    assert_same(1, oidc_av_row_count($db, 1, $stored), 'the uploaded_avatars row exists');

    $db->close();
});

wallos_test('the callback files wire the avatar import helpers', function () {
    assert_true(wallos_test_file_calls('includes/oidc/oidc_create_user.php', 'wallos_oidc_decode_picture'),
        'oidc_create_user.php decodes the picture claim');
    assert_true(wallos_test_file_calls('includes/oidc/oidc_create_user.php', 'wallos_oidc_write_avatar'),
        'oidc_create_user.php stores the decoded avatar');
    assert_true(wallos_test_file_calls('includes/oidc/oidc_create_user.php', 'wallos_oidc_register_avatar'),
        'oidc_create_user.php records ownership of the imported avatar');
    assert_true(wallos_test_file_calls('includes/oidc/handle_oidc_callback.php', 'wallos_oidc_maybe_update_avatar'),
        'the returning-login path updates the avatar from the picture');
});

wallos_test('an svg picture is refused and the default avatar stays', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'bob');
    $tmp = oidc_av_tmpdir();
    oidc_av_set_avatar($db, 1, WALLOS_OIDC_AVATAR_DEFAULT);

    // svg is refused by the raster allowlist regardless of its content — which is
    // exactly the point: an svg avatar served as <img> is a stored-XSS vector.
    $svg = 'data:image/svg+xml;base64,'
        . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    assert_true(wallos_oidc_decode_picture($svg) === null, 'an svg data-URI is not decoded');

    $changed = wallos_oidc_maybe_update_avatar(
        $db, ['id' => 1, 'avatar' => WALLOS_OIDC_AVATAR_DEFAULT], $svg, 'sub-bob', $tmp);
    assert_true($changed === false, 'no avatar change for an svg');
    assert_same(WALLOS_OIDC_AVATAR_DEFAULT, oidc_av_get_avatar($db, 1), 'the account keeps the default avatar');

    $db->close();
});

wallos_test('an oversized picture is skipped and the login still succeeds', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'carol');
    $tmp = oidc_av_tmpdir();
    oidc_av_set_avatar($db, 1, WALLOS_OIDC_AVATAR_DEFAULT);

    // A genuinely valid jpeg inflated past the base64 ceiling, so the size guard
    // is the only thing that rejects it — removing that guard would let it in.
    $samples = oidc_av_samples();
    $rawJpeg = base64_decode(substr($samples['jpg'], strlen('data:image/jpeg;base64,')), true);
    $oversized = 'data:image/jpeg;base64,' . base64_encode($rawJpeg . str_repeat("\x00", 250000));
    assert_true(strlen($oversized) > WALLOS_OIDC_AVATAR_MAX_BASE64, 'the payload really is over the ceiling');
    assert_true(wallos_oidc_decode_picture($oversized) === null, 'an oversized payload is not decoded');

    $changed = wallos_oidc_maybe_update_avatar(
        $db, ['id' => 1, 'avatar' => WALLOS_OIDC_AVATAR_DEFAULT], $oversized, 'sub-carol', $tmp);
    assert_true($changed === false, 'the avatar is left as it was');
    assert_same(WALLOS_OIDC_AVATAR_DEFAULT, oidc_av_get_avatar($db, 1), 'and the account keeps the default avatar');

    $db->close();
});

wallos_test('a non-data picture value (a URL) is skipped', function () {
    assert_true(wallos_oidc_decode_picture('https://idp.example.com/avatar/alice.jpg') === null,
        'an https URL is not fetched or stored');
    assert_true(wallos_oidc_decode_picture('') === null, 'an empty value is skipped');
    assert_true(wallos_oidc_decode_picture(null) === null, 'a missing claim is skipped');
});

wallos_test('a raster MIME with mismatched bytes is rejected', function () {
    $samples = oidc_av_samples();

    // jpeg bytes, but the data-URI declares png. The declared MIME is not trusted.
    $jpegBase64 = substr($samples['jpg'], strlen('data:image/jpeg;base64,'));
    $mislabelled = 'data:image/png;base64,' . $jpegBase64;
    assert_true(wallos_oidc_decode_picture($mislabelled) === null,
        'declared image/png but jpeg bytes is refused');

    // Bytes that are not an image at all, declared as a png.
    $notAnImage = 'data:image/png;base64,' . base64_encode('this is not really a png at all');
    assert_true(wallos_oidc_decode_picture($notAnImage) === null, 'non-image bytes are refused');
});

wallos_test('a manually uploaded avatar is never overwritten on returning login', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'dave');
    $tmp = oidc_av_tmpdir();
    $samples = oidc_av_samples();

    $manual = 'images/uploads/logos/avatars/1700000000-avatars-dave.png';
    oidc_av_set_avatar($db, 1, $manual);

    $changed = wallos_oidc_maybe_update_avatar(
        $db, ['id' => 1, 'avatar' => $manual], $samples['png'], 'sub-dave', $tmp);
    assert_true($changed === false, 'the import stands aside');
    assert_same($manual, oidc_av_get_avatar($db, 1), 'the account keeps the avatar the user uploaded');

    $db->close();
});

wallos_test('a changed provider picture is taken up on returning login', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'erin');
    $tmp = oidc_av_tmpdir();
    $sub = 'sub-erin';
    $samples = oidc_av_samples();

    // A previous OIDC import: png.
    $first = wallos_oidc_decode_picture($samples['png']);
    $firstPath = wallos_oidc_write_avatar($sub, $first, $tmp);
    oidc_av_set_avatar($db, 1, $firstPath);
    wallos_oidc_register_avatar($db, 1, $firstPath);

    // The provider now sends a different picture: webp.
    $changed = wallos_oidc_maybe_update_avatar(
        $db, ['id' => 1, 'avatar' => $firstPath], $samples['webp'], $sub, $tmp);
    assert_true($changed === true, 'a different picture replaces the imported avatar');

    $second = wallos_oidc_decode_picture($samples['webp']);
    $secondPath = wallos_oidc_avatar_relpath($sub, $second);
    assert_same($secondPath, oidc_av_get_avatar($db, 1), 'the account points at the new picture');
    assert_true(is_file($tmp . '/' . basename($secondPath)), 'the new file is on disk');
    assert_true(!is_file($tmp . '/' . basename((string) $firstPath)), 'the superseded file is cleaned up');
    assert_same(0, oidc_av_row_count($db, 1, $firstPath), 'and its ownership row is gone');

    $db->close();
});

wallos_test('an unchanged provider picture is not re-written on returning login', function () {
    $db = wallos_test_open_database();
    wallos_test_create_user($db, 1, 'fran');
    $tmp = oidc_av_tmpdir();
    $sub = 'sub-fran';
    $samples = oidc_av_samples();

    // The account already points at this exact picture (content-addressed name),
    // and the file is deliberately not written, so any write would be visible.
    $decoded = wallos_oidc_decode_picture($samples['jpg']);
    $current = wallos_oidc_avatar_relpath($sub, $decoded);
    oidc_av_set_avatar($db, 1, $current);

    $changed = wallos_oidc_maybe_update_avatar(
        $db, ['id' => 1, 'avatar' => $current], $samples['jpg'], $sub, $tmp);
    assert_true($changed === false, 'an identical picture is a no-op');
    assert_true(!is_file($tmp . '/' . basename($current)), 'nothing is written for an unchanged picture');
    assert_same($current, oidc_av_get_avatar($db, 1), 'the avatar is unchanged');

    $db->close();
});

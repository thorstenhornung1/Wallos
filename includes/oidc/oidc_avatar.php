<?php
/*
  Importing the profile picture an OIDC provider sends into a Wallos avatar.

  The picture is attacker-influenceable: a hostile or misconfigured identity
  provider, or a user who hand-sets their own `picture` attribute, controls
  every byte of it. So the value is treated as untrusted input and never trusted
  on its label alone. The validation chain, in order, is:

    1. It must be a `data:image/(jpeg|png|webp);base64,` URI. Anything else — an
       https:// URL, an empty value, image/svg+xml, image/gif — is refused. SVG
       is refused on purpose twice over: the provider generates SVG *initials*
       (importing them would replace Wallos's own default with the same idea),
       and an SVG stored and later served as an <img> avatar is a stored-XSS
       vector. One raster-MIME allowlist settles both.
    2. The base64 length is bounded BEFORE decoding, so a hostile 50 MB payload
       cannot be expanded in memory. Over the ceiling: skip, keep the avatar.
    3. The decoded bytes must actually be that raster type by magic bytes — the
       declared MIME is never trusted on its own, so a JPEG mislabelled as PNG is
       rejected — and getimagesizefromstring() must agree it parses as the same
       type. Only then are the bytes written to disk, with a raster extension.

  Nothing here ever renders or executes the bytes; they are written verbatim to
  a file the application serves as a static <img src>, which is safe for a raster
  and is exactly why svg is not allowed to reach this point. The image bytes and
  the data-URI are never logged — only the outcome, with the user id, the way the
  rest of the OIDC code logs.

  A malformed, oversized or hostile picture must never break the login: every
  failure degrades to "keep the current avatar" and returns null/false.

  --- Marking an imported avatar, and the returning-login override policy -------

  There is no schema change. An imported avatar is recognised by its filename:
  `oidc-<subject-hash>-<content-hash>.<ext>`, stored under the same
  images/uploads/logos/avatars/ directory and registered in the same
  uploaded_avatars table an ordinary upload uses. The name is content-addressed,
  so the same picture always yields the same filename (an unchanged picture is
  therefore never re-written), and a changed picture yields a new one (so it
  propagates). The subject hash keeps one account's file from colliding with
  another's.

  On a RETURNING login the avatar is updated only when it is both safe and
  useful — that is, only when the account's current avatar is the creation
  default (images/avatars/0.svg) OR one we imported before (the `oidc-` prefix),
  AND the decoded picture differs from what is already stored. A user who picked
  a built-in avatar or uploaded their own is never overwritten. This is the one
  real judgement call in the feature; it is stated here so it can be argued with.
*/

const WALLOS_OIDC_AVATAR_DEFAULT = 'images/avatars/0.svg';
const WALLOS_OIDC_AVATAR_RELDIR = 'images/uploads/logos/avatars/';

// The ceiling is on the base64 text, checked before any decode. The QA user's
// real picture is ~118 KB of JPEG (~158 KB base64); 256 KB of base64 leaves that
// comfortable room while still bounding the work a hostile payload can ask for.
const WALLOS_OIDC_AVATAR_MAX_BASE64 = 262144;

/**
 * Validates and decodes a `picture` claim value.
 *
 * Pure: no I/O, no logging, no database. Returns the decoded raster on success,
 * or null for every rejected input (not a data-URI, not an allowed raster MIME,
 * oversized, undecodable, or a magic-byte/parse mismatch).
 *
 * @param mixed $claim The raw `picture` claim from userinfo.
 * @return array{ext: string, mime: string, bytes: string, sha: string}|null
 */
function wallos_oidc_decode_picture($claim)
{
    if (!is_string($claim) || $claim === '') {
        return null;
    }

    // Anchored and short, so this only ever inspects the start of the string —
    // a huge claim is not scanned end to end here.
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#i', $claim, $matches)) {
        return null;
    }

    $declared = strtolower($matches[1]);
    $base64 = substr($claim, strpos($claim, ',') + 1);

    // Bound the work BEFORE decoding.
    if (strlen($base64) > WALLOS_OIDC_AVATAR_MAX_BASE64) {
        return null;
    }

    $bytes = base64_decode($base64, true);
    if ($bytes === false || $bytes === '') {
        return null;
    }

    // The declared MIME is not trusted: the bytes have to be that raster type.
    $actual = wallos_oidc_detect_raster($bytes);
    if ($actual === null || $actual !== $declared) {
        return null;
    }

    // A second, independent validator. getimagesizefromstring() is core PHP (it
    // does not need the GD extension) and refuses anything it cannot parse as the
    // image it claims to be.
    $size = @getimagesizefromstring($bytes);
    if ($size === false || !isset($size['mime'])
        || strtolower($size['mime']) !== 'image/' . $declared) {
        return null;
    }

    $extensions = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];

    return [
        'ext' => $extensions[$declared],
        'mime' => 'image/' . $declared,
        'bytes' => $bytes,
        'sha' => hash('sha256', $bytes),
    ];
}

/**
 * The raster type of a byte string by its magic bytes, or null if it is none of
 * the three allowed types.
 *
 * @param string $bytes
 * @return string|null 'jpeg', 'png' or 'webp'
 */
function wallos_oidc_detect_raster($bytes)
{
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpeg';
    }

    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'png';
    }

    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
        return 'webp';
    }

    return null;
}

/**
 * The content-addressed filename an imported avatar is stored under.
 *
 * @param string $oidcSub
 * @param array  $decoded Result of wallos_oidc_decode_picture().
 * @return string
 */
function wallos_oidc_avatar_filename($oidcSub, array $decoded)
{
    $subjectHash = substr(hash('sha256', (string) $oidcSub), 0, 16);
    $contentHash = substr($decoded['sha'], 0, 16);

    return 'oidc-' . $subjectHash . '-' . $contentHash . '.' . $decoded['ext'];
}

/**
 * The path an imported avatar is stored and served under (the value that goes
 * into user.avatar and uploaded_avatars.path).
 *
 * @param string $oidcSub
 * @param array  $decoded
 * @return string
 */
function wallos_oidc_avatar_relpath($oidcSub, array $decoded)
{
    return WALLOS_OIDC_AVATAR_RELDIR . wallos_oidc_avatar_filename($oidcSub, $decoded);
}

/**
 * The absolute avatar upload directory, when a caller does not supply one.
 *
 * @return string
 */
function wallos_oidc_avatar_root_dir()
{
    return dirname(__DIR__, 2) . '/' . WALLOS_OIDC_AVATAR_RELDIR;
}

/**
 * Whether a stored avatar path is one this code imported.
 *
 * @param mixed $path
 * @return bool
 */
function wallos_oidc_avatar_is_oidc($path)
{
    if (!is_string($path) || $path === '') {
        return false;
    }

    return strpos($path, WALLOS_OIDC_AVATAR_RELDIR) === 0
        && strpos(basename($path), 'oidc-') === 0;
}

/**
 * Whether the account's current avatar may be replaced by an imported one: the
 * creation default, or an avatar this code imported before. Never a built-in the
 * user chose, never an avatar the user uploaded.
 *
 * @param mixed $path
 * @return bool
 */
function wallos_oidc_avatar_is_replaceable($path)
{
    return $path === WALLOS_OIDC_AVATAR_DEFAULT || wallos_oidc_avatar_is_oidc($path);
}

/**
 * Writes decoded avatar bytes to disk and returns the stored path, or null on
 * any failure (the caller then keeps the existing avatar).
 *
 * @param string      $oidcSub
 * @param array       $decoded
 * @param string|null $baseDir Absolute target directory; defaults to the web
 *                             root's avatar directory. Injected by tests.
 * @return string|null The images/uploads/logos/avatars/<file> path, or null.
 */
function wallos_oidc_write_avatar($oidcSub, array $decoded, $baseDir = null)
{
    $directory = $baseDir !== null ? rtrim($baseDir, '/') . '/' : wallos_oidc_avatar_root_dir();

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return null;
    }

    $filename = wallos_oidc_avatar_filename($oidcSub, $decoded);
    $written = file_put_contents($directory . $filename, $decoded['bytes'], LOCK_EX);

    // The write result is read: a short write is a failed write.
    if ($written === false || $written !== strlen($decoded['bytes'])) {
        return null;
    }

    return WALLOS_OIDC_AVATAR_RELDIR . $filename;
}

/**
 * Records ownership of an imported avatar in uploaded_avatars, the same row an
 * ordinary upload registers (so the avatar picker offers it and deletion stays
 * scoped to the owner). Idempotent: an existing row for the same path is left
 * as it is.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $relpath
 * @return bool
 */
function wallos_oidc_register_avatar($db, $userId, $relpath)
{
    $existing = $db->scalar(
        'SELECT id FROM uploaded_avatars WHERE user_id = :userId AND path = :path',
        [':userId' => $userId, ':path' => $relpath]
    );
    if ($existing !== null) {
        return true;
    }

    $statement = $db->prepare('INSERT INTO uploaded_avatars (user_id, path) VALUES (:userId, :path)');
    if ($statement === false) {
        error_log('[Wallos OIDC] could not prepare the avatar ownership row for user ' . $userId);

        return false;
    }

    $statement->bindValue(':userId', $userId);
    $statement->bindValue(':path', $relpath);

    if ($statement->execute() === false) {
        error_log('[Wallos OIDC] could not record the imported avatar for user ' . $userId);

        return false;
    }

    return true;
}

/**
 * Removes an avatar this code imported before — its file and its ownership row —
 * when a newer import supersedes it. Only ever touches an `oidc-` file inside the
 * avatar directory; a failure is logged and never propagated.
 *
 * @param WallosDatabase $db
 * @param int            $userId
 * @param string         $relpath
 * @param string|null    $baseDir
 * @return void
 */
function wallos_oidc_forget_avatar($db, $userId, $relpath, $baseDir = null)
{
    if (!wallos_oidc_avatar_is_oidc($relpath)) {
        return;
    }

    $directory = $baseDir !== null ? rtrim($baseDir, '/') . '/' : wallos_oidc_avatar_root_dir();
    $resolved = realpath($directory . basename($relpath));
    $resolvedDir = realpath($directory);

    if ($resolved !== false && $resolvedDir !== false
        && strpos($resolved, $resolvedDir) === 0 && is_file($resolved)) {
        @unlink($resolved);
    }

    $statement = $db->prepare('DELETE FROM uploaded_avatars WHERE user_id = :userId AND path = :path');
    if ($statement === false) {
        error_log('[Wallos OIDC] could not prepare cleanup of the superseded avatar for user ' . $userId);

        return;
    }

    $statement->bindValue(':userId', $userId);
    $statement->bindValue(':path', $relpath);

    if ($statement->execute() === false) {
        error_log('[Wallos OIDC] could not remove the superseded avatar row for user ' . $userId);
    }
}

/**
 * The returning-login policy: update the account's avatar from the provider
 * picture only when it is safe and useful. See the file header for the rule.
 *
 * Never throws for a bad picture and never fails the login — the worst outcome
 * is "the avatar is left as it was".
 *
 * @param WallosDatabase $db
 * @param array          $userData     The account row (needs id and avatar).
 * @param mixed          $pictureClaim The raw `picture` claim, or null.
 * @param string         $oidcSub
 * @param string|null    $baseDir      Injected by tests.
 * @return bool Whether the avatar was changed.
 */
function wallos_oidc_maybe_update_avatar($db, array $userData, $pictureClaim, $oidcSub, $baseDir = null)
{
    $userId = (int) $userData['id'];
    $current = isset($userData['avatar']) ? (string) $userData['avatar'] : '';

    $decoded = wallos_oidc_decode_picture($pictureClaim);
    if ($decoded === null) {
        // Only worth a line when the provider actually sent something we then
        // refused; the common no-picture case is silent.
        if (is_string($pictureClaim) && $pictureClaim !== '') {
            error_log('[Wallos OIDC] profile picture skipped for user ' . $userId
                . ' (not an importable raster data-URI)');
        }

        return false;
    }

    if (!wallos_oidc_avatar_is_replaceable($current)) {
        error_log('[Wallos OIDC] profile picture skipped for user ' . $userId
            . ' (account has its own avatar)');

        return false;
    }

    $target = wallos_oidc_avatar_relpath($oidcSub, $decoded);
    if (basename($current) === basename($target)) {
        // Content-addressed: an identical picture is the same file, so there is
        // nothing to write and the account already points at it.
        return false;
    }

    $stored = wallos_oidc_write_avatar($oidcSub, $decoded, $baseDir);
    if ($stored === null) {
        error_log('[Wallos OIDC] profile picture import failed for user ' . $userId
            . ' (could not store the decoded image)');

        return false;
    }

    $statement = $db->prepare('UPDATE "user" SET avatar = :avatar WHERE id = :userId');
    if ($statement === false) {
        error_log('[Wallos OIDC] could not prepare the avatar update for user ' . $userId);

        return false;
    }

    $statement->bindValue(':avatar', $stored);
    $statement->bindValue(':userId', $userId);

    if ($statement->execute() === false) {
        error_log('[Wallos OIDC] could not update the avatar for user ' . $userId);

        return false;
    }

    if (!wallos_oidc_register_avatar($db, $userId, $stored)) {
        // The file exists and the account points at it; a missing ownership row
        // only keeps it out of the picker, so the login still succeeds.
        error_log('[Wallos OIDC] imported the avatar for user ' . $userId
            . ' but could not record ownership');
    }

    if (wallos_oidc_avatar_is_oidc($current) && $current !== $stored) {
        wallos_oidc_forget_avatar($db, $userId, $current, $baseDir);
    }

    error_log('[Wallos OIDC] profile picture imported for user ' . $userId . ' (' . $decoded['ext'] . ')');

    return true;
}

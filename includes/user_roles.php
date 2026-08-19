<?php

/**
 * Application roles.
 *
 * Wallos owns authorization. An identity provider says who someone is, and may
 * supply a claim, but whether that person administers this installation is a
 * decision recorded here.
 *
 * A role is (user, role, source). Today the only role is `admin` and the only
 * sources are `local` and `oidc`. The source matters: an OIDC synchronisation
 * rewrites the `oidc` rows on every login, and must never be able to remove the
 * local administrator whose account is the way back in when the provider is
 * misconfigured.
 */

define('WALLOS_ROLE_ADMIN', 'admin');
define('WALLOS_ROLE_SOURCE_LOCAL', 'local');
define('WALLOS_ROLE_SOURCE_OIDC', 'oidc');

/**
 * Whether a user administers this installation.
 *
 * True if an admin role exists from any source — a local grant and an OIDC
 * claim are equally valid, and holding both is not different from holding one.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool
 */
function wallos_user_is_admin($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    $stmt = $db->prepare("SELECT 1 FROM user_roles WHERE user_id = :userId AND role = :role LIMIT 1");
    if ($stmt === false) {
        // The table is missing, which means the migration has not run. Refusing
        // is the safe answer: granting admin because a lookup failed is how an
        // authorization bug becomes an authorization hole.
        return false;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':role', WALLOS_ROLE_ADMIN, SQLITE3_TEXT);
    $result = $stmt->execute();

    return $result !== false && $result->fetchArray(SQLITE3_ASSOC) !== false;
}

/**
 * Grant a role. Doing it twice changes nothing.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $role
 * @param string  $source
 * @return bool
 */
function wallos_grant_role($db, $userId, $role, $source)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    // ON CONFLICT DO NOTHING rather than INSERT OR IGNORE: the UNIQUE
    // (user_id, role, source) constraint is what makes a repeated grant a
    // no-op, and both databases spell that this way.
    $stmt = $db->prepare("INSERT INTO user_roles (user_id, role, source) VALUES (:userId, :role, :source) ON CONFLICT DO NOTHING");
    if ($stmt === false) {
        return false;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
    $stmt->bindValue(':source', $source, SQLITE3_TEXT);

    return $stmt->execute() !== false;
}

/**
 * Revoke a role from one source only.
 *
 * Always scoped by source. Revoking "admin" without saying where it came from
 * is what would let a provider that stopped sending a claim log out the local
 * administrator too.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param string  $role
 * @param string  $source
 * @return int rows removed
 */
function wallos_revoke_role($db, $userId, $role, $source)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = :userId AND role = :role AND source = :source");
    if ($stmt === false) {
        return 0;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':role', $role, SQLITE3_TEXT);
    $stmt->bindValue(':source', $source, SQLITE3_TEXT);

    // As in session_tokens.php: changes() survives a failed statement on both
    // backends, so a revocation that did not run would report the previous
    // statement's row count as its own.
    if ($stmt->execute() === false) {
        return 0;
    }

    return $db->changes();
}

/**
 * Which sources give this user the admin role.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return string[]
 */
function wallos_user_admin_sources($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return [];
    }

    $stmt = $db->prepare("SELECT source FROM user_roles WHERE user_id = :userId AND role = :role ORDER BY source");
    if ($stmt === false) {
        return [];
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':role', WALLOS_ROLE_ADMIN, SQLITE3_TEXT);
    $result = $stmt->execute();

    $sources = [];
    if ($result !== false) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sources[] = $row['source'];
        }
    }

    return $sources;
}

/**
 * How many distinct users administer this installation.
 *
 * Counts users, not rows — someone holding both a local and an OIDC admin role
 * is still one administrator, and counting rows would make it look safe to
 * delete the only one.
 *
 * @param SQLite3 $db
 * @return int
 */
function wallos_count_admins($db)
{
    $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) AS total FROM user_roles WHERE role = :role");
    if ($stmt === false) {
        return 0;
    }
    $stmt->bindValue(':role', WALLOS_ROLE_ADMIN, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result === false) {
        return 0;
    }
    $row = $result->fetchArray(SQLITE3_ASSOC);

    return $row ? (int) $row['total'] : 0;
}

/**
 * Whether removing this user's admin role would leave nobody in charge.
 *
 * Replaces the old rule that user 1 could not be deleted. What actually needs
 * protecting is not a particular id, it is the existence of a way into the
 * administration area.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool
 */
function wallos_is_last_admin($db, $userId)
{
    return wallos_user_is_admin($db, $userId) && wallos_count_admins($db) <= 1;
}

/**
 * Give the first account of a fresh installation the administrator role.
 *
 * Someone has to be able to reach the administration area on a new install, and
 * on a database with no roles at all there is nobody to grant it. This closes
 * that gap once, for the account being created, and only while no administrator
 * exists yet.
 *
 * Call it from local registration. Deliberately NOT from OIDC provisioning: an
 * account created by whoever happens to authenticate first must not inherit the
 * installation. That is the failure the role table exists to prevent.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @return bool whether the role was granted
 */
function wallos_claim_first_admin($db, $userId)
{
    if (wallos_count_admins($db) > 0) {
        return false;
    }

    return wallos_grant_role($db, $userId, WALLOS_ROLE_ADMIN, WALLOS_ROLE_SOURCE_LOCAL);
}

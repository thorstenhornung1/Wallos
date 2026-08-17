<?php
// Makes sure an upgraded installation still has a reachable administrator.
//
// Migration 000058 gives the admin role to user 1, because that is who the old
// `id == 1` rule made an administrator. But an installation whose first account
// was deleted has no user 1 — the ids that follow are never reused — and then
// nobody gets the role and the admin area becomes unreachable.
//
// That installation was already in a broken state before the upgrade: under the
// old rule nobody could administer it either. The upgrade is simply the moment
// the problem can be fixed, and the oldest surviving account is the closest
// thing to "whoever set this up".
//
// Only runs when there is no administrator at all, so it can never take the
// role away from anyone or hand out a second one.

$adminCount = 0;
$result = $db->query("SELECT COUNT(DISTINCT user_id) AS total FROM user_roles WHERE role = 'admin'");
if ($result !== false) {
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $adminCount = $row ? (int) $row['total'] : 0;
}

if ($adminCount === 0) {
    $oldest = $db->querySingle('SELECT id FROM user ORDER BY id ASC LIMIT 1');

    if ($oldest !== null && $oldest !== false) {
        $statement = $db->prepare("INSERT OR IGNORE INTO user_roles (user_id, role, source)
                                   VALUES (:userId, 'admin', 'local')");
        $statement->bindValue(':userId', (int) $oldest, SQLITE3_INTEGER);
        $statement->execute();
    }
}

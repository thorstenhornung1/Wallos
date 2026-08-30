<?php
// Account ids become monotonic (issue #92).
//
// A freed id used to be handed out again: the storage assigns max+1, so
// deleting the newest account and creating another reassigned the same id —
// at which point any rows a pre-#81 deletion left behind became the new
// account's own data, indistinguishable from what it created itself. Another
// person's subscriptions, spending history and household members, reachable
// on an open-registration instance by simply signing up after somebody
// deleted their account.
//
// The rebuild lives in the boundary, because this is where the two backends
// genuinely differ: one needs its account table rebuilt around a monotonic
// key, the other draws ids from a sequence that never revisits a freed value
// and answers true without touching anything. Idempotent — an interrupted
// upgrade retries this file against an already-rebuilt table and nothing
// happens.
//
// What no migration can repair: where an id was already reused, the inherited
// rows now belong to a live account and no query can tell them from that
// account's own data. That case belongs in the release notes, with the advice
// to check accounts created shortly after a deletion.

if (!$db->rebuildWithMonotonicIds('user')) {
    error_log('Wallos: migration 000071 could not rebuild the account table; nothing was changed.');

    return false;
}

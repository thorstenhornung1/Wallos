# Test run 2026-08-21 — 5.8.4 on PostgreSQL

5.8.4 answers the two security findings from the night run and a night of work
on the shape behind them: a write whose result nobody reads, and a refusal
nobody can tell from a success. Everything it changes is verified here. No new
defect found; one observation about a boundary that is currently safe for a
reason the release itself argues against relying on.

## Environment

| | |
| --- | --- |
| Image | `ghcr.io/thorstenhornung1/wallos:5.8.4` |
| Digest | `sha256:10798f1540c4cdb2921a7974a395bc7eabb0949c8e460ccbfcc30d011153f848` |
| Version | `Wallos v5.8.4` |
| Database | PostgreSQL 18.6, dedicated, node-local volume |
| Platform | Docker Swarm, pinned to `docker-infra-3` |

## Results

| Test | Result | Evidence |
| --- | --- | --- |
| #94 PHP in writable directories | **pass** | 403 everywhere, ten bypass attempts refused |
| #97 refusals carry a refusal's status | **pass** | 405 / 403 / 401, no warnings, no paths |
| migration recorded only when it worked | **pass** | 42 tables, 65 migrations, stray table gone |
| new account start state | **pass** | 34 currencies, 31 payment methods, 17 categories |
| #96 password reset says why | **pass** | `login.php?reset=unavailable` |
| `write-audit` ratchet | **pass** | 23 discarded, 315 unchecked, in 119 files |
| backups outside the webroot | **pass** | system temp; `.tmp/` denied entirely |
| `db/` serves non-`.db` files | **observation** | safe today, rests on an invariant |

## Details

### #94 — the writable directories no longer execute

Every writable directory, a PHP file placed by `www-data` and fetched over HTTP:

```
db                             403   not executed, source not served
images/uploads                 403
images/uploads/logos           403
images/uploads/logos/avatars   403   <- nested, the prefix rule reaches it
images/uploads/icons           403   <- the directory the old rule missed
login.php                      200   <- counterproof, real pages still run
```

Ten bypass attempts, all refused:

```
db/x.phtml   db/x.php5   db/x.PHP   db/x.phar   db/x.phps
images/uploads/x.phtml
images/uploads/logos/deep/deeper/x.php
images/uploads/x.jpg.php
```

Two of the ten answered 200, and neither is a bypass:

* `images/uploads/x.php.jpg` — the file ends in `.jpg`, so it is served as a
  static image. The execution marker is absent; only the source I had just
  written appears. A `.jpg` containing PHP is a `.jpg`.
* `db/.htaccess` — nginx does not read `.htaccess` at all. Inert.

Both layers the release describes are present:

```
nginx     location ~* ^/(db|images/uploads)/.*\.(php|phtml|phar|phps|php[0-9])$
php-fpm   security.limit_extensions = .php   (zz-docker.conf)
```

The nginx rule is a **prefix** over the directory rather than a list of
directories, which is what makes `avatars/` and `icons/` inherit it.

### #97 — a refusal now has a refusal's status code

```
GET on a POST endpoint      405   Method Not Allowed
POST without a CSRF token   403   Forbidden
GET with no session         401   Unauthorized
```

Eight endpoints checked, all refusing before anything runs. And the body that
prompted the issue:

```
endpoints/subscriptions/get.php with no cookie
  70 bytes | PHP warnings: 0 | absolute paths: 0
  {"success":false,"message":"Your session expired. Please login again"}
```

On 2026-08-20 the same request answered **200** with **755 bytes** containing
three PHP warnings naming absolute paths and line numbers.

The gradation is worth more than the codes individually: a single status for
everything would already have been correct, three tell the caller *what* is
missing. A rate limiter counting 401s can now tell an attack from a bug in its
own client.

### Migration accounting, and a table that outlived its removal

```
tables in schema:  42     (was 43)
migrations:        65
table 'notifications': gone
```

Migration `000065` removed it, and its comment names a cause that reaches
further than this instance:

> 000016 splits the old notifications table into email_notifications and
> notification_settings and then drops it. The drop ran while the migration's
> own `SELECT COUNT(*) FROM notifications` result was still open, and SQLite
> refuses to drop a table a statement is holding.

The failure was never checked, so the migration recorded itself as applied with
the table still present — on every installation ever made, until 000065. A
migration marked done is never retried.

This closes an entry from the [2026-08-19 report](test-results-2026-08-19.md),
which recorded 43 tables against a baseline of 42 and noted that no migration
removed the difference. That was true when measured. The interesting part is
not the count but the mechanism: the same unchecked write that this whole
release is about, with a blast radius of every installation and a duration of
years.

### A new account is complete or it is not created

```
currencies        34
payment_methods   31
categories        17
household          1
settings           1
```

Previously written across three files in sixteen inserts with every result
discarded, so an account could be reported as created holding eleven of its
thirty-four currencies.

**Correction to my own measurement:** the comparison account showed 33 payment
methods, which read as a discrepancy. It has 33 because I created two during the
#93 tests. 31 is the correct default.

### #96 — the password reset says why

```
server_url empty:
  302 -> http://127.0.0.1/login.php?reset=unavailable
```

Previously a silent redirect to the front page, which is also what a broken
feature looks like — and did, for two full test attempts on 2026-08-20 before
the cause was found in the source.

### The write-audit ratchet holds

```
write-audit: ok — 23 discarded result(s) and 315 unchecked prepare(s) in 119 file(s)
```

Down from 66 and 368 when the tool was written, and the numbers match the
release notes exactly.

## Observation: `db/` serves anything that is not a `.db`

Not a defect, and nothing writes such a file today. Recorded because the
reasoning matters.

```
db/wallos.db, setup_token.db, …   403   (location ~ \.db$ deny all)
db/probe.sql                      200   content served
db/probe.bak                      200
db/probe.json                     200
db/probe.tar.gz                   200
```

The database itself is protected, and backups no longer land in a servable path
— `backup.php:53` uses `tempnam(sys_get_temp_dir(), …)`, and restore writes to
`.tmp/`, which is denied outright:

```
.tmp/probe.txt   403
```

So the exposure is theoretical: it depends on nothing ever placing a non-`.db`
file into a directory the web server user can write to.

That is the same kind of dependency the release rejects for `#94`, in its own
words — *"that invariant was the entire safety margin, in a layer with no reason
to depend on it."* `.tmp/` got a blanket denial; `db/` has an extension
denylist. A blanket denial there would cost nothing, since every file the
application puts in it is a `.db`.

## Conclusions, kept separate from the observations above

* **Both security findings from the night run are closed**, and each was checked
  by attacking it rather than by reading the diff: ten bypass attempts against
  the execution rule, three distinct refusal paths against the status codes.
* **The release's own theme holds up under test.** Unchecked writes were not a
  category invented for a changelog — migration 000016 is one, it shipped in
  every installation for years, and it was invisible precisely because the
  record of success was itself the unchecked write.
* **The remaining boundary is `db/`**, and only by convention.

## Not covered

* The migration *upgrade* path on PostgreSQL. 5.8.4 adds a CI case for it on 14
  and 18; this instance was created from the baseline, so nothing here exercises
  it.
* Sections 4, 5 and 7 of the plan were not re-run — 5.8.4 changes none of the
  paths they cover, and the release is a patch on the code exercised in the
  [night run](test-results-2026-08-20-nightrun.md).

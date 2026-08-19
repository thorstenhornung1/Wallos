#!/bin/sh

set -euo pipefail

echo "Startup script is running..." > /var/log/startup.log

# Default the PUID and PGID environment variables to 82, otherwise
# set to the user defined ones.
PUID=${PUID:-82}
PGID=${PGID:-82}

# Change the www-data user id and group id to be the user-specified ones
groupmod -o -g "$PGID" www-data
usermod -o -u "$PUID" www-data
# Only the two mount points, not the whole tree.
#
# The Dockerfile already set ownership at build time, so a recursive chown here
# changes nothing — but every chown() against an overlayfs lower file forces a
# full copy-up, which wrote 14MB into the container's writable layer on every
# start before a single request was served. The two directories below are the
# only ones that can arrive with foreign ownership, because they are volumes.
#
# /tmp is deliberately not among them. It is 1777 — sticky and world-writable —
# so www-data can already write there, and chowning it recursively would hand
# every file any other process left there to the web server user, which is the
# opposite of what the sticky bit is for. It was also the one chown here without
# `|| true`, so any environment that refused it killed the container at this
# line with "chown: /tmp: Operation not permitted" and nothing to act on.
chown -R www-data:www-data /var/www/html/db /var/www/html/images/uploads /var/www/html/.tmp 2>/dev/null || true

# PIDs we’ll track
PHP_FPM_PID=
NGINX_PID=
CROND_PID=
shutdown_in_progress=0

shutdown_once() {
  exit_signal=$?
  kill_signal=$(kill -l "$exit_signal" 2>/dev/null || echo "$exit_signal")

  [ "$shutdown_in_progress" -eq 1 ] && return 0
  shutdown_in_progress=1

  echo "Got signal: $kill_signal - Shutting down gracefully... "
  # nginx wants QUIT for graceful
  nginx -s quit || true
  # php-fpm graceful quit as well
  [ -n "${PHP_FPM_PID}" ] && kill -QUIT "${PHP_FPM_PID}" 2>/dev/null || true
  # cron can just get TERM
  [ -n "${CROND_PID}" ] && kill -TERM "${CROND_PID}" 2>/dev/null || true
  echo "Graceful shutdown complete."
}

# Handle all common stop signals
trap 'shutdown_once' SIGTERM SIGINT SIGQUIT

# Start both PHP-FPM and Nginx
echo "Launching php-fpm"
php-fpm -F &
PHP_FPM_PID=$!

echo "Launching crond"
crond -f &
CROND_PID=$!

echo "Launching nginx"
nginx -g 'daemon off;' &
NGINX_PID=$!

touch ~/startup.txt

# Wait one second before running scripts
sleep 1

# Change permissions on the database directory
# Tolerated on purpose: a mount that refuses these produces a cryptic
# 'Read-only file system' and a dead container. The check below says the
# same thing in a sentence the operator can act on.
chmod -R 755 /var/www/html/db/ 2>/dev/null || true
chown -R www-data:www-data /var/www/html/db/ 2>/dev/null || true

mkdir -p /var/www/html/images/uploads/logos/avatars 2>/dev/null || true

# Change permissions on the logos directory
chmod -R 755 /var/www/html/images/uploads/logos 2>/dev/null || true
chown -R www-data:www-data /var/www/html/images/uploads/logos 2>/dev/null || true

# Prove the data directories are writable by the user that will write to them.
#
# When they are not, Wallos does not stop: SQLite reports "attempt to write a
# readonly database", PHP logs a warning nobody reads, and every page still
# renders. The application looks healthy while nothing is being saved — a
# subscription added, an avatar uploaded, a rate refreshed, all silently
# discarded. That failure is far more likely than any attack: a bind mount with
# the wrong owner, a restored backup, a volume recreated by hand, or a switch to
# a different uid.
#
# So: write a file as www-data, in the directories that matter, and say plainly
# what to do if it fails. This runs as root, hence the drop to the user
# the application actually runs as — testing as root would prove nothing.
database_writable=1
uploads_writable=1

for dir in /var/www/html/db /var/www/html/images/uploads/logos; do
    probe="$dir/.write-probe-$$"

    # su rather than setpriv: BusyBox's setpriv has none of the util-linux
    # options, and -s is needed because www-data has no login shell.
    if su -s /bin/sh www-data -c "touch '$probe' 2>/dev/null && rm -f '$probe' 2>/dev/null"; then
        continue
    fi

    owner=$(stat -c '%U:%G (mode %a)' "$dir" 2>/dev/null || echo 'unknown')
    expected="$(id -u www-data 2>/dev/null):$(id -g www-data 2>/dev/null)"

    echo "" >&2
    echo "!!! Wallos: $dir is not writable by www-data." >&2

    # Two different faults produce the same symptom, and they need opposite
    # fixes. Naming the wrong one sends the operator chasing a chown that
    # cannot work — so ask root, who is subject to the mount but not to the
    # ownership, which of the two it is.
    if touch "$probe" 2>/dev/null; then
        rm -f "$probe" 2>/dev/null
        echo "!!!   It is owned by $owner; www-data is $expected." >&2
        echo "!!!   Fix the mounted directory on the host and restart:" >&2
        echo "!!!       chown -R $expected <the directory you mounted there>" >&2
    else
        echo "!!!   Not even root can write there, so the mount itself is" >&2
        echo "!!!   read-only — no chown will help. Drop ':ro' from the volume," >&2
        echo "!!!   or mount a writable location. (It is owned by $owner.)" >&2
    fi

    case "$dir" in
        */db) database_writable=0 ;;
        *) uploads_writable=0 ;;
    esac
done

if [ "$uploads_writable" = "0" ]; then
    echo "!!!   Logo and avatar uploads will fail. Everything else keeps working." >&2
    echo "" >&2
fi

if [ "$database_writable" = "0" ]; then
    # Refusing to start is the point, and it has to happen here — before the
    # database is opened. Run afterwards, createdatabase.php fails first and
    # `set -e` kills the script with SQLite's "unable to open database file",
    # which is exactly the cryptic message this check exists to replace.
    #
    # Refusing at all: otherwise PHP logs a warning nobody reads and every page
    # still renders, so the application looks healthy while every subscription,
    # every setting and every rate refresh is silently discarded. A container
    # that will not start is a problem somebody fixes today; one that quietly
    # saves nothing is found weeks later, if at all.
    echo "!!!   Refusing to start: Wallos cannot save anything without this" >&2
    echo "!!!   directory, and it would go on serving pages as if it could." >&2
    echo "" >&2
    exit 1
fi

# Create database if it does not exist
/usr/local/bin/php /var/www/html/endpoints/cronjobs/createdatabase.php

# Perform any database migrations
/usr/local/bin/php /var/www/html/endpoints/db/migrate.php

# The startup runs of the scheduled jobs, which are now able to report failure
# with a non-zero status.
#
# `|| true` on each, because this script runs under `set -e` and these jobs are
# not what the container is for. An exchange-rate provider refusing a key, or
# GitHub being unreachable, must not stop Wallos from serving pages — the job
# still logs the failure and records it for the admin page, which is where such
# a problem belongs. Without this the container would fail to start over a
# temporarily unreachable third party.
/usr/local/bin/php /var/www/html/endpoints/cronjobs/updatenextpayment.php || true

# Run updateexchange.php
/usr/local/bin/php /var/www/html/endpoints/cronjobs/updateexchange.php || true

# Run checkforupdates.php
/usr/local/bin/php /var/www/html/endpoints/cronjobs/checkforupdates.php || true

# Essentially wait until all child processes exit
wait

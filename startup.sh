#!/bin/sh

set -euo pipefail

# Whether this process is root decides everything below, so it is the first
# statement: under `user:` every privileged call is unreachable, and the
# script used to die on its old first line — a log file an unprivileged user
# cannot create — before anything could say why (#86).
RUNNING_AS_ROOT=0
if [ "$(id -u)" = "0" ]; then
    RUNNING_AS_ROOT=1
fi

echo "Wallos starting as $(id -u):$(id -g)"

if [ "$RUNNING_AS_ROOT" = "1" ]; then
    # Root with PUID/PGID stays the recommended path for plain Docker;
    # `user:` is the alternative, not the replacement. Default both to 82,
    # the image's www-data.
    PUID=${PUID:-82}
    PGID=${PGID:-82}

    # Checked since #86: a remap that failed used to be silent, and every
    # ownership assumption below it was then wrong.
    if ! groupmod -o -g "$PGID" www-data; then
        echo "!!! Wallos: could not set the www-data gid to $PGID; continuing with the image default." >&2
    fi
    if ! usermod -o -u "$PUID" www-data; then
        echo "!!! Wallos: could not set the www-data uid to $PUID; continuing with the image default." >&2
    fi

    # Only the two mount points, not the whole tree.
    #
    # The Dockerfile already set ownership at build time, so a recursive chown
    # here changes nothing — but every chown() against an overlayfs lower file
    # forces a full copy-up, which wrote 14MB into the container's writable
    # layer on every start before a single request was served. The two
    # directories below are the only ones that can arrive with foreign
    # ownership, because they are volumes.
    #
    # /tmp is deliberately not among them. It is 1777 — sticky and
    # world-writable — so www-data can already write there, and chowning it
    # recursively would hand every file any other process left there to the
    # web server user, which is the opposite of what the sticky bit is for.
    chown -R www-data:www-data /var/www/html/db /var/www/html/images/uploads 2>/dev/null || true
else
    echo "Running unprivileged: usermod, groupmod and chown are skipped."
    echo "The db/ and images/uploads mounts must already be writable by $(id -u):$(id -g)"
    echo "(the image grants group 0 access, so user: $(id -u):0 works against fresh volumes)."
fi

# Everything ephemeral in one place, created on every start because a tmpfs
# at /tmp begins empty: PHP sessions (named in php-wallos.ini), the cron job
# logs, and nginx's runtime state (#85, #86).
mkdir -p /tmp/wallos-sessions /tmp/cron /tmp/nginx
chmod 700 /tmp/wallos-sessions
if [ "$RUNNING_AS_ROOT" = "1" ]; then
    chown www-data:www-data /tmp/wallos-sessions 2>/dev/null || true
fi

# nginx cannot read environment variables, so a non-default port means a
# rewritten copy of the configuration under /tmp — the one place a read-only
# container writes. The capability on the binary keeps port 80 working for
# existing deployments; this is for the ones that drop every capability and
# have to listen high (#86). The healthcheck follows the same variable.
#
# The binary switches with the port: the capped one cannot even be exec'd
# once the bounding set lost the bind capability, and the uncapped copy could
# not bind 80. A high port needs no capability, so the copy serves it.
NGINX_CONF=/etc/nginx/nginx.conf
NGINX_BIN=/usr/sbin/nginx
WALLOS_HTTP_PORT=${WALLOS_HTTP_PORT:-80}
if [ "$WALLOS_HTTP_PORT" != "80" ]; then
    sed "s/listen       \[::\]:80 /listen       [::]:$WALLOS_HTTP_PORT /" /etc/nginx/nginx.conf > /tmp/nginx/nginx.conf
    NGINX_CONF=/tmp/nginx/nginx.conf
    NGINX_BIN=/usr/sbin/nginx-nocap
    echo "Listening on port $WALLOS_HTTP_PORT instead of 80."
fi

# PIDs we’ll track
PHP_FPM_PID=
NGINX_PID=
SUPERCRONIC_PID=
shutdown_in_progress=0

shutdown_once() {
  exit_signal=$?
  kill_signal=$(kill -l "$exit_signal" 2>/dev/null || echo "$exit_signal")

  [ "$shutdown_in_progress" -eq 1 ] && return 0
  shutdown_in_progress=1

  echo "Got signal: $kill_signal - Shutting down gracefully... "
  # nginx wants QUIT for graceful
  "$NGINX_BIN" -e /dev/stderr -c "$NGINX_CONF" -s quit || true
  # php-fpm graceful quit as well
  [ -n "${PHP_FPM_PID}" ] && kill -QUIT "${PHP_FPM_PID}" 2>/dev/null || true
  # supercronic can just get TERM
  [ -n "${SUPERCRONIC_PID}" ] && kill -TERM "${SUPERCRONIC_PID}" 2>/dev/null || true
  echo "Graceful shutdown complete."
}

# Handle all common stop signals
trap 'shutdown_once' SIGTERM SIGINT SIGQUIT

# Start both PHP-FPM and Nginx
echo "Launching php-fpm"
php-fpm -F &
PHP_FPM_PID=$!

# supercronic instead of dcron (#86): dcron maps the crontab filename to a
# username and calls setuid(), so it runs nothing at all unless it is root —
# measured, it synchronises and tests jobs and then stays silent. supercronic
# runs every job as the current uid, whatever that is.
echo "Launching supercronic"
supercronic -quiet /etc/cron.d/cronjobs &
SUPERCRONIC_PID=$!

# -e names the error log before the configuration is parsed; without it the
# unprivileged start opens with an alert about the compiled-in log path.
echo "Launching nginx"
"$NGINX_BIN" -e /dev/stderr -c "$NGINX_CONF" -g 'daemon off;' &
NGINX_PID=$!

# Wait one second before running scripts
sleep 1

# A dead nginx here is a container that runs everything except HTTP. The one
# known way to get there is dropping every capability while keeping port 80 —
# the capped binary then fails at exec, silently, in the background.
if ! kill -0 "$NGINX_PID" 2>/dev/null; then
    echo "!!! Wallos: nginx did not start. If this deployment drops all" >&2
    echo "!!!   capabilities, set WALLOS_HTTP_PORT to a port above 1024." >&2
    exit 1
fi

if [ "$RUNNING_AS_ROOT" = "1" ]; then
    # Change permissions on the database directory
    # Tolerated on purpose: a mount that refuses these produces a cryptic
    # 'Read-only file system' and a dead container. The check below says the
    # same thing in a sentence the operator can act on.
    chmod -R 755 /var/www/html/db/ 2>/dev/null || true
    chown -R www-data:www-data /var/www/html/db/ 2>/dev/null || true
fi

mkdir -p /var/www/html/images/uploads/logos/avatars 2>/dev/null || true

if [ "$RUNNING_AS_ROOT" = "1" ]; then
    # Change permissions on the logos directory
    chmod -R 755 /var/www/html/images/uploads/logos 2>/dev/null || true
    chown -R www-data:www-data /var/www/html/images/uploads/logos 2>/dev/null || true
fi

# Prove the data directories are writable by the identity that will write to
# them.
#
# When they are not, Wallos does not stop: SQLite reports "attempt to write a
# readonly database", PHP logs a warning nobody reads, and every page still
# renders. The application looks healthy while nothing is being saved — a
# subscription added, an avatar uploaded, a rate refreshed, all silently
# discarded. That failure is far more likely than any attack: a bind mount with
# the wrong owner, a restored backup, a volume recreated by hand, or a switch to
# a different uid — which is exactly the switch `user:` support invites (#86).
#
# As root the probe drops to www-data, the user the application actually runs
# as — testing as root would prove nothing. Unprivileged, this process IS the
# application's identity, so it probes directly.
database_writable=1
uploads_writable=1

for dir in /var/www/html/db /var/www/html/images/uploads/logos; do
    probe="$dir/.write-probe-$$"

    if [ "$RUNNING_AS_ROOT" = "1" ]; then
        # su rather than setpriv: BusyBox's setpriv has none of the util-linux
        # options, and -s is needed because www-data has no login shell.
        if su -s /bin/sh www-data -c "touch '$probe' 2>/dev/null && rm -f '$probe' 2>/dev/null"; then
            continue
        fi
    else
        if touch "$probe" 2>/dev/null; then
            rm -f "$probe" 2>/dev/null || true
            continue
        fi
    fi

    owner=$(stat -c '%U:%G (mode %a)' "$dir" 2>/dev/null || echo 'unknown')

    echo "" >&2
    echo "!!! Wallos: $dir is not writable." >&2

    if [ "$RUNNING_AS_ROOT" = "1" ]; then
        expected="$(id -u www-data 2>/dev/null):$(id -g www-data 2>/dev/null)"

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
    else
        expected="$(id -u):$(id -g)"
        echo "!!!   It is owned by $owner; this container runs as $expected." >&2
        echo "!!!   Prepare the mounted directory on the host and restart:" >&2
        echo "!!!       chown -R $expected <the directory you mounted there>" >&2
        echo "!!!   Or run as user: $(id -u):0 — the image grants group 0 access" >&2
        echo "!!!   to fresh volumes. If the mount is ':ro', no chown will help." >&2
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

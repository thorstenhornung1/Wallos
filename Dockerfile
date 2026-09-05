# Use the php:8.3-fpm-alpine base image
FROM php:8.3-fpm-alpine

# Set working directory to /var/www/html
WORKDIR /var/www/html

# Update packages and install dependencies.
#
# Two groups. The runtime packages stay in the image; the build-only ones go in
# a virtual package that is deleted in this same layer once the extensions are
# built, so the compiler headers never ship — they were extra size and extra CVE
# surface for something no runtime needs (the base image carries no toolchain to
# rebuild an extension anyway).
#
# The catch that makes this more than a one-liner: the extension .so files link
# libpq (pdo_pgsql), icu-libs (intl), libwebp (gd) and libzip (zip) at runtime,
# and those four were present only as transitive dependencies of the -dev
# headers. Deleting the headers would have taken the runtime libraries with them
# and broken the extension at *runtime*, not at build. They are named explicitly
# here so `apk del` keeps them. (icu-libs pulls libstdc++/libgcc, libwebp pulls
# libsharpyuv; keeping the parents keeps those.) Verified against `ldd` of every
# built .so, not assumed. Every package named is architecture-agnostic — this
# builds identically on each architecture upstream ships.
RUN apk upgrade --no-cache && \
    apk add --no-cache \
        dumb-init shadow curl libgomp nginx supercronic libcap-setcap tzdata \
        sqlite libpng libjpeg-turbo freetype icu-data-full icu-libs \
        libwebp libpq libzip && \
    apk add --no-cache --virtual .build-deps \
        autoconf sqlite-dev libpng-dev libjpeg-turbo-dev freetype-dev icu-dev \
        libwebp-dev libpq-dev libzip-dev && \
    docker-php-ext-install pdo pdo_sqlite pdo_pgsql calendar && \
    docker-php-ext-enable pdo pdo_sqlite pdo_pgsql && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install -j$(nproc) gd intl zip && \
    apk del --no-network .build-deps

# Copy your PHP application files into the container
COPY . .

# The one nginx configuration. The template that used to ship beside it went
# to a directory the loaded configuration never includes, and is gone (#125).
COPY nginx.conf /etc/nginx/nginx.conf

# PHP's ephemeral-state configuration (#85); conf.d is scanned even though no
# php.ini is loaded.
COPY php-wallos.ini /usr/local/etc/php/conf.d/wallos.ini

# Remove config files from webroot
RUN rm -rf /var/www/html/nginx.conf && \
    rm -rf /var/www/html/php-wallos.ini

# Copy the custom crontab file
COPY cronjobs /etc/cron.d/cronjobs

# Convert the line endings and allow read access to the cron file. supercronic
# reads it in place — no crontab installation, no per-user mapping, and the job
# logs land under /tmp (#86).
RUN dos2unix /etc/cron.d/cronjobs && \
    chmod 0644 /etc/cron.d/cronjobs && \
    # The application code belongs to root; only the data belongs to www-data.
    #
    # Both halves matter. The base image leaves /var/www/html at mode 1777, so
    # any process could drop a file in the docroot for nginx to serve. And a
    # blanket chown to www-data made the cron job scripts writable by the user
    # php-fpm runs as — the user any PHP-level flaw reaches — while root
    # executes those same scripts every two minutes. Either alone is a bug;
    # together they are a two-minute path from code execution to container root.
    chown -R root:root /var/www/html && \
    chmod 0755 /var/www/html && \
    mkdir -p /var/www/html/db /var/www/html/images/uploads/logos/avatars && \
    chown -R www-data:www-data /var/www/html/db /var/www/html/images/uploads && \
    # The gid-0 convention (#86): group 0 owns the data directories with the
    # owner's permissions, so `user: <uid>:0` can write fresh volumes without
    # any host-side chown. An arbitrary gid still cannot — the startup
    # preflight refuses that loudly, naming the chown to run.
    chgrp -R 0 /var/www/html/db /var/www/html/images/uploads && \
    chmod -R g=u /var/www/html/db /var/www/html/images/uploads && \
    # The bind capability on the binary, not on the process: existing compose
    # files keep port 80 without running as root (#86). The uncapped copy is
    # for deployments that drop every capability — the kernel refuses to exec
    # a binary whose file capabilities the bounding set cannot grant, so under
    # cap_drop ALL the capped binary does not merely fail to bind, it fails
    # to start. Those deployments set WALLOS_HTTP_PORT and get the copy.
    cp /usr/sbin/nginx /usr/sbin/nginx-nocap && \
    setcap cap_net_bind_service=+ep /usr/sbin/nginx && \
    chmod +x /var/www/html/startup.sh && \
    echo 'pm.max_children = 15' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_requests = 500' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    # ondemand instead of the base image's dynamic pool. dynamic pre-forks
    # pm.start_servers workers (2, from www.conf) and holds pm.min_spare of
    # them for as long as the container lives, whether or not anyone is using
    # it. A self-hosted household issues a request or two at a time and then
    # sits idle for minutes; ondemand forks a worker only when a request needs
    # one and reaps it after pm.process_idle_timeout, so an idle instance holds
    # the master alone and no worker RAM. pm.max_children = 15 above stays the
    # ceiling, so a burst still forks up to fifteen workers on demand — the
    # only cost is one fork's latency on the first hit after an idle spell, and
    # the opcache is shared, so that worker starts warm, not recompiling.
    echo 'pm = ondemand' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.process_idle_timeout = 10s' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    # The second half of the nginx rules that refuse PHP under db/ and
    # images/uploads/ (issue #94). nginx decides what it passes to php-fpm;
    # this decides what php-fpm agrees to run when something else asks. Two
    # layers, because the whole finding was that one of them had been extended
    # per directory and had fallen behind.
    echo 'security.limit_extensions = .php' >> /usr/local/etc/php-fpm.d/zz-docker.conf

# Expose port 80 for Nginx
EXPOSE 80

ENTRYPOINT ["dumb-init", "--"]

# Requires docker engine 25+ for the --start-interval flag. Shell form on
# purpose: the port has to follow WALLOS_HTTP_PORT (#86), and only the shell
# form expands it.
HEALTHCHECK --interval=2m --timeout=2s --start-period=20s --start-interval=5s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${WALLOS_HTTP_PORT:-80}/health.php"

# Start both PHP-FPM, Nginx
CMD ["/var/www/html/startup.sh"]

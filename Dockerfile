# Use the php:8.3-fpm-alpine base image
FROM php:8.3-fpm-alpine

# Set working directory to /var/www/html
WORKDIR /var/www/html

# Update packages and install dependencies
RUN apk upgrade --no-cache && \
    apk add --no-cache dumb-init shadow sqlite-dev libpng libpng-dev libjpeg-turbo libjpeg-turbo-dev freetype freetype-dev curl autoconf libgomp icu-dev icu-data-full nginx dcron tzdata libzip-dev sqlite libwebp-dev libpq-dev && \
    docker-php-ext-install pdo pdo_sqlite pdo_pgsql calendar && \
    docker-php-ext-enable pdo pdo_sqlite pdo_pgsql && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install -j$(nproc) gd intl zip

# Copy your PHP application files into the container
COPY . .

# Copy Nginx configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY nginx.default.conf /etc/nginx/http.d/default.conf

# PHP's ephemeral-state configuration (#85); conf.d is scanned even though no
# php.ini is loaded.
COPY php-wallos.ini /usr/local/etc/php/conf.d/wallos.ini

# Remove config files from webroot
RUN rm -rf /var/www/html/nginx.conf && \
    rm -rf /var/www/html/nginx.default.conf && \
    rm -rf /var/www/html/php-wallos.ini

# Copy the custom crontab file
COPY cronjobs /etc/cron.d/cronjobs

# Convert the line endings, allow read access to the cron file, and create cron log folder
RUN dos2unix /etc/cron.d/cronjobs && \
    chmod 0644 /etc/cron.d/cronjobs && \
    /usr/bin/crontab /etc/cron.d/cronjobs && \
    mkdir /var/log/cron && \
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
    mkdir -p /var/www/html/db /var/www/html/images/uploads/logos/avatars /var/www/html/.tmp && \
    chown -R www-data:www-data /var/www/html/db /var/www/html/images/uploads /var/www/html/.tmp && \
    chmod +x /var/www/html/startup.sh && \
    echo 'pm.max_children = 15' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_requests = 500' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    # The second half of the nginx rules that refuse PHP under db/ and
    # images/uploads/ (issue #94). nginx decides what it passes to php-fpm;
    # this decides what php-fpm agrees to run when something else asks. Two
    # layers, because the whole finding was that one of them had been extended
    # per directory and had fallen behind.
    echo 'security.limit_extensions = .php' >> /usr/local/etc/php-fpm.d/zz-docker.conf

# Expose port 80 for Nginx
EXPOSE 80

ENTRYPOINT ["dumb-init", "--"]

# Requires docker engine 25+ for the --start-interval flag
HEALTHCHECK --interval=2m --timeout=2s --start-period=20s --start-interval=5s --retries=3 \
    CMD ["curl", "-fsS", "http://127.0.0.1/health.php"]

# Start both PHP-FPM, Nginx
CMD ["/var/www/html/startup.sh"]

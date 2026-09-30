# Development image for the homeside/laravel-ai-agents package.
#
# Minimum tooling to install dependencies and run the QA suite:
#   - PHP 8.4 CLI (the composer.lock requires >= 8.4.1)
#   - Composer 2
#   - pdo_sqlite + mbstring (testbench runs on in-memory SQLite)
#
# The package directory is mounted as a volume at runtime, so code changes
# never require rebuilding the image.

FROM php:8.4-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# mbstring ships compiled into the official php:8.4 images; only pdo_sqlite
# needs building (sqlite-dev provides the headers).
RUN apk add --no-cache git unzip sqlite-dev \
    && docker-php-ext-install pdo_sqlite

WORKDIR /app

# Composer may run as root inside the container; keep its cache in /tmp so
# the mounted package directory never accumulates cache noise.
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

CMD ["php", "-v"]

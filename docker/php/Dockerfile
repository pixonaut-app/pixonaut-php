# The PHP the package is developed and tested on. PHP_VERSION selects the
# php:<version>-cli tag; Composer comes from the official composer:2 image.
ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

# Composer extracts dist archives with unzip and falls back to git for sources.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

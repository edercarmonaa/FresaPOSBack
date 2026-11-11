FROM composer:2.9.2 AS composer
FROM php:8.3.36-fpm-bookworm

ARG DEBIAN_SNAPSHOT=20261110T000000Z
ARG MSODBCSQL_VERSION=18.7.1.1-1
ARG SQLSRV_VERSION=5.13.3

RUN rm /etc/apt/sources.list.d/debian.sources \
    && printf 'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/%s bookworm main\ndeb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/%s bookworm-security main\n' "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" > /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg unzip \
       libicu-dev libonig-dev libxml2-dev libzip-dev unixodbc-dev=2.3.11-2+deb12u1 $PHPIZE_DEPS \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo 'deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main' > /etc/apt/sources.list.d/microsoft-prod.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18=${MSODBCSQL_VERSION} \
    && docker-php-ext-install -j"$(nproc)" intl mbstring zip bcmath opcache \
    && pecl install sqlsrv-${SQLSRV_VERSION} pdo_sqlsrv-${SQLSRV_VERSION} \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
RUN groupadd --gid 1000 app && useradd --uid 1000 --gid app --create-home app \
    && mkdir -p /var/www/html && chown app:app /var/www/html
WORKDIR /var/www/html
USER app
CMD ["php-fpm", "-F"]

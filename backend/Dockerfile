FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libzip-dev libonig-dev libicu-dev libsqlite3-dev && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip pcntl intl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

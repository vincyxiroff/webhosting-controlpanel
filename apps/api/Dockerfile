FROM php:8.4-cli-alpine

RUN apk add --no-cache bash curl git icu-dev libzip-dev oniguruma-dev postgresql-dev \
    && docker-php-ext-install intl mbstring pcntl pdo_pgsql zip

WORKDIR /app
COPY composer.json composer.json
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-interaction --prefer-dist --no-scripts
COPY . .

EXPOSE 8080
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]


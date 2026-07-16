FROM php:8.2-cli

WORKDIR /app

# Needed for MySQL PDO in api/config/db.php
RUN docker-php-ext-install pdo_mysql

COPY . /app

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t ."]

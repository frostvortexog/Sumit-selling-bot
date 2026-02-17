# ---------- Dockerfile (PHP 8.2 + PostgreSQL PDO) ----------
FROM php:8.2-apache

# Enable Apache rewrite (optional but useful)
RUN a2enmod rewrite

# Install system deps + PHP extensions for Postgres
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    ca-certificates \
 && docker-php-ext-install pdo pdo_pgsql \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Copy your webhook file
COPY index.php /var/www/html/index.php

# (Optional) You can add a simple health route if you want
# But Telegram will just call /index.php

# Expose Apache port
EXPOSE 80

# Apache is default CMD in php:apache

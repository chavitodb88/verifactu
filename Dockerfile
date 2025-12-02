FROM php:8.2-apache

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    git \
    unzip \
  && docker-php-ext-install \
    intl \
    pdo \
    pdo_mysql \
    mysqli \
    zip \
  && rm -rf /var/lib/apt/lists/*

COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

FROM php:8.2-apache

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    git \
    unzip \
    \
    # GD deps (para endroid/qr-code -> PngWriter)
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install \
    intl \
    pdo \
    pdo_mysql \
    mysqli \
    zip \
    gd \
  && rm -rf /var/lib/apt/lists/*

COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

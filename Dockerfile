FROM php:7.4-apache as php7
RUN docker-php-ext-install mysqli

RUN apt-get update && apt-get install -y libpng-dev zlib1g-dev
RUN apt-get install -y \
    libwebp-dev \
    libjpeg62-turbo-dev \
    libpng-dev libxpm-dev \
    libfreetype6-dev \
    libpng-dev zlib1g-dev

RUN docker-php-ext-install gd

RUN docker-php-ext-configure gd \
    --enable-gd \
    --with-webp \
    --with-jpeg \
    --with-xpm \
    --with-freetype

RUN a2enmod rewrite

WORKDIR /var/www/html
COPY php.ini /usr/local/etc/php/

FROM php:8.1-apache as php_8_1
RUN docker-php-ext-install mysqli

RUN apt-get update && apt-get install -y libpng-dev zlib1g-dev
RUN apt-get install -y \
    libwebp-dev \
    libjpeg62-turbo-dev \
    libpng-dev libxpm-dev \
    libfreetype6-dev \
    libpng-dev zlib1g-dev

RUN docker-php-ext-install gd

RUN docker-php-ext-configure gd \
    --enable-gd \
    --with-webp \
    --with-jpeg \
    --with-xpm \
    --with-freetype

RUN a2enmod rewrite

WORKDIR /var/www/html
COPY php.ini /usr/local/etc/php/


FROM php:8.2-apache as php_8_2
RUN docker-php-ext-install mysqli

RUN apt-get update && apt-get install -y libpng-dev zlib1g-dev
RUN apt-get install -y \
    libwebp-dev \
    libjpeg62-turbo-dev \
    libpng-dev libxpm-dev \
    libfreetype6-dev

RUN docker-php-ext-install gd

RUN docker-php-ext-configure gd \
    --enable-gd \
    --with-webp \
    --with-jpeg \
    --with-xpm \
    --with-freetype

RUN a2enmod rewrite

WORKDIR /var/www/html
COPY php.ini /usr/local/etc/php/

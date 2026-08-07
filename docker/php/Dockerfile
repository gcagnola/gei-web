FROM php:8.4-apache

ARG UID=1000
ARG GID=1000

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        python3 \
        python3-venv \
        python3-pip \
        python3-psycopg \
        postgresql-client \
        unzip \
        zip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pdo_pgsql \
        pgsql \
        zip \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data

WORKDIR /var/www/html

COPY src/python/gei_liquidaciones_piloto/requirements.txt /tmp/gei_pdf_requirements.txt

RUN python3 -m venv /opt/gei-python \
    && /opt/gei-python/bin/pip install --upgrade pip \
    && /opt/gei-python/bin/pip install --no-cache-dir -r /tmp/gei_pdf_requirements.txt \
    && rm -f /tmp/gei_pdf_requirements.txt

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

CMD ["apache2-foreground"]

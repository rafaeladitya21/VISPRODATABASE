FROM php:8.3-apache
ENV PORT=80
# PostgreSQL (Supabase): driver pdo_pgsql butuh libpq
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
# Pengaturan PHP production: error tidak ditampilkan ke pengunjung (tetap tercatat di log)
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN a2enmod rewrite
COPY . /var/www/html/
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
RUN chown -R www-data:www-data /var/www/html
# Railway: pastikan hanya satu MPM (prefork) yang aktif saat container start
CMD ["bash", "-c", "rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* && a2enmod -q mpm_prefork && exec apache2-foreground"]

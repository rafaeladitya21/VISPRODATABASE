FROM php:8.3-apache
ENV PORT=80
RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN a2enmod rewrite
COPY . /var/www/html/
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
RUN chown -R www-data:www-data /var/www/html
# Railway: pastikan hanya satu MPM (prefork) yang aktif saat container start
CMD ["bash", "-c", "rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* && a2enmod -q mpm_prefork && exec apache2-foreground"]

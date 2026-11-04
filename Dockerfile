FROM php:8.3-apache
ENV PORT=80
RUN docker-php-ext-install pdo pdo_mysql mysqli
COPY . /var/www/html/
RUN sed -i "s/80/\${PORT}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
RUN chown -R www-data:www-data /var/www/html


FROM php:8.2-apache

# Install required PHP extensions for MySQL
RUN docker-php-ext-install mysqli pdo_mysql

# Enable Apache URL rewriting
RUN a2enmod rewrite

# Copy project files into Apache web directory
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Configure Apache to use Render's assigned port
EXPOSE 10000

CMD ["sh", "-c", "sed -i 's/Listen 80/Listen ${PORT:-10000}/' /etc/apache2/ports.conf && sed -i 's/:80>/:${PORT:-10000}>/' /etc/apache2/sites-available/000-default.conf && apache2-foreground"]

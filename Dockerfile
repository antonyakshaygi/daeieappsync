FROM php:8.2-apache

# Install required PHP extensions for MySQL/PDO
RUN docker-php-ext-install pdo pdo_mysql

# Copy project files to Apache root
COPY . /var/www/html/

# Enable Apache rewrite module if needed
RUN a2enmod rewrite

# Expose port 80 for Render
EXPOSE 80

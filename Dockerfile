FROM php:8.2-apache

# Install dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql gd fileinfo \
    && a2enmod rewrite

# Copy application files
COPY ./public /var/www/html

# Create .htaccess file
RUN echo '<IfModule mod_rewrite.c>\n\
    RewriteEngine On\n\
    RewriteBase /\n\
    RewriteCond %{REQUEST_URI} !^/public/uploads/\n\
    RewriteCond %{REQUEST_FILENAME} !-f\n\
    RewriteCond %{REQUEST_FILENAME} !-d\n\
    RewriteRule ^(.*)$ index.php [L,QSA]\n\
    </IfModule>\n\
    <FilesMatch "\.js$">\n\
    SetHandler none\n\
    AddType application/javascript js\n\
    </FilesMatch>' > /var/www/html/.htaccess


# Set permissions
RUN mkdir -p /var/www/html/public/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/public/uploads \
    && chown www-data:www-data /var/www/html/public/uploads \
    && chmod 775 /var/www/html/public/uploads

# Configure PHP upload settings
RUN echo "upload_max_filesize = 5M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 6M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "upload_tmp_dir = /tmp/php_uploads" >> /usr/local/etc/php/conf.d/uploads.ini \
    && mkdir -p /tmp/php_uploads \
    && chown www-data:www-data /tmp/php_uploads \
    && chmod 775 /tmp/php_uploads

# Configure Apache
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install dependencies from composer.json and composer.lock
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader
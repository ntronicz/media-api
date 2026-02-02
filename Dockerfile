FROM php:8.3-apache

# Install FFmpeg and required tools
RUN apt-get update && apt-get install -y \
    ffmpeg \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Create directories
RUN mkdir -p /var/www/html/logs \
    /var/www/html/uploads \
    /var/www/html/outputs \
    && chmod -R 777 /var/www/html/logs \
    /var/www/html/uploads \
    /var/www/html/outputs

# Configure PHP
RUN echo "upload_max_filesize = 500M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 500M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 80

WORKDIR /var/www/html

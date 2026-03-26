# Dockerfile
FROM php:7.4-fpm

# Install Nginx
RUN apt-get update && \  
    apt-get install -y nginx && \  
    apt-get clean && \  
    rm -rf /var/lib/apt/lists/*

# Copy nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html/

# Expose port 80
EXPOSE 80

CMD service nginx start && php-fpm

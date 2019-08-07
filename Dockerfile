FROM quay.io/hellofresh/php70:7.1

# Adds nginx configurations
ADD ./docker/nginx/default.conf   /etc/nginx/sites-available/default

# Environment variables to PHP-FPM
RUN sed -i -e "s/;clear_env\s*=\s*no/clear_env = no/g" /etc/php/7.1/fpm/pool.d/www.conf

# Set apps home directory.
ENV APP_DIR /server/http

# Adds the application code to the image
ADD . ${APP_DIR}

# Define current working directory.
WORKDIR ${APP_DIR}

RUN apt-get update
RUN apt-get install apt-utils
RUN apt-get install -y zip
RUN apt-get install -y unzip

RUN cd /tmp && wget https://github.com/phpredis/phpredis/archive/3.1.4.zip -O phpredis.zip && cd /tmp && unzip phpredis.zip && cd /tmp/phpredis-3.1.4 && phpize && ./configure && make && make install && echo "extension=redis.so" >> /etc/php/7.1/fpm/php.ini && echo "Done with php-redis"

# Cleanup
RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

EXPOSE 80

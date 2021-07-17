ARG PHP_VERSION
FROM bref/php-$PHP_VERSION

# Overwrite the original bootstrap
COPY bootstrap /opt/bootstrap
# Copy files to /var/runtime to support deploying as a Docker image
RUN cp /opt/bootstrap /var/runtime

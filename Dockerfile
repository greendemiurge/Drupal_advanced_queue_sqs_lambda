# Set Bref version as a build argument (e.g., 82 for PHP 8.2)
ARG BREF_VERSION=83

# Use Bref's dev base for the CLI Lambda runtime
FROM bref/php-${BREF_VERSION}:3 AS lambda

COPY 99-overrides.ini /opt/bref/etc/php/conf.d/

# Copy precompiled extensions from Bref's official extension images
COPY --from=bref/extra-mongodb-php-83:1 /opt /opt
COPY --from=bref/extra-gd-php-83:1 /opt /opt

# Install system dependencies
RUN yum install -y \
    git \
    zip \
 && yum clean all && rm -rf /var/cache/yum

# Define paths
ENV PROJECT_ROOT=/var/task
ENV DRUPAL_ROOT=/var/task/web
ENV HOME=/tmp
ENV PATH="${PATH}:/var/task/vendor/bin"
ENV _HANDLER=execute_sqs_queue_item.php
ENV BREF_RUNTIME=Bref\\FunctionRuntime\\Main

# Copy the execute script.
COPY execute_sqs_queue_item.php $PROJECT_ROOT

# Install composer
COPY install_composer.sh $PROJECT_ROOT
RUN $PROJECT_ROOT/install_composer.sh

ENTRYPOINT ["/opt/bootstrap"]
CMD ["execute_sqs_queue_item.php"]

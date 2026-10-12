# The PHAR holds only PHP files, so it is built once on the builder's native arch for every target platform.
FROM --platform=$BUILDPLATFORM public.ecr.aws/docker/library/php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS build
ARG VERSION=dev

RUN apk add --no-cache bash unzip
COPY --from=public.ecr.aws/docker/library/composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
RUN wget -qO /usr/local/bin/box https://github.com/box-project/box/releases/download/4.7.0/box.phar \
    && echo "3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52  /usr/local/bin/box" | sha256sum -c - \
    && chmod +x /usr/local/bin/box

WORKDIR /src
COPY . .
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN .build/build-phar.sh "$VERSION" /stbox.phar

FROM public.ecr.aws/docker/library/php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb
COPY --from=build /stbox.phar /usr/local/bin/stbox.phar
# OCR of a 1,500-cue PGS file peaks at about 139 MB, above the 128 MB default.
RUN echo "memory_limit=512M" > "$PHP_INI_DIR/conf.d/stbox.ini"
WORKDIR /work
USER www-data
ENTRYPOINT ["php", "/usr/local/bin/stbox.phar"]

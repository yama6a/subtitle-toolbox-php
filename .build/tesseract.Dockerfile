# The image of .build/Dockerfile plus Tesseract with the fast models of every language, released with a -tesseract tag suffix.
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

# Alpine packages the standard models of 67 languages only. The fast models of all languages take 338 MB, the
# standard models 1,014 MB, and both read the test files without errors. The commit pins the content.
FROM --platform=$BUILDPLATFORM public.ecr.aws/docker/library/php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS tessdata
ARG TESSDATA_COMMIT=87416418657359cb625c412a48b6e1d6d41c29bd
RUN apk add --no-cache git
WORKDIR /tessdata
RUN git init -q . \
    && git remote add origin https://github.com/tesseract-ocr/tessdata_fast.git \
    && git sparse-checkout set --no-cone '/*.traineddata' \
    && git fetch -q --depth 1 --filter=blob:none origin "$TESSDATA_COMMIT" \
    && git checkout -q FETCH_HEAD \
    && rm -rf .git

FROM public.ecr.aws/docker/library/php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb
RUN apk add --no-cache tesseract-ocr
COPY --from=tessdata /tessdata/ /usr/share/tessdata/
COPY --from=build /stbox.phar /usr/local/bin/stbox.phar
# OCR of a 1,500-cue PGS file peaks at about 139 MB, above the 128 MB default.
RUN echo "memory_limit=512M" > "$PHP_INI_DIR/conf.d/stbox.ini"
WORKDIR /work
USER www-data
ENTRYPOINT ["php", "/usr/local/bin/stbox.phar"]

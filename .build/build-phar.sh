#!/usr/bin/env bash
# Builds stbox.phar with php-glyph-ocr bundled, from a copy of the tree, so the checkout stays clean.
# Usage: .build/build-phar.sh <version> [output path]. Needs php, composer and box on the PATH.
set -euo pipefail

version=${1:?usage: build-phar.sh <version, for example 2.0.0> [output path]}
root=$(cd "$(dirname "$0")/.." && pwd)
output=${2:-$root/stbox.phar}
[[ $output == /* ]] || output=$PWD/$output
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

cp -R "$root/bin" "$root/src" "$root/composer.json" "$root/composer.lock" "$root/box.json" "$work/"
cd "$work"

composer install --no-dev --no-interaction --no-progress
# php-glyph-ocr is a dev dependency of the library. Box leaves dev packages out, so the build moves the
# locked version into require.
ocr=$(composer show --locked --format=json yama6a/php-glyph-ocr | php -r 'echo json_decode(stream_get_contents(STDIN))->versions[0];')
composer require --update-no-dev --no-interaction --no-progress "yama6a/php-glyph-ocr:$ocr"

# git-version would read the previous tag, because the release job builds before it tags.
# shellcheck disable=SC2016 # the $ belong to PHP
VERSION=$version php -r '
    $config = json_decode(file_get_contents("box.json"), true);
    unset($config["git-version"]);
    $config["replacements"] = ["package_version" => getenv("VERSION")];
    file_put_contents("box.json", json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'
box compile --no-interaction
mv stbox.phar "$output"

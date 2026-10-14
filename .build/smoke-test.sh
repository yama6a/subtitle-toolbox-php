#!/usr/bin/env bash
# Runs the PHAR once per feature that depends on how it was built: the version, iconv, the EUC-JP table of iconv and the bundled OCR.
# It also checks that the PHP of the image has ext-curl, which the translate command needs.
# Usage: .build/smoke-test.sh <expected version> <tests/files as the PHP command sees it> <PHAR path> <PHP command...>
# SMOKE_TESSERACT=1 also reads Cyrillic text with Tesseract, for the image with all Tesseract languages.
set -euo pipefail

expected=$1
files=$2
phar=$3
shift 3

version=$("$@" "$phar" --version)
if [ "$version" != "$expected" ]; then
  echo "--version printed '$version', expected '$expected'" >&2
  exit 1
fi

vtt=$("$@" "$phar" convert "$files/cli/latin1.srt" --encoding Windows-1252 --to vtt --output -)
if ! grep -qx 'Café au lait.' <<< "$vtt"; then
  printf 'convert printed:\n%s\n' "$vtt" >&2
  exit 1
fi

arib=$("$@" "$phar" convert "$files/mpegts/arib_japanese.ts" --track 304 --to srt --output - 2>/dev/null)
if [[ $arib != *天気* ]]; then
  printf 'convert of ARIB captions printed:\n%s\n' "$arib" >&2
  exit 1
fi

if ! "$@" -r 'exit(function_exists("curl_init") ? 0 : 1);'; then
  echo "PHP has no ext-curl, which translate needs" >&2
  exit 1
fi

ocr=$("$@" "$phar" convert "$files/pgs/text_1080p.sup" --ocr --to srt --output -)
if [[ $ocr != *Bergen* ]]; then
  printf 'convert --ocr printed:\n%s\n' "$ocr" >&2
  exit 1
fi

if [ "${SMOKE_TESSERACT:-}" = 1 ]; then
  ocr=$("$@" "$phar" convert "$files/pgs/text_cyrillic_1080p.sup" --ocr --ocr-engine tesseract --ocr-language rus --to srt --output -)
  if [[ $ocr != *Берген* ]]; then
    printf 'convert --ocr-engine tesseract printed:\n%s\n' "$ocr" >&2
    exit 1
  fi
fi

echo "smoke test passed: $version"

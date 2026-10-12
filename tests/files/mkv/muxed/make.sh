#!/bin/sh
# Muxes the subtitle files in this folder with ffmpeg 7.0.2 and mkvmerge v99.0 into the MKV, WebM and MP4 fixtures.
# Run: sh tests/files/mkv/muxed/make.sh
set -eu
cd "$(dirname "$0")"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

ffmpeg -y -v error -f lavfi -i color=c=gray:s=64x36:r=4:d=20 -c:v libx264 -preset ultrafast -g 8 -pix_fmt yuv420p \
    -fflags +bitexact -flags:v +bitexact "$work/video.mp4"
ffmpeg -y -v error -f lavfi -i color=c=gray:s=64x36:r=4:d=20 -c:v libvpx-vp9 -deadline realtime -crf 63 -b:v 0 -g 8 \
    -fflags +bitexact -flags:v +bitexact "$work/video.webm"

mkvmerge --quiet --deterministic 756 -o ../mkvmerge.mkv "$work/video.mp4" \
    --language 0:en --track-name 0:English harbour.srt --language 0:de --default-track-flag 0:no harbour.ass
ffmpeg -y -v error -i "$work/video.mp4" -i harbour.srt -map 0 -map 1 -c:v copy -c:s srt -metadata:s:s:0 language=eng \
    ../ffmpeg_srt.mkv
ffmpeg -y -v error -i "$work/video.webm" -i harbour.vtt -map 0 -map 1 -c copy -metadata:s:s:0 language=eng \
    ../ffmpeg_webvtt.webm
ffmpeg -y -v error -i "$work/video.mp4" -i harbour.srt -map 0 -map 1 -c:v copy -c:s mov_text -metadata:s:s:0 language=eng \
    ../../mp4/ffmpeg_mov_text.mp4

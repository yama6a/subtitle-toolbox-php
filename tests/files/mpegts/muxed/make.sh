#!/bin/sh
# Encodes the PGS fixture shapes_1080p.sup and the VobSub fixture two-tracks-pal.idx as DVB subtitles with ffmpeg 7.0.2,
# muxes them into dvb_subtitles.ts, and remuxes that file into ../../mkv/dvbsub.mkv with mkvmerge v99.0.
# Run: sh tests/files/mpegts/muxed/make.sh
set -eu
cd "$(dirname "$0")"

# -fix_sub_duration ends each PGS and VobSub cue at the next one. Without it, ffmpeg 7.0.2 clears the page 49 days later.
ffmpeg -y -v error -f lavfi -i color=c=gray:s=64x36:r=4:d=17 \
    -fix_sub_duration -itsoffset 1 -i ../../pgs/shapes_1080p.sup -fix_sub_duration -i ../../vobsub/two-tracks-pal.idx \
    -map 0 -map 1 -map 2:0 -c:v libx264 -preset ultrafast -g 8 -pix_fmt yuv420p -c:s dvbsub \
    -metadata:s:s:0 language=deu -metadata:s:s:1 language=eng -disposition:s:1 hearing_impaired \
    -fflags +bitexact -flags:v +bitexact ../dvb_subtitles.ts
mkvmerge --quiet --deterministic 1029 -o ../../mkv/dvbsub.mkv ../dvb_subtitles.ts

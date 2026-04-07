#!/bin/sh
set -eu

KSEF_MODE_VALUE="${KSEF_MODE:-production}"
echo "[$(date)] KSeF relay server started in ${KSEF_MODE_VALUE} mode."

exec php -S 0.0.0.0:8080 -t src src/index.php
#!/bin/bash
# Bench entrypoint of the OpenLiteSpeed container: installs the bench vhost, then hands over
# to the image's own entrypoint (which chowns the conf and starts lswsctrl).
set -euo pipefail

if [ -z "$(ls -A -- /usr/local/lsws/conf/)" ]; then
	cp -R /usr/local/lsws/.conf/* /usr/local/lsws/conf/
fi
cp /bench/ols/vhconf.conf /usr/local/lsws/conf/vhosts/Example/vhconf.conf

exec /entrypoint.sh "$@"

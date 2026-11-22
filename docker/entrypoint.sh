#!/bin/sh
set -e

PORT="${PORT:-80}"
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/http.d/default.conf

exec supervisord -c /etc/supervisord.conf

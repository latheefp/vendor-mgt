#!/bin/sh
set -e

####################################################################
# FrankenPHP entrypoint.
#
# tmp/ and logs/ arrive as freshly created Docker named volumes owned
# by root. Without fixing that first, the very first request dies
# trying to write a log line — and the error you then see is the
# logging failure rather than whatever actually went wrong.
#
# Runs on every start because a volume can be recreated at any time.
####################################################################

for dir in \
    /app/tmp \
    /app/tmp/cache \
    /app/tmp/cache/models \
    /app/tmp/cache/persistent \
    /app/tmp/cache/views \
    /app/tmp/sessions \
    /app/tmp/tests \
    /app/logs \
    /app/webroot/uploads
do
    mkdir -p "$dir"
    chmod 0777 "$dir" 2>/dev/null || true
done

exec "$@"

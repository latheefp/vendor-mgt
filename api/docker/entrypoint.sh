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

####################################################################
# Schema migrations run on every boot, not in CI, because the deploy
# workflow only swaps the image — nothing ever ran `migrations migrate`
# against the database, which is how the login lockout columns ended
# up missing in production. Phinx records what it has already applied
# and skips it, so this is a no-op on most starts. With multiple
# replicas booting together, two pods can race the same ALTER TABLE;
# the loser exits (set -e) and Kubernetes restarts it, at which point
# the migration is already applied and it boots clean.
####################################################################
if [ "$1" = "frankenphp" ]; then
    bin/cake migrations migrate
fi

exec "$@"

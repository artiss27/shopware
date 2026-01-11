#!/bin/sh
# Simple script to process Caddyfile template and replace environment variables
# Base image handles PHP-FPM and Caddy startup - we only process the Caddyfile

set -e

CADDYFILE_SRC="${CADDYFILE_SRC:-/etc/caddy/Caddyfile.template}"
# Write to /tmp first (writable by www-data), then copy to /etc/caddy/ if we have permissions
# If /etc/caddy/ is read-only (mounted volume), we'll use /tmp/Caddyfile.proc
CADDYFILE_DST_TMP="/tmp/Caddyfile.proc"
CADDYFILE_DST="${CADDYFILE_DST:-/etc/caddy/Caddyfile}"

if [ ! -f "$CADDYFILE_SRC" ]; then
    echo "ERROR: Caddyfile template not found: $CADDYFILE_SRC" >&2
    exit 1
fi

# Check CADDY_HOST
if [ -z "${CADDY_HOST:-}" ]; then
    echo "ERROR: CADDY_HOST not set!" >&2
    exit 1
fi

# Remove protocol if present
CADDY_HOST_CLEANED=$(echo "$CADDY_HOST" | sed -e 's|^http://||' -e 's|^https://||')
export CADDY_HOST="$CADDY_HOST_CLEANED"

# Process with envsubst - write to /tmp first (always writable)
envsubst '${CADDY_HOST}' < "$CADDYFILE_SRC" > "$CADDYFILE_DST_TMP"

# Try to copy to /etc/caddy/Caddyfile if we have permissions, otherwise use /tmp version
if cp "$CADDYFILE_DST_TMP" "$CADDYFILE_DST" 2>/dev/null; then
    echo "Caddyfile written to $CADDYFILE_DST" >&2
    CADDYFILE_DST="$CADDYFILE_DST"
else
    echo "Warning: Cannot write to $CADDYFILE_DST (permission denied), using $CADDYFILE_DST_TMP" >&2
    CADDYFILE_DST="$CADDYFILE_DST_TMP"
fi

# Handle Basic Auth marker
if [ "${CADDY_BASIC_AUTH_ENABLED:-false}" != "true" ] && [ "${CADDY_BASIC_AUTH_ENABLED}" != "1" ]; then
    sed -i '/{{BASIC_AUTH}}/d' "$CADDYFILE_DST" 2>/dev/null || true
elif [ -n "${CADDY_BASIC_AUTH_USER:-}" ] && [ -n "${CADDY_BASIC_AUTH_PASS:-}" ]; then
    HASH="${CADDY_BASIC_AUTH_HASH:-}"
    if [ -z "$HASH" ] && command -v caddy >/dev/null 2>&1; then
        HASH=$(echo "$CADDY_BASIC_AUTH_PASS" | caddy hash-password --plaintext 2>/dev/null || echo "$CADDY_BASIC_AUTH_PASS")
    fi
    [ -z "$HASH" ] && HASH="$CADDY_BASIC_AUTH_PASS"
    printf '        basicauth /* {\n            %s %s\n        }\n' "$CADDY_BASIC_AUTH_USER" "$HASH" | \
        sed -i '/{{BASIC_AUTH}}/{
            r /dev/stdin
            d
        }' "$CADDYFILE_DST" 2>/dev/null || true
else
    sed -i '/{{BASIC_AUTH}}/d' "$CADDYFILE_DST" 2>/dev/null || true
fi

echo "Caddyfile processed: $CADDYFILE_SRC -> $CADDYFILE_DST" >&2
# Export path for use in command
echo "$CADDYFILE_DST" > /tmp/caddyfile_path.txt
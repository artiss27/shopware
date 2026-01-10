#!/bin/sh
set -e

# Replace environment variables in Caddyfile
# Caddyfile is mounted as read-only, so we create a processed copy in /tmp
if [ -f /etc/caddy/Caddyfile ]; then
    # Use envsubst if available, otherwise use sed for variable substitution
    # Only replace CADDY_HOST, as email is handled via environment variable
    if command -v envsubst >/dev/null 2>&1; then
        # Replace CADDY_HOST environment variable
        envsubst '${CADDY_HOST}' < /etc/caddy/Caddyfile > /tmp/Caddyfile.proc
    else
        # Fallback: use sed for manual replacement
        cp /etc/caddy/Caddyfile /tmp/Caddyfile.proc
        if [ -n "$CADDY_HOST" ]; then
            sed -i "s|{\\$CADDY_HOST}|$CADDY_HOST|g" /tmp/Caddyfile.proc
        fi
    fi
    
    # Validate processed Caddyfile
    if command -v caddy >/dev/null 2>&1; then
        caddy validate --config /tmp/Caddyfile.proc || {
            echo "ERROR: Caddyfile validation failed after variable substitution!" >&2
            echo "CADDY_EMAIL=${CADDY_EMAIL:-not set}" >&2
            echo "CADDY_HOST=${CADDY_HOST:-not set}" >&2
            echo "Original Caddyfile:" >&2
            cat /etc/caddy/Caddyfile >&2
            echo "" >&2
            echo "Processed Caddyfile:" >&2
            cat /tmp/Caddyfile.proc >&2
            exit 1
        }
    fi
fi

# Replace --config argument in command to use processed Caddyfile
# Parse arguments and replace --config /etc/caddy/Caddyfile with --config /tmp/Caddyfile.proc
NEW_CMD=""
skip_next=false
for arg in "$@"; do
    if [ "$skip_next" = true ]; then
        if [ "$arg" = "/etc/caddy/Caddyfile" ]; then
            NEW_CMD="$NEW_CMD /tmp/Caddyfile.proc"
        else
            NEW_CMD="$NEW_CMD $arg"
        fi
        skip_next=false
    elif [ "$arg" = "--config" ]; then
        NEW_CMD="$NEW_CMD --config"
        skip_next=true
    else
        NEW_CMD="$NEW_CMD $arg"
    fi
done

# Execute Caddy with processed config (split NEW_CMD into array)
eval "set -- $NEW_CMD"
exec "$@"

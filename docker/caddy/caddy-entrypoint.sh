#!/bin/sh
set -e

# Replace environment variables in Caddyfile
# Caddyfile is mounted as read-only, so we create a processed copy in /tmp
if [ -f /etc/caddy/Caddyfile ]; then
    # Check if CADDY_HOST is set (required for Caddyfile)
    if [ -z "${CADDY_HOST:-}" ]; then
        echo "WARNING: CADDY_HOST environment variable is not set!" >&2
        echo "Setting CADDY_HOST to 'localhost' as fallback" >&2
        export CADDY_HOST="localhost"
    else
        # Remove protocol (http:// or https://) if present, as Caddy only needs domain name
        CADDY_HOST_CLEANED=$(echo "$CADDY_HOST" | sed -e 's|^http://||' -e 's|^https://||')
        if [ "$CADDY_HOST_CLEANED" != "$CADDY_HOST" ]; then
            echo "WARNING: CADDY_HOST contains protocol, removing it: '$CADDY_HOST' -> '$CADDY_HOST_CLEANED'" >&2
            export CADDY_HOST="$CADDY_HOST_CLEANED"
        fi
    fi
    
    # Use envsubst if available, otherwise use sed for variable substitution
    # Replace CADDY_HOST environment variable
    if command -v envsubst >/dev/null 2>&1; then
        envsubst '${CADDY_HOST}' < /etc/caddy/Caddyfile > /tmp/Caddyfile.proc
    else
        # Fallback: use sed for manual replacement
        cp /etc/caddy/Caddyfile /tmp/Caddyfile.proc
        sed -i "s|{\\$CADDY_HOST}|${CADDY_HOST}|g" /tmp/Caddyfile.proc
    fi
    
    # Add HTTP Basic Auth if enabled via CADDY_BASIC_AUTH_ENABLED
    # Replace the marker {{BASIC_AUTH}} with actual basicauth block if enabled
    if [ "${CADDY_BASIC_AUTH_ENABLED:-false}" = "true" ] || [ "${CADDY_BASIC_AUTH_ENABLED}" = "1" ]; then
        if [ -n "$CADDY_BASIC_AUTH_USER" ] && [ -n "$CADDY_BASIC_AUTH_PASS" ]; then
            # Determine which hash to use: pre-provided, generated, or plain password
            FINAL_HASH=""
            
            # First, try pre-provided hash
            if [ -n "$CADDY_BASIC_AUTH_HASH" ]; then
                FINAL_HASH="$CADDY_BASIC_AUTH_HASH"
            # Try to generate hash using Caddy
            elif command -v caddy >/dev/null 2>&1; then
                FINAL_HASH=$(echo "$CADDY_BASIC_AUTH_PASS" | caddy hash-password --plaintext 2>/dev/null || echo "")
            fi
            
            # Use plain password as last resort (Caddy will hash it, but shows warning)
            if [ -z "$FINAL_HASH" ]; then
                FINAL_HASH="$CADDY_BASIC_AUTH_PASS"
                echo "Warning: Using plain password for basic auth. Generate hash with: docker run --rm caddy:latest caddy hash-password --plaintext 'your-password'" >&2
            fi
            
            # Create basicauth block content in temporary file
            # Use printf to avoid variable expansion issues
            # Note: basicauth is applied inside handle {} block, which processes all paths NOT matched above
            # Health check endpoints are handled in a separate handle block above, so they bypass this basicauth
            # basicauth /* protects all paths within this handle block
            printf '        basicauth /* {\n            %s %s\n        }\n' "$CADDY_BASIC_AUTH_USER" "$FINAL_HASH" > /tmp/auth_block.txt
            
            # Replace {{BASIC_AUTH}} marker with content from file using sed 'r' command (read)
            # This is more reliable than multiline sed substitution
            sed -i '/{{BASIC_AUTH}}/{
                r /tmp/auth_block.txt
                d
            }' /tmp/Caddyfile.proc
            
            rm -f /tmp/auth_block.txt
        else
            echo "Warning: CADDY_BASIC_AUTH_ENABLED is true but CADDY_BASIC_AUTH_USER or CADDY_BASIC_AUTH_PASS is not set!" >&2
            sed -i '/{{BASIC_AUTH}}/d' /tmp/Caddyfile.proc
        fi
    else
        # Remove marker if auth is disabled
        sed -i '/{{BASIC_AUTH}}/d' /tmp/Caddyfile.proc
    fi
    
    # Validate processed Caddyfile
    if command -v caddy >/dev/null 2>&1; then
        if ! caddy validate --config /tmp/Caddyfile.proc 2>&1; then
            echo "ERROR: Caddyfile validation failed after variable substitution!" >&2
            echo "CADDY_EMAIL=${CADDY_EMAIL:-not set}" >&2
            echo "CADDY_HOST=${CADDY_HOST:-not set}" >&2
            echo "CADDY_BASIC_AUTH_ENABLED=${CADDY_BASIC_AUTH_ENABLED:-not set}" >&2
            echo "Original Caddyfile:" >&2
            cat /etc/caddy/Caddyfile >&2
            echo "" >&2
            echo "Processed Caddyfile:" >&2
            cat /tmp/Caddyfile.proc >&2
            exit 1
        fi
        echo "Caddyfile validation successful" >&2
    fi
else
    # If Caddyfile doesn't exist, create a minimal one
    echo "WARNING: /etc/caddy/Caddyfile not found, creating minimal fallback" >&2
    cat > /tmp/Caddyfile.proc << EOF
localhost:8000 {
    reverse_proxy localhost:8000
}
EOF
fi

# Replace --config argument if present to use processed Caddyfile
# Or if no --config argument, use processed file directly
HAS_CONFIG=false
NEW_ARGS=()
for arg in "$@"; do
    if [ "$HAS_CONFIG" = true ]; then
        # Replace config path with processed file
        NEW_ARGS+=("/tmp/Caddyfile.proc")
        HAS_CONFIG=false
    elif [ "$arg" = "--config" ]; then
        NEW_ARGS+=("--config")
        HAS_CONFIG=true
    elif echo "$arg" | grep -q "Caddyfile"; then
        # If argument contains Caddyfile, replace with processed version
        NEW_ARGS+=("/tmp/Caddyfile.proc")
    else
        NEW_ARGS+=("$arg")
    fi
done

# If no arguments provided, use default: caddy run --config /tmp/Caddyfile.proc
if [ ${#NEW_ARGS[@]} -eq 0 ]; then
    NEW_ARGS=("run" "--config" "/tmp/Caddyfile.proc")
fi

echo "Starting Caddy with arguments: ${NEW_ARGS[*]}" >&2
echo "Processed Caddyfile location: /tmp/Caddyfile.proc" >&2

# Execute Caddy with processed config
exec caddy "${NEW_ARGS[@]}"

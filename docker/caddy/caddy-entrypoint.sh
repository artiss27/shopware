#!/bin/sh
# Don't use set -e here - we need to handle errors gracefully and show all errors
set -u  # Only fail on undefined variables

# Track errors
ERRORS=0

error_exit() {
    echo "ERROR: $1" >&2
    exit 1
}

# Replace environment variables in Caddyfile
# Caddyfile is mounted as read-only, so we create a processed copy in /tmp
if [ -f /etc/caddy/Caddyfile ]; then
    echo "Processing Caddyfile..." >&2
    echo "Environment variables check:" >&2
    echo "  CADDY_HOST=${CADDY_HOST:-NOT SET}" >&2
    echo "  CADDY_EMAIL=${CADDY_EMAIL:-NOT SET}" >&2
    echo "  CADDY_BASIC_AUTH_ENABLED=${CADDY_BASIC_AUTH_ENABLED:-NOT SET}" >&2
    
    # Check if CADDY_HOST is set (required for Caddyfile)
    if [ -z "${CADDY_HOST:-}" ] || [ "${CADDY_HOST}" = "" ]; then
        echo "ERROR: CADDY_HOST environment variable is not set or is empty!" >&2
        echo "This is REQUIRED for Caddy to work. Please set CADDY_HOST in docker-compose.prod.yml" >&2
        echo "For initial setup (before DNS), use: CADDY_HOST=localhost:8000" >&2
        echo "After DNS is configured, use: CADDY_HOST=your-domain.com" >&2
        exit 1
    fi
    
    # Remove protocol (http:// or https://) if present, as Caddy only needs domain name
    CADDY_HOST_CLEANED=$(echo "$CADDY_HOST" | sed -e 's|^http://||' -e 's|^https://||' || echo "$CADDY_HOST")
    if [ "$CADDY_HOST_CLEANED" != "$CADDY_HOST" ]; then
        echo "WARNING: CADDY_HOST contains protocol, removing it: '$CADDY_HOST' -> '$CADDY_HOST_CLEANED'" >&2
        export CADDY_HOST="$CADDY_HOST_CLEANED"
    fi
    
    # Validate CADDY_HOST is not empty after cleaning
    if [ -z "$CADDY_HOST" ] || [ "$CADDY_HOST" = "" ]; then
        echo "ERROR: CADDY_HOST is empty after cleaning protocol!" >&2
        exit 1
    fi
    
    echo "Using CADDY_HOST: $CADDY_HOST" >&2
    
    # Use envsubst if available, otherwise use sed for variable substitution
    # Replace CADDY_HOST environment variable
    if command -v envsubst >/dev/null 2>&1; then
        envsubst '${CADDY_HOST}' < /etc/caddy/Caddyfile > /tmp/Caddyfile.proc || {
            echo "ERROR: Failed to process Caddyfile with envsubst" >&2
            exit 1
        }
    else
        # Fallback: use sed for manual replacement
        cp /etc/caddy/Caddyfile /tmp/Caddyfile.proc || {
            echo "ERROR: Failed to copy Caddyfile" >&2
            exit 1
        }
        sed -i "s|{\\$CADDY_HOST}|${CADDY_HOST}|g" /tmp/Caddyfile.proc || {
            echo "ERROR: Failed to replace CADDY_HOST in Caddyfile" >&2
            exit 1
        }
    fi
    
    echo "Caddyfile copied and CADDY_HOST replaced" >&2
    
    # Add HTTP Basic Auth if enabled via CADDY_BASIC_AUTH_ENABLED
    # Replace the marker {{BASIC_AUTH}} with actual basicauth block if enabled
    if [ "${CADDY_BASIC_AUTH_ENABLED:-false}" = "true" ] || [ "${CADDY_BASIC_AUTH_ENABLED}" = "1" ]; then
        echo "Basic Auth is enabled, processing..." >&2
        if [ -n "${CADDY_BASIC_AUTH_USER:-}" ] && [ -n "${CADDY_BASIC_AUTH_PASS:-}" ]; then
            # Determine which hash to use: pre-provided, generated, or plain password
            FINAL_HASH=""
            
            # First, try pre-provided hash
            if [ -n "${CADDY_BASIC_AUTH_HASH:-}" ]; then
                FINAL_HASH="$CADDY_BASIC_AUTH_HASH"
                echo "Using pre-provided hash for basic auth" >&2
            # Try to generate hash using Caddy
            elif command -v caddy >/dev/null 2>&1; then
                FINAL_HASH=$(echo "$CADDY_BASIC_AUTH_PASS" | caddy hash-password --plaintext 2>/dev/null || echo "")
                if [ -n "$FINAL_HASH" ]; then
                    echo "Generated hash for basic auth using Caddy" >&2
                fi
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
            printf '        basicauth /* {\n            %s %s\n        }\n' "$CADDY_BASIC_AUTH_USER" "$FINAL_HASH" > /tmp/auth_block.txt || {
                echo "ERROR: Failed to create auth block file" >&2
                exit 1
            }
            
            # Replace {{BASIC_AUTH}} marker with content from file using sed 'r' command (read)
            # This is more reliable than multiline sed substitution
            # Check if marker exists before trying to replace
            if grep -q '{{BASIC_AUTH}}' /tmp/Caddyfile.proc; then
                sed -i '/{{BASIC_AUTH}}/{
                    r /tmp/auth_block.txt
                    d
                }' /tmp/Caddyfile.proc || {
                    echo "ERROR: Failed to inject basic auth block" >&2
                    rm -f /tmp/auth_block.txt
                    exit 1
                }
                echo "Basic auth block injected successfully" >&2
            else
                echo "Warning: {{BASIC_AUTH}} marker not found in Caddyfile" >&2
            fi
            
            rm -f /tmp/auth_block.txt
        else
            echo "Warning: CADDY_BASIC_AUTH_ENABLED is true but CADDY_BASIC_AUTH_USER or CADDY_BASIC_AUTH_PASS is not set!" >&2
            # Remove marker if variables are not set
            if grep -q '{{BASIC_AUTH}}' /tmp/Caddyfile.proc; then
                sed -i '/{{BASIC_AUTH}}/d' /tmp/Caddyfile.proc || {
                    echo "ERROR: Failed to remove {{BASIC_AUTH}} marker" >&2
                    exit 1
                }
            fi
        fi
    else
        # Remove marker if auth is disabled
        echo "Basic Auth is disabled, removing {{BASIC_AUTH}} marker..." >&2
        if grep -q '{{BASIC_AUTH}}' /tmp/Caddyfile.proc; then
            sed -i '/{{BASIC_AUTH}}/d' /tmp/Caddyfile.proc || {
                echo "ERROR: Failed to remove {{BASIC_AUTH}} marker" >&2
                exit 1
            }
            echo "{{BASIC_AUTH}} marker removed successfully" >&2
        fi
    fi
    
    # Verify processed file exists and has content
    if [ ! -f /tmp/Caddyfile.proc ]; then
        echo "ERROR: Processed Caddyfile not found at /tmp/Caddyfile.proc" >&2
        exit 1
    fi
    
    if [ ! -s /tmp/Caddyfile.proc ]; then
        echo "ERROR: Processed Caddyfile is empty" >&2
        exit 1
    fi
    
    echo "Processed Caddyfile created successfully ($(wc -l < /tmp/Caddyfile.proc) lines)" >&2
    
    # Validate processed Caddyfile
    if command -v caddy >/dev/null 2>&1; then
        echo "Validating Caddyfile..." >&2
        # Use --adapter caddyfile explicitly to ensure we're using Caddyfile adapter
        VALIDATION_OUTPUT=$(caddy validate --config /tmp/Caddyfile.proc --adapter caddyfile 2>&1)
        VALIDATION_EXIT=$?
        if [ $VALIDATION_EXIT -ne 0 ]; then
            echo "ERROR: Caddyfile validation failed after variable substitution!" >&2
            echo "Exit code: $VALIDATION_EXIT" >&2
            echo "Validation output:" >&2
            echo "$VALIDATION_OUTPUT" >&2
            echo "" >&2
            echo "Environment variables:" >&2
            echo "CADDY_EMAIL=${CADDY_EMAIL:-not set}" >&2
            echo "CADDY_HOST=${CADDY_HOST:-not set}" >&2
            echo "CADDY_BASIC_AUTH_ENABLED=${CADDY_BASIC_AUTH_ENABLED:-not set}" >&2
            echo "CADDY_BASIC_AUTH_USER=${CADDY_BASIC_AUTH_USER:-not set}" >&2
            echo "" >&2
            echo "Original Caddyfile (first 50 lines):" >&2
            head -50 /etc/caddy/Caddyfile >&2 || true
            echo "" >&2
            echo "Processed Caddyfile (full content):" >&2
            cat /tmp/Caddyfile.proc >&2
            echo "" >&2
            echo "Checking for common issues..." >&2
            if grep -q '{{BASIC_AUTH}}' /tmp/Caddyfile.proc 2>/dev/null; then
                echo "ERROR: {{BASIC_AUTH}} marker is still present in processed Caddyfile!" >&2
            fi
            if grep -q '{\$CADDY_HOST}' /tmp/Caddyfile.proc 2>/dev/null; then
                echo "ERROR: {\$CADDY_HOST} placeholder is still present in processed Caddyfile!" >&2
            fi
            echo "Line numbers where issues might be:" >&2
            grep -n '{{BASIC_AUTH}}\|{\$CADDY_HOST}' /tmp/Caddyfile.proc 2>/dev/null || echo "No markers found" >&2
            exit 1
        fi
        echo "Caddyfile validation successful" >&2
    else
        echo "Warning: caddy command not found, skipping validation" >&2
        echo "This may cause Caddy to fail at startup!" >&2
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

echo "Starting PHP-FPM in background..." >&2
# Start PHP-FPM in background (Caddy needs PHP-FPM to proxy to)
# Use -F flag to run in foreground mode but start as daemon
# This ensures PHP-FPM stays running even if parent process exits
php-fpm -D || {
    echo "ERROR: Failed to start PHP-FPM" >&2
    echo "Attempting to start PHP-FPM in foreground mode..." >&2
    php-fpm -F &
    PHP_FPM_PID=$!
    echo "PHP-FPM started in foreground (PID: $PHP_FPM_PID)" >&2
}

# Wait a bit for PHP-FPM to be ready
sleep 3

# Check if PHP-FPM is running
PHP_FPM_PIDS=$(pgrep -x php-fpm 2>/dev/null || echo "")
if [ -z "$PHP_FPM_PIDS" ]; then
    echo "ERROR: PHP-FPM process not found after start attempt" >&2
    echo "Checking PHP-FPM configuration..." >&2
    php-fpm -t 2>&1 || true
    echo "Attempting to check PHP-FPM error logs..." >&2
    if [ -f /var/www/html/var/log/php-fpm.log ]; then
        tail -20 /var/www/html/var/log/php-fpm.log 2>/dev/null || true
    fi
    exit 1
else
    echo "PHP-FPM started successfully (PIDs: $PHP_FPM_PIDS)" >&2
fi

# Check if PHP-FPM is listening on port 8000
echo "Checking if PHP-FPM is listening on port 8000..." >&2
MAX_CHECK=10
CHECK_COUNT=0
PHP_FPM_READY=false
while [ $CHECK_COUNT -lt $MAX_CHECK ]; do
    if command -v nc >/dev/null 2>&1; then
        if nc -z 127.0.0.1 8000 2>/dev/null; then
            echo "PHP-FPM is listening on port 8000" >&2
            PHP_FPM_READY=true
            break
        fi
    elif command -v ss >/dev/null 2>&1; then
        if ss -lnt 2>/dev/null | grep -q ':8000 '; then
            echo "PHP-FPM is listening on port 8000 (checked via ss)" >&2
            PHP_FPM_READY=true
            break
        fi
    elif command -v netstat >/dev/null 2>&1; then
        if netstat -lnt 2>/dev/null | grep -q ':8000 '; then
            echo "PHP-FPM is listening on port 8000 (checked via netstat)" >&2
            PHP_FPM_READY=true
            break
        fi
    fi
    CHECK_COUNT=$((CHECK_COUNT + 1))
    if [ $CHECK_COUNT -lt $MAX_CHECK ]; then
        sleep 1
    fi
done

if [ "$PHP_FPM_READY" != "true" ]; then
    echo "WARNING: PHP-FPM may not be listening on port 8000 after ${MAX_CHECK} attempts" >&2
    echo "This may cause Caddy to fail. Continuing anyway..." >&2
    echo "PHP-FPM processes:" >&2
    ps aux | grep php-fpm | grep -v grep || echo "No PHP-FPM processes found" >&2
fi

echo "Starting Caddy with arguments: ${NEW_ARGS[*]}" >&2
echo "Processed Caddyfile location: /tmp/Caddyfile.proc" >&2

# Execute Caddy as PID 1 (using exec replaces shell with Caddy process)
# PHP-FPM is already running in background as daemon (via php-fpm -D)
# This is the standard Docker pattern: one main process (Caddy) as PID 1
# PHP-FPM runs as daemon process in the background
exec caddy "${NEW_ARGS[@]}"

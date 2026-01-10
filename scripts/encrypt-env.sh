#!/bin/bash
# Encrypt .env.prod file using GPG for secrets management
# Usage: ./encrypt-env.sh [encrypt|decrypt]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

ENV_FILE=".env.prod"
ENCRYPTED_FILE=".env.prod.gpg"
GPG_KEY="${GPG_KEY_ID:-}"

if [[ ! -f "$ENV_FILE" ]] && [[ ! -f "$ENCRYPTED_FILE" ]]; then
    echo "Error: Neither $ENV_FILE nor $ENCRYPTED_FILE found"
    exit 1
fi

if [[ -z "$GPG_KEY" ]]; then
    echo "Error: GPG_KEY_ID environment variable not set"
    echo "Usage: GPG_KEY_ID=your-key-id $0 [encrypt|decrypt]"
    exit 1
fi

encrypt() {
    if [[ ! -f "$ENV_FILE" ]]; then
        echo "Error: $ENV_FILE not found"
        exit 1
    fi
    
    echo "Encrypting $ENV_FILE..."
    gpg --encrypt --recipient "$GPG_KEY" --armor --output "$ENCRYPTED_FILE" "$ENV_FILE"
    chmod 600 "$ENCRYPTED_FILE"
    echo "Encrypted file created: $ENCRYPTED_FILE"
    echo "You can now safely delete $ENV_FILE"
}

decrypt() {
    if [[ ! -f "$ENCRYPTED_FILE" ]]; then
        echo "Error: $ENCRYPTED_FILE not found"
        exit 1
    fi
    
    echo "Decrypting $ENCRYPTED_FILE..."
    gpg --decrypt --output "$ENV_FILE" "$ENCRYPTED_FILE"
    chmod 600 "$ENV_FILE"
    echo "Decrypted file created: $ENV_FILE"
}

case "${1:-}" in
    encrypt)
        encrypt
        ;;
    decrypt)
        decrypt
        ;;
    *)
        echo "Usage: $0 [encrypt|decrypt]"
        echo ""
        echo "Before using, set GPG_KEY_ID environment variable:"
        echo "  export GPG_KEY_ID=your-gpg-key-id"
        echo ""
        echo "To get your GPG key ID:"
        echo "  gpg --list-keys"
        exit 1
        ;;
esac

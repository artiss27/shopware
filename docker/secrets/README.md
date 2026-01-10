# Secrets Management

This directory contains documentation and scripts for managing secrets securely.

## Option 1: Encrypted .env.prod file (Recommended for single server)

Use GPG to encrypt `.env.prod` file:

```bash
# 1. Generate GPG key (if not exists)
gpg --gen-key

# 2. Get your GPG key ID
gpg --list-keys

# 3. Encrypt .env.prod
export GPG_KEY_ID=your-key-id
./scripts/encrypt-env.sh encrypt

# 4. On server, decrypt before deployment
./scripts/encrypt-env.sh decrypt
```

Store only `.env.prod.gpg` in repository (if needed), never `.env.prod`.

## Option 2: Docker Secrets (Recommended for Docker Swarm)

For Docker Swarm, use Docker secrets:

```bash
# Create secrets
echo "your-db-password" | docker secret create db_password -
echo "your-opensearch-password" | docker secret create opensearch_password -
echo "your-mailer-dsn" | docker secret create mailer_dsn -

# Update docker-compose.prod.yml to use secrets
secrets:
  db_password:
    external: true
  opensearch_password:
    external: true
  mailer_dsn:
    external: true
```

## Option 3: Environment Variables on Server

Set environment variables directly on server:

```bash
# In /etc/environment or ~/.bashrc
export DB_PASSWORD=your-password
export OPENSEARCH_PASSWORD=your-password
# ...

# docker-compose.prod.yml will automatically use them
```

## Best Practices

1. **Never commit secrets to git** - Use `.gitignore` for `.env.prod`
2. **Rotate secrets regularly** - Change passwords every 90 days
3. **Use different secrets for dev/staging/prod**
4. **Limit access** - Only authorized personnel should have access to secrets
5. **Use strong passwords** - Minimum 32 characters for production
6. **Audit access** - Log who accessed secrets and when

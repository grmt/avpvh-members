#!/usr/bin/env bash
# deploy-lldap.sh — one-shot setup for LLDAP + Authelia + nginx for avphilipsvanhorne.nl
# Run from the repo root:  bash scripts/deploy-lldap.sh
# Requires: docker, sudo access for the Authelia config copy.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_DIR=/opt/docker/scripts
SECRETS_DIR=/opt/docker/secrets/compose
AUTHELIA_CONFIG=/opt/docker/volumes/authelia/config/configuration.yml
NGINX_CONTAINER=scripts-nginx-1
MYSQL_CONTAINER=scripts-mysql-1

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
info()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
die()   { echo -e "${RED}[✗]${NC} $*" >&2; exit 1; }
ask()   { echo -e "${YELLOW}[?]${NC} $*"; }

echo ""
echo "=== AVP-PvH LLDAP deployment ==="
echo ""

# ---------------------------------------------------------------------------
# 1. LLDAP admin password
# ---------------------------------------------------------------------------
LLDAP_PASS_FILE="$SECRETS_DIR/lldap_admin_password.txt"
JWT_FILE="$SECRETS_DIR/lldap_jwt_secret.txt"

if [[ -f "$LLDAP_PASS_FILE" ]]; then
    info "LLDAP admin password secret already exists — skipping."
    LLDAP_PASS=$(cat "$LLDAP_PASS_FILE")
else
    ask "Enter LLDAP admin password (will be stored in $LLDAP_PASS_FILE):"
    read -r -s LLDAP_PASS
    echo ""
    if [[ -z "$LLDAP_PASS" ]]; then
        die "Password cannot be empty."
    fi
    echo -n "$LLDAP_PASS" > "$LLDAP_PASS_FILE"
    chmod 600 "$LLDAP_PASS_FILE"
    info "LLDAP admin password saved."
fi

# ---------------------------------------------------------------------------
# 2. LLDAP JWT secret
# ---------------------------------------------------------------------------
if [[ -f "$JWT_FILE" ]]; then
    info "LLDAP JWT secret already exists — skipping."
else
    openssl rand -hex 32 > "$JWT_FILE"
    chmod 600 "$JWT_FILE"
    info "LLDAP JWT secret generated."
fi

# ---------------------------------------------------------------------------
# 3. Deploy Authelia config (fill in the LLDAP admin password)
# ---------------------------------------------------------------------------
AUTHELIA_SRC="$REPO_DIR/config/authelia-configuration.yml"
AUTHELIA_TMP=$(mktemp)
sed "s/REPLACE_WITH_LLDAP_ADMIN_PASSWORD/$LLDAP_PASS/" "$AUTHELIA_SRC" > "$AUTHELIA_TMP"

if sudo cp "$AUTHELIA_TMP" "$AUTHELIA_CONFIG"; then
    info "Authelia config deployed to $AUTHELIA_CONFIG"
else
    rm -f "$AUTHELIA_TMP"
    die "Failed to copy Authelia config (sudo required)."
fi
rm -f "$AUTHELIA_TMP"

# ---------------------------------------------------------------------------
# 4. Create lldap database and grant access
# ---------------------------------------------------------------------------
MYSQL_ROOT_PASS=$(cat "$SECRETS_DIR/mysql_root_password.txt")

info "Creating lldap database and granting access..."
docker exec -i "$MYSQL_CONTAINER" \
    mariadb -uroot -p"$MYSQL_ROOT_PASS" <<'SQL'
CREATE DATABASE IF NOT EXISTS lldap CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON lldap.* TO 'wp_user'@'%';
FLUSH PRIVILEGES;
SQL
info "Database ready."

# ---------------------------------------------------------------------------
# 5. Create lldap data volume directory
# ---------------------------------------------------------------------------
mkdir -p /opt/docker/volumes/lldap
info "LLDAP data directory ready."

# ---------------------------------------------------------------------------
# 6. Start LLDAP
# ---------------------------------------------------------------------------
info "Starting LLDAP..."
(cd "$COMPOSE_DIR" && docker compose up -d lldap)
echo -n "Waiting for LLDAP to be ready"
for i in $(seq 1 20); do
    if docker exec "$MYSQL_CONTAINER" mariadb -uroot -p"$MYSQL_ROOT_PASS" \
        -e "SELECT 1 FROM lldap.users LIMIT 1;" &>/dev/null; then
        echo ""
        info "LLDAP is up and tables are created."
        break
    fi
    echo -n "."
    sleep 2
    if [[ $i -eq 20 ]]; then
        echo ""
        die "LLDAP did not initialise within 40 seconds. Check: docker logs scripts-lldap-1"
    fi
done

# ---------------------------------------------------------------------------
# 7. Restart Authelia
# ---------------------------------------------------------------------------
info "Restarting Authelia..."
(cd "$COMPOSE_DIR" && docker compose restart authelia)

# ---------------------------------------------------------------------------
# 8. Reload nginx
# ---------------------------------------------------------------------------
info "Reloading nginx..."
docker exec "$NGINX_CONTAINER" nginx -s reload
info "nginx reloaded."

# ---------------------------------------------------------------------------
# 9. DNS: add A + AAAA for leden-admin.avphilipsvanhorne.nl
# ---------------------------------------------------------------------------
TRANSIP_KEY=/opt/docker/scripts/transip_api_key.pem
TRANSIP_API=https://api.transip.nl/v6
TRANSIP_LOGIN=grmt
TRANSIP_DOMAIN=avphilipsvanhorne.nl
TRANSIP_SUBDOMAIN=leden-admin
SERVER_IPV4=31.14.99.15
SERVER_IPV6=2a01:7c8:bb02:349:5054:ff:fe6b:5973

if [[ ! -r "$TRANSIP_KEY" ]]; then
    warn "TransIP key not found at $TRANSIP_KEY — skipping DNS."
    warn "Add A/AAAA records for $TRANSIP_SUBDOMAIN.$TRANSIP_DOMAIN manually."
else
    info "Adding DNS records for $TRANSIP_SUBDOMAIN.$TRANSIP_DOMAIN..."

    _AUTH_BODY=$(mktemp)
    _SIGNATURE=$(mktemp)
    _NONCE=$(openssl rand -hex 16)

    jq -nc \
        --arg login "$TRANSIP_LOGIN" \
        --arg nonce "$_NONCE" \
        --arg label "deploy-lldap-$(date +%s)" \
        '{login: $login, nonce: $nonce, read_only: false,
          expiration_time: "30 minutes", label: $label, global_key: false}' \
        > "$_AUTH_BODY"

    openssl dgst -sha512 -sign "$TRANSIP_KEY" -binary "$_AUTH_BODY" \
        | base64 -w0 > "$_SIGNATURE"

    _TOKEN=$(curl -fsS \
        -H "Signature: $(cat "$_SIGNATURE")" \
        --data-binary "@$_AUTH_BODY" \
        "$TRANSIP_API/auth" | jq -r '.token // empty')

    rm -f "$_AUTH_BODY" "$_SIGNATURE"

    if [[ -z "$_TOKEN" ]]; then
        warn "TransIP auth failed — add DNS records manually."
    else
        for _TYPE in A AAAA; do
            if [[ "$_TYPE" == "A" ]]; then _CONTENT="$SERVER_IPV4"; else _CONTENT="$SERVER_IPV6"; fi

            _PAYLOAD=$(jq -nc \
                --arg name "$TRANSIP_SUBDOMAIN" \
                --arg type "$_TYPE" \
                --arg content "$_CONTENT" \
                --argjson ttl 3600 \
                '{dnsEntry: {name: $name, expire: $ttl, type: $type, content: $content}}')

            _RESP=$(mktemp)
            _STATUS=$(curl -sS \
                -o "$_RESP" \
                -w '%{http_code}' \
                -X POST \
                -H "Authorization: Bearer $_TOKEN" \
                -H "Content-Type: application/json" \
                --data-binary "$_PAYLOAD" \
                "$TRANSIP_API/domains/$TRANSIP_DOMAIN/dns")
            _BODY=$(cat "$_RESP"); rm -f "$_RESP"

            if [[ "$_STATUS" -ge 200 && "$_STATUS" -lt 300 ]]; then
                info "DNS $_TYPE $TRANSIP_SUBDOMAIN added (HTTP $_STATUS)"
            elif [[ "$_STATUS" -eq 409 ]]; then
                info "DNS $_TYPE $TRANSIP_SUBDOMAIN already exists — skipping."
            else
                warn "DNS $_TYPE $TRANSIP_SUBDOMAIN: HTTP $_STATUS — $_BODY"
            fi
        done
    fi
fi

# ---------------------------------------------------------------------------
# 10. Expand TLS certificate to include leden-admin.avphilipsvanhorne.nl
# ---------------------------------------------------------------------------
info "Expanding TLS certificate..."
if (cd "$COMPOSE_DIR" && docker compose run --rm --entrypoint certbot certbot \
        certonly --webroot -w /var/www/certbot \
        --expand \
        --email clinton@xs4all.nl \
        -d avphilipsvanhorne.nl \
        -d www.avphilipsvanhorne.nl \
        -d leden-admin.avphilipsvanhorne.nl \
        --agree-tos \
        --non-interactive 2>&1); then
    info "Certificate expanded. Reloading nginx..."
    docker exec "$NGINX_CONTAINER" nginx -s reload
else
    warn "Certificate expansion failed (DNS may not have propagated yet)."
    warn "Once DNS is live, re-run manually:"
    warn "  cd $COMPOSE_DIR && docker compose run --rm --entrypoint certbot certbot \\"
    warn "    certonly --webroot -w /var/www/certbot --expand --email clinton@xs4all.nl \\"
    warn "    -d avphilipsvanhorne.nl -d www.avphilipsvanhorne.nl -d leden-admin.avphilipsvanhorne.nl \\"
    warn "    --agree-tos --non-interactive"
    warn "  docker exec $NGINX_CONTAINER nginx -s reload"
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
echo ""
echo -e "${GREEN}=== Deployment complete ===${NC}"
echo ""
echo "  LLDAP web UI : https://leden-admin.avphilipsvanhorne.nl"
echo "  LLDAP login  : admin / <password you just set>"
echo ""
echo "Next steps:"
echo "  1. Open https://leden-admin.avphilipsvanhorne.nl and create groups 'leden' and 'ex-leden'"
echo "  2. Run the member import:"
echo "       python3 scripts/import-avpvh-members.py /path/to/ledenlijst.xlsx"
echo "  3. Copy the plugin to WordPress:"
echo "       sudo cp -r $REPO_DIR /opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members"
echo "  4. Activate the plugin:"
echo "       docker exec scripts-wpcli-pvh-1 wp plugin activate avpvh-members --allow-root"
echo "  5. Grant the WordPress DB user SELECT on lldap (already done above)"
echo "     and add to wp-config.php if using a non-default LLDAP DB name:"
echo "       define('AVPVH_LLDAP_DB', 'lldap');"
echo ""

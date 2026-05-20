#!/usr/bin/env bash
set -euo pipefail

API_URL="https://api.transip.nl/v6"
LOGIN="grmt"
PRIVATE_KEY_PATH="/opt/docker/scripts/transip_api_key.pem"
DOMAIN="avphilipsvanhorne.nl"
NEW_SPF="v=spf1 include:_spf.google.com include:_spf.transip.email ~all"

make_token() {
    local nonce label auth_response token
    nonce="$(openssl rand -hex 16)"
    label="spf-update-$(date +%s)"
    AUTH_BODY="$(mktemp)"
    SIGNATURE="$(mktemp)"

    jq -nc \
        --arg login "$LOGIN" \
        --arg nonce "$nonce" \
        --arg label "$label" \
        --argjson global_key false \
        '{login: $login, nonce: $nonce, read_only: false, expiration_time: "5 minutes", label: $label, global_key: $global_key}' \
        > "$AUTH_BODY"

    openssl dgst -sha512 -sign "$PRIVATE_KEY_PATH" -binary "$AUTH_BODY" \
        | base64 -w0 > "$SIGNATURE"

    auth_response="$(curl -fsS \
        -H "Signature: $(cat "$SIGNATURE")" \
        --data-binary "@$AUTH_BODY" \
        "$API_URL/auth")"

    token="$(printf '%s' "$auth_response" | jq -r '.token // empty')"
    rm -f "$AUTH_BODY" "$SIGNATURE"
    printf '%s' "$token"
}

TOKEN="$(make_token)"

# 1. Delete old SPF record
echo "Deleting old SPF record..."
curl -sS -X DELETE \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    --data '{"dnsEntry": {"name": "@", "expire": 300, "type": "TXT", "content": "v=spf1 include:_spf.google.com ~all"}}' \
    "$API_URL/domains/$DOMAIN/dns"

# 2. Add new SPF record
echo "Adding new SPF record..."
curl -sS -X POST \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    --data "{\"dnsEntry\": {\"name\": \"@\", \"expire\": 300, \"type\": \"TXT\", \"content\": \"$NEW_SPF\"}}" \
    "$API_URL/domains/$DOMAIN/dns"

# 3. Add x-transip-mail-auth record
echo "Adding x-transip-mail-auth record..."
curl -sS -X POST \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    --data '{"dnsEntry": {"name": "x-transip-mail-auth", "expire": 300, "type": "TXT", "content": "3be01aef5af377efe30a7fc500855a06f5f81001a6b1dafc4a662efb380f6cc1"}}' \
    "$API_URL/domains/$DOMAIN/dns"

echo "SPF record updated successfully."

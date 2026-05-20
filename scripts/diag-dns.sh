#!/usr/bin/env bash
set -euo pipefail

API_URL="https://api.transip.nl/v6"
LOGIN="grmt"
PRIVATE_KEY_PATH="/opt/docker/scripts/transip_api_key.pem"
DOMAIN="avphilipsvanhorne.nl"

make_token() {
    local nonce label auth_response token
    nonce="$(openssl rand -hex 16)"
    label="diag-$(date +%s)"
    AUTH_BODY="$(mktemp)"
    SIGNATURE="$(mktemp)"

    jq -nc \
        --arg login "$LOGIN" \
        --arg nonce "$nonce" \
        --arg label "$label" \
        --argjson global_key false \
        '{login: $login, nonce: $nonce, read_only: true, expiration_time: "5 minutes", label: $label, global_key: $global_key}' \
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
curl -fsS -H "Authorization: Bearer $TOKEN" "$API_URL/domains/$DOMAIN/dns" | jq .

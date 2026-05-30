# Plan: Migratie naar OpenResty + JWT-validatie voor private uploads

## Doel
Vervang `nginx:stable-alpine` door `openresty/openresty:alpine` zodat nginx
private uploads cryptografisch kan verifiëren zonder roundtrip naar Authelia of PHP.

---

## Achtergrond

De huidige situatie voor private uploads:
```
Browser → nginx: GET /wp-content/uploads/private/foto.jpg
nginx checkt: bevat $http_cookie "wordpress_logged_in"?
  Ja → serveer bestand direct
  Nee → 302 naar /avpvh-login/
```
Probleem: cookie-aanwezigheid is geen cryptografische verificatie. Iedereen
die de cookienaam kent kan een nep-cookie sturen.

Met OpenResty + Lua kan nginx een JWT valideren zonder roundtrip.

---

## Aanpak

### Stap 1 — Docker: nginx → OpenResty

In `/opt/docker/scripts/docker-compose.yml`:
```yaml
# Oud:
image: nginx:stable-alpine
# Nieuw:
image: openresty/openresty:alpine
```

OpenResty is nagenoeg 100% compatible met standaard nginx-configuratie.
Controleer of alle `include`-paden nog kloppen (config root blijft `/etc/nginx/`).

**Risico:** laag. OpenResty ship standaard nginx-modules + LuaJIT.

---

### Stap 2 — WordPress: JWT-cookie bij inloggen

In de avpvh-members plugin (`class-access.php` of nieuw `class-media-token.php`):

```php
// Na succesvolle WordPress-login
add_action('wp_login', function(string $login, WP_User $user) {
    if (!avpvh_is_active_member($user)) return;

    $secret  = defined('AVPVH_JWT_SECRET') ? AVPVH_JWT_SECRET : AUTH_KEY;
    $payload = base64_encode(json_encode([
        'sub' => $user->ID,
        'exp' => time() + 8 * 3600,  // 8 uur (zelfde als Authelia sessie)
        'iat' => time(),
    ]));
    $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $sig     = base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    $token   = "$header.$payload.$sig";

    setcookie('avpvh_media_token', $token, [
        'expires'  => time() + 8 * 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}, 10, 2);
```

De `AVPVH_JWT_SECRET` constante wordt via een Docker secret beschikbaar gemaakt
(zie Stap 3). Bij uitloggen cookie wissen.

---

### Stap 3 — Gedeeld JWT-secret via Docker secret

In `docker-compose.yml`:
```yaml
secrets:
  avpvh_jwt_secret:
    file: /opt/docker/secrets/compose/avpvh_jwt_secret.txt

services:
  nginx:
    secrets:
      - avpvh_jwt_secret

  wordpress-pvh:
    secrets:
      - avpvh_jwt_secret
    environment:
      WORDPRESS_CONFIG_EXTRA: |
        define('AVPVH_JWT_SECRET', trim(file_get_contents('/run/secrets/avpvh_jwt_secret')));
```

Secret aanmaken:
```bash
openssl rand -hex 32 > /opt/docker/secrets/compose/avpvh_jwt_secret.txt
```

---

### Stap 4 — OpenResty Lua: JWT-validatie voor private uploads

Nieuw bestand: `/opt/docker/volumes/nginx/lua/validate_media_token.lua`

```lua
local secret_file = io.open("/run/secrets/avpvh_jwt_secret", "r")
local secret = secret_file:read("*l")
secret_file:close()

local cookie = ngx.var.cookie_avpvh_media_token
if not cookie then
    return ngx.redirect("/avpvh-login/")
end

-- Parse JWT
local header, payload, sig = cookie:match("^([^.]+)%.([^.]+)%.([^.]+)$")
if not header or not payload or not sig then
    return ngx.redirect("/avpvh-login/")
end

-- Verify HMAC-SHA256 signature
local hmac = require "resty.hmac"
local expected = ngx.encode_base64(
    hmac:new(secret, hmac.ALGOS.SHA256):final(header .. "." .. payload)
)
if sig ~= expected then
    return ngx.redirect("/avpvh-login/")
end

-- Check expiry
local ok, data = pcall(function()
    return require("cjson").decode(ngx.decode_base64(payload))
end)
if not ok or not data.exp or data.exp < ngx.time() then
    return ngx.redirect("/avpvh-login/")
end
-- Validated — allow request to continue
```

In `avpvh.conf`:
```nginx
location ~* ^/wp-content/uploads/private/ {
    access_by_lua_file /etc/nginx/lua/validate_media_token.lua;
    rewrite ^/wp-content/(?<rest>.*) /wp-content-pvh/$rest last;
}
```

---

## Volgorde van uitvoering

1. Genereer JWT secret: `openssl rand -hex 32 > /opt/docker/secrets/compose/avpvh_jwt_secret.txt`
2. Update `docker-compose.yml`: image + secrets
3. Voeg Lua-script toe aan nginx-volume
4. Update `avpvh.conf`: vervang cookie-check door `access_by_lua_file`
5. Voeg JWT-cookie logica toe aan WordPress-plugin
6. Deploy: `docker compose up -d nginx wordpress-pvh`
7. Test: inloggen → private upload → token gevalideerd zonder roundtrip

---

## Resultaat

| Situatie | Huidig | Na migratie |
|----------|--------|-------------|
| Niet ingelogd → private upload | 302 (cookie check) | 302 (JWT ontbreekt/ongeldig) |
| Ingelogd lid → private upload | Serveer (cookie aanwezig) | Serveer (JWT cryptografisch geverifieerd) |
| Roundtrip | Geen | Geen |
| Verificatie | Aanwezigheid cookie | HMAC-SHA256 handtekening |

---

## Notities

- OpenResty levert `lua-resty-hmac` en `lua-resty-string` standaard mee.
- JWT-expiry synchroon houden met WordPress sessieduur (8 uur).
- Bij wachtwoord-reset of force-logout: cookie wissen + optioneel secret rouleren.
- `resty.hmac` module beschikbaar in `openresty/openresty:alpine` image.

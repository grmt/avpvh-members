<?php
// mu-plugin for the tenant e2e test: Google and Microsoft are idp.php.
add_filter('avpvh_tenant_oauth_providers', static function (array $providers): array {
    foreach ($providers as $name => $provider) {
        $providers[$name]['auth_url']  = "http://localhost:8089/$name/authorize";
        $providers[$name]['token_url'] = "http://idp:9092/$name/token";
    }
    return $providers;
});

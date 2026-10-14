<?php
return [
    'tenant_id' => 'rsp_vve_vp4042',
    'ancestors' => ['rsp', 'rsp_vve'],
    'canonical_host' => 'localhost',
    'authelia_url' => 'https://auth.example.invalid',
    'authelia_authz_url' => 'http://authz:9091/',
    'authelia_session_cookie' => 'test_session',
    'member_group' => 'vp4042-leden',
    'admin_group' => 'rsp-vve-vp4042-admins',
    'ancestor_admin_groups' => ['rsp-admins', 'rsp-vve-admins'],
    'members_only_pages' => ['leden'],
    'navigation_refs' => 'all',
];

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
    'navigation_refs' => 'header',
    'plugin_locale' => 'nl_NL',
    'shares' => [
        'testbestand' => [
            'title' => 'Testbestand',
            'file' => 'test bestand.zip',
            'emails' => ['Gast@Example.invalid'],
        ],
    ],
    'directory' => [
        'url' => 'ldap://openldap:1389',
        'bind_dn' => 'cn=tenant-reader,dc=nl',
        'bind_password_file' => '/tmp/reader',
        'base_dn' => 'dc=nl',
    ],
    'oauth' => [
        'google' => ['client_id' => 'test-google', 'client_secret' => 'test-secret-google'],
        'microsoft' => ['client_id' => 'test-microsoft', 'client_secret_file' => '/tmp/microsoft-secret'],
    ],
];

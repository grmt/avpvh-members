# Plan: LLDAP vervangen door OpenLDAP

Status: fase 0 uitgevoerd (2026-10-03, docker-scripts PR #40): eigen
WordPress-image met `ldap`-extensie voor `wordpress-pvh`/`wpcli-pvh`,
serviceaccount `cn=avpvh-admin,dc=nl` met secret
`openldap_avpvh_admin_password`, placeholder `cn=vacant,ou=avpvh,dc=nl` en
de ACL-regel voor `ou=avpvh`; grenstest op live geslaagd. Fase 1 e.v. nog
niet begonnen.

## Besluit

- **OpenLDAP wordt de enige identiteitsbron** (accounts, e-mail, naam,
  wachtwoorden, groepen) voor avpvh. LLDAP verdwijnt volledig.
- OpenLDAP bewaart zijn data in LMDB (`back-mdb`), niet in MariaDB. Een SQL-
  backend (`back-sql`) is sinds OpenLDAP 2.5 "experimental, deprecated" en
  MariaDB kan LDAP niet als externe tabel lezen. Daarom **synchroniseert de
  plugin een cache** van de identiteitsgegevens naar MariaDB. Geen voorkeur,
  maar de enige robuuste route.
- De regel in GEMINI.md ("e-mail nooit in WordPress-tabellen") wordt:
  *OpenLDAP is de bron; WordPress houdt alleen een alleen-lezen cache bij die
  de plugin zelf vult. Schrijven gaat altijd naar OpenLDAP.*

## Huidige situatie

- Authelia logt sinds de cutover (docker-scripts PR #31) in tegen OpenLDAP
  (`ldap://openldap:1389`, root `dc=nl`), met per tenant een eigen boom:
  `ou=avpvh,dc=nl`, `ou=rechtspreker,dc=nl` (met `ou=vve`, `ou=vp40-42`).
- De plugin praat nog uitsluitend met LLDAP:
  - `AVPVH_LLDAP` (GraphQL, als LLDAP-root `admin`) voor accounts en groepen:
    `get_user_groups`, `update_user`, `add_to_group`, `remove_from_group`,
    `get_all_group_memberships`, `list_groups`, `get_user_display_name`,
    `create_user`, `test_connection_with`;
  - `AVPVH_DB::member_select()` en verwante queries JOINen elk lid met
    `lldap.users` (e-mail, naam) via `AVPVH_LLDAP_DB`;
  - OAuth-login matcht het provider-adres tegen `lldap.users.lowercase_email`.
- Gevolg: alles wat de plugin na de cutover schreef staat alleen in LLDAP.
  Vergelijking op 2026-10-02 (alleen tellingen):
  - OpenLDAP `ou=avpvh`: 391 accounts; LLDAP: 396 (waarvan 1 `admin`, 4 VvE-
    accounts die in `ou=vve` horen, en **1 lid dat na de cutover via Nieuw lid
    is aangemaakt**);
  - groepen gelijk, behalve **1 bestuurswijziging** (alleen in LLDAP verwerkt)
    en de leden-groep van dat nieuwe lid.
- Slechts 4 accounts in OpenLDAP hebben een wachtwoord: LLDAP-hashes waren
  niet over te zetten. Geen probleem: de overige leden hebben nooit met een
  wachtwoord ingelogd (leden loggen in via Google/Microsoft). Wie later toch
  een wachtwoord nodig heeft gebruikt "wachtwoord vergeten" in Authelia.
- `leden-admin.avphilipsvanhorne.nl` is de LLDAP-webinterface. Na de migratie
  is er geen LDAP-beheerinterface meer; beheer gaat via de plugin.

## Technische randvoorwaarden

1. **PHP LDAP-extensie ontbreekt** in `wordpress:fpm-alpine`. Opties:
   - a. eigen image: `FROM wordpress:fpm-alpine` + `docker-php-ext-install ldap`
     (met `openldap-dev`), gebouwd in docker-scripts. Standaard en snel; vraagt
     een rebuild bij elke WordPress-image-update. **Voorkeur.**
   - b. pure-PHP client (bijv. FreeDSx/LDAP) in de plugin meeleveren. Geen
     image-wijziging, wel een extra afhankelijkheid in de plugin.
2. **Groepen zijn `groupOfNames`**: `member` is verplicht. Een lege groep
   (vacante voorzitter/secretaris/penningmeester na aftreden) wordt door
   OpenLDAP geweigerd. Oplossing: een vaste placeholder-member
   `cn=vacant,ou=avpvh,dc=nl` (bestaat niet als persoon; Authelia's
   `(member={dn})` matcht er nooit op). De plugin voegt die toe vóór het
   verwijderen van het laatste echte lid en haalt hem weg bij een nieuw lid,
   en negeert hem bij het lezen.
3. **Eigen serviceaccount** `cn=avpvh-admin,dc=nl` met `manage` op
   `ou=avpvh` en niets daarbuiten, zelfde patroon als `vve-admin` (docker-
   scripts PR #29), inclusief grenstest: geen toegang tot `ou=rechtspreker`
   en tot `dc=nl` zelf. Wachtwoord alleen in een Docker-secret, nooit in de
   repo of de WP-database. Vervangt het gebruik van LLDAP-root `admin`.
   Het account staat buiten zijn eigen boom (net als `vve-admin`), zodat het
   zichzelf niet kan wijzigen. Uitvoering: `openldap-avpvh-admin.sh` in
   docker-scripts (branch `feat/openldap-avpvh-admin`), door de beheerder op
   de server gedraaid.
4. Group-ID's: LLDAP gebruikt numerieke ID's, OpenLDAP DN's/`cn`. De plugin
   gaat overal met groepsnamen (`cn`) werken (o.a. Ledendetail-checkboxes,
   `handle_save_groups()`, `AVPVH_Roles`).

## Architectuur

- **`AVPVH_Directory`**: één klasse met dezelfde verantwoordelijkheden als
  `AVPVH_LLDAP`, maar op groepsnaam: `get_user`, `create_user`,
  `update_user`, `delete_user`, `list_groups`, `get_user_groups`,
  `get_all_group_memberships`, `add_to_group(uid, cn)`,
  `remove_from_group(uid, cn)`, `test_connection`. Implementatie met
  `ldap_*` tegen `ldap://openldap:1389`, bind als `avpvh-admin`, base
  `ou=avpvh,dc=nl`. Configuratie via constants in `wp-config.php`
  (`AVPVH_LDAP_URL`, `AVPVH_LDAP_BIND_DN`, `AVPVH_LDAP_PASSWORD_FILE`).
- **Cache-tabel** `pvh_avm_directory_users`:
  `uid` (PK), `mail`, `lowercase_mail` (index), `display_name`,
  `entry_uuid`, `synced_at`.
  - write-through: elke schrijfactie van de plugin werkt na een geslaagde
    LDAP-write direct de cache bij;
  - volledige sync elke 15 minuten via WP-cron (alleen `ou=people,ou=avpvh`),
    plus een knop "Nu synchroniseren" op Instellingen;
  - bij Authelia-login en OAuth-login wordt dat ene account ververst;
  - verwijderde LDAP-accounts worden uit de cache verwijderd; leden zonder
    LDAP-account verdwijnen dan uit lijsten, zoals nu bij een ontbrekende
    `lldap.users`-rij.
- `member_select()` en verwante queries JOINen op de cache-tabel in plaats
  van `lldap.users`; verder ongewijzigd (zoeken/sorteren op e-mail blijft SQL).
- Groepslidmaatschap blijft live uit LDAP met de bestaande transient-cache
  (`avpvh_all_group_memberships`, 15 min, gewist bij elke wijziging).
- Status-sync (`leden` / `ex-leden`) en rollen (`AVPVH_Roles`) blijven
  hetzelfde, maar via `AVPVH_Directory`.

## Fasering

Elke fase is los te deployen en terug te draaien.

0. **Voorbereiding (infra, docker-scripts)**
   - eigen WordPress-image met `ldap`-extensie (of besluit voor optie b);
   - serviceaccount `avpvh-admin` + ACL + grenstest;
   - placeholder `cn=vacant,ou=avpvh,dc=nl`.
1. **Code achter een schakelaar** (`AVPVH_DIRECTORY_BACKEND` = `lldap` |
   `openldap`, standaard `lldap`)
   - `AVPVH_Directory` + OpenLDAP-implementatie, LLDAP-implementatie als
     adapter rond de bestaande code;
   - cache-tabel (DB-migratie; let op: versie 2.19 is al dubbel in gebruik,
     neem de eerstvolgende vrije) + sync;
   - callers omzetten van group-ID's naar groepsnamen;
   - testscripts in `scripts/` (lezen, schrijven in een testgroep, vacature-
     placeholder, grenstest vanuit de plugin).
2. **Gegevens gelijktrekken** (read-only vergelijking, dan replay)
   - `scripts/compare-directories.php`: accounts, e-mail, naam en
     groepslidmaatschap LLDAP vs OpenLDAP, alleen tellingen en member-ID's;
   - writes van na de cutover alsnog in OpenLDAP doorvoeren (nu: 1 nieuw
     account + groep, 1 bestuurswijziging); vlak vóór fase 3 opnieuw draaien.
3. **Omschakelen**: schakelaar naar `openldap`, cache vullen, controleren
   (ledenlijst, Ledendetail, Rollen & delegatie, Bestuur-pagina, OAuth-login,
   Authelia-login, Nieuw lid met testlid). Terugdraaien = schakelaar terug.
4. **LLDAP opruimen** (na een stabiele periode)
   - plugin: `class-lldap.php`, `AVPVH_LLDAP_DB`, `avpvh_lldap_*`-opties,
     schakelaar en LLDAP-adapter weg; GEMINI.md, AGENTS.md, README.md bijwerken;
   - scripts: `deploy-lldap.sh`, `manage-lldap-group.sh`, `test-user.sh`,
     `backfill-member-identities.sh`, importscripts omzetten of schrappen;
   - docker-scripts: `lldap`-service, `leden-admin`-vhost, secrets
     `lldap_jwt_secret`, `lldap_admin_password`, `authelia_lldap_password`;
     MariaDB-database `lldap` en de SELECT-grant voor `wp_user`;
   - VvE-accounts staan al in `ou=vve`; controleren dat rechtspreker niets
     meer uit LLDAP leest.

## Risico's en open punten

- `bitnamilegacy/openldap` krijgt geen updates meer; met LLDAP weg wordt
  OpenLDAP een blijvende afhankelijkheid. Overweeg een eigen image op
  Debian-pakketten (al genoemd in de docker-scripts-commit van de cutover).
- Hoofdlettergebruik van uid's: plugin vergelijkt case-insensitive, LDAP-DN's
  ook; bij het omzetten consequent lowercase gebruiken.
- 1 account in `ou=avpvh` heeft geen `mail`; controleren of dat een
  placeholder-account is.
- Back-ups: OpenLDAP-volume (`/opt/docker/volumes/openldap`) moet in de
  back-up zitten zoals nu de `lldap`-database.
- Tot fase 3 schrijft de plugin nog naar LLDAP; elke wijziging die dan via de
  plugin gebeurt moet in fase 2 opnieuw worden meegenomen.

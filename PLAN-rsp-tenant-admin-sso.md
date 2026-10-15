# Plan: Rechtspreker-SSO en hiërarchische tenantbeheerders

## Status

Fase 1–4 geïmplementeerd in de plugin (`includes/tenant/`), getest met
`scripts/test-tenant-sso.php`, `scripts/test-tenant-bootstrap.php` en de
end-to-end-test `scripts/tenant-e2e/run.sh` (echte WordPress, nep-Authelia,
fictieve accounts). Fase 5 (activering op VP40-42) staat hieronder onder
"Uitrol op VP40-42" en vraagt eerst de genoemde infrawijzigingen.

## Uitvoering (zoals gebouwd)

- **Grens AVP/generiek.** `avpvh-members.php` laadt bij een tenantconfig
  alleen `includes/tenant/` (plus vertalingen) en stopt dan: geen
  `AVPVH_DB`, geen activation hook, geen `pvh_avm_*`-tabellen. Alle andere
  classes in `includes/` gebruiken AVP-businessdata of AVP-specifieke
  constants (page-ID's, `auth.avphilipsvanhorne.nl`) en blijven AVP-only.
- **Configuratie.** `wp-content/avpvh-tenant.php` geeft een array terug (of
  de constant `AVPVH_TENANT_CONFIG` in wp-config.php). Het bestand heeft de
  voorkeur: de WordPress- en WP-CLI-container delen `wp-content`, maar niet
  `WORDPRESS_CONFIG_EXTRA`; een WP-CLI zonder tenantconfig zou de site in
  AVP-modus starten en tabellen aanmaken. Validatie bij elke start (tenant-ID,
  ancestorketen, canonieke host = `home_url()`, https-portal, groepsnamen,
  één beheergroep per ancestor, leden- ≠ beheergroep); ongeldig → geen login
  of provisioning, wel een melding in wp-admin.
- **Identiteit zonder LDAP.** De WordPress-container heeft geen
  `ldap`-extensie nodig: de plugin stuurt de Authelia-sessiecookie van de
  bezoeker server-side naar Authelia's `/api/authz/auth-request` voor het
  probepad (`/avpvh-sso/`) en leest `Remote-User`/`Remote-Groups`/
  `Remote-Email`/`Remote-Name` uit het antwoord. Clientheaders worden nooit
  gelezen. Fout of time-out → geen sessie.
- **Provisioning.** Lokale user gekoppeld via usermeta
  `avpvh_directory_subject` = `<tenant_id>/<uid>`; een bestaande lokale login
  of e-mailadres wordt nooit overgenomen (409, beheerder koppelt expliciet
  met `wp user meta add <id> avpvh_directory_subject <tenant_id>/<uid>`).
  Een optie-lock voorkomt dubbele users bij gelijktijdige eerste logins.
- **Rollen.** Eigen of ancestor-beheergroep → `administrator`, ledengroep →
  `subscriber`, anders geen sessie. Een door de plugin gezette rol volgt de
  groepen, ook bij intrekking; een met de hand gewijzigde rol blijft staan.
  Elke `recheck_seconds` (standaard 300) vraagt de plugin Authelia opnieuw;
  ander account, verlopen Authelia-sessie of fout → WordPress-uitlog.
- **Afgeschermde inhoud.** `members_only_pages` (slugs) plus alle
  onderliggende pagina's: alleen voor `content_groups` (standaard de
  ledengroep; beheerders dus niet automatisch). Verborgen in menu's
  (klassiek en Navigation-block), paginalijsten, zoekresultaten, feeds,
  REST (lijst, item, search) en sitemaps; direct bezoek → login of 403.
- **Login-/logoutlink.** "Inloggen"/"Uitloggen" met persoonsicoon aan het
  eind van de geconfigureerde menulocaties (`menu_locations`) en/of
  Navigation-blocks (`navigation_refs`: ID's, `'all'`, of `'header'` voor
  de Navigation in het header-template-part, die vaak geen ref heeft). Uitloggen beëindigt ook de
  Authelia-sessie (en daarmee SSO op alle Rechtspreker-sites).
- **Taal.** De AVP-taalwisselaar wordt niet geladen (die zet de locale
  van de hele site). `plugin_locale` bepaalt alleen de taal van de
  plugin-teksten, bv. `nl_NL` op een site met Engelse beheertaal.
- **Bestanden delen.** `shares` in het tenantbestand (titel, bestandsnaam
  in `uploads/private/delen/`, toegestane adressen; nooit in de repo):
  `/?delen=<id>` geeft een formulier, een toegestaan adres of een lid (ook
  als het alleen bij Authelia is ingelogd) krijgt een downloadlink die 24
  uur geldig is. Max. 10 foute adressen per 10 minuten per IP; downloads
  worden alleen gehasht gelogd.
- **Noodtoegang.** Zolang `local_login` true is (standaard) blijft
  `wp-login.php` werken voor lokale accounts; die worden niet herchecked.
  Laatste redmiddel: `wp plugin deactivate avpvh-members` in de
  WP-CLI-container.
- **Logging.** Alleen tenant-ID, stap, sha256-prefix van de uid en de
  beslissing (`[avpvh-tenant] ...` in de PHP-errorlog).

## Uitrol op VP40-42

Vooraf (infra, buiten deze repo):

1. **Authelia** (`vp40-42.vve.rechtspreker.nl`): een regel
   `^/avpvh-sso/` met `policy: one_factor` zonder `subject`, vóór de
   afsluitende bypass; de `^/wp-admin/.*`-regel ook voor de drie
   beheergroepen (`group:rsp-admins`, `group:rsp-vve-admins`,
   `group:rsp-vve-vp4042-admins`) naast `group:vp4042-leden`.
2. **OpenLDAP**: de groepen `rsp-admins`, `rsp-vve-admins` en
   `rsp-vve-vp4042-admins` (met de `cn=vacant`-placeholder zolang leeg) en
   fictieve testaccounts voor iedere rij van de testmatrix.
3. **Tenantbestand** `wp-content-vp4042/avpvh-tenant.php` met
   `canonical_host` `vp40-42.vve.rechtspreker.nl`, `authelia_url`
   `https://auth.rechtspreker.nl`, `authelia_authz_url`
   `http://authelia:9091/api/authz/auth-request`, de sessiecookienaam van
   het Rechtspreker-sessiedomein, de slugs van de leden- en zoekpagina en de
   menulocatie of Navigation-block van het thema.

Daarna, volgens fase 5:

1. Plugin rsyncen naar `wp-content-vp4042/plugins/avpvh-members/` (inactief)
   en `wp eval-file .../includes/tenant/healthcheck.php` in `wpcli-vp4042`.
2. Activeren; de tijdelijke mu-plugins blijven eerst staan.
3. Testmatrix met de fictieve accounts doorlopen, inclusief intrekking.
4. Daarna, als afzonderlijke stap: beide mu-plugins verwijderen
   (`vp4042-login-menu.php`, `vp4042-bestand-delen.php`); de plugin doet
   login-menu, afscherming en `/?delen=`. De bestanden in
   `uploads/private/` blijven staan.

## Vervolg: inloggen met Google of Microsoft

Status: fase 1 gebouwd. Dat is `AVPVH_Tenant_Directory`
(`includes/tenant/class-tenant-directory.php`): het optionele
`directory`-blok in het tenantbestand en een regel in de healthcheck. Getest
met `scripts/tenant-e2e/directory/run.sh`, met een OpenLDAP en fictieve
accounts. Fase 2–4 volgen.

### Aanleiding

De meeste VvE-leden hebben al een Google- of Microsoft-account. Laat ze daarmee
inloggen, naast de Authelia-login met gebruikersnaam en wachtwoord. Nu laadt
de tenant-modus `AVPVH_OAuth` niet. Die class koppelt de geverifieerde
e-mail aan AVP-ledendata (`avm_member_identities`), en een tenant heeft
alleen directory-accounts.

Stand in de directory (oktober 2026, alleen tellingen): `vp4042-leden` heeft 6
leden, elk met één `mail`. Eén daarvan is een gmail-adres, één een
hotmail-adres en vier zitten bij een andere provider. Wie bij zo'n andere
provider zit, kan toch een Google- of Microsoft-account op dat adres hebben,
want beide staan accounts op een bestaand e-mailadres toe. Zo niet, dan zet
een beheerder een extra `mail`-waarde (gmail/hotmail) bij het account. `mail`
mag meerdere waarden hebben.

### Ontwerp

1. **Knoppen.** De loginpagina en het accountmenu krijgen "Inloggen met
   Google" en "Inloggen met Microsoft". De Authelia-login blijft gewoon
   bestaan. Microsoft gebruikt het `consumers`-endpoint (persoonlijke
   accounts: hotmail.com, outlook.com, live.com), net als op de AVP-site.
2. **OAuth-flow in `includes/tenant/`.** Authorization code met PKCE, `state`
   en `nonce` in een korte transient, callback op
   `/wp-json/avpvh/v1/tenant-oauth/<provider>/callback`. Alleen een e-mailadres
   dat de provider als geverifieerd meldt telt: bij Google `email_verified`,
   bij Microsoft het adres uit het ID-token van een consumer-account. Client-ID
   en secret staan in het tenantbestand op de server, nooit in git.
3. **Koppelen aan de directory.** De plugin zoekt
   `(&(objectClass=inetOrgPerson)(mail=<adres>))` (geëscaped,
   hoofdletterongevoelig) onder de geconfigureerde basis-DN. Alleen bij
   precies één treffer gaat het verder, en anders volgt een duidelijke melding
   zonder te verraden of het adres bestaat. Daarna haalt de plugin de groepen
   op met `(member=<dn>)`. De rol volgt uit dezelfde
   `AVPVH_Tenant_SSO::role_for_groups()` als bij Authelia, en provisioning
   gebruikt hetzelfde `avpvh_directory_subject` (`<tenant>/<uid>`). Daardoor
   komt iemand via Google, Microsoft of Authelia in hetzelfde WordPress-account.
4. **Sessie en intrekken.** De WordPress-sessie onthoudt dat hij via OAuth is
   gestart. De bestaande controle om de 300 seconden vraagt dan niet Authelia,
   maar de directory: zijn de groepen nog goed? Zo niet, dan volgt uitloggen
   of een lagere rol, net als nu. Uitloggen beëindigt alleen de
   WordPress-sessie; er is geen Authelia-sessie.
5. **Privébestanden.** nginx laat `uploads/private/` nu alleen door met een
   Authelia-sessie. Wie via OAuth inlogt, heeft die niet. Daarom gaat de
   `auth_request` van die locatie naar een plugin-endpoint
   (`/avpvh-file-auth/`, via PHP-FPM). Dat geeft 204 voor wie de ledenpagina's
   mag lezen: een WordPress-sessie met de ledengroep, of een Authelia-sessie
   met die groep (via de bestaande probe). Alle anderen krijgen 401 met een
   redirect naar de login. nginx levert het bestand daarna zelf, zodat grote
   zips niet door PHP gaan.
6. **wp-admin.** Beheerders blijven via Authelia met 2FA inloggen. Google en
   Microsoft geven op deze route alleen leestoegang (subscriber), ook als de
   directory de persoon als beheerder kent. Zo blijft 2FA voor beheer
   afgedwongen.

### Infrastructuur (buiten deze repo)

- **PHP `ldap`-extensie** in de WordPress- en WP-CLI-image van de tenant.
- **Directory-serviceaccount** met alleen leesrechten (bind-DN plus
  wachtwoord als Docker-secret) op de people-OU's en groepen die de tenant
  nodig heeft. Dat zijn ook AVP-accounts die lid zijn van `vp4042-leden`.
- **nginx:** de `auth_request` voor `uploads/private/` gaat naar het
  plugin-endpoint in plaats van naar Authelia.
- **Google Cloud Console en Azure-app-registratie:** de redirect-URI's per
  tenant toevoegen, of per tenant een eigen client.

### Fasering

1. Directory-lookup met tests tegen de OpenLDAP-testcontainer
   (`scripts/test-directory-openldap.php`-stijl) en de config-validatie.
2. OAuth-flow met nep-provider in de tenant-e2e (`scripts/tenant-e2e/`):
   geverifieerd/ongeverifieerd adres, geen of meer treffers, lid,
   beheerder (blijft subscriber) en buitenstaander, intrekking via
   groepswijziging.
3. Endpoint voor privébestanden en een e2e-test van de nginx-route
   (lid via OAuth, lid via Authelia, buitenstaander, anoniem).
4. Uitrol op VP40-42, eerst alleen Google of alleen Microsoft, met één
   proefpersoon.

### Open vragen

- Moeten beheerders via Google/Microsoft ook wp-admin kunnen bereiken? Dan
  is 2FA bij de provider niet af te dwingen. Het voorstel is: nee.
- Eén OAuth-client voor alle Rechtspreker-tenants, of één per tenant?
- Mogen VvE-leden die niet in de directory staan (bijvoorbeeld alleen een
  ontvanger van een gedeeld bestand) zichzelf aanmelden? Het voorstel is:
  nee, een beheerder maakt het account aan.

## Doel

Maak één configureerbare WordPress-authenticatie- en provisioninglaag voor de
Rechtspreker-hiërarchie:

```text
RSP
└── VvE
    └── VP40-42
```

- Een RSP-beheerder beheert RSP, VvE en VP40-42.
- Een VvE-beheerder beheert VvE en VP40-42, maar niet RSP.
- Een VP40-42-beheerder beheert alleen VP40-42.
- Een gewoon lid krijgt geen WordPress-beheerrechten.

Dit document behandelt de applicatielaag. Databases, secrets, mounts,
OpenLDAP-bootstrap, Authelia en nginx staan in `PLAN-rsp-tenant-hierarchy.md`
in de repository `web/scripts`.

## Randvoorwaarden

- Authenticatie en autorisatie blijven verschillende stappen: Authelia bewijst
  de identiteit; de plugin bepaalt de lokale WordPress-rol.
- De vertrouwde gebruikersnaam komt uitsluitend uit een door nginx gevalideerde
  proxyheader. Een rechtstreeks aangeleverde clientheader mag nooit worden
  vertrouwd.
- OpenLDAP is voor Rechtspreker de identity source of truth.
- Parentbeheer geeft geen rechtstreekse toegang tot childdatabases.
- Iedere site houdt eigen WordPress-users, capabilities, cookies en sessies.
- Lokale WordPress-wachtwoorden zijn niet de normale loginroute. Er blijft wel
  een gedocumenteerd, extra beveiligd noodaccount/herstelpad bestaan.
- Rechtspreker-functionaliteit mag niet afhankelijk worden van AVP-specifieke
  contributie-, kamp- of ledenadministratietabellen.

## Eerst te nemen architectuurbesluit

Deze repository bevat nu één AVP-PvH-plugin met veel verenigde functies. Voor
hergebruik op Rechtspreker moet de generieke identitylaag een duidelijke grens
krijgen. Voorkeursrichting:

1. isoleer login, proxyheadervalidatie, tenantconfiguratie, provisioning en
   rolsynchronisatie in een generieke module;
2. laad AVP-specifieke leden- en bedrijfsmodules alleen voor de AVP-tenant;
3. configureer RSP-, VvE- en VP40-42-installaties declaratief;
4. overweeg pas daarna of de generieke module een afzonderlijke plugin/repo
   moet worden. Kopieer geen bijna-gelijke tenantplugins.

Tot dit besluit is uitgevoerd, mag `avpvh-members` niet simpelweg op alle
Rechtspreker-sites worden geactiveerd: de bestaande bootstrap en tabellen zijn
AVP-specifiek.

## Tenantconfiguratie

Elke installatie krijgt server-side configuratie, bijvoorbeeld via constants
of een root-only bestand, met minstens:

- stabiele tenant-ID (`rsp`, `rsp_vve`, `rsp_vve_vp4042`);
- parent-ID en ancestorpad;
- canonieke hostnaam en toegestane redirecthosts;
- OpenLDAP user base en groups base;
- eigen ledengroep;
- eigen directe beheerdersgroep;
- geaccepteerde ancestorbeheerdersgroepen;
- login- en logoutbestemming;
- feature flags voor AVP-specifieke modules.

Deze waarden komen niet uit requestheaders en worden niet uitsluitend uit de
hostnaam afgeleid.

## Autorisatiemodel

Bereken voor iedere site de effectieve administratorgroepen:

| Site | Groepen die lokaal `administrator` opleveren |
|---|---|
| RSP | `rsp-admins` |
| VvE | `rsp-admins`, `rsp-vve-admins` |
| VP40-42 | `rsp-admins`, `rsp-vve-admins`, `rsp-vve-vp4042-admins` |

Ledengroepen en beheerdersgroepen blijven gescheiden. Een beheerder krijgt
alleen toegang tot afgeschermde ledeninhoud als het beleid dat expliciet via
een groep of capability toekent.

Gebruik WordPress-capabilities voor fijnmazige functies. De mapping naar de
ingebouwde rol `administrator` is alleen geschikt waar werkelijk volledig
sitebeheer nodig is.

## Login- en provisioningsflow

1. Een bezoeker kiest het accounticoon of opent een beveiligde beheerroute.
2. De site start de Rechtspreker-login bij Authelia met een gevalideerde
   same-origin return-URL.
3. Na authenticatie levert nginx een vertrouwde stabiele gebruikers-ID aan
   WordPress.
4. De plugin leest actuele OpenLDAP-groepen via een minimaal bevoegd
   serviceaccount of een aantoonbaar betrouwbare groepsheader.
5. De plugin vindt of maakt de lokale WordPress-user op basis van de stabiele
   directory-ID, niet alleen het e-mailadres.
6. De plugin synchroniseert displaygegevens en effectieve capabilities.
7. De plugin maakt de lokale WordPress-sessie en redirect uitsluitend naar een
   allowlisted lokale URL.

Bij iedere login worden rechten opnieuw berekend. Voor snelle intrekking komt
daarnaast een periodieke synchronisatie of korte cache-TTL. Fouten bij het
ophalen van groepen mogen geen nieuwe beheerrechten opleveren (fail closed).

## Uitvoeringsfasen

### 1. Generieke kern afbakenen

- Inventariseer de huidige login-, navigation-, OAuth-, proxy- en rolcode.
- Beschrijf expliciet welke classes AVP-businessdata gebruiken.
- Introduceer interfaces voor identity lookup, tenantconfiguratie en
  role/capability mapping.
- Zorg dat de generieke kern zonder `pvh_avm_*`-tabellen kan starten.
- Houd bestaand AVP-gedrag achterwaarts compatibel en gedekt door tests.

### 2. Rechtspreker-provider en veilige configuratie

- Voeg een OpenLDAP-provider toe die stabiele IDs en groepslidmaatschappen
  leest binnen de geconfigureerde subtree.
- Valideer tenant-ID, parentketen, bases, groepen en canonieke host bij startup.
- Maak identity- en groepscaches tenantgebonden; dezelfde gebruikersnaam in
  twee subtrees mag niet botsen.
- Log beslissingen met tenant-ID en geanonimiseerde/stabiele subject-ID, zonder
  e-mailadressen, tokens of persoonsgegevens te loggen.

### 3. Idempotente lokale provisioning

- Koppel een directorysubject duurzaam aan precies één lokale WordPress-user.
- Maak provisioning veilig bij herhaalde of gelijktijdige logins.
- Ken alleen allowlisted rollen/capabilities toe.
- Verwijder eerder geprovisioneerde beheerrechten wanneer groepen wijzigen,
  zonder handmatig toegekende uitzonderingen stil te overschrijven.
- Definieer lifecyclebeleid voor gedeactiveerde/verwijderde directoryaccounts
  en content ownership.

### 4. Gemeenschappelijke loginervaring

- Gebruik op alle drie de sites hetzelfde toegankelijke accounticoon en
  login-/logoutpatroon.
- Vervang op VP40-42 de tijdelijke MU-plugin die nu naar `/wp-login.php`
  verwijst zodra de volledige SSO-flow aantoonbaar werkt.
- Voorkom open redirects en behoud de bestemming binnen de eigen tenant.
- Toon een begrijpelijke foutpagina wanneer identiteit wel geldig is maar geen
  tenanttoegang bestaat.
- Houd footer-, mobiele en desktopnavigatie gedekt door DOM-/integratietests.

### 5. Gefaseerde activering

Activeer eerst VP40-42, daarna VvE en als laatste RSP:

1. Deploy de plugin inactief en valideer configuratie/healthcheck.
2. Test met uitsluitend fictieve accounts voor iedere rij in de matrix.
3. Activeer login en provisioning zonder bestaande noodtoegang te verwijderen.
4. Verifieer roltoekenning én rolintrekking.
5. Observeer loginfouten en auditlogs gedurende de afgesproken periode.
6. Verwijder daarna de tijdelijke login-MU-plugin en ongebruikte lokale
   accounts via een afzonderlijk goedgekeurde stap.

## Testmatrix

Minimaal geautomatiseerd of reproduceerbaar testen:

- RSP-admin: administrator op alle drie sites.
- VvE-admin: geweigerd op RSP; administrator op VvE en VP40-42.
- VP40-42-admin: alleen administrator op VP40-42.
- Gewoon VP40-42-lid: kan inloggen indien toegestaan, nooit administrator.
- Onbekende of gedeactiveerde gebruiker: geen lokale sessie.
- Verwijderd groepslidmaatschap: eerder geërfde rol wordt ingetrokken.
- LDAP-timeout of ongeldige response: geen privilege-escalatie.
- Gespoofte identityheader rechtstreeks van een client: wordt genegeerd.
- Redirect naar een externe host: wordt geweigerd.
- Cookies en nonces van de ene site geven geen WordPress-sessie op een andere.
- AVP-PvH-login en bestaande memberfuncties blijven ongewijzigd werken.

## Acceptatiecriteria

- De volledige beheerhiërarchie werkt volgens de testmatrix.
- Eén Authelia-login kan gebruikersgemak over Rechtspreker-hosts bieden, terwijl
  iedere WordPress-site een eigen lokale sessie uitgeeft.
- Rechten worden uit actuele OpenLDAP-groepen afgeleid en worden ook ingetrokken.
- Geen Rechtspreker-site heeft de databasecredentials van een parent of child
  nodig.
- De generieke pluginlaag start zonder AVP-businessdatatabellen.
- Het gewone VP40-42-accounticoon gebruikt de nieuwe SSO-flow; de tijdelijke
  `/wp-login.php`-oplossing is verwijderd.
- Er is een getest en gedocumenteerd nood- en rollbackpad.

## Buiten scope

- Database- en filesystemmigratie; zie het infrastructuurplan.
- WordPress Multisite.
- Gedeelde WordPress-authcookies tussen tenants.
- Automatisch toegang tot privéledeninhoud geven aan iedere beheerder.
- Wijzigingen aan `avpvh-gallery`.

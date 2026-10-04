# Plan: Rechtspreker-SSO en hiërarchische tenantbeheerders

## Status

Ontwerp; nog niet implementeren of deployen.

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

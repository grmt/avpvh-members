# Plan: naamaliases en adresnormalisatie

## Doel

Voeg aan `avpvh-members` een centrale alias- en normalisatielaag toe voor persoonsnamen en adressen. De officiële waarden blijven behouden voor weergave. Aliases worden uitsluitend gebruikt voor zoeken, synchronisatie, duplicaatdetectie en integratie met `avpvh-bookkeeping`.

## 1. Naamaliases modelleren

Voeg een tabel `avm_member_name_aliases` toe met minimaal:

- `id`
- `member_id`
- `first_name`
- `suffix`
- `last_name`
- `alias_type`: `maiden`, `married`, `nickname`, `spelling`, `abbreviation` of `historical`
- `valid_from` en `valid_until`
- `source` en `note`
- een genormaliseerde zoeksleutel
- aanmaak- en wijzigingsmetadata

Een alias hoort altijd bij één bestaand lid en mag nooit zelfstandig een nieuw lid worden. Maak de combinatie van `member_id` en genormaliseerde zoeksleutel uniek, maar sta toe dat dezelfde alias bij verschillende leden voorkomt; dat laatste moet als ambigu conflict behandeld worden.

## 2. Normalisatie centraliseren

Maak één gedeelde normalisatie-API voor ledenimport, zoeken en boekhouding:

- hoofdletters negeren;
- accenten negeren tijdens vergelijking, maar bewaren voor weergave;
- punten, koppeltekens en overtollige spaties normaliseren;
- tussenvoegsels herkennen, zowel in de losse `suffix`-kolom als in de achternaam;
- de officiële naam en alle aliases doorzoekbaar maken.

Voornaamvarianten zoals `Wil`/`Will` en meisjes-/gehuwde namen worden expliciete aliases. Leid zulke varianten niet generiek af: dat kan verschillende personen ten onrechte samenvoegen.

## 3. Veilige matchvolgorde

Gebruik bij synchronisatie deze volgorde:

1. onveranderlijke ID of geverifieerd e-mailadres;
2. exacte officiële naam;
3. exacte naamalias;
4. fuzzy overeenkomst uitsluitend als handmatige suggestie.

Als dezelfde alias bij meerdere leden voorkomt, nooit automatisch koppelen maar een conflict tonen.

Wanneer een bronnaam op een alias matcht:

- gebruik het bestaande lid;
- synchroniseer de overige toegestane gegevens;
- behoud de officiële naam;
- maak geen tweede ledenrecord;
- rapporteer dat via een alias is gematcht.

## 4. Beheerinterface

Voeg bij **Ledendetail** een onderdeel **Naamvarianten** toe met:

- alias toevoegen, wijzigen en verwijderen;
- type en eventuele geldigheidsperiode;
- bron en notitie;
- waarschuwing als een alias ook bij een ander lid voorkomt;
- zichtbare vermelding waarop een synchronisatie gematcht heeft.

Aliases moeten ook gebruikt worden door zoeken in Ledenbeheer.

## 5. Adresnormalisatie

Maak een centrale adresnormalisatie met behoud van de oorspronkelijke schrijfwijze:

- accenten, punten, hoofdletters en overtollige spaties normaliseren;
- postcodes uniform vergelijken;
- huisnummer en toevoeging afzonderlijk respecteren;
- bekende plaatsaliassen ondersteunen, zoals `Den Bosch` en `'s-Hertogenbosch`;
- straatafkortingen expliciet en lokaal vastleggen, bijvoorbeeld `F. van Pruisenweg` en `Frederika van Pruisenweg`, bij voorkeur beperkt tot postcode en plaats.

Gebruik geen brede fuzzy straatmatching: een afkorting zoals `F.` kan veel verschillende voornamen betekenen.

Overweeg afzonderlijke tabellen voor:

- globale plaatsaliassen, met land en canonieke plaatsnaam;
- straataliassen, beperkt tot land/postcode/plaats en canonieke straatnaam.

## 6. Adresgeschiedenis repareren

Pas ook de geldigheidslogica aan:

- maximaal één huidig adres per lid;
- bij een nieuw adres de vorige actuele regel automatisch afsluiten;
- overlappende actuele adressen signaleren;
- historische regels niet als huidig adres kiezen;
- imports eerst normaliseren en daarna vergelijken;
- bestaande ambiguïteiten in een dry-run rapporteren voordat gegevens worden aangepast.

## 7. API voor andere plugins

Bied vanuit `avpvh-members` stabiele methodes aan, bijvoorbeeld:

```php
AVPVH_DB::get_member_name_variants($member_id);
AVPVH_DB::find_members_by_name_or_alias($first_name, $suffix, $last_name);
AVPVH_DB::normalize_person_name($first_name, $suffix, $last_name);
AVPVH_DB::normalize_address($address);
```

`avpvh-bookkeeping` moet deze API gebruiken en geen tweede aliasadministratie onderhouden. Toon bij een match eventueel de reden, bijvoorbeeld: `gematcht via naamalias`.

## 8. Bestaande gegevens migreren

Minimaal:

- Leg de bevestigde spellingalias uit de genegeerde lokale configuratie vast bij het actieve doellid.
- Voeg meisjesnamen, gehuwde namen en roepnaam-/spellingvarianten pas na controle van de betrokken leden-ID's toe. Bewaar die leden-ID's en namen uitsluitend in een genegeerd `*.local.*`-bestand.
- Voeg geen alias toe op basis van alleen gelijkenis; verifieer eerst dat het werkelijk dezelfde persoon is.

Maak voor het inactieve duplicaat uit de lokale configuratie een veilige samenvoeg-dry-run:

- rapporteer alle afhankelijkheden;
- beoordeel de geboortedatum voor overname;
- behoud het huidige telefoonnummer en huidige adres van het doellid tenzij anders besloten;
- bewaar het oude adres eventueel als historische regel met correcte einddatum;
- controleer LLDAP-groepen en identiteit vóór verwijdering;
- verwijder niets in productie voordat de dry-run zonder conflicten slaagt.

## 9. Synchronisaties aanpassen

Pas minimaal aan:

- `scripts/reconcile-members.py`;
- de initiële ledenimport;
- historische ledenlijstimport;
- kamp-/activiteitimports die rechtstreeks op naam zoeken;
- de dubbele-ledenwaarschuwing in het beheerscherm.

Vervang de huidige lokale `db_name_key_corrections` waar mogelijk door de centrale aliastabel. Behoud lokale overrides alleen voor uitzonderingen die niet als duurzaam administratief gegeven thuishoren.

## 10. Tests en acceptatiecriteria

Test minimaal:

- `José` en `Jose` vergelijken gelijk, terwijl de officiële spelling behouden blijft;
- tussenvoegsel los versus onderdeel van de achternaam;
- de bevestigde spellingalias koppelt aan het ingestelde doellid;
- een bevestigde geboorte-/gehuwdenaam kan na verificatie hetzelfde lid vinden;
- een bevestigde roepnaam-/spellingvariant kan na verificatie hetzelfde lid vinden;
- een alias die bij twee leden voorkomt blokkeert automatische koppeling;
- synchronisatie via alias overschrijft de officiële naam niet;
- naamzoeken vindt officiële namen en aliases;
- plaats- en straataliassen leveren dezelfde canonieke adressleutel;
- overlappende actuele adressen worden gesignaleerd;
- boekhoudmatching gebruikt de centrale alias-API en toont de matchreden;
- een nieuwe synchronisatie maakt geen duplicaat van het geconfigureerde doellid.

## 11. Uitvoering en deployment

1. Inventariseer bestaande dubbele leden, naamvarianten en overlappende adressen met een read-only rapport.
2. Voeg schema, migratie en centrale normalisatie-API toe.
3. Voeg unit-/integratietests toe.
4. Pas synchronisaties en zoekfuncties aan.
5. Voeg de beheerinterface toe.
6. Maak een dry-run van datamigraties en merges.
7. Maak een databaseback-up.
8. Deploy en voer migraties uit.
9. Controleer de leden uit de lokale migratieconfiguratie handmatig.
10. Pas daarna `avpvh-bookkeeping` aan om de nieuwe members-API te gebruiken.

## Opdracht voor de vervolgsessie

Implementeer dit plan in `avpvh-members`. Begin met schema, migraties, centrale normalisatie-API en tests. Voeg daarna de beheerinterface en synchronisatie-integratie toe. Maak vervolgens een veilige dry-run voor het lokaal geconfigureerde duplicaat en doellid. Verwijder of combineer geen productiegegevens zonder eerst conflicterende velden en afhankelijkheden te rapporteren.

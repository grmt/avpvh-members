# Privacyregels voor de repository

- Zet geen namen, persoonlijke e-mailadressen of andere herleidbare gegevens van leden in code, documentatie, tests, voorbeelddata of commitberichten.
- Gebruik duidelijk fictieve voorbeelden en gereserveerde domeinen, zoals `test.member` en `member@example.invalid`.
- Bewaar lokale controlegegevens in een bestand dat aan `*.local.*` voldoet. Zulke bestanden worden door Git genegeerd.
- Voer vóór iedere commit `./scripts/check-member-names.sh` uit.

De controle leest standaard vaste zoektermen uit `scripts/member-names.local.txt`. Iedere niet-lege regel is één letterlijke, hoofdletterongevoelige zoekterm. Het lokale bestand zelf mag nooit worden gecommit.

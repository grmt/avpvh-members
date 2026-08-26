# Werkwijze in deze repo

- **Nooit direct op `main` committen of pushen.** `main` is op GitHub beschermd
  (branch protection, `enforce_admins: true`) — een directe push wordt door
  GitHub geweigerd, ook voor de repo-eigenaar.
- Maak voor elke wijziging een feature branch (`feature/...` of `fix/...`),
  commit daar, push die branch, en open een pull request naar `main`.
- Reviews zijn niet verplicht (required_approving_review_count: 0) — een PR
  mag door de eigenaar zelf gemerged worden, maar moet wél als PR bestaan.
- Deploy naar productie (rsync naar de server) mag vanaf een feature branch
  vóór het mergen, zoals al gebruikelijk in deze workflow — dat is losstaand
  van de git-historie.

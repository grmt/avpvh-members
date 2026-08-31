#!/usr/bin/env bash
set -euo pipefail

source_ref="${1:-privacy/scrubbed-main}"
repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

if ! git show-ref --verify --quiet "refs/heads/$source_ref"; then
    echo "Lokale bronbranch bestaat niet: $source_ref" >&2
    exit 2
fi

repo="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
endpoint="repos/$repo/branches/main/protection/enforce_admins"

echo "Dit vervangt de remote history van main door $source_ref."
read -r -p "Typ JA om door te gaan: " confirmation
if [[ "$confirmation" != "JA" ]]; then
    echo "Afgebroken."
    exit 1
fi

git fetch origin main
expected_main="$(git rev-parse refs/remotes/origin/main)"
source_commit="$(git rev-parse "refs/heads/$source_ref")"
protection_disabled=0

restore_protection() {
    if [[ "$protection_disabled" -eq 1 ]]; then
        gh api --method POST "$endpoint" >/dev/null
        protection_disabled=0
        echo "Branch protection voor beheerders is hersteld."
    fi
}

on_exit() {
    status=$?
    trap - EXIT
    if ! restore_protection; then
        echo "LET OP: branch protection kon niet automatisch worden hersteld." >&2
        status=1
    fi
    exit "$status"
}
trap on_exit EXIT

enforced="$(gh api "$endpoint" --jq .enabled)"
if [[ "$enforced" == "true" ]]; then
    protection_disabled=1
    gh api --method DELETE "$endpoint" >/dev/null
    echo "Branch protection voor beheerders is tijdelijk uitgeschakeld."
fi

git push \
    --force-with-lease="refs/heads/main:$expected_main" \
    origin \
    "$source_ref:main"

restore_protection

git fetch origin main
actual_main="$(git rev-parse refs/remotes/origin/main)"
if [[ "$actual_main" != "$source_commit" ]]; then
    echo "Controle mislukt: origin/main wijst niet naar de verwachte commit." >&2
    exit 1
fi

echo "Klaar: origin/main wijst naar $source_ref en de bescherming is hersteld."

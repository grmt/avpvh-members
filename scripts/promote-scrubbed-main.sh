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
admin_endpoint="repos/$repo/branches/main/protection/enforce_admins"
force_endpoint="repos/$repo/branches/main/protection/allow_force_pushes"

echo "Dit vervangt de remote history van main door $source_ref."
read -r -p "Typ JA om door te gaan: " confirmation
if [[ "$confirmation" != "JA" ]]; then
    echo "Afgebroken."
    exit 1
fi

git fetch origin main
expected_main="$(git rev-parse refs/remotes/origin/main)"
source_commit="$(git rev-parse "refs/heads/$source_ref")"
admin_protection_changed=0
force_push_changed=0

restore_protection() {
    restore_status=0
    if [[ "$force_push_changed" -eq 1 ]]; then
        if gh api --method DELETE "$force_endpoint" >/dev/null; then
            force_push_changed=0
            echo "Force pushes zijn weer geblokkeerd."
        else
            restore_status=1
        fi
    fi
    if [[ "$admin_protection_changed" -eq 1 ]]; then
        if gh api --method POST "$admin_endpoint" >/dev/null; then
            admin_protection_changed=0
        else
            restore_status=1
        fi
    fi
    if [[ "$restore_status" -eq 0 ]]; then
        echo "Branch protection voor beheerders is hersteld."
    fi
    return "$restore_status"
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

enforced="$(gh api "$admin_endpoint" --jq .enabled)"
if [[ "$enforced" == "true" ]]; then
    admin_protection_changed=1
    gh api --method DELETE "$admin_endpoint" >/dev/null
    echo "Branch protection voor beheerders is tijdelijk uitgeschakeld."
fi

force_push_allowed="$(gh api "$force_endpoint" --jq .enabled)"
if [[ "$force_push_allowed" != "true" ]]; then
    force_push_changed=1
    gh api --method POST "$force_endpoint" >/dev/null
    echo "Force pushes zijn tijdelijk toegestaan."
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

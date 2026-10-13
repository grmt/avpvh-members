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
owner="${repo%%/*}"
repo_name="${repo#*/}"

echo "Dit vervangt de remote history van main door $source_ref."
read -r -p "Typ JA om door te gaan: " confirmation
if [[ "$confirmation" != "JA" ]]; then
    echo "Afgebroken."
    exit 1
fi

git fetch origin main
expected_main="$(git rev-parse refs/remotes/origin/main)"
source_commit="$(git rev-parse "refs/heads/$source_ref")"
protection_changed=0

read -r rule_id original_admin_enforced original_force_pushes < <(
    gh api graphql \
        -f query='query($owner:String!,$name:String!){repository(owner:$owner,name:$name){branchProtectionRules(first:100){nodes{id pattern isAdminEnforced allowsForcePushes}}}}' \
        -F owner="$owner" \
        -F name="$repo_name" \
        --jq '.data.repository.branchProtectionRules.nodes[] | select(.pattern == "main") | [.id, (.isAdminEnforced | tostring), (.allowsForcePushes | tostring)] | @tsv'
)

if [[ -z "$rule_id" ]]; then
    echo "Geen branch-protectionregel voor main gevonden." >&2
    exit 2
fi

update_protection() {
    local admin_enforced="$1"
    local force_pushes="$2"
    gh api graphql \
        -f query='mutation($id:ID!,$admin:Boolean!,$force:Boolean!){updateBranchProtectionRule(input:{branchProtectionRuleId:$id,isAdminEnforced:$admin,allowsForcePushes:$force}){branchProtectionRule{id}}}' \
        -f id="$rule_id" \
        -F admin="$admin_enforced" \
        -F force="$force_pushes" \
        >/dev/null
}

restore_protection() {
    if [[ "$protection_changed" -eq 1 ]]; then
        if ! update_protection "$original_admin_enforced" "$original_force_pushes"; then
            return 1
        fi
        protection_changed=0
        echo "De oorspronkelijke branch protection is hersteld."
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

protection_changed=1
update_protection false true
echo "Admin-handhaving is tijdelijk uitgeschakeld en force pushes zijn tijdelijk toegestaan."

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

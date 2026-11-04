#!/usr/bin/env bash
# Deploy the committed HEAD of avpvh-members to the live pvh WordPress site.
#
# Run from your workstation, on any branch (deploying a feature branch before
# merging is allowed -- see CLAUDE.md). This script does NOT bump the version,
# commit or push: set the version header (base version + "+<commit hash>", see
# WORKFLOW-version-test-deploy.md) in your feature commit, and merge via a PR.
#
# What reaches the server is `git archive HEAD`, so .gitattributes'
# export-ignore decides what is left out (scripts/, bin/, .github/, *.md, ...).
# Everything under wp-content/plugins/ is reachable over the web: only what
# the plugin loads at runtime may go there. Uncommitted changes are never
# deployed -- the script refuses to run with a dirty working tree.
#
# Server login: AVPVH_DEPLOY_HOST from the environment or deploy.local.env.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
REMOTE_DIR="/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members"
COMPOSE="/opt/docker/scripts/docker-compose.yml"

if [[ -z "${AVPVH_DEPLOY_HOST:-}" && -f "$SRC/deploy.local.env" ]]; then
  # shellcheck source=/dev/null
  source "$SRC/deploy.local.env"
fi
HOST="${AVPVH_DEPLOY_HOST:?set AVPVH_DEPLOY_HOST or create deploy.local.env}"

cd "$SRC"

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo "error: uncommitted changes -- commit first; only HEAD is deployed" >&2
  exit 1
fi

# The archive must contain runtime files only.
leaked=$(git archive HEAD | tar -t | grep -E '\.md$|\.sh$|\.py$|\.env|^\.|(^|/)test|^scripts/|^bin/|^config/' || true)
if [[ -n "$leaked" ]]; then
  echo "error: archive contains files that must not reach the server:" >&2
  echo "$leaked" >&2
  exit 1
fi

version=$(grep -oP '(?<=\* Version:)\s*\S+' avpvh-members.php | tr -d ' ')
echo "deploying $(git rev-parse --abbrev-ref HEAD) @ $(git rev-parse --short HEAD) (version $version) to $HOST"

tmp="/tmp/avm-deploy-$(date +%s)"
ssh "$HOST" "mkdir -p $tmp"
git archive HEAD | ssh "$HOST" "tar -x -C $tmp"
ssh "$HOST" "rsync -rl --delete --no-owner --no-group $tmp/ $REMOTE_DIR/ && rm -rf $tmp"

echo "--- live:"
ssh "$HOST" "docker compose -f $COMPOSE run --rm --no-deps wpcli-pvh wp plugin get avpvh-members --fields=name,status,version" 2>&1 \
  | grep -vE 'Permission denied|Deprecated|Container '

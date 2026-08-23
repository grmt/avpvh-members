#!/usr/bin/env bash
# Deploy avpvh-members plugin to the pvh WordPress container.
# Bumps the mini (patch) version on every deploy.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
MAIN_FILE="$SRC/avpvh-members.php"
DEST="/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/"

# Bump mini version. current_full may carry a leftover "+<hash>"
# feature-branch-testing suffix (see WORKFLOW-version-test-deploy.md) --
# only the leading X.Y.Z is used for the bump math, and the sed below
# replaces the whole value so a stale hash can't survive the bump.
current_full=$(grep -oP '(?<=\* Version:\s{5}).*' "$MAIN_FILE")
current=$(grep -oP '^\d+\.\d+\.\d+' <<< "$current_full")
major=${current%%.*}; rest=${current#*.}; minor=${rest%%.*}; mini=${rest##*.}
mini=$(( mini + 1 ))
new_version="$major.$minor.$mini"

sed -i "s/\(\* Version:[[:space:]]*\).*/\1$new_version/" "$MAIN_FILE"
echo "version: $current_full → $new_version"

# Commit and push
git -C "$SRC" add "$MAIN_FILE"
git -C "$SRC" commit -m "build: bump version to $new_version"
git -C "$SRC" push origin

rsync -rl --delete --no-owner --no-group \
  --exclude='.git' \
  --exclude='.claude' \
  --exclude='PLAN.md' \
  --exclude='README.md' \
  --exclude='AGENTS.md' \
  --exclude='GEMINI.md' \
  --exclude='scripts/' \
  --exclude='config/' \
  "$SRC/" "$DEST"

echo "deployed $new_version to $DEST"

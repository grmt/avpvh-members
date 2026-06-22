#!/usr/bin/env bash
# Deploy avpvh-members plugin to the pvh WordPress container.
# Bumps the mini (patch) version on every deploy.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
MAIN_FILE="$SRC/avpvh-members.php"
DEST="/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/"

# Bump mini version
current=$(grep -oP '(?<=\* Version:\s{5})\d+\.\d+\.\d+' "$MAIN_FILE")
major=${current%%.*}; rest=${current#*.}; minor=${rest%%.*}; mini=${rest##*.}
mini=$(( mini + 1 ))
new_version="$major.$minor.$mini"

sed -i "s/\* Version:     $current/* Version:     $new_version/" "$MAIN_FILE"
echo "version: $current → $new_version"

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

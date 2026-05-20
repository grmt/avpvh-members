#!/usr/bin/env bash
# Deploy avpvh-members plugin to the pvh WordPress container.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)/"
DEST="/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/"

rsync -rl --delete --no-owner --no-group \
  --exclude='.git' \
  --exclude='.claude' \
  --exclude='PLAN.md' \
  --exclude='README.md' \
  --exclude='AGENTS.md' \
  --exclude='GEMINI.md' \
  --exclude='scripts/' \
  --exclude='config/' \
  "$SRC" "$DEST"

echo "deployed to $DEST"

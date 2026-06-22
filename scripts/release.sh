#!/usr/bin/env bash
# Bump version when closing a branch.
#   default     : minor bump, mini reset to 0  (1.2.3 → 1.3.0)
#   --major     : major bump, minor+mini reset  (1.2.3 → 2.0.0)
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
MAIN_FILE="$SRC/avpvh-members.php"

current=$(grep -oP '(?<=\* Version:\s{5})\d+\.\d+\.\d+' "$MAIN_FILE")
major=${current%%.*}; rest=${current#*.}; minor=${rest%%.*}

if [[ "${1:-}" == "--major" ]]; then
  major=$(( major + 1 ))
  new_version="$major.0.0"
else
  minor=$(( minor + 1 ))
  new_version="$major.$minor.0"
fi

sed -i "s/\* Version:     $current/* Version:     $new_version/" "$MAIN_FILE"
echo "version: $current → $new_version"

git -C "$SRC" add "$MAIN_FILE"
git -C "$SRC" commit -m "release: bump version to $new_version"
git -C "$SRC" push origin

echo "release $new_version committed and pushed"

#!/usr/bin/env bash
set -euo pipefail

patterns_file="${1:-scripts/member-names.local.txt}"

if [[ ! -f "$patterns_file" ]]; then
    echo "Geen lokaal patroonbestand gevonden: $patterns_file" >&2
    echo "Maak het bestand aan met één naam of persoonlijk adres per regel." >&2
    exit 2
fi

filtered_patterns="$(mktemp)"
trap 'rm -f "$filtered_patterns"' EXIT
sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e '/^$/d' "$patterns_file" > "$filtered_patterns"

if [[ ! -s "$filtered_patterns" ]]; then
    echo "Het lokale patroonbestand bevat geen zoektermen." >&2
    exit 2
fi

if git grep -I -n -i -F -f "$filtered_patterns" -- .; then
    echo "Persoonsgegevens gevonden in bijgehouden bestanden." >&2
    exit 1
fi

echo "Geen opgegeven persoonsgegevens gevonden in bijgehouden bestanden."

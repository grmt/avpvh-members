#!/usr/bin/env python3
"""
Sync the excavation campaign's day-by-day participation grid (sheet "totaal
inschrijvingen" in the club's registration spreadsheet) into a WordPress
option as JSON, for the [avpvh_kamp_overzicht] shortcode to render.

This is a snapshot, not a live view — the spreadsheet changes continuously
as people register/cancel, so re-run this whenever the site should show
fresher data. No LLDAP/member-account writes here, just one wp_options row.

The source may be one .xlsx file or a directory. For a directory, the most
recently modified .xlsx file is used, so the command can be rerun unchanged
after a newer export is downloaded.

Usage:
    python3 sync-kamp-overzicht.py /path/to/Overzicht.xlsx [--dry-run]
    python3 sync-kamp-overzicht.py /path/to/exports/ [--dry-run]

Dependencies:
    pip install pymysql openpyxl requests
"""
import argparse
import json
import re
from datetime import date
from pathlib import Path

import openpyxl

from _avpvh_import_common import SECRET_FILE, WP_PREFIX, get_db, read_secret

SHEET_NAME = 'totaal inschrijvingen'
GRID_FIRST_ROW = 3
GRID_LAST_ROW = 97
GRID_FIRST_COL = 4   # D
GRID_LAST_COL = 26   # Z


def newest_workbook(source: Path) -> Path:
    """Resolve one workbook, selecting the newest export for a directory."""
    if source.is_file():
        if source.suffix.lower() != '.xlsx':
            raise SystemExit(f'ERROR: expected an .xlsx file, got {source}')
        return source
    if not source.is_dir():
        raise SystemExit(f'ERROR: source does not exist: {source}')

    candidates = [
        path for path in source.rglob('*.xlsx')
        if not path.name.startswith('~$')
    ]
    if not candidates:
        raise SystemExit(f'ERROR: no .xlsx files found under {source}')
    return max(candidates, key=lambda path: path.stat().st_mtime)


def infer_year(path: Path, title: str) -> int:
    """Prefer a year in the title/path; otherwise use the current year."""
    for value in (title, path.stem, *reversed(path.parts)):
        match = re.search(r'\b(19|20)\d{2}\b', value)
        if match:
            return int(match.group())
    return date.today().year


def cell_color(cell) -> str | None:
    fill = cell.fill
    if not fill or not fill.fgColor:
        return None
    rgb = fill.fgColor.rgb
    if not rgb or not isinstance(rgb, str) or rgb in ('00000000',):
        return None
    # openpyxl ARGB -> #RRGGBB
    return '#' + rgb[-6:]


def cell_bold(cell) -> bool:
    return bool(cell.font and cell.font.bold)


def cell_value(cell) -> str:
    v = cell.value
    if v is None:
        return ''
    if isinstance(v, float) and v.is_integer():
        v = int(v)
    return str(v).strip()


def build_grid(ws) -> dict:
    title = cell_value(ws.cell(row=1, column=3)) or 'Overzicht inschrijvingen'
    last_updated = cell_value(ws.cell(row=2, column=3))
    note = cell_value(ws.cell(row=2, column=6))

    grid = []
    for r in range(GRID_FIRST_ROW, GRID_LAST_ROW + 1):
        row = []
        for c in range(GRID_FIRST_COL, GRID_LAST_COL + 1):
            cell = ws.cell(row=r, column=c)
            row.append({'v': cell_value(cell), 'c': cell_color(cell), 'b': cell_bold(cell)})
        grid.append(row)

    return {'title': title, 'last_updated': last_updated, 'note': note, 'grid': grid}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('source', type=Path,
                    help='Overzicht .xlsx file, or directory containing exports')
    ap.add_argument('--year', type=int,
                    help='Campaign year (normally inferred from the workbook/path)')
    ap.add_argument('--option-name',
                    help='Override the WordPress option name')
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    workbook_path = newest_workbook(args.source)
    print(f'Workbook: {workbook_path}')
    wb = openpyxl.load_workbook(workbook_path, data_only=True)
    if SHEET_NAME not in wb.sheetnames:
        raise SystemExit(f'sheet {SHEET_NAME!r} not found; sheets: {wb.sheetnames}')

    data = build_grid(wb[SHEET_NAME])
    year = args.year or infer_year(workbook_path, data['title'])
    option_name = args.option_name or f'avpvh_kamp_{year}_overzicht'
    payload = json.dumps(data, ensure_ascii=False)
    print(f'Title: {data["title"]!r}')
    print(f'Last updated (per sheet): {data["last_updated"]!r}')
    print(f'Grid: {len(data["grid"])} rows x {GRID_LAST_COL - GRID_FIRST_COL + 1} cols')
    print(f'Payload size: {len(payload)} bytes')
    print(f'WordPress option: {option_name}')

    if args.dry_run:
        print('\nDry-run complete — option not written.')
        return

    conn = get_db(read_secret(SECRET_FILE))
    try:
        with conn.cursor() as cur:
            cur.execute(
                f"INSERT INTO {WP_PREFIX}options (option_name, option_value, autoload) "
                f"VALUES (%s, %s, 'no') "
                f"ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                (option_name, payload)
            )
        conn.commit()
        print(f'\nWritten to wp_options[{option_name}].')
    finally:
        conn.close()


if __name__ == '__main__':
    main()

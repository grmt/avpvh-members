#!/usr/bin/env python3
"""
Import excavation-camp participation from XLS/XLSX files into the current
pvh_avm_activities and pvh_avm_activity_participation tables.

The source may be one workbook or a directory. Directory imports process all
workbooks unless --latest is supplied. Re-running an import updates existing
participation rows, making the script idempotent.

Usage:
    python3 import-avpvh-camps.py /path/to/Opgravingen/ --dry-run
    python3 import-avpvh-camps.py /path/to/exports/ --latest

For each workbook the script:
  - extracts the year and activity name from its path;
  - creates a Kamp activity if it does not exist;
  - reads "totaal inschrijvingen" (or the first sheet);
  - matches participants to members without choosing ambiguous matches;
  - inserts or updates participation details;
  - reports unmatched or ambiguous names for manual review.

Dependencies:
    pip install pymysql openpyxl requests xlrd
"""

import argparse
import re
import sys
from collections import defaultdict
from datetime import date, datetime
from pathlib import Path

import openpyxl

try:
    import xlrd
    HAS_XLRD = True
except ImportError:
    HAS_XLRD = False

from _avpvh_import_common import (
    SECRET_FILE,
    WP_PREFIX,
    get_db,
    normalize_name_key,
    read_secret,
)

PREFERRED_SHEET = 'totaal inschrijvingen'
SUPPORTED_SUFFIXES = {'.xls', '.xlsx'}


def workbook_paths(source: Path, latest: bool) -> list[Path]:
    if source.is_file():
        if source.suffix.lower() not in SUPPORTED_SUFFIXES:
            raise SystemExit(f'ERROR: unsupported workbook type: {source}')
        return [source]
    if not source.is_dir():
        raise SystemExit(f'ERROR: source does not exist: {source}')

    paths = sorted(
        path for path in source.rglob('*')
        if path.is_file()
        and path.suffix.lower() in SUPPORTED_SUFFIXES
        and not path.name.startswith('~$')
    )
    if not paths:
        raise SystemExit(f'ERROR: no XLS/XLSX files found under {source}')
    if latest:
        return [max(paths, key=lambda path: path.stat().st_mtime)]
    return paths


def extract_year_activity(path: Path) -> tuple[int | None, str, str]:
    year = None
    for value in (*reversed(path.parts), path.stem):
        match = re.search(r'\b(19|20)\d{2}\b', value)
        if match:
            year = int(match.group())
            break

    parent_without_year = re.sub(r'\b(19|20)\d{2}\b', '', path.parent.name).strip(' _-')
    stem_without_year = re.sub(r'\b(19|20)\d{2}\b', '', path.stem).strip(' _-')
    activity_name = path.parent.name if path.parent.name else path.stem
    kenmerk = parent_without_year or stem_without_year
    return year, activity_name, kenmerk


def activity_title_metadata(rows: list[list[str]]) -> tuple[int | None, str]:
    """Read the modern export's "Activity name YYYY" title when present."""
    for row in rows[:2]:
        for value in row:
            if 'bijgewerkt' in value.casefold():
                continue
            match = re.search(r'\b(19|20)\d{2}\b', value)
            if not match:
                continue
            name = (value[:match.start()] + value[match.end():]).strip(' _-')
            if name:
                return int(match.group()), name
    return None, ''


def open_workbook(path: Path):
    if path.suffix.lower() == '.xlsx':
        return openpyxl.load_workbook(path, data_only=True), 'openpyxl'
    if HAS_XLRD:
        return xlrd.open_workbook(str(path)), 'xlrd'
    print(f'  SKIP (.xls requires xlrd): {path}')
    return None, None


def get_sheet(workbook, workbook_type: str):
    if workbook_type == 'openpyxl':
        names = {name.casefold(): name for name in workbook.sheetnames}
        return workbook[names.get(PREFERRED_SHEET.casefold(), workbook.sheetnames[0])]
    for index in range(workbook.nsheets):
        sheet = workbook.sheet_by_index(index)
        if PREFERRED_SHEET.casefold() in sheet.name.casefold():
            return sheet
    return workbook.sheet_by_index(0)


def iter_rows_as_strings(sheet, workbook_type: str):
    if workbook_type == 'openpyxl':
        rows = sheet.iter_rows(values_only=True)
    else:
        rows = (
            [sheet.cell_value(row, column) for column in range(sheet.ncols)]
            for row in range(sheet.nrows)
        )
    for row in rows:
        yield [cell_text(value) for value in row]


def cell_text(value) -> str:
    if value is None:
        return ''
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    return str(value).strip()


def normalized_header(value: str) -> str:
    return re.sub(r'\s+', ' ', value.strip().casefold())


def first_index(headers: list[str], *names: str) -> int | None:
    for name in names:
        try:
            return headers.index(name)
        except ValueError:
            continue
    return None


def find_header(rows: list[list[str]]) -> tuple[int, list[str]] | None:
    for index, row in enumerate(rows[:15]):
        headers = [normalized_header(value) for value in row]
        if any(name in headers for name in ('naam', 'achternaam', 'voornaam')):
            return index, headers
    return None


def parse_int(value: str) -> int | None:
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return None


def parse_bool(value: str) -> int:
    normalized = value.strip().casefold()
    if normalized in ('', '0', 'nee', 'no', 'false', 'n.v.t.'):
        return 0
    return 1


def parse_day_header(value: str, default_year: int) -> date | None:
    """Read ISO dates and localized headers such as "za 18-7"."""
    value = value.strip()
    for fmt in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%d', '%d-%m-%Y', '%d/%m/%Y'):
        try:
            return datetime.strptime(value, fmt).date()
        except ValueError:
            pass
    match = re.search(r'(?<!\d)(\d{1,2})[-/](\d{1,2})(?![-/\d])', value)
    if not match:
        return None
    try:
        return date(default_year, int(match.group(2)), int(match.group(1)))
    except ValueError:
        return None


def full_name_key(value: str) -> str:
    return re.sub(r'[^\w]+', ' ', value.casefold(), flags=re.UNICODE).strip()


class MemberMatcher:
    """Match exact normalized names and reject duplicate/ambiguous keys."""

    def __init__(self, cursor):
        cursor.execute(
            f'SELECT id, first_name, suffix, last_name FROM {WP_PREFIX}avm_members'
        )
        self.by_parts = defaultdict(list)
        self.by_full = defaultdict(list)
        for member_id, first_name, suffix, last_name in cursor.fetchall():
            first_name = first_name or ''
            suffix = suffix or ''
            last_name = last_name or ''
            self.by_parts[normalize_name_key(first_name, last_name)].append(member_id)
            variants = {
                f'{first_name} {suffix} {last_name}',
                f'{first_name} {last_name}',
                f'{last_name}, {first_name}',
            }
            for variant in variants:
                self.by_full[full_name_key(variant)].append(member_id)

    def find(self, first_name: str, last_name: str, full_name: str) -> tuple[int | None, str]:
        matches = (
            self.by_parts.get(normalize_name_key(first_name, last_name), [])
            if first_name and last_name
            else self.by_full.get(full_name_key(full_name), [])
        )
        unique = set(matches)
        if len(unique) == 1:
            return unique.pop(), 'matched'
        return None, 'ambiguous' if unique else 'unmatched'


def get_kamp_type_id(cursor) -> int:
    cursor.execute(
        f'SELECT id FROM {WP_PREFIX}avm_activity_types WHERE LOWER(name) = LOWER(%s)',
        ('Kamp',),
    )
    row = cursor.fetchone()
    if not row:
        raise RuntimeError('activity type "Kamp" does not exist')
    return int(row[0])


def get_or_create_activity(cursor, name: str, year: int, kenmerk: str,
                           kamp_type_id: int, dry_run: bool) -> int | None:
    cursor.execute(
        f'SELECT id FROM {WP_PREFIX}avm_activities WHERE name = %s AND year = %s',
        (name, year),
    )
    row = cursor.fetchone()
    if row:
        return int(row[0])
    if dry_run:
        print(f'  [dry-run] would create Kamp activity: {name} ({year})')
        return None
    cursor.execute(
        f'''INSERT INTO {WP_PREFIX}avm_activities
            (name, type_id, year, kenmerk) VALUES (%s, %s, %s, %s)''',
        (name, kamp_type_id, year, kenmerk),
    )
    activity_id = int(cursor.lastrowid)
    print(f'  created activity id={activity_id}')
    return activity_id


def save_participation(cursor, member_id: int, activity_id: int,
                       fields: dict[str, object]) -> int:
    """Upsert only columns present in the workbook, preserving other data."""
    columns = ['member_id', 'activity_id', *fields]
    placeholders = ', '.join(['%s'] * len(columns))
    values = [member_id, activity_id, *fields.values()]
    if fields:
        updates = [f'{column} = VALUES({column})' for column in fields]
        updates.append('id = LAST_INSERT_ID(id)')
        update_sql = ', '.join(updates)
        duplicate_sql = f'ON DUPLICATE KEY UPDATE {update_sql}'
    else:
        duplicate_sql = 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    cursor.execute(
        f'''INSERT INTO {WP_PREFIX}avm_activity_participation
            ({', '.join(columns)}) VALUES ({placeholders})
            {duplicate_sql}''',
        values,
    )
    return int(cursor.lastrowid)


def replace_participation_days(cursor, participation_id: int,
                               days: dict[date, str]) -> None:
    cursor.execute(
        f'DELETE FROM {WP_PREFIX}avm_activity_participation_days '
        'WHERE participation_id = %s',
        (participation_id,),
    )
    for day, status in days.items():
        status = status.strip()[:10]
        if not status:
            continue
        cursor.execute(
            f'''INSERT INTO {WP_PREFIX}avm_activity_participation_days
                (participation_id, date, status) VALUES (%s, %s, %s)''',
            (participation_id, day.isoformat(), status),
        )


def import_file(cursor, matcher: MemberMatcher, path: Path,
                kamp_type_id: int, dry_run: bool) -> tuple[int, int, int]:
    year, activity_name, kenmerk = extract_year_activity(path)
    workbook, workbook_type = open_workbook(path)
    if not workbook:
        return 0, 0, 0
    rows = list(iter_rows_as_strings(get_sheet(workbook, workbook_type), workbook_type))
    title_year, title_name = activity_title_metadata(rows)
    # Historical archives already have a meaningful year-bearing directory
    # name. A newly downloaded export usually sits in a generic directory, in
    # which case its first-row title is the authoritative activity identity.
    if not re.search(r'\b(19|20)\d{2}\b', path.parent.name) and title_name:
        year = title_year
        activity_name = title_name
        kenmerk = title_name
    if not year:
        print(f'  SKIP (no year found in path or workbook title): {path}')
        return 0, 0, 0

    print(f'\n{path.name} -> activity={activity_name!r}, year={year}, kenmerk={kenmerk!r}')
    header = find_header(rows)
    if not header:
        print('  SKIP (no recognisable header row)')
        return 0, 0, 0

    header_index, headers = header
    idx_last = first_index(headers, 'achternaam')
    idx_first = first_index(headers, 'voornaam', 'roepnaam')
    idx_full = first_index(headers, 'naam')
    if idx_last is None and idx_full is None:
        print('  SKIP (no name column)')
        return 0, 0, 0

    idx_nights = first_index(headers, 'nachten', 'nights')
    idx_nawacht = first_index(headers, 'nawacht')
    idx_diet = first_index(headers, 'dieet', 'diet')
    idx_notes = first_index(headers, 'notities', 'opmerkingen', 'notes')
    day_columns = {
        index: day
        for index, header_value in enumerate(headers)
        if (day := parse_day_header(header_value, year)) is not None
    }

    activity_id = get_or_create_activity(
        cursor, activity_name, year, kenmerk, kamp_type_id, dry_run
    )
    imported = unmatched = ambiguous = 0
    for row in rows[header_index + 1:]:
        first_name = row[idx_first] if idx_first is not None and idx_first < len(row) else ''
        last_name = row[idx_last] if idx_last is not None and idx_last < len(row) else ''
        full_name = row[idx_full] if idx_full is not None and idx_full < len(row) else ''
        display_name = full_name or f'{first_name} {last_name}'.strip()
        if not display_name or display_name.casefold() in ('totaal', 'sum'):
            continue

        member_id, result = matcher.find(first_name, last_name, full_name)
        if not member_id:
            print(f'  {result.upper()}: {display_name}')
            unmatched += result == 'unmatched'
            ambiguous += result == 'ambiguous'
            continue

        def value(index: int | None) -> str:
            return row[index] if index is not None and index < len(row) else ''

        fields = {}
        if idx_nights is not None:
            fields['nights'] = parse_int(value(idx_nights))
        if idx_nawacht is not None:
            fields['nawacht'] = parse_bool(value(idx_nawacht))
        if idx_diet is not None:
            fields['diet'] = value(idx_diet) or None
        if idx_notes is not None:
            fields['notes'] = value(idx_notes) or None
        if not dry_run and activity_id is not None:
            participation_id = save_participation(
                cursor, member_id, activity_id, fields
            )
            if day_columns:
                replace_participation_days(cursor, participation_id, {
                    day: value(index) for index, day in day_columns.items()
                })
        imported += 1

    print(f'  imported/updated={imported} unmatched={unmatched} ambiguous={ambiguous}')
    return imported, unmatched, ambiguous


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('source', type=Path,
                        help='Workbook or directory containing camp workbooks')
    parser.add_argument('--latest', action='store_true',
                        help='Import only the most recently modified workbook')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()

    paths = workbook_paths(args.source, args.latest)
    print(f'Found {len(paths)} workbook(s)')
    connection = get_db(read_secret(SECRET_FILE))
    totals = [0, 0, 0]
    try:
        with connection.cursor() as cursor:
            kamp_type_id = get_kamp_type_id(cursor)
            matcher = MemberMatcher(cursor)
            for path in paths:
                result = import_file(cursor, matcher, path, kamp_type_id, args.dry_run)
                totals = [left + right for left, right in zip(totals, result)]
        if args.dry_run:
            connection.rollback()
            print('\nDry-run complete - no changes written.')
        else:
            connection.commit()
            print('\nCommitted.')
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()

    print(
        f'Total: imported/updated={totals[0]} '
        f'unmatched={totals[1]} ambiguous={totals[2]}'
    )
    if totals[1] or totals[2]:
        sys.exit(2)


if __name__ == '__main__':
    main()

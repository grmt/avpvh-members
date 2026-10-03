#!/usr/bin/env python3
"""
Import the AVP-PvH camp's special "totaal inschrijvingen" grid into the
current activity-participation tables.

This is deliberately not a generic spreadsheet importer. The camp overview
has a fixed, calculation-heavy layout:

  - row 3: month headings;
  - row 5: day numbers;
  - column D from row 17: participant names, mixed with category rows;
  - columns E-T: day status (normally "n", "on" or "?");
  - columns W/X/Y/Z: nawacht, two note fields and diet.

The source may be one overview workbook or an archive directory. Directory
imports consider only filenames containing "overzicht"; --latest selects the
most recently modified overview. Re-running updates existing participation
and replaces its day grid, so the operation is idempotent.

Usage:
    python3 import-avpvh-camps.py /path/to/Opgravingen/ --dry-run
    python3 import-avpvh-camps.py /path/to/Opgravingen/ --latest

Dependencies:
    pip install pymysql openpyxl requests xlrd
"""

import argparse
import re
import sys
from collections import defaultdict
from datetime import date
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
    read_secret,
)

SHEET_NAME = 'totaal inschrijvingen'
SUPPORTED_SUFFIXES = {'.xls', '.xlsx'}

MONTH_ROW = 3
DAY_ROW = 5
FIRST_PARTICIPANT_ROW = 17
LAST_PARTICIPANT_ROW = 250
NAME_COLUMN = 4       # D
FIRST_DAY_COLUMN = 5  # E
LAST_DAY_COLUMN = 20  # T
NAWACHT_COLUMN = 23   # W
NOTES_COLUMNS = (24, 25)  # X, Y
DIET_COLUMN = 26      # Z

DUTCH_MONTHS = {
    'januari': 1,
    'februari': 2,
    'maart': 3,
    'april': 4,
    'mei': 5,
    'juni': 6,
    'juli': 7,
    'augustus': 8,
    'september': 9,
    'oktober': 10,
    'november': 11,
    'december': 12,
}


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
        and 'overzicht' in path.name.casefold()
        and not path.name.startswith('~$')
    )
    if not paths:
        raise SystemExit(f'ERROR: no camp overview XLS/XLSX files found under {source}')
    return [max(paths, key=lambda path: path.stat().st_mtime)] if latest else paths


def extract_year_activity(path: Path) -> tuple[int | None, str, str]:
    """The archive's parent directory is the authoritative camp identity."""
    year = None
    for value in (path.parent.name, path.stem):
        match = re.search(r'\b(19|20)\d{2}\b', value)
        if match:
            year = int(match.group())
            break
    activity_name = path.parent.name
    kenmerk = re.sub(r'\b(19|20)\d{2}\b', '', activity_name).strip(' _-')
    return year, activity_name, kenmerk


def open_workbook(path: Path):
    if path.suffix.lower() == '.xlsx':
        return openpyxl.load_workbook(path, data_only=True), 'openpyxl'
    if HAS_XLRD:
        return xlrd.open_workbook(str(path)), 'xlrd'
    print(f'  SKIP (.xls requires xlrd): {path}')
    return None, None


def get_camp_sheet(workbook, workbook_type: str):
    if workbook_type == 'openpyxl':
        names = {name.strip().casefold(): name for name in workbook.sheetnames}
        exact = names.get(SHEET_NAME)
        return workbook[exact] if exact else None
    for index in range(workbook.nsheets):
        sheet = workbook.sheet_by_index(index)
        if sheet.name.strip().casefold() == SHEET_NAME:
            return sheet
    return None


def cell_value(sheet, workbook_type: str, row: int, column: int):
    if workbook_type == 'openpyxl':
        return sheet.cell(row=row, column=column).value
    if row > sheet.nrows or column > sheet.ncols:
        return None
    return sheet.cell_value(row - 1, column - 1)


def cell_text(sheet, workbook_type: str, row: int, column: int) -> str:
    value = cell_value(sheet, workbook_type, row, column)
    if value is None:
        return ''
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    return str(value).strip()


def camp_date_columns(sheet, workbook_type: str, campaign_year: int) -> dict[int, date]:
    """Build column-to-date mapping from the two-level month/day heading."""
    result = {}
    current_month = None
    current_year = campaign_year
    previous_month = None

    for column in range(FIRST_DAY_COLUMN, LAST_DAY_COLUMN + 1):
        month_label = cell_text(sheet, workbook_type, MONTH_ROW, column).casefold()
        if month_label:
            current_month = DUTCH_MONTHS.get(month_label)
            if current_month is None:
                raise ValueError(f'unknown month heading {month_label!r} in column {column}')
            if previous_month is not None and current_month < previous_month:
                current_year += 1
            previous_month = current_month

        raw_day = cell_value(sheet, workbook_type, DAY_ROW, column)
        if current_month is None or raw_day in (None, ''):
            continue
        try:
            result[column] = date(current_year, current_month, int(float(raw_day)))
        except (TypeError, ValueError) as error:
            raise ValueError(
                f'invalid camp day {raw_day!r} in row {DAY_ROW}, column {column}'
            ) from error

    if not result:
        raise ValueError('no camp dates found in rows 3 and 5, columns E-T')
    return result


def parse_bool(value: str) -> int:
    return int(value.strip().casefold() not in ('', '0', 'nee', 'no', 'false', 'n.v.t.'))


def normalized_full_name(value: str) -> str:
    return re.sub(r'[^\w]+', ' ', value.casefold(), flags=re.UNICODE).strip()


class MemberMatcher:
    """Match exact full names and refuse duplicate/ambiguous matches."""

    def __init__(self, cursor):
        cursor.execute(
            f'SELECT id, first_name, suffix, last_name FROM {WP_PREFIX}avm_members'
        )
        self.by_name = defaultdict(set)
        for member_id, first_name, suffix, last_name in cursor.fetchall():
            first_name = first_name or ''
            suffix = suffix or ''
            last_name = last_name or ''
            variants = {
                f'{first_name} {suffix} {last_name}',
                f'{first_name} {last_name}',
                f'{last_name}, {first_name}',
            }
            for variant in variants:
                self.by_name[normalized_full_name(variant)].add(int(member_id))

    def find(self, full_name: str) -> tuple[int | None, str]:
        matches = self.by_name.get(normalized_full_name(full_name), set())
        if len(matches) == 1:
            return next(iter(matches)), 'matched'
        return None, 'ambiguous' if matches else 'unmatched'


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
    columns = ['member_id', 'activity_id', *fields]
    placeholders = ', '.join(['%s'] * len(columns))
    updates = [f'{column} = VALUES({column})' for column in fields]
    updates.append('id = LAST_INSERT_ID(id)')
    cursor.execute(
        f'''INSERT INTO {WP_PREFIX}avm_activity_participation
            ({', '.join(columns)}) VALUES ({placeholders})
            ON DUPLICATE KEY UPDATE {', '.join(updates)}''',
        [member_id, activity_id, *fields.values()],
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


def participant_rows(sheet, workbook_type: str, date_columns: dict[int, date]):
    """Yield actual people while ignoring the grid's summary/category rows."""
    for row in range(FIRST_PARTICIPANT_ROW, LAST_PARTICIPANT_ROW + 1):
        name = cell_text(sheet, workbook_type, row, NAME_COLUMN)
        if not name:
            continue

        days = {
            day: cell_text(sheet, workbook_type, row, column)
            for column, day in date_columns.items()
        }
        nawacht = cell_text(sheet, workbook_type, row, NAWACHT_COLUMN)
        notes = [
            cell_text(sheet, workbook_type, row, column)
            for column in NOTES_COLUMNS
        ]
        diet = cell_text(sheet, workbook_type, row, DIET_COLUMN)

        # Category labels and totals have a value in D but no registration
        # data in the camp-specific input columns.
        if not any(days.values()) and not nawacht and not any(notes) and not diet:
            continue

        yield {
            'name': name,
            'days': days,
            'nights': sum(status.casefold() == 'n' for status in days.values()),
            'nawacht': parse_bool(nawacht),
            'notes': '\n'.join(note for note in notes if note) or None,
            'diet': diet or None,
        }


def import_file(cursor, matcher: MemberMatcher, path: Path,
                kamp_type_id: int, dry_run: bool) -> tuple[int, int, int]:
    year, activity_name, kenmerk = extract_year_activity(path)
    if not year:
        print(f'  SKIP (no year found in camp directory or filename): {path}')
        return 0, 0, 0

    workbook, workbook_type = open_workbook(path)
    if not workbook:
        return 0, 0, 0
    sheet = get_camp_sheet(workbook, workbook_type)
    if sheet is None:
        print(f'  SKIP (sheet {SHEET_NAME!r} not found): {path}')
        return 0, 0, 0

    try:
        date_columns = camp_date_columns(sheet, workbook_type, year)
    except ValueError as error:
        print(f'  SKIP ({error}): {path}')
        return 0, 0, 0

    print(
        f'\n{path.name} -> activity={activity_name!r}, year={year}, '
        f'dates={min(date_columns.values())}..{max(date_columns.values())}'
    )
    activity_id = get_or_create_activity(
        cursor, activity_name, year, kenmerk, kamp_type_id, dry_run
    )

    imported = unmatched = ambiguous = 0
    for participant in participant_rows(sheet, workbook_type, date_columns):
        member_id, result = matcher.find(participant['name'])
        if not member_id:
            print(f'  {result.upper()}: {participant["name"]}')
            unmatched += result == 'unmatched'
            ambiguous += result == 'ambiguous'
            continue

        if not dry_run and activity_id is not None:
            participation_id = save_participation(cursor, member_id, activity_id, {
                'nights': participant['nights'],
                'nawacht': participant['nawacht'],
                'diet': participant['diet'],
                'notes': participant['notes'],
            })
            replace_participation_days(
                cursor, participation_id, participant['days']
            )
        imported += 1

    print(f'  imported/updated={imported} unmatched={unmatched} ambiguous={ambiguous}')
    return imported, unmatched, ambiguous


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('source', type=Path,
                        help='Camp overview workbook or archive directory')
    parser.add_argument('--latest', action='store_true',
                        help='Import only the newest camp overview workbook')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()

    paths = workbook_paths(args.source, args.latest)
    print(f'Found {len(paths)} camp overview workbook(s)')
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

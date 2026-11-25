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

Camps up to 2014 have no such sheet. Their registrations are in the older
form's "Inschrijving" sheet: a header cell "VOORNAAM & ACHTERNAAM" (column A
or B), day numbers to its right on the same row, month headings two rows
above, then one participant per row. Columns after the day grid are found by
their heading ("nachten", "opmerkingen", "eetopmerkingen"/"dieet"). Each
workbook's layout is detected from its sheets.

The source may be one overview workbook or an archive directory. Directory
imports consider filenames containing "overzicht". A camp directory without
any overview falls back to its top-level workbooks whose name contains
"inschrijvingen" or "algemeen" (the older collected registration lists);
per-person forms and blank templates are not matched. --latest selects the
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

LEGACY_SHEET_NAME = 'inschrijving'
LEGACY_NAME_HEADER = 'voornaam & achternaam'
LEGACY_HEADER_SEARCH_ROWS = 20
LEGACY_HEADER_SEARCH_COLUMNS = 4
LEGACY_LIST_NAME = re.compile(r'inschrijvingen|algemeen', re.IGNORECASE)

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

    workbooks = sorted(
        path for path in source.rglob('*')
        if path.is_file()
        and path.suffix.lower() in SUPPORTED_SUFFIXES
        and not path.name.startswith('~$')
    )
    paths = [path for path in workbooks if 'overzicht' in path.name.casefold()]
    if latest:
        if not paths:
            raise SystemExit(f'ERROR: no camp overview XLS/XLSX files found under {source}')
        return [max(paths, key=lambda path: path.stat().st_mtime)]

    def camp_dir(path: Path) -> Path:
        return source / path.relative_to(source).parts[0]

    camps_with_overview = {camp_dir(path) for path in paths}
    paths += [
        path for path in workbooks
        if path.parent != source
        and path.parent == camp_dir(path)
        and path.parent not in camps_with_overview
        and LEGACY_LIST_NAME.search(path.name)
    ]
    if not paths:
        raise SystemExit(f'ERROR: no camp overview XLS/XLSX files found under {source}')
    return sorted(paths)


def extract_year_activity(path: Path) -> tuple[int | None, str, str]:
    """The archive's parent directory is the authoritative camp identity.

    "2021 Goeblange III" gives year 2021, kenmerk "Goeblange III" and the
    activity name "Goeblange": Kamp activities are named after the place
    only, like the ones entered in the admin.
    """
    year = None
    for value in (path.parent.name, path.stem):
        match = re.search(r'\b(19|20)\d{2}\b', value)
        if match:
            year = int(match.group())
            break
    kenmerk = re.sub(r'\b(19|20)\d{2}\b', '', path.parent.name).strip(' _-')
    activity_name = re.sub(r'\s+[IVX]+$', '', kenmerk).strip() or kenmerk
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
    """Match exact full names and refuse duplicate/ambiguous matches.

    Besides each member's official name, the name variants recorded for
    them (Naamvarianten on Ledendetail: old spellings, maiden names, ...)
    are matched too.
    """

    def __init__(self, cursor):
        self.by_name = defaultdict(set)
        cursor.execute(
            f'SELECT id, first_name, suffix, last_name FROM {WP_PREFIX}avm_members'
        )
        for row in cursor.fetchall():
            self._add(*row)
        cursor.execute(
            f'SELECT member_id, first_name, suffix, last_name '
            f'FROM {WP_PREFIX}avm_member_name_aliases'
        )
        for row in cursor.fetchall():
            self._add(*row)

    def _add(self, member_id, first_name, suffix, last_name) -> None:
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
                           kamp_type_id: int, dates: list[date],
                           dry_run: bool) -> tuple[int | None, bool]:
    """Return (activity_id, usable) for the year's Kamp activity.

    There is one camp a year, so an existing Kamp activity of that year is
    reused whatever its name. With several, the one named like the camp
    folder wins; otherwise the workbook is not imported (usable False).
    A new activity gets the place name, the folder as kenmerk and the
    grid's first and last day. In a dry-run it is only announced
    (activity_id None, usable True).
    """
    cursor.execute(
        f'SELECT id, name FROM {WP_PREFIX}avm_activities '
        'WHERE type_id = %s AND year = %s ORDER BY id',
        (kamp_type_id, year),
    )
    existing = [(int(activity_id), existing_name) for activity_id, existing_name in cursor.fetchall()]
    if len(existing) > 1:
        existing = [row for row in existing if row[1].casefold() == name.casefold()]
        if len(existing) != 1:
            print(f'  SKIP: several Kamp activities in {year}, none uniquely named {name!r}')
            return None, False
    if existing:
        activity_id, existing_name = existing[0]
        print(f'  using Kamp activity id={activity_id} {existing_name!r}')
        return activity_id, True

    # name+year is unique over all activity types.
    cursor.execute(
        f'SELECT id FROM {WP_PREFIX}avm_activities WHERE name = %s AND year = %s',
        (name, year),
    )
    if cursor.fetchone():
        print(f'  SKIP: a non-Kamp activity {name!r} ({year}) already exists')
        return None, False
    if dry_run:
        print(f'  [dry-run] would create Kamp activity: {name} ({year}), kenmerk {kenmerk!r}')
        return None, True
    cursor.execute(
        f'''INSERT INTO {WP_PREFIX}avm_activities
            (name, type_id, year, kenmerk, start_date, end_date)
            VALUES (%s, %s, %s, %s, %s, %s)''',
        (name, kamp_type_id, year, kenmerk,
         min(dates).isoformat(), max(dates).isoformat()),
    )
    activity_id = int(cursor.lastrowid)
    print(f'  created activity id={activity_id}')
    return activity_id, True


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


def sheet_size(sheet, workbook_type: str) -> tuple[int, int]:
    if workbook_type == 'openpyxl':
        return sheet.max_row, sheet.max_column
    return sheet.nrows, sheet.ncols


def is_number(value) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool)


def get_legacy_sheet(workbook, workbook_type: str):
    """Return (sheet, header_row, name_column) of the older registration form."""
    if workbook_type == 'openpyxl':
        names = {name.strip().casefold(): name for name in workbook.sheetnames}
        exact = names.get(LEGACY_SHEET_NAME)
        sheet = workbook[exact] if exact else None
    else:
        sheet = next(
            (workbook.sheet_by_index(index) for index in range(workbook.nsheets)
             if workbook.sheet_by_index(index).name.strip().casefold() == LEGACY_SHEET_NAME),
            None,
        )
    if sheet is None:
        return None
    rows, columns = sheet_size(sheet, workbook_type)
    for row in range(1, min(rows, LEGACY_HEADER_SEARCH_ROWS) + 1):
        for column in range(1, min(columns, LEGACY_HEADER_SEARCH_COLUMNS) + 1):
            text = cell_text(sheet, workbook_type, row, column).casefold()
            if text.startswith(LEGACY_NAME_HEADER):
                return sheet, row, column
    return None


def legacy_date_columns(sheet, workbook_type: str, header_row: int,
                        name_column: int, campaign_year: int) -> dict[int, date]:
    """Day numbers follow the name header; months are two rows above."""
    _, columns = sheet_size(sheet, workbook_type)
    day_columns = []
    for column in range(name_column + 1, columns + 1):
        if not is_number(cell_value(sheet, workbook_type, header_row, column)):
            break
        day_columns.append(column)
    if not day_columns:
        raise ValueError(f'no day numbers right of the name header in row {header_row}')

    month_row = header_row - 2
    months = {}
    for column in day_columns:
        label = cell_text(sheet, workbook_type, month_row, column).casefold()
        if label:
            if label not in DUTCH_MONTHS:
                raise ValueError(f'unknown month heading {label!r} in column {column}')
            months[column] = DUTCH_MONTHS[label]
    if not months:
        raise ValueError(f'no month headings in row {month_row}')

    # Month headings sit on merged cells and need not line up with the
    # month break, so only the first one is used; after that a day number
    # lower than the previous one starts the next month.
    result = {}
    current_month = months[min(months)]
    current_year = campaign_year
    previous_day = None
    for column in day_columns:
        day = int(cell_value(sheet, workbook_type, header_row, column))
        if previous_day is not None and day < previous_day:
            current_month += 1
            if current_month > 12:
                current_month, current_year = 1, current_year + 1
        previous_day = day
        result[column] = date(current_year, current_month, day)
    return result


def legacy_label_columns(sheet, workbook_type: str, header_row: int,
                         last_day_column: int) -> dict[str, list[int]]:
    """Find nights/notes/diet columns after the day grid by their headings."""
    _, columns = sheet_size(sheet, workbook_type)
    found = {'nights': [], 'notes': [], 'diet': []}
    for column in range(last_day_column + 1, columns + 1):
        label = ' '.join(
            cell_text(sheet, workbook_type, row, column)
            for row in range(max(1, header_row - 2), header_row + 1)
        ).casefold()
        if 'eetopmerking' in label or 'dieet' in label:
            found['diet'].append(column)
        elif 'opmerking' in label:
            found['notes'].append(column)
        elif 'nachten' in label:
            found['nights'].append(column)
    return found


def legacy_participant_rows(sheet, workbook_type: str, header_row: int,
                            name_column: int, date_columns: dict[int, date]):
    """Yield people from the older form, skipping per-day total rows."""
    rows, _ = sheet_size(sheet, workbook_type)
    labels = legacy_label_columns(sheet, workbook_type, header_row, max(date_columns))

    def texts(row: int, columns: list[int]) -> list[str]:
        return [
            cell_text(sheet, workbook_type, row, column) for column in columns
            if cell_text(sheet, workbook_type, row, column)
            and not is_number(cell_value(sheet, workbook_type, row, column))
        ]

    for row in range(header_row + 1, rows + 1):
        name = cell_text(sheet, workbook_type, row, name_column)
        if not name or name.casefold().startswith('totaal'):
            continue
        # Summary rows ("Volwassenen", "Kinders", ...) hold counts per day.
        if any(is_number(cell_value(sheet, workbook_type, row, column))
               for column in date_columns):
            continue

        days = {
            day: cell_text(sheet, workbook_type, row, column)
            for column, day in date_columns.items()
        }
        notes = texts(row, labels['notes'])
        diet = texts(row, labels['diet'])
        if not any(days.values()) and not notes and not diet:
            continue

        nights = None
        for column in labels['nights']:
            value = cell_value(sheet, workbook_type, row, column)
            if is_number(value):
                nights = int(value)
                break
        if nights is None:
            nights = sum(
                bool(status) and status.casefold() != 'on' and '?' not in status
                for status in days.values()
            )

        yield {
            'name': name,
            'days': days,
            'nights': nights,
            'nawacht': 0,
            'notes': '\n'.join(notes) or None,
            'diet': '; '.join(diet) or None,
        }


def read_participants(workbook, workbook_type: str, year: int):
    """Return (date_columns, participants) for whichever layout the workbook has."""
    sheet = get_camp_sheet(workbook, workbook_type)
    if sheet is not None:
        date_columns = camp_date_columns(sheet, workbook_type, year)
        return date_columns, participant_rows(sheet, workbook_type, date_columns)

    legacy = get_legacy_sheet(workbook, workbook_type)
    if legacy is None:
        raise ValueError(
            f'neither sheet {SHEET_NAME!r} nor a {LEGACY_SHEET_NAME!r} sheet '
            f'with a {LEGACY_NAME_HEADER!r} header'
        )
    sheet, header_row, name_column = legacy
    date_columns = legacy_date_columns(sheet, workbook_type, header_row, name_column, year)
    return date_columns, legacy_participant_rows(
        sheet, workbook_type, header_row, name_column, date_columns
    )


def import_file(cursor, matcher: MemberMatcher, path: Path,
                kamp_type_id: int, dry_run: bool) -> tuple[int, int, int]:
    year, activity_name, kenmerk = extract_year_activity(path)
    if not year:
        print(f'  SKIP (no year found in camp directory or filename): {path}')
        return 0, 0, 0

    workbook, workbook_type = open_workbook(path)
    if not workbook:
        return 0, 0, 0
    try:
        date_columns, participants = read_participants(workbook, workbook_type, year)
    except ValueError as error:
        print(f'  SKIP ({error}): {path}')
        return 0, 0, 0

    print(
        f'\n{path.name} -> activity={activity_name!r}, year={year}, '
        f'dates={min(date_columns.values())}..{max(date_columns.values())}'
    )
    activity_id, usable = get_or_create_activity(
        cursor, activity_name, year, kenmerk, kamp_type_id,
        list(date_columns.values()), dry_run
    )
    if not usable:
        return 0, 0, 0

    imported = unmatched = ambiguous = 0
    for participant in participants:
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

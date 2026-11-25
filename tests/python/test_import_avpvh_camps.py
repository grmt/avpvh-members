"""Tests for scripts/import-avpvh-camps.py.

Workbooks are generated per test with openpyxl; all names are fictitious.
No database is used: a fake cursor stands in for pymysql.
"""

import importlib.util
import sys
from datetime import date
from pathlib import Path

import openpyxl
import pytest

SCRIPTS = Path(__file__).resolve().parents[2] / 'scripts'
sys.path.insert(0, str(SCRIPTS))
_spec = importlib.util.spec_from_file_location(
    'import_avpvh_camps', SCRIPTS / 'import-avpvh-camps.py'
)
camps = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(camps)


# --- workbook builders -----------------------------------------------------

def legacy_workbook(path: Path, *, name_column=1, header_row=3, rows=(),
                    month_labels=None, days=(22, 23, 24, 25, 1, 2),
                    extra_headers=None, sheet_name='Inschrijving'):
    """Older registration form: name header, day numbers, months 2 rows up.

    rows: dicts keyed 'name', 'd<n>' (n-th day column) or 'x<n>' (n-th column
    after the day grid). extra_headers: {n: (row offset, label)} for the
    columns after the day grid.
    """
    workbook = openpyxl.Workbook()
    sheet = workbook.active
    sheet.title = sheet_name
    first_day = name_column + 1
    after = first_day + len(days)
    sheet.cell(row=header_row, column=name_column, value='VOORNAAM & ACHTERNAAM')
    for offset, day in enumerate(days):
        sheet.cell(row=header_row, column=first_day + offset, value=day)
    # Like the real files: the first heading sits one column after the first day.
    labels = month_labels if month_labels is not None else {1: 'Juli'}
    for offset, label in labels.items():
        sheet.cell(row=header_row - 2, column=first_day + offset, value=label)
    for offset, (row_delta, label) in (extra_headers or {}).items():
        sheet.cell(row=header_row + row_delta, column=after + offset, value=label)
    for index, values in enumerate(rows, start=1):
        for key, value in values.items():
            if key == 'name':
                column = name_column
            elif key.startswith('d'):
                column = first_day + int(key[1:])
            else:
                column = after + int(key[1:])
            sheet.cell(row=header_row + index, column=column, value=value)
    workbook.save(path)
    return path


def modern_workbook(path: Path, rows=()):
    """The fixed 'totaal inschrijvingen' grid of 2019 and later."""
    workbook = openpyxl.Workbook()
    sheet = workbook.active
    sheet.title = 'totaal inschrijvingen'
    sheet.cell(row=camps.MONTH_ROW, column=camps.FIRST_DAY_COLUMN, value='juli')
    sheet.cell(row=camps.DAY_ROW, column=camps.FIRST_DAY_COLUMN, value=30)
    sheet.cell(row=camps.DAY_ROW, column=camps.FIRST_DAY_COLUMN + 1, value=31)
    sheet.cell(row=camps.MONTH_ROW, column=camps.FIRST_DAY_COLUMN + 2, value='augustus')
    sheet.cell(row=camps.DAY_ROW, column=camps.FIRST_DAY_COLUMN + 2, value=1)
    for index, (name, statuses) in enumerate(rows):
        row = camps.FIRST_PARTICIPANT_ROW + index
        sheet.cell(row=row, column=camps.NAME_COLUMN, value=name)
        for offset, status in enumerate(statuses):
            sheet.cell(row=row, column=camps.FIRST_DAY_COLUMN + offset, value=status)
    workbook.save(path)
    return path


def open_sheet(path: Path):
    return camps.open_workbook(path)


# --- fakes -----------------------------------------------------------------

class FakeCursor:
    """Answers the importer's queries from in-memory rows.

    activities: (id, name, year, type_id) tuples.
    """

    def __init__(self, members=(), aliases=(), activities=(), kamp_type=7):
        self.members = list(members)
        self.aliases = list(aliases)
        self.activities = list(activities)
        self.kamp_type = kamp_type
        self.executed = []
        self._result = []
        self.lastrowid = 100

    def execute(self, sql, params=()):
        sql = ' '.join(sql.split())
        self.executed.append((sql, params))
        self._result = []
        if 'FROM pvh_avm_members' in sql:
            self._result = self.members
        elif 'FROM pvh_avm_member_name_aliases' in sql:
            self._result = self.aliases
        elif 'FROM pvh_avm_activity_types' in sql:
            self._result = [(self.kamp_type,)] if self.kamp_type else []
        elif 'FROM pvh_avm_activities WHERE type_id' in sql:
            type_id, year = params
            self._result = [(a, n) for a, n, y, t in self.activities if t == type_id and y == year]
        elif 'FROM pvh_avm_activities WHERE name' in sql:
            name, year = params
            self._result = [(a,) for a, n, y, _ in self.activities if n == name and y == year]
        elif sql.startswith('INSERT'):
            self.lastrowid += 1

    def fetchall(self):
        return self._result

    def fetchone(self):
        return self._result[0] if self._result else None

    def inserts(self, table):
        return [(sql, params) for sql, params in self.executed
                if sql.startswith(f'INSERT INTO {table} ')]


class FakeXlrdSheet:
    """Just the parts of xlrd's Sheet the importer uses (0-based cells)."""

    def __init__(self, name, cells):
        self.name = name
        self.cells = cells
        self.nrows = max((r for r, _ in cells), default=-1) + 1
        self.ncols = max((c for _, c in cells), default=-1) + 1

    def cell_value(self, row, column):
        return self.cells.get((row, column), '')


class FakeXlrdBook:
    def __init__(self, *sheets):
        self.sheets = sheets
        self.nsheets = len(sheets)

    def sheet_by_index(self, index):
        return self.sheets[index]


# --- workbook selection ----------------------------------------------------

def touch(path: Path):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(b'')
    return path


def test_workbook_paths_prefers_overview_and_falls_back_per_camp(tmp_path):
    overview = touch(tmp_path / '2016 Plaats' / 'Overzicht Inschrijvingen 2016.xlsx')
    touch(tmp_path / '2016 Plaats' / 'inschrijvingen tussenstand.xlsx')
    general = touch(tmp_path / '2011 Plaats' / 'Inschrijvingsformulier Plaats algemeen.xlsx')
    touch(tmp_path / '2011 Plaats' / 'inschrijvingsformulier 2011 voorbeeld persoon.xlsx')
    collected = touch(tmp_path / '2013 Plaats' / 'Inschrijvingen Plaats 2013.xls')
    touch(tmp_path / '2013 Plaats' / 'Inschrijvingsformulier Plaats2013.xls')
    touch(tmp_path / '2014 Plaats' / 'sub' / 'Inschrijvingen in submap.xlsx')
    touch(tmp_path / '2015 Plaats' / '~$Inschrijvingen lock.xlsx')
    touch(tmp_path / 'Inschrijvingen in de root.xlsx')
    touch(tmp_path / '2012 Plaats' / 'notes.txt')

    assert camps.workbook_paths(tmp_path, latest=False) == sorted(
        [overview, general, collected]
    )


def test_workbook_paths_latest_only_uses_overviews(tmp_path):
    touch(tmp_path / '2011 Plaats' / 'Inschrijvingen algemeen.xlsx')
    with pytest.raises(SystemExit):
        camps.workbook_paths(tmp_path, latest=True)
    newest = touch(tmp_path / '2025 Plaats' / 'Overzicht 2025.xlsx')
    assert camps.workbook_paths(tmp_path, latest=True) == [newest]


def test_workbook_paths_single_file_and_errors(tmp_path):
    single = touch(tmp_path / 'any.xlsx')
    assert camps.workbook_paths(single, latest=False) == [single]
    with pytest.raises(SystemExit):
        camps.workbook_paths(touch(tmp_path / 'any.csv'), latest=False)
    with pytest.raises(SystemExit):
        camps.workbook_paths(tmp_path / 'missing', latest=False)
    empty = tmp_path / 'empty'
    empty.mkdir()
    with pytest.raises(SystemExit):
        camps.workbook_paths(empty, latest=False)


# --- legacy layout ---------------------------------------------------------

def test_legacy_sheet_found_in_column_b(tmp_path):
    path = legacy_workbook(tmp_path / 'b.xlsx', name_column=2, header_row=3)
    workbook, kind = open_sheet(path)
    sheet, header_row, name_column = camps.get_legacy_sheet(workbook, kind)
    assert (header_row, name_column) == (3, 2)
    assert sheet.title == 'Inschrijving'


def test_legacy_sheet_missing_or_without_header(tmp_path):
    workbook, kind = open_sheet(legacy_workbook(tmp_path / 'x.xlsx', sheet_name='Blad1'))
    assert camps.get_legacy_sheet(workbook, kind) is None

    workbook = openpyxl.Workbook()
    workbook.active.title = 'Inschrijving'
    workbook.active['A1'] = 'Iets anders'
    assert camps.get_legacy_sheet(workbook, 'openpyxl') is None


def test_legacy_sheet_with_xlrd_book():
    sheet = FakeXlrdSheet('Inschrijving ', {(2, 1): 'VOORNAAM & ACHTERNAAM hieronder', (2, 2): 19.0})
    book = FakeXlrdBook(FakeXlrdSheet('Informatie', {(0, 0): 'x'}), sheet)
    found, header_row, name_column = camps.get_legacy_sheet(book, 'xlrd')
    assert found is sheet
    assert (header_row, name_column) == (3, 2)
    assert camps.sheet_size(sheet, 'xlrd') == (3, 3)
    assert camps.get_legacy_sheet(FakeXlrdBook(FakeXlrdSheet('Blad1', {})), 'xlrd') is None


def test_legacy_dates_roll_over_on_day_number_not_heading(tmp_path):
    # "Augustus" placed over 24 July must not move 24/25 July into August.
    path = legacy_workbook(tmp_path / 'd.xlsx', month_labels={1: 'Juli', 2: 'Augustus'})
    workbook, kind = open_sheet(path)
    sheet, header_row, name_column = camps.get_legacy_sheet(workbook, kind)
    dates = camps.legacy_date_columns(sheet, kind, header_row, name_column, 2011)
    assert list(dates.values()) == [
        date(2011, 7, 22), date(2011, 7, 23), date(2011, 7, 24),
        date(2011, 7, 25), date(2011, 8, 1), date(2011, 8, 2),
    ]


def test_legacy_dates_roll_over_into_next_year(tmp_path):
    path = legacy_workbook(tmp_path / 'y.xlsx', days=(30, 31, 1), month_labels={0: 'December'})
    workbook, kind = open_sheet(path)
    sheet, header_row, name_column = camps.get_legacy_sheet(workbook, kind)
    dates = camps.legacy_date_columns(sheet, kind, header_row, name_column, 2011)
    assert list(dates.values())[-1] == date(2012, 1, 1)


@pytest.mark.parametrize('kwargs, message', [
    ({'days': ()}, 'no day numbers'),
    ({'month_labels': {}}, 'no month headings'),
    ({'month_labels': {0: 'Julember'}}, 'unknown month heading'),
])
def test_legacy_dates_errors(tmp_path, kwargs, message):
    workbook, kind = open_sheet(legacy_workbook(tmp_path / 'e.xlsx', **kwargs))
    sheet, header_row, name_column = camps.get_legacy_sheet(workbook, kind)
    with pytest.raises(ValueError, match=message):
        camps.legacy_date_columns(sheet, kind, header_row, name_column, 2011)


def legacy_rows(tmp_path, rows, extra_headers):
    path = legacy_workbook(tmp_path / 'p.xlsx', rows=rows, extra_headers=extra_headers)
    workbook, kind = open_sheet(path)
    sheet, header_row, name_column = camps.get_legacy_sheet(workbook, kind)
    dates = camps.legacy_date_columns(sheet, kind, header_row, name_column, 2011)
    return list(camps.legacy_participant_rows(sheet, kind, header_row, name_column, dates))


def test_legacy_participants_read_nights_notes_and_diet(tmp_path):
    headers = {0: (0, 'NACHTEN'), 1: (-1, 'Opmerkingen:'), 2: (-2, 'Eetopmerkingen')}
    rows = [
        {'name': 'Anna Voorbeeld', 'd0': 'v', 'd1': 'v', 'x0': 2, 'x1': 'Komt later', 'x2': 'vegetarisch'},
        {'name': 'Bram Testpersoon', 'd2': 'k', 'd3': 'k', 'd4': 'on', 'x1': 12.5},
        {'name': 'Volwassenen', 'd0': 17, 'd1': 18},
        {'name': 'Totaal per dag', 'd0': 'x'},
        {'name': 'Cor Leeg'},
        {'name': 'Dien Alleennotitie', 'x1': 'misschien'},
    ]
    people = legacy_rows(tmp_path, rows, headers)

    assert [person['name'] for person in people] == [
        'Anna Voorbeeld', 'Bram Testpersoon', 'Dien Alleennotitie',
    ]
    anna, bram, dien = people
    assert anna['nights'] == 2
    assert anna['notes'] == 'Komt later'
    assert anna['diet'] == 'vegetarisch'
    assert anna['days'][date(2011, 7, 22)] == 'v'
    assert anna['nawacht'] == 0
    # No nights value: counted from the grid, "on" (daytime only) excluded.
    assert bram['nights'] == 2
    assert bram['notes'] is None  # numeric note cells are calculations, not notes
    assert bram['diet'] is None
    assert dien['nights'] == 0


def test_legacy_participants_without_label_columns(tmp_path):
    people = legacy_rows(tmp_path, [{'name': 'Eva Fictief', 'd0': 'n', 'd1': 'n?'}], {})
    assert people[0]['nights'] == 1  # an uncertain "n?" is not counted


# --- layout detection and import -------------------------------------------

def test_read_participants_uses_modern_sheet_when_present(tmp_path):
    path = modern_workbook(tmp_path / 'm.xlsx', [('Fien Fictief', ['n', 'n', 'on'])])
    workbook, kind = open_sheet(path)
    dates, participants = camps.read_participants(workbook, kind, 2025)
    assert min(dates.values()) == date(2025, 7, 30)
    assert [person['name'] for person in participants] == ['Fien Fictief']


def test_read_participants_uses_legacy_sheet_and_reports_neither(tmp_path):
    path = legacy_workbook(tmp_path / 'l.xlsx', rows=[{'name': 'Gijs Fictief', 'd0': 'v'}])
    workbook, kind = open_sheet(path)
    dates, participants = camps.read_participants(workbook, kind, 2011)
    assert min(dates.values()) == date(2011, 7, 22)
    assert [person['name'] for person in participants] == ['Gijs Fictief']

    other = openpyxl.Workbook()
    other.active.title = 'Blad1'
    with pytest.raises(ValueError, match='neither sheet'):
        camps.read_participants(other, 'openpyxl', 2011)


def camp_dir_workbook(tmp_path):
    folder = tmp_path / '2011 Plaats'
    folder.mkdir()
    rows = [
        {'name': 'Anna Voorbeeld', 'd0': 'v', 'd1': 'v'},
        {'name': 'Onbekend Persoon', 'd0': 'v'},
    ]
    return legacy_workbook(folder / 'Inschrijvingen algemeen.xlsx', rows=rows)


def test_import_file_dry_run_counts_without_writing(tmp_path, capsys):
    path = camp_dir_workbook(tmp_path)
    cursor = FakeCursor(members=[(1, 'Anna', None, 'Voorbeeld')])
    matcher = camps.MemberMatcher(cursor)
    result = camps.import_file(cursor, matcher, path, kamp_type_id=7, dry_run=True)
    assert result == (1, 1, 0)
    assert not any(sql.startswith('INSERT') for sql, _ in cursor.executed)
    output = capsys.readouterr().out
    assert "activity='Plaats'" in output
    assert 'UNMATCHED: Onbekend Persoon' in output


def test_import_file_writes_participation_and_days(tmp_path):
    path = camp_dir_workbook(tmp_path)
    cursor = FakeCursor(members=[(1, 'Anna', None, 'Voorbeeld')])
    matcher = camps.MemberMatcher(cursor)
    result = camps.import_file(cursor, matcher, path, kamp_type_id=7, dry_run=False)
    assert result == (1, 1, 0)
    (_, params), = cursor.inserts('pvh_avm_activities')
    assert params == ('Plaats', 7, 2011, 'Plaats', '2011-07-22', '2011-08-02')
    assert len(cursor.inserts('pvh_avm_activity_participation_days')) == 2


def test_import_file_skips_unreadable_layout(tmp_path, capsys):
    folder = tmp_path / '2011 Plaats'
    folder.mkdir()
    other = openpyxl.Workbook()
    other.active.title = 'Blad1'
    other.save(folder / 'Inschrijvingen.xlsx')
    cursor = FakeCursor()
    result = camps.import_file(cursor, camps.MemberMatcher(cursor),
                               folder / 'Inschrijvingen.xlsx', 7, True)
    assert result == (0, 0, 0)
    assert 'SKIP (neither sheet' in capsys.readouterr().out


# --- camp activity and name variants ---------------------------------------

@pytest.mark.parametrize('folder, expected', [
    ('2021 Plaats III', (2021, 'Plaats', 'Plaats III')),
    ('2019 Plaats ', (2019, 'Plaats', 'Plaats')),
    ('2011 Twee Woorden', (2011, 'Twee Woorden', 'Twee Woorden')),
    ('Zonder jaar', (None, 'Zonder jaar', 'Zonder jaar')),
    ('2015 II', (2015, 'II', 'II')),
])
def test_extract_year_activity_names_camp_after_place(tmp_path, folder, expected):
    assert camps.extract_year_activity(tmp_path / folder / 'x.xlsx') == expected


def test_matcher_uses_recorded_name_variants():
    cursor = FakeCursor(
        members=[(1, 'Anna', None, 'Voorbeeld'), (2, 'Bram', 'de', 'Testpersoon')],
        aliases=[(1, 'Annie', '', 'Voorbeeld'), (2, 'Anna', '', 'Voorbeeld')],
    )
    matcher = camps.MemberMatcher(cursor)
    assert matcher.find('Annie Voorbeeld') == (1, 'matched')
    assert matcher.find('Bram de Testpersoon') == (2, 'matched')
    # A variant equal to another member's name makes it ambiguous.
    assert matcher.find('Anna Voorbeeld') == (None, 'ambiguous')
    assert matcher.find('Cor Onbekend') == (None, 'unmatched')


DATES = [date(2021, 7, 24), date(2021, 8, 7)]


def test_activity_reuses_the_years_only_camp_whatever_its_name(capsys):
    cursor = FakeCursor(activities=[(11, 'Plaats', 2021, 7), (40, 'Lezing', 2021, 3)])
    result = camps.get_or_create_activity(cursor, 'Plaats', 2021, 'Plaats III', 7, DATES, False)
    assert result == (11, True)
    cursor = FakeCursor(activities=[(11, 'Andere naam', 2021, 7)])
    assert camps.get_or_create_activity(cursor, 'Plaats', 2021, 'Plaats III', 7, DATES, False) == (11, True)
    assert not cursor.inserts('pvh_avm_activities')
    assert "using Kamp activity id=11 'Andere naam'" in capsys.readouterr().out


def test_activity_with_several_camps_needs_a_unique_name(capsys):
    cursor = FakeCursor(activities=[(11, 'Plaats', 2021, 7), (12, 'Elders', 2021, 7)])
    assert camps.get_or_create_activity(cursor, 'plaats', 2021, 'Plaats', 7, DATES, False) == (11, True)
    assert camps.get_or_create_activity(cursor, 'Derde', 2021, 'Derde', 7, DATES, False) == (None, False)
    assert 'SKIP: several Kamp activities in 2021' in capsys.readouterr().out


def test_activity_refuses_name_taken_by_other_type(capsys):
    cursor = FakeCursor(activities=[(40, 'Plaats', 2021, 3)])
    assert camps.get_or_create_activity(cursor, 'Plaats', 2021, 'Plaats', 7, DATES, False) == (None, False)
    assert 'non-Kamp activity' in capsys.readouterr().out
    assert not cursor.inserts('pvh_avm_activities')


def test_activity_created_with_dates_or_announced_in_dry_run(capsys):
    cursor = FakeCursor()
    assert camps.get_or_create_activity(cursor, 'Plaats', 2021, 'Plaats III', 7, DATES, True) == (None, True)
    assert not cursor.inserts('pvh_avm_activities')
    assert "would create Kamp activity: Plaats (2021), kenmerk 'Plaats III'" in capsys.readouterr().out
    activity_id, usable = camps.get_or_create_activity(cursor, 'Plaats', 2021, 'Plaats III', 7, DATES, False)
    assert usable
    assert activity_id == cursor.lastrowid
    (_, params), = cursor.inserts('pvh_avm_activities')
    assert params == ('Plaats', 7, 2021, 'Plaats III', '2021-07-24', '2021-08-07')


def test_import_file_skips_workbook_when_camp_is_ambiguous(tmp_path):
    path = camp_dir_workbook(tmp_path)
    cursor = FakeCursor(
        members=[(1, 'Anna', None, 'Voorbeeld')],
        activities=[(11, 'Een', 2011, 7), (12, 'Twee', 2011, 7)],
    )
    result = camps.import_file(cursor, camps.MemberMatcher(cursor), path, 7, False)
    assert result == (0, 0, 0)
    assert not cursor.inserts('pvh_avm_activity_participation')

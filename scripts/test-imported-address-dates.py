#!/usr/bin/env python3
"""Fictitious fixtures only. Run with the member-import Python dependencies.

Optional SQL integration: AVPVH_ADDRESS_TEST_DB=address_fixture and
AVPVH_ADDRESS_TEST_PORT pointing to a disposable local MariaDB instance.
"""
import importlib.util
import os
from pathlib import Path
import unittest
from datetime import date, datetime
from unittest.mock import patch

import openpyxl
import pymysql

from _avpvh_import_common import imported_address_start, IMPORTED_ADDRESS_VALID_FROM


def load_script(filename, name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


reconcile = load_script('reconcile-members.py', 'reconcile')
initial_import = load_script('import-avpvh-members.py', 'initial_import')


def sheet_row():
    return dict(first_name='Testlid', last_name='Voorbeeld', suffix='',
                email='testlid@example.test', birth_date=None, phone='', mobile='',
                emergency_contact='', street='Fictieve straat', house_number='10',
                postal_code='1234 AB', city='Teststad', country='Nederland',
                address_valid_from=IMPORTED_ADDRESS_VALID_FROM)


class AddressPolicyTests(unittest.TestCase):
    def test_missing_dates_use_baseline(self):
        for value in (None, '', ' ', '0000-00-00'):
            with self.subTest(value=value):
                self.assertEqual(imported_address_start(value), date(2025, 1, 1))

    def test_explicit_dates_are_preserved(self):
        for value in ('02-06-2026', '2026-06-02', '02/06/2026',
                      '2026-06-02 00:00:00', date(2026, 6, 2), datetime(2026, 6, 2)):
            with self.subTest(value=value):
                self.assertEqual(imported_address_start(value), date(2026, 6, 2))

    def test_invalid_date_is_rejected(self):
        with self.assertRaises(ValueError):
            imported_address_start('31-02-2026')

    def test_newer_self_service_address_wins(self):
        old, incoming = sheet_row(), sheet_row()
        old['street'] = 'Nieuwe fictieve straat'
        old['address_valid_from'] = date(2026, 7, 1)
        incoming['phone'] = 'fixture-phone'
        changes = reconcile.diff_member(old, incoming)
        self.assertNotIn('address', changes)
        self.assertIn('phone', changes)

    def test_newer_explicit_import_is_accepted(self):
        old, incoming = sheet_row(), sheet_row()
        incoming['street'] = 'Andere fictieve straat'
        incoming['address_valid_from'] = date(2026, 7, 1)
        self.assertIn('address', reconcile.diff_member(old, incoming))

    def test_no_address_can_accept_an_explicit_older_date(self):
        old, incoming = sheet_row(), sheet_row()
        for field in reconcile.ADDRESS_FIELDS:
            old[field] = None
        old['address_valid_from'] = None
        incoming['address_valid_from'] = date(2024, 1, 1)
        self.assertIn('address', reconcile.diff_member(old, incoming))

    def test_excel_date_and_missing_header(self):
        for headers, values, expected in (
            (['Roepnaam', 'Achternaam'], ['Testlid', 'Voorbeeld'], date(2025, 1, 1)),
            (['Roepnaam', 'Achternaam', 'Begindatum adres'],
             ['Testlid', 'Voorbeeld', datetime(2026, 7, 1)], date(2026, 7, 1)),
        ):
            wb = openpyxl.Workbook()
            wb.active.append(headers)
            wb.active.append(values)
            parsed = next(iter(reconcile.load_sheet_rows(wb.active).values()))
            self.assertEqual(parsed['address_valid_from'], expected)


@unittest.skipUnless(os.getenv('AVPVH_ADDRESS_TEST_DB') == 'address_fixture',
                     'SQL tests require a disposable address_fixture database')
class AddressSQLTests(unittest.TestCase):
    def setUp(self):
        self.db = pymysql.connect(host='127.0.0.1',
            port=int(os.getenv('AVPVH_ADDRESS_TEST_PORT', '16614')),
            user='root', password='address-fixture-only', database='address_fixture')
        self.cur = self.db.cursor()
        self.prefix_patch = patch.multiple(reconcile, WP_PREFIX='pdpy_')
        self.prefix_patch.start()
        self.initial_prefix = patch.object(initial_import, 'WP_PREFIX', 'pdpy_')
        self.initial_prefix.start()
        self.cur.execute('CREATE TABLE pdpy_avm_members (id INT AUTO_INCREMENT PRIMARY KEY, '
            'lldap_user_id VARCHAR(255), first_name VARCHAR(100), last_name VARCHAR(100), '
            'suffix VARCHAR(50), birth_date DATE NULL, phone VARCHAR(100), mobile VARCHAR(100), '
            'emergency_contact VARCHAR(255), status VARCHAR(20), joined_year INT, left_year INT) ENGINE=InnoDB')
        self.cur.execute('CREATE TABLE pdpy_avm_addresses (id INT AUTO_INCREMENT PRIMARY KEY, '
            'member_id INT, street VARCHAR(255), house_number VARCHAR(20), postal_code VARCHAR(20), '
            'city VARCHAR(100), country VARCHAR(100), valid_from DATE NULL, valid_until DATE NULL) ENGINE=InnoDB')
        self.cur.execute('CREATE TABLE pdpy_avm_fees (member_id INT, year INT, status VARCHAR(20)) ENGINE=InnoDB')

    def tearDown(self):
        self.db.rollback()
        for table in ('addresses', 'fees', 'members'):
            self.cur.execute(f'DROP TABLE pdpy_avm_{table}')
        self.db.close()
        self.prefix_patch.stop()
        self.initial_prefix.stop()

    def add_member(self):
        self.cur.execute("INSERT INTO pdpy_avm_members (id,first_name,last_name,status) "
                         "VALUES(1,'Testlid','Voorbeeld','active')")

    def add_address(self, street, start, until=None):
        self.cur.execute('INSERT INTO pdpy_avm_addresses (member_id,street,valid_from,valid_until) '
                         'VALUES(1,%s,%s,%s)', (street, start, until))

    def test_current_address_selection_and_ties(self):
        self.add_member()
        self.add_address('Oud fictief', None)
        self.add_address('Nieuw fictief', date(2026, 1, 1))
        self.add_address('Laatste fictief', date(2026, 1, 1))
        self.add_address('Toekomst fictief', date(2099, 1, 1))
        self.add_address('Verlopen fictief', date(2026, 2, 1), date(2026, 2, 2))
        member = next(iter(reconcile.load_db_members(self.cur).values()))
        self.assertEqual(member['street'], 'Laatste fictief')
        self.assertNotIn('address', reconcile.diff_member(member, sheet_row()))

    def test_import_fits_before_future_address_without_closing_it(self):
        self.add_member()
        self.add_address('Historisch fictief', date(2024, 1, 1))
        self.add_address('Toekomst fictief', date(2099, 1, 1))
        reconcile.apply_update(self.cur, 1, {'address': ('old', 'new')}, sheet_row(), False)
        self.cur.execute('SELECT valid_from,valid_until FROM pdpy_avm_addresses ORDER BY id')
        self.assertEqual(self.cur.fetchall(), (
            (date(2024, 1, 1), date(2024, 12, 31)),
            (date(2099, 1, 1), None),
            (date(2025, 1, 1), date(2098, 12, 31))))

    def test_same_day_import_does_not_create_invalid_period(self):
        self.add_member()
        self.add_address('Baseline fictief', date(2025, 1, 1))
        reconcile.apply_update(self.cur, 1, {'address': ('old', 'new')}, sheet_row(), False)
        self.cur.execute('SELECT COUNT(*) FROM pdpy_avm_addresses WHERE valid_until < valid_from')
        self.assertEqual(self.cur.fetchone()[0], 0)
        current = next(iter(reconcile.load_db_members(self.cur).values()))
        self.assertEqual(current['street'], sheet_row()['street'])
        self.assertNotIn('address', reconcile.diff_member(current, sheet_row()))

    def test_dry_run_does_not_write(self):
        self.add_member()
        reconcile.apply_update(self.cur, 1, {'address': ('old', 'new')}, sheet_row(), True)
        self.cur.execute('SELECT COUNT(*) FROM pdpy_avm_addresses')
        self.assertEqual(self.cur.fetchone()[0], 0)

    def test_both_creation_paths_date_addresses(self):
        with patch.object(reconcile, 'lldap_create_user'), patch.object(reconcile, 'lldap_add_to_group'):
            reconcile.create_member(self.cur, None, sheet_row(), 1, False)
        wb = openpyxl.Workbook()
        wb.active.append(['Roepnaam', 'Achternaam', 'email', 'straat', 'Begindatum adres'])
        wb.active.append(['Testlid2', 'Voorbeeld2', 'second@example.test', 'Fictief2', datetime(2026, 3, 4)])
        with patch.object(initial_import, 'lldap_create_user'), patch.object(initial_import, 'lldap_add_to_group'):
            initial_import.import_sheet(self.cur, None, wb.active, 'active', 1, False)
        self.cur.execute('SELECT valid_from FROM pdpy_avm_addresses ORDER BY id')
        self.assertEqual(self.cur.fetchall(), ((date(2025, 1, 1),), (date(2026, 3, 4),)))


if __name__ == '__main__':
    unittest.main()

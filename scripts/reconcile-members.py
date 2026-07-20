#!/usr/bin/env python3
"""
Reconcile pvh_avm_members with a newer ledenlijst spreadsheet: updates
existing active members' birth_date/phone/mobile/emergency_contact/address
(and cleans up the legacy last_name/suffix split — see normalize_name_key())
where the sheet's "Leden" data differs, creates members present in the sheet
but not in the DB, and reports — never touches — DB members not found in
the sheet.

Matches by normalized name, not e-mail: many members' registered e-mail
differs from what's in a given spreadsheet snapshot, and e-mail-based
matching would create duplicates (see import-avpvh-members.py's docstring —
confirmed via --dry-run against this exact issue). A false *positive* name
match (silently merging two different people) is a worse failure mode than
a false negative, so matching is exact, never fuzzy — anything that doesn't
match exactly shows up in the "no sheet match" / "no DB match" groups below
for a human to check, not guessed at.

Only the "Leden" (active) sheet is reconciled — ex-leden is out of scope.
Members in the DB but not in the sheet are listed only, never deactivated.

Usage:
    python3 reconcile-members.py /path/to/ledenlijst.xlsx [--dry-run]

Dependencies:
    pip install pymysql openpyxl requests
"""

import argparse
from datetime import date, timedelta

import openpyxl
import requests

from _avpvh_import_common import (
    WP_PREFIX, GROUP_ACTIVE,
    read_secret, get_db, lldap_login, get_group_id,
    uid_from_email, lldap_create_user, lldap_add_to_group,
    sheet_headers, col, parse_date, age_on, placeholder_child_uid,
    normalize_name_key, SECRET_FILE,
)

FIELDS_TO_SYNC = ['birth_date', 'phone', 'mobile', 'emergency_contact']
ADDRESS_FIELDS = ['street', 'house_number', 'postal_code', 'city', 'country']


def blank(v) -> bool:
    return v is None or (isinstance(v, str) and v.strip() == '')


def load_sheet_rows(sheet) -> dict:
    headers = sheet_headers(sheet)
    rows = {}
    for row in sheet.iter_rows(min_row=2):
        def c(name): return col(row, headers, name)

        first_name = c('voornaam')
        last_name = c('achternaam') or c('naam')
        if not first_name or not last_name:
            continue

        key = normalize_name_key(first_name, last_name)
        if key in rows:
            print(f'  WARNING: duplicate name in sheet, keeping first occurrence: '
                  f'{first_name} {last_name}')
            continue

        rows[key] = {
            'first_name': first_name,
            'suffix': c('suffix'),
            'last_name': last_name,
            'email': c('email').removeprefix('mailto:'),
            'phone': c('telefoon'),
            'mobile': c('mobiel'),
            'birth_date': parse_date(c('geboortedatum')),
            'emergency_contact': c('noodcontact') or c('emergency_contact'),
            'street': c('straat'),
            'house_number': c('huisnummer') or c('nr'),
            'postal_code': c('postcode'),
            'city': c('woonplaats') or c('stad'),
            'country': c('land') or 'Nederland',
        }
    return rows


def load_db_members(cursor) -> dict:
    cursor.execute(f"""
        SELECT m.id, m.first_name, m.last_name, m.suffix, m.birth_date,
               m.phone, m.mobile, m.emergency_contact,
               a.street, a.house_number, a.postal_code, a.city, a.country
        FROM {WP_PREFIX}avm_members m
        LEFT JOIN {WP_PREFIX}avm_addresses a ON a.member_id = m.id
            AND (a.valid_from IS NULL OR a.valid_from <= CURDATE())
            AND (a.valid_until IS NULL OR a.valid_until >= CURDATE())
        WHERE m.status = 'active'
    """)
    members = {}
    for (mid, first, last, suffix, birth_date, phone, mobile, emergency,
         street, house_nr, postal, city, country) in cursor.fetchall():
        key = normalize_name_key(first, last)
        if key in members:
            print(f'  WARNING: duplicate name among active DB members, first one wins: '
                  f'{first} {last} (id={mid})')
            continue
        members[key] = {
            'id': mid, 'first_name': first, 'last_name': last, 'suffix': suffix,
            'birth_date': birth_date, 'phone': phone, 'mobile': mobile,
            'emergency_contact': emergency,
            'street': street, 'house_number': house_nr, 'postal_code': postal,
            'city': city, 'country': country,
        }
    return members


def diff_member(db_row: dict, sheet_row: dict) -> dict:
    """Field -> (old, new) for anything the sheet would change. Blank sheet
    cells never overwrite an existing value."""
    changes = {}
    for field in FIELDS_TO_SYNC:
        new = sheet_row[field]
        if blank(new):
            continue
        old = db_row[field]
        if str(old or '') != str(new):
            changes[field] = (old, new)

    if sheet_row['last_name'] and (
        db_row['last_name'] != sheet_row['last_name']
        or (db_row['suffix'] or '') != (sheet_row['suffix'] or '')
    ):
        changes['name_split'] = (
            f"last_name={db_row['last_name']!r} suffix={db_row['suffix']!r}",
            f"last_name={sheet_row['last_name']!r} suffix={sheet_row['suffix']!r}",
        )

    address_changed = any(
        not blank(sheet_row[f]) and str(db_row[f] or '') != str(sheet_row[f])
        for f in ADDRESS_FIELDS
    )
    if address_changed:
        changes['address'] = (
            ', '.join(f'{f}={db_row[f]!r}' for f in ADDRESS_FIELDS),
            ', '.join(f'{f}={sheet_row[f]!r}' for f in ADDRESS_FIELDS),
        )
    return changes


def apply_update(cursor, member_id: int, changes: dict, sheet_row: dict, dry_run: bool) -> None:
    updates = {field: sheet_row[field] for field in FIELDS_TO_SYNC if field in changes}
    if 'name_split' in changes:
        updates['last_name'] = sheet_row['last_name']
        updates['suffix'] = sheet_row['suffix']

    if updates and not dry_run:
        set_clause = ', '.join(f'{f} = %s' for f in updates)
        cursor.execute(
            f"UPDATE {WP_PREFIX}avm_members SET {set_clause} WHERE id = %s",
            (*updates.values(), member_id)
        )

    if 'address' in changes and not dry_run:
        today = date.today()
        yesterday = today - timedelta(days=1)
        # Close out the current address row, then insert the new one — address
        # is a history log (valid_from/valid_until), never overwritten in place,
        # matching class-member-profile-form.php's own self-service save path.
        cursor.execute(
            f"UPDATE {WP_PREFIX}avm_addresses SET valid_until = %s "
            f"WHERE member_id = %s AND (valid_until IS NULL OR valid_until >= %s)",
            (yesterday, member_id, today)
        )
        cursor.execute(
            f"""INSERT INTO {WP_PREFIX}avm_addresses
                (member_id, street, house_number, postal_code, city, country, valid_from)
                VALUES (%s,%s,%s,%s,%s,%s,%s)""",
            (member_id, sheet_row['street'], sheet_row['house_number'],
             sheet_row['postal_code'], sheet_row['city'], sheet_row['country'], today)
        )


def create_member(cursor, session: requests.Session, sheet_row: dict,
                  group_id: int, dry_run: bool) -> None:
    first_name = sheet_row['first_name']
    last_name = sheet_row['last_name']
    suffix = sheet_row['suffix']
    email = sheet_row['email']
    birth_date = sheet_row['birth_date']

    if not email or '@' not in email:
        print(f'  SKIP create (no e-mail on file): {first_name} {last_name}')
        return

    if birth_date and age_on(birth_date, date.today()) < 16:
        uid = placeholder_child_uid(session, first_name, last_name, dry_run)
        email = f'{uid}@avpvh.local'
    else:
        uid = uid_from_email(email)

    cursor.execute(f"SELECT id FROM {WP_PREFIX}avm_members WHERE lldap_user_id = %s", (uid,))
    if cursor.fetchone():
        print(f'  SKIP create (gezin deelt account, uid={uid}): {first_name} {last_name}')
        return

    lldap_create_user(session, uid, email, first_name, last_name, dry_run)
    lldap_add_to_group(session, uid, group_id, dry_run)

    if not dry_run:
        cursor.execute(
            f"""INSERT INTO {WP_PREFIX}avm_members
                (lldap_user_id, first_name, suffix, last_name, birth_date,
                 phone, mobile, emergency_contact, status)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,'active')""",
            (uid, first_name, suffix, last_name, birth_date,
             sheet_row['phone'], sheet_row['mobile'], sheet_row['emergency_contact'])
        )
        member_id = cursor.lastrowid
        if sheet_row['street'] or sheet_row['city']:
            cursor.execute(
                f"""INSERT INTO {WP_PREFIX}avm_addresses
                    (member_id, street, house_number, postal_code, city, country)
                    VALUES (%s,%s,%s,%s,%s,%s)""",
                (member_id, sheet_row['street'], sheet_row['house_number'],
                 sheet_row['postal_code'], sheet_row['city'], sheet_row['country'])
            )

    print(f'  CREATE: {first_name} {last_name} <{email}> → uid={uid}')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('xlsx', help='Path to ledenlijst xlsx')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    dry = args.dry_run

    session = requests.Session()
    lldap_login(session)
    group_active = get_group_id(session, GROUP_ACTIVE)

    conn = get_db(read_secret(SECRET_FILE))
    try:
        with conn.cursor() as cur:
            wb = openpyxl.load_workbook(args.xlsx, data_only=True)
            sheet_rows = load_sheet_rows(wb['Leden'])
            db_members = load_db_members(cur)

            matched = sorted(set(sheet_rows) & set(db_members))
            sheet_only = sorted(set(sheet_rows) - set(db_members))
            db_only = sorted(set(db_members) - set(sheet_rows))

            print(f'Sheet rows: {len(sheet_rows)}, active DB members: {len(db_members)}')
            print(f'Matched by name: {len(matched)}, sheet-only (candidates to create): '
                  f'{len(sheet_only)}, DB-only (not in sheet, not touched): {len(db_only)}')

            print('\n=== UPDATE (matched members with changed fields) ===')
            updated = 0
            for key in matched:
                db_row = db_members[key]
                changes = diff_member(db_row, sheet_rows[key])
                if not changes:
                    continue
                updated += 1
                print(f"{db_row['first_name']} {db_row['last_name']} (id={db_row['id']}):")
                for field, (old, new) in changes.items():
                    print(f'    {field}: {old!r} -> {new!r}')
                apply_update(cur, db_row['id'], changes, sheet_rows[key], dry)
            verb = 'would be updated' if dry else 'updated'
            print(f'{updated} member(s) {verb} ({len(matched) - updated} matched, no changes)')

            print('\n=== CREATE (in sheet, no matching DB member) ===')
            for key in sheet_only:
                create_member(cur, session, sheet_rows[key], group_active, dry)

            print('\n=== DB members not in this sheet — listed only, not touched ===')
            for key in db_only:
                m = db_members[key]
                print(f"  {m['first_name']} {m['last_name']} (id={m['id']})")

        if not dry:
            conn.commit()
            print('\nCommitted.')
        else:
            print('\nDry-run complete — no changes written.')
    finally:
        conn.close()


if __name__ == '__main__':
    main()

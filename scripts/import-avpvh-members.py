#!/usr/bin/env python3
"""
Import AVP-PvH members from the XLS ledenlijst into LLDAP + pvh_avm_* tables.

Usage:
    python3 import-avpvh-members.py /path/to/ledenlijst.xlsx [--dry-run]

Sheet "Leden"    → status = active  → LLDAP group "leden"
Sheet "ex-leden" → status = inactive → LLDAP group "ex-leden"

Idempotent on email. WP user creation is deferred to first login.

Dependencies:
    pip install pymysql openpyxl requests
"""

import argparse
import re
import sys
from datetime import date, datetime

import pymysql
import openpyxl
import requests

SECRET_FILE   = '/opt/docker/secrets/compose/mysql_password.txt'
DB_HOST       = '127.0.0.1'
DB_PORT       = 6603
DB_USER       = 'wp_user'
DB_NAME       = 'wpdb'
WP_PREFIX     = 'pvh_'
CURRENT_YEAR  = date.today().year

LLDAP_URL     = 'https://leden-admin.avphilipsvanhorne.nl'
LLDAP_ADMIN   = 'admin'
LLDAP_SECRET_FILE = '/opt/docker/secrets/compose/lldap_admin_password.txt'

GROUP_ACTIVE   = 'leden'
GROUP_INACTIVE = 'ex-leden'

# Normalize xlsx header names to internal field names
HEADER_ALIASES = {
    'voorna(a)m(en)': 'voornaam',
    'e-mailadres':    'email',
    'telefoonnummer': 'telefoon',
    'plaats':         'woonplaats',
    'contact bij calamiteit:': 'noodcontact',
}

# ex-leden sheet has no header row; col 0 contains a left-year note
EX_LEDEN_COLS = ['vertrekjaar', 'achternaam', 'voornaam', 'geboortedatum',
                 'straat', 'huisnummer', 'postcode', 'woonplaats',
                 'land', 'email', 'telefoon', 'mobiel']


# ---------------------------------------------------------------------------
# MariaDB helpers
# ---------------------------------------------------------------------------

def read_secret(path: str) -> str:
    with open(path) as f:
        return f.read().strip()


def get_db(password: str):
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT,
        user=DB_USER, password=password,
        database=DB_NAME, charset='utf8mb4',
    )


# ---------------------------------------------------------------------------
# LLDAP GraphQL helpers
# ---------------------------------------------------------------------------

def lldap_login(session: requests.Session) -> None:
    password = read_secret(LLDAP_SECRET_FILE)
    r = session.post(f'{LLDAP_URL}/auth/simple/login', json={
        'username': LLDAP_ADMIN,
        'password': password,
    }, timeout=10)
    r.raise_for_status()
    token = r.json()['token']
    session.headers['Authorization'] = f'Bearer {token}'


def graphql(session: requests.Session, query: str, variables: dict | None = None) -> dict:
    r = session.post(f'{LLDAP_URL}/api/graphql',
                     json={'query': query, 'variables': variables or {}},
                     timeout=10)
    r.raise_for_status()
    body = r.json()
    if 'errors' in body:
        raise RuntimeError(f'GraphQL error: {body["errors"]}')
    return body.get('data', {})


def get_group_id(session: requests.Session, group_name: str) -> int:
    data = graphql(session, 'query { groups { id displayName } }')
    for g in data.get('groups', []):
        if g['displayName'].lower() == group_name.lower():
            return int(g['id'])
    # Create group if missing
    data = graphql(session,
        'mutation CreateGroup($name: String!) { createGroup(name: $name) { id } }',
        {'name': group_name})
    return int(data['createGroup']['id'])


def uid_from_email(email: str) -> str:
    local = email.split('@')[0].lower()
    return re.sub(r'[^a-z0-9._-]', '.', local)


def lldap_user_exists(session: requests.Session, uid: str) -> bool:
    try:
        data = graphql(session,
            'query GetUser($id: String!) { user(userId: $id) { id } }',
            {'id': uid})
        return data.get('user') is not None
    except RuntimeError:
        return False


def lldap_create_user(session: requests.Session, uid: str, email: str,
                      first_name: str, last_name: str, dry_run: bool) -> bool:
    if lldap_user_exists(session, uid):
        print(f'    LLDAP user already exists: {uid}')
        return True
    if dry_run:
        print(f'    [dry-run] would create LLDAP user: {uid} <{email}>')
        return True
    graphql(session,
        '''mutation CreateUser($user: CreateUserInput!) {
               createUser(user: $user) { id }
           }''',
        {'user': {
            'id':          uid,
            'email':       email,
            'displayName': f'{first_name} {last_name}'.strip(),
        }})
    return True


def lldap_add_to_group(session: requests.Session, uid: str, group_id: int,
                       dry_run: bool) -> None:
    if dry_run:
        return
    try:
        graphql(session,
            '''mutation Add($userId: String!, $groupId: Int!) {
                   addUserToGroup(userId: $userId, groupId: $groupId) { ok }
               }''',
            {'userId': uid, 'groupId': group_id})
    except RuntimeError as e:
        if 'unique-memberships' in str(e) or 'Duplicate entry' in str(e):
            pass  # already a member
        else:
            raise


# ---------------------------------------------------------------------------
# XLS parsing helpers
# ---------------------------------------------------------------------------

def col(row: tuple, headers: list[str], name: str) -> str:
    try:
        idx = headers.index(name)
        v = row[idx].value if hasattr(row[idx], 'value') else row[idx]
        return str(v).strip() if v is not None else ''
    except ValueError:
        return ''


def parse_date(raw: str):
    for fmt in ('%d-%m-%Y', '%Y-%m-%d', '%d/%m/%Y'):
        try:
            return datetime.strptime(raw, fmt).date()
        except (ValueError, TypeError):
            pass
    return None


def parse_year(raw: str):
    m = re.search(r'\b(19|20)\d{2}\b', str(raw))
    return int(m.group()) if m else None


# ---------------------------------------------------------------------------
# Import logic
# ---------------------------------------------------------------------------

def import_sheet(cursor, session: requests.Session, sheet,
                 status: str, group_id: int, dry_run: bool,
                 positional_headers: list[str] | None = None) -> None:

    if positional_headers is not None:
        headers = positional_headers
        rows = sheet.iter_rows()
    else:
        raw = [str(c.value).strip().lower() if c.value else ''
               for c in next(sheet.iter_rows(min_row=1, max_row=1))]
        headers = [HEADER_ALIASES.get(h, h) for h in raw]
        rows = sheet.iter_rows(min_row=2)

    def c(row, name): return col(row, headers, name)

    for row in rows:
        email = c(row, 'email').removeprefix('mailto:')
        if not email or '@' not in email:
            continue

        last_name  = c(row, 'achternaam') or c(row, 'naam')
        first_name = c(row, 'voornaam')
        phone      = c(row, 'telefoon')
        mobile     = c(row, 'mobiel')
        birth_date = parse_date(c(row, 'geboortedatum'))
        joined_yr  = parse_year(c(row, 'lidjaar') or c(row, 'lid jaar'))
        left_yr    = parse_year(c(row, 'vertrekjaar') or c(row, 'vertrek jaar'))
        emergency  = c(row, 'noodcontact') or c(row, 'emergency_contact')
        street     = c(row, 'straat')
        house_nr   = c(row, 'huisnummer') or c(row, 'nr')
        postal     = c(row, 'postcode')
        city       = c(row, 'woonplaats') or c(row, 'stad')
        country    = c(row, 'land') or 'Nederland'

        uid = uid_from_email(email)

        # Skip if this LLDAP uid already has a member record (family sharing one email)
        cursor.execute(
            f"SELECT id FROM {WP_PREFIX}avm_members WHERE lldap_user_id = %s",
            (uid,)
        )
        if cursor.fetchone():
            print(f'  skip (gezin deelt account): {last_name}, {first_name} → uid={uid}')
            continue

        # 1. Create LLDAP user
        lldap_create_user(session, uid, email, first_name, last_name, dry_run)
        lldap_add_to_group(session, uid, group_id, dry_run)

        if not dry_run:
            # 2. Insert pvh_avm_members
            cursor.execute(
                f"""INSERT INTO {WP_PREFIX}avm_members
                    (lldap_user_id, first_name, last_name, birth_date,
                     phone, mobile, emergency_contact, status, joined_year, left_year)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                (uid, first_name, last_name, birth_date,
                 phone, mobile, emergency, status, joined_yr, left_yr)
            )
            member_id = cursor.lastrowid

            # 3. Insert address
            if street or city:
                cursor.execute(
                    f"""INSERT INTO {WP_PREFIX}avm_addresses
                        (member_id, street, house_number, postal_code, city, country, valid_until)
                        VALUES (%s,%s,%s,%s,%s,%s,NULL)""",
                    (member_id, street, house_nr, postal, city, country)
                )

            # 4. Insert pending fee for current year (active members only)
            if status == 'active':
                cursor.execute(
                    f"""INSERT IGNORE INTO {WP_PREFIX}avm_fees (member_id, year, status)
                        VALUES (%s,%s,'pending')""",
                    (member_id, CURRENT_YEAR)
                )

        print(f'  imported ({status}): {last_name}, {first_name} <{email}> → uid={uid}')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('xlsx', help='Path to ledenlijst xlsx')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()

    db_password = read_secret(SECRET_FILE)
    conn = get_db(db_password)

    session = requests.Session()
    lldap_login(session)

    wb = openpyxl.load_workbook(args.xlsx, data_only=True)
    print(f'Sheets: {wb.sheetnames}')

    try:
        with conn.cursor() as cur:
            group_active   = get_group_id(session, GROUP_ACTIVE)
            group_inactive = get_group_id(session, GROUP_INACTIVE)
            print(f'Groups: {GROUP_ACTIVE}={group_active}, {GROUP_INACTIVE}={group_inactive}')

            if 'Leden' in wb.sheetnames:
                print('\n=== Active members (sheet "Leden") ===')
                import_sheet(cur, session, wb['Leden'], 'active', group_active, args.dry_run)
            else:
                print('WARNING: sheet "Leden" not found')

            if 'ex-leden' in wb.sheetnames:
                print('\n=== Ex-members (sheet "ex-leden") ===')
                import_sheet(cur, session, wb['ex-leden'], 'inactive', group_inactive, args.dry_run,
                             positional_headers=EX_LEDEN_COLS)
            else:
                print('WARNING: sheet "ex-leden" not found')

        if not args.dry_run:
            conn.commit()
            print('\nCommitted.')
        else:
            print('\nDry-run complete — no changes written.')
    finally:
        conn.close()


if __name__ == '__main__':
    main()

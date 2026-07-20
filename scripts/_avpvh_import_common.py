#!/usr/bin/env python3
"""
Shared helpers for AVPVH member-sync scripts (import-avpvh-members.py,
reconcile-members.py). Not meant to run standalone.

Keeping this in one place matters beyond tidiness: the minor-placeholder-
account logic here is a real privacy control (members under 16 never get a
real login — see placeholder_child_uid()), not just a style preference, and
must behave identically wherever a new member gets created.

Dependencies:
    pip install pymysql openpyxl requests
"""

import re
from datetime import date, datetime

import pymysql
import requests

SECRET_FILE   = '/opt/docker/secrets/compose/wordpress_db_password.txt'
DB_HOST       = '127.0.0.1'
DB_PORT       = 6603
DB_USER       = 'wp_user'
DB_NAME       = 'wpdb'
WP_PREFIX     = 'pvh_'

LLDAP_URL          = 'https://leden-admin.avphilipsvanhorne.nl'
LLDAP_ADMIN        = 'admin'
LLDAP_SECRET_FILE  = '/opt/docker/secrets/compose/lldap_admin_password.txt'

GROUP_ACTIVE   = 'leden'
GROUP_INACTIVE = 'ex-leden'

# Normalize xlsx header names to internal field names.
# "voorna(a)m(en)" was the older sheet format's first-name header; the
# current sheet uses "Roepnaam" instead — both map to the same field.
HEADER_ALIASES = {
    'voorna(a)m(en)': 'voornaam',
    'roepnaam':       'voornaam',
    'e-mailadres':    'email',
    'telefoonnummer': 'telefoon',
    'plaats':         'woonplaats',
    'contact bij calamiteit:': 'noodcontact',
}


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

def sheet_headers(sheet) -> list[str]:
    """Normalized header row for a sheet with a real header (e.g. "Leden")."""
    raw = [str(c.value).strip().lower() if c.value else ''
           for c in next(sheet.iter_rows(min_row=1, max_row=1))]
    return [HEADER_ALIASES.get(h, h) for h in raw]


def col(row: tuple, headers: list[str], name: str) -> str:
    try:
        idx = headers.index(name)
        v = row[idx].value if hasattr(row[idx], 'value') else row[idx]
        # Excel stores plain numbers (house numbers, postal codes) as floats;
        # openpyxl hands them back as e.g. 47.0 — strip the spurious ".0".
        if isinstance(v, float) and v.is_integer():
            v = int(v)
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


def age_on(birth_date, today: date) -> int:
    years = today.year - birth_date.year
    if (today.month, today.day) < (birth_date.month, birth_date.day):
        years -= 1
    return years


def placeholder_child_uid(session: requests.Session, first_name: str, last_name: str,
                          dry_run: bool) -> str:
    """Members under 16 never get their own login (per policy) — generate a
    non-deliverable placeholder uid/email instead of using the row's real
    (guardian's) email, so no one can authenticate as them."""
    base = uid_from_email(f'{first_name}.{last_name}@placeholder')
    uid = base
    n = 1
    while not dry_run and lldap_user_exists(session, uid):
        n += 1
        uid = f'{base}{n}'
    return uid


TUSSENVOEGSEL_PREFIXES = (
    'van der ', 'van den ', 'van de ', 'ten ', 'ter ',
    'de ', 'van ', 'te ', 'von ', 'la ', 'le ', 'du ',
)


def normalize_name_key(first_name: str, last_name: str) -> tuple[str, str]:
    """Normalized (first, last) key for matching a DB member to a sheet row.
    pvh_avm_members stores tussenvoegsel three different ways depending on
    when a row was created: legacy rows glue it into last_name as
    "Achternaam, suffix" (empty suffix column); some rows glue a capitalized
    prefix directly onto last_name with no comma ("De Boe"); newer rows keep
    it in the separate suffix column with a clean last_name. Handling both
    comma- and prefix-based forms means this is a no-op for sheet rows,
    which never contain either."""
    last = (last_name or '').strip()
    if ',' in last:
        core_last = last.split(',', 1)[0]
    else:
        lowered = last.lower()
        core_last = last
        for prefix in TUSSENVOEGSEL_PREFIXES:
            if lowered.startswith(prefix):
                core_last = last[len(prefix):]
                break
    return ((first_name or '').strip().lower(), core_last.strip().lower())


def first_name_contains(db_first_name: str, sheet_first_name: str) -> bool:
    """True if the sheet's (short) first name appears as a whole word inside
    the DB's first name — catches e.g. DB "Frank (Franciscus Maria
    Henricus)" vs sheet "Frank", or "Julie Vrijheid" vs sheet "Julie"."""
    words = re.findall(r"[^\W\d_]+", (db_first_name or '').lower(), re.UNICODE)
    return (sheet_first_name or '').strip().lower() in words

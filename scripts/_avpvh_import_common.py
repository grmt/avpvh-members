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

from __future__ import annotations

import re
import unicodedata
from datetime import date, datetime

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
    import pymysql

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


def fold_match_value(value: str) -> str:
    """Accent/case/punctuation-insensitive key; never use it for display."""
    decomposed = unicodedata.normalize('NFKD', (value or '').strip())
    unaccented = ''.join(c for c in decomposed if not unicodedata.combining(c))
    unaccented = re.sub(r"[.\-_,;:/\\]+", ' ', unaccented.lower())
    return re.sub(r"[^\w']+", ' ', unaccented, flags=re.UNICODE).strip()


def normalize_name_key(first_name: str, last_name: str,
                       suffix: str = '') -> tuple[str, str]:
    """Normalized (first, last) key for matching a DB member to a sheet row.
    pvh_avm_members stores tussenvoegsel three different ways depending on
    when a row was created: legacy rows glue it into last_name as
    "Achternaam, suffix" (empty suffix column); some rows glue a capitalized
    prefix directly onto last_name with no comma ("De Voorbeeld"); newer rows keep
    it in the separate suffix column with a clean last_name. Handling both
    comma- and prefix-based forms means this is a no-op for sheet rows,
    which never contain either."""
    last = (last_name or '').strip()
    explicit_suffix = (suffix or '').strip()
    if not explicit_suffix and ',' in last:
        core_last, explicit_suffix = (part.strip() for part in last.split(',', 1))
    else:
        lowered = fold_match_value(last)
        core_last = last
        for prefix in TUSSENVOEGSEL_PREFIXES + ('v/d ', 'vd '):
            folded_prefix = fold_match_value(prefix)
            if not explicit_suffix and lowered.startswith(f'{folded_prefix} '):
                word_count = len(prefix.strip().split())
                core_last = ' '.join(last.split()[word_count:])
                break
    # Deliberately omit the suffix. Legacy separate/combined/abbreviated
    # forms share a candidate set; multiple people are then ambiguous.
    return (fold_match_value(first_name), fold_match_value(core_last))


def load_member_name_index(cursor, status: str | None = None) -> dict:
    """Return normalized key -> candidates from official names and aliases."""
    where = ' WHERE status = %s' if status else ''
    params = (status,) if status else ()
    cursor.execute(
        f"SELECT id, first_name, suffix, last_name, status "
        f"FROM {WP_PREFIX}avm_members{where}", params
    )
    index: dict[tuple[str, str], dict[int, dict]] = {}
    for member_id, first, suffix, last, member_status in cursor.fetchall():
        key = normalize_name_key(first, last, suffix)
        index.setdefault(key, {})[int(member_id)] = {
            'id': int(member_id), 'match_type': 'official',
            'match_reason': 'officiële naam', 'status': member_status,
        }

    cursor.execute(f"SHOW TABLES LIKE '{WP_PREFIX}avm_member_name_aliases'")
    if cursor.fetchone():
        alias_where = ' WHERE m.status = %s' if status else ''
        cursor.execute(
            f"SELECT a.member_id, a.first_name, a.suffix, a.last_name, "
            f"a.alias_type, m.status FROM {WP_PREFIX}avm_member_name_aliases a "
            f"JOIN {WP_PREFIX}avm_members m ON m.id = a.member_id{alias_where}",
            params,
        )
        for member_id, first, suffix, last, alias_type, member_status in cursor.fetchall():
            key = normalize_name_key(first, last, suffix)
            candidates = index.setdefault(key, {})
            candidates.setdefault(int(member_id), {
                'id': int(member_id), 'match_type': 'alias',
                'match_reason': f'naamalias ({alias_type})', 'status': member_status,
            })
    return {key: list(candidates.values()) for key, candidates in index.items()}


def find_members_by_name_or_alias(index: dict, first_name: str,
                                  last_name: str, suffix: str = '') -> list[dict]:
    return index.get(normalize_name_key(first_name, last_name, suffix), [])


def normalize_postal_code(postal_code: str) -> str:
    compact = re.sub(r'\s+', '', (postal_code or '').strip()).upper()
    match = re.fullmatch(r'(\d{4})([A-Z]{2})', compact)
    return f'{match.group(1)} {match.group(2)}' if match else compact


def normalize_address_key(address: dict, city_aliases: dict | None = None,
                          street_aliases: dict | None = None) -> tuple[str, ...]:
    """Central comparison key while preserving original values for storage."""
    city_aliases = city_aliases or {}
    street_aliases = street_aliases or {}
    country = fold_match_value(address.get('country') or 'Nederland')
    postal = fold_match_value(normalize_postal_code(address.get('postal_code') or ''))
    city = fold_match_value(address.get('city') or '')
    city = fold_match_value(city_aliases.get((country, city), city))
    street = fold_match_value(address.get('street') or '')
    street = fold_match_value(street_aliases.get((country, postal, city, street), street))
    house_number = fold_match_value(address.get('house_number') or '')
    return country, postal, city, street, house_number


def first_name_contains(db_first_name: str, sheet_first_name: str) -> bool:
    """True if the sheet's (short) first name appears as a whole word inside
    the DB's first name — catches a short call name inside a longer formal
    name, or one part of a compound given name."""
    words = re.findall(r"[^\W\d_]+", (db_first_name or '').lower(), re.UNICODE)
    return (sheet_first_name or '').strip().lower() in words

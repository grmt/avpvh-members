#!/usr/bin/env python3
"""
Shared helpers for AVPVH member-sync scripts (import-avpvh-members.py,
reconcile-members.py). Not meant to run standalone.

Keeping this in one place matters beyond tidiness: the minor-placeholder-
account logic here is a real privacy control (members under 16 never get a
real login — see placeholder_child_uid()), not just a style preference, and
must behave identically wherever a new member gets created.

Accounts and groups live in OpenLDAP; they are written through the plugin
(scripts/directory-bridge.php, run with wp-cli in the wpcli-pvh container),
so the plugin's directory cache stays in step.

Dependencies:
    pip install pymysql openpyxl
"""

import os
import re
import subprocess
from datetime import date, datetime

import pymysql

SECRET_FILE   = '/opt/docker/secrets/compose/wordpress_db_password.txt'
DB_HOST       = '127.0.0.1'
DB_PORT       = 6603
DB_USER       = 'wp_user'
DB_NAME       = 'wpdb'
WP_PREFIX     = 'pvh_'

COMPOSE_DIR      = os.environ.get('AVPVH_COMPOSE_DIR', '/opt/docker/scripts')
DIRECTORY_BRIDGE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'directory-bridge.php')

GROUP_ACTIVE   = 'leden'
GROUP_INACTIVE = 'ex-leden'

# Undated addresses from the membership administration are historical data,
# not a new address change on the day the spreadsheet happens to be imported.
IMPORTED_ADDRESS_VALID_FROM = date(2025, 1, 1)

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
    'begindatum adres': 'adres_begindatum',
    'adres geldig vanaf': 'adres_begindatum',
    'address_valid_from': 'adres_begindatum',
    'valid_from': 'adres_begindatum',
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
# Directory (OpenLDAP) helpers, through the plugin
# ---------------------------------------------------------------------------

def directory(*args: str) -> str:
    """Run one directory-bridge.php command; returns its answer line."""
    with open(DIRECTORY_BRIDGE, 'rb') as code:
        r = subprocess.run(
            ['docker', 'compose', 'run', '--rm', '-T', 'wpcli-pvh',
             'wp', 'eval-file', '-', *args],
            cwd=COMPOSE_DIR, stdin=code, capture_output=True, timeout=120,
        )
    # PHP deprecation notices from other plugins share stdout; the answer
    # is the last line.
    lines = [l for l in r.stdout.decode(errors='replace').splitlines() if l.strip()]
    answer = lines[-1].strip() if lines else ''
    if r.returncode != 0 or answer not in ('yes', 'no', 'ok'):
        err = r.stderr.decode(errors='replace').strip().splitlines()
        raise RuntimeError(f'directory {args[0]} failed: {err[-1] if err else answer}')
    return answer


def uid_from_email(email: str) -> str:
    local = email.split('@')[0].lower()
    return re.sub(r'[^a-z0-9._-]', '.', local)


def directory_user_exists(uid: str) -> bool:
    return directory('exists', uid) == 'yes'


def directory_create_user(uid: str, email: str, first_name: str,
                          last_name: str, dry_run: bool) -> None:
    if directory_user_exists(uid):
        print(f'    directory account already exists: {uid}')
        return
    if dry_run:
        print(f'    [dry-run] would create directory account: {uid} <{email}>')
        return
    directory('create', uid, email, f'{first_name} {last_name}'.strip())


def directory_add_to_group(uid: str, group: str, dry_run: bool) -> None:
    """Adding an existing member is a no-op in AVPVH_Directory."""
    if dry_run:
        return
    directory('add-to-group', uid, group)


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


def imported_address_start(raw=None) -> date:
    """Preserve an explicit address start; only missing dates use the baseline."""
    if raw is None or str(raw).strip() in ('', '0000-00-00'):
        return IMPORTED_ADDRESS_VALID_FROM
    if isinstance(raw, datetime):
        return raw.date()
    if isinstance(raw, date):
        return raw
    text = str(raw).strip()
    parsed = parse_date(text)
    if parsed is not None:
        return parsed
    # col() turns Excel datetime cells into ISO timestamps.
    try:
        return datetime.fromisoformat(text).date()
    except ValueError as exc:
        raise ValueError('Invalid address start date in membership spreadsheet') from exc


def age_on(birth_date, today: date) -> int:
    years = today.year - birth_date.year
    if (today.month, today.day) < (birth_date.month, birth_date.day):
        years -= 1
    return years


def placeholder_child_uid(first_name: str, last_name: str, dry_run: bool) -> str:
    """Members under 16 never get their own login (per policy) — generate a
    non-deliverable placeholder uid/email instead of using the row's real
    (guardian's) email, so no one can authenticate as them."""
    base = uid_from_email(f'{first_name}.{last_name}@placeholder')
    uid = base
    n = 1
    while not dry_run and directory_user_exists(uid):
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
    prefix directly onto last_name with no comma ("De Voorbeeld"); newer rows keep
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
    the DB's first name — catches a short call name inside a longer formal
    name, or one part of a compound given name."""
    words = re.findall(r"[^\W\d_]+", (db_first_name or '').lower(), re.UNICODE)
    return (sheet_first_name or '').strip().lower() in words

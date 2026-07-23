#!/usr/bin/env python3
"""
Create avm_members rows (status='visitor') for the 7 people on the 2026
Goeblange camp sheet who aren't club members yet — relatives/partners of
existing members who came along for the dig but were never imported via
import-avpvh-members.py. Needed before populate-kampdeelname-2026.py can
attach their camp participation.

Same LLDAP-then-avm_members two-step as import-avpvh-members.py (every
avm_members row needs a matching lldap.users row — see class-db.php's
member_select(), an INNER JOIN on lldap_user_id). Uses a placeholder
@avpvh.local address like the existing minor-placeholder-account
convention, since none of these 7 have a real e-mail on file here.

ASSUMPTION (please correct if wrong): visitor rows are NOT added to the
"leden" LLDAP group, so they get no member-portal login — just a tracked
record for camp/fee history. If you want them to be able to log in, add
`lldap_add_to_group(session, uid, get_group_id(session, GROUP_ACTIVE), args.dry_run)`
per person below.

Usage:
    python3 add-2026-visitor-members.py [--dry-run]

Dependencies:
    pip install pymysql requests
"""
import argparse

import requests

from _avpvh_import_common import (
    WP_PREFIX, read_secret, get_db, lldap_login,
    uid_from_email, lldap_create_user, SECRET_FILE,
)

# (first_name, suffix, last_name, family_relation_member_id or None, note)
VISITORS = [
    ('Fenna', '', 'Lip', 118, 'sister of Dirk Lip (118) / Roos Lip (121); parents Henk & Mariska not in system'),
    ('Iris', 'de', 'Zwart', 32, "partner of Olaf Boekholt (32)"),
    ('Jessica', '', 'Hammarlund Bergmann', None, 'no known household link'),
    ('May', '', 'Hasendonckx', 51, 'partner of Gerrit Hasendonckx (51)'),
    ('Taras', '', 'Muravskiy', None, 'no known household link'),
    ('Dean', '', 'Berendsen', 80, 'son of Sylvia Soulier (80)'),
    ('Bram', '', 'Keijers', 57, 'relative of Jaap Keijers (57)'),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    conn = get_db(read_secret(SECRET_FILE))
    session = requests.Session()
    lldap_login(session)

    created = {}
    try:
        with conn.cursor() as cur:
            for first_name, suffix, last_name, family_relation, note in VISITORS:
                uid = uid_from_email(f'{first_name}.{last_name}@avpvh.local')
                email = f'{uid}@avpvh.local'
                display_name = f'{first_name} {suffix} {last_name}'.replace('  ', ' ').strip()

                cur.execute(
                    f"SELECT id FROM {WP_PREFIX}avm_members WHERE lldap_user_id = %s", (uid,)
                )
                existing = cur.fetchone()
                if existing:
                    print(f'  skip (already exists): {display_name} -> member id {existing[0]}')
                    created[display_name] = existing[0]
                    continue

                lldap_create_user(session, uid, email, first_name, last_name, args.dry_run)
                # Deliberately NOT added to the "leden" LLDAP group — see
                # docstring assumption above.

                if not args.dry_run:
                    cur.execute(
                        f"""INSERT INTO {WP_PREFIX}avm_members
                            (lldap_user_id, first_name, suffix, last_name, status, family_relation_member_id)
                            VALUES (%s, %s, %s, %s, 'visitor', %s)""",
                        (uid, first_name, suffix, last_name, family_relation)
                    )
                    member_id = cur.lastrowid
                    created[display_name] = member_id
                    print(f'  created (visitor): {display_name} <{email}> -> member id {member_id}  ({note})')
                else:
                    print(f'  [dry-run] would create (visitor): {display_name} <{email}>  ({note})')

        if not args.dry_run:
            conn.commit()
            print('\nCommitted.')
            print('\nAdd these to NAME_TO_MEMBER_ID in populate-kampdeelname-2026.py '
                  'and remove them from UNLINKED_NAMES:')
            for name, mid in created.items():
                print(f'    {name!r}: {mid},')
        else:
            print('\nDry-run complete — nothing written.')
    finally:
        conn.close()


if __name__ == '__main__':
    main()

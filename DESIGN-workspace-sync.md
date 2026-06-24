# Design: Workspace Sync Storage

## Purpose

Define how AVP-PvH stores Google Workspace-derived data in the WordPress plugin database.

This design covers:

- groups
- mailing lists
- contacts
- membership links

It does not cover authentication or LLDAP identity.

## Storage Model

### 1. Groups

Store one row per Workspace group.

Suggested table:

- `pvh_avm_workspace_groups`

Fields:

- `id`
- `workspace_id`
- `name`
- `email`
- `label`
- `source`
- `last_synced_at`

### 2. Mailing Lists

Store one row per Workspace mailing list or distribution list.

Suggested table:

- `pvh_avm_workspace_mailing_lists`

Fields:

- `id`
- `workspace_id`
- `name`
- `email`
- `type`
- `notes`
- `last_synced_at`

### 3. Contacts

Store one row per Workspace contact.

Suggested table:

- `pvh_avm_workspace_contacts`

Fields:

- `id`
- `workspace_id`
- `display_name`
- `email`
- `phone`
- `notes`
- `last_synced_at`

### 4. Membership Links

Store the relation between Workspace objects and AVP-PvH members.

Suggested table:

- `pvh_avm_workspace_memberships`

Fields:

- `id`
- `member_id`
- `workspace_object_type`
- `workspace_object_id`
- `workspace_email`
- `matched_by`
- `created_at`
- `updated_at`

This table must allow multiple rows per member so one person can belong to multiple Workspace groups.

## Matching Rules

Match Workspace records to members by:

1. canonical LLDAP email
2. linked Google identity email
3. linked Microsoft identity email if relevant

Normalize all e-mails to lowercase before comparing.

## Group Mapping

Map Workspace groups to member group labels using metadata:

- `avpvh_member_groups`

Recommended values:

- `boek`
- `50jaararcheo`

Readable labels in the UI can be derived from these values:

- `boek` -> `Boek`
- `50jaararcheo` -> `50 jaar archeo`

## `info@` Handling

Treat `info@avphilipsvanhorne.nl` as a Workspace mailing list or alias object.

Do not store it as:

- WP user identity
- LLDAP identity

Store it only in the Workspace sync tables unless it becomes a member-owned address later.

## Sync Flow

1. Fetch Workspace groups/lists/contacts
2. Normalize emails and names
3. Upsert Workspace rows
4. Match rows to members
5. Save membership links
6. Update member metadata for groups where applicable
7. Record unresolved rows for review

## Admin UI

Provide a read-only admin view showing:

- synced groups
- synced mailing lists
- synced contacts
- matched members
- unresolved items

Optional actions:

- resync one object
- resync all
- mark a match manually

## Backfill Strategy

When first enabling the sync:

- create the new tables
- import current Workspace data
- attempt automatic matching
- leave unresolved items untouched for manual review

## Open Questions

- Is a Workspace group always one label, or can a member belong to multiple labels?
- Are mailing lists and contacts available from the same API/source?
- Should we allow manual overrides for unresolved matches?
- Should the sync write into user meta or keep everything in dedicated tables?

## Recommendation

Start with dedicated tables for Workspace data and a small amount of member meta for the current group labels.

That keeps:

- auth
- member identity
- operational Workspace data

separate and easier to roll back independently.

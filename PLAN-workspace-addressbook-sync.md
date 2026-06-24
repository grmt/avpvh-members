# Plan: Google Workspace Address Book Sync

## Goal

Track important Google Workspace information related to `info@avphilipsvanhorne.nl` and internal AVP-PvH operations:

- groups
- mailing lists
- contacts
- aliases or shared addresses when relevant

This is separate from:

- authentication
- LLDAP identity
- WordPress roles
- member profile data

## Workspace Object Types

### 1. Groups

Use groups for internal member roles such as:

- bestuur
- feest
- boek
- fiscus
- secretariaat

### 2. Mailing Lists

Use mailing lists for distribution and handling of addresses such as:

- `info@avphilipsvanhorne.nl`
- other team or committee lists

Track:

- list name
- list address
- members
- owners/managers if available
- last sync timestamp

### 3. Contacts

Use contacts for important people or shared operational contacts.

Track:

- contact name
- contact email
- phone if available
- notes if available
- source label if known

## Data Model

Recommended storage options:

- `avpvh_workspace_groups`
- `avpvh_workspace_mailing_lists`
- `avpvh_workspace_contacts`
- `avpvh_workspace_memberships`

If the dataset is small, a simpler approach may be to store only the resolved role/group labels in member metadata and keep mailing lists/contacts as read-only synced records.

## Sync Strategy

### Source of truth

Google Workspace is the source of truth for:

- group membership
- mailing list membership
- contacts

### Matching

Match Workspace users to AVP-PvH members by normalized e-mail address.

Rules:

- lower-case comparison
- use linked identity addresses when appropriate
- record unmatched Workspace entries for review

### Special case: `info@avphilipsvanhorne.nl`

Treat `info@` as a managed operational address, not a member login identity.

Track:

- who owns or manages it
- which list it belongs to
- whether it is a mailing list, alias, or contact point

Do not store it as a WordPress login identity unless there is a real member account attached to it.

## UI/Reporting

Show the following in the admin UI or a dedicated report page:

- Workspace groups per member
- mailing list membership
- contacts tied to the organization
- last sync time
- unresolved/missing matches

Optional:

- a search page for `info@` and related addresses
- a team overview of all list owners and group members

## Implementation Options

### Option A: Server-side sync script

Use a script on the server to fetch Workspace data and write it into the WordPress database.

Best when:

- the data is already exported or pulled from Workspace on the server
- you want to keep Workspace credentials out of the plugin

### Option B: Plugin-side sync command

Use a WordPress admin action or WP-CLI command to sync Workspace data.

Best when:

- the sync should be triggered from WP admin
- you want the data visible in the plugin immediately

## Recommended Approach

Start with a server-side sync script and a narrow data model:

- sync groups
- sync mailing lists
- sync contacts
- store unresolved items for review

This keeps the implementation simple and avoids coupling Workspace-specific logic to authentication.

## Implementation Steps

1. Identify the Workspace source for groups, lists, and contacts
2. Decide whether the source is API, export, or existing scripts
3. Define the storage schema
4. Implement group sync
5. Implement mailing list sync
6. Implement contacts sync
7. Add a review UI for unresolved matches
8. Add smoke checks and a repeatable backfill

## Open Questions

- Are mailing lists available via API, export, or manual files?
- Are contacts shared workspace contacts, personal contacts, or both?
- Should `info@avphilipsvanhorne.nl` be modeled as an alias, a list, or both?
- Do we need to track owners/managers separately from members?
- Should unresolved matches block sync or just produce warnings?

## Notes

- Keep Workspace sync separate from LLDAP auth.
- Keep Workspace lists/contacts separate from WordPress login identities.
- Prefer read-only sync first; add edit support only if needed later.

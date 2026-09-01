# Plan: Google Workspace Group Sync for Member Roles

## Goal

Sync Google Workspace groups into AVP-PvH member metadata so the UI can show all groups a member belongs to:

- Boek
- 50 jaar archeo

These roles are not WordPress roles. They are membership metadata sourced from Google Workspace groups.

## Existing Foundation

- Google Drive file sync already exists on the server in `~/copy_from_google_drive.sh`
- Google Sheets sync already exists in the plugin
- Identity/login sync already exists in the plugin

## Proposed Data Model

### Member group storage

Store Workspace group membership as member metadata, for example:

- `avpvh_member_groups`
- `avpvh_member_groups_source`
- `avpvh_member_groups_updated_at`

The value should be a list of canonical group labels, such as:

- `boek`
- `50jaararcheo`

### Optional future extension

If the data set grows, store a separate membership table later. For now, the sync should still preserve multiple group memberships per member.

## Sync Strategy

### Source

Use Google Workspace group membership as the source of truth for member roles.

### Matching

Match workspace users to AVP-PvH members by e-mail address.

Rules:

- use the member’s canonical identity e-mail
- allow matching through linked Google identity e-mail when needed
- lower-case and normalize before comparing

### Group mapping

Map Workspace groups to role labels:

- `boek` → `Boek`
- `50jaararcheo` → `50 jaar archeo`

If a member is in multiple groups, keep all matches and store them in a deterministic order.

## Implementation Options

### Option A: Extend existing server scripts

Add a new server-side sync script next to:

- `~/copy_from_google_drive.sh`
- other home-directory automation scripts

This script can:

- fetch Workspace group members
- output CSV/JSON
- update the WordPress database directly

### Option B: Add plugin-side sync command

Add a WordPress/plugin command or admin action that:

- queries Workspace group data
- updates member metadata in `wp_usermeta` or a dedicated AVP-PvH table

This is better if the sync should be triggered from the WP admin UI.

## Recommended Approach

Use a small server-side script to fetch Workspace group membership and then write the role into WordPress member metadata.

Reasons:

- existing server automation already exists
- keeps Workspace credential handling out of the plugin UI
- makes scheduled sync easier

## UI Changes

### Navigation badge

Show:

- member name
- Google Workspace groups if present
- active/inactive member status

Example:

- `grmt · Beheerder · Boek · 50 jaar archeo · Lid`

### Profile page

Show the same role label in the profile status block.

### Admin member detail

Show:

- current role
- role source
- last sync timestamp

## Implementation Steps

1. Confirm where Workspace group membership can be fetched from
2. Decide whether the sync will run from the server shell or from WordPress
3. Add a member-groups storage field
4. Add a sync script that maps Workspace groups to stored memberships
5. Add a backfill pass for existing members
6. Show the groups in nav/profile/admin UI
7. Add tests or smoke checks for group mapping and multi-group display

## Open Questions

- Is one member allowed to have multiple Workspace groups at once?
- Should we show all matching groups, or highlight only one primary group?
- Should the sync overwrite manual role edits?
- Where exactly is the Workspace source available now: API, exported file, or another existing sync script?
- Should this live in the plugin, on the server, or both?

## Notes

- Do not use WordPress roles for these member groups.
- Keep these roles separate from authentication and from LLDAP identity.
- Treat Workspace group membership as a syncable business attribute, not as login auth.

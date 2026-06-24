# Implementation Plan: Workspace Sync

## Phase 1: Database Foundation

1. Add tables for Workspace data:
   - `pvh_avm_workspace_groups`
   - `pvh_avm_workspace_mailing_lists`
   - `pvh_avm_workspace_contacts`
   - `pvh_avm_workspace_memberships`
2. Add migration logic to `includes/class-db.php`
3. Add a version bump and idempotent upgrade path
4. Add indexes and uniqueness constraints on `workspace_id` and emails
5. Allow multiple Workspace group membership rows per member

## Phase 2: Sync Script

1. Add a server-side sync script
2. Fetch Workspace groups, mailing lists, and contacts
3. Normalize names and emails
4. Upsert Workspace rows
5. Match records to members by email
6. Save one membership row per matched group/member pair
7. Update member metadata with all active group labels
8. Record unresolved items for manual review

## Phase 3: Admin UI

1. Add a read-only Workspace overview page in WP Admin
2. Show synced groups, lists, contacts, and membership links
3. Show unresolved items and last sync time
4. Add a manual resync action
5. Show all group labels per member, not only a single primary label

## Phase 4: Member Role Display

1. Show the Workspace-derived role in nav/profile badges
2. Keep the labels separate from WordPress roles
3. Show all active Workspace group labels in the UI

## Phase 5: Backfill

1. Run an initial sync against existing Workspace data
2. Attempt automatic matching
3. Leave unresolved records for review
4. Verify important addresses like `info@avphilipsvanhorne.nl`

## Phase 6: Testing

1. Add smoke checks for the sync script
2. Add verification for role mapping precedence
3. Add checks for unresolved matches
4. Verify rollback behavior if the sync tables are removed

## Notes

- Keep this sync separate from LLDAP authentication.
- Keep it separate from Google Sheets registration sync.
- Prefer read-only sync first.
- Do not store Workspace objects as WordPress login identities.
- Do not collapse multiple Workspace groups into a single label unless a screen explicitly requires a fallback summary.

# Plan: Member Login via Google, Microsoft, and Email

## Goal

Members must be able to log in with exactly one of these routes:

- Google
- Microsoft
- own e-mail + password via Authelia/LLDAP

The login identity is always an e-mail address. Google and Microsoft may use an external e-mail address, as long as that address is explicitly linked to the member.

## Rules

- A member can have at most 3 e-mail addresses in total:
  - primary member e-mail
  - Google e-mail
  - Microsoft e-mail
- `username` is always an e-mail address
- Google and Microsoft are alternative login providers, not separate identities
- If a Google or Microsoft account uses an external e-mail address, that is allowed if it is linked to the member
- LLDAP remains the single source of truth for identity data
- WordPress must not store duplicate e-mail identity data as a separate truth source
- Password authentication stays in Authelia and LLDAP
- The plugin only handles provider login, identity mapping, and WordPress session creation

## Data Model

### Identity mapping

Store provider-linked login addresses as explicit identity links, not as ad hoc login state.

Recommended model:

- `lldap.users.email` or `lldap.users.lowercase_email` remains the primary member e-mail
- a WordPress-side identity link table stores:
  - `member_id`
  - `provider` (`google`, `microsoft`, `email`)
  - `email`
  - `is_primary`
  - `created_at`
  - `updated_at`

### Validation

- Enforce uniqueness per provider email
- Prevent more than 3 total linked e-mail addresses per member
- Reject duplicate provider links for the same member
- Allow one member to have different Google and Microsoft e-mails, including external addresses

## Login Flow

### Google / Microsoft

1. User clicks Google or Microsoft login
2. Provider returns e-mail address
3. Plugin lowercases and normalizes the e-mail
4. Plugin matches the e-mail against the member's linked identity records
5. If matched, create or reuse the WordPress session
6. If not matched, show a clear error telling the user which e-mail address is expected

### Authelia Password Login

1. User enters e-mail and password
2. User is redirected to Authelia
3. Authelia validates the username/password against LLDAP
4. After success, nginx passes `REMOTE_USER` to WordPress
5. The plugin resolves the member and creates the WordPress session
6. If the e-mail is unknown or not linked to a member, show a clear error

## Admin UX

Add member admin UI support for:

- viewing all linked login e-mail addresses
- adding/removing Google or Microsoft addresses
- marking the primary e-mail
- showing whether an address is used for Google, Microsoft, or the Authelia password route

## Profile Editing Rules

- Members may edit their own profile after logging in
- Administrators may edit any member profile
- Member self-service must stay scoped to the logged-in member
- Administrator edits may target any member explicitly
- Email identity data remains read-only in member self-service
- Only administrators may change linked login identities

## Edge Cases

- Member changes primary e-mail
- Google or Microsoft account email changes
- Same provider e-mail is linked to another member
- User signs in with an external Google/Microsoft e-mail that is not the primary member e-mail
- Member uses only one e-mail address

## Implementation Steps

1. Review current OAuth and Authelia proxy-login code paths
2. Define the identity link table or schema extension
3. Add validation for max 3 linked e-mails per member
4. Update Google and Microsoft login matching logic
5. Verify the Authelia proxy-login matching logic
6. Extend admin screens for identity management
7. Add migration and import/update support
8. Add tests for matching, duplicate prevention, and limit enforcement

## Concrete Technical Change List

### 1. Database and schema

- Extend `includes/class-db.php` with a dedicated identity-link table or equivalent schema support.
- Keep `lldap.users` as the read source for canonical identity data.
- Add migration logic for existing installs.
- Add a version bump and idempotent upgrade path.

### 2. Identity lookup layer

- Add helper methods in `includes/class-db.php` for:
  - resolving a member by provider and e-mail
  - listing linked login e-mails for a member
  - checking whether a member already has 3 linked addresses
- Normalize all e-mail input with `sanitize_email()` and lowercase matching.

### 3. OAuth login flow

- Update `includes/class-oauth.php` so Google and Microsoft logins:
  - fetch the provider e-mail
  - match against the linked identity record, not just a single implicit e-mail
  - fail with a clear message when the provider e-mail is not linked
- Preserve the current OAuth callback structure and redirect behavior.

### 4. Email/password login flow

- Keep `includes/class-access.php` focused on the existing Authelia proxy-header flow
- Do not add a WordPress password form
- Resolve the member from `REMOTE_USER`/LLDAP and the linked identity records
- Keep Authelia as the password authenticator

### 5. Admin UI

- Extend `admin/member-detail.php` to show linked login addresses.
- Extend `includes/class-admin.php` or the member edit flow to add/remove provider e-mails.
- Show validation errors when the 3-address limit would be exceeded.

### 6. Import and sync scripts

- Update `scripts/import-avpvh-members.py` to populate the new identity model where needed.
- Update any sync logic that assumes one e-mail per member.
- Keep imports idempotent.

### 7. Validation and constraints

- Enforce:
  - max 3 linked e-mails per member
  - unique provider e-mail per identity
  - no duplicate linkage for the same provider/member combination
- Make validation errors user-facing in Dutch where applicable.

### 8. Testing

- Add tests for:
  - Google login with primary e-mail
  - Microsoft login with external e-mail
  - Authelia proxy login with linked e-mail
  - rejection when a provider e-mail is not linked
  - rejection when a member already has 3 linked addresses
- Add regression tests for any existing login paths that should not change.

## Open Questions

- Should the 3 e-mail limit be strict across all linked addresses, or only across login-capable addresses?
- Should the primary e-mail always be the LLDAP e-mail, or can a provider address become primary?
- Should the primary e-mail remain the canonical LLDAP e-mail for all members?
- Should admins be able to link an external Google/Microsoft address without user confirmation?

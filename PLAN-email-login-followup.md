# Follow-up Checklist: Email Login Identities

## Done

- Google and Microsoft logins can use explicit linked identity records
- Authelia/LLDAP remains the password-auth path
- WordPress session creation still happens in the plugin
- Admins can view, add, remove, and primary-mark linked login addresses
- Members can edit their own profile
- Administrators can edit any member profile
- Verified the 3-email limit is enforced at the schema level (`ENUM('email','google','microsoft')` + `UNIQUE(member_id, provider)` on `avm_member_identities` caps a member at 3 identities structurally); added a regression test (`scripts/test-identity-helpers.php`) documenting this; fixed OAuth callback silently ignoring `ensure_identity()`'s return value
- Primary identities are backfilled from LLDAP

## Still Open

### 1. Admin profile routing

- Make the admin profile edit link less dependent on a fixed page slug
- Confirm the `/avpvh-member-profile/` page exists in all environments

### 2. Identity lifecycle

- Decide how Google/Microsoft address changes should be handled by admins
- Define whether a primary identity may be changed away from the canonical LLDAP email
- Add a clearer removal flow for the last remaining identity of a member

### 3. Backfill coverage

- Extend backfill support if Google/Microsoft identities are sourced from elsewhere later
- Verify existing production data after the new table is deployed

### 4. UX polish

- Improve Dutch user-facing copy in the new profile/identity screens
- Add clearer warnings when a member is already at the identity limit
- Show provider-specific labels more consistently in admin screens

### 5. Testing

- Add regression tests for Google login with external addresses
- Add regression tests for Authelia proxy login after identity backfill
- Add regression tests for admin edit vs self-service edit permissions
- Add tests for identity deletion edge cases

### 6. Deployment

- Deploy the migration and backfill scripts together with the plugin
- Run the backfill once on the production database after deploy
- Verify that new test users receive identity records as expected

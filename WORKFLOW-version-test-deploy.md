# Workflow: Version, Test, Bump, Deploy

## Branching Rule

- `main` tracks GitHub `origin/main`
- feature work happens on a local feature branch
- do not merge or push the feature branch to `main` until explicitly requested
- do not push upstream just to test

## Versioning Rule

Use the plugin header version in `avpvh-members.php`.

Pattern:

- base version stays aligned with the last production release
- append the feature commit hash as build metadata

Example:

- `1.0.2+24c5de1`

This makes it obvious which feature commit is deployed for test, without claiming a new release number.

## Test Rule

Before deploy:

- run `php -l` on changed PHP files
- run `bash -n` on changed shell scripts
- run any saved ad-hoc scripts relevant to the change

Saved ad-hoc test scripts currently include:

- `scripts/test-identity-helpers.php`
- `scripts/test-identity-limit.php`
- `scripts/test-role-labels.php`

## Commit Rule

- commit locally on the feature branch before deploy
- include the version metadata change in the same commit
- keep the commit message descriptive

## Deploy Rule

There are two deployment targets:

### 1. Server clone for testing

- sync the repo to `~/04-src/avpvh-members` on the server
- this is safe for branch testing
- no merge/push to `main` is needed

### 2. Live plugin directory

- the live WordPress plugin path uses the Docker volume path
- this may require `sudo` on the server
- if sudo is unavailable non-interactively, test first on the server clone and deploy live separately

## Recommended Procedure

1. Make changes on feature branch
2. Update version string with the base version + commit hash
3. Run syntax and ad-hoc checks
4. Commit locally
5. Sync to server clone for testing
6. Test in the server clone or live environment as appropriate
7. Merge/push only after explicit approval

## Notes

- Keep test scripts stored in the repo so they can be rerun later.
- Keep deploy and merge separate.
- Do not use the version string as a release tag unless the branch is promoted to production.

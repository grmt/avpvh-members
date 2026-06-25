# Deploy Notes

## Branches

- `main` follows `origin/main`
- feature work stays on a local feature branch

## Versioning

- keep the base version aligned with the last release
- append the feature commit hash as build metadata

Example:

- `1.0.2+24c5de1`

## Test Deploy

1. Commit locally on the feature branch
2. Run syntax checks and saved ad-hoc tests
3. Sync the repo to the server clone at `~/04-src/avpvh-members`
4. Test there before merging or pushing anything upstream

## Live Deploy

- the live plugin path may require `sudo` on the server
- test on the server clone first if `sudo` is not available non-interactively
- do not merge or push to `main` unless explicitly requested

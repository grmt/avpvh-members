# Deploy Notes

## Branches

- `main` follows `origin/main`
- feature work stays on a local feature branch

## Versioning

- a plain `MAJOR.MINOR.PATCH` version, no build metadata
- every feature commit you deploy or merge bumps the patch version, and
  `Stable tag:` in `readme.txt` gets the same version (Plugin Check fails
  when they differ)
- `scripts/release.sh` makes the minor/major bump when closing a branch
- `scripts/deploy.sh` prints the branch and commit hash it deploys

Example:

- `1.0.116`

## Test Deploy

1. Commit locally on the feature branch
2. Run syntax checks and saved ad-hoc tests
3. Sync the repo to the server clone at `~/03-src/avpvh-members`
4. Test there before merging or pushing anything upstream

## Live Deploy

Run from your workstation:

```bash
scripts/deploy.sh
```

- deploys the committed `HEAD` via `git archive`, so `.gitattributes`'
  `export-ignore` decides what is left out (`scripts/`, `bin/`, `.github/`,
  `*.md`, dotfiles). Everything under `wp-content/plugins/` is reachable over
  the web: only runtime files may go there
- refuses to run with uncommitted changes, and aborts if the archive contains
  scripts, tests, `.md` or dotfiles
- does **not** bump the version, commit or push -- set the version header in
  your feature commit (see Versioning) and merge via a pull request; `main`
  is protected and rejects direct pushes
- deploying a feature branch before merging is allowed; merge it afterwards
  so the live site and `main` don't drift apart
- server login comes from `AVPVH_DEPLOY_HOST` (environment or
  `deploy.local.env`); the target is
  `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/` --
  `wp-content-pvh`, not `wp-content`
- the script ends by printing the live version via WP-CLI (`wpcli-pvh`)

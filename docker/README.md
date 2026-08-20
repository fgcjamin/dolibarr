# Docker image for this fork

Builds `ghcr.io/fgcjamin/dolibarr` — the official `dolibarr/dolibarr` image with this fork's
custom accountancy/treasury REST API code layered on top. See
`roadmap/DOCKERIZATION_PLAN.md` for the full design rationale.

## Build locally

```bash
docker build -f docker/Dockerfile -t dolibarr-fork:local .
```

`DOLIBARR_BASE_VERSION` build-arg controls which official image is used as the base (defaults to
the version this fork last synced with — see `htdocs/version.inc.php`).

## CI

`.github/workflows/docker-build.yml` builds and pushes this image to GHCR on every push to `dev`
or `develop`, and can be run manually via `workflow_dispatch`. It publishes:

- `ghcr.io/fgcjamin/dolibarr:<base-version>-custom.<run-number>` — immutable, safe to pin in a
  deployment (e.g. `23.0.3-custom.42`)
- `ghcr.io/fgcjamin/dolibarr:<branch-name>` — floating, for testing only (`dev`, `develop`)
- `ghcr.io/fgcjamin/dolibarr:latest` — moves on every `develop` build

The image is private. Consumers need `docker login ghcr.io` with a PAT that has `read:packages`
scope before pulling.

## When this fork rebases onto a newer upstream Dolibarr version

1. Merge/rebase the new upstream release tag into this branch.
2. Re-run `git diff --stat <new-base-tag> HEAD -- htdocs` and update the `COPY` lines in
   `Dockerfile` if the changed-file set grew or moved.
3. Push the new upstream version's git tag to `origin` too — the workflow's guard step diffs
   against it, and it must resolve on this remote for CI to find it.
4. Nothing else to change: `DOLIBARR_BASE_VERSION` and the guard step's base tag are both derived
   from `htdocs/version.inc.php` automatically.

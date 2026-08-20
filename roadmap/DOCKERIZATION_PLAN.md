# Dockerization plan for this fork

## Summary / recommendation

Build a **thin overlay Docker image**: `FROM dolibarr/dolibarr:<base-version>` (the official image,
pinned to the exact upstream tag this fork diverged from), with a single `COPY` layer adding this
fork's custom accountancy/treasury REST API code on top. Keep the Dockerfile and CI **in this
repo** (`docker/`), not in a new dedicated repo. Publish to **GHCR**
(`ghcr.io/fgcjamin/dolibarr`), **private**, tagged `<base-version>-custom.<n>`.

This is simple because the fork's diff from upstream is small and fully additive (see below), so
there is no need to reinvent the official image's entrypoint, install automation, or multi-arch
build — all of that is inherited for free. It directly enables `dolibarr-deployment` to swap one
`image:` line and keep every existing env var and volume unchanged.

## Why this shape: the facts that drove it

**The fork's diff from upstream is small and confined.**
This repo is tagged `23.0.3` at the exact point it diverged from `Dolibarr/dolibarr`. Comparing
that tag to the current `dev` branch:

```
$ git diff --stat 23.0.3 HEAD -- htdocs
```

touches exactly **21 files**, all inside `htdocs/accountancy/**`, plus three individual files:
`htdocs/api/class/api_setup.class.php`, `htdocs/core/class/fiscalyear.class.php`, and
`htdocs/core/lib/functions2.lib.php`. No changes to `htdocs/main.inc.php`, `htdocs/install/`,
`htdocs/core/db/`, composer/vendor wiring, or anything else the official image's install/bootstrap
logic depends on. (Outside `htdocs/`, the only changes are `CLAUDE.md`, `roadmap/`, and new
`test/phpunit/` files — none of which ship in the runtime image.)

This means the *entire* value of this fork, from a Docker image's perspective, is a well-scoped
set of new/modified PHP files under one directory tree. That's exactly the case where layering on
top of the official image beats rebuilding from source.

**Upstream itself doesn't build Docker images in-tree, and for a different reason than "simple."**
`.github/workflows/ci-on-release.yml` in this repo (inherited from upstream) fires a
`repository_dispatch` event to a *separate* repo, `Dolibarr/dolibarr-docker`, on every GitHub
release; that repo does the actual build/publish to Docker Hub (`dolibarr/dolibarr`, tags
`latest`/`develop`/`x.y.z`, multi-arch amd64+arm64v8, via a `Dockerfile.template` and
`new-version.sh`/`update.sh`/`versions.sh` scripts). That split exists because
`dolibarr-docker` has to serve **every** historical Dolibarr release across PHP-version variants —
a many-versions-times-many-consumers problem. This fork tracks one live line of development for
one consumer (`dolibarr-deployment`), so that split would only add cross-repo sync overhead without
a matching benefit. **Recommendation: keep the Dockerfile and its CI workflow in this repo**,
under `docker/`, not a new repo.

**Version identification.** `htdocs/version.inc.php` defines `DOL_VERSION = 23.0.3` (no top-level
`VERSION` file). CI should read this value rather than hand-maintaining it anywhere else.

**The consuming project today.** `compta-lcv/dolibarr-deployment` is a plain `docker-compose.yml`
(mariadb + web). The `web` service currently pulls `dolibarr/dolibarr:latest` unauthenticated from
Docker Hub, fully configured through the official image's env-var contract (`DOLI_DB_*`,
`DOLI_ADMIN_*`, `DOLI_INSTALL_AUTO`, `DOLI_INIT_DEMO`, `DOLI_URL_ROOT`), with volumes for
`documents` and `custom`. It has no version pinning (floating `latest`) and no auth/registry
config today. Because the overlay approach never touches the entrypoint, **every one of those env
vars and volumes keeps working unchanged** after switching images — the only real change needed
there is the `image:` line (plus registry auth, since we're going private).

## Design decisions

| Decision | Choice | Why |
|---|---|---|
| Build strategy | Overlay on official image (`FROM dolibarr/dolibarr:<base>`) | Diff is 21 files, all additive; inherits install/entrypoint/multi-arch for free |
| Where the Dockerfile lives | In this repo, `docker/` | One repo, one CI pipeline, versioning tied directly to this fork's own git history — no second repo to keep in sync |
| Registry | GHCR (`ghcr.io/fgcjamin/dolibarr`) | Reuses GitHub identity; CI authenticates with the repo's built-in `GITHUB_TOKEN`, no extra secrets to provision |
| Visibility | Private | This fork ships proprietary treasury/accounting API code for a specific client deployment |
| Tagging | `<base-version>-custom.<n>` immutable, plus branch-name floating tags for CI/testing | See below |

### Rejected alternative: full from-source build

Mirroring `dolibarr-docker`'s own Dockerfile (build PHP+Apache from this repo's source tree from
scratch) would give more control but means reimplementing or vendoring the entrypoint's ~40
`DOLI_*` env vars, secrets `_FILE` handling, cron support, and multi-arch build matrix — none of
which this fork needs to change. Not worth the ongoing maintenance for a 21-file diff. Revisit only
if the fork starts modifying install/bootstrap behavior itself.

## Version & tag strategy

This is the part most likely to cause confusion later, so it's spelled out explicitly:

- **Base version** (`DOLIBARR_BASE_VERSION` build-arg) is extracted by CI from
  `htdocs/version.inc.php`'s `DOL_VERSION` — never hand-typed into the Dockerfile or workflow.
- **Immutable release tags**: `<base-version>-custom.<n>`, e.g. `23.0.3-custom.1`, incrementing
  `n` for a fork-only fix that doesn't bump the base (e.g. `23.0.3-custom.2`). This is
  deliberately distinct from this repo's own `23.0.3` git tag, which marks the *base checkout
  point*, not a Docker release — conflating the two would make it impossible to ship two custom
  builds against the same base version.
- **Floating tags** on every push to `dev`/`develop`: image tagged with the branch name (`dev`,
  `develop`) for CI/manual testing only — `dolibarr-deployment` (or any real deployment) should
  never reference these.
- **`latest`** moves only on tagged fork releases, matching upstream's own convention.
- **Consuming projects must always pin an immutable `<base>-custom.<n>` tag**, never `latest` or a
  branch tag. Flag this explicitly to `dolibarr-deployment`, since it currently floats on
  `dolibarr/dolibarr:latest`.
- **CI safety net**: on every build, run the same `git diff --stat <base-tag> HEAD -- htdocs` check
  used above and fail/warn if changes land outside the Dockerfile's `COPY` allow-list. This is what
  keeps the overlay from silently missing files as more API phases land (see `roadmap/backlog.md`
  for planned future phases).

## Upstream rebase workflow

When upstream Dolibarr cuts a new release this fork wants to move to:

1. Add an `upstream` remote pointing at `Dolibarr/dolibarr`, fetch the new release tag.
2. Rebase (or merge) this fork's `dev` branch onto the new tag.
3. Re-run `git diff --stat <new-tag> HEAD -- htdocs` and update the Dockerfile's `COPY` list if the
   changed-file set has grown or moved.
4. Confirm `dolibarr/dolibarr:<new-tag>` exists on Docker Hub as a base image (upstream's
   `ci-on-release.yml` → `dolibarr-docker` dispatch means it should appear shortly after the
   GitHub release).
5. Bump nothing manually for the version tag — CI re-derives `DOLIBARR_BASE_VERSION` from
   `htdocs/version.inc.php` automatically once that file is updated by the rebase.
6. Build, tag (`<new-base>-custom.1`), push.

## Example Dockerfile (illustrative — not applied in this change)

```dockerfile
# docker/Dockerfile
ARG DOLIBARR_BASE_VERSION=23.0.3
FROM dolibarr/dolibarr:${DOLIBARR_BASE_VERSION}

# Overlay this fork's accountancy/treasury REST API additions.
# Keep in sync with: git diff --stat <base-tag> HEAD -- htdocs
COPY htdocs/accountancy/ /var/www/html/accountancy/
COPY htdocs/api/class/api_setup.class.php /var/www/html/api/class/api_setup.class.php
COPY htdocs/core/class/fiscalyear.class.php /var/www/html/core/class/fiscalyear.class.php
COPY htdocs/core/lib/functions2.lib.php /var/www/html/core/lib/functions2.lib.php
```

## Example CI workflow (illustrative — not applied in this change)

```yaml
# .github/workflows/docker-build.yml
name: Build & publish Docker image
on:
  push:
    branches: [dev, develop]
    tags: ['*-custom.*']

permissions:
  contents: read
  packages: write

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6

      - name: Extract Dolibarr base version
        id: ver
        run: |
          BASE=$(grep "define('DOL_VERSION'" htdocs/version.inc.php | sed -E "s/.*'([0-9.]+)'.*/\1/")
          echo "base=$BASE" >> "$GITHUB_OUTPUT"

      - name: Guard against untracked htdocs drift
        run: git diff --stat "${{ steps.ver.outputs.base }}" HEAD -- htdocs

      - uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - uses: docker/build-push-action@v6
        with:
          context: .
          file: docker/Dockerfile
          build-args: DOLIBARR_BASE_VERSION=${{ steps.ver.outputs.base }}
          push: true
          tags: |
            ghcr.io/fgcjamin/dolibarr:${{ steps.ver.outputs.base }}-custom.${{ github.run_number }}
            ghcr.io/fgcjamin/dolibarr:${{ github.ref_name }}
```

## Follow-up for `dolibarr-deployment` (not implemented here, different repo)

```yaml
services:
  web:
    image: ghcr.io/fgcjamin/dolibarr:23.0.3-custom.1   # pin, never :latest
    # all existing DOLI_* env vars and volumes unchanged
```

Since the image will be private, that project's host needs a one-time
`docker login ghcr.io -u fgcjamin` (PAT with `read:packages` scope) before `docker compose pull`
will succeed — worth documenting in that repo's README when this change lands there.

## Open item

Repo root currently has both `composer.json` and `composer.json.disabled` with identical content,
which deviates from this repo's own documented convention ("ships disabled by default"). It doesn't
affect the overlay build above (composer/vendor state comes entirely from the base image, and the
Dockerfile never touches composer files), but it's worth a quick look before relying on this
checkout as a general build context for anything else.

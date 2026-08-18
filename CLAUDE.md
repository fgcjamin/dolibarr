# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Dolibarr ERP & CRM is a large PHP web application (GPL-3.0+) covering invoicing, orders, stock,
CRM, HR, accounting, projects, and dozens of other business modules. The application code lives
under `htdocs/`; everything else (`dev/`, `test/`, `scripts/`, `doc/`) supports building, testing,
and packaging it.

## Commands

### Lint / static analysis

```bash
# PHP syntax check (parallel-lint), excludes vendored code under htdocs/includes
vendor/bin/parallel-lint -e php --exclude htdocs/includes .

# PHPStan (level 10, config at phpstan.neon.dist, baseline at dev/build/phpstan/phpstan-baseline.neon)
vendor/bin/phpstan analyse -c phpstan.neon.dist

# PHP CodeSniffer, ruleset at dev/setup/codesniffer/ruleset.xml
vendor/bin/phpcs --standard=dev/setup/codesniffer/ruleset.xml htdocs/path/to/file.php

# Pre-commit hooks (whitespace/EOL fixers, xml/yaml/json checks, gitleaks secret scan, translation sanity check)
pre-commit run --all-files
```

Note: `composer.json.disabled` must be renamed to `composer.json` (and vendor dir is
`htdocs/includes`, per its `config.vendor-dir`) before `composer install` will work — the repo
ships it disabled by default so a normal checkout isn't mistaken for having deps installed.

### Tests

PHPUnit tests live in `test/phpunit/` (one `*Test.php` file per module/class, e.g.
`CompanyLibTest.php`, `FactureTest.php`). `test/phpunit/AllTests.php` is the full suite entry
point; `test/phpunit/phpunittest.xml` is the PHPUnit config (4G memory limit, warnings/notices
promoted to failures, `stopOnFailure`).

```bash
# Run the full suite
phpunit -d memory_limit=-1 -c test/phpunit/phpunittest.xml test/phpunit/AllTests.php

# Run a single test file
phpunit -d memory_limit=-1 -c test/phpunit/phpunittest.xml test/phpunit/CompanyLibTest.php

# Run a single test method
phpunit -d memory_limit=-1 -c test/phpunit/phpunittest.xml --filter testMethodName test/phpunit/CompanyLibTest.php
```

Other test suites: `test/hurl/` (HTTP/API tests via hurl) and `test/acceptance/` (browser
acceptance tests) — see their respective READMEs.

## Architecture

### Module layout

Each business domain is a top-level directory under `htdocs/` (e.g. `societe/`, `compta/`,
`commande/`, `product/`, `hrm/`). A module typically follows this pattern:

- `class/*.class.php` — the model, usually extending `CommonObject` (or `CommonInvoice`,
  `CommonOrder`, etc. from `htdocs/core/class/`), implementing CRUD (`create`, `fetch`, `update`,
  `delete`), status/workflow methods, and `$fields` array describing DB columns.
- `card.php` — view/edit a single record.
- `list.php` — list/search view.
- `class/*.sql` (in `htdocs/install/mysql/tables/` and `htdocs/install/mysql/data/`) — schema and
  reference data, applied in filename order during install/upgrade.
- Module activation/config is declared by a descriptor class `htdocs/core/modules/modXxx.class.php`
  extending `DolibarrModules` (`htdocs/core/modules/DolibarrModules.class.php`), which declares
  menus, permissions (rights), tabs, triggers, boxes, and dictionaries the module contributes.
- `langs/en_US/*.lang` — translation strings. **Never edit non-`en_US` language files** — they're
  synced from Transifex automatically; only add/modify `en_US`.

### Core framework

`htdocs/core/` holds shared infrastructure:
- `core/class/` — base classes (`CommonObject`, `CommonObjectLine`, `commoninvoice`,
  `commonorder`, `conf.class.php`, `CMailFile`, etc.) and DB layer (`core/db/`).
  Wide-reaching base-class changes ripple across nearly every module — check callers carefully.
- `core/lib/*.lib.php` — grouped procedural helper functions (e.g. `functions.lib.php`,
  `company.lib.php`, `date.lib.php`), loaded via `require` where needed.
  `htdocs/core/lib/functions.lib.php` and `functions2.lib.php` hold the generic, most-used helpers.
  Prefer reusing an existing `*.lib.php` helper over duplicating logic.
- `core/triggers/` — event hooks fired on object CRUD (`interface_*.class.php`), used for
  cross-module reactions (e.g. accounting entries on invoice validation).
  Modules can also register their own hooks (`core/hookmanager`-style) — search for
  `initHooks`/`executeHooks` before adding a duplicate mechanism.
- `core/modules/` — module descriptors, and subdirectories with pluggable numbering-mask/
  document-template generators per domain (e.g. `core/modules/facture/`, `core/modules/commande/`).
  Adding a new numbering mask or ODT/PDF doc template for a module means adding a class here
  implementing that module's model interface (e.g. `ModeleNumRefFactures`).

### Bootstrap and request flow

`htdocs/main.inc.php` is the standard entry point pulled in by nearly every page (`master.inc.php`
for lighter/API contexts) — it sets up `$db`, `$conf`, `$user`, `$langs`, session, and security
checks (CSRF token, permission checks). Page scripts (`card.php`, `list.php`, action controllers)
include it first, then typically:
1. Load needed classes.
2. Handle `$action` from GET/POST (often via shared `core/actions_*.inc.php` includes for common
   patterns like extrafields, mass actions, linked files, doc building).
3. Fetch/build objects and render output, often through `core/tpl/` shared Twig-free PHP templates.

### Coding conventions

- Indentation: **tabs**, 4-space width (`.editorconfig`); PHP CodeSniffer ruleset at
  `dev/setup/codesniffer/ruleset.xml` enforces this and more.
- PHPStan is run at **level 10**; new code should not introduce new baseline suppressions in
  `dev/build/phpstan/phpstan-baseline.neon` — fix the type issue instead of suppressing it.
- Minimum supported PHP is 7.1 in general, but static analysis targets PHP 8.2
  (`phpstan.neon.dist` `phpVersion: 80200`) — avoid syntax/APIs that break older supported
  versions unless a file/module has clearly moved its floor up.
- File headers carry GPL copyright blocks per contributor — when substantially modifying a file,
  follow the existing pattern (don't remove others' copyright lines).

## Contribution workflow (see `.github/CONTRIBUTING.md` for full detail)

- PRs should target the `develop` branch unless fixing a bug in an older maintained version.
- Don't commit directly to a branch literally named `develop` or matching `x.y` (enforced by a
  pre-commit hook) — use a feature branch.
- Never hand-edit `ChangeLog` (generated at release time from commit messages).
- Commit message format: `KEYWORD #issuenum Short description` where KEYWORD is one of
  `Fix`/`FIX`, `Close`/`CLOSE`, `New`/`NEW`, `Perf`/`PERF`, `Qual`/`QUAL` (uppercase makes it show
  in the generated ChangeLog).

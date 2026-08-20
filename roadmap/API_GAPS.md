# Dolibarr Accountancy API Gaps

**Status**: item 1 (chart-of-accounts model CRUD) is closed — see Phase 6 in `roadmap/backlog.md`.
Item 5 (accounting account categories) is closed — see Phase 8 in that same file. Items 2, 3, 4,
6, and 7 are tracked as Phases 7, 9, 10, and 11 in that same file (scoped, not yet implemented).
This file is kept as the original point-in-time gap analysis.

What's still missing from the Dolibarr fork's REST API (`/home/cloclo/sources/dolibarr`, branch `dev`)
for an agent to complete the accountancy module's full workflow through the API alone, with zero manual
UI steps. Findings below are from reading the fork's source directly, not from testing against a live
instance.

Workflow steps referenced below are the ones from the module's own usage guide:

- **1–9**: one-time/yearly setup (journal list, chart of accounts model, chart of accounts, fiscal
  period, default accounts, bank accounts, VAT accounts, tax accounts, product accounts)
- **A–E**: recurring cycle (customer binding, vendor binding, ledger transfer, reporting, closure)

## Summary

| Step | Task | Status |
|---|---|---|
| 2 | Create a chart-of-accounts model | **Closed** — see Phase 6 in `roadmap/backlog.md` |
| 3 (the "select" half) | Activate a chart-of-accounts model | **Missing** — UI-only, and non-trivial to replicate |
| 6 | Link a bank account to its accounting journal | Not missing — already possible via the generic Products/Bank API, just not wired into this MCP server yet |
| 9 | Set product/service accounting codes | Not missing, in either config — see Phase 10 in `roadmap/backlog.md` |
| *(not in the 9+5 list)* | Manage accounting account categories | **Closed** — see Phase 8 in `roadmap/backlog.md` |
| *(not in the 9+5 list)* | CSV import of a chart of accounts | **Missing** — no API wrapper at all |
| C | Preview exact debit/credit amounts before ledger transfer | **Partial** — preview exists but only returns ref + error flag, not amounts (a deliberate design tradeoff, not an oversight) |

## Details

### 1. Chart-of-accounts model CRUD (Step 2) — closed (see Phase 6, `roadmap/backlog.md`)

`AccountancySystem` (`htdocs/accountancy/class/accountancysystem.class.php`) has only `fetch()` and
`create()` — there is no `update()` or `delete()` method in the model class at all, so full CRUD isn't
even possible without extending the model first.

`AccountingSetup`'s API (`htdocs/accountancy/class/api_accountingsetup.class.php`) exposes only:
- `GET accountingsystems`
- `GET accountingsystems/{id}`

No `POST`, `PUT`, or `DELETE` exists for this resource — even though `AccountancySystem::create()`
already exists and could be wrapped today.

**To close this gap:** add `AccountancySystem::update()`/`delete()`, then add
`POST/PUT/DELETE accountingsystems[/{id}]` to `api_accountingsetup.class.php`, following the same
pattern as the existing `fiscalyears` CRUD in that file.

### 2. Activating a chart of accounts (Step 3) — missing, and non-trivial

This isn't a simple constant toggle. The actual logic lives in
`htdocs/accountancy/admin/account.php:171-213`:

1. It loads a **country-specific SQL dataset** for the chosen chart via `run_sql($sqlfile, ...)`
   (`account.php:190-198`) — this bulk-inserts the chart's account rows.
2. Only then does it call `dolibarr_set_const($db, 'CHARTOFACCOUNTS', $chartofaccounts, ...)`
   (`account.php:213`) to make it the active chart.

There is no generic Dolibarr "set a constant" API to fall back on either: `htdocs/api/class/api_setup.class.php`
exposes 40+ read-only dictionary `GET` endpoints and zero write endpoints.

**To close this gap:** add a dedicated endpoint, e.g. `POST accountingsystems/{id}/activate`, that
replicates `account.php`'s two-step logic server-side. Because step 1 is a bulk data load (not just a
metadata change), this is the highest-value but also the highest-risk addition in this list — it
probably deserves a dry-run/confirmation parameter before an agent is allowed to call it unattended.

### 3. Bank account accounting journal linkage (Step 6) — not actually missing

Corrected from an earlier assumption: `htdocs/compta/bank/class/api_bankaccounts.class.php`'s `$FIELDS`
constant (`ref, label, type, currency_code, country_id`) is used **only** for mandatory-field validation
on create, not as a write allowlist. Both `post()` and `put()` forward every key in the request body
straight onto the `Account` object before calling `create()`/`update()`. Since `fk_accountancy_journal`
is a public, persisted property of `Account` (`htdocs/compta/bank/class/account.class.php:248`), it is
already settable today via:

```
PUT /bankaccounts/{id}
{ "fk_accountancy_journal": 3 }
```

**Nothing to add on the Dolibarr side.** This MCP server just doesn't currently wrap the generic
`bankaccounts` endpoint — see "MCP server follow-up" below.

### 4. Product/service accounting codes (Step 9) — not missing, in either config (see Phase 10, `roadmap/backlog.md`)

Same situation as bank accounts: `htdocs/product/class/api_products.class.php`'s `$FIELDS` is only
`ref, label` for validation purposes, but `post()`/`put()` forward arbitrary fields onto the `Product`
object. `Product` has public `accountancy_code_sell`, `accountancy_code_sell_intra`,
`accountancy_code_sell_export`, `accountancy_code_buy`, `accountancy_code_buy_intra`,
`accountancy_code_buy_export` (`product.class.php:579-599`), and `Product::update()`/`create()`
persist them correctly **in both configs**, not just when `MAIN_PRODUCT_PERENTITY_SHARED` is off.

**Corrected from an earlier assumption** (this item originally claimed `Product::update()`
"deliberately skips" these fields when `MAIN_PRODUCT_PERENTITY_SHARED` is on, and that a new
endpoint wrapping `Product::setAccountancyCode()` would be needed to close the gap): `update()`
does skip them from the `UPDATE llx_product` SET-clause in that mode (`product.class.php:1621-1628`)
— but immediately after (lines 1660-1688), a second block guarded by the same flag deletes+inserts
all 6 fields into `llx_product_perentity`, scoped to `(fk_product, conf->entity)`; `create()` has
the identical pair of blocks. `fetch()` (lines 2955-2996) is symmetric, reading from
`product_perentity` instead of `product` in that mode. So the standard `GET/PUT/POST products/{id}`
endpoints already work correctly in both modes, with no code changes needed — confirmed via a live
test against a real DB in both configs, see Phase 10 in `roadmap/backlog.md` for the full writeup
and the regression test added to `test/phpunit/ProductTest.php`.
`Product::setAccountancyCode($type, $value)` (`product.class.php:2149-2210`) — the method this item
originally proposed wrapping — is confirmed unused (no callers anywhere in `htdocs/`) and is *not*
per-entity-aware; wiring an endpoint to it would have been a regression, not a fix.

### 5. Accounting account categories — closed (see Phase 8, `roadmap/backlog.md`)

`AccountancyCategory` (`htdocs/accountancy/class/accountancycategory.class.php`) exists and backs
`htdocs/accountancy/admin/categories.php`, but no `api_*.class.php` anywhere references it. Not part of
the original 9+5 step workflow, so lower priority, but a gap if an agent needs to manage account
categorization.

### 6. Chart-of-accounts CSV import — missing

`AccountancyImport` (`htdocs/accountancy/class/accountancyimport.class.php`) exists with no API wrapper
either. Same low-priority note as above — useful for bulk-loading a custom chart, not required by the
core 9+5 workflow since accounts can already be created one at a time via `accounting_chart_of_accounts`.

### 7. Ledger-transfer preview omits amounts (Step C) — partial, by design

`AccountingJournals::pendingData()` (`api_accountingjournals.class.php:353-374`) deliberately returns
only `{ nb_elements, items: [{ref, has_error}] }`, not debit/credit subtotals. Per the method's own
docblock: computing real subtotals without writing "would mean re-deriving the write loop's math a
second time." For bank/treasury journals (nature 4) specifically, `has_error` is also hardcoded `false`
since that data path has no per-line error map to draw from (unlike sells/purchases).

**Practical effect:** an agent can check *whether* a journal has pending items and whether any are
individually flagged as errors, but cannot get a true trial-balance-style preview before committing a
transfer. Closing this gap fully means either accepting the duplicated-math risk the original author
avoided, or refactoring `writeIntoBookkeepingFor*()` to separate "compute" from "persist" so both the
preview and the real write share one code path — the latter is the more correct fix but touches the
highest-risk part of the accountancy module.

## MCP server follow-up (not a Dolibarr API gap)

Items 3 and 4 above are already achievable through Dolibarr's generic `bankaccounts` and `products`
APIs. The MCP server now wraps them as `accounting_bank_account_journal` (get/set
`fk_accountancy_journal`) and `accounting_product_codes` (get/set the six `accountancy_code_*` fields),
so steps 6 and 9 of the setup workflow are covered end-to-end without any further Dolibarr-side changes
— including under `MAIN_PRODUCT_PERENTITY_SHARED`, confirmed by Phase 10's research
(`roadmap/backlog.md`) to already work correctly through the standard `products` endpoint, no new
Dolibarr endpoint needed.

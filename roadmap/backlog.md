# Accountancy Module REST API — Backlog (Phases 1-11)

## Status summary

Phases 1-5 are implemented and cover the 9-step setup workflow and the 5-step recurring workflow
(binding → ledger transfer → closure/reporting) described in the original request — a Dolibarr
install can be driven through the accountancy module end to end via the API alone, with no UI step
required. Phases 6-11 are a follow-up backlog written after auditing what's still missing beyond
that core workflow (see `roadmap/API_GAPS.md` for the source analysis) — Phases 6, 7, 8, 9, and 11
are implemented; Phase 10 is scoped but open, blocked pending further research (see its section
below).

| Phase | Scope | Status | Key files |
|---|---|---|---|
| 1 | Setup CRUD (chart of accounts, journals, fiscal years, default/VAT/tax accounts) | Done | `api_accountingaccounts.class.php`, `api_accountingjournals.class.php`, `api_accountingsetup.class.php` |
| 2 | Invoice-line binding (steps A/B) | Done — see follow-up note below | `accountingaccount.class.php` (`bindInvoiceLine`/`unbindInvoiceLine`), `api_accountingbind.class.php` |
| 3a | Ledger CRUD + lettering | Done | `api_accountancy.class.php` (`ledger/*`) |
| 3b | Ledger transfer, all 5 journal natures (various, sells, purchases, expense reports, bank, treasury) | Done | `accountingjournal.class.php` (`getDataForXxx()`/`writeIntoBookkeepingForXxx()` per journal), `api_accountingjournals.class.php` (`transfer()`/`pendingData()`) |
| 4 | Period closure (step E) | Done | `api_accountancy.class.php` (`fiscalperiods/*`) |
| 5 | Reporting/exports (step D) | Done | `api_accountancy.class.php` (`ledger`, `ledger/balance`, `exportData`) |
| 6 | Chart-of-accounts model CRUD (step 2) | Done | `accountancysystem.class.php` (`update`/`delete`), `api_accountingsetup.class.php` (`accountingsystems` POST/PUT/DELETE) |
| 7 | Activate a chart of accounts (step 3, "select" half) | Done | `accountancysystem.class.php` (`activate()`), `api_accountingsetup.class.php` (`accountingsystems/{id}/activate[/preview]`) |
| 8 | Accounting account categories CRUD + assignment | Done | `accountancycategory.class.php` (bug fix in `create()`), new `api_accountingcategories.class.php` |
| 9 | Ledger-transfer preview amounts (step C enhancement) | Done | `accountingjournal.class.php` (`getPreviewAmountsForSells()`/`getPreviewAmountsForPurchases()`), `api_accountingjournals.class.php` (`pendingData()`) |
| 10 | Product accountancy codes under `MAIN_PRODUCT_PERENTITY_SHARED` | Open — needs research first | `product.class.php`, `api_products.class.php` |
| 11 | Chart-of-accounts CSV import | Done | new `api_accountingimport.class.php` |

**One non-blocking follow-up, not a functional gap**: `test/phpunit/AccountingBindTest.php`
(Phase 2) exercises `AccountingAccount::bindInvoiceLine()`/`unbindInvoiceLine()` directly, not the
`AccountingBind` REST endpoints themselves — so nothing currently proves the HTTP/permission
wiring of `GET/PUT/POST customerlines|supplierlines/...` works end to end, only that the
underlying model methods do. Worth an `*ApiTest.php`-style test if this module sees further work,
but does not block calling the endpoints today.

## Phase-by-phase detail

Phase 1 (Setup CRUD: chart of accounts, journals, fiscal years, chart-of-accounts models,
default accounts, VAT/tax accounting codes) has been implemented — see
`htdocs/accountancy/class/api_accountingaccounts.class.php`,
`htdocs/accountancy/class/api_accountingjournals.class.php`,
`htdocs/accountancy/class/api_accountingsetup.class.php`.

Phase 2 (Binding invoice lines, steps A & B) has been implemented — see
`AccountingAccount::bindInvoiceLine()`/`unbindInvoiceLine()` added to
`htdocs/accountancy/class/accountingaccount.class.php:700-748`, the refactor of all 4 UI call
sites (`htdocs/accountancy/customer/{list,card}.php`, `htdocs/accountancy/supplier/{list,card}.php`)
off raw inline `UPDATE ... fk_code_ventilation` SQL onto the new methods, and the new
`htdocs/accountancy/class/api_accountingbind.class.php` (`AccountingBind` class) implementing all
10 endpoints from this section's original spec (see the Phase 2 section below), gated on
`accounting->bind->write` throughout. Commit `b5988c9a371`. Test coverage in
`test/phpunit/AccountingBindTest.php` (customer + supplier bind/unbind, invalid type rejection,
negative-accountid-clamps-to-unbind) — **but this only exercises `bindInvoiceLine()`/
`unbindInvoiceLine()` directly, not the `AccountingBind` REST endpoints**, so there's no test
proving the HTTP/permission layer itself is wired correctly. Flagged as a follow-up in the status
summary above, not a blocker — the endpoints work, they're just only indirectly tested.

Phase 4 (Close accounting period) has been implemented — see the `fiscalperiods` endpoints added
to `htdocs/accountancy/class/api_accountancy.class.php` and
`test/phpunit/AccountingClosureApiTest.php`. Implemented in-file rather than as a separate
`api_accountingclosure.class.php`, since `api_accountancy.class.php` stayed well under the
~500-line split threshold.

Phase 5 (Reporting/exports) has been implemented — see the `getLedger()` (`GET ledger`) and
`getLedgerBalance()` (`GET ledger/balance`) endpoints added to
`htdocs/accountancy/class/api_accountancy.class.php`, wrapping `BookKeeping::fetchAll()`/
`fetchAllByAccount()`/`fetchAllBalance()`. Gated on `accounting->comptarapport->lire` OR
`accounting->mouvements->lire` (the backlog-documented right plus a fallback matching every other
read endpoint's actual convention — `comptarapport` exists in `modAccounting.class.php` but no
UI page or existing API method actually checks it). `exportData()` was left untouched — already
confirmed to cover all `AccountancyExport` formats end to end.

Phase 3a (Ledger CRUD + lettering, the safe additive half of Phase 3) has been implemented — see
`GET/PUT/DELETE ledger/{id}`, `POST/DELETE ledger/lettering`, and `POST thirdparties/{id}/lettering`
added to `htdocs/accountancy/class/api_accountancy.class.php`, wrapping already-correct
`BookKeeping::fetch()/update()/delete()` and `Lettering::updateLettering()/deleteLettering()/
letteringThirdparty()`. Gated on `accounting->mouvements->{lire,creer,supprimer}`. `PUT` is
restricted to the exact field set the UI's single-line edit form exposes (`numero_compte`,
`subledger_account`, `subledger_label`, `label_compte`, `label_operation`, `debit`, `credit`;
piece-level fields stay UI-only) and both `PUT`/`DELETE` replicate the UI's own
validated/exported guards at the API layer, since the model layer doesn't enforce them.
`POST thirdparties/{id}/lettering` deliberately deviates from this section's original `GET`
verb — `letteringThirdparty()` mutates data (calls `updateLettering()` internally), so `GET`
would be unsafe. Test coverage in `test/phpunit/AccountingLedgerApiTest.php`.

Phase 3b (first slice: `create()` fix + expense-reports journal transfer) has been implemented —
see `AccountingJournal::getDataForExpenseReports()`/`writeIntoBookkeepingForExpenseReports()` in
`htdocs/accountancy/class/accountingjournal.class.php` (a mechanical, behavior-preserving
extraction of `accountancy/journal/expensereportsjournal.php`'s former inline data-collection +
write logic, verified against a golden pre-refactor baseline — see the Phase 3b section below
for exact methodology), the corresponding `POST journals/{id}/transfer`/
`GET journals/{id}/pendingdata` endpoints on `AccountingJournals`
(`htdocs/accountancy/class/api_accountingjournals.class.php`, dispatching on journal nature: `1`
via the already-correct `getData()`/`writeIntoBookkeeping()`, `5` via the new expense-reports
methods, anything else `400`), and the `BookKeeping::create()` return-value fix in
`htdocs/accountancy/class/bookkeeping.class.php` (audited all 12 production call sites first —
all safe). Test coverage in `test/phpunit/AccountingJournalExpenseReportsTransferTest.php` and
the corrected assertion in `test/phpunit/BookKeepingTest.php::testBookKeepingCreate()`. Sells,
purchases, bank, and treasury remain open at this point — see the rewritten Phase 3b section below.

Phase 3b, second slice (sells journal transfer) has been implemented — see
`AccountingJournal::getDataForSells()`/`writeIntoBookkeepingForSells()` in
`htdocs/accountancy/class/accountingjournal.class.php`, a verbatim lift of
`accountancy/journal/sellsjournal.php`'s former inline data-collection (5 hook points, richer
than expense reports: `doActions`, `printFieldListSelect`/`From`/`Where`,
`processingJournalData`, `processedJournalData`) and write loop (5 `create()` sites: warranty,
thirdparty with an auto-lettering side effect, product/service, VAT, revenue stamp; plus the
replaced-invoice skip guard). Wired into `POST journals/{id}/transfer`/
`GET journals/{id}/pendingdata` as nature `2`. Verified against a golden pre-refactor baseline
(byte-for-byte match via both the page's own call sequence and the class method alone),
idempotency, and the replaced-invoice guard. **Caught and fixed one real bug during this
extraction**: the initial lift dropped data-collection's `$error++` on the
`ACCOUNTANCY_MAX_TOO_MANY_LINES_TO_PROCESS` too-many-lines guard, which — since the page's
write-action gate reads that `$error` — would have silently let the write proceed on truncated
data instead of being blocked like the original. Fixed by threading an `'error'` count through
`getDataForSells()`'s return, having the page accumulate it, and having
`writeIntoBookkeepingForSells()` itself refuse to run if data-collection signaled it (a
phpstan "always true" finding on the write-action's `if` condition surfaced the gap — worth
re-running phpstan after any future journal extraction, not just lint/phpcs, for exactly this
class of subtle omission). Test coverage in
`test/phpunit/AccountingJournalSellsTransferTest.php`. Purchases, bank, and treasury remained
open at this point — see the rewritten Phase 3b section below.

Phase 3b, third slice (purchases journal transfer) has been implemented — see
`AccountingJournal::getDataForPurchases()`/`writeIntoBookkeepingForPurchases()` in
`htdocs/accountancy/class/accountingjournal.class.php`, a verbatim lift of
`purchasesjournal.php`'s former inline data-collection (4 hook points: `doActions`,
`printFieldListSelect`/`From`/`Where` — this journal has no `processingJournalData`/
`processedJournalData` hooks, unlike sells) and write loop (4 `create()` sites: thirdparty with
an auto-lettering side effect, product/service, VAT with a reverse-charge substitution branch,
VAT-NPR counterpart; plus the replaced-invoice skip guard). Wired into
`POST journals/{id}/transfer`/`GET journals/{id}/pendingdata` as nature `3`. `getDataForPurchases()`'s
return shape is a genuine superset+subset mismatch with `getDataForSells()`'s, not a copy: no
`tabwarranty`/`tabrevenuestamp`, but adds purchases-specific `tabother` (VAT-NPR counterpart) and
`tabrctva`/`tabrclocaltax1`/`tabrclocaltax2` (VAT reverse-charge) — none of these three are
pre-zero-initialized for every invoice the way `tabtva`/`tablocaltax1`/`tablocaltax2` are, so the
original code's `isset()`/`is_array()` guards around them were preserved verbatim in the lift
rather than "cleaned up." `writeIntoBookkeepingForPurchases()` needs `$mysoc` in the *write* half
too (the VAT-substitution branch re-checks `$mysoc->country_code`), unlike sells' write method
which only needed it during data-collection. Verified against a golden pre-refactor baseline
(byte-for-byte match on `numero_compte`/`debit`/`credit` across all 3 rows, via both the page's
own call sequence and the class methods alone), a direct API smoke test of both new
`transfer()`/`pendingData()` branches, and idempotency. Test coverage in
`test/phpunit/AccountingJournalPurchasesTransferTest.php` (baseline write + replaced-invoice
skip, mirroring the sells test structure) — reverse-charge substitution and the VAT-NPR
counterpart are deliberately left as **follow-up** test cases, not covered by this slice's
fixture (which has both switched off to keep the expected math simple: only 3 of the 4 create
sites fire). Bank and treasury remained open at this point — see the rewritten Phase 3b section
below.

Phase 3b, fourth slice (bank journal transfer, non-`RECETTES-DEPENSES` accounting mode) has been
implemented — see `AccountingJournal::getDataForBank()`/`writeIntoBookkeepingForBank()` in
`htdocs/accountancy/class/accountingjournal.class.php`, a verbatim lift of `bankjournal.php`'s
former inline data-collection (one query, zero hooks — confirmed the whole file fires none, and
has no `ACCOUNTANCY_MAX_TOO_MANY_LINES_TO_PROCESS` guard to preserve, unlike sells/purchases) and
write loop (3 physical `create()` sites — bank line, thirdparty/counterpart, waiting-account
fallback — behind an 11-way payment-type branch: `payment`/`payment_supplier`/
`payment_expensereport`/`payment_salary`/`sc`+`payment_sc`/`payment_vat`/`payment_donation`/
`member`/`payment_loan`/`payment_various`/`banktransfert`, plus an `unknown` fallback to
`ACCOUNTING_ACCOUNT_SUSPENSE`). The page-local `getSourceDocRef()` function
(`bankjournal.php:1607-1715`) moved onto the class as **`getSourceDocRefForBank()`** — `public`,
not `private`, since the page's own export-CSV and view-rendering blocks call it too, from
outside the class. Wired into `POST journals/{id}/transfer`/`GET journals/{id}/pendingdata` as
nature `4`, but **only** when `getDolGlobalString('ACCOUNTING_MODE') != 'RECETTES-DEPENSES'` — at
the time this slice shipped, both endpoints threw `RestException(501, ...)` in `RECETTES-DEPENSES`
mode instead, since that mode routes through `treasuryjournal.php` (unimplemented at the time,
since implemented — see the treasury status paragraph below), and there is no other field on the
nature-4 journal row itself to distinguish the two pages (`eldy.lib.php:1811-1876` makes the same
`ACCOUNTING_MODE` check to pick which page's menu entry to show). `pendingData()`'s
nature-4 branch deliberately does not attempt to compute a resolved doc ref or per-line error
flag (bank's data-collection has no `errorforinvoice`-style map the way sells/purchases do) — it
returns the bank line's own already-collected raw `ref` and `has_error => false` throughout,
consistent with the endpoint's existing "not a full trial-balance preview" scope.

**Two real bugs caught and fixed during this extraction** (both confirmed via a golden
pre-refactor baseline diff, not just code review):
1. `$account_supplier`/`$account_customer`/`$account_employee`/`$account_transfer` (derived from
   `getDolGlobalString(...)` config, e.g. `ACCOUNTING_ACCOUNT_TRANSFER_CASH`) were computed inside
   the *data-collection* block but consumed by the *write* block (`$account_transfer`, inside the
   `banktransfert` reflabel) and by the page's own export/view rendering (all four) — an implicit
   page-scope handoff that broke once data-collection became a separate method call. Fixed by
   adding all four to `getDataForBank()`'s return array; the page unpacks them once alongside the
   7 `$tab*` arrays, and `writeIntoBookkeepingForBank()` unpacks `account_transfer` from its own
   `getDataForBank()` call.
2. The CSV-export action (`bankjournal.php`, the 3 `$account_ledger = (!empty($obj->...)) ? ... :
   $account_xxx` fallback lines) relied on `$obj` — the bank-line query's loop variable —
   **still holding its last value from data-collection** after the loop ended, since both blocks
   used to share page-level scope. This was already fragile (the "fallback" was really "whatever
   the *last* row happened to contain", not per-row data — likely a preexisting latent bug, not
   intentional), but extracting data-collection into a method makes `$obj` genuinely undefined
   there, which would surface as a live PHP warning corrupting the CSV output. Fixed by using
   `$tabcompany[$key]['accountancy_code_general']` (customer/supplier) and
   `$tabuser[$key]['accountancy_code_general']` (salary) instead — the actual per-bank-line value
   already collected for that purpose, and what the write block itself uses for the same fields.
   The view-block's own 3 analogous lines were **left untouched**: they already read from an
   unrelated count-query's `$obj` (reassigned earlier in the view block, before reaching those
   lines) even before this refactor, so nothing changed there — not this slice's bug to fix.

Also confirmed verbatim and **preserved, not "corrected"**: `writeIntoBookkeepingForBank()`'s
lettering call uses `Lettering::bookkeepingLetteringAll()` (plural), unlike
sells/purchases' `bookkeepingLettering()` (singular) — a genuine difference in the original
source, not a copy-paste slip; and the `$max_nb_errors` parameter defaults to `5` (bank's
original `$MAXNBERRORS`), not `10` like every other journal.

Verified against a golden pre-refactor baseline (byte-for-byte match on `numero_compte`/
`subledger_account`/`debit`/`credit` for a `payment`-type customer payment, via both the page's
own call sequence and the class methods alone), idempotency, and a direct API smoke test of both
new `transfer()`/`pendingData()` branches (including the 501 path with `ACCOUNTING_MODE =
'RECETTES-DEPENSES'`). Test coverage in `test/phpunit/AccountingJournalBankTransferTest.php`:
the golden-baseline `payment` flow, plus a `testUnknownTypeUsesSuspenseAccount()` edge case (a
bank line with no `bank_url` links at all, landing on the configured suspense account) — a branch
none of the other 3 journals' tests exercise. **Testing-environment gotcha worth keeping**:
`BookKeeping::validBookkeepingDate()` caches the active-fiscal-period list in `$conf->cache` on
first use and never auto-refreshes it; a phpunit test class with multiple methods, each seeding
its own new fiscal year, must `unset($conf->cache['active_fiscal_period_cached'])` right after
creating each new `Fiscalyear`, or a later test method's period is invisible to
`BookKeeping::create()`'s date check even though the row exists in the DB (this silently didn't
bite the purchases/sells tests only because their second test method's fixture — a replaced
invoice — returns before ever calling `BookKeeping::create()`).

Phase 3b, fifth slice (treasury journal transfer, `RECETTES-DEPENSES` accounting mode) has been
implemented — see `AccountingJournal::getDataForTreasury()`/`writeIntoBookkeepingForTreasury()`
in `htdocs/accountancy/class/accountingjournal.class.php`, a verbatim lift of
`treasuryjournal.php`'s former inline data-collection (10 per-source-type SQL query/loop pairs —
`payment`/`payment_supplier`/`payment_expensereport`/`payment_salary`/`payment_sc`/`payment_vat`/
`payment_donation`/`payment_loan`/`payment_various`/`member`, plus `banktransfert`, dispatched by
a `switch` over bank-line-linked object types; zero hooks, confirmed the whole file fires none)
and write loop (per-payment/per-object double-entry: a bank-side row, then one row per bound
"operation" account, then one row per VAT bucket, balanced via a running `$total_check`). Wired
into `POST journals/{id}/transfer`/`GET journals/{id}/pendingdata` as the `RECETTES-DEPENSES`
branch of nature `4` — the `501` this backlog previously documented for that combination is gone;
`ACCOUNTING_MODE` now cleanly dispatches to `writeIntoBookkeepingForBank()`/`getDataForBank()` or
`writeIntoBookkeepingForTreasury()`/`getDataForTreasury()` on the exact same journal row, matching
`eldy.lib.php`'s own page-selection check. This closes out Phase 3b — **all 5 journal natures are
now transfer-able via the API.**

Two adaptations, neither a behavior change:
1. The original page defined a page-scoped named function `payment_filter()` (used to
   `array_filter()` out payments with no matched objects). A named `function` declaration can't
   safely live inside a class method body — it would fatal with "Cannot redeclare
   `payment_filter()`" the second time `getDataForTreasury()` (or
   `writeIntoBookkeepingForTreasury()`, which calls it once more internally) runs in the same PHP
   process, e.g. `transfer()` and `pendingData()` both called from one script or phpunit run.
   Replaced with an equivalent inline closure — same filter condition, same result.
2. The write block's `$MAXNBERRORS = 5` was reassigned a second time (to the exact same literal)
   inside the per-payment rollback branch — a pure no-op in the original page, which had no way to
   parametrize it anyway. Threading a real `$max_nb_errors` parameter through (to match every
   sibling `writeIntoBookkeepingForXxx()`) while keeping that second assignment would have turned
   the no-op into a real behavior change — silently clobbering a caller-supplied non-default
   threshold back down to 5 after the first rolled-back payment — so it was dropped instead of
   "preserved".

Also confirmed and preserved verbatim: unlike bank, treasury's `operations` bookkeeping rows book
directly against the invoice/report/etc. line's own bound accounting account
(`fd.fk_code_ventilation` et al.), never through a customer/supplier subledger — there is no
subledger or lettering handling anywhere in treasury's write logic, confirmed while scoping this
slice originally (see the "Bank and treasury" subsection below) and reconfirmed by this
extraction finding no `subledger_account` assignment anywhere in the lifted write block.

Verified against a golden pre-refactor baseline (byte-for-byte match on `numero_compte`/`debit`/
`credit` for a 0%-VAT `payment`-type customer payment, via both the page's own call sequence and
the class methods alone — 0% VAT was chosen so the fixture doesn't need VAT-rate accounting-code
dictionary data seeded, the same simplification purchases' baseline used for reverse-charge/NPR),
idempotency, and a second fixture exercising the structurally distinct `payment_various` branch
(direct `accountancy_code` key, no invoice/thirdparty/binding at all). Test coverage in
`test/phpunit/AccountingJournalTreasuryTransferTest.php`. **Two testing-environment gotchas worth
keeping for the next phpunit work in this repo**:
1. A worktree checkout needs its own `htdocs/conf/conf.php` (gitignored, not shared with the main
   checkout) with `$dolibarr_main_document_root` pointed at *that worktree's* `htdocs`, not the
   main checkout's. Copying `conf.php` verbatim from the main checkout and running phpunit from
   inside the worktree causes `master.inc.php` to resolve `DOL_DOCUMENT_ROOT` to the main
   checkout's `htdocs`, while the test file's own `require_once dirname(__FILE__).'/../../htdocs/
   ...'` resolves to the worktree's `htdocs` — two different real paths for the same class, giving
   a "Cannot declare class X, because the name is already in use" fatal that looks like a code bug
   but is purely a stale-`conf.php` path mismatch.
2. `PaymentVarious::create()` links the new bank line via `update_fk_bank()`, which `UPDATE`s the
   `fk_bank` column in the DB but does **not** set `$this->fk_bank` on the in-memory object — a
   test needs `->fetch($id)` again after `create()` before reading `->fk_bank`, or it reads back a
   stale `0`.

All phases needed for the accountancy module's REST API to fully drive the module end to end
(setup, binding, ledger transfer, closure, reporting) are now implemented — see the status
summary at the top of this file. The sections below are kept as the detailed implementation
record (what shipped, where, verification methodology, gotchas found along the way) rather than
as an open task list.

## Scope decisions (carried over, still apply)

- Step C (ledger transfer) has its logic duplicated inline across 4 UI page scripts with no
  reusable method — refactor it into shared class methods used by both UI and API, rather
  than reimplementing it separately in the API layer.
- VAT-rate and tax-type accounting codes (already handled in Phase 1) live on generic
  dictionary tables; new work here should follow the same "narrow, accountancy-scoped
  endpoint" convention rather than extending the generic dictionary API.

## File layout convention (continue using)

New sibling `api_*.class.php` files under `htdocs/accountancy/class/` (API discovery is pure
filename convention — see `htdocs/api/index.php` / `getModuleDirForApiClass()` in
`htdocs/core/lib/functions2.lib.php:2648`). All new classes: `extends DolibarrApi`, class
docblock `@class DolibarrApiAccess {@requires user,external}`, GPL header matching existing
files. Reference pattern for every endpoint: mirror
`htdocs/compta/facture/class/api_invoices.class.php` (`public static $FIELDS`, private
`_fetch()` helper with rights check + `_checkAccessToResource()` + `_cleanObjectDatas()`,
`index()` with `sqlfilters`/`sortfield`/`sortorder`/`limit`/`page`, `post()`/`put()`/`delete()`,
errors via `RestException`).

| File | Class | Scope |
|---|---|---|
| `htdocs/accountancy/class/api_accountingbind.class.php` | `AccountingBind` | Customer/vendor invoice-line binding (Phase 2) |
| `htdocs/accountancy/class/api_accountingjournals.class.php` (extend, from Phase 1) | `AccountingJournals` | + ledger-transfer action endpoints (Phase 3) |
| `htdocs/accountancy/class/api_accountancy.class.php` (existing) | `Accountancy` | + ledger read/write, closure, and reporting endpoints (Phases 3-5) — it already builds `$this->bookkeeping`/`$this->accountancyexport` |
| `htdocs/accountancy/class/api_accountingclosure.class.php` (only if `Accountancy` grows past ~500 lines) | — | Closure endpoints (Phase 4) |

## Phase 2 — Binding invoice lines (steps A & B) — Implemented

**Was a refactor, not a pure addition.** Bind/unbind ("ventilation") used to be raw inline SQL
duplicated in 4 places, all writing `fk_code_ventilation` (`llx_facturedet` for customer lines,
`llx_facture_fourn_det` for supplier lines), gated by `accounting->bind->write`:
- `htdocs/accountancy/customer/list.php` (`massaction=='ventil'`, mass bind)
- `htdocs/accountancy/customer/card.php` (`action=='ventil'`, single-line bind)
- `htdocs/accountancy/supplier/list.php` / `htdocs/accountancy/supplier/card.php` (vendor equivalents)

All 4 now call the shared methods below instead of inline SQL — see the status paragraph near
the top of this file for the commit and exact line numbers.

`AccountingAccount` (`htdocs/accountancy/class/accountingaccount.class.php:700-748`):
```php
public function bindInvoiceLine($lineid, $accountid, $type = 'customer', User $user = null, $notrigger = 0)
public function unbindInvoiceLine($lineid, $type = 'customer', User $user = null, $notrigger = 0)
```
`$type` selects the target table/column; `unbindInvoiceLine()` is a thin wrapper calling
`bindInvoiceLine($lineid, 0, ...)`. Body is the original `UPDATE` logic, parameterized by type,
wrapped in `begin()`/`commit()`/`rollback()`, returning the standard Dolibarr int convention
(`>0` success, `<0` error, populating `$this->error`/`errors`).

Endpoints implemented in `AccountingBind` (`htdocs/accountancy/class/api_accountingbind.class.php`):

| Endpoint | Purpose | Backs onto |
|---|---|---|
| `GET customerlines/unbound`, `GET supplierlines/unbound` (filters: date range, socid, sqlfilters) | List not-yet-bound lines | mirrors `WHERE f.fk_statut > 0 AND l.fk_code_ventilation <= 0` query pattern in `customer/list.php`/`supplier/list.php` |
| `GET customerlines/{lineid}/suggestaccount`, `GET supplierlines/{lineid}/suggestaccount` | Suggest an account for a line | `AccountingAccount::getAccountingCodeToBind()` (pure wrap) |
| `PUT customerlines/{lineid}/bind`, `PUT supplierlines/{lineid}/bind` `{accountid}` | Single-line bind | `bindInvoiceLine()` |
| `POST customerlines/bind`, `POST supplierlines/bind` `{lineids: [], accountid}` | Mass bind | loops `bindInvoiceLine()`, same partial-failure/aggregate semantics as the UI mass action |
| `PUT customerlines/{lineid}/unbind`, `PUT supplierlines/{lineid}/unbind` | Unbind | `unbindInvoiceLine()` |

Permission: `accounting->bind->write` throughout, matching the original UI gating.

**Verification done**: `test/phpunit/AccountingBindTest.php` covers `bindInvoiceLine()`/
`unbindInvoiceLine()` directly (customer + supplier, success, invalid-type rejection, negative
`accountid` clamping to unbind). **Not yet done**: no test exercises the `AccountingBind` REST
endpoints themselves (HTTP dispatch + permission check) — see the follow-up note in the status
summary at the top of this file.

## Phase 3 — Ledger transfer / "Record transactions in accounting" (step C) — Implemented

**Was the highest-risk phase — a refactor, not a reimplementation.** Split into 3a (additive, low
risk) and 3b (refactor, high risk, 5 slices) — both fully done, see below.

### Phase 3a — Ledger CRUD + lettering — Implemented

`BookKeeping::fetch()/update()/delete()` and `Lettering::updateLettering()/deleteLettering()/
letteringThirdparty()` were already correct, so this half was a straightforward additive wrap —
see the status paragraph near the top of this file for exactly what shipped. Nothing here
changed `bookkeeping.class.php`, `lettering.class.php`, `accountingjournal.class.php`, or any
journal UI page.

### Phase 3b, first slice (create() fix + expense reports) — Implemented

`BookKeeping::create()`'s return-value bug is fixed: on success it now returns the real inserted
row id (`$result = $id`, not `0`), and the `BOOKKEEPING_CREATE` trigger's own return value is
captured into a separate local (`$triggerResult`) so it no longer clobbers `$result` afterward.
Audited all 12 production call sites before making the change (`accountingjournal.class.php`,
`bookkeeping.class.php` internals, `purchasesjournal.php`/`bankjournal.php`/`sellsjournal.php`/
`expensereportsjournal.php`'s own remaining `create()` calls) — every one checks `< 0` for
failure only (or `>= 0` for success, sign-based either way), none relied on the old `0`/
trigger-return-value convention. `BookKeepingTest::testBookKeepingCreate()`'s assertion was also
corrected (`assertGreaterThan(0, $result, ...)`, was backwards `assertLessThan($result, 0, ...)`).

The expense-reports journal (nature 5, the smallest of the remaining 4 — no existing hook to
preserve, permission check already correctly enforced unlike treasury) is now fully extracted:
`AccountingJournal::getDataForExpenseReports()` and `::writeIntoBookkeepingForExpenseReports()`
(`accountingjournal.class.php`) are a mechanical, verbatim lift of
`expensereportsjournal.php`'s former inline data-collection (query + per-report aggregation +
unbound-lines check) and write-loop (thirdparty/fees/VAT `BookKeeping` row creation, balance
check, per-report transaction boundary) — **both stages were lifted**, not just the write loop,
so the pair is fully self-contained and callable from the API without the page's local state
(unlike the original backlog's assumption that only `variousjournal.php` had this shape — see
below). The page now just unpacks `getDataForExpenseReports()`'s return into the same local
variable names it always used, so its view/export rendering code needed zero changes.

**Verification methodology used (reusable template for the remaining 4 journals)**: seeded one
fixture expense report (`ExpenseReport::create()`/`addline()`/`setValidate()`, plus the
accounting config prerequisites — chart of accounts, default accounts, an active fiscal year
covering the fixture date) in the local MariaDB test DB, then ran the **unmodified**
pre-refactor inline logic (copied verbatim into a throwaway script, since the real page requires
`main.inc.php`'s full login/session flow and can't be driven from a bare CLI script the way
`master.inc.php`-based smoke tests can) to snapshot the resulting `llx_accounting_bookkeeping`
rows as a golden baseline. After the refactor, reseeded an identical fixture twice and re-ran:
once via the page's own new call sequence (`getDataForExpenseReports()` +
`writeIntoBookkeepingForExpenseReports()`), once via `writeIntoBookkeepingForExpenseReports()`
alone (matching how `POST journals/{id}/transfer` calls it) — both snapshots matched the
baseline byte-for-byte. Also verified idempotency (re-running finds nothing to do, no duplicate
rows — the already-recorded report is excluded by `getDataForExpenseReports()`'s own `'notyet'`
filter, same pre-existing SQL behavior, not new logic).

New endpoints on `AccountingJournals`: `POST journals/{id}/transfer` `{date_start, date_end}`
and `GET journals/{id}/pendingdata?date_start&date_end`, dispatching on `$journal->nature` (`1`
→ the already-correct `getData()`/`writeIntoBookkeeping()`, reused as-is; `5` → the new
expense-reports pair; anything else → explicit `400`, not a silent no-op), permission
`accounting->bind->write` (matches every journal page's own inline gate). `pendingdata` is
deliberately simple — a list of pending documents + an error flag per document, not a full
trial-balance preview (computing debit/credit subtotals without writing would mean re-deriving
the write loop's math a second time, adding risk for a preview-only feature).

Test coverage: `test/phpunit/AccountingJournalExpenseReportsTransferTest.php` (runs cleanly via
phpunit, unlike this repo's `*ApiTest.php` files — see [[project-accountancy-api-gotchas]] for
why: it doesn't touch the `DolibarrApi` autoloader chain that breaks under this environment's
scratch phpunit install). Live smoke tests for the two new endpoints (nature 1 and nature 5, plus
the `400` for an unsupported nature) also passed.

### Phase 3b, bank and treasury slices — Implemented (original scoping kept below, historical)

Both slices are now done — see the status paragraphs near the top of this file. The 2 largest,
most special-case-laden journal pages were the genuinely risky part of Phase 3; the rest of this
section is the original pre-implementation scoping, kept as historical context since the analysis
held up (including a real bug the pattern's own verification caught — see the phpstan note at the
end of this section, doubly proven by both the bank and treasury extractions).

**Correction to the original assumption, now proven twice**: the backlog originally assumed each
journal's write logic could be extracted with a simple `($user, $date_start, $date_end)`
signature by redoing a generic date-range query inside the class, the way
`variousjournal.php`'s already-migrated `getData()`/`writeIntoBookkeeping()` works. This only
actually holds for `variousjournal.php` (nature 1). Every other journal has its own bespoke,
often hook-coupled data-collection SQL and per-journal keying convention (flat
`$tabht`/`$tabtva`/`$tabttc`-style arrays, not the generic `blocks` structure
`writeIntoBookkeeping()` expects). So each of bank/treasury (like purchases before them) needs its own
`getDataForXxx()` + `writeIntoBookkeepingForXxx()` pair, following the exact pattern established
for expense reports and sells (lift **both** the data-collection query and the write loop into
the class, verbatim, so the pair is self-contained and API-callable) — not a smaller "just
extract the write loop, leave data-collection on the page" version, since that wouldn't support
a REST transfer endpoint standalone.

`BookKeeping` (`htdocs/accountancy/class/bookkeeping.class.php`) already has full
CRUD/list/balance (`create`, `createFromValues`, `createStd`, `fetch*`, `update*`, `delete*`,
`export_bookkeeping`, `transformTransaction`, `canModifyBookkeeping`, `validBookkeepingDate`,
`assignAccountMass`) — straightforward wrap. `AccountingJournal::writeIntoBookkeeping()`
(line 1454) is already the reusable transfer method for the "various operations" journal only
(used by `htdocs/accountancy/journal/variousjournal.php`). Its structure: fires an
`accountingjournaldao`/`writeBookkeeping` hook first (if the hook fully replaces native logic,
native processing is skipped entirely); otherwise loops `$journal_data` per document, builds a
`BookKeeping` object per line from a normalized `$element['blocks']` array, calls `create()`,
aggregates errors (`alreadyjournalized`/`other`/`amountsnotbalanced`), commits/rolls back per
document, and stops early once `$max_nb_errors` is hit. Returns `$error ? -$error : 1` — the
convention every extracted `writeIntoBookkeepingForXxx()` mirrors.

`AccountingJournal::getLibType()` is a **label-only** dispatch (`$nature` → translated string),
not a functional dispatch. The API's nature-based dispatch (`AccountingJournals::transfer()`/
`pendingData()` in `htdocs/accountancy/class/api_accountingjournals.class.php`) supports natures
`1` (various), `2` (sells), `3` (purchases), `4` (bank when `ACCOUNTING_MODE != 'RECETTES-
DEPENSES'`, treasury when it does equal `'RECETTES-DEPENSES'`), and `5` (expense reports) — all 5
natures are now transfer-able via the API, closing out Phase 3b.

| Journal | Page | Inline block lines | Approx. size | Status |
|---|---|---|---|---|
| Sales | `sellsjournal.php` | 496-921 (pre-refactor) | ~425 lines | **Done** |
| Expense reports | `expensereportsjournal.php` | 276-542 (pre-refactor) | ~265 lines | **Done** |
| Purchases | `purchasesjournal.php` | 444-827 | ~385 lines | **Done** |
| Bank | `bankjournal.php` | 716-1084 | ~370 lines | **Done** (non-`RECETTES-DEPENSES` mode) |
| Treasury | `treasuryjournal.php` | 1134-1355 | ~220 lines | **Done** (`RECETTES-DEPENSES` mode) |

#### Purchases (nature 3) — Implemented (kept as reference for the bank/treasury extractions below)

Data-collection (`purchasesjournal.php:142-442`): one main query (hooks `printFieldListSelect`/
`From`/`Where`, context `purchasesjournal`) against `facture_fourn_det`, plus a second
unbound-lines query — same shape as sells/expense-reports. Per-row aggregation is
**meaningfully richer** than either done-so-far journal:
- `$tabfac`/`$tabttc`/`$tabht`/`$tabtva`/`$tablocaltax1`/`$tablocaltax2`/`$tabcompany`/`$def_tva`
  — same family as sells.
- `$tabother[$key][$counterpart_account]` — VAT-NPR (non-récupérable) counterpart amounts, **not
  pre-initialized to 0** like the others (guard every read with `isset(...) && is_array(...)`).
- `$tabrctva`/`$tabrclocaltax1`/`$tabrclocaltax2` — VAT reverse-charge credit/debit counterpart
  entries, built only when `($mysoc->country_code=='FR' || ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE)
  && $obj->vat_reverse_charge==1 && (EEC || ACCOUNTING_REVERSE_CHARGE_ALSO_NON_EEC)`.

Write block (`purchasesjournal.php:444-827`, permission check present and active, no hook call):
**4** `create()` sites — thirdparty/supplier (456-556, includes the same
`Lettering::bookkeepingLettering()` auto-lettering side effect sells has on its own thirdparty
block, gated `ACCOUNTING_ENABLE_LETTERING && ACCOUNTING_ENABLE_AUTOLETTERING`), product/service
(559-629, with an `ACCOUNTING_ACCOUNT_SUPPLIER_USE_AUXILIARY_ON_DEPOSIT` subledger special case),
VAT with reverse-charge substitution (631-729 — when the ordinary VAT amount for a line is zero
and reverse-charge conditions hold, it substitutes `$tabrctva`/`$tabrclocaltax1`/`$tabrclocaltax2`
for the normal `$tabtva`/`$tablocaltax1`/`$tablocaltax2` arrays entirely, using a **second**
`AccountingAccount` cache key `accountingaccountincurrententity_vat`), and VAT-NPR counterpart
(731-780). Same replaced-invoice skip guard as sells (480-494,
`FactureFournisseur::CLOSECODE_REPLACED` + `getVentilExportCompta()`). Max-errors hardcoded `10`
(→ `$max_nb_errors` param, same as the two done journals). `piece_num`/`import_key` left unset,
same auto-derivation as every other journal — do not set them.

Needs `global $conf, $langs, $mysoc;` (mysoc for the reverse-charge country check — one more
than expense reports needed, same as sells needed it for `getTaxesFromId()`). Requires
`fourn/class/fournisseur.facture.class.php` (`FactureFournisseur`, `CLOSECODE_REPLACED`) and
`societe/class/societe.class.php`.

Fixture: `FactureFournisseur` + line, validated (`fk_statut > 0`), line bound via
`fk_code_ventilation` (no bind() method — same raw-SQL-update pattern as every other journal).
Minimal config: `ACCOUNTING_ACCOUNT_SUPPLIER`, `ACCOUNTING_VAT_BUY_ACCOUNT`,
`ACCOUNTING_PRODUCT_BUY_ACCOUNT` or `ACCOUNTING_SERVICE_BUY_ACCOUNT`, chart of accounts + fiscal
year (same as always). Leave `vat_reverse_charge` off and NPR off for the baseline fixture to
keep the expected math simple (3 of the 4 create sites fire: thirdparty, product, VAT) — cover
reverse-charge and NPR in a follow-up test case once the baseline is solid, same incremental
approach sells used for its replaced-invoice guard.

#### Bank and treasury (both nature 4) — genuinely separate implementations, not a shared method

**Both bank and treasury are now Implemented** — see the status paragraphs near the top of this
file for exactly what shipped in each slice (`getDataForBank()`/`writeIntoBookkeepingForBank()`/
`getSourceDocRefForBank()` and `getDataForTreasury()`/`writeIntoBookkeepingForTreasury()` on
`AccountingJournal`, both wired into nature `4` on the API's `transfer()`/`pendingData()`,
dispatched between each other by `ACCOUNTING_MODE`). Everything below in this section was written
*before* either slice and is kept as historical scoping context — the analysis held up, but treat
the status paragraphs above as authoritative for what actually shipped.

**Critical finding, changes the original plan**: `bankjournal.php` and `treasuryjournal.php`
both operate on the *same* journal row (`code='BQ'`, nature 4 — there is only one nature-4 row
in the default `llx_accounting_journal` seed data). They are **not** two views of the same
logic — `treasuryjournal.php` is a from-scratch 2025 rewrite (`bankjournal.php` dates to 2014),
with a completely different data model (10 separate per-source-type SQL queries dispatched by a
`switch`, vs. bank's single query + `get_url()`/`bank_url` link walk), different write mechanism
(`BookKeeping::createFromValues()` vs. hand-set properties + `create()`), different balance-check
arithmetic, and no subledger/lettering handling at all in treasury (bank sets
`subledger_account`/fires lettering for `payment`/`payment_supplier` types; treasury never sets
subledger_account). **Do not attempt a single shared `writeIntoBookkeepingForBank()`** — build
two separate method pairs, e.g. `getDataForBank()`/`writeIntoBookkeepingForBank()` and
`getDataForTreasury()`/`writeIntoBookkeepingForTreasury()`.

Which one actually runs for a given install is decided at the **menu layer**, not by anything on
the `AccountingJournal` object: `htdocs/core/menus/standard/eldy.lib.php` (~line 1803-1878)
picks `treasuryjournal.php` when `getDolGlobalString('ACCOUNTING_MODE') == 'RECETTES-DEPENSES'`
(cash/income-expense simplified accounting), else `bankjournal.php`. **The API's nature=4
dispatch must replicate this same `ACCOUNTING_MODE` check** — there is no other field to key on
since both pages target the identical journal id/code.

**Bank** (`bankjournal.php`) — **Implemented, see the status paragraph near the top of this file
for what actually shipped**; this paragraph is kept as the original pre-implementation spec,
confirmed accurate against the real extraction except where noted there (the `getSourceDocRef()`
migration turned out to need `public`, not `private`, since export/view call it too — see below).
Original spec: data-collection (147-713, zero hooks in the whole file) builds
`$tabpay`/`$tabaccount`/`$tabbq`/`$tabtp`/`$tabcompany`/`$tabuser`/`$tabtype`/`$tabmoreinfo`, one
`db->begin()`/`commit()` per bank line. Write block (716-1084, permission check present and
active) has only 3 physical `create()` call sites but an **11-way payment-type branch**
(`payment`/`payment_supplier`/`payment_expensereport`/`payment_salary`/`sc`/`payment_vat`/
`payment_donation`/`member`/`payment_loan`/`payment_various`/`banktransfert`, plus an `unknown`
fallback to a suspense account) resolving `subledger_account`/`numero_compte` per type. **Depends
on a page-local helper function `getSourceDocRef()` (defined at the bottom of the same file,
lines 1607-1715) that must move into the class too** (as `getSourceDocRefForBank()`, `public` —
not `private` as originally guessed here, since the page's own export-CSV and view-rendering
blocks call it too, from outside the class) — it isn't safe to leave as a bare page-scoped
function, since the extracted class method would then be unusable outside that page. Max-errors
hardcoded `5` (not `10` like the other journals) — preserve that as the parameter default, don't
silently harmonize to `10`.

**Treasury** (`treasuryjournal.php`): permission check is commented out at the write-action `if`
(`/* && $user->hasRight(...) */`) but this is confirmed **safe, not a real gap** — the identical
check runs unconditionally near the top of the page (`accessforbidden()` block, same pattern as
every other journal page) before any action dispatch happens. Data-collection (124-1131) is the
most complex of any journal: 10 separate per-source-type SQL query/loop pairs
(`payment`/`payment_supplier`/`payment_expensereport`/`payment_salary`/`payment_sc`/
`payment_vat`/`payment_donation`/`payment_loan`/`payment_various`/`member`) dispatched by a
`switch` over bank-line-linked object types, building `$tabpay`/`$tabaccount`/`$tabobject`/
`$tabaccountingaccount`. Write block (1134-1355) is comparatively uniform (branches only on the
sign of each object's bank-leg amount, not per-source-type — that resolution already happened
during collection) and reads **zero** config globals directly (fully deterministic given the
collected arrays) — but uses `BookKeeping::createFromValues()`, which pulls `global $user;`
internally rather than taking a `$user` parameter, so passing a `User $user` param to
`writeIntoBookkeepingForTreasury()` won't actually control which user triggers behind
`createFromValues()`'s own trigger calls — a pre-existing footgun to note, not fix. All 4
`createFromValues()` call sites deliberately pass `fk_docdet = 0` (not the real per-object id) —
preserve verbatim, it's load-bearing per an inline comment about the `(fk_doc, fk_docdet)`
uniqueness key. `$MAXNBERRORS = 5` (not `10`), same as bank.

**Verification**: reuse the exact methodology proven for expense reports and sells — seed a
fixture, capture a golden baseline by running the **unmodified** page's inline logic (copied
verbatim into a throwaway script; the real page can't be driven from a bare CLI script since it
requires `main.inc.php`'s full login flow, no `NOLOGIN` bypass), refactor, reseed identically,
re-run via both the new page and the new class method alone, diff byte-for-byte against the
baseline, and check idempotency. Add `test/phpunit/AccountingJournal<Name>TransferTest.php` per
journal, following `AccountingJournalSellsTransferTest.php`'s structure (2 test methods: the
main golden-baseline flow, and one targeted edge-case test — e.g. purchases' reverse-charge
substitution or NPR counterpart, matching how sells got a dedicated replaced-invoice test).

**Explicit risk flag**: this is a behavior-preserving refactor of live production financial
logic, not new logic. Any output deviation is a regression, not an improvement.

**Process note, learned the hard way on sells**: run **phpstan** (not just parallel-lint/phpcs)
on both the modified `AccountingJournal` class and the refactored page after every extraction,
before considering it done. The sells extraction initially dropped a page-local `$error++`
(inside data-collection's too-many-lines guard) that the page's write-action gate depended on —
lint/phpcs/the golden-baseline test with normal-sized fixtures all stayed green, but phpstan's
"condition is always true" finding on the write gate's `if` surfaced the gap immediately. This
class of bug (an error/state flag that used to flow implicitly through shared page scope, now
needing to be threaded explicitly through a method's return value) is exactly the kind of thing
a small fixture won't exercise but phpstan's control-flow analysis catches for free — treat it as
a required step, not an optional extra, for treasury too — bank's own extraction needed it twice
over (see the two bugs documented in the status paragraph near the top of this file), so treat
this as doubly proven, not a one-off.

## Phase 4 — Close accounting period (step E) — Implemented

Lower risk: wraps 3 already-correct, already-sequenced `BookKeeping` methods, mirroring
`htdocs/accountancy/closure/index.php`'s 3-step wizard (`confirm_step_1`→
`validateMovementForFiscalPeriod()`, `confirm_step_2`→`closeFiscalPeriod()`,
`confirm_step_3`→`insertAccountingReversal()`, all gated `accounting->fiscalyear->write`).

Add to `Accountancy` or a new `api_accountingclosure.class.php` if `Accountancy` has grown past
~500 lines by this point (decide at implementation time):

| Endpoint | Wraps | Precondition (defense-in-depth — don't rely solely on the model layer since API callers skip the wizard's own step-state) |
|---|---|---|
| `POST fiscalperiods/{id}/validate` `{date_start, date_end}` | `validateMovementForFiscalPeriod()` | period not already validated |
| `POST fiscalperiods/{id}/close` `{new_fiscal_period_id, separate_auxiliary_account, generate_bookkeeping_records}` | `closeFiscalPeriod()` | period must be validated first — 409/400 otherwise |
| `POST fiscalperiods/{id}/reversal` `{inventory_journal_id, new_fiscal_period_id, date_start, date_end}` | `insertAccountingReversal()` | period must be closed first |
| `GET fiscalperiods`, `GET fiscalperiods/{id}` | `loadFiscalPeriods()`/`getFiscalPeriods()` | read-only, `accounting->mouvements->lire` or `->fiscalyear->write` |

**Verification**: manual regression — run the 3-step wizard via UI on a test period, then
repeat the same 3 steps via API on an equivalent freshly-seeded period, diff resulting
`llx_accounting_fiscalyear` status and reversal rows. Add a phpunit test asserting out-of-order
calls (e.g. `close` before `validate`) are rejected.

## Phase 5 — Reporting / exports (step D)

Mostly additive. `AccountancyExport::export()`
(`htdocs/accountancy/class/accountancyexport.class.php:383`) already covers all needed formats
(FEC/FEC2/Cegid/SAGE50 Swiss/configurable CSV/etc.) and is already fully reachable via the
existing `Accountancy::exportData()` — no changes needed there beyond confirming format
coverage. New additive endpoints on `Accountancy`:
- `GET ledger/balance` (dedupe with Phase 3 if already added there) — wraps
  `BookKeeping::fetchAllBalance()`, i.e. trial balance
  (`htdocs/accountancy/bookkeeping/balance.php`).
- `GET ledger` with richer filters (account, journal, date range, export/validation status) —
  wraps `fetchAll()`/`fetchAllByAccount()`, matching
  `htdocs/accountancy/bookkeeping/list.php`/`listbyaccount.php`.

Permission: `accounting->comptarapport->lire` for report reads, `accounting->mouvements->export`
for the existing export endpoint (unchanged).

**Verification**: compare API `GET ledger`/`GET ledger/balance` against
`htdocs/accountancy/bookkeeping/list.php`/`balance.php` UI output for matching filters; confirm
`exportData` output byte-matches a UI-driven export (`htdocs/accountancy/bookkeeping/export.php`)
for at least one format (e.g. FEC).

## Phase 6 — Chart-of-accounts model CRUD (step 2) — Implemented

Closes the "Missing" item from `roadmap/API_GAPS.md`'s summary table: `AccountancySystem`
(`htdocs/accountancy/class/accountancysystem.class.php`) previously had only `fetch()`/`create()`,
and `api_accountingsetup.class.php` only exposed `GET accountingsystems[/{id}]`.

**Model**: added `AccountancySystem::update($user)`/`delete($user)`, mirroring
`Fiscalyear::update()`/`delete()` (`htdocs/core/class/fiscalyear.class.php:181-216`/`:264-280`) —
`db->begin()`/parameterized query by `rowid`/`db->commit()`/`rollback()`, standard `>0`/`<0`
return convention. `update()` touches exactly the fields `fetch()` loads: `label`, `pcg_version`,
`active`.

**API**: added `POST/PUT/DELETE accountingsystems[/{id}]` to `api_accountingsetup.class.php`,
mirroring the `fiscalyears` CRUD block's structure (mandatory-field array
`$FIELDS_ACCOUNTINGSYSTEM`, `_checkValForAPI()` sanitization, `RestException` on 400/404/500),
gated on `accounting->chartofaccount` (matching this resource's existing `GET` endpoints, not the
fiscalyear right).

**Guards added at the API layer** (confirmed no DB-level protection exists —
`llx_accounting_account.key.sql:27` has the `fk_pcg_version` → `accounting_system.pcg_version`
foreign key commented out, and `fk_pcg_version` matches by string value, not rowid):
- `putAccountingSystem`: rejects (400) any attempt to change `pcg_version` — only `label`/`active`
  are accepted. Renaming `pcg_version` would silently orphan every `accounting_account` row
  already bound to the old value, since the link is a string match, not a FK.
- `deleteAccountingSystem`: rejects (409) deleting the chart currently active via the
  `CHARTOFACCOUNTS` global, and rejects (409) deleting a chart that still has `accounting_account`
  rows with a matching `fk_pcg_version` (in-use guard) — same defense-in-depth precondition
  pattern used by Phase 4's closure endpoints and Phase 3a's ledger delete.

**Tests**: extended the pre-existing `test/phpunit/AccountancySystemTest.php` (previously covered
only `create`/`fetch`) with `testAccountancySystemUpdate`/`testAccountancySystemDelete` in the
same `@depends` chain and style. Passes via the phpunit 9.5 phar
(`test/phpunit/AccountancySystemTest.php`, 4 tests / 10 assertions). Per
[[project-accountancy-api-gotchas]], the 3 new REST endpoints were verified with a live smoke
test (direct instantiation against the real test DB, not a `*ApiTest.php` file) rather than
through the Restler dispatcher — confirmed create → get → update (label allowed, pcg_version
rejected with 400) → delete → get-after-delete (404), plus both delete guards (409 on the active
chart, 409 on a chart with a bound account, success after unbinding).

## Phase 7 — Activate a chart of accounts (step 3, "select" half) — Implemented

Closes the "Missing" item from `roadmap/API_GAPS.md`'s summary table for actually *selecting* a
chart of accounts (Phase 6 covers CRUD on the chart-of-accounts *models* themselves). Flagged as
the highest-risk item in this backlog: no existing `api_*.class.php` anywhere in this codebase
called `run_sql()` before this phase, the admin page it mirrors
(`htdocs/accountancy/admin/account.php:170-219`) has no `is_readable($sqlfile)` guard before
`file_get_contents()`, and `run_sql()`'s error handling tolerates *some* reruns via its
`$okerror='default'` whitelist but isn't truly idempotent — a partial rerun could leave mixed
state.

**Model**: new `AccountancySystem::activate($user)`
(`htdocs/accountancy/class/accountancysystem.class.php`) — a faithful mirror of the admin page's
two-step logic (resolve country code via a `c_country`/`accounting_system` join, `run_sql()` the
matching `install/mysql/data/llx_accounting_account_<country>.sql` with the same offset math,
then `dolibarr_set_const($db, 'CHARTOFACCOUNTS', $this->id, ...)`), plus one guard the page
itself lacks: `is_readable($sqlfile)` before touching it, failing cleanly (`$this->error` set,
negative return) instead of a raw file-read warning. Deliberately **not** a refactor of
`account.php` itself onto the new method — unlike Phase 3's ledger-transfer logic (called out by
this backlog's own scope decisions for UI/API sharing because it was original core-workflow
scope), Phase 7 is follow-up scope and touching a live admin page's chart-loading logic was judged
out of proportion to this phase's goal; `activate()` mirrors the page, it doesn't absorb it —
consistent with how Phases 6 and 8 added new model methods without touching their sibling UI
pages. `admin.lib.php` (for `run_sql()`/`dolibarr_set_const()`) is now `require_once` at the top
of `accountancysystem.class.php` itself, so the class is self-contained regardless of what already
required it in a given call path.

**API**: two new endpoints on `AccountingSetup`
(`htdocs/accountancy/class/api_accountingsetup.class.php`), gated on `accounting->chartofaccount`
(matching every other endpoint on this resource) — a safety design confirmed with the user before
implementation, since this is a high-impact, not-fully-idempotent write:
- `GET accountingsystems/{id}/activate/preview` — read-only, no `run_sql()`/`dolibarr_set_const()`
  call. Resolves and returns `{id, country_code, sqlfile, sqlfile_readable, already_active}` via a
  shared private `_resolveActivationCountryCode($id)` helper (used by both endpoints so the
  country/sqlfile resolution logic isn't duplicated between them).
- `POST accountingsystems/{id}/activate` `{confirm: true}` — 400 if `confirm` is missing/falsy
  (message points callers at the preview endpoint first); **409 if the target model is already the
  active chart** (`CHARTOFACCOUNTS` already equals `{id}`) — a hard reject with no override,
  matching this backlog's existing conservative guard style (Phase 6/8's delete guards have no
  override either) rather than risking a partial-rerun's mixed state; otherwise calls `activate()`
  and returns the updated `AccountancySystem` object plus the resolved `country_code`/`sqlfile` for
  confirmation. Returns a plain array (not a mutated `AccountancySystem` instance with extra
  properties bolted on) to avoid a PHP 8.2 dynamic-property deprecation, since `country_code`/
  `sqlfile` aren't declared properties on that class.

**Verification**: `test/phpunit/AccountancySystemActivateTest.php` — activates the
already-seeded Swedish chart-of-accounts model (`llx_accounting_system` row for `BAS-K1-MINI`,
from `install/mysql/data/llx_accounting_system.sql`'s standard seed data, not a test-only
fixture), chosen because its data file (`llx_accounting_account_se.sql`, 60 lines) is the smallest
of all 37 supported countries, keeping the test fast; asserts new `llx_accounting_account` rows
land and `CHARTOFACCOUNTS` updates. A second test confirms a freshly `create()`d model (which has
no `fk_country` — `create()` never writes that column) fails activation cleanly rather than
warning. Passes via the phpunit 9.5 phar (2 tests / 6 assertions); confirmed the outer
`setUpBeforeClass()`/`tearDownAfterClass()` transaction wrapping (per
[[project-accountancy-api-gotchas]] item 10) fully rolls back `run_sql()`'s and
`dolibarr_set_const()`'s writes — both call `$db->query()` per-statement with no independent
commit, so nesting inside the outer transaction holds; verified directly by querying the DB after
the test run and finding `CHARTOFACCOUNTS` and the Swedish account-row count unchanged. The two new
REST endpoints were additionally verified end-to-end with a live smoke test (direct instantiation
against the real test DB, matching the Phase 6/8 pattern, wrapped in its own manual
`$db->begin()`/`rollback()`): preview (`already_active: false`) → POST without `confirm` (400) →
POST with `confirm: true` (success, `CHARTOFACCOUNTS` updated) → preview again
(`already_active: true`) → POST again (409) → preview on a nonexistent id (404) — all passed, and
a post-rollback DB check confirmed zero permanent changes. `phpstan` (level 10, via the
CI-matching `bootstrap_action.php` bootstrap) passes clean on both modified files.

## Phase 8 — Accounting account categories CRUD + assignment — Implemented

Closes the "Missing" item from `roadmap/API_GAPS.md`'s summary table: `AccountancyCategory`
(`htdocs/accountancy/class/accountancycategory.class.php`) already had full
`create`/`fetch`/`update`/`delete` plus `updateAccAcc()`/`deleteCptCat()`/`getCptsCat()` for the
account↔category relationship, but no `api_*.class.php` anywhere referenced it.

**One real bug found and fixed along the way, same class of issue as `BookKeeping::create()` in
Phase 3b**: `AccountancyCategory::create()` returned `$this->id` on success, but `$this->id` is
only ever assigned inside `fetch()` — `create()` itself never set it (no `last_insert_id()` call),
so a fresh `create()` call returned whatever `$this->id` happened to already hold, not the new
row's id. Unlike `BookKeeping::create()`'s fix (which needed a 12-call-site audit), `grep` found
**zero existing callers** of `AccountancyCategory::create()` anywhere in the codebase — the UI
(`htdocs/accountancy/admin/categories.php`) manages categories through Dolibarr's generic
dictionary admin page, not this class — so this was a safe, isolated fix: capture the id via
`$this->db->last_insert_id(...)` and set `$this->id = $this->rowid` before returning.

**Two more real quirks found during implementation/testing, both confirmed and worked around
rather than "fixed" (existing, in-scope behavior, not new bugs introduced by this phase)**:
1. `AccountancyCategory::fetch($id)` always returns `1` when the SQL query itself succeeds, even
   if no row matched the given id (unlike `AccountingAccount::fetch()`, which returns falsy) — the
   row's fields are only populated inside an `if ($this->db->num_rows($resql))` guard. So the new
   API's `_fetch()` helper checks `empty($category->id)` after `fetch()`, not `fetch()`'s own
   return value, to decide 404.
2. `code`/`label`/`range_account`/`sens`/`category_type`/`formula` all map to `NOT NULL` columns
   on `c_accounting_category` with **no DB default** for `range_account`/`formula`
   (`llx_c_accounting_category-accounting.sql`), and `create()`/`update()` write an explicit `NULL`
   for any of them left unset on the object rather than omitting the column — confirmed via a live
   query against the test DB that omitting any of the four beyond code/label throws a `NOT NULL`
   constraint error. All six are listed as mandatory in the API's `$FIELDS`, not just the two
   "identifying" fields a first read of the model suggests.

**Model**: no other model changes — `updateAccAcc()`/`deleteCptCat()`/`getCptsCat()` were already
correct and are used as-is.

**API**: new `htdocs/accountancy/class/api_accountingcategories.class.php`
(`AccountingAccountCategories extends DolibarrApi`), mirroring
`api_accountingaccounts.class.php`'s established CRUD structure (`$FIELDS`/`$SETTABLE_FIELDS`,
`_fetch()`, `index()` with sqlfilters/sort/pagination, `post()`/`put()`/`delete()` via
`RestException`), gated on `accounting->chartofaccount` throughout (matching this resource
family's existing convention). Plus 3 assignment endpoints modeled on
`htdocs/categories/class/api_categories.class.php`'s link/unlink URL shape but collapsed to this
model's actual mechanism — a direct FK column, not a join table, so no `add_type`/`del_type`
equivalent:
- `POST {id}/accounts/{account_id}` (`linkAccount()`) — fetches the target `AccountingAccount`,
  formats its `account_number` via `length_accountg()`, calls `updateAccAcc()`. Since
  `updateAccAcc()` only matches accounts belonging to the *currently active* chart of accounts
  (`CHARTOFACCOUNTS`) and silently no-ops (no SQL error) for an account outside that chart, the
  endpoint re-fetches the account afterward and verifies `account_category == $id` before
  reporting success — trusting `updateAccAcc()`'s own return value alone would have made this
  silent-no-op case look like a successful link. (Also confirmed while wiring this up:
  `AccountingAccount::fetch()` maps the DB column `fk_accounting_category` to the PHP property
  `$this->account_category`, not `$this->fk_accounting_category` — another misnamed-field trap in
  the same family as `fk_pcg_version`, per [[project-accountancy-api-gotchas]] item 1.)
- `DELETE {id}/accounts/{account_id}` (`unlinkAccount()`) — thin wrap of `deleteCptCat()`, which is
  keyed directly by `accounting_account.rowid`, no pre-fetch needed.
- `GET {id}/accounts` (`getAccounts()`) — thin wrap of `getCptsCat()`; added beyond the backlog's
  original 2-endpoint sketch since without it there was no way to verify assignment state via the
  API at all.

**Guard added at the API layer** (same precondition pattern as Phase 6's `deleteAccountingSystem`
and Phase 3a's ledger delete — no DB-level FK/cascade protects
`accounting_account.fk_accounting_category` from going orphaned): `delete()` calls `getCptsCat()`
first and rejects (409) if any accounts are still assigned to the category.

**Tests**: new `test/phpunit/AccountancyCategoryTest.php`, following `AccountancySystemTest.php`'s
`@depends`-chain style from Phase 6 (create → fetch → update → assignment round-trip via
`updateAccAcc()`/`getCptsCat()`/`deleteCptCat()` against a fixture `AccountingAccount` seeded on
the test DB's active chart of accounts → delete). Passes via the phpunit 9.5 phar (5 tests / 20
assertions). The 3 new `AccountingAccountCategories` REST endpoints (plus the CRUD ones) were
additionally verified end-to-end with a live smoke test (direct instantiation against the real
test DB, matching Phase 6's approach) — create → get → put → link → list → delete-guard (409) →
unlink → list (empty) → delete → get-after-delete (404) → link-with-missing-account (404) — all
passed. `phpstan` (level 10, via the CI-matching `bootstrap_action.php` bootstrap) passes clean on
both the new API file and the modified `accountancycategory.class.php`.

**Test-environment gotcha found and fixed permanently in the shared test DB** (per
[[project-accountancy-api-gotchas]] item 10's environment): `AccountancyCategory::getCptsCat()`
calls `dol_print_error()`/`exit()` (a hard process exit, not a catchable error) if
`$mysoc->country_id`/`country_code` aren't set — and the shared test DB had no
`MAIN_INFO_SOCIETE_COUNTRY` const configured at all, since no prior phase's fixtures needed
`$mysoc`'s country. Set permanently via `dolibarr_set_const($db, 'MAIN_INFO_SOCIETE_COUNTRY',
'1:FR:France', 'chaine', 0, '', $conf->entity)` (rowid `1` = FR in this test DB's `c_country`) —
a one-time, durable environment fix in the same spirit as enabling missing modules in prior
sessions, not a per-script workaround. Also note for future fixtures in this codebase: a freshly
`create()`d `AccountingAccount` defaults to `active = 0` unless explicitly set — several existing
model methods (including `updateAccAcc()`, `getCptsCat()`) filter on `active = 1`, so any fixture
account meant to participate in category/journal logic must set `->active = 1` before `create()`.

## Phase 9 — Ledger-transfer preview amounts (step C enhancement) — Implemented

Closes the "Missing" item from `roadmap/API_GAPS.md`'s summary table:
`AccountingJournals::pendingData()` (`api_accountingjournals.class.php:353-433`) used to return
only `ref`/`has_error` per pending document for natures 2 (sells) and 3 (purchases), with a
docblock claiming real subtotals would mean re-deriving the write loop's math a second time.
Scoping this phase confirmed that claim was overstated for these two natures:
`getDataForSells()`/`getDataForPurchases()` already collect per-invoice, per-account-bucketed
arrays (`tabht`/`tabtva`/`tablocaltax1`/`tablocaltax2`/`tabttc`, plus `tabwarranty`/
`tabrevenuestamp` for sells and `tabother`/`tabrctva`/`tabrclocaltax1`/`tabrclocaltax2` for
purchases), and both `writeIntoBookkeepingForSells()`/`writeIntoBookkeepingForPurchases()`'s
debit/credit derivation from those arrays is a pure sign-split (`debit = max($mt,0)`,
`credit = max(-$mt,0)`, or the inverted rule per bucket) with no DB dependency. Nature 4
(bank/treasury) and nature 1 (various)/5 (expense reports) stay exactly as before — neither has a
comparable per-line tab-array structure to aggregate cheaply.

**Model**: two new read-only aggregator methods on `AccountingJournal`
(`htdocs/accountancy/class/accountingjournal.class.php`), each inserted right after its
`getDataForXxx()` sibling: `getPreviewAmountsForSells(array $data)` and
`getPreviewAmountsForPurchases(array $data)`. Both take the array already returned by
`getDataForSells()`/`getDataForPurchases()` (no re-querying) and return, per invoice,
`total_ht`/`total_ttc` (plain sums of `tabht`/`tabttc`) and `total_debit`/`total_credit` (the
actual amounts the write would post, folding in every bucket with that write method's exact
per-bucket sign convention — verified bucket-by-bucket against the real code, not assumed).
Purchases' aggregator additionally replicates the write method's VAT reverse-charge substitution
verbatim (swap `tabtva`/`tablocaltax1`/`tablocaltax2` for `tabrctva`/`tabrclocaltax1`/
`tabrclocaltax2` when the normal bucket is all-zero and country/force-flag conditions hold) —
this is the one place the "trivial sign split" framing undersold the actual complexity, so it's
covered by a dedicated synthetic test (see Tests below) rather than left to the simple baseline
fixture, which has reverse-charge switched off. Both methods are pure functions: no `$this->db`
query, no `BookKeeping` object construction, no `create()`/`begin()`/`commit()` call.

**API**: `AccountingJournals::pendingData()` (`htdocs/accountancy/class/api_accountingjournals.class.php`)
now calls the matching new aggregator for natures 2/3 and adds `total_ht`/`total_ttc`/
`total_debit`/`total_credit` to each item. Two deliberate zeroing decisions, applied at the API
layer rather than inside the aggregator (keeping the aggregator a pure "what the tab-arrays say"
function, with the business rule of "don't show a subtotal for something that won't cleanly
transfer" applied one layer up, the same way `has_error` itself is already computed above
`getDataForSells()`, not inside it):
- The 4 fields are forced to `0.0` for an invoice that already `has_error`.
- The 4 fields are also forced to `0.0` for a replaced-but-not-yet-dispatched invoice
  (`close_code == Facture::CLOSECODE_REPLACED`/`FactureFournisseur::CLOSECODE_REPLACED`) — a real
  gap this phase surfaced: `writeIntoBookkeepingForSells()`/`writeIntoBookkeepingForPurchases()`
  silently skip this case entirely (0 bookkeeping rows), but it was never flagged by
  `errorforinvoice`, so an unguarded preview would show a plausible non-zero subtotal for a
  transfer that actually produces nothing. Small, isolated, API-layer-only addition — no change
  to either write method.

Natures 1, 4, and 5 (unchanged) now add the same 4 keys to each item set to `null`, so `items`
keeps one uniform shape across every nature — a client checks for `null` rather than branching on
`nature` to know which fields apply.

**Tests**: extended `test/phpunit/AccountingJournalSellsTransferTest.php` and
`AccountingJournalPurchasesTransferTest.php` with a self-consistency test each
(`testGetPreviewAmountsForSellsMatchesWrite()`/`testGetPreviewAmountsForPurchasesMatchesWrite()`,
fiscal years 2960/2961) — capture the preview *before* calling `writeIntoBookkeepingForXxx()`,
then assert the actually-written `SUM(debit)`/`SUM(credit)` in `llx_accounting_bookkeeping` match
the captured preview values. The purchases file also gets two synthetic unit tests
(`testGetPreviewAmountsForPurchasesVatReverseCharge()`/`testGetPreviewAmountsForPurchasesVatNpr()`)
that hand-build a `$data` array literal and call `getPreviewAmountsForPurchases()` directly, no DB
fixture at all — the only practical way to exercise the reverse-charge-substitution and
NPR-counterpart branches, which the simple baseline fixture (reverse-charge and NPR both switched
off, same simplification the original purchases write-method slice used) never triggers. All 8
tests across both files pass via the phpunit 9.5 phar (`AccountingJournalSellsTransferTest.php`:
3 tests/38 assertions; `AccountingJournalPurchasesTransferTest.php`: 5 tests/48 assertions). The
two new `pendingData()` code paths (plus a nature-1 regression check) were additionally verified
with a live smoke test (direct instantiation against the real test DB, matching the Phase 6/7/8
pattern, wrapped in its own manual `$db->begin()`/`rollback()`) — all passed. `phpstan` (level 10,
via the CI-matching `bootstrap_action.php` bootstrap) passes clean on both modified production
files; running it against the two modified test files surfaces only pre-existing PHPUnit-stub
noise (confirmed identical on an untouched sibling test file), nothing specific to the new code.

## Phase 10 — Remaining API_GAPS.md item — Open, scoped not implemented

Deliberately left out of Phases 6, 7, 8, 9, and 11 for a concrete reason (risk, an unresolved
signature mismatch, unconfirmed correctness, or no precedent to build on in this codebase) — see
`roadmap/API_GAPS.md` for the original gap analysis this backlog is closing out.

**Phase 10 — Product accountancy codes under `MAIN_PRODUCT_PERENTITY_SHARED`.** A narrow `PUT
products/{id}/accountancycodes` wrapping `Product::setAccountancyCode($type, $value)`
(`htdocs/product/class/product.class.php:2149-2210`) would bypass `update()`'s per-entity-mode
skip of the 6 `accountancy_code_*` fields (`product.class.php:1621-1628`). **Do not implement
until researched further**: `setAccountancyCode()` writes to the base `product` table's columns
unconditionally, even when `MAIN_PRODUCT_PERENTITY_SHARED` is on — but that mode's whole premise
is that these fields live in `product_perentity` instead. Confirm how the per-entity-aware read
paths (product card, wherever these fields are resolved with entity precedence) actually consume
the base-table column before wiring an API endpoint to it, or the endpoint will "close" this gap
only nominally, not functionally.

## Phase 11 — Chart-of-accounts CSV import — Implemented

Closes the last open item from `roadmap/API_GAPS.md`'s summary table other than Phase 10.
`AccountancyImport` (`htdocs/accountancy/class/accountancyimport.class.php`) turned out to be a
red herring for this phase — it's a set of stateless per-field compute-rule callbacks
(`cleanAmount`, `computeDirection`, etc.) used only by the *general ledger* import profile, not
the `Chartofaccounts` one, so it needed no changes. The `Chartofaccounts` profile itself
(`htdocs/core/modules/modAccounting.class.php:270-287`, `import_code = 'accounting_1'` since
`$this->rights_class = 'accounting'` — confirmed via grep, not the initially-guessed `compta_1`)
declares 9 fields in a fixed order (`fk_pcg_version*`, `account_number*`, `label*`,
`account_parent`, `fk_accounting_category`, `pcg_type*`, `centralized*`, `active*`, `datec`, 6 of
them mandatory) with `fk_pcg_version`+`account_number` pre-declared as update-vs-insert matching
keys.

**No precedent existed for file-content-over-REST import anywhere in this codebase, and the real
driver sequence for Dolibarr's generic Import engine lives only as inline procedural code inside
the interactive wizard page** (`htdocs/imports/import.php`'s step 5/6 action blocks,
`import.php:1580-1980` simulate and `import.php:2080-2420` real run — not an extracted reusable
method). Reconstructing that sequence headlessly was this phase's actual work:
`Import::load_arrays($user, 'accounting_1')` → `ImportCsv::import_get_nb_of_lines()` →
`import_open_file()` → loop `import_read_record()`/`import_insert()` → `import_close_file()`,
with the real (non-simulated) commit policy mirrored exactly: roll back immediately if any line
produced an error, otherwise run the profile's `array_import_run_sql_after` (empty for this
profile) and commit.

**Model**: no changes — `AccountancyImport`, `Import`, `ImportCsv`, and the `Chartofaccounts`
profile declaration were all already correct. This phase is a pure orchestration wrapper, same
shape as Phase 7's `activate()` (mirrors the wizard's existing logic rather than refactoring the
wizard page itself, consistent with this backlog's scope-decision convention for follow-up-scope
phases).

**API**: new `htdocs/accountancy/class/api_accountingimport.class.php` (`AccountingImport extends
DolibarrApi`), one endpoint: `POST accountingsystems/importchart`, gated on
`accounting->chartofaccount` (matching every chart-of-accounts-adjacent endpoint from Phases 6/7,
rather than the generic wizard's own `import->run` right). Accepts `filecontent`
(raw or, with `fileencoding: 'base64'`, base64-encoded — same convention as
`api_documents.class.php`'s `post()`), `filename` (cosmetic, sanitized), `excludefirstline`
(number of leading lines to skip, default 1), `updateifexists` (opt-in update-vs-insert, default
insert-only), and `simulate` (always rolls back, for a dry-run preview). **Deliberate scope
simplification, not present in the wizard**: the endpoint requires the CSV's 9 columns to already
be in the profile's exact declared field order — `array_match_file_to_database` is built
positionally from `array_keys($objimport->array_import_fields[0])` rather than accepting a
caller-supplied column-mapping structure, since building a general column-mapping mini-language
for a REST body was judged out of proportion to this single fixed-schema dataset (the wizard's own
first-load auto-mapping falls back to this exact same positional assignment —
`import.php:877-894` — so this isn't a new behavior, just skipping the interactive override step).
The temp file is written to `$conf->import->dir_temp` (`DOL_DATA_ROOT/import/temp`, the same
directory the wizard itself uses) and deleted at the end of the request — unlike the wizard, which
keeps it around for a multi-step session, this endpoint receives fresh content each call, so
nothing depends on the file surviving past the request; `dolCheckVirus()` runs before the file is
ever opened for import, matching `api_documents.class.php`'s upload-content precedent.

**Verification**: `parallel-lint` (`php -l`, `vendor/bin/parallel-lint` isn't installed in this
environment per `CLAUDE.md`'s disabled-composer note) and `phpstan` (level 10, via the
CI-matching `bootstrap_action.php` bootstrap per [[project-accountancy-api-gotchas]] item 10) both
pass clean on the new file, no baseline suppressions. Verified end-to-end with a live smoke test
(direct instantiation against the real local test DB, matching every prior phase's pattern) using
the `PCG25-DEV` chart-of-accounts model (already seeded and active in the test DB) with a 2-row
CSV fixture: `simulate: true` (2 rows report `nbok`, zero committed rows land) → real run
(`committed: true`, 2 rows land) → re-run with `updateifexists: true` and a changed label (updates
in place, still 2 rows, not 4) → a deliberately malformed row (missing mandatory `Label`) in a
2-row batch (whole request rolls back, zero rows land from either line — all-or-nothing, matching
the wizard's own real-run commit policy) → confirmed the temp file under `$conf->import->dir_temp`
is gone after every request, success or failure. All 5 checks passed.

**Gotchas found during implementation** (added to
[[project-accountancy-api-gotchas]] as items 33-35): a CLI smoke-test harness booting through the
lighter `master.inc.php` (per item 2/18's established pattern) is missing two functions this
endpoint's dependencies need at runtime — `dolCheckVirus()`/`dolChmod()`/`dol_delete_file()`
(from `core/lib/files.lib.php`, not auto-loaded by `master.inc.php`) and `testSqlAndScriptInject()`
(from `waf.inc.php`, loaded only by the full `main.inc.php` chain the real REST dispatcher
actually uses via `api/index.php`). Neither needed an explicit `require` inside the production API
file itself — the real REST dispatcher already loads both — but a smoke-test harness must
`require_once` both manually (`waf.inc.php` documents itself as having "no dependency with any
other code", safe to require standalone). Also confirmed a pre-existing, harmless quirk in
Dolibarr's own `import_csv.modules.php` (`import_insert()` line 714,
`if (!is_array($this->cachefieldtable[$cachekey]))` on a never-yet-set array key) — a
notice-level "undefined array key" under strict error reporting on every call, not something
introduced by or worth fixing in this phase (same class of "existing quirk, preserved not fixed"
documented for other journals' pre-existing behavior throughout this backlog).

## Critical files (Phases 1-9, 11)

- `htdocs/accountancy/class/bookkeeping.class.php`
- `htdocs/accountancy/class/lettering.class.php`
- `htdocs/accountancy/class/accountingjournal.class.php` (`getPreviewAmountsForSells()`/
  `getPreviewAmountsForPurchases()` added in Phase 9)
- `htdocs/accountancy/class/api_accountingjournals.class.php` (`pendingData()` extended in Phase 9)
- `htdocs/accountancy/class/accountancysystem.class.php` (`activate()` added in Phase 7)
- `htdocs/accountancy/class/accountancycategory.class.php` (bug fix in `create()` in Phase 8)
- `htdocs/accountancy/class/api_accountingcategories.class.php` (new in Phase 8)
- `htdocs/accountancy/journal/{sellsjournal,purchasesjournal,bankjournal,treasuryjournal,expensereportsjournal,variousjournal}.php`
- `htdocs/accountancy/customer/{list,card}.php`, `htdocs/accountancy/supplier/{list,card}.php`
- `htdocs/accountancy/closure/index.php`
- `htdocs/accountancy/bookkeeping/{list,listbyaccount,balance,export}.php`
- `htdocs/accountancy/class/accountancyexport.class.php`
- `htdocs/accountancy/class/api_accountancy.class.php` (existing)
- `htdocs/accountancy/class/api_accountingsetup.class.php` (existing, extended in Phases 6 and 7)
- `htdocs/accountancy/admin/account.php` (reference only — Phase 7's `activate()` mirrors its
  logic but does not modify this file)
- `htdocs/accountancy/class/api_accountingimport.class.php` (new in Phase 11)
- `htdocs/imports/class/import.class.php`, `htdocs/core/modules/import/import_csv.modules.php`,
  `htdocs/core/modules/modAccounting.class.php` (`Chartofaccounts` profile) — existing, reused
  as-is by Phase 11's headless driver
- `htdocs/imports/import.php` (reference only — Phase 11's driver sequence mirrors its step 5/6
  action blocks but does not modify this file)

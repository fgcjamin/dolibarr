# Accountancy Module REST API — Backlog (Phases 2-5)

Phase 1 (Setup CRUD: chart of accounts, journals, fiscal years, chart-of-accounts models,
default accounts, VAT/tax accounting codes) has been implemented — see
`htdocs/accountancy/class/api_accountingaccounts.class.php`,
`htdocs/accountancy/class/api_accountingjournals.class.php`,
`htdocs/accountancy/class/api_accountingsetup.class.php`.

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

This backlog covers the remaining phases needed for the accountancy module's REST API to
fully drive the module end to end (recurring operations: binding, ledger transfer, closure,
reporting). Each phase is independently mergeable. Phase 3 (ledger transfer) should be done
last given its risk profile.

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

## Phase 2 — Binding invoice lines (steps A & B)

**Refactor, not pure addition.** Bind/unbind ("ventilation") is today raw inline SQL
duplicated in 4 places, all writing `fk_code_ventilation` (verified: `llx_facturedet` for
customer lines, `llx_facture_fourn_det` for supplier lines), gated by `accounting->bind->write`:
- `htdocs/accountancy/customer/list.php` (`massaction=='ventil'`, mass bind)
- `htdocs/accountancy/customer/card.php` (`action=='ventil'`, single-line bind)
- `htdocs/accountancy/supplier/list.php` / `htdocs/accountancy/supplier/card.php` (vendor equivalents)

**2.1** Add to `AccountingAccount` (`htdocs/accountancy/class/accountingaccount.class.php`):
```php
public function bindInvoiceLine($lineid, $accountid, $type = 'customer', User $user = null, $notrigger = 0)
public function unbindInvoiceLine($lineid, $type = 'customer', User $user = null, $notrigger = 0)
```
`$type` selects the target table/column; confirm the exact "unbind" sentinel value (0 vs NULL)
against the current inline SQL before finalizing. Body is the current `UPDATE` logic,
parameterized by type, returning the standard Dolibarr int convention (`>0` success, `<0`
error, populating `$this->error`/`errors`).

**2.2** Refactor the 4 UI call sites to call the new methods, preserving surrounding
`setEventMessages()`/error handling exactly.

**2.3** New endpoints in `AccountingBind`:

| Endpoint | Purpose | Backs onto |
|---|---|---|
| `GET customerlines/unbound`, `GET supplierlines/unbound` (filters: date range, socid, sqlfilters) | List not-yet-bound lines | mirrors `WHERE f.fk_statut > 0 AND l.fk_code_ventilation <= 0` query pattern in `customer/list.php`/`supplier/list.php` |
| `GET customerlines/{lineid}/suggestaccount`, `GET supplierlines/{lineid}/suggestaccount` | Suggest an account for a line | `AccountingAccount::getAccountingCodeToBind()` (already exists, pure wrap) |
| `PUT customerlines/{lineid}/bind`, `PUT supplierlines/{lineid}/bind` `{accountid}` | Single-line bind | `bindInvoiceLine()` |
| `POST customerlines/bind`, `POST supplierlines/bind` `{lineids: [], accountid}` | Mass bind | loop over `bindInvoiceLine()`, same partial-failure/aggregate semantics as the UI mass action |
| `PUT customerlines/{lineid}/unbind`, `PUT supplierlines/{lineid}/unbind` | Unbind | `unbindInvoiceLine()` |

Permission: `accounting->bind->write` throughout, matching current UI gating. Risk: medium
(real refactor of production logic, but a single-column UPDATE with simple semantics).

**Verification**: manual regression — bind/unbind a known line via the UI, note
`fk_code_ventilation`, repeat via the new API on the same line, confirm identical resulting
value. Add `test/phpunit/AccountingBindTest.php` (follow `AccountingAccountTest.php`'s
bootstrap pattern) covering `bindInvoiceLine()`/`unbindInvoiceLine()`.

## Phase 3 — Ledger transfer / "Record transactions in accounting" (step C)

**Highest-risk phase — refactor, not reimplementation.**

**Known bug to account for**: `BookKeeping::create()` (`bookkeeping.class.php` ~line 506-508)
sets `$result = 0` on a *successful* insert instead of returning the new row's id — confirmed
live against MariaDB while verifying Phase 4. `$this->id` is set correctly, only the return
value is wrong. Not fixed as part of Phase 4 (out of scope, and this method is squarely Phase
3's territory — a fix here has to go through the same behavior-preserving-refactor discipline
as the rest of this phase). Any Phase 3 code that currently branches on `create()`'s return
value expecting a positive id (rather than checking `< 0` for error / reading `->id` after)
should be corrected as part of the refactor, and the `BookKeepingTest::testBookKeepingCreate()`
assertion (`assertLessThan($result, 0)`, i.e. expects `$result > 0`) is likely silently
succeeding today only because the two args are backwards from what the name suggests. Worth a
one-line fix (`$result = 0;` → `$result = $id;` or `$result = 1;`) alongside the phase 3 work,
with its own before/after regression check like everything else in this phase.

`BookKeeping` (`htdocs/accountancy/class/bookkeeping.class.php`) already has full
CRUD/list/balance (`create`, `createFromValues`, `createStd`, `fetch*`, `update*`, `delete*`,
`export_bookkeeping`, `transformTransaction`, `canModifyBookkeeping`, `validBookkeepingDate`,
`assignAccountMass`) — straightforward wrap. `AccountingJournal::writeIntoBookkeeping()`
(line 1394) is already the reusable transfer method for the "various operations" journal only
(used by `htdocs/accountancy/journal/variousjournal.php:135`).

The other 4 journal types duplicate this kind of logic **inline** instead of factoring it:

| Journal | Page | Inline block (`action=='writebookkeeping'`) |
|---|---|---|
| Sales | `htdocs/accountancy/journal/sellsjournal.php` | ~line 497-921 (~420 lines) |
| Purchases | `htdocs/accountancy/journal/purchasesjournal.php` | ~line 445-827 (~380 lines) |
| Bank / Treasury | `htdocs/accountancy/journal/bankjournal.php` (~716-1084) and `htdocs/accountancy/journal/treasuryjournal.php` (~1135-1355) | near-duplicate pair |
| Expense reports | `htdocs/accountancy/journal/expensereportsjournal.php` | ~line 276-538 (~260 lines) |

**3.1** Add to `AccountingJournal`, named consistently with the existing method:
```php
public function writeIntoBookkeepingForSells(User $user, $date_start, $date_end, $max_nb_errors = 10)
public function writeIntoBookkeepingForPurchases(User $user, $date_start, $date_end, $max_nb_errors = 10)
public function writeIntoBookkeepingForBank(User $user, $date_start, $date_end, $max_nb_errors = 10)          // shared by bank + treasury pages
public function writeIntoBookkeepingForExpenseReports(User $user, $date_start, $date_end, $max_nb_errors = 10)
```
Each is a **behavior-preserving, near-verbatim extraction** of the corresponding page's block
(page-local `$db`/`$langs`/`$conf`/`$hookmanager` become instance/global refs the way
`writeIntoBookkeeping()` already does — `global $conf, $langs, $hookmanager;` at line 1408).
Preserve the existing hook pattern (`initHooks(array('accountingjournaldao'))` /
`executeHooks('writeBookkeeping', ...)`) so third-party hooks keep working. Where a page's
preceding `getData()`/`getAssetData()` call feeds the block, pull it in too (or accept
`$journal_data` as a param, as `writeIntoBookkeeping()` does) — decide per journal based on
whether the data-gathering step has UI-only concerns (e.g. pagination) that shouldn't leak
into the reusable method.

**3.2** Refactor the 5 UI pages to call the new methods, using the already-refactored
`variousjournal.php` (calling `writeIntoBookkeeping()`) as the structural template. **No
accounting numbers, piece numbers, or line counts may change** — this is the phase's core
acceptance criterion. Do a pure cut-and-paste extraction; any subsequent cleanup is a separate,
later change under its own review.

**3.3** New endpoints, extending `AccountingJournals`:

| Endpoint | Behavior | Permission |
|---|---|---|
| `POST journals/{id}/transfer` `{date_start, date_end}` | Dispatches on the journal's nature (`getLibType()`) to the matching `writeIntoBookkeepingFor*()` / `writeIntoBookkeeping()` | `accounting->bind->write` (matches all 5 pages today) |
| `GET journals/{id}/pendingdata?date_start&date_end` | Preview via `getData()`/`getAssetData()` without writing | `accounting->bind->write` or `->mouvements->lire` |

New ledger endpoints on `Accountancy` (`api_accountancy.class.php`), wrapping already-correct
`BookKeeping` methods: `GET ledger` (`fetchAll`), `GET ledger/{id}`, `PUT ledger/{id}`
(`update`), `DELETE ledger/{id}`, `GET ledger/balance` (`fetchAllBalance`) — permissions
`accounting->mouvements->{lire,creer,supprimer,supprimer_tous}` respectively. Bundle lettering
here too, since it operates on rows this phase produces
(`htdocs/accountancy/class/lettering.class.php`, `Lettering extends BookKeeping`): `POST
ledger/lettering` (`updateLettering`), `DELETE ledger/lettering` (`deleteLettering`), `GET
thirdparties/{id}/lettering` (`letteringThirdparty`) — same `mouvements` permission family.

**Explicit risk flag**: this is a behavior-preserving refactor of live production financial
logic, not new logic. Any output deviation (account numbers, piece numbering, rounding,
dropped edge cases like the sells-journal replaced-invoice/retained-warranty branches near
`sellsjournal.php:530+`) is a regression, not an improvement.

**Verification** (critical, given zero current test coverage of `BookKeeping`/
`AccountingJournal` transfer/`Lettering` — only `test/phpunit/AccountingAccountTest.php`
exists for this module today): before refactoring each journal page, capture a golden baseline
— run a transfer via the current UI on a seeded test dataset, snapshot resulting
`llx_accounting_bookkeeping` rows (piece_num, accounts, debit/credit, count). After
refactoring, re-run the same transfer via the UI (now calling the new method) on freshly
reseeded identical data and diff — must match exactly. Then repeat once more calling only the
new API endpoint and diff again. Add `test/phpunit/AccountingJournalTransferTest.php` covering
each `writeIntoBookkeepingFor*()` against fixture invoices/payments/expense reports, explicitly
including the edge-case branches visible in the current inline code (e.g. sells-journal
replaced-invoice/retained-warranty handling) so a careless extraction can't silently drop them.

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

## Critical files (Phases 2-5)

- `htdocs/accountancy/class/bookkeeping.class.php`
- `htdocs/accountancy/class/lettering.class.php`
- `htdocs/accountancy/class/accountingjournal.class.php`
- `htdocs/accountancy/journal/{sellsjournal,purchasesjournal,bankjournal,treasuryjournal,expensereportsjournal,variousjournal}.php`
- `htdocs/accountancy/customer/{list,card}.php`, `htdocs/accountancy/supplier/{list,card}.php`
- `htdocs/accountancy/closure/index.php`
- `htdocs/accountancy/bookkeeping/{list,listbyaccount,balance,export}.php`
- `htdocs/accountancy/class/accountancyexport.class.php`
- `htdocs/accountancy/class/api_accountancy.class.php` (existing)

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
would be unsafe. Test coverage in `test/phpunit/AccountingLedgerApiTest.php`; the write-side
journal-transfer refactor (Phase 3b below) remains open.

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

**Highest-risk phase — refactor, not reimplementation.** Split into 3a (done, additive, low
risk) and 3b (remaining, refactor, high risk) — see below.

### Phase 3a — Ledger CRUD + lettering — Implemented

`BookKeeping::fetch()/update()/delete()` and `Lettering::updateLettering()/deleteLettering()/
letteringThirdparty()` were already correct, so this half was a straightforward additive wrap —
see the status paragraph near the top of this file for exactly what shipped. Nothing here
changed `bookkeeping.class.php`, `lettering.class.php`, `accountingjournal.class.php`, or any
journal UI page.

### Phase 3b — Journal transfer refactor — Remaining, do this next

**This is the genuinely risky part of Phase 3.** `BookKeeping::create()`,
`AccountingJournal::writeIntoBookkeeping()`, and the 5 journal UI pages are untouched by 3a.

**Known bug to account for**: `BookKeeping::create()` (`bookkeeping.class.php`, `create()`
method) sets `$result = 0` on a *successful* insert instead of returning the new row's id —
confirmed live against MariaDB while verifying Phase 4, and re-confirmed while scoping 3b: the
exact lines are `$id = $this->db->last_insert_id(...); if ($id > 0) { $this->id = $id; $result
= 0; }`, and further down, if triggers run (`$notrigger` not set), `$result =
$this->call_trigger('BOOKKEEPING_CREATE', $user);` **overwrites `$result` again** before the
final `return $error ? -1 * $error : $result;`. So this is not a one-line fix — naively changing
`$result = 0` to `$result = $id` would still get clobbered by the trigger-return-value
reassignment whenever triggers are enabled (the common case). `$this->id` is set correctly in
all cases; that's what every existing caller already relies on (`createFromValues()`,
`writeIntoBookkeeping()`, `BookKeepingTest`, `AccountingClosureApiTest`). A real fix needs to
either capture `$id` before the trigger call and restore it after (if `call_trigger()` returned
`>= 0`), or stop overloading `$result` for two different meanings. Do this fix inside 3b's own
behavior-preserving-refactor discipline (golden-baseline diff, not a drive-by patch), since
`writeIntoBookkeeping()` is `create()`'s only real production caller today. Note
`BookKeepingTest::testBookKeepingCreate()`'s current assertion (`assertLessThan($result, 0)`,
i.e. expects `$result > 0`) is silently passing today only because the two args are backwards
from what the name suggests — worth fixing that assertion alongside the class fix, not before.

`BookKeeping` (`htdocs/accountancy/class/bookkeeping.class.php`) already has full
CRUD/list/balance (`create`, `createFromValues`, `createStd`, `fetch*`, `update*`, `delete*`,
`export_bookkeeping`, `transformTransaction`, `canModifyBookkeeping`, `validBookkeepingDate`,
`assignAccountMass`) — straightforward wrap. `AccountingJournal::writeIntoBookkeeping()`
(line 1454) is already the reusable transfer method for the "various operations" journal only
(used by `htdocs/accountancy/journal/variousjournal.php`, action block at lines 131-150). Its
structure (confirmed by reading it in full): fires an `accountingjournaldao`/`writeBookkeeping`
hook first (if the hook fully replaces native logic, native processing is skipped entirely);
otherwise loops `$journal_data` per document, builds a `BookKeeping` object per line from a
normalized `$element['blocks']` array, calls `create()`, aggregates errors
(`alreadyjournalized`/`other`/`amountsnotbalanced`), commits/rolls back per document, and stops
early once `$max_nb_errors` (default 10) is hit. Returns `$error ? -$error : 1` — the convention
any new `POST journals/{id}/transfer` endpoint should mirror. The page-level glue in
`variousjournal.php` is thin (`getData($user, 'bookkeeping', ...)` builds `$journal_data`,
`writeIntoBookkeeping($user, $journal_data)` writes it, `setEventMessages()` on the result) —
this is the template to match for the other 5 pages' endpoints and extracted methods.

`AccountingJournal::getLibType()` is a **label-only** dispatch (`$nature` → translated string
via `LibType()`), not a functional dispatch — there is no existing `$nature`-keyed routing table
from a journal to its transfer page/method. `POST journals/{id}/transfer` will need to build one
from scratch: nature 1→various, 2→sells, 3→purchases, 4→bank/treasury (two pages share nature
4 — `bankjournal.php`/`treasuryjournal.php` — decide the dispatch key between them, e.g. by
journal code, not just nature), 5→expense reports. Note nature 8 (inventory) has no `LibType()`
label at all today (missing `elseif ($nature == 8)` branch) — a separate minor pre-existing gap,
worth a one-line fix alongside 3b but not blocking it.

The other 4 journal types duplicate this kind of logic **inline** instead of factoring it. Exact
block boundaries (all guarded by `action == 'writebookkeeping'`, confirmed via grep):

| Journal | Page | Inline block lines | Approx. size |
|---|---|---|---|
| Sales | `sellsjournal.php` | 497-921 | ~425 lines |
| Purchases | `purchasesjournal.php` | 445-830 | ~385 lines |
| Bank | `bankjournal.php` | 716-1208 | ~490 lines (largest — highest risk) |
| Treasury | `treasuryjournal.php` | 1135-1359 | ~225 lines |
| Expense reports | `expensereportsjournal.php` | 276-542 | ~265 lines |

`treasuryjournal.php`'s permission check is currently **commented out**
(`if ($action == 'writebookkeeping' /* && $user->hasRight(...) */)` with a "test on permission
already done" note) — a minor pre-existing inconsistency vs. the other 4 pages' inline
`$user->hasRight('accounting', 'bind', 'write')` check. Worth normalizing during the 3b
extraction (all 5 should end up with the same explicit check the shared method or its callers
enforce), not silently fixed as an unrelated drive-by before that.

`sellsjournal.php` (read in full while scoping this) is representative of the pattern: loops
`foreach ($tabfac as $key => $val)` (one iteration per invoice, not per line), creates up to 5
distinct kinds of `BookKeeping` rows per invoice (retained-warranty, thirdparty/customer,
product/service revenue, VAT/localtax, revenue-stamp), wraps each invoice in
`$db->begin()/commit()/rollback()` with a debit/credit balance check before commit, and aborts
after 10 accumulated errors — all logic `writeIntoBookkeeping()` already has, just operating on
raw page-local arrays (`$tabfac`, `$tabttc`, `$tabht`, `$tabtva`, ...) instead of the normalized
`$journal_data` structure `getData()` produces for `variousjournal.php`. Two gaps to carry
through the extraction, not silently drop:
- A "replaced invoice" skip branch (`sellsjournal.php:534-548`) with **no equivalent** in
  `writeIntoBookkeeping()` or its `getData()` — an invoice whose `close_code ==
  Facture::CLOSECODE_REPLACED` and isn't yet in the bookkeeping is skipped entirely before any
  rows are built for it. This needs to live in a `getData()`-equivalent data-prep step (e.g. an
  `element['skip']` flag), not inside the write method itself.
- `writeIntoBookkeeping()` fires an `accountingjournaldao`/`writeBookkeeping` hook
  (`accountingjournal.class.php:1461-1468`) before doing anything; the inline `sellsjournal.php`
  write block (lines 497-921) has **no such hook call** today. Extracting the logic into a
  shared method either introduces this hook point for sells/purchases/bank/treasury/expense
  reports (new behavior for third-party hook consumers — flag this explicitly, don't do it
  silently) or the extraction needs its own justification for why it's safe to add.

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

Ledger CRUD + lettering endpoints on `Accountancy` are already implemented — see Phase 3a above.

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

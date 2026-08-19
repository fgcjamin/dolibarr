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
the corrected assertion in `test/phpunit/BookKeepingTest.php::testBookKeepingCreate()`. Purchases,
bank, and treasury remain open — see the rewritten Phase 3b section below.

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
`test/phpunit/AccountingJournalSellsTransferTest.php`. Purchases, bank, and treasury remain
open — see the rewritten Phase 3b section below.

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

### Phase 3b, remaining slice — purchases, bank, treasury

**This is still the genuinely risky part of Phase 3.** The 3 largest, most special-case-laden
journal pages are untouched. Sells and expense reports are both done now (see status paragraphs
above) and between them fully validate the extraction pattern below, including a real bug the
pattern's own verification caught (see the phpstan note at the end of this section) — treat that
as required reading before starting the next journal, not optional context.

**Correction to the original assumption, now proven twice**: the backlog originally assumed each
journal's write logic could be extracted with a simple `($user, $date_start, $date_end)`
signature by redoing a generic date-range query inside the class, the way
`variousjournal.php`'s already-migrated `getData()`/`writeIntoBookkeeping()` works. This only
actually holds for `variousjournal.php` (nature 1). Every other journal has its own bespoke,
often hook-coupled data-collection SQL and per-journal keying convention (flat
`$tabht`/`$tabtva`/`$tabttc`-style arrays, not the generic `blocks` structure
`writeIntoBookkeeping()` expects). So each of purchases/bank/treasury needs its own
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
`pendingData()` in `htdocs/accountancy/class/api_accountingjournals.class.php`) currently
supports natures `1` (various) and `2` (sells); `3` (purchases) and `5` (expense reports) are
the next straightforward additions — but **nature `4` (bank/treasury) needs special handling,
not a simple `elseif`**, see below.

| Journal | Page | Inline block lines | Approx. size | Status |
|---|---|---|---|---|
| Sales | `sellsjournal.php` | 496-921 (pre-refactor) | ~425 lines | **Done** |
| Expense reports | `expensereportsjournal.php` | 276-542 (pre-refactor) | ~265 lines | **Done** |
| Purchases | `purchasesjournal.php` | 444-827 | ~385 lines | Remaining |
| Bank | `bankjournal.php` | 716-1084 | ~370 lines | Remaining |
| Treasury | `treasuryjournal.php` | 1134-1355 | ~220 lines | Remaining |

#### Purchases (nature 3)

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

**Bank** (`bankjournal.php`): data-collection (147-713, zero hooks in the whole file) builds
`$tabpay`/`$tabaccount`/`$tabbq`/`$tabtp`/`$tabcompany`/`$tabuser`/`$tabtype`/`$tabmoreinfo`, one
`db->begin()`/`commit()` per bank line. Write block (716-1084, permission check present and
active) has only 3 physical `create()` call sites but an **11-way payment-type branch**
(`payment`/`payment_supplier`/`payment_expensereport`/`payment_salary`/`sc`/`payment_vat`/
`payment_donation`/`member`/`payment_loan`/`payment_various`/`banktransfert`, plus an `unknown`
fallback to a suspense account) resolving `subledger_account`/`numero_compte` per type. **Depends
on a page-local helper function `getSourceDocRef()` (defined at the bottom of the same file,
lines 1607-1715) that must move into the class too** (e.g. as a private method) — it isn't safe
to leave as a bare page-scoped function, since the extracted class method would then be
unusable outside that page. Max-errors hardcoded `5` (not `10` like the other journals) —
preserve that as the parameter default, don't silently harmonize to `10`.

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
a required step, not an optional extra, for purchases/bank/treasury too.

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

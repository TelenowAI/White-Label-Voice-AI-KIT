# Pricing: the rules engine every stack runs

What a customer pays for a call or a number fee: Telenow's charge for it, run through the operator's price
list (SELF_HOSTED_KIT_PLAN.md §4.6 b–c). This folder is the engine's specification. Every stack implements it and
must reproduce every fixture exactly, to the last decimal and in the same line order. That way a customer is
charged the same on Laravel and on Node.

| Here | What |
|---|---|
| `price-list.schema.json` | The format of a price list. |
| `price-lists/*.json` | Price lists the fixtures use; also examples an operator can start from. |
| `fixtures/*.json` | Cases: an item, a plan, a rate → the exact lines and total, or the exact refusal. Format: `fixture.schema.json`. Written by `oracle.py`. |
| `oracle.py` | An implementation of this spec apart from every engine (Python, standard library). It computes each fixture's expected answer: add a case there, run `python3 pricing/oracle.py`, and never edit an `expect` by hand. |
| `engine.ts` | The reference engine (TypeScript). `tests/pricing.test.ts` holds it to the fixtures. |

The Laravel engine is `laravel/app/Kit/Pricing`; its test reads these same files.

## What is priced

- **A call:** Telenow's settled charge (`GET /api/orgs/{orgId}/calls/{id}/cost`, `CallCharge`) and the call
  (`GET /api/v1/calls/{id}`).
- **A fee:** a charge in the operator's Telenow ledger (`GET /api/orgs/{orgId}/billing/ledger`, `LedgerEntry`),
  such as number rent (`refKind: rental`) or setup (`refKind: number_setup`).

Each becomes an *item*:

```json
{ "kind": "call", "attributes": { "callType": "agent_voice", "direction": "outbound", "channel": "telephony" },
  "durationSec": 95, "components": { "llm": "0.0041250000", "telephony": "0.0266000000" }, "totalUsd": "0.0307250000" }
{ "kind": "fee", "attributes": { "refKind": "rental" }, "costUsd": "1.1500000000" }
```

From Telenow's answers:
- A call's `attributes` are `callType` from the charge, and `direction` and `channel` from the call, with Telenow's
  names and values.
  - `durationSec` is the call's `duration_sec` (0 when null).
  - `totalUsd` is `totalChargeUsd`.
  - `components` are every other `<name>Usd` field of the charge, keyed by `<name>`. The engine has no list of
    components: a component Telenow adds is priced by the rules like any other.
- A charge that is `null` (the call is not rated yet) is refused: `not_rated`.
- A fee's `attributes` are its `refKind`, and `costUsd` is its `amountUsd` negated (a charge is negative).
  - An entry whose `entryType` is not `charge` is refused: `not_a_charge`.

Money is a decimal string, `-?digits[.digits]`. It is never a float. The engine computes exactly (fractions) and
rounds only where step 6 says.

## A price list

```json
{ "id": "starter", "extends": "base", "currency": "INR", "decimals": 2, "rounding": "half-up",
  "billingIncrementSeconds": 60,
  "rules": [
    { "id": "browser", "match": { "item": "call", "channel": "web_call" }, "price": { "perMinute": "1.20" } },
    { "id": "lines", "match": { "item": "component", "component": "telephony" }, "price": { "perMinute": "0.90" } },
    { "id": "ai", "match": { "item": "component" }, "price": { "markupPercent": "45" } },
    { "id": "rent", "match": { "item": "fee", "refKind": "rental" }, "price": { "fixed": "120.00" } }
  ] }
```

- **`match.item`** says what a rule prices:
  - `call`: a whole call, all in;
  - `component`: one component of a call;
  - `fee`: a ledger fee.
- **Every other key of `match`** names an attribute: the item's own attributes, plus `component` (the component's
  name) for a component rule. A rule matches when each named attribute equals the value given, or is one of the
  values when a list is given. An attribute the item does not have never matches.
- **`price`** is one of the following. A list that pairs an item with a price it cannot take is invalid.
  - `perMinute` (call, component): this much per billed minute, in the list's currency.
  - `markupPercent` (call, component, fee): Telenow's charge × (100 + p) / 100, converted at the rate. It may be
    negative, down to −100.
  - `fixed` (fee): this much, in the list's currency.
- **`extends`**: a fallback list (another plan's). Its rules are tried after this list's own. Its `currency` must
  be this list's (`currency_mismatch`). Every other setting comes from the plan's own list.
- **`decimals`**, **`rounding`** (`half-up`, `half-even`, `up`, `down`) and **`billingIncrementSeconds`**: see the
  steps.

## The steps

Inputs: the price lists by id, the plan's list id, `usdRate` (the list's currency per 1 USD, as a decimal
string, recorded with the charge), and an item.

1. **The rules.** Take the plan's list, then the list it extends, and so on.
   - An id with no list: `unknown_price_list`.
   - A list met twice: `price_list_cycle`.
   - A currency different from the plan's: `currency_mismatch`.

   The rules are every list's rules in that order. The first rule that matches wins.
2. **A call, all in.** If a `call` rule matches the call, it prices the whole call as one line (subject `call`,
   `costUsd` the total), and step 3 is skipped.
3. **A call by component.** Otherwise each component with a non-zero charge is one line (subject
   `component:<name>`, `costUsd` its charge).
   - The part of the total the components do not cover (`totalUsd` minus their sum) is a component of its own,
     `unlisted`: Telenow's total can hold a charge its answer does not list. If that part is negative:
     `components_exceed_total`.
   - A component no `component` rule matches: `unpriced_component`. A charge is never given away for free.
   - Lines are in byte order of their component name.
4. **A fee.** The first `fee` rule that matches gives one line (subject `fee:<refKind>`, with nothing after the
   colon when `refKind` is null). None: `unpriced_fee`.
5. **Each line's amount, exactly:**
   - `perMinute`: rate × billed seconds / 60, where billed seconds = `durationSec` rounded up to a whole number of
     `billingIncrementSeconds` (60: each started minute; 1: by the second).
   - `markupPercent`: `costUsd` × (100 + p) / 100 × `usdRate`.
   - `fixed`: the amount.
6. **Rounding.** Each line's amount is rounded to `decimals` places by `rounding`:
   - `half-up`: to the nearest, ties away from zero;
   - `half-even`: to the nearest, ties to the even digit;
   - `up`: away from zero;
   - `down`: towards zero.

   The total is the sum of the rounded lines. So the lines always add up to the total, and an invoice can list
   them.
7. **The answer:** `{ currency, usdRate, lines, total }`.
   - Each line is `{ subject, rule, method, costUsd, billedSeconds, amount }`:
     - `rule` is `<list id>/<rule id>`;
     - `method` is the price used;
     - `billedSeconds` is the per-minute basis, or null for other methods.
   - `amount` and `total` have exactly `decimals` places, with no exponent and no `-0`.
   - `costUsd` and `usdRate` are written in their shortest form: no trailing zeros after the point, no point
     when nothing follows it, and `0` for zero.

A refusal is `{ "error": "<code>", "subject": "<what>" }`. Checks run in this order, and the first refusal is
the answer:
1. The item: `not_rated` (subject `call`) or `not_a_charge` (subject `fee`).
2. The lists: `unknown_price_list`, `price_list_cycle` or `currency_mismatch`, with the list's id as the subject.
3. The lines, in the order of steps 2–4: `components_exceed_total` (subject `call`), `unpriced_component`
   (subject `component:<name>`) or `unpriced_fee` (subject `fee:<refKind>`).

## Where the inputs came from

The calls and fees in `../telenow-mock/cassettes` (`getCallCost.*`, `getCallV1.*`, `listLedger.*`) are Telenow's
own answers. They were recorded from main `2446ecb`'s binary on a throwaway database, whose charge rows and ledger
fees were seeded at Telenow's NUMERIC(20,10). The amounts
are made up; their shape, names and decimal strings are Telenow's. Fixtures with an `inline` item pin a rule that
no recording reaches (a tie in rounding, an unlisted remainder), and those with a `ledgerEntry` one no recorded
ledger holds (a payment); both say so.

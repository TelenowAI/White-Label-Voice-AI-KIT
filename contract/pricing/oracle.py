"""The pricing oracle: writes fixtures/*.json, each case's EXPECTED answer computed here by an implementation of
README.md apart from every stack's engine (Python fractions, standard library only), so no engine is checked against
its own output. The cases are CASES below: to add one, add it here and run `python3 pricing/oracle.py` from
contract/ — never edit a fixture's `expect` by hand. Every engine (engine.ts, laravel/app/Kit/Pricing) must then
answer the new case exactly.

Not here: the engines, the test harnesses (tests/pricing.test.ts, laravel PricingFixturesTest)."""
import json
import os
import re
import sys
from fractions import Fraction as F

ROOT = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PRICING = os.path.join(ROOT, "pricing")
CASSETTES = os.path.join(ROOT, "telenow-mock", "cassettes")
DEC = re.compile(r"^-?(0|[1-9][0-9]*)(\.[0-9]+)?$")


class Refused(Exception):
    def __init__(self, code, subject):
        self.code, self.subject = code, subject


def dec(s):
    assert DEC.match(s), s
    return F(s)


def short(q):
    """Shortest decimal string of a finite decimal fraction."""
    d = q.denominator
    k = 0
    while (10 ** k) % d:
        k += 1
        assert k < 60, q
    n = q.numerator * (10 ** k // d)
    sign = "-" if n < 0 else ""
    digits = str(abs(n)).rjust(k + 1, "0")
    whole, frac = (digits[:-k], digits[-k:]) if k else (digits, "")
    frac = frac.rstrip("0")
    return sign + whole + ("." + frac if frac else "") if n else "0"


def rnd(q, places, mode):
    x = q * 10 ** places
    a = abs(x)
    fl = a.numerator // a.denominator
    r = a - fl
    if r == 0:
        m = fl
    elif mode == "down":
        m = fl
    elif mode == "up":
        m = fl + 1
    elif mode == "half-up":
        m = fl + 1 if r >= F(1, 2) else fl
    elif mode == "half-even":
        m = fl + 1 if r > F(1, 2) or (r == F(1, 2) and fl % 2 == 1) else fl
    else:
        raise ValueError(mode)
    return -m if x < 0 else m


def fmt(m, places):
    digits = str(abs(m)).rjust(places + 1, "0")
    s = digits[:-places] + "." + digits[-places:] if places else digits
    return ("-" if m < 0 else "") + s


def load_lists(extra):
    lists = {}
    for f in sorted(os.listdir(os.path.join(PRICING, "price-lists"))):
        l = json.load(open(os.path.join(PRICING, "price-lists", f)))
        lists[l["id"]] = l
    for l in extra:
        assert l["id"] not in lists
        lists[l["id"]] = l
    return lists


def cassette(name):
    return json.load(open(os.path.join(CASSETTES, name + ".json")))["response"]["body"]


def item_of(spec):
    if "call" in spec:
        cost = cassette(spec["call"]["cost"])["data"]
        call = cassette(spec["call"]["call"])
        if cost is None:
            raise Refused("not_rated", "call")
        comps = {k[:-3]: v for k, v in cost.items() if k.endswith("Usd") and k != "totalChargeUsd"}
        return {"kind": "call", "attributes": {"callType": cost["callType"], "direction": call["direction"], "channel": call["channel"]},
                "durationSec": call["duration_sec"] or 0, "components": comps, "totalUsd": cost["totalChargeUsd"]}
    if "fee" in spec:
        entries = [e for e in cassette(spec["fee"]["ledger"])["data"]["entries"] if e["refKind"] == spec["fee"]["refKind"]]
        assert len(entries) == 1
        return fee_of(entries[0])
    if "ledgerEntry" in spec:
        return fee_of(spec["ledgerEntry"])
    return spec["inline"]


def fee_of(e):
    if e["entryType"] != "charge":
        raise Refused("not_a_charge", "fee")
    return {"kind": "fee", "attributes": {"refKind": e["refKind"]}, "costUsd": short(-dec(e["amountUsd"]))}


def chain(lists, plan):
    out, seen, cur = [], set(), plan
    while cur is not None:
        if cur not in lists:
            raise Refused("unknown_price_list", cur)
        if cur in seen:
            raise Refused("price_list_cycle", cur)
        seen.add(cur)
        if out and lists[cur]["currency"] != out[0]["currency"]:
            raise Refused("currency_mismatch", cur)
        out.append(lists[cur])
        cur = lists[cur].get("extends")
    return out


def matches(rule, kind, attrs):
    m = rule["match"]
    if m["item"] != kind:
        return False
    for k, v in m.items():
        if k == "item":
            continue
        have = attrs.get(k)
        if have is None:
            return False
        if (have not in v) if isinstance(v, list) else (have != v):
            return False
    return True


def price(lists, plan, usd_rate, spec):
    try:
        item = item_of(spec)
        ch = chain(lists, plan)
    except Refused as e:
        return {"error": e.code, "subject": e.subject}
    head = ch[0]
    rules = [(l["id"], r) for l in ch for r in l["rules"]]
    rate = dec(usd_rate)
    inc = head["billingIncrementSeconds"]

    def line(subject, lid, rule, cost):
        p = rule["price"]
        billed = None
        if "perMinute" in p:
            billed = -(-int(item.get("durationSec", 0)) // inc) * inc
            amount, method = dec(p["perMinute"]) * billed / 60, "perMinute"
        elif "markupPercent" in p:
            amount, method = cost * (100 + dec(p["markupPercent"])) / 100 * rate, "markupPercent"
        else:
            amount, method = dec(p["fixed"]), "fixed"
        return {"subject": subject, "rule": f"{lid}/{rule['id']}", "method": method, "costUsd": short(cost),
                "billedSeconds": billed, "amount": rnd(amount, head["decimals"], head["rounding"])}

    try:
        lines = []
        attrs = item["attributes"]
        if item["kind"] == "call":
            total = dec(item["totalUsd"])
            hit = next(((lid, r) for lid, r in rules if matches(r, "call", attrs)), None)
            if hit:
                lines.append(line("call", hit[0], hit[1], total))
            else:
                comps = {k: dec(v) for k, v in item["components"].items()}
                rest = total - sum(comps.values(), F(0))
                if rest < 0:
                    raise Refused("components_exceed_total", "call")
                if rest > 0:
                    comps["unlisted"] = rest
                for name in sorted(comps):
                    if comps[name] == 0:
                        continue
                    a2 = dict(attrs, component=name)
                    hit = next(((lid, r) for lid, r in rules if matches(r, "component", a2)), None)
                    if not hit:
                        raise Refused("unpriced_component", f"component:{name}")
                    lines.append(line(f"component:{name}", hit[0], hit[1], comps[name]))
        else:
            hit = next(((lid, r) for lid, r in rules if matches(r, "fee", attrs)), None)
            if not hit:
                raise Refused("unpriced_fee", f"fee:{attrs.get('refKind') or ''}")
            lines.append(line(f"fee:{attrs.get('refKind') or ''}", hit[0], hit[1], dec(item["costUsd"])))
    except Refused as e:
        return {"error": e.code, "subject": e.subject}
    total = sum(l["amount"] for l in lines)
    for l in lines:
        l["amount"] = fmt(l["amount"], head["decimals"])
    return {"currency": head["currency"], "usdRate": short(rate), "lines": lines, "total": fmt(total, head["decimals"])}


def L(id, rules, **over):
    base = {"id": id, "currency": "INR", "decimals": 2, "rounding": "half-up", "billingIncrementSeconds": 60, "rules": rules}
    base.update(over)
    return base


OUT_C = {"cost": "getCallCost.outbound-phone", "call": "getCallV1.outbound-phone"}
IN_C = {"cost": "getCallCost.inbound-phone", "call": "getCallV1.inbound-phone"}
WEB_C = {"cost": "getCallCost.browser-call", "call": "getCallV1.browser-call"}
RENT = {"fee": {"ledger": "listLedger.number-fees", "refKind": "rental"}}
SETUP = {"fee": {"ledger": "listLedger.number-fees", "refKind": "number_setup"}}
CALL_ATTRS = {"callType": "agent_voice", "direction": "outbound", "channel": "telephony"}
MARKUP_ALL = [{"id": "all", "match": {"item": "component"}, "price": {"markupPercent": "0"}},
              {"id": "fees", "match": {"item": "fee"}, "price": {"markupPercent": "0"}}]

CASES = [
    ("01-starter-outbound-phone", "A markup on every component, outbound phone call", "Each non-zero component is its own line, in name order, converted at the rate; zero components (embedding, simulation …) make no line.", "starter", "84.25", {"call": OUT_C}, []),
    ("02-starter-inbound-phone", "A markup on every component, inbound phone call", "A component the other calls do not have (postCallAnalysis) is priced by the same catch-all rule.", "starter", "84.25", {"call": IN_C}, []),
    ("03-starter-browser-call", "A markup on every component, browser call", "A call with no telephony component has no telephony line.", "starter", "84.25", {"call": WEB_C}, []),
    ("04-per-minute-outbound-phone", "All in per minute, outbound phone call", "A call rule prices the whole call; 95 s bills as 2 started minutes.", "per-minute", "84.25", {"call": OUT_C}, []),
    ("05-per-minute-inbound-phone", "All in per minute, inbound phone call", "The direction picks the rule: 312 s bills as 6 minutes at the inbound rate.", "per-minute", "84.25", {"call": IN_C}, []),
    ("06-per-minute-browser-call", "All in per minute, browser call", "A rule matches when the attribute is one of the listed values (channel web_call).", "per-minute", "84.25", {"call": WEB_C}, []),
    ("07-per-second-billing", "Per-minute price billed by the second", "With billingIncrementSeconds 1, 95 s is 95/60 of a minute, kept exact until the line is rounded.", "by-the-second", "84.25", {"call": OUT_C},
     [L("by-the-second", [{"id": "outbound-phone", "match": {"item": "call", "direction": "outbound"}, "price": {"perMinute": "3.50"}}], billingIncrementSeconds=1)]),
    ("08-mixed-outbound-phone", "Telephony per minute, AI marked up, platform fee passed through", "Component rules of different kinds on one call; a 0% markup passes Telenow's charge through, converted.", "mixed", "84.25", {"call": OUT_C}, []),
    ("09-mixed-inbound-unpriced", "A component no rule prices is refused", "postCallAnalysis has no rule in this list: the call is refused, never charged without it.", "mixed", "84.25", {"call": IN_C}, []),
    ("10-plan-falls-back", "A plan's rules first, then the list it extends", "pro prices llm and outbound telephony; every other component falls back to base.", "pro", "84.25", {"call": OUT_C}, []),
    ("11-plan-fallback-inbound", "A rule that does not match falls back too", "pro's telephony rule is for outbound calls only, so an inbound call's telephony falls back to base's markup.", "pro", "84.25", {"call": IN_C}, []),
    ("12-fee-rent-fixed", "Number rent at a fixed price", "A fixed price is the amount as written, whatever Telenow charged.", "per-minute", "84.25", RENT, []),
    ("13-fee-setup-free", "Number setup given free", "A fixed price of 0 is a line of 0.00, so the fee still shows on the customer's bill.", "per-minute", "84.25", SETUP, []),
    ("14-fee-rent-markup", "Number rent marked up", "A fee's cost is its ledger amount negated, marked up and converted.", "starter", "84.25", RENT, []),
    ("15-fee-unpriced", "A fee no rule prices is refused", "mixed has no fee rule.", "mixed", "84.25", RENT, []),
    ("16-call-not-rated", "A call Telenow has not rated yet", "Telenow answers null until the call is settled; there is nothing to price.", "starter", "84.25", {"call": {"cost": "getCallCost.not-rated", "call": "getCallV1.outbound-phone"}}, []),
    ("17-ledger-payment-not-a-fee", "A ledger entry that is not a charge", "A payment (entryType payment) is not a fee. Written for this case: no recorded ledger holds a payment.", "starter", "84.25",
     {"ledgerEntry": {"id": "00000000-0000-4000-8000-000000000017", "entryType": "payment", "amountUsd": "50.0000000000", "balanceAfterUsd": "90.0000000000", "refKind": "manual",
                      "refId": None, "fxCurrency": None, "fxRate": None, "fxAmount": None, "note": "Top-up", "createdBy": "system", "createdAt": "2026-10-29T17:00:00Z", "sourceOrgId": None}}, []),
    ("18-unlisted-part-of-the-total", "Telenow's total holds a charge its answer does not list", "The total is more than the listed components (a feature surcharge is in the total but not in the answer): the difference is priced as `unlisted`. Written for this case.", "starter", "84.25",
     {"inline": {"kind": "call", "attributes": CALL_ATTRS, "durationSec": 95, "components": {"llm": "0.0041250000", "telephony": "0.0266000000"}, "totalUsd": "0.0412250000"}}, []),
    ("19-unlisted-part-unpriced", "An unlisted part with no rule is refused", "A list that prices components by name only must also price `unlisted`. Written for this case.", "mixed", "84.25",
     {"inline": {"kind": "call", "attributes": CALL_ATTRS, "durationSec": 95, "components": {"llm": "0.0041250000", "telephony": "0.0266000000"}, "totalUsd": "0.0412250000"}}, []),
    ("20-components-exceed-total", "Components that add up to more than the total", "Telenow's numbers disagree: refused rather than guessed. Written for this case.", "starter", "84.25",
     {"inline": {"kind": "call", "attributes": CALL_ATTRS, "durationSec": 95, "components": {"llm": "0.0041250000", "telephony": "0.0266000000"}, "totalUsd": "0.0300000000"}}, []),
    ("21-rounding-half-up-ties", "Ties round away from zero", "0.125 → 0.13 and 0.135 → 0.14 with half-up. Written for this case.", "ties-half-up", "1",
     {"inline": {"kind": "call", "attributes": CALL_ATTRS, "durationSec": 60, "components": {"llm": "0.125", "stt": "0.135"}, "totalUsd": "0.26"}}, [L("ties-half-up", MARKUP_ALL)]),
    ("22-rounding-half-even-ties", "Ties round to the even digit", "0.125 → 0.12 and 0.135 → 0.14 with half-even; the total is the sum of the rounded lines. Written for this case.", "ties-half-even", "1",
     {"inline": {"kind": "call", "attributes": CALL_ATTRS, "durationSec": 60, "components": {"llm": "0.125", "stt": "0.135"}, "totalUsd": "0.26"}}, [L("ties-half-even", MARKUP_ALL, rounding="half-even")]),
    ("23-rounding-up", "Rounding up", "0.121 → 0.13 when rounding up. Written for this case.", "round-up", "1",
     {"inline": {"kind": "fee", "attributes": {"refKind": "rental"}, "costUsd": "0.121"}}, [L("round-up", MARKUP_ALL, rounding="up")]),
    ("24-rounding-down", "Rounding down", "0.129 → 0.12 when rounding down. Written for this case.", "round-down", "1",
     {"inline": {"kind": "fee", "attributes": {"refKind": "rental"}, "costUsd": "0.129"}}, [L("round-down", MARKUP_ALL, rounding="down")]),
    ("25-no-minor-units", "A currency billed in whole units", "decimals 0: each line is a whole number, with no point.", "whole-units", "152.4", {"call": OUT_C},
     [L("whole-units", [{"id": "all", "match": {"item": "component"}, "price": {"markupPercent": "40"}}], currency="JPY", decimals=0)]),
    ("26-price-list-cycle", "Lists that extend each other", "loop-a extends loop-b extends loop-a: refused, naming the list met twice.", "loop-a", "84.25", {"call": OUT_C},
     [L("loop-a", [], extends="loop-b"), L("loop-b", [], extends="loop-a")]),
    ("27-unknown-plan", "A plan with no price list", "Refused, naming the missing list.", "no-such-plan", "84.25", {"call": OUT_C}, []),
    ("28-unknown-fallback", "A fallback list that does not exist", "Refused, naming the missing list, even before a rule could match.", "orphan", "84.25", {"call": OUT_C},
     [L("orphan", [{"id": "all", "match": {"item": "component"}, "price": {"markupPercent": "40"}}], extends="gone")]),
    ("29-currency-mismatch", "A fallback in another currency", "A plan in USD cannot fall back to an INR list: refused, naming the fallback.", "usd-over-inr", "1", {"call": OUT_C},
     [L("usd-over-inr", [], extends="base", currency="USD", decimals=4)]),
    ("30-whole-call-markup-usd", "A markup on the whole call, in USD", "A call rule with a markup marks up Telenow's total; USD at rate 1, 4 places, half-even.", "wholesale-usd", "1", {"call": OUT_C}, []),
    ("31-discount", "A negative markup", "−10% gives a fee below Telenow's charge.", "discount", "84.25", RENT,
     [L("discount", [{"id": "fees", "match": {"item": "fee"}, "price": {"markupPercent": "-10"}}])]),
    ("32-zero-duration", "A per-minute price on a call of 0 s", "No started minute: one line of 0.00. Written for this case.", "per-minute", "84.25",
     {"inline": {"kind": "call", "attributes": CALL_ATTRS, "durationSec": 0, "components": {"telephony": "0"}, "totalUsd": "0"}}, []),
    ("33-missing-attribute-never-matches", "A rule on an attribute the item lacks", "direction is null, so the per-minute call rules do not match and the call falls to component rules — here none, so refused. Written for this case.", "per-minute", "84.25",
     {"inline": {"kind": "call", "attributes": {"callType": "agent_voice", "direction": None, "channel": "telephony"}, "durationSec": 95, "components": {"telephony": "0.0266"}, "totalUsd": "0.0266"}}, []),
    ("34-fee-with-no-kind", "A ledger charge with no refKind", "A catch-all fee rule still prices it; the line's subject has nothing after the colon. Written for this case.", "starter", "84.25",
     {"ledgerEntry": {"id": "00000000-0000-4000-8000-000000000034", "entryType": "charge", "amountUsd": "-0.5000000000", "balanceAfterUsd": "39.5000000000", "refKind": None,
                      "refId": None, "fxCurrency": None, "fxRate": None, "fxAmount": None, "note": "Manual charge", "createdBy": "staff", "createdAt": "2026-10-29T17:00:00Z", "sourceOrgId": None}}, []),
    ("35-lines-in-name-order", "Lines in byte order of their names, and the rate written short", "Components given out of order come out in byte order (platformAi < platformFee < postCallAnalysis: capitals sort first); a rate written 84.2500 is answered 84.25. Written for this case.", "starter", "84.2500",
     {"inline": {"kind": "call", "attributes": CALL_ATTRS, "durationSec": 95,
                 "components": {"tts": "0.0158400000", "postCallAnalysis": "0.0012500000", "platformFee": "0.0158333333", "platformAi": "0.0010000000", "llm": "0.0041250000"},
                 "totalUsd": "0.0380483333"}}, []),
]


def main():
    out_dir = os.path.join(PRICING, "fixtures")
    for f in os.listdir(out_dir):
        if f.endswith(".json"):
            os.remove(os.path.join(out_dir, f))
    for fid, name, why, plan, rate, item, extra in CASES:
        fixture = {"name": name, "why": why, "plan": plan, "usdRate": rate}
        if extra:
            fixture["priceLists"] = extra
        fixture["item"] = item
        fixture["expect"] = price(load_lists(extra), plan, rate, item)
        with open(os.path.join(out_dir, fid + ".json"), "w") as fh:
            json.dump(fixture, fh, indent=2)
            fh.write("\n")
        e = fixture["expect"]
        print(fid, e.get("error") or e["total"], e.get("subject", ""), [(l["subject"], l["amount"]) for l in e.get("lines", [])])


main()

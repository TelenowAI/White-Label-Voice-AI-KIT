/**
 * Exact fractions on BigInt, for money: a decimal string in, exact sums, products and quotients, and rounding only
 * when asked, to a number of places by a named rule. A per-minute price over 61 seconds is 61/60 of a minute; no
 * float ever touches it. Used by the pricing engine (pricing/engine.ts).
 *
 * Not here: what is priced or how (the engine), formatting a rounded amount's places (formatScaled, below).
 */

const DECIMAL = /^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/;

export type Rounding = "half-up" | "half-even" | "up" | "down";

function gcd(a: bigint, b: bigint): bigint {
  let x = a < 0n ? -a : a;
  let y = b < 0n ? -b : b;
  while (y) [x, y] = [y, x % y];
  return x;
}

export class Rational {
  private constructor(
    readonly num: bigint,
    readonly den: bigint,
  ) {}

  static of(num: bigint, den: bigint = 1n): Rational {
    if (den === 0n) throw new RangeError("division by zero");
    const sign = den < 0n ? -1n : 1n;
    const g = gcd(num, den) || 1n;
    return new Rational((sign * num) / g, (sign * den) / g);
  }

  /** A decimal string, `-?digits[.digits]` — nothing else (no exponent, no spaces, no float). */
  static parse(s: string): Rational {
    if (!DECIMAL.test(s)) throw new SyntaxError(`not a decimal: ${JSON.stringify(s)}`);
    const negative = s.startsWith("-");
    const [whole, frac = ""] = (negative ? s.slice(1) : s).split(".");
    const n = BigInt(whole + frac) * (negative ? -1n : 1n);
    return Rational.of(n, 10n ** BigInt(frac.length));
  }

  plus(o: Rational): Rational {
    return Rational.of(this.num * o.den + o.num * this.den, this.den * o.den);
  }

  minus(o: Rational): Rational {
    return Rational.of(this.num * o.den - o.num * this.den, this.den * o.den);
  }

  times(o: Rational): Rational {
    return Rational.of(this.num * o.num, this.den * o.den);
  }

  div(o: Rational): Rational {
    return Rational.of(this.num * o.den, this.den * o.num);
  }

  sign(): -1 | 0 | 1 {
    return this.num < 0n ? -1 : this.num > 0n ? 1 : 0;
  }

  /** This value × 10^places, rounded to a whole number by `mode` (ties and directions as README.md step 6). */
  round(places: number, mode: Rounding): bigint {
    const n = this.num * 10n ** BigInt(places);
    const d = this.den;
    const a = n < 0n ? -n : n;
    const floor = a / d;
    const rem = a - floor * d; // a = floor·d + rem, 0 ≤ rem < d
    let m = floor;
    if (rem !== 0n) {
      const twice = 2n * rem;
      if (mode === "up") m = floor + 1n;
      else if (mode === "half-up") m = twice >= d ? floor + 1n : floor;
      else if (mode === "half-even") m = twice > d || (twice === d && floor % 2n === 1n) ? floor + 1n : floor;
    }
    return n < 0n ? -m : m;
  }

  /** The shortest decimal string of this value: no trailing zeros, `0` for zero. Only for a finite decimal. */
  toShortDecimal(): string {
    let k = 0;
    while ((10n ** BigInt(k)) % this.den !== 0n) {
      if (++k > 60) throw new RangeError("not a finite decimal");
    }
    const scaled = (this.num * 10n ** BigInt(k)) / this.den;
    if (scaled === 0n) return "0";
    const digits = (scaled < 0n ? -scaled : scaled).toString().padStart(k + 1, "0");
    const whole = k ? digits.slice(0, -k) : digits;
    // k is the fewest places that hold the value exactly (the fraction is kept reduced), so the last digit is
    // never a 0: there are no trailing zeros to strip.
    const frac = k ? digits.slice(-k) : "";
    return (scaled < 0n ? "-" : "") + whole + (frac ? `.${frac}` : "");
  }
}

/** A rounded amount (a whole number of 10^-places units) as a decimal string with exactly `places` places. */
export function formatScaled(m: bigint, places: number): string {
  const digits = (m < 0n ? -m : m).toString().padStart(places + 1, "0");
  const s = places ? `${digits.slice(0, -places)}.${digits.slice(-places)}` : digits;
  return (m < 0n ? "-" : "") + s;
}

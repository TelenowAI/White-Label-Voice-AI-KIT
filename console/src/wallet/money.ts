/**
 * Money as the kit sends it — an exact decimal string and a currency code — shown in the viewer's locale with the
 * currency's sign, keeping exactly the decimals it came with (a charge of 3.42 never reads 3.4 or 3.420).
 * Used by the console's wallet and calls, and admin's customer wallet and payments. `subtract` is exact on such
 * strings (what is left to refund), never a float's 299.49999; `rounded` rounds one to fewer places, exactly (a
 * balance the voice platform sends to 10 places, shown to the cent).
 */
export function money(amount: string, currency: string | null): string {
  const places = amount.includes(".") ? amount.split(".")[1].length : 0;
  if (!currency) return amount;
  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency, minimumFractionDigits: places, maximumFractionDigits: places }).format(Number(amount));
  } catch {
    return `${amount} ${currency}`;
  }
}

/** a − b on exact decimal strings ("499.50" − "200" → "299.50"), with the larger of their places. */
export function subtract(a: string, b: string): string {
  const places = Math.max(...[a, b].map((x) => (x.includes(".") ? x.split(".")[1].length : 0)));
  const scaled = (x: string) => {
    const [whole, frac = ""] = x.replace(/^-/, "").split(".");
    const n = BigInt(whole + frac.padEnd(places, "0"));
    return x.startsWith("-") ? -n : n;
  };
  const d = scaled(a) - scaled(b);
  const digits = (d < 0n ? -d : d).toString().padStart(places + 1, "0");
  const out = places ? `${digits.slice(0, -places)}.${digits.slice(-places)}` : digits;
  return d < 0n ? `-${out}` : out;
}

/** An exact decimal string rounded half away from zero to `places` ("40.0000000000" → "40.00", "-0.125" → "-0.13"). */
export function rounded(amount: string, places: number): string {
  const negative = amount.startsWith("-");
  const [whole, frac = ""] = amount.replace(/^-/, "").split(".");
  if (frac.length <= places) return `${negative ? "-" : ""}${whole}${places ? `.${frac.padEnd(places, "0")}` : ""}`;
  let n = BigInt(whole + frac.slice(0, places));
  if (Number(frac[places]) >= 5) n += 1n;
  const digits = n.toString().padStart(places + 1, "0");
  const out = places ? `${digits.slice(0, -places)}.${digits.slice(-places)}` : digits;
  return negative && /[1-9]/.test(out) ? `-${out}` : out;
}

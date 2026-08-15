const SCALE_DIGITS = 18;
const SCALE = 10n ** 18n;

function parseAtoms(value) {
  const text = String(value).trim();
  const match = text.match(/^([+-]?)(\d+)(?:\.(\d*))?$/);
  if (!match) throw new Error(`Invalid decimal: ${value}`);
  const sign = match[1] === '-' ? -1n : 1n;
  const whole = BigInt(match[2]);
  const rawFrac = match[3] || '';
  if (rawFrac.length > SCALE_DIGITS && /[1-9]/.test(rawFrac.slice(SCALE_DIGITS))) {
    throw new Error(`Decimal has more than ${SCALE_DIGITS} non-zero fractional digits: ${value}`);
  }
  const frac = rawFrac.slice(0, SCALE_DIGITS).padEnd(SCALE_DIGITS, '0');
  return sign * (whole * SCALE + BigInt(frac || '0'));
}

function formatAtoms(atoms) {
  const negative = atoms < 0n;
  const abs = negative ? -atoms : atoms;
  const whole = abs / SCALE;
  const frac = (abs % SCALE).toString().padStart(SCALE_DIGITS, '0');
  return `${negative ? '-' : ''}${whole.toString()}.${frac}`;
}

export class Dec {
  constructor(atoms) { this.atoms = BigInt(atoms); }
  static from(value) { return value instanceof Dec ? value : new Dec(parseAtoms(value)); }
  static min(a, b) { a = Dec.from(a); b = Dec.from(b); return a.lte(b) ? a : b; }
  static max(a, b) { a = Dec.from(a); b = Dec.from(b); return a.gte(b) ? a : b; }
  plus(value) { return new Dec(this.atoms + Dec.from(value).atoms); }
  minus(value) { return new Dec(this.atoms - Dec.from(value).atoms); }
  mul(value) { return new Dec((this.atoms * Dec.from(value).atoms) / SCALE); }
  div(value) {
    const other = Dec.from(value).atoms;
    if (other === 0n) throw new Error('Division by zero');
    return new Dec((this.atoms * SCALE) / other);
  }
  abs() { return new Dec(this.atoms < 0n ? -this.atoms : this.atoms); }
  neg() { return new Dec(-this.atoms); }
  quantize(scale) {
    const digits = Number(scale);
    if (!Number.isInteger(digits) || digits < 0 || digits > 18) throw new Error(`Invalid decimal scale: ${scale}`);
    if (digits === 18) return new Dec(this.atoms);
    const factor = 10n ** BigInt(18 - digits);
    return new Dec((this.atoms / factor) * factor);
  }
  gt(v) { return this.atoms > Dec.from(v).atoms; }
  gte(v) { return this.atoms >= Dec.from(v).atoms; }
  lt(v) { return this.atoms < Dec.from(v).atoms; }
  lte(v) { return this.atoms <= Dec.from(v).atoms; }
  eq(v) { return this.atoms === Dec.from(v).atoms; }
  isZero() { return this.atoms === 0n; }
  sign() { return this.atoms > 0n ? 1 : this.atoms < 0n ? -1 : 0; }
  toString() { return formatAtoms(this.atoms); }
}

export function d(value) { return Dec.from(value); }
export function fixed18(value) { return Dec.from(value).toString(); }
export function positive(value) {
  const x = Dec.from(value);
  if (x.lte(0)) throw new Error(`Expected positive decimal, got ${value}`);
  return x;
}
export function ratioBps(numerator, denominator) {
  const den = Dec.from(denominator);
  if (den.lte(0)) throw new Error('ratio denominator must be positive');
  const num = Dec.from(numerator).abs();
  return Number((num.atoms * 10000n) / den.atoms);
}

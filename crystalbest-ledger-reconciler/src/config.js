import 'dotenv/config';

function integer(name, fallback, min, max) {
  const raw = process.env[name];
  const value = raw == null || raw === '' ? fallback : Number(raw);
  if (!Number.isInteger(value) || value < min || value > max) throw new Error(`${name} invalid`);
  return value;
}
function required(name) {
  const value = String(process.env[name] || '').trim();
  if (!value) throw new Error(`${name} is required`);
  return value;
}
function bool(name, fallback) {
  const raw = process.env[name];
  if (raw == null || raw === '') return fallback;
  return ['1','true','yes','on'].includes(String(raw).toLowerCase());
}
function optionalIso(name) {
  const raw = String(process.env[name] || '').trim();
  if (!raw) return null;
  const d = new Date(raw);
  if (Number.isNaN(d.getTime())) throw new Error(`${name} must be ISO date/time`);
  return d;
}

export const config = {
  version: '1.0.0',
  db: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: integer('DB_PORT', 3306, 1, 65535),
    user: required('DB_USER'),
    password: required('DB_PASSWORD'),
    database: process.env.DB_NAME || 'jwrf72paj77dykp4',
    connectionLimit: integer('DB_CONNECTION_LIMIT', 4, 2, 20)
  },
  schedule: {
    tzOffsetMinutes: integer('RECONCILE_TZ_OFFSET_MINUTES', 480, -720, 840),
    hour: integer('RECONCILE_DAILY_HOUR', 1, 0, 23),
    minute: integer('RECONCILE_DAILY_MINUTE', 0, 0, 59),
    catchUp: bool('RECONCILE_CATCH_UP', true)
  },
  bootstrapAt: optionalIso('RECONCILE_BOOTSTRAP_AT'),
  systems: { feeCode: String(process.env.TRADING_FEE_SYSTEM_CODE || 'TRADING_FEE').trim() },
  logs: { level: String(process.env.LOG_LEVEL || 'info').toLowerCase() }
};

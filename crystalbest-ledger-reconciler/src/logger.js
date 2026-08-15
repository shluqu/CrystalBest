const levels = { debug: 10, info: 20, warn: 30, error: 40 };
export function createLogger(level='info') {
  const threshold = levels[level] || 20;
  const out = (name, data, message) => {
    if ((levels[name] || 20) < threshold) return;
    const row = { ts: new Date().toISOString(), level: name, service: 'ledger-reconciler', msg: message };
    if (data && typeof data === 'object') Object.assign(row, data);
    console.log(JSON.stringify(row));
  };
  return {
    debug: (data,msg) => out('debug',data,msg), info: (data,msg) => out('info',data,msg),
    warn: (data,msg) => out('warn',data,msg), error: (data,msg) => out('error',data,msg)
  };
}

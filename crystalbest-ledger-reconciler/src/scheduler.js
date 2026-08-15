function shifted(date, offsetMinutes) { return new Date(date.getTime() + offsetMinutes * 60000); }
function unshifted(date, offsetMinutes) { return new Date(date.getTime() - offsetMinutes * 60000); }

export function localDateKey(date, offsetMinutes) {
  const d = shifted(date, offsetMinutes);
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth()+1).padStart(2,'0')}-${String(d.getUTCDate()).padStart(2,'0')}`;
}

export function nextScheduledAt(now, { tzOffsetMinutes, hour, minute }) {
  const local = shifted(now, tzOffsetMinutes);
  let candidate = new Date(Date.UTC(local.getUTCFullYear(), local.getUTCMonth(), local.getUTCDate(), hour, minute, 0, 0));
  if (candidate.getTime() <= local.getTime()) candidate = new Date(candidate.getTime() + 86400000);
  return unshifted(candidate, tzOffsetMinutes);
}

export class DailyScheduler {
  constructor({ schedule, logger, shouldCatchUp, run }) {
    this.schedule = schedule; this.logger = logger; this.shouldCatchUp = shouldCatchUp; this.run = run;
    this.timer = null; this.stopped = true;
  }
  async start() {
    this.stopped = false;
    if (this.schedule.catchUp && await this.shouldCatchUp()) {
      this.logger.warn({}, 'Missed daily reconciliation detected; running one catch-up now');
      await this.run('catch-up');
    }
    this.#arm();
  }
  stop() { this.stopped = true; if (this.timer) clearTimeout(this.timer); this.timer = null; }
  #arm() {
    if (this.stopped) return;
    const at = nextScheduledAt(new Date(), this.schedule);
    const delay = Math.max(1000, at.getTime() - Date.now());
    this.logger.info({ next_run_at_utc: at.toISOString(), delay_ms: delay }, 'Daily reconciliation scheduled');
    this.timer = setTimeout(async () => {
      try { await this.run('scheduled'); }
      catch (e) { this.logger.error({ err: e.message, stack: e.stack }, 'Daily reconciliation failed'); }
      finally { this.#arm(); }
    }, delay);
  }
}

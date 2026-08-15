import { ulid } from 'ulid';
import { d, fixed18 } from './decimal.js';

const RUNNING = 1;
const PASSED = 2;
const DIFFERENCES = 3;
const FAILED = 4;

function sqlNow(date = new Date()) {
  return date.toISOString().replace('T',' ').replace('Z','').replace(/\.(\d{3})$/, '.$1000');
}
function parseDbDate(value) {
  if (!value) return null;
  const s = String(value).replace(' ', 'T').replace(/(\.\d{1,6})$/, (m) => m.padEnd(7,'0')) + 'Z';
  const x = new Date(s);
  return Number.isNaN(x.getTime()) ? null : x;
}
function sameNullableDecimal(a,b) {
  if (a == null && b == null) return true;
  if (a == null || b == null) return false;
  return d(a).eq(b);
}
function signed(direction, amount) { return Number(direction) === 2 ? d(amount) : d(amount).neg(); }
function chunks(arr, n) { const out=[]; for(let i=0;i<arr.length;i+=n) out.push(arr.slice(i,i+n)); return out; }

export class LedgerReconciler {
  constructor({ pool, config, logger }) {
    this.pool = pool; this.config = config; this.logger = logger;
  }

  async doctor() {
    const [[db]] = await this.pool.query('SELECT DATABASE() AS db, VERSION() AS version');
    const required = [
      'cex_audit_reconciliation_runs','cex_audit_reconciliation_items','cex_audit_reconciliation_cursors',
      'cex_audit_reconciliation_repair_actions','cex_asset_ledger_transactions','cex_asset_ledger_entries',
      'cex_asset_ledger_accounts','cex_asset_balances','cex_asset_holds','cex_spot_fills','cex_perp_fills','cex_perp_positions'
    ];
    const [tables] = await this.pool.query(`SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()`);
    const have = new Set(tables.map(r=>String(r.TABLE_NAME)));
    const support = Object.fromEntries(required.map(x=>[x,have.has(x)]));
    const [[fee]] = await this.pool.query('SELECT id,public_id,status FROM cex_account_accounts WHERE account_kind=2 AND system_code=? LIMIT 1',[this.config.systems.feeCode]);
    const ok = Object.values(support).every(Boolean) && fee && Number(fee.status)===1;
    return { ok, version:this.config.version, db, support_tables:support, fee_account:fee||null };
  }

  async shouldCatchUpDaily(localDateKeyFn, offsetMinutes) {
    const now = new Date();
    const local = new Date(now.getTime() + offsetMinutes * 60000);
    const scheduledTodayPassed = local.getUTCHours() > this.config.schedule.hour
      || (local.getUTCHours() === this.config.schedule.hour && local.getUTCMinutes() >= this.config.schedule.minute);
    const target = scheduledTodayPassed ? now : new Date(now.getTime() - 86400000);
    const targetKey = localDateKeyFn(target, offsetMinutes);
    const [[row]] = await this.pool.query(`
      SELECT period_end_at FROM cex_audit_reconciliation_runs
      WHERE reconciliation_type='LEDGER_DAILY_ALL_USERS' AND status IN (2,3)
      ORDER BY id DESC LIMIT 1
    `);
    if (!row) return true;
    const last = parseDbDate(row.period_end_at);
    if (!last) return true;
    return localDateKeyFn(last, offsetMinutes) < targetKey;
  }

  async runDaily(trigger='scheduled') { return this.#run('LEDGER_DAILY_ALL_USERS','DAILY',trigger); }
  async runManual() { return this.#run('LEDGER_MANUAL_INCREMENTAL','MANUAL','manual'); }

  async #run(type, cursorKey, trigger) {
    const lockConn = await this.pool.getConnection();
    const lockName = 'cb:ledger-reconciler:global';
    let got=false;
    try {
      const [[r]] = await lockConn.query('SELECT GET_LOCK(?,0) AS ok',[lockName]);
      got=Number(r?.ok)===1;
      if(!got) throw new Error('RECONCILIATION_ALREADY_RUNNING');
      const periodEnd = new Date();
      const periodStart = await this.#periodStart(cursorKey, periodEnd);
      if (periodStart.getTime() >= periodEnd.getTime()) periodStart.setTime(periodEnd.getTime()-1);
      const runNo = ulid();
      const started = sqlNow();
      const [ins] = await lockConn.execute(`
        INSERT INTO cex_audit_reconciliation_runs
          (run_no,reconciliation_type,scope_key,period_start_at,period_end_at,status,checked_count,difference_count,summary_json,started_at,created_at)
        VALUES (?,?,?, ?,?, ?,0,0,?,?,?)
      `,[runNo,type,'ALL_USERS',sqlNow(periodStart),sqlNow(periodEnd),RUNNING,JSON.stringify({trigger,version:this.config.version}),started,started]);
      const runId=String(ins.insertId);
      const ctx={ runId, runNo, type, cursorKey, trigger, periodStart, periodEnd, checked:0, differences:0, reasonCounts:{}, feeAccountId:null };
      this.logger.info({run_no:runNo,type,period_start:periodStart.toISOString(),period_end:periodEnd.toISOString(),trigger},'Reconciliation started');
      try {
        const [[fee]]=await lockConn.query('SELECT id FROM cex_account_accounts WHERE account_kind=2 AND system_code=? AND status=1 LIMIT 1',[this.config.systems.feeCode]);
        if(!fee) throw new Error('TRADING_FEE_SYSTEM_ACCOUNT_MISSING');
        ctx.feeAccountId=String(fee.id);
        await this.#checkLedgerJournals(ctx);
        await this.#checkLedgerEntries(ctx);
        await this.#checkBalanceCache(ctx);
        await this.#checkHolds(ctx);
        await this.#checkPerpFills(ctx);
        await this.#checkSpotFills(ctx);
        await this.#checkPositions(ctx);
        const completed=sqlNow();
        const status=ctx.differences>0?DIFFERENCES:PASSED;
        const summary={trigger,version:this.config.version,checked:ctx.checked,differences:ctx.differences,reason_counts:ctx.reasonCounts,repair_mode:'PROPOSAL_ONLY_ADMIN_NOT_ENABLED'};
        await lockConn.execute(`UPDATE cex_audit_reconciliation_runs SET status=?,checked_count=?,difference_count=?,summary_json=?,completed_at=? WHERE id=?`,[status,ctx.checked,ctx.differences,JSON.stringify(summary),completed,runId]);
        await lockConn.execute(`
          INSERT INTO cex_audit_reconciliation_cursors (cursor_key,last_run_id,last_completed_at,updated_at)
          VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_run_id=VALUES(last_run_id),last_completed_at=VALUES(last_completed_at),updated_at=VALUES(updated_at)
        `,[cursorKey,runId,sqlNow(periodEnd),completed]);
        this.logger[ctx.differences?'warn':'info']({run_no:runNo,checked:ctx.checked,differences:ctx.differences,reason_counts:ctx.reasonCounts},'Reconciliation completed');
        return {ok:ctx.differences===0,run_id:runId,run_no:runNo,type,period_start:periodStart.toISOString(),period_end:periodEnd.toISOString(),checked:ctx.checked,differences:ctx.differences,reason_counts:ctx.reasonCounts};
      } catch(error) {
        const completed=sqlNow();
        await lockConn.execute(`UPDATE cex_audit_reconciliation_runs SET status=?,checked_count=?,difference_count=?,summary_json=?,completed_at=? WHERE id=?`,[FAILED,ctx.checked,ctx.differences,JSON.stringify({trigger,version:this.config.version,error:error.message,reason_counts:ctx.reasonCounts}),completed,runId]);
        throw error;
      }
    } finally {
      if(got) { try{await lockConn.query('SELECT RELEASE_LOCK(?)',[lockName]);}catch{} }
      lockConn.release();
    }
  }

  async #periodStart(cursorKey, periodEnd) {
    const [[cursor]]=await this.pool.query('SELECT last_completed_at FROM cex_audit_reconciliation_cursors WHERE cursor_key=? LIMIT 1',[cursorKey]);
    const fromCursor=parseDbDate(cursor?.last_completed_at);
    if(fromCursor) return fromCursor;
    if(this.config.bootstrapAt) return new Date(this.config.bootstrapAt);
    const [[first]]=await this.pool.query('SELECT MIN(occurred_at) AS at FROM cex_asset_ledger_transactions');
    return parseDbDate(first?.at) || new Date(periodEnd.getTime()-86400000);
  }

  async #issue(ctx,{entityType,entityId,assetId=null,expected=null,actual=null,reason,details={},repair=null}) {
    const difference=(expected!=null&&actual!=null)?fixed18(d(actual).minus(expected)):null;
    const [result]=await this.pool.execute(`
      INSERT INTO cex_audit_reconciliation_items
        (run_id,entity_type,entity_id,asset_id,expected_value,actual_value,difference_value,resolution_status,reason_code,details_json,created_at,updated_at)
      VALUES (?,?,?,?,CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),CAST(? AS DECIMAL(38,18)),1,?,?,?,?)
    `,[ctx.runId,entityType,String(entityId),assetId,expected,actual,difference,reason,JSON.stringify(details),sqlNow(),sqlNow()]);
    ctx.differences+=1; ctx.reasonCounts[reason]=(ctx.reasonCounts[reason]||0)+1;
    if(repair) await this.pool.execute(`
      INSERT INTO cex_audit_reconciliation_repair_actions
        (item_id,repair_code,risk_level,proposed_value,proposed_payload_json,status,requires_approval,created_at,updated_at)
      VALUES (?,?,?,CAST(? AS DECIMAL(38,18)),?,1,1,?,?)
      ON DUPLICATE KEY UPDATE id=id
    `,[String(result.insertId),repair.code,repair.riskLevel||2,repair.proposedValue??null,JSON.stringify(repair.payload||{}),sqlNow(),sqlNow()]);
  }
  #checked(ctx,n=1){ctx.checked+=n;}

  async #checkLedgerJournals(ctx){
    const [rows]=await this.pool.query(`
      SELECT t.id,t.journal_no,e.asset_id,
             SUM(CASE WHEN e.direction=2 THEN e.amount ELSE -e.amount END) AS net_delta
      FROM cex_asset_ledger_transactions t
      JOIN cex_asset_ledger_entries e ON e.transaction_id=t.id
      WHERE t.occurred_at>=? AND t.occurred_at<?
      GROUP BY t.id,t.journal_no,e.asset_id ORDER BY t.id,e.asset_id
    `,[sqlNow(ctx.periodStart),sqlNow(ctx.periodEnd)]);
    for(const row of rows){this.#checked(ctx); if(!d(row.net_delta||'0').isZero()) await this.#issue(ctx,{entityType:'LEDGER_TRANSACTION_BALANCE',entityId:`${row.id}:${row.asset_id}`,assetId:row.asset_id,expected:'0',actual:row.net_delta,reason:'LEDGER_UNBALANCED',details:{transaction_id:String(row.id),journal_no:row.journal_no},repair:{code:'MANUAL_LEDGER_REVIEW',riskLevel:3,payload:{transaction_id:String(row.id)}}});}
  }

  async #checkLedgerEntries(ctx){
    const [rows]=await this.pool.query(`SELECT id,transaction_id,ledger_account_id,asset_id,direction,amount,balance_before,balance_after FROM cex_asset_ledger_entries WHERE created_at>=? AND created_at<? ORDER BY ledger_account_id,id`,[sqlNow(ctx.periodStart),sqlNow(ctx.periodEnd)]);
    const affected=[...new Set(rows.map(r=>String(r.ledger_account_id)))];
    for(const row of rows){
      this.#checked(ctx);
      const expected=d(row.balance_before).plus(signed(row.direction,row.amount));
      if(!expected.eq(row.balance_after)) await this.#issue(ctx,{entityType:'LEDGER_ENTRY_ARITHMETIC',entityId:row.id,assetId:row.asset_id,expected:fixed18(expected),actual:row.balance_after,reason:'LEDGER_ENTRY_ARITHMETIC_MISMATCH',details:{transaction_id:String(row.transaction_id),ledger_account_id:String(row.ledger_account_id)},repair:{code:'MANUAL_LEDGER_REVIEW',riskLevel:3,payload:{entry_id:String(row.id)}}});
    }
    for(const group of chunks(affected,200)){
      if(!group.length) continue;
      const qs=group.map(()=>'?').join(',');
      const [chain]=await this.pool.query(`SELECT id,ledger_account_id,asset_id,balance_before,balance_after FROM cex_asset_ledger_entries WHERE ledger_account_id IN (${qs}) AND created_at<? ORDER BY ledger_account_id,id`,[...group,sqlNow(ctx.periodEnd)]);
      const prev=new Map();
      for(const row of chain){
        const key=String(row.ledger_account_id); const p=prev.get(key);
        if(p){ this.#checked(ctx); if(!d(p.balance_after).eq(row.balance_before)) await this.#issue(ctx,{entityType:'LEDGER_ENTRY_CHAIN',entityId:row.id,assetId:row.asset_id,expected:p.balance_after,actual:row.balance_before,reason:'LEDGER_ENTRY_CHAIN_BROKEN',details:{ledger_account_id:key,previous_entry_id:String(p.id)},repair:{code:'MANUAL_LEDGER_REVIEW',riskLevel:3,payload:{entry_id:String(row.id),previous_entry_id:String(p.id)}}}); }
        prev.set(key,row);
      }
    }
  }

  async #checkBalanceCache(ctx){
    const [rows]=await this.pool.query(`
      SELECT la.id AS ledger_account_id,la.asset_id,la.account_id,b.balance,b.last_entry_id,
             le.id AS expected_last_entry_id,le.balance_after AS expected_balance
      FROM cex_asset_ledger_accounts la
      LEFT JOIN cex_asset_balances b ON b.ledger_account_id=la.id
      LEFT JOIN (
        SELECT e1.* FROM cex_asset_ledger_entries e1
        JOIN (SELECT ledger_account_id,MAX(id) AS max_id FROM cex_asset_ledger_entries GROUP BY ledger_account_id) x ON x.max_id=e1.id
      ) le ON le.ledger_account_id=la.id
      WHERE la.status=1 ORDER BY la.id
    `);
    for(const row of rows){
      this.#checked(ctx);
      const expected=row.expected_balance==null?'0':String(row.expected_balance); const actual=row.balance==null?null:String(row.balance);
      const lastOk=String(row.last_entry_id??'')===String(row.expected_last_entry_id??'');
      const balOk=actual!=null&&d(actual).eq(expected);
      if(!lastOk||!balOk) await this.#issue(ctx,{entityType:'BALANCE_CACHE',entityId:row.ledger_account_id,assetId:row.asset_id,expected,actual:actual??'0',reason:'BALANCE_CACHE_MISMATCH',details:{account_id:String(row.account_id),last_entry_id:row.last_entry_id?String(row.last_entry_id):null,expected_last_entry_id:row.expected_last_entry_id?String(row.expected_last_entry_id):null,balance_row_missing:row.balance==null},repair:{code:'REBUILD_BALANCE_CACHE_FROM_LAST_LEDGER_ENTRY',riskLevel:1,proposedValue:expected,payload:{ledger_account_id:String(row.ledger_account_id),asset_id:String(row.asset_id),expected_last_entry_id:row.expected_last_entry_id?String(row.expected_last_entry_id):null}}});
    }
  }

  async #checkHolds(ctx){
    const [active]=await this.pool.query(`
      SELECT h.id,h.business_type,h.business_id,h.account_id,h.asset_id,h.remaining_amount,h.status,
             po.id AS perp_order_id,po.status AS perp_status,po.hold_id AS perp_hold_id,
             so.id AS spot_order_id,so.status AS spot_status,so.hold_id AS spot_hold_id
      FROM cex_asset_holds h
      LEFT JOIN cex_perp_orders po ON h.business_type='PERP_ORDER' AND po.order_no=h.business_id
      LEFT JOIN cex_spot_orders so ON h.business_type='SPOT_ORDER' AND so.order_no=h.business_id
      WHERE h.status=1 AND h.business_type IN ('PERP_ORDER','SPOT_ORDER') ORDER BY h.id
    `);
    for(const row of active){
      this.#checked(ctx); let ok=true,details={business_type:row.business_type,business_id:row.business_id};
      if(d(row.remaining_amount||'0').lte(0)) ok=false;
      if(row.business_type==='PERP_ORDER') ok=ok&&row.perp_order_id!=null&&[2,3].includes(Number(row.perp_status))&&String(row.perp_hold_id)===String(row.id);
      else ok=ok&&row.spot_order_id!=null&&[2,3].includes(Number(row.spot_status))&&String(row.spot_hold_id)===String(row.id);
      if(!ok) await this.#issue(ctx,{entityType:'ACTIVE_HOLD_STATE',entityId:row.id,assetId:row.asset_id,expected:'1',actual:'0',reason:'ACTIVE_HOLD_ORDER_MISMATCH',details,repair:{code:'MANUAL_HOLD_REVIEW',riskLevel:2,payload:{hold_id:String(row.id)}}});
    }
    const [perp]=await this.pool.query(`
      SELECT o.id,o.order_no,o.account_id,o.reduce_only,o.reserved_order_margin,o.hold_id,h.status AS hold_status,h.remaining_amount
      FROM cex_perp_orders o LEFT JOIN cex_asset_holds h ON h.id=o.hold_id
      WHERE o.status IN (2,3) AND o.account_id IN (SELECT id FROM cex_account_accounts WHERE account_kind=1)
    `);
    for(const row of perp){this.#checked(ctx); const zeroReduce=Number(row.reduce_only)===1&&d(row.reserved_order_margin||'0').isZero()&&row.hold_id==null; const ok=zeroReduce||(row.hold_id!=null&&Number(row.hold_status)===1&&d(row.remaining_amount||'0').gt(0)); if(!ok) await this.#issue(ctx,{entityType:'PERP_OPEN_ORDER_HOLD',entityId:row.id,expected:'1',actual:'0',reason:'PERP_OPEN_ORDER_HOLD_MISMATCH',details:{order_no:row.order_no,account_id:String(row.account_id)},repair:{code:'MANUAL_ORDER_HOLD_REVIEW',riskLevel:2,payload:{order_id:String(row.id)}}});}
    const [spot]=await this.pool.query(`SELECT o.id,o.order_no,o.account_id,o.hold_id,h.status AS hold_status,h.remaining_amount FROM cex_spot_orders o LEFT JOIN cex_asset_holds h ON h.id=o.hold_id WHERE o.status IN (2,3) AND o.account_id IN (SELECT id FROM cex_account_accounts WHERE account_kind=1)`);
    for(const row of spot){this.#checked(ctx); const ok=row.hold_id!=null&&Number(row.hold_status)===1&&d(row.remaining_amount||'0').gt(0); if(!ok) await this.#issue(ctx,{entityType:'SPOT_OPEN_ORDER_HOLD',entityId:row.id,expected:'1',actual:'0',reason:'SPOT_OPEN_ORDER_HOLD_MISMATCH',details:{order_no:row.order_no,account_id:String(row.account_id)},repair:{code:'MANUAL_ORDER_HOLD_REVIEW',riskLevel:2,payload:{order_id:String(row.id)}}});}
  }

  async #checkPerpFills(ctx){
    const [rows]=await this.pool.query(`
      SELECT f.id,f.fill_no,f.account_id,f.fee_asset_id,f.fee_amount,f.fee_rate_snapshot,f.fee_basis_amount,f.fee_rule_code,f.realized_pnl,f.ledger_transaction_id,
             t.business_type,t.metadata_json,
             COALESCE(SUM(CASE WHEN la.account_id=? AND e.asset_id=f.fee_asset_id THEN CASE WHEN e.direction=2 THEN e.amount ELSE -e.amount END ELSE 0 END),0) AS ledger_fee
      FROM cex_perp_fills f
      LEFT JOIN cex_asset_ledger_transactions t ON t.id=f.ledger_transaction_id
      LEFT JOIN cex_asset_ledger_entries e ON e.transaction_id=t.id
      LEFT JOIN cex_asset_ledger_accounts la ON la.id=e.ledger_account_id
      WHERE f.created_at>=? AND f.created_at<?
      GROUP BY f.id,f.fill_no,f.account_id,f.fee_asset_id,f.fee_amount,f.fee_rate_snapshot,f.fee_basis_amount,f.fee_rule_code,f.realized_pnl,f.ledger_transaction_id,t.business_type,t.metadata_json
      ORDER BY f.id
    `,[ctx.feeAccountId,sqlNow(ctx.periodStart),sqlNow(ctx.periodEnd)]);
    for(const row of rows){
      this.#checked(ctx,5);
      if(String(row.business_type||'')!=='PERP_REFERENCE_FILL') await this.#issue(ctx,{entityType:'PERP_FILL_LEDGER_LINK',entityId:row.id,assetId:row.fee_asset_id,expected:'1',actual:'0',reason:'PERP_FILL_LEDGER_TRANSACTION_INVALID',details:{fill_no:row.fill_no,ledger_transaction_id:String(row.ledger_transaction_id),business_type:row.business_type},repair:{code:'MANUAL_FILL_LEDGER_REVIEW',riskLevel:3,payload:{fill_id:String(row.id)}}});
      if(!d(row.ledger_fee||'0').eq(row.fee_amount||'0')) await this.#issue(ctx,{entityType:'PERP_FILL_FEE_LEDGER',entityId:row.id,assetId:row.fee_asset_id,expected:row.fee_amount,actual:row.ledger_fee,reason:'PERP_FILL_FEE_LEDGER_MISMATCH',details:{fill_no:row.fill_no,fee_rule_code:row.fee_rule_code},repair:{code:'MANUAL_FEE_LEDGER_REVIEW',riskLevel:3,payload:{fill_id:String(row.id)}}});
      if(row.fee_rate_snapshot!=null&&row.fee_basis_amount!=null){const expected=d(row.fee_basis_amount).mul(row.fee_rate_snapshot); if(!expected.eq(row.fee_amount||'0')) await this.#issue(ctx,{entityType:'PERP_FILL_FEE_SNAPSHOT',entityId:row.id,assetId:row.fee_asset_id,expected:fixed18(expected),actual:row.fee_amount,reason:'PERP_FILL_FEE_SNAPSHOT_MISMATCH',details:{fill_no:row.fill_no,fee_rate_snapshot:String(row.fee_rate_snapshot),fee_basis_amount:String(row.fee_basis_amount),note:'Historical fee is validated against immutable fill snapshot, never current contract fee rate.'},repair:{code:'MANUAL_FEE_SNAPSHOT_REVIEW',riskLevel:2,payload:{fill_id:String(row.id)}}});}
      try { const meta=typeof row.metadata_json==='string'?JSON.parse(row.metadata_json):row.metadata_json; if(meta?.fee_amount!=null&&!d(meta.fee_amount).eq(row.fee_amount)) await this.#issue(ctx,{entityType:'PERP_FILL_METADATA_FEE',entityId:row.id,assetId:row.fee_asset_id,expected:row.fee_amount,actual:meta.fee_amount,reason:'PERP_FILL_METADATA_FEE_MISMATCH',details:{fill_no:row.fill_no},repair:{code:'MANUAL_METADATA_REVIEW',riskLevel:2,payload:{fill_id:String(row.id)}}}); if(meta?.realized_pnl!=null&&!d(meta.realized_pnl).eq(row.realized_pnl)) await this.#issue(ctx,{entityType:'PERP_FILL_METADATA_PNL',entityId:row.id,assetId:row.fee_asset_id,expected:row.realized_pnl,actual:meta.realized_pnl,reason:'PERP_FILL_METADATA_PNL_MISMATCH',details:{fill_no:row.fill_no},repair:{code:'MANUAL_METADATA_REVIEW',riskLevel:2,payload:{fill_id:String(row.id)}}}); } catch {}
    }
  }

  async #checkSpotFills(ctx){
    const [rows]=await this.pool.query(`
      SELECT f.id,f.fill_no,f.account_id,f.side,f.fee_asset_id,f.fee_amount,f.fee_rate_snapshot,f.fee_basis_amount,f.fee_rule_code,f.ledger_transaction_id,
             a.ledger_decimals,t.business_type,t.metadata_json,
             COALESCE(SUM(CASE WHEN la.account_id=? AND e.asset_id=f.fee_asset_id THEN CASE WHEN e.direction=2 THEN e.amount ELSE -e.amount END ELSE 0 END),0) AS ledger_fee
      FROM cex_spot_fills f
      JOIN cex_asset_assets a ON a.id=f.fee_asset_id
      LEFT JOIN cex_asset_ledger_transactions t ON t.id=f.ledger_transaction_id
      LEFT JOIN cex_asset_ledger_entries e ON e.transaction_id=t.id
      LEFT JOIN cex_asset_ledger_accounts la ON la.id=e.ledger_account_id
      WHERE f.created_at>=? AND f.created_at<?
      GROUP BY f.id,f.fill_no,f.account_id,f.side,f.fee_asset_id,f.fee_amount,f.fee_rate_snapshot,f.fee_basis_amount,f.fee_rule_code,f.ledger_transaction_id,a.ledger_decimals,t.business_type,t.metadata_json
      ORDER BY f.id
    `,[ctx.feeAccountId,sqlNow(ctx.periodStart),sqlNow(ctx.periodEnd)]);
    for(const row of rows){
      this.#checked(ctx,3);
      if(String(row.business_type||'')!=='SPOT_REFERENCE_FILL') await this.#issue(ctx,{entityType:'SPOT_FILL_LEDGER_LINK',entityId:row.id,assetId:row.fee_asset_id,expected:'1',actual:'0',reason:'SPOT_FILL_LEDGER_TRANSACTION_INVALID',details:{fill_no:row.fill_no,ledger_transaction_id:String(row.ledger_transaction_id),business_type:row.business_type},repair:{code:'MANUAL_FILL_LEDGER_REVIEW',riskLevel:3,payload:{fill_id:String(row.id)}}});
      if(!d(row.ledger_fee||'0').eq(row.fee_amount||'0')) await this.#issue(ctx,{entityType:'SPOT_FILL_FEE_LEDGER',entityId:row.id,assetId:row.fee_asset_id,expected:row.fee_amount,actual:row.ledger_fee,reason:'SPOT_FILL_FEE_LEDGER_MISMATCH',details:{fill_no:row.fill_no,fee_rule_code:row.fee_rule_code},repair:{code:'MANUAL_FEE_LEDGER_REVIEW',riskLevel:3,payload:{fill_id:String(row.id)}}});
      if(row.fee_rate_snapshot!=null&&row.fee_basis_amount!=null){const expected=d(row.fee_basis_amount).mul(row.fee_rate_snapshot).quantize(Number(row.ledger_decimals)); if(!expected.eq(row.fee_amount||'0')) await this.#issue(ctx,{entityType:'SPOT_FILL_FEE_SNAPSHOT',entityId:row.id,assetId:row.fee_asset_id,expected:fixed18(expected),actual:row.fee_amount,reason:'SPOT_FILL_FEE_SNAPSHOT_MISMATCH',details:{fill_no:row.fill_no,fee_rate_snapshot:String(row.fee_rate_snapshot),fee_basis_amount:String(row.fee_basis_amount),fee_asset_decimals:Number(row.ledger_decimals),note:'Historical fee is validated against immutable fill snapshot, never current market fee rate.'},repair:{code:'MANUAL_FEE_SNAPSHOT_REVIEW',riskLevel:2,payload:{fill_id:String(row.id)}}});}
    }
  }

  async #checkPositions(ctx){
    const [pairs]=await this.pool.query(`
      SELECT DISTINCT p.account_id,p.contract_id,p.id AS position_id,p.position_quantity,p.entry_price,p.realized_pnl
      FROM cex_perp_positions p JOIN cex_account_accounts a ON a.id=p.account_id AND a.account_kind=1
      WHERE p.position_quantity<>0 OR p.updated_at>=?
      UNION
      SELECT DISTINCT f.account_id,o.contract_id,p.id AS position_id,p.position_quantity,p.entry_price,p.realized_pnl
      FROM cex_perp_fills f JOIN cex_perp_orders o ON o.id=f.order_id
      JOIN cex_perp_positions p ON p.account_id=f.account_id AND p.contract_id=o.contract_id
      WHERE f.created_at>=? AND f.created_at<?
    `,[sqlNow(ctx.periodStart),sqlNow(ctx.periodStart),sqlNow(ctx.periodEnd)]);
    for(const pair of pairs){
      const [fills]=await this.pool.query(`
        SELECT f.id,f.position_quantity_before,f.position_quantity_after,f.entry_price_before,f.entry_price_after,f.realized_pnl
        FROM cex_perp_fills f JOIN cex_perp_orders o ON o.id=f.order_id
        WHERE f.account_id=? AND o.contract_id=? ORDER BY f.id ASC
      `,[pair.account_id,pair.contract_id]);
      this.#checked(ctx,3+Math.max(0,fills.length-1));
      if(!fills.length){ if(!d(pair.position_quantity||'0').isZero()) await this.#issue(ctx,{entityType:'PERP_POSITION_FILL_CHAIN',entityId:pair.position_id,expected:'0',actual:pair.position_quantity,reason:'PERP_POSITION_WITHOUT_FILLS',details:{account_id:String(pair.account_id),contract_id:String(pair.contract_id)},repair:{code:'MANUAL_POSITION_REVIEW',riskLevel:3,payload:{position_id:String(pair.position_id)}}}); continue; }
      for(let i=1;i<fills.length;i++){ if(!d(fills[i-1].position_quantity_after).eq(fills[i].position_quantity_before)) await this.#issue(ctx,{entityType:'PERP_POSITION_FILL_CONTINUITY',entityId:fills[i].id,expected:fills[i-1].position_quantity_after,actual:fills[i].position_quantity_before,reason:'PERP_FILL_POSITION_CHAIN_BROKEN',details:{position_id:String(pair.position_id),previous_fill_id:String(fills[i-1].id)},repair:{code:'MANUAL_POSITION_REVIEW',riskLevel:3,payload:{position_id:String(pair.position_id),fill_id:String(fills[i].id)}}}); }
      const last=fills[fills.length-1];
      if(!d(last.position_quantity_after).eq(pair.position_quantity||'0')) await this.#issue(ctx,{entityType:'PERP_POSITION_QUANTITY',entityId:pair.position_id,expected:last.position_quantity_after,actual:pair.position_quantity,reason:'PERP_POSITION_QUANTITY_MISMATCH',details:{account_id:String(pair.account_id),contract_id:String(pair.contract_id),last_fill_id:String(last.id)},repair:{code:'REBUILD_POSITION_SNAPSHOT_FROM_FILLS',riskLevel:2,proposedValue:last.position_quantity_after,payload:{position_id:String(pair.position_id)}}});
      if(!sameNullableDecimal(last.entry_price_after,pair.entry_price)) await this.#issue(ctx,{entityType:'PERP_POSITION_ENTRY_PRICE',entityId:pair.position_id,expected:last.entry_price_after??'0',actual:pair.entry_price??'0',reason:'PERP_POSITION_ENTRY_PRICE_MISMATCH',details:{account_id:String(pair.account_id),contract_id:String(pair.contract_id),last_fill_id:String(last.id),expected_null:last.entry_price_after==null,actual_null:pair.entry_price==null},repair:{code:'REBUILD_POSITION_SNAPSHOT_FROM_FILLS',riskLevel:2,payload:{position_id:String(pair.position_id),entry_price:last.entry_price_after}}});
      const realized=fills.reduce((acc,x)=>acc.plus(x.realized_pnl||'0'),d(0));
      if(!realized.eq(pair.realized_pnl||'0')) await this.#issue(ctx,{entityType:'PERP_POSITION_REALIZED_PNL',entityId:pair.position_id,expected:fixed18(realized),actual:pair.realized_pnl,reason:'PERP_POSITION_REALIZED_PNL_MISMATCH',details:{account_id:String(pair.account_id),contract_id:String(pair.contract_id)},repair:{code:'REBUILD_POSITION_SNAPSHOT_FROM_FILLS',riskLevel:2,proposedValue:fixed18(realized),payload:{position_id:String(pair.position_id)}}});
    }
  }

  async listOpenIssues(limit=100){
    const [rows]=await this.pool.query(`
      SELECT i.id,i.run_id,r.run_no,r.reconciliation_type,i.entity_type,i.entity_id,i.asset_id,i.expected_value,i.actual_value,i.difference_value,
             i.reason_code,i.details_json,i.created_at,ra.repair_code,ra.risk_level,ra.status AS repair_status,ra.proposed_value,ra.proposed_payload_json
      FROM cex_audit_reconciliation_items i JOIN cex_audit_reconciliation_runs r ON r.id=i.run_id
      LEFT JOIN cex_audit_reconciliation_repair_actions ra ON ra.item_id=i.id
      WHERE i.resolution_status IN (1,2) ORDER BY i.id DESC LIMIT ?
    `,[Number(limit)]); return rows;
  }
  async repairPreview(itemId){
    const [rows]=await this.pool.query(`
      SELECT i.*,r.run_no,r.reconciliation_type,ra.repair_code,ra.risk_level,ra.proposed_value,ra.proposed_payload_json,ra.status AS repair_status
      FROM cex_audit_reconciliation_items i JOIN cex_audit_reconciliation_runs r ON r.id=i.run_id
      LEFT JOIN cex_audit_reconciliation_repair_actions ra ON ra.item_id=i.id WHERE i.id=? LIMIT 1
    `,[itemId]);
    if(!rows.length) throw new Error('RECONCILIATION_ITEM_NOT_FOUND');
    return {...rows[0],apply_enabled:false,notice:'Admin correction execution is intentionally NOT implemented in v1. Only repair proposals are stored.'};
  }
}

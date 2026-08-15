import { config } from './config.js';
import { createDb } from './db.js';
import { createLogger } from './logger.js';
import { LedgerReconciler } from './reconciliation.js';

const pool=createDb(config.db); const logger=createLogger(config.logs.level); const reconciler=new LedgerReconciler({pool,config,logger});
function arg(name,fallback=''){const p=`--${name}=`;const hit=process.argv.find(x=>String(x).startsWith(p));return hit?String(hit).slice(p.length):fallback;}
const command=String(process.argv[2]||'doctor');
try{
  if(command==='doctor'){const r=await reconciler.doctor();console.log(JSON.stringify(r,null,2));if(!r.ok)process.exitCode=2;}
  else if(command==='manual'){const r=await reconciler.runManual();console.log(JSON.stringify(r,null,2));if(!r.ok)process.exitCode=2;}
  else if(command==='daily-now'){const r=await reconciler.runDaily('manual-daily-test');console.log(JSON.stringify(r,null,2));if(!r.ok)process.exitCode=2;}
  else if(command==='issues'){const rows=await reconciler.listOpenIssues(Number(arg('limit','100')));console.table(rows);}
  else if(command==='repair-preview'){const id=Number(arg('item-id'));if(!Number.isInteger(id)||id<=0)throw new Error('Usage: node src/cli.js repair-preview --item-id=123');console.log(JSON.stringify(await reconciler.repairPreview(id),null,2));}
  else throw new Error(`Unknown command: ${command}`);
}catch(e){console.error(e.stack||e.message);process.exitCode=1;}finally{await pool.end();}

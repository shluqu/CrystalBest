import { config } from './config.js';
import { createDb } from './db.js';
import { createLogger } from './logger.js';
import { DailyScheduler, localDateKey } from './scheduler.js';
import { LedgerReconciler } from './reconciliation.js';

const logger=createLogger(config.logs.level);
const pool=createDb(config.db);
const reconciler=new LedgerReconciler({pool,config,logger});
let scheduler;
let stopping=false;

async function main(){
  const doctor=await reconciler.doctor();
  if(!doctor.ok) throw new Error(`RECONCILER_DOCTOR_FAILED:${JSON.stringify(doctor)}`);
  logger.info({version:config.version,schedule:{hour:config.schedule.hour,minute:config.schedule.minute,tz_offset_minutes:config.schedule.tzOffsetMinutes}},'Ledger reconciler started');
  scheduler=new DailyScheduler({
    schedule:config.schedule,logger,
    shouldCatchUp:()=>reconciler.shouldCatchUpDaily(localDateKey,config.schedule.tzOffsetMinutes),
    run:(trigger)=>reconciler.runDaily(trigger)
  });
  await scheduler.start();
}
async function shutdown(signal,code=0){if(stopping)return;stopping=true;logger.info({signal},'Stopping ledger reconciler');try{scheduler?.stop();await pool.end();}catch(e){logger.error({err:e.message},'Shutdown error');code=1;}finally{process.exit(code);}}
process.on('SIGINT',()=>shutdown('SIGINT'));
process.on('SIGTERM',()=>shutdown('SIGTERM'));
process.on('uncaughtException',(e)=>{logger.error({err:e.message,stack:e.stack},'Uncaught exception');shutdown('uncaughtException',1);});
process.on('unhandledRejection',(e)=>{logger.error({err:e?.message||String(e),stack:e?.stack},'Unhandled rejection');shutdown('unhandledRejection',1);});
main().catch((e)=>{logger.error({err:e.message,stack:e.stack},'Ledger reconciler startup failed');shutdown('startup',1);});

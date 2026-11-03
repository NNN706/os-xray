import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile,writeFile,mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFileSync} from 'node:child_process';
import {php} from './helpers.mjs';
test('Heartbeat worker waits across cron boundary instead of doubling 60 second interval',async()=>{
 const dir=await mkdtemp(join(tmpdir(),'xray-heartbeat-clock-'));
 const id='11111111-1111-4111-8111-111111111111';
 try{
  await writeFile(dir+'/active.json',JSON.stringify({inst_uuid:id,health_enabled:'1',health_interval:'60',health_bypass:'0',routing_mode:'off',tun_iface:'tun0',socks:{host:'127.0.0.1',port:10808}}));
  const {mkdir}=await import('node:fs/promises');await mkdir(dir+'/health');
  await writeFile(dir+'/health/'+id+'.json',JSON.stringify({checked_at:1,successes:3,desired:'proxy',route:'proxy',history:[]}));
  await writeFile(dir+'/config.inc',`<?php
namespace OPNsense\\Core { class Config {static function getInstance(){return new self;}function object(){return simplexml_load_string('<root><OPNsense><xray><general><enabled>1</enabled></general></xray></OPNsense></root>');}}}
namespace {class LabClock {static $now=60;static function time(){return (int)self::$now;}static function mono(){return (int)(self::$now*1e9);}static function sleep(){self::$now+=0.25;}}}
`);
  await writeFile(dir+'/xray-probe.inc.php',`<?php require_once ${JSON.stringify(resolve('plugin/scripts/Xray/xray-config.inc.php'))}; class XrayProbe {static function run($socks){LabClock::$now+=1;return ['result'=>'ok','http_code'=>204,'latency_ms'=>1000,'timed_out'=>false];}}`);
  await writeFile(dir+'/xray-process.inc.php','<?php class XrayProcess {static function alive(...$args){return true;}}');
  await writeFile(dir+'/xray-health.inc.php',await readFile('plugin/scripts/Xray/xray-health.inc.php'));
  await writeFile(dir+'/xray-routing.inc.php','<?php class XrayRouting {}');
  let worker=await readFile('plugin/scripts/Xray/xray-heartbeat.php','utf8');
  worker=worker.replace("require_once 'config.inc';","require_once __DIR__ . '/config.inc';").replace("const HEALTH_DIR = '/var/run/xray-health';",'const HEALTH_DIR = '+JSON.stringify(dir+'/health')+';').replace("'/usr/local/etc/xray-core/active.json'",JSON.stringify(dir+'/active.json')).replace("'/var/run/xray-transition.lock'",JSON.stringify(dir+'/transition.lock'));
  worker=worker.replaceAll('hrtime(true)','LabClock::mono()').replaceAll('time()','LabClock::time()').replace('usleep(200000);','LabClock::sleep();');
  worker=worker.replace("exec('/sbin/ifconfig ' . escapeshellarg($active['tun_iface']) . ' 2>/dev/null', $ifout, $ifrc);","$ifout=['tun0: flags=8051<UP,POINTOPOINT,RUNNING>']; $ifrc=0;");
  await writeFile(dir+'/worker.php',worker);
  assert.match(execFileSync(php,[dir+'/worker.php'],{encoding:'utf8'}),/OK/);
  const result=JSON.parse(await readFile(dir+'/health/'+id+'.json','utf8'));
  assert.equal(result.checked_at,62,'request begins at due time 61 and completes at 62');
  assert.equal(result.healthy,true);assert.equal(result.route,'unmanaged');assert.equal(result.probe.http_code,204);
 }finally{await rm(dir,{recursive:true,force:true});}
});

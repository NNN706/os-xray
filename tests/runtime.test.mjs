/* Runs the real PHP lifecycle/store code with a deterministic process/interface adapter.
   This is a transaction simulation, not a FreeBSD TUN integration test. */
import test from 'node:test';
import {existsSync} from 'node:fs';
import assert from 'node:assert/strict';
import {readFile,writeFile,mkdtemp,rm,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {spawn} from 'node:child_process';
import {php} from './helpers.mjs';
const id='11111111-1111-4111-8111-111111111111';
const run=(file,args=[])=>new Promise((ok,bad)=>{const p=spawn(php,[file,...args]);let out='',err='';p.stdout.on('data',b=>out+=b);p.stderr.on('data',b=>err+=b);p.on('error',bad);p.on('close',code=>ok({code,out,err}));});
const xml=s=>s.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
function replaceFunction(src,name,body){const start=src.indexOf('function '+name+'('),end=src.indexOf('\nfunction ',start+1);assert.ok(start>=0&&end>start);return src.slice(0,start)+body+'\n'+src.slice(end);}
test('Lifecycle rollback and prepared/applied separation (platform simulation)',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'os-xray-runtime-'));
 try {
  for(const name of ['conf','run','t2s','bin','profiles'])await mkdir(dir+'/'+name);
  const libs=['xray-config.inc.php','xray-store.inc.php','xray-subscription.inc.php','xray-process.inc.php','xray-health.inc.php','xray-routing.inc.php'];
  for(const lib of libs){let s=await readFile('plugin/scripts/Xray/'+lib,'utf8');s=s.replace("const DIR = '/usr/local/etc/xray-core/routing';",'const DIR = '+JSON.stringify(dir+'/routing')+';').replace("const DIR = '/usr/local/etc/xray-core/profiles';",'const DIR = '+JSON.stringify(dir+'/profiles')+';').replaceAll("'/usr/local/bin/xray-core'",JSON.stringify(dir+'/bin/xray-core')).replaceAll("'/usr/local/bin/curl'",JSON.stringify(dir+'/bin/curl'));await writeFile(dir+'/'+lib,s);}
  await writeFile(dir+'/config.inc',`<?php namespace OPNsense\\Core; final class Config {private static $i;private $root;static function getInstance(){return self::$i??=new self();}function object(){return $this->root??=$this->load();}function load(){return simplexml_load_file(__DIR__.'/config.xml');}function lock(){ $this->root=$this->load(); }function unlock(){}function save(){file_put_contents(__DIR__.'/config.xml',$this->root->asXML());}}`);
  await writeFile(dir+'/bin/xray-core','#!'+php+'\n<?php $c=json_decode(file_get_contents(end($argv)));exit(($c->remarks??"")==="reject-core"?1:0);',{mode:0o700});
  await writeFile(dir+'/bin/tun2socks','#!/bin/sh\nexit 0\n',{mode:0o700});
  await writeFile(dir+'/bin/curl','#!'+php+'\n<?php $a=$argv;file_put_contents($a[array_search("--dump-header",$a)+1],"HTTP/2 200\\r\\nprofile-update-interval: 1\\r\\nping-type: proxy-head\\r\\n\\r\\n");file_put_contents($a[array_search("--output",$a)+1],file_get_contents(__DIR__."/../remote.json"));',{mode:0o700});
  let source=await readFile('plugin/scripts/Xray/xray-service-control.php','utf8');
  source=source.replace("require_once 'config.inc';","require_once __DIR__ . '/config.inc';\nrequire_once __DIR__ . '/platform.php';");
  for(const [from,to] of [['/usr/local/bin/xray-core',dir+'/bin/xray-core'],['/usr/local/tun2socks/tun2socks',dir+'/bin/tun2socks'],['/usr/local/etc/xray-core',dir+'/conf'],['/usr/local/tun2socks',dir+'/t2s'],['/var/run/',dir+'/run/']])source=source.replaceAll(from,to);
  source=replaceFunction(source,'proc_is_running',`function proc_is_running(string $path): bool { return is_file($path); }`);
  source=replaceFunction(source,'proc_kill',`function proc_kill(string $path): void { @unlink($path); }`);
  source=replaceFunction(source,'proc_start',`function proc_start(string $bin,string $args,string $pidfile,string $logfile=''): void {
    $id='${id}';$config=json_decode(file_get_contents(xray_conf_path($id)));
    file_put_contents(__DIR__.'/commands',basename($bin).' '.$args."\\n",FILE_APPEND);
    if (basename($bin)==='xray-core' && ($config->remarks??'')==='fail-start') {return;}
    file_put_contents($pidfile,bin2hex(random_bytes(8)));
  }`);
  source=source.replaceAll('exec(', 'test_exec(').replaceAll('stream_socket_client(', 'test_socket_client(').replaceAll('stream_socket_server(', 'test_socket_server(');
  await writeFile(dir+'/control.php',source);
  await writeFile(dir+'/platform.php',`<?php
function test_exec($command, &$output=null, &$code=null) {
 $output=[];$code=0;
 if(strpos($command,'ifconfig')!==false && strpos($command,'destroy')===false) {$code=is_file(__DIR__.'/run/tun2socks_${id}.pid')?0:1;}
}
function test_socket_server($address,&$errno,&$error) {return fopen('php://memory','r+');}
function test_socket_client($address,&$errno,&$error,$timeout) {$errno=0;$error='';return is_file(__DIR__.'/run/xray_core_${id}.pid')?fopen('php://memory','r+'):false;}
`);
  const fixture=JSON.parse(await readFile('tests/full-config.json','utf8'));
  async function config(value,extra='') {await writeFile(dir+'/config.xml',`<opnsense><OPNsense><xray><general><enabled>1</enabled></general><instances><instance uuid="${id}"><name>Fixture</name><enabled>1</enabled><config_json>${xml(JSON.stringify(value))}</config_json><use_config_dns>1</use_config_dns><socks_inbound></socks_inbound><tun_interface>tun9</tun_interface><mtu>1500</mtu><auto_update>1</auto_update><update_mode>manual</update_mode><update_hours>1</update_hours><subscription_url>https://example.test/secret</subscription_url><subscription_fetched>1</subscription_fetched>${extra}</instance></instances></xray></OPNsense></opnsense>`);}
  const runtime=()=>readFile(dir+'/conf/config-'+id+'.json','utf8'),pid=()=>readFile(dir+'/run/xray_core_'+id+'.pid','utf8');
  await config(fixture);
  await t.test('initial apply and correct tun2socks argument',async()=>{const r=await run(dir+'/control.php',['start',id]);assert.equal(r.code,0,r.out+r.err);assert.match(await readFile(dir+'/commands','utf8'),/tun2socks --config/);assert.equal(JSON.parse(await runtime()).routing.balancers[0].strategy.settings.expected,2);});
  const original=await runtime(),oldPid=await pid();
  await t.test('invalid core configuration leaves active PID/file untouched',async()=>{await config({...fixture,remarks:'reject-core'});const r=await run(dir+'/control.php',['reconfigure',id]);assert.equal(r.code,1,r.out);assert.equal(await runtime(),original);assert.equal(await pid(),oldPid);});
  await t.test('failed readiness restores previous configuration and starts it',async()=>{await config({...fixture,remarks:'fail-start'});const r=await run(dir+'/control.php',['restart',id]);assert.equal(r.code,1,r.out);assert.equal(await runtime(),original);assert.ok(await pid());assert.equal(JSON.parse(await readFile(dir+'/conf/active.json','utf8')).config_json,JSON.stringify(fixture));});
  await config(fixture);
  await t.test('scheduled update changes draft but neither PID nor applied file',async()=>{
   const before=await pid();await writeFile(dir+'/remote.json',JSON.stringify({...fixture,remarks:'Downloaded'}));
   await writeFile(dir+'/update.php',"<?php require __DIR__.'/config.inc';require __DIR__.'/xray-store.inc.php';foreach(XrayStore::profiles() as $c){XrayStore::update($c);}");
   const r=await run(dir+'/update.php');assert.equal(r.code,0,r.err);assert.equal(await pid(),before);assert.equal(await runtime(),original);
   const draft=JSON.parse(await readFile(dir+'/profiles/'+id+'-prepared.json','utf8'));assert.equal(JSON.parse(draft.config_json).remarks,'Downloaded');
  });
  await t.test('watchdog recovery uses applied snapshot, never downloaded draft',async()=>{await rm(dir+'/run/xray_core_'+id+'.pid');const r=await run(dir+'/control.php',['recover',id]);assert.equal(r.code,0,r.out+r.err);assert.equal(await runtime(),original);});
  await t.test('manual reconnect applies downloaded draft',async()=>{const r=await run(dir+'/control.php',['restart',id]);assert.equal(r.code,0,r.out+r.err);assert.equal(JSON.parse(await runtime()).remarks,'Downloaded');});
  await t.test('failed subscription core validation retains both versions',async()=>{
   const path=dir+'/profiles/'+id+'-prepared.json';let draft=JSON.parse(await readFile(path,'utf8'));draft.fetched_at=1;await writeFile(path,JSON.stringify(draft));const before=await readFile(path,'utf8'),applied=await runtime(),p=await pid();
   await writeFile(dir+'/remote.json',JSON.stringify({...fixture,remarks:'reject-core'}));await run(dir+'/update.php');assert.equal(await readFile(path,'utf8'),before);assert.equal(await runtime(),applied);assert.equal(await pid(),p);
  });
  await t.test('durable journal restores an interrupted transition',async()=>{
   const old=JSON.parse(await readFile(dir+'/conf/active.json','utf8')),next={...old,config_json:JSON.stringify({...fixture,remarks:'Interrupted'}),effective_json:JSON.stringify({...fixture,remarks:'Interrupted'})};
   await writeFile(dir+'/conf/transition.json',JSON.stringify({old,next,was_running:true}));await writeFile(dir+'/conf/active.json',JSON.stringify(next));await writeFile(dir+'/conf/config-'+id+'.json',next.effective_json);
   const r=await run(dir+'/control.php',['recover',id]);assert.equal(r.code,0,r.out+r.err);assert.equal(await runtime(),old.effective_json);assert.equal(JSON.parse(await readFile(dir+'/conf/active.json','utf8')).config_json,old.config_json);await assert.rejects(readFile(dir+'/conf/transition.json'));
  });
  await t.test('Start/Stop/Apply share one nonblocking lifecycle lock',async()=>{
   const before=await pid();
   const holder=spawn(php,['-r',' $fd=fopen($argv[1],"c");flock($fd,LOCK_EX);echo "ready\\n";fflush(STDOUT);sleep(10);',dir+'/run/xray-transition.lock']);
   try{await new Promise((ok,bad)=>{holder.stdout.once('data',ok);holder.once('error',bad);});for(const action of ['start','stop','reconfigure']){const r=await run(dir+'/control.php',[action,id]);assert.equal(r.code,1,r.out);assert.match(r.out,/transition is in progress/);assert.equal(await pid(),before);}}finally{holder.kill('SIGTERM');}
  });
  await t.test('stop prevents watchdog resurrection; boot uses applied snapshot',async()=>{let r=await run(dir+'/control.php',['stop',id]);assert.equal(r.code,0);r=await run(dir+'/control.php',['recover',id]);assert.equal(r.code,0);await assert.rejects(pid());r=await run(dir+'/control.php',['boot']);assert.equal(r.code,0,r.out+r.err);assert.equal(JSON.parse(await runtime()).remarks,'Downloaded');});
 }finally{await rm(dir,{recursive:true,force:true});}
});

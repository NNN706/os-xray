import test from 'node:test';
import {existsSync} from 'node:fs';
import assert from 'node:assert/strict';
import {createServer} from 'node:https';
import {readFile,writeFile,mkdtemp,rm,mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {spawn,execFileSync} from 'node:child_process';
const curl=process.env.CURL_BIN || ['/usr/local/bin/curl','/usr/local/opt/curl/bin/curl','/usr/bin/curl'].find(existsSync);
import {php} from './helpers.mjs';
const fixture=await readFile('tests/full-config.json','utf8');
const run=(file,args=[])=>new Promise((ok,bad)=>{const p=spawn(php,[file,...args]);let stdout='',stderr='';p.stdout.on('data',b=>stdout+=b);p.stderr.on('data',b=>stderr+=b);p.on('error',bad);p.on('close',code=>ok({code,stdout,stderr}));});

test('Subscription HTTPS transport and total deadline',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'os-xray-net-')); let server;
 try {
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',dir+'/key','-out',dir+'/cert','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1'],{stdio:'ignore'});
  const seen=[];
  server=createServer({key:await readFile(dir+'/key'),cert:await readFile(dir+'/cert')},(req,res)=>{
   seen.push({path:req.url,ua:req.headers['user-agent']});
   if(req.url==='/redirect'){setTimeout(()=>{res.writeHead(302,{location:'/slow-body'}).end();},1200);return;}
   if(req.url==='/error'){res.writeHead(503).end();return;}
   if(req.url==='/invalid'){res.end('{broken');return;}
   if(req.url==='/slow-body'){res.writeHead(200,{'profile-update-interval':'1'});res.flushHeaders();setTimeout(()=>res.end(fixture),1200);return;}
   if(req.url==='/slow'){setTimeout(()=>res.end(fixture),2500);return;}
   res.writeHead(200,{'profile-update-interval':'3','ping-type':'proxy','check-url-via-proxy':'https://example.test/check'});res.end(req.url==='/array'?'['+fixture+','+fixture.replace('Balanced example','Other')+']':fixture);
  });
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  await writeFile(dir+'/curl','#!/bin/sh\nexec "'+curl+'" --cacert "'+dir+'/cert" "$@"\n',{mode:0o700});
  const src=await readFile('plugin/scripts/Xray/xray-subscription.inc.php','utf8');
  await writeFile(dir+'/xray-subscription.inc.php',src.replace("'/usr/local/bin/curl'",JSON.stringify(dir+'/curl')));
  await writeFile(dir+'/xray-config.inc.php',await readFile('plugin/scripts/Xray/xray-config.inc.php'));
  await writeFile(dir+'/request.php','<?php require __DIR__."/xray-subscription.inc.php";try { echo json_encode(XraySubscription::fetch($argv[1])); } catch(Throwable $e) { echo json_encode(["error"=>$e->getMessage()]);exit(1); }');
  const url='https://127.0.0.1:'+server.address().port;
  await t.test('exact UA, object, metadata and multi-config array',async()=>{
   const response=await run(dir+'/request.php',[url+'/object']);assert.equal(response.code,0,response.stderr);const data=JSON.parse(response.stdout);
   assert.equal(data.configurations.length,1);assert.equal(data.metadata.interval_hours,3);assert.equal(data.metadata.ping_type,'proxy');assert.equal(data.metadata.check_url,'https://example.test/check');
   const array=await run(dir+'/request.php',[url+'/array']);assert.equal(JSON.parse(array.stdout).configurations.length,2);assert.ok(seen.every(r=>r.ua==='OPNSense'));
  });
  await t.test('HTTP and malformed JSON errors',async()=>{for(const path of ['/error','/invalid']){const r=await run(dir+'/request.php',[url+path]);assert.equal(r.code,1);assert.ok(JSON.parse(r.stdout).error);}});
  await t.test('2000 ms total includes redirects and body',async()=>{for(const path of ['/slow','/redirect']){const start=Date.now(),r=await run(dir+'/request.php',[url+path]);assert.equal(r.code,1);assert.match(r.stdout,/2000 ms/);assert.ok(Date.now()-start<2450, 'Request exceeded total deadline tolerance');}});
  await t.test('TLS peer verification remains enabled',async()=>{
   await writeFile(dir+'/curl','#!/bin/sh\nexec "'+curl+'" "$@"\n',{mode:0o700});const r=await run(dir+'/request.php',[url+'/object']);assert.equal(r.code,1);assert.match(r.stdout,/TLS/);
  });
 } finally {if(server) {server.closeAllConnections();await new Promise(r=>server.close(r));}await rm(dir,{recursive:true,force:true});}
});

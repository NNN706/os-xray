import test from 'node:test';
import {existsSync} from 'node:fs';
import assert from 'node:assert/strict';
import https from 'node:https';
import net from 'node:net';
import {readFile,writeFile,mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {spawn,execFileSync} from 'node:child_process';
const curl=process.env.CURL_BIN || ['/usr/local/bin/curl','/usr/local/opt/curl/bin/curl','/usr/bin/curl'].find(existsSync);
import {php} from './helpers.mjs';
const run=file=>new Promise((ok,bad)=>{const p=spawn(php,[file]);let out='',err='';p.stdout.on('data',b=>out+=b);p.stderr.on('data',b=>err+=b);p.on('error',bad);p.on('close',code=>{try{ok({code,data:JSON.parse(out),err});}catch(e){bad(new Error(out+' '+err));}});});
test('Test through SOCKS with remote DNS, HEAD/GET, HTTP and timeout',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'os-xray-ping-')), sockets=new Set(), methods=[],names=[];let server,proxy;
 const track=s=>{sockets.add(s);s.on('error',()=>{});s.on('close',()=>sockets.delete(s));return s;};
 try {
  execFileSync(curl,['--disable','--version'],{stdio:'ignore'});
  execFileSync('openssl',['req','-x509','-newkey','rsa:2048','-nodes','-keyout',dir+'/key','-out',dir+'/cert','-days','1','-subj','/CN=latency.test','-addext','subjectAltName=DNS:latency.test'],{stdio:'ignore'});
  server=https.createServer({key:await readFile(dir+'/key'),cert:await readFile(dir+'/cert')},(req,res)=>{
   methods.push(req.method);const code=req.url==='/204'?204:req.url==='/503'?503:200;
   setTimeout(()=>{res.writeHead(code);res.end(req.method==='HEAD'?'':'ok');},req.url==='/slow'?2600:65);
  });server.on('connection',track);await new Promise(r=>server.listen(0,'127.0.0.1',r));
  proxy=net.createServer(socket=>{
   track(socket);let stage=0,buf=Buffer.alloc(0);
   function consume(data){buf=Buffer.concat([buf,data]);
    if(stage===0){if(buf.length<2 || buf.length<2+buf[1])return;buf=buf.subarray(2+buf[1]);socket.write(Buffer.from([5,0]));stage=1;}
    if(stage===1){if(buf.length<5)return;assert.equal(buf[3],3,'curl must send domain to SOCKS');const len=buf[4];if(buf.length<7+len)return;
     names.push(buf.subarray(5,5+len).toString());const rest=buf.subarray(7+len);socket.off('data',consume);stage=2;
     const upstream=track(net.connect(server.address().port,'127.0.0.1',()=>{socket.write(Buffer.from([5,0,0,1,127,0,0,1,0,0]));if(rest.length)upstream.write(rest);socket.pipe(upstream);upstream.pipe(socket);}));socket.on('close',()=>upstream.destroy());upstream.on('close',()=>socket.destroy());
    }
   }socket.on('data',consume);
  });await new Promise(r=>proxy.listen(0,'127.0.0.1',r));
  for(const lib of ['xray-config.inc.php','xray-store.inc.php','xray-subscription.inc.php','xray-probe.inc.php'])await writeFile(dir+'/'+lib,(await readFile('plugin/scripts/Xray/'+lib,'utf8')).replace("'/usr/local/bin/curl'",JSON.stringify(dir+'/curl')));
  await writeFile(dir+'/config.inc','<?php');
  await writeFile(dir+'/xray-process.inc.php','<?php final class XrayProcess { static function alive(...$args) {return true;} }');
  await writeFile(dir+'/curl','#!/bin/sh\nexec "'+curl+'" --cacert "'+dir+'/cert" "$@"\n',{mode:0o700});
  let src=await readFile('plugin/scripts/Xray/xray-testconnect.php','utf8');src=src.replace("require_once 'config.inc'",'require_once __DIR__ . "/config.inc"').replace("'/usr/local/etc/xray-core/active.json'",JSON.stringify(dir+'/active.json')).replace("'/usr/local/bin/curl'",JSON.stringify(dir+'/curl'));
  await writeFile(dir+'/test.php',src);
  async function check(path,type){await writeFile(dir+'/active.json',JSON.stringify({inst_uuid:'11111111-1111-4111-8111-111111111111',socks:{host:'127.0.0.1',port:proxy.address().port},test_metadata:{ping_type:type,check_url:'https://latency.test:'+server.address().port+path}}));return (await run(dir+'/test.php')).data;}
  await t.test('HEAD / 200 reports TTFB',async()=>{const r=await check('/200','proxy-head');assert.equal(r.result,'ok');assert.equal(r.http_code,200);assert.ok(r.latency_ms>=50 && r.latency_ms<1000);assert.equal(r.timed_out,false);assert.match(r.message,/HTTP latency: \d+ ms/);assert.equal(methods.at(-1),'HEAD');});
  await t.test('GET / 204 succeeds',async()=>{const r=await check('/204','proxy');assert.equal(r.result,'ok');assert.equal(r.http_code,204);assert.equal(methods.at(-1),'GET');});
  await t.test('HTTP 503 reports failure',async()=>{const r=await check('/503','proxy-head');assert.equal(r.result,'failed');assert.equal(r.http_code,503);});
  await t.test('1000 ms timeout has no fabricated latency',async()=>{const start=Date.now(),r=await check('/slow','proxy-head');assert.equal(r.result,'failed');assert.equal(r.timed_out,true);assert.equal(r.latency_ms,null);assert.ok(Date.now()-start<1400);assert.match(r.message,/Timeout \(1000 ms\)/);});
  await t.test('Heartbeat uses same SOCKS, exact 204 and 2000 ms deadline',async()=>{
   const call=async(path)=>{await writeFile(dir+'/heartbeat-probe.php','<?php require __DIR__."/xray-probe.inc.php"; echo json_encode(XrayProbe::run('+JSON.stringify({host:'127.0.0.1',port:proxy.address().port}).replace(/"([^"]+)":/g,'"$1"=>').replace('{','[').replace('}',']')+', '+JSON.stringify('https://latency.test:'+server.address().port+path)+'));');return (await run(dir+'/heartbeat-probe.php')).data;};
   assert.equal((await call('/204')).result,'ok');
   assert.equal((await call('/200')).result,'failed','heartbeat must require 204');
   const start=Date.now(),r=await call('/slow');assert.equal(r.timed_out,true);assert.equal(r.latency_ms,null);assert.ok(Date.now()-start<2400);assert.match(r.message,/2000 ms/);
  });
  assert.ok(names.length>=4 && names.every(n=>n==='latency.test'));
 }finally{for(const s of sockets)s.destroy();if(proxy)await new Promise(r=>proxy.close(r));if(server){server.closeAllConnections();await new Promise(r=>server.close(r));}await rm(dir,{recursive:true,force:true});}
});

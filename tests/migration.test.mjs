import test from 'node:test';
import {existsSync} from 'node:fs';
import assert from 'node:assert/strict';
import {readFile,writeFile,mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {spawn} from 'node:child_process';
import {php} from './helpers.mjs';
const id='11111111-1111-4111-8111-111111111111';
const run=file=>new Promise((ok,bad)=>{const p=spawn(php,[file]);let out='',err='';p.stdout.on('data',b=>out+=b);p.stderr.on('data',b=>err+=b);p.on('error',bad);p.on('close',code=>ok({code,out,err}));});
test('Migration validates before saving and retains legacy source',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'os-xray-migrate-'));
 try{
  const config=await readFile('plugin/scripts/Xray/xray-config.inc.php','utf8');
  await writeFile(dir+'/core','#!'+php+'\n<?php $v=json_decode(file_get_contents(end($argv)));exit(($v->outbounds[0]->protocol??"")==="invalid"?1:0);',{mode:0o700});
  await writeFile(dir+'/xray-config.inc.php',config.replace("'/usr/local/bin/xray-core'",JSON.stringify(dir+'/core')));
  await writeFile(dir+'/config.inc',`<?php namespace OPNsense\\Core;final class Config{private static $i;private $c;static function getInstance(){return self::$i??=new self();}function lock(){}function object(){return $this->c??=simplexml_load_file(__DIR__.'/config.xml');}function save(){file_put_contents(__DIR__.'/config.xml',$this->c->asXML());}}`);
  const src=(await readFile('plugin/scripts/Xray/xray-migrate.php','utf8')).replace("require_once 'config.inc'","require_once __DIR__ . '/config.inc'").replace("'/usr/local/etc/xray-core/config.json'",JSON.stringify(dir+'/no-file'));
  await writeFile(dir+'/migrate.php',src);
  const legacy='<enabled>1</enabled><name>Legacy</name><outbound_config>{"tag":"proxy","protocol":"freedom","settings":{}}</outbound_config><socks5_listen>127.0.0.1</socks5_listen><socks5_port>10808</socks5_port><bypass_networks>10.0.0.0/8,192.168.0.0/16</bypass_networks><tun_interface>tun9</tun_interface><mtu>1400</mtu>';
  await t.test('outbound + bypass conversion and idempotence',async()=>{
   await writeFile(dir+'/config.xml',`<opnsense><OPNsense><xray><instances><instance uuid="${id}">${legacy}</instance></instances></xray></OPNsense></opnsense>`);
   let r=await run(dir+'/migrate.php');assert.equal(r.code,0,r.err);const after=await readFile(dir+'/config.xml','utf8');assert.match(after,/<outbound_config>/);assert.match(after,/<config_json>/);assert.match(after,/socks-in/);assert.match(after,/192.168.0.0\/16/);
   r=await run(dir+'/migrate.php');assert.equal(r.code,0);assert.equal(await readFile(dir+'/config.xml','utf8'),after);
  });
  await t.test('one invalid conversion leaves all source bytes unchanged',async()=>{
   const invalid=`<opnsense><OPNsense><xray><instances><instance uuid="${id}">${legacy}</instance><instance uuid="22222222-2222-4222-8222-222222222222">${legacy.replace('"freedom"','"invalid"')}</instance></instances></xray></OPNsense></opnsense>`;
   await writeFile(dir+'/config.xml',invalid);const r=await run(dir+'/migrate.php');assert.equal(r.code,1);assert.equal(await readFile(dir+'/config.xml','utf8'),invalid);
  });
  await t.test('single-instance migration retains old node and is not duplicated',async()=>{
   await writeFile(dir+'/config.xml',`<opnsense><OPNsense><xray><instance>${legacy}</instance></xray></OPNsense></opnsense>`);let r=await run(dir+'/migrate.php');assert.equal(r.code,0,r.err);const after=await readFile(dir+'/config.xml','utf8');assert.match(after,/<instance>/);assert.match(after,/uuid="[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}"/);assert.equal((after.match(/uuid="/g)||[]).length,1);r=await run(dir+'/migrate.php');assert.equal(r.code,0);assert.equal(await readFile(dir+'/config.xml','utf8'),after);
  });
 }finally{await rm(dir,{recursive:true,force:true});}
});

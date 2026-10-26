import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile,writeFile,mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {execFileSync} from 'node:child_process';
import {php} from './helpers.mjs';
test('Managed firewall transaction and rollback with OPNsense adapter',async()=>{
 const dir=await mkdtemp(join(tmpdir(),'xray-policy-'));
 try {
  let src=await readFile('plugin/scripts/Xray/xray-routing.inc.php','utf8');
  src=src.replace("require_once __DIR__ . '/xray-subscription.inc.php';",'require_once '+JSON.stringify(resolve('plugin/scripts/Xray/xray-subscription.inc.php'))+';').replace("const DIR = '/usr/local/etc/xray-core/routing';",'const DIR = '+JSON.stringify(dir)+';');
  src=src.replace("exec('/usr/local/sbin/configctl filter reload >/dev/null 2>&1', $out, $rc);",'$rc = \\Lab::$failReload-- > 0 ? 1 : 0;');
  src=src.replace("exec('/sbin/pfctl -k label -k ' . escapeshellarg($label) . ' >/dev/null 2>&1', $unused, $rc);",'\\Lab::$killed[] = $label; $rc = 0;');
  await writeFile(dir+'/routing.php',src);
  await writeFile(dir+'/run.php',`<?php
namespace OPNsense\\Core { final class Config { static $instance; public $xml; public $locked=false; static function getInstance(){return self::$instance;} function object(){return $this->xml;} function lock(){if($this->locked)throw new \\Exception('double lock');$this->locked=true;} function unlock(){$this->locked=false;} function save(){if(!$this->locked)throw new \\Exception('save without lock');} function toArray($unused,$node){$result=[];foreach($node->attributes() as $k=>$v)$result['@attributes'][$k]=(string)$v;foreach($node->children() as $k=>$v)$result[$k]=$v->count()?$this->toArray(null,$v):(string)$v;return $result;} } }
namespace OPNsense\\Routing { final class Gateways { function gatewaysIndexedByName(...$args){return ['TUN'=>['if'=>'tun0','ipprotocol'=>'inet'],'WAN'=>['if'=>'igc0','ipprotocol'=>'inet','force_down'=>'0']];} } }
namespace OPNsense\\Firewall { final class Util { static function calcRuleHash($rule){unset($rule['descr']);return md5(json_encode($rule));} } }
namespace { final class Lab {static $failReload=0;static $killed=[];} require __DIR__.'/routing.php';
function ok($v,$name){if(!$v)throw new \\Exception($name);echo "PASS $name\\n";}
$c=new \\OPNsense\\Core\\Config();\\OPNsense\\Core\\Config::$instance=$c;
$c->xml=simplexml_load_string('<root><interfaces><lan><if>igc1</if></lan></interfaces><gateways><untouched>original</untouched></gateways><filter><rule><tracker>12345</tracker><descr>[os-xray] test</descr><type>pass</type><interface>lan</interface><ipprotocol>inet</ipprotocol><gateway>TUN</gateway><source><network>lan</network></source><destination><any/></destination></rule><rule><tracker>67890</tracker><descr>foreign</descr><gateway>OTHER</gateway><destination><any/></destination></rule></filter><OPNsense><Firewall><Alias><aliases/></Alias></Firewall></OPNsense></root>');
$p=['routing_mode'=>'networks','routing_rules'=>'tracker:12345','routing_tun4'=>'TUN','routing_wan4'=>'WAN','tun_iface'=>'tun0','routing_alias'=>'XRAY_TEST','routing_prefixes'=>['192.0.2.0/24'],'inst_uuid'=>'11111111-1111-4111-8111-111111111111'];
$foreign=$c->xml->filter->rule[1]->asXML();$gateways=$c->xml->gateways->asXML();
\\XrayRouting::apply($p,'bypass');
ok((string)$c->xml->filter->rule[0]->gateway==='WAN','only enrolled rule switched');
ok((string)$c->xml->filter->rule[0]->destination->address==='XRAY_TEST','destination constrained to own alias');
ok((string)$c->xml->OPNsense->Firewall->Alias->aliases->alias->content==='192.0.2.0/24','ASN CIDR policy lives outside Xray JSON');
ok($foreign===$c->xml->filter->rule[1]->asXML() && $gateways===$c->xml->gateways->asXML(),'foreign rule and all gateways unchanged');
ok(count(Lab::$killed)===2,'only old and new enrolled labels expired');
ok(!file_exists(__DIR__.'/transition.json'),'journal cleaned after success');
$before=$c->xml->asXML();Lab::$failReload=1;
try{\\XrayRouting::apply($p,'proxy');throw new \\Exception('expected failure');}catch(\\RuntimeException $expected){}
ok($before===$c->xml->asXML(),'failed firewall reload restores previous policy');
ok(!$c->locked,'config unlocked after failure');
$r=$c->xml->filter->rule[0];$fields=['gateway'=>(string)$r->gateway,'destination'=>$r->destination->asXML()];$after=$fields;$after['gateway']='TUN';
\\XrayConfig::atomic(__DIR__.'/transition.json',\\XrayConfig::encode(['rules'=>['tracker:12345'=>['before'=>$fields,'after'=>$after]]]));
\\XrayRouting::recover();ok($before===$c->xml->asXML(),'journal before config save is recoverable');
\\XrayConfig::atomic(__DIR__.'/transition.json',\\XrayConfig::encode(['rules'=>['tracker:12345'=>['before'=>$fields,'after'=>$after]]]));
$r->gateway='ADMIN_CHANGED';$manual=$c->xml->asXML();
try{\\XrayRouting::recover();throw new \\Exception('expected conflict');}catch(\\RuntimeException $expected){}
ok($manual===$c->xml->asXML(),'manual edit conflicts are never overwritten');unlink(__DIR__.'/transition.json');$r->gateway=$fields['gateway'];
$p['routing_rules']='tracker:67890';try{\\XrayRouting::apply($p,'proxy');throw new \\Exception('expected refusal');}catch(\\RuntimeException $expected){}
ok($before===$c->xml->asXML(),'foreign rule cannot be enrolled without explicit marker');
}
`);
  const output=execFileSync(php,[dir+'/run.php'],{encoding:'utf8'});assert.equal((output.match(/PASS/g)||[]).length,11,output);
 }finally{await rm(dir,{recursive:true,force:true});}
});

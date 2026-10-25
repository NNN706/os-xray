<?php
require_once __DIR__ . '/../plugin/scripts/Xray/xray-health.inc.php';
require_once __DIR__ . '/../plugin/scripts/Xray/xray-routing.inc.php';
$count = 0;
function verify($ok, $label) { global $count; if (!$ok) { throw new RuntimeException($label); } $count++; }
function rejects($fn) { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected rejection'); }
foreach (XrayHealth::INTERVALS as $interval) { verify(XrayHealth::settings(['health_interval'=>(string)$interval])['interval'] === $interval, 'Supported interval'); }
foreach (['15x', '1.5', '0', '-15', '14', '600'] as $interval) { rejects(fn()=>XrayHealth::settings(['health_interval'=>$interval])); }
rejects(fn()=>XrayHealth::settings(['health_bypass'=>'1']));
$good=['result'=>'ok','http_code'=>204,'latency_ms'=>148,'timed_out'=>false];
$bad=['result'=>'failed','http_code'=>0,'latency_ms'=>null,'timed_out'=>true];
$parts=['xray'=>true,'tun2socks'=>true,'tun'=>true];
$state=null;
foreach ([1,2,3] as $n) { $state=XrayHealth::sample($state,$good,$parts,$n); verify($state['desired']===($n===3?'proxy':'bypass'),'Three successes before proxy'); }
$state=XrayHealth::sample($state,$bad,$parts,4); verify($state['desired']==='bypass' && $state['successes']===0,'One failure bypasses immediately');
verify($state['history'][3]['latency_ms']===null && $state['history'][3]['timed_out'],'Timeout carries no invented latency');
$state=XrayHealth::sample($state,$good,$parts,5); $state=XrayHealth::sample($state,$bad,$parts,6);
verify($state['successes']===0,'Intermittent failure resets recovery');
$state=XrayHealth::sample($state,$good,['xray'=>true,'tun2socks'=>true,'tun'=>false],7);
verify(!$state['healthy'] && $state['desired']==='bypass','SOCKS success cannot hide absent TUN');
for($n=8;$n<100;$n++) { $state=XrayHealth::sample($state,$good,$parts,$n); }
verify(count($state['history'])===60 && $state['history'][59]['at']===99,'Bounded chronological history');
verify(XrayRouting::prefix('192.0.2.129/24')==='192.0.2.0/24','IPv4 canonical prefix');
verify(XrayRouting::prefix('2001:db8:1::7/48')==='2001:db8:1::/48','IPv6 canonical prefix');
foreach (['0.0.0.0/0','::/0','1.2.3.4/33','a;id','1.2.3.4/-1'] as $badPrefix) { rejects(fn()=>XrayRouting::prefix($badPrefix)); }
$snapshot=['latest_time'=>'2026-10-26T10:00:00','prefixes'=>[
 ['prefix'=>'192.0.2.0/24','timelines'=>[['starttime'=>'2026-10-25T10:00:00','endtime'=>'2026-10-26T10:00:00']]],
 ['prefix'=>'198.51.100.0/24','timelines'=>[['starttime'=>'2026-10-24T10:00:00','endtime'=>'2026-10-25T10:00:00']]]
]];
verify(XrayRouting::announced($snapshot)===['192.0.2.0/24'],'Withdrawn historical prefixes excluded from ASN snapshot');
rejects(fn()=>XrayRouting::announced([]));
verify(XrayRouting::response(['status'=>'ok','data'=>['resource'=>'64500']+$snapshot],'AS64500')===['192.0.2.0/24'],'RIPE numeric resource matches AS-prefixed input');
rejects(fn()=>XrayRouting::response(['status'=>'ok','data'=>['resource'=>'64501']+$snapshot],'AS64500'));
$lookups=[];
$prefixes=XrayRouting::destinations('192.0.2.1/24,192.0.2.0/24','AS64500 64500',function($asn) use (&$lookups) { $lookups[]=$asn; return ['2001:db8::/32','192.0.2.0/24']; });
verify($prefixes===['192.0.2.0/24','2001:db8::/32'],'ASN and CIDR combined without duplicates');
rejects(fn()=>XrayRouting::destinations('','AS4294967296',fn()=>[]));
rejects(fn()=>XrayRouting::destinations('','AS64500',fn()=>[]));
rejects(fn()=>XrayRouting::destinations('','',fn()=>[]));
$root=simplexml_load_string('<root><filter><rule><tracker>12345</tracker></rule><rule uuid="11111111-1111-4111-8111-111111111111"/></filter></root>');
verify(count(XrayRouting::rules($root))===2,'Classic tracker and UUID rules supported');
verify(XrayRouting::ids('tracker:12345')===['tracker:12345'],'Explicit classic rule identifier');
rejects(fn()=>XrayRouting::ids('tracker:1;id'));
echo "PASS heartbeat / CIDR / ASN policy ($count assertions plus rejection cases)\n";

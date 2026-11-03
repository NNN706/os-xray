<?php
require __DIR__ . '/../plugin/scripts/Xray/xray-store.inc.php';
require __DIR__ . '/../plugin/scripts/Xray/xray-process.inc.php';
require __DIR__ . '/../plugin/etc/inc/plugins.inc.d/xray.inc';
function check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } echo 'PASS ' . $message . "\n"; }
function rejects(callable $function, string $message): void { try { $function(); } catch (Throwable $e) { check(true, $message); return; } throw new RuntimeException('Did not reject: ' . $message); }
$raw = file_get_contents(__DIR__ . '/full-config.json');
$config = XrayConfig::decode($raw);
check(XrayConfig::effective($raw, true) === $raw, 'DNS On retains original JSON bytes');
$off = XrayConfig::decode(XrayConfig::effective($raw, false));
check(!isset($off->dns) && isset($config->dns), 'DNS Off affects only effective copy');
check(XrayConfig::canonical($off->routing) === XrayConfig::canonical($config->routing), 'Routing and expected:2 preserved');
check($off->routing->balancers[0]->strategy->settings->expected === 2 && count($off->outbounds) === 3, 'Multiple outbounds / leastLoad expected:2 preserved');
check($off->inbounds[1]->port === 10809 && $off->inbounds[0]->sniffing->destOverride === ['http','tls','quic'], 'HTTP inbound and sniffing preserved');
check(XrayConfig::socks($config)['port'] === 10808, 'SOCKS selected from JSON');
$config->inbounds[] = clone $config->inbounds[0]; $config->inbounds[2]->tag = 'other'; $config->inbounds[2]->port = 10810;
check(XrayConfig::socks($config)['port'] === 10808, 'tag socks preferred');
$config->inbounds[0]->tag = 'first';
rejects(fn() => XrayConfig::socks($config), 'Ambiguous SOCKS requires selection');
check(XrayConfig::socks($config, 'tag:other')['port'] === 10810, 'Explicit SOCKS choice');
rejects(fn() => XrayConfig::socks($config, 'tag:missing'), 'Stale SOCKS choice rejected');
$config->inbounds = [];
rejects(fn() => XrayConfig::socks($config), 'Missing SOCKS is not injected');
rejects(fn() => XrayConfig::decode('[]'), 'Arrays not silently merged');
rejects(fn() => XrayConfig::decode('{'), 'Invalid JSON rejected');
check(XrayConfig::name((object)['Remarks'=>'Imported'], 'Existing') === 'Imported' && XrayConfig::name((object)[], 'Existing') === 'Existing', 'Remarks aliases and name fallback');
check(XrayConfig::canonical(json_decode('{"b":2,"a":{"z":{},"x":[]}}')) === XrayConfig::canonical(json_decode('{"a":{"x":[],"z":{}},"b":2}')), 'Formatting and object key order ignored');
check(XrayConfig::canonical([1,2]) !== XrayConfig::canonical([2,1]), 'Array order is meaningful');
check(XraySubscription::metadata(['check-url-via-proxy'=>'http://example.test/check'])['check_url'] === 'http://example.test/check', 'HTTP check targets supported independently of HTTPS subscription policy');
$meta = XraySubscription::metadata(['profile-update-interval'=>'1','ping-type'=>'proxy']);
check($meta['interval_hours'] === 1 && $meta['ping_type'] === 'proxy' && $meta['check_url'] === 'https://cp.cloudflare.com/generate_204', 'Supported Happ metadata');
foreach (['0','-1','1.5','abc',''] as $bad) { check(XraySubscription::metadata(['profile-update-interval'=>$bad])['interval_hours'] === null, 'Invalid interval ' . json_encode($bad)); }
$c = ['auto_update'=>'1','subscription_url'=>'https://example.test/profile','update_mode'=>'subscription','update_hours'=>'3','subscription_metadata'=>'{"interval_hours":1}','subscription_fetched'=>'100'];
check(!XrayStore::schedule($c,3699)['due'] && XrayStore::schedule($c,3700)['due'], 'Schedule counts from successful fetch');
$c['subscription_metadata']='{}'; check(!XrayStore::schedule($c,99999)['due'], 'Missing header disables schedule');
$c['update_mode']='manual'; check(XrayStore::schedule($c,10900)['due'], 'Manual interval independent of header');
$c['auto_update']='0'; check(!XrayStore::schedule($c,99999)['due'], 'Off disables schedule');
$legacy = XrayConfig::wrap((object)['tag'=>'proxy','protocol'=>'freedom'], '127.0.0.1',10808,'10.0.0.0/8,192.168.0.0/16');
check($legacy->routing->rules[0]->ip === ['10.0.0.0/8','192.168.0.0/16'] && $legacy->outbounds[1]->tag === 'direct', 'Legacy bypass routing preserved');
rejects(fn() => XraySubscription::url('http://example.test'), 'Subscription requires HTTPS');
rejects(fn() => XraySubscription::url('https://user:pass@example.test'), 'URL credentials rejected');
$a=(object)['remarks'=>'a']; $b=(object)['remarks'=>'b'];
check(XraySubscription::select([$a,$b],'b') === $b && XraySubscription::select([$a],'') === $a, 'Subscription array selection');
rejects(fn() => XraySubscription::select([$a,$b],''), 'Multi-profile update cannot choose silently');
$dir = sys_get_temp_dir() . '/xray-test-' . bin2hex(random_bytes(8)); mkdir($dir,0700);
try {
    $cron = xray_cron();
    check(count($cron) === 3 && $cron[0]['autocron'][1] === '*' && $cron[1]['autocron'][1] === '*', 'OPNsense autocron format runs both checks every minute');
    // Launch with the absolute executable path: PHP_BINARY can differ from a CLI alias on Linux.
    $child=proc_open([PHP_BINARY, '-r', 'usleep(2000000);', 'tests/config.php'], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
    if (!is_resource($child)) { throw new RuntimeException('Cannot start process identity fixture'); }
    file_put_contents($dir.'/pid', (string)proc_get_status($child)['pid']);
    check(XrayProcess::alive($dir.'/pid', PHP_BINARY, 'tests/config.php'), 'Managed process identity matches binary and config');
    check(!XrayProcess::alive($dir.'/pid', '/not/the/binary', 'tests/config.php'), 'Reused or unrelated PID is rejected');
    proc_terminate($child); proc_close($child);
    XrayConfig::atomic($dir.'/config', 'old'); XrayConfig::atomic($dir.'/config','new');
    check(file_get_contents($dir.'/config') === 'new' && (fileperms($dir.'/config') & 0777) === 0600, 'Atomic private file replacement');
    rejects(fn() => XrayConfig::validate($raw, true, '/does/not/exist'), 'Missing core fails validation');
    foreach ([0, 1] as $exit) {
        $binary=$dir.'/core'; file_put_contents($binary,"#!/bin/sh\nexit $exit\n"); chmod($binary,0700);
        if ($exit) { rejects(fn() => XrayConfig::validate($raw,true,$binary), 'Core rejection propagates'); }
        else { XrayConfig::validate($raw,true,$binary); check(true,'Core success accepted'); }
    }
} finally { foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
if (!empty($argv[1])) { XrayConfig::validate($raw,true,$argv[1]); XrayConfig::validate($raw,false,$argv[1]); check(true,'Real Xray-core accepts full fixture with DNS On and Off'); }

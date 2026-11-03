#!/usr/local/bin/php
<?php
/** Explicit isolated-lab lifecycle test; does NOT install/configure a policy. */
if (getenv('XRAY_ISOLATED_LAB') !== 'YES' || PHP_OS_FAMILY !== 'BSD') { fwrite(STDERR,"Requires XRAY_ISOLATED_LAB=YES on isolated OPNsense\n"); exit(2); }
set_include_path('/usr/local/etc/inc'.PATH_SEPARATOR.get_include_path());
require_once 'config.inc';
require_once '/usr/local/opnsense/scripts/Xray/xray-health.inc.php';
$active=json_decode(file_get_contents('/usr/local/etc/xray-core/active.json'),true,128,JSON_THROW_ON_ERROR);
$settings=XrayHealth::settings($active);
if(!$settings['enabled'] || !$settings['fallback']) { throw new RuntimeException('Apply a lab profile with heartbeat and managed WAN bypass first'); }
$id=$active['inst_uuid'];
if(!preg_match('/^[a-f0-9-]{36}$/Di',$id))throw new RuntimeException('Invalid active identity');
$original=OPNsense\Core\Config::getInstance()->object();
function protected_hash($root) {
    // Exclude only explicitly managed rule gateway/destination; all other XML stays protected.
    global $active;
    $copy=simplexml_load_string($root->asXML());
    unset($copy->revision); // Framework audit metadata changes on every legitimate save.
    require_once '/usr/local/opnsense/scripts/Xray/xray-routing.inc.php';
    foreach(XrayRouting::rules($copy) as $id=>$rule)if(in_array($id,XrayRouting::ids($active['routing_rules']),true)){unset($rule->gateway,$rule->destination);}
    // Allow own ASN/CIDR alias; every foreign alias is retained.
    foreach($copy->OPNsense->Firewall->Alias->aliases->alias??[] as $alias)if((string)$alias->name===($active['routing_alias']??'')) { $dom=dom_import_simplexml($alias);$dom->parentNode->removeChild($dom); }
    $dom=new DOMDocument();$dom->preserveWhiteSpace=false;$dom->loadXML($copy->asXML());
    return hash('sha256',$dom->C14N());
}
$baseline=protected_hash($original);
function defaults() {
    exec('/usr/bin/netstat -rn', $lines, $rc);
    if ($rc !== 0) { throw new RuntimeException('Cannot inspect kernel default routes'); }
    $rows=[]; foreach($lines as $line)if(preg_match('/^default\s/',trim($line)))$rows[]=preg_replace('/\s+/', ' ', trim($line));
    sort($rows);return $rows;
}
$defaultRoutes=defaults();
$files=[];foreach(['/etc/rc.conf','/usr/local/etc/unbound/unbound.conf'] as $p)if(is_file($p))$files[$p]=hash_file('sha256',$p);
function action($action) { global $id; exec('/usr/local/sbin/configctl xray '.$action.' '.escapeshellarg($id).' >/dev/null 2>&1',$out,$rc); if($rc)throw new RuntimeException('Lifecycle action failed: '.$action); }
function wait_route($wanted,$timeout) {
    global $id;
    $deadline=hrtime(true)+$timeout*1000000000;
    do { clearstatcache();$file='/var/run/xray-health/'.$id.'.json';$s=is_file($file)?json_decode(file_get_contents($file),true):null;
        if($s && ($s['route']??'')===$wanted && empty($s['routing_error']) && ($wanted!=='proxy'||($s['successes']??0)>=3)){echo 'PASS observed '.$wanted."\n";return;}
        usleep(250000);
    }while(hrtime(true)<$deadline);
    throw new RuntimeException('Timed out waiting for '.$wanted);
}
$failed=false;$restore=false;
try {
    wait_route('proxy',3*$settings['interval']+90);
    $restore=true; action('stop');
    wait_route('bypass',$settings['interval']+90);
    action('start');
    wait_route('proxy',3*$settings['interval']+90);$restore=false;
} catch(Throwable $error) { $failed=true;fwrite(STDERR,'FAIL '.$error->getMessage()."\n"); }
finally {
    if($restore)try{action('start');}catch(Throwable $error){$failed=true;fwrite(STDERR,"FAIL restoration; use lab console\n");}
    OPNsense\Core\Config::getInstance()->forceReload();
    if(protected_hash(OPNsense\Core\Config::getInstance()->object())!==$baseline){$failed=true;fwrite(STDERR,"FAIL unrelated configuration changed\n");}
    if(defaults()!==$defaultRoutes){$failed=true;fwrite(STDERR,"FAIL kernel default routes changed\n");}
    foreach($files as $p=>$hash)if(!is_file($p)||hash_file('sha256',$p)!==$hash){$failed=true;fwrite(STDERR,"FAIL protected system file changed\n");}
}
if(!$failed)echo "PASS lifecycle failover and protected configuration invariants; client data-plane test still required\n";
exit($failed?1:0);

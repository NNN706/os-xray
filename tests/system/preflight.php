#!/usr/local/bin/php
<?php
/** Read-only target check. Emits booleans/versions, never config, endpoints or credentials. */
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
$failed=false;
function check($name,$ok) { global $failed; echo ($ok?'PASS ':'FAIL ').$name."\n"; if(!$ok)$failed=true; }
check('FreeBSD target', PHP_OS_FAMILY==='BSD');
check('OPNsense framework',is_file('/usr/local/etc/inc/config.inc'));
if ($failed) { exit(1); }
require_once 'config.inc';
require_once '/usr/local/opnsense/scripts/Xray/xray-store.inc.php';
require_once '/usr/local/opnsense/scripts/Xray/xray-health.inc.php';
require_once '/usr/local/opnsense/scripts/Xray/xray-routing.inc.php';
foreach (['/usr/local/bin/xray-core','/usr/local/tun2socks/tun2socks','/usr/local/bin/curl','/sbin/pfctl','/usr/local/sbin/configctl'] as $binary) { check(basename($binary).' installed',is_executable($binary)); }
check('Framework config lock',method_exists(OPNsense\Core\Config::getInstance(),'lock'));
check('Native gateway model',class_exists('OPNsense\Routing\Gateways'));
check('Native rule hash',method_exists('OPNsense\Firewall\Util','calcRuleHash'));
$path='/usr/local/etc/xray-core/active.json';
check('Applied profile exists',is_file($path));
if(is_file($path)) {
    check('Applied profile private',(fileperms($path)&0077)===0 && fileowner($path)===0);
    try {
        $active=json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        $settings=XrayHealth::settings($active);
        check('Heartbeat enabled',$settings['enabled']);
        check('Managed WAN bypass enabled',$settings['fallback']);
        check('Selected SOCKS loopback',in_array($active['socks']['host'],['127.0.0.1','::1'],true));
        // Review exactly the saved policy; no ASN fetch or configuration write.
        $cfg=OPNsense\Core\Config::getInstance()->object();
        $rules=XrayRouting::rules($cfg);
        $gws=(new OPNsense\Routing\Gateways())->gatewaysIndexedByName(false,true);
        foreach(XrayRouting::ids($active['routing_rules']??'') as $id) { XrayRouting::checkRule($rules[$id]??null,$active,$gws); }
        check('Managed rules and existing gateways validated',true);
    } catch(Throwable $error) { check('Applied policy validation',false); }
}
foreach(['/etc/rc.conf','/usr/local/etc/unbound/unbound.conf'] as $file) { if(is_file($file))echo 'BASELINE '.basename($file).' SHA256 '.hash_file('sha256',$file)."\n"; }
exit($failed?1:0);

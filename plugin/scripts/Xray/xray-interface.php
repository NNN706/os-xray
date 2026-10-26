#!/usr/local/bin/php
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once 'config.inc';
require_once __DIR__ . '/xray-process.inc.php';
$root = OPNsense\Core\Config::getInstance()->object();
if ((string)($root->OPNsense->xray->general->enabled ?? '0') !== '1') { exit(0); }
if (empty($argv[1])) {
    exec('/usr/local/bin/php ' . escapeshellarg(__DIR__ . '/xray-service-control.php') . ' boot >/dev/null 2>&1');
}
$lock = fopen('/var/run/xray-transition.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) { exit(1); }
try {
    $path = '/usr/local/etc/xray-core/active.json';
    $c = is_file($path) ? json_decode(file_get_contents($path), true) : null;
    if (!$c || (!empty($argv[1]) && $argv[1] !== $c['inst_uuid']) || !preg_match('/^[a-z][a-z0-9]{0,13}[0-9]$/D', $c['tun_iface'])) { exit(0); }
    $id = $c['inst_uuid'];
    if (!XrayProcess::alive('/var/run/xray_core_' . $id . '.pid', '/usr/local/bin/xray-core', '/usr/local/etc/xray-core/config-' . $id . '.json') || !XrayProcess::alive('/var/run/tun2socks_' . $id . '.pid', '/usr/local/tun2socks/tun2socks', '/usr/local/tun2socks/config-' . $id . '.yaml')) { exit(0); }
    $cfg = OPNsense\Core\Config::getInstance()->object();
    foreach ($cfg->interfaces->children() as $interface) {
        if ((string)$interface->if !== $c['tun_iface']) { continue; }
        $address = (string)$interface->ipaddr;
        $prefix = (string)$interface->subnet;
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && preg_match('/^[0-9]+$/D', $prefix) && (int)$prefix <= 32) {
            exec('/sbin/ifconfig ' . escapeshellarg($c['tun_iface']) . ' inet ' . escapeshellarg($address . '/' . $prefix) . ' >/dev/null 2>&1');
        }
        $address6 = (string)$interface->ipaddrv6;
        $prefix6 = (string)$interface->subnetv6;
        if (filter_var($address6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && preg_match('/^[0-9]+$/D', $prefix6) && (int)$prefix6 <= 128) {
            exec('/sbin/ifconfig ' . escapeshellarg($c['tun_iface']) . ' inet6 ' . escapeshellarg($address6 . '/' . $prefix6) . ' >/dev/null 2>&1');
        }
    }
    exec('/usr/local/sbin/configctl filter reload >/dev/null 2>&1');
} finally { flock($lock, LOCK_UN); fclose($lock); }

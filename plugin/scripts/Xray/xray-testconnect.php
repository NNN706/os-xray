#!/usr/local/bin/php
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once 'config.inc';
require_once __DIR__ . '/xray-store.inc.php';
require_once __DIR__ . '/xray-process.inc.php';
require_once __DIR__ . '/xray-probe.inc.php';
umask(0077);
$result = ['result' => 'failed', 'http_code' => 0, 'latency_ms' => null, 'timed_out' => false, 'message' => 'No active tunnel'];
try {
    $id = $argv[1] ?? '';
    $path = '/usr/local/etc/xray-core/active.json';
    $active = is_file($path) ? json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR) : null;
    if (!$active || ($id !== '' && $id !== '%1' && $id !== '%s' && $active['inst_uuid'] !== $id)) { throw new RuntimeException('No active tunnel for this profile'); }
    $uuid = $active['inst_uuid'];
    if (!XrayProcess::alive('/var/run/xray_core_' . $uuid . '.pid', '/usr/local/bin/xray-core', '/usr/local/etc/xray-core/config-' . $uuid . '.json') || !XrayProcess::alive('/var/run/tun2socks_' . $uuid . '.pid', '/usr/local/tun2socks/tun2socks', '/usr/local/tun2socks/config-' . $uuid . '.yaml')) { throw new RuntimeException('Active tunnel is stopped'); }
    $meta = $active['test_metadata'] ?? [];
    $result = XrayProbe::run($active['socks'], $meta['check_url'] ?? XrayProbe::TARGET, ($meta['ping_type'] ?? 'proxy-head') === 'proxy' ? 'GET' : 'HEAD', 1000, null);
} catch (Throwable $error) { $result['message'] = $error instanceof JsonException ? 'Invalid applied configuration' : $error->getMessage(); }
echo json_encode($result) . "\n";

#!/usr/local/bin/php
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once __DIR__ . '/xray-store.inc.php';
umask(0077);
try {
    $action = $argv[1] ?? '';
    // Draft validation/fetch do not read config.xml or contend with MVC's save lock.
    if (in_array($action, ['schedule', 'prepared', 'summary'], true)) { require_once 'config.inc'; }
    $id = $argv[2] ?? '';
    if ($action === 'schedule') {
        foreach (XrayStore::profiles() as $profile) { XrayStore::update($profile); }
        echo "OK\n";
    } elseif ($action === 'summary') {
        $summary = [];
        foreach (XrayStore::profiles() as $uuid => $profile) {
            $json = json_decode($profile['config_json'], true) ?: [];
            $outbound = $json['outbounds'][0] ?? [];
            $server = $outbound['settings']['vnext'][0] ?? ($outbound['settings'] ?? []);
            $summary[$uuid] = ['name' => $profile['name'], 'server_address' => (string)($server['address'] ?? ''), 'server_port' => isset($server['port']) ? (string)$server['port'] : ''];
        }
        echo json_encode($summary);
    } elseif ($action === 'prepared') {
        $c = XrayStore::profiles()[$id] ?? null;
        if (!$c) { throw new RuntimeException('Profile not found'); }
        echo json_encode(['result' => 'ok', 'profile' => $c, 'schedule' => XrayStore::schedule($c, time()), 'update' => XrayStore::read($id, 'subscription')]);
    } elseif (in_array($action, ['validate', 'fetch'], true)) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new RuntimeException('Invalid request'); }
        $path = '/var/tmp/xray-api/' . $id;
        if (realpath(dirname($path)) !== '/var/tmp/xray-api' || !is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600 || fileowner($path) !== fileowner(dirname($path)) || filesize($path) > 4200000) { throw new RuntimeException('Invalid request file'); }
        try { $request = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR); }
        finally { unlink($path); }
        if ($action === 'fetch') {
            echo json_encode(['result' => 'ok'] + XraySubscription::fetch($request['url'] ?? ''));
        } else {
            XrayConfig::validate($request['config_json'] ?? '', (bool)($request['use_config_dns'] ?? true));
            XrayConfig::socks(XrayConfig::decode($request['config_json']), (string)($request['socks_inbound'] ?? ''));
            echo json_encode(['result' => 'ok', 'message' => 'Effective configuration is valid']);
        }
    } else { throw new RuntimeException('Unknown API operation'); }
} catch (Throwable $error) {
    echo json_encode(['result' => 'failed', 'message' => $error instanceof JsonException ? 'Invalid JSON configuration' : $error->getMessage()]);
    exit(1);
}

#!/usr/local/bin/php
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once 'config.inc';
require_once __DIR__ . '/xray-config.inc.php';
umask(0077);
function legacy_json($instance): string
{
    if ((string)($instance->config_json ?? '') !== '') { return (string)$instance->config_json; }
    $custom = (string)($instance->custom_config ?? '');
    if ((string)($instance->config_mode ?? '') === 'custom' && $custom !== '') {
        XrayConfig::decode($custom);
        return $custom;
    }
    $raw = (string)($instance->outbound_config ?? '');
    if ($raw !== '') { $outbound = json_decode($raw, false, 128, JSON_THROW_ON_ERROR); }
    else {
        $user = (object)['id' => (string)($instance->vless_uuid ?? $instance->uuid ?? ''), 'encryption' => 'none', 'flow' => (string)($instance->flow ?? '')];
        if ($user->id === '' || (string)($instance->server_address ?? '') === '') { throw new RuntimeException('Legacy instance has no usable outbound'); }
        $outbound = (object)['tag' => 'proxy', 'protocol' => 'vless', 'settings' => (object)['vnext' => [(object)['address' => (string)$instance->server_address, 'port' => (int)(string)($instance->server_port ?? '443'), 'users' => [$user]]]], 'streamSettings' => (object)['network' => 'tcp', 'security' => 'reality', 'realitySettings' => (object)['serverName' => (string)($instance->reality_sni ?? ''), 'publicKey' => (string)($instance->reality_pubkey ?? ''), 'shortId' => (string)($instance->reality_shortid ?? ''), 'fingerprint' => (string)($instance->reality_fingerprint ?? 'chrome')]]];
    }
    if (!$outbound instanceof stdClass) { throw new RuntimeException('Legacy outbound must be an object'); }
    $level = (string)($instance->loglevel ?? 'warning');
    if (in_array($level, ['e','loglevel_error'], true)) { $level = 'error'; }
    $config = XrayConfig::wrap($outbound, (string)($instance->socks5_listen ?? '127.0.0.1'), (int)(string)($instance->socks5_port ?? '10808'), (string)($instance->bypass_networks ?? '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'), $level);
    $config->inbounds[0]->tag = 'socks-in';
    $config->inbounds[0]->settings->ip = $config->inbounds[0]->listen;
    return XrayConfig::encode($config);
}
try {
    $cfg = OPNsense\Core\Config::getInstance();
    $cfg->lock();
    $root = $cfg->object();
    if (!isset($root->OPNsense)) { $root->addChild('OPNsense'); }
    if (!isset($root->OPNsense->xray)) { $root->OPNsense->addChild('xray'); }
    $xray = $root->OPNsense->xray;
    $pending = [];
    foreach ($xray->instances->instance ?? [] as $instance) {
        if ((string)($instance->config_json ?? '') !== '') { continue; }
        $json = legacy_json($instance);
        XrayConfig::socks(XrayConfig::decode($json));
        XrayConfig::validate($json, true);
        $pending[] = [$instance, $json];
    }
    $newJson = null; $old = null;
    if (!isset($xray->instances->instance)) {
        if (isset($xray->instance)) { $old = $xray->instance; $newJson = legacy_json($old); }
        elseif (is_file('/usr/local/etc/xray-core/config.json')) { $newJson = file_get_contents('/usr/local/etc/xray-core/config.json'); }
        if ($newJson !== null) { XrayConfig::socks(XrayConfig::decode($newJson)); XrayConfig::validate($newJson, true); }
    }
    // No config.xml mutations until every conversion passed core validation.
    foreach ($pending as [$instance, $json]) { $instance->config_json = $json; $instance->use_config_dns = '1'; }
    if ($newJson !== null) {
        if (!isset($xray->instances)) { $xray->addChild('instances'); }
        $instance = $xray->instances->addChild('instance');
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        $id = substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20);
        $instance->addAttribute('uuid', $id);
        if ($old) { foreach ($old->children() as $key => $value) { $instance->$key = (string)$value; } }
        $instance->config_json = $newJson;
        $instance->use_config_dns = '1';
        $instance->enabled = '1';
        $instance->name = XrayConfig::name(XrayConfig::decode($newJson), (string)($old->name ?? 'Imported'));
        $instance->tun_interface = (string)($old->tun_interface ?? 'proxytun2socks0');
        $instance->mtu = (string)($old->mtu ?? '1500');
    }
    if ($pending || $newJson !== null) { $cfg->save(); }
    // Adopt an existing, unambiguous working v3 runtime for boot/watchdog recovery.
    // Read the runtime files, never an unapplied editor draft.
    if (!is_file('/usr/local/etc/xray-core/active.json') && (string)($xray->general->enabled ?? '0') === '1') {
        $runtimes = [];
        foreach ($xray->instances->instance ?? [] as $source) {
            $id = (string)$source['uuid'];
            if (!preg_match('/^[a-f0-9-]{36}$/Di', $id) || (string)($source->enabled ?? '1') !== '1') { continue; }
            $jsonPath = '/usr/local/etc/xray-core/config-' . $id . '.json';
            $yamlPath = '/usr/local/tun2socks/config-' . $id . '.yaml';
            if (is_file($jsonPath) && is_file($yamlPath)) { $runtimes[] = [$source, $id, $jsonPath, $yamlPath]; }
        }
        if (count($runtimes) === 1) {
            [$source, $id, $jsonPath, $yamlPath] = $runtimes[0];
            $raw = file_get_contents($jsonPath); $yaml = file_get_contents($yamlPath);
            $socks = XrayConfig::socks(XrayConfig::decode($raw));
            XrayConfig::validate($raw, true);
            if (!preg_match('/^device:\s*([a-z][a-z0-9]{0,13}[0-9])\s*$/m', $yaml, $device) || !preg_match('/^mtu:\s*(\d+)\s*$/m', $yaml, $mtu) || (int)$mtu[1] < 576 || (int)$mtu[1] > 9000) { throw new RuntimeException('Legacy TUN settings need review'); }
            $applied = ['inst_uuid' => $id, 'name' => (string)$source->name, 'enabled' => true, 'config_json' => $raw, 'effective_json' => $raw, 'tun_yaml' => $yaml, 'tun_iface' => $device[1], 'mtu' => (int)$mtu[1], 'use_config_dns' => true, 'socks' => $socks, 'test_metadata' => []];
            XrayConfig::atomic('/usr/local/etc/xray-core/profiles/' . $id . '-applied.json', XrayConfig::encode($applied));
            XrayConfig::atomic('/usr/local/etc/xray-core/active.json', XrayConfig::encode($applied));
        }
    }
    echo "OK: migration checked; legacy source fields retained\n";
} catch (Throwable $error) { fwrite(STDERR, "ERROR: migration rejected; original configuration retained. Review legacy configuration in a private session.\n"); exit(1); }

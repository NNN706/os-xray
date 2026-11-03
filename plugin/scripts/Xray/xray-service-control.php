#!/usr/local/bin/php
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once 'config.inc';
require_once __DIR__ . '/xray-store.inc.php';
require_once __DIR__ . '/xray-process.inc.php';
require_once __DIR__ . '/xray-health.inc.php';
require_once __DIR__ . '/xray-routing.inc.php';
umask(0077);
const XRAY_BIN = '/usr/local/bin/xray-core';
const T2S_BIN = '/usr/local/tun2socks/tun2socks';
const XRAY_CONF_DIR = '/usr/local/etc/xray-core';
const T2S_CONF_DIR = '/usr/local/tun2socks';
function xray_conf_path(string $id): string { return XRAY_CONF_DIR . '/config-' . $id . '.json'; }
function t2s_conf_path(string $id): string { return T2S_CONF_DIR . '/config-' . $id . '.yaml'; }
function xray_pid_path(string $id): string { return '/var/run/xray_core_' . $id . '.pid'; }
function t2s_pid_path(string $id): string { return '/var/run/tun2socks_' . $id . '.pid'; }
function stopped_path(string $id): string { return '/var/run/xray_stopped_' . $id . '.flag'; }
function active_profile(): ?array {
    $path = XRAY_CONF_DIR . '/active.json';
    return is_file($path) ? json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR) : null;
}
// ─── PID helpers (FreeBSD: no posix extension — use /bin/kill) ───────────────
function proc_is_running(string $pidfile): bool
{
    $isXray = strpos(basename($pidfile), 'xray_core_') === 0;
    $id = preg_replace('/^(xray_core_|tun2socks_)|\.pid$/', '', basename($pidfile));
    return XrayProcess::alive($pidfile, $isXray ? XRAY_BIN : T2S_BIN, $isXray ? xray_conf_path($id) : t2s_conf_path($id));
}

function proc_kill(string $pidfile): void
{
    if (!file_exists($pidfile)) {
        return;
    }
    $pid = (int)trim(file_get_contents($pidfile));
    if ($pid > 0) {
        if (!proc_is_running($pidfile)) { @unlink($pidfile); return; }

        exec('/bin/kill -TERM ' . $pid . ' 2>/dev/null');
        $i = 0;
        while ($i++ < 30) {
            exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                break;
            }
            usleep(100000);
        }
        exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out2, $rc2);
        if ($rc2 === 0) {
            exec('/bin/kill -KILL ' . $pid . ' 2>/dev/null');
        }
    }
    @unlink($pidfile);
}

function proc_start(string $bin, string $args, string $pidfile, string $logfile = ''): void
{
    // Keep core output private: startup diagnostics may contain credentials.
    $log = escapeshellarg('/dev/null');
    exec('/usr/sbin/daemon -p ' . escapeshellarg($pidfile)
       . ' ' . escapeshellarg($bin) . ' ' . $args . ' >> ' . $log . ' 2>&1');
}


function stop_processes(array $c): void
{
    $id = $c['inst_uuid'];
    proc_kill(t2s_pid_path($id));
    proc_kill(xray_pid_path($id));
    exec('/sbin/ifconfig ' . escapeshellarg($c['tun_iface']) . ' destroy >/dev/null 2>&1');
}
function prepare_profile(array $c): array
{
    if (!is_executable(XRAY_BIN) || !is_executable(T2S_BIN)) { throw new RuntimeException('Xray-core or tun2socks is unavailable'); }
    if (!preg_match('/^[a-z][a-z0-9]{0,13}[0-9]$/D', $c['tun_iface']) || $c['mtu'] < 576 || $c['mtu'] > 9000) { throw new RuntimeException('Invalid TUN name or MTU'); }
    XrayHealth::settings($c);
    $c = XrayRouting::prepare($c);
    $config = XrayConfig::decode($c['config_json']);
    $socks = XrayConfig::socks($config, $c['socks_inbound']);
    XrayConfig::validate($c['config_json'], $c['use_config_dns']);
    $c['effective_json'] = XrayConfig::effective($c['config_json'], $c['use_config_dns']);
    $c['socks'] = $socks;
    $host = strpos($socks['host'], ':') !== false ? '[' . $socks['host'] . ']' : $socks['host'];
    $c['tun_yaml'] = 'proxy: socks5://' . $host . ':' . $socks['port'] . "\n" . 'device: ' . $c['tun_iface'] . "\nmtu: " . $c['mtu'] . "\nloglevel: error\n";
    $c['test_metadata'] = json_decode($c['subscription_metadata'] ?: '{}', true) ?: [];
    return $c;
}
function resources(array $c, ?array $old): void
{
    // Only the currently managed process is allowed to own a requested listening port.
    $oldConfig = $old ? XrayConfig::decode($old['config_json']) : null;
    $oldInbounds = $oldConfig->inbounds ?? [];
    foreach (XrayConfig::decode($c['effective_json'])->inbounds ?? [] as $inbound) {
        if (!isset($inbound->port) || !is_int($inbound->port) || !isset($inbound->listen)) { continue; }
        $managed = false;
        foreach ($oldInbounds as $existing) {
            if ((($existing->listen ?? '') === $inbound->listen || in_array($existing->listen ?? '', ['0.0.0.0','::'], true) || in_array($inbound->listen, ['0.0.0.0','::'], true)) && ($existing->port ?? 0) === $inbound->port && $old && proc_is_running(xray_pid_path($old['inst_uuid']))) { $managed = true; }
        }
        if (!$managed) {
            $host = $inbound->listen;
            $host = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
            $socket = @stream_socket_server('tcp://' . $host . ':' . $inbound->port, $errno, $error);
            if (!$socket) { throw new RuntimeException('An inbound address or port is unavailable'); }
            fclose($socket);
        }
    }
    if (!$old || $old['tun_iface'] !== $c['tun_iface']) {
        exec('/sbin/ifconfig ' . escapeshellarg($c['tun_iface']) . ' >/dev/null 2>&1', $unused, $rc);
        if ($rc === 0) { throw new RuntimeException('Requested TUN interface is already in use'); }
    }
}
function launch_profile(array $c): void
{
    $id = $c['inst_uuid'];
    XrayConfig::atomic(xray_conf_path($id), $c['effective_json']);
    XrayConfig::atomic(t2s_conf_path($id), $c['tun_yaml']);
    proc_start(XRAY_BIN, 'run -c ' . escapeshellarg(xray_conf_path($id)), xray_pid_path($id));
    proc_start(T2S_BIN, '--config ' . escapeshellarg(t2s_conf_path($id)), t2s_pid_path($id));
    $host = $c['socks']['host'];
    $host = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        exec('/sbin/ifconfig ' . escapeshellarg($c['tun_iface']) . ' >/dev/null 2>&1', $unused, $rc);
        $socket = @stream_socket_client('tcp://' . $host . ':' . $c['socks']['port'], $errno, $error, 0.1);
        if ($socket) { fclose($socket); }
        if ($rc === 0 && $socket && proc_is_running(xray_pid_path($id)) && proc_is_running(t2s_pid_path($id))) {
            exec('/sbin/ifconfig ' . escapeshellarg($c['tun_iface']) . ' mtu ' . (int)$c['mtu'] . ' up >/dev/null 2>&1', $unused2, $setRc);
            if ($setRc !== 0) { break; }
            return;
        }
    }
    throw new RuntimeException('Services did not become ready; restoring last working configuration');
}
function transition(array $candidate, ?array $old): void
{
    // Everything that can be checked offline is checked before stopping the live tunnel.
    $next = prepare_profile($candidate);
    resources($next, $old);
    $wasRunning = $old && (proc_is_running(xray_pid_path($old['inst_uuid'])) || proc_is_running(t2s_pid_path($old['inst_uuid'])));
    XrayConfig::atomic(XRAY_CONF_DIR . '/transition.json', XrayConfig::encode(['old' => $old, 'next' => $next, 'was_running' => $wasRunning]));
    try {
        if ($old) { XrayRouting::apply($old, 'bypass'); stop_processes($old); }
        launch_profile($next);
        XrayRouting::apply($next, XrayHealth::settings($next)['fallback'] ? 'bypass' : 'proxy');
        XrayStore::write($next['inst_uuid'], 'applied', $next);
        XrayConfig::atomic(XRAY_CONF_DIR . '/active.json', XrayConfig::encode($next));
        @unlink('/var/run/xray-health/' . $next['inst_uuid'] . '.json');
        @unlink(stopped_path($next['inst_uuid']));
        @unlink(XRAY_CONF_DIR . '/transition.json');
        // The hook only configures assigned interfaces/firewall; it never generates JSON.
        exec('/bin/sh /usr/local/etc/rc.syshook.d/start/50-xray ' . escapeshellarg($next['inst_uuid']) . ' >/dev/null 2>&1 &');
    } catch (Throwable $error) {
        stop_processes($next);
        if ($old) {
            XrayConfig::atomic(xray_conf_path($old['inst_uuid']), $old['effective_json']);
            XrayConfig::atomic(t2s_conf_path($old['inst_uuid']), $old['tun_yaml']);
            XrayConfig::atomic(XRAY_CONF_DIR . '/active.json', XrayConfig::encode($old));
            XrayStore::write($old['inst_uuid'], 'applied', $old);
            if ($wasRunning) { launch_profile($old); XrayRouting::apply($old, XrayHealth::settings($old)['fallback'] ? 'bypass' : 'proxy'); }
        }
        @unlink(XRAY_CONF_DIR . '/transition.json');
        throw $error;
    }
}
function discover_legacy(array $profiles): ?array
{
    $running = array_filter($profiles, static function ($c) {
        return proc_is_running(xray_pid_path($c['inst_uuid'])) || proc_is_running(t2s_pid_path($c['inst_uuid']));
    });
    if (count($running) > 1) { throw new RuntimeException('Multiple legacy tunnels are running. Stop them before choosing one profile'); }
    if (!$running) { return null; }
    $old = reset($running); $id = $old['inst_uuid'];
    if (!is_file(xray_conf_path($id)) || !is_file(t2s_conf_path($id))) { throw new RuntimeException('Legacy runtime files are missing; stop the legacy tunnel first'); }
    $old['config_json'] = file_get_contents(xray_conf_path($id));
    $old['effective_json'] = $old['config_json'];
    $old['use_config_dns'] = true;
    $old['socks'] = XrayConfig::socks(XrayConfig::decode($old['config_json']));
    $old['tun_yaml'] = file_get_contents(t2s_conf_path($id));
    // Use actual applied TUN settings rather than edited config.xml values.
    if (preg_match('/^device:\s*([a-z][a-z0-9]{0,13}[0-9])\s*$/m', $old['tun_yaml'], $match)) { $old['tun_iface'] = $match[1]; }
    if (preg_match('/^mtu:\s*(\d+)\s*$/m', $old['tun_yaml'], $match)) { $old['mtu'] = (int)$match[1]; }
    $old['test_metadata'] = [];
    XrayStore::write($id, 'applied', $old);
    XrayConfig::atomic(XRAY_CONF_DIR . '/active.json', XrayConfig::encode($old));
    return $old;
}
function status_profile(string $id, string $name = ''): array
{
    $x = proc_is_running(xray_pid_path($id)); $t = proc_is_running(t2s_pid_path($id));
    return ['name' => $name, 'inst_uuid' => $id, 'status' => $x && $t ? 'ok' : 'stopped', 'xray_core' => $x ? 'running' : 'stopped', 'tun2socks' => $t ? 'running' : 'stopped'];
}
try {
    $action = $argv[1] ?? 'status'; $id = $argv[2] ?? '';
    if ($id === '%1' || $id === '%s') { $id = ''; }
    if ($id !== '') { XrayStore::path($id, 'prepared'); }
    $profiles = XrayStore::profiles();
    if ($id !== '' && !isset($profiles[$id])) { throw new RuntimeException('Unknown profile'); }
    $old = active_profile();
    if ($action === 'version') { echo json_encode(['version' => trim(file_get_contents(is_file(__DIR__ . '/version.txt') ? __DIR__ . '/version.txt' : dirname(__DIR__, 3) . '/VERSION'))]); }
    elseif ($action === 'statusall') {
        $result = []; foreach ($profiles as $uuid => $c) { $result[$uuid] = status_profile($uuid, $c['name']); }
        echo json_encode($result);
    } elseif ($action === 'status') { echo json_encode($id !== '' ? status_profile($id) : ($old ? status_profile($old['inst_uuid']) : ['status' => 'stopped', 'xray_core' => 'stopped', 'tun2socks' => 'stopped'])); }
    elseif ($action === 'validate') {
        $c = $id !== '' ? $profiles[$id] : ($old ? ($profiles[$old['inst_uuid']] ?? null) : null);
        if (!$c) { throw new RuntimeException('Select a profile or validate the editor content'); }
        prepare_profile($c); echo "OK: effective configuration is valid\n";
    } else {
        if (!in_array($action, ['start', 'stop', 'restart', 'reconfigure', 'boot', 'recover'], true)) { throw new RuntimeException('Unknown operation'); }
        $lock = fopen('/var/run/xray-transition.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another lifecycle transition is in progress'); }
        try {
            XrayRouting::recover();
            $old = active_profile();
            if (!$old && $action !== 'stop') { $old = discover_legacy($profiles); }
            // A durable journal permits recovery after interruption between file replacements.
            $journal = XRAY_CONF_DIR . '/transition.json';
            if (is_file($journal)) {
                $pending = json_decode(file_get_contents($journal), true, 128, JSON_THROW_ON_ERROR);
                stop_processes($pending['next']);
                if ($pending['old']) {
                    $old = $pending['old'];
                    XrayConfig::atomic(XRAY_CONF_DIR . '/active.json', XrayConfig::encode($old));
                    XrayStore::write($old['inst_uuid'], 'applied', $old);
                    if ($pending['was_running'] && $action !== 'stop' && !empty($profiles[$old['inst_uuid']]['enabled'])) { launch_profile($old); }
                } else { @unlink(XRAY_CONF_DIR . '/active.json'); $old = null; }
                unlink($journal);
            }
            if ($action === 'stop') {
                if (!$old) { foreach ($profiles as $legacy) { if (($id === '' || $id === $legacy['inst_uuid']) && (proc_is_running(xray_pid_path($legacy['inst_uuid'])) || proc_is_running(t2s_pid_path($legacy['inst_uuid'])))) { stop_processes($legacy); XrayConfig::atomic(stopped_path($legacy['inst_uuid']), 'stopped'); } } }
                if ($old && ($id === '' || $id === $old['inst_uuid'])) { XrayRouting::apply($old, 'bypass'); stop_processes($old); XrayConfig::atomic(stopped_path($old['inst_uuid']), 'stopped'); }
                echo "OK: stopped\n";
            } elseif (in_array($action, ['boot', 'recover'], true)) {
                // Recovery must not apply a downloaded draft, even after a reboot.
                if ($old && isset($profiles[$old['inst_uuid']]) && $profiles[$old['inst_uuid']]['enabled'] && ($id === '' || $id === $old['inst_uuid']) && ($action === 'boot' || !file_exists(stopped_path($old['inst_uuid'])))) {
                    if (!proc_is_running(xray_pid_path($old['inst_uuid'])) || !proc_is_running(t2s_pid_path($old['inst_uuid']))) { XrayRouting::apply($old, 'bypass'); stop_processes($old); launch_profile($old); XrayRouting::apply($old, XrayHealth::settings($old)['fallback'] ? 'bypass' : 'proxy'); }
                }
                echo "OK: recovery checked\n";
            } else {
                $candidates = array_filter($profiles, static function ($c) { return $c['enabled']; });
                $selected = $id !== '' ? ($profiles[$id]['enabled'] ? $profiles[$id] : null) : (count($candidates) === 1 ? reset($candidates) : ($old && isset($candidates[$old['inst_uuid']]) ? $candidates[$old['inst_uuid']] : null));
                if (!$selected && !$candidates) {
                    if ($old) { XrayRouting::apply($old, 'bypass'); stop_processes($old); XrayConfig::atomic(stopped_path($old['inst_uuid']), 'stopped'); }
                    echo "OK: disabled\n";
                } elseif (!$selected) { throw new RuntimeException('Select one enabled profile; only one tunnel can be active'); }
                else { transition($selected, $old); echo "OK: applied\n"; }
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
} catch (Throwable $error) {
    echo 'ERROR: ' . ($error instanceof JsonException ? 'Invalid JSON configuration' : $error->getMessage()) . "\n";
    exit(1);
}

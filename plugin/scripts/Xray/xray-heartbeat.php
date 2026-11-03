#!/usr/local/bin/php
<?php
set_include_path('/usr/local/etc/inc' . PATH_SEPARATOR . get_include_path());
require_once 'config.inc';
require_once __DIR__ . '/xray-probe.inc.php';
require_once __DIR__ . '/xray-process.inc.php';
require_once __DIR__ . '/xray-health.inc.php';
require_once __DIR__ . '/xray-routing.inc.php';
umask(0077);
const HEALTH_DIR = '/var/run/xray-health';
function health_state(string $id): ?array {
    $file = HEALTH_DIR . '/' . $id . '.json';
    return is_file($file) ? json_decode(file_get_contents($file), true) : null;
}
function health_active(): ?array {
    $file = '/usr/local/etc/xray-core/active.json';
    return is_file($file) ? json_decode(file_get_contents($file), true, 128, JSON_THROW_ON_ERROR) : null;
}
try {
    if (!is_dir(HEALTH_DIR) && !mkdir(HEALTH_DIR, 0700)) { throw new RuntimeException('Private health storage unavailable'); }
    $action = $argv[1] ?? 'worker';
    if ($action === 'status') {
        $active = health_active();
        $settings = $active ? XrayHealth::settings($active) : null;
        $state = $active ? health_state($active['inst_uuid']) : null;
        $checked = $state['checked_at'] ?? 0;
        echo json_encode(['result'=>'ok', 'enabled'=>$settings['enabled'] ?? false, 'interval'=>$settings['interval'] ?? null, 'profile'=>$active['inst_uuid'] ?? null, 'stale'=>$checked === 0 || time() - $checked > ($settings['interval'] ?? 60) + 10, 'state'=>$state]); exit(0);
    }
    if ($action === 'options') {
        $cfg = OPNsense\Core\Config::getInstance()->object(); $rules = [];
        foreach (XrayRouting::rules($cfg) as $id => $rule) {
            if (strpos((string)$rule->descr, '[os-xray]') === 0) { $rules[] = ['uuid'=>$id, 'name'=>(string)$rule->descr, 'interface'=>(string)$rule->interface, 'family'=>(string)$rule->ipprotocol]; }
        }
        $gateways = [];
        foreach ((new OPNsense\Routing\Gateways())->gatewaysIndexedByName(false, true) as $name => $gateway) { $gateways[] = ['name'=>$name, 'interface'=>$gateway['if'], 'family'=>$gateway['ipprotocol']]; }
        echo json_encode(['result'=>'ok','rules'=>$rules,'gateways'=>$gateways]); exit(0);
    }
    if ($action !== 'worker') { throw new RuntimeException('Invalid health action'); }
    $worker = fopen(HEALTH_DIR . '/worker.lock', 'c');
    if (!$worker || !flock($worker, LOCK_EX | LOCK_NB)) { exit(0); }
    // One minute cron supervises this short-lived worker. Sub-minute presets use monotonic timing.
    $end = hrtime(true) + 55000000000;
    do {
        $active = health_active();
        $cfg = OPNsense\Core\Config::getInstance()->object();
        $settings = $active ? XrayHealth::settings($active) : null;
        if (!$active || !$settings['enabled'] || (string)($cfg->OPNsense->xray->general->enabled ?? '0') !== '1') { break; }
        $state = health_state($active['inst_uuid']);
        $due = !$state || time() >= ($state['checked_at'] ?? 0) + $settings['interval'];
        if ($due) {
            $lock = fopen('/var/run/xray-transition.lock', 'c');
            if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    // Re-read under the same lock as Start/Stop/Apply. Never inspect an editor draft.
                    $current = health_active();
                    if (!$current || $current !== $active) { continue; }
                    $id = $active['inst_uuid'];
                    $stopped = is_file('/var/run/xray_stopped_' . $id . '.flag');
                    $components = [
                        'xray'=>!$stopped && XrayProcess::alive('/var/run/xray_core_' . $id . '.pid','/usr/local/bin/xray-core','/usr/local/etc/xray-core/config-' . $id . '.json'),
                        'tun2socks'=>!$stopped && XrayProcess::alive('/var/run/tun2socks_' . $id . '.pid','/usr/local/tun2socks/tun2socks','/usr/local/tun2socks/config-' . $id . '.yaml'),
                    ];
                    exec('/sbin/ifconfig ' . escapeshellarg($active['tun_iface']) . ' 2>/dev/null', $ifout, $ifrc);
                    $components['tun'] = $ifrc === 0 && preg_match('/flags=\S*<[^>]*\bUP\b/', implode("\n", $ifout)) === 1; unset($ifout);
                    $probe = !in_array(false, $components, true) ? XrayProbe::run($active['socks']) : ['result'=>'failed','http_code'=>0,'latency_ms'=>null,'timed_out'=>false,'message'=>$stopped ? 'Intentionally stopped; recovery paused' : 'TUN or managed process is unavailable'];
                    $state = XrayHealth::sample($state, $probe, $components, time());
                    $state['mode'] = $settings['fallback'] ? 'automatic bypass' : 'diagnostics only';
                    try {
                        if ($settings['fallback']) { XrayRouting::apply($active, $state['desired']); $state['route'] = $state['desired']; }
                        else { $state['route'] = 'unmanaged'; }
                        $state['routing_error'] = false;
                    } catch (Throwable $error) { $state['route'] = 'unknown'; $state['routing_error'] = true; }
                    XrayConfig::atomic(HEALTH_DIR . '/' . $id . '.json', XrayConfig::encode($state));
                } finally { flock($lock, LOCK_UN); fclose($lock); }
            } elseif ($lock) { fclose($lock); }
        }
        // Keep waiting when a minute-aligned cron arrives just before the next check.
        // Otherwise a 1 s request would turn a 60 s setting into a 120 s interval.
        $nextAt = ($state['checked_at'] ?? time()) + $settings['interval'];
        if (($nextAt - time()) * 1000000000 > $end - hrtime(true)) { break; }
        usleep(200000);
    } while (hrtime(true) < $end);
    echo "OK: heartbeat checked\n";
} catch (Throwable $error) { echo "ERROR: heartbeat unavailable; review private policy settings\n"; exit(1); }

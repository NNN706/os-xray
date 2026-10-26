<?php

function xray_enabled(): bool
{
    $cfg = OPNsense\Core\Config::getInstance()->object();
    return isset($cfg->OPNsense->xray->general->enabled)
        && (string)$cfg->OPNsense->xray->general->enabled === '1';
}

function xray_configure(): array
{
    return ['bootup' => ['xray_configure_do:10']];
}

/** Restore only the last applied profile through the common lifecycle transaction. */
function xray_configure_do(bool $verbose = false): void
{
    if (!xray_enabled()) { return; }
    exec('/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-service-control.php boot', $output, $rc);
    if ($verbose) { echo $rc === 0 ? "xray: recovery checked\n" : "xray: boot recovery failed\n"; }
}

/**
 * v3.0.0: регистрирует один сервис на каждый инстанс.
 */
function xray_services()
{
    $services  = [];
    $cfg       = OPNsense\Core\Config::getInstance()->object();
    $instances = $cfg->OPNsense->xray->instances ?? null;

    if ($instances) {
        foreach ($instances->instance as $inst) {
            $inst_uuid = (string)$inst['uuid'];
            if ($inst_uuid === '' || (string)($inst->enabled ?? '1') !== '1') {
                continue;
            }
            $name       = (string)($inst->name ?? 'default');
            $services[] = [
                'description' => 'Xray (' . $name . ')',
                'configd'     => [
                    'restart' => ['xray restart ' . $inst_uuid],
                    'start'   => ['xray start '   . $inst_uuid],
                    'stop'    => ['xray stop '    . $inst_uuid],
                ],
                'name'    => 'xray_' . $inst_uuid,
                'pidfile' => "/var/run/xray_core_{$inst_uuid}.pid",
            ];
        }
    }

    return $services;
}

/**
 * E1: регистрирует cron-задание для watchdog.
 */
function xray_cron(): array
{
    return [
        ['autocron' => ['/usr/local/sbin/configctl xray schedule', '*']],
        ['autocron' => ['/usr/local/sbin/configctl xray watchdog', '*']],
        ['autocron' => ['/usr/local/sbin/configctl xray heartbeat', '*']],
    ];
}

function xray_xmlrpc_sync()
{
    return [];
}

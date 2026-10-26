<?php
/** Deterministic health state; routing success is recorded separately from probe success. */
final class XrayHealth
{
    const INTERVALS = [15, 30, 60, 120, 300];
    public static function settings(array $profile): array
    {
        $rawInterval = (string)($profile['health_interval'] ?? '60');
        if (!preg_match('/^[1-9][0-9]*$/D', $rawInterval)) { throw new RuntimeException('Invalid health interval'); }
        $interval = (int)$rawInterval;
        if (!in_array($interval, self::INTERVALS, true)) { throw new RuntimeException('Invalid health interval'); }
        $mode = $profile['routing_mode'] ?? 'off';
        if (!in_array($mode, ['off', 'all', 'networks'], true)) { throw new RuntimeException('Invalid managed traffic mode'); }
        if (($profile['health_bypass'] ?? '0') === '1' && (($profile['health_enabled'] ?? '0') !== '1' || $mode === 'off')) { throw new RuntimeException('WAN bypass requires heartbeat and managed traffic'); }
        return ['enabled' => ($profile['health_enabled'] ?? '0') === '1', 'interval' => $interval, 'fallback' => ($profile['health_bypass'] ?? '0') === '1' && $mode !== 'off'];
    }
    public static function sample(?array $previous, array $probe, array $components, int $now): array
    {
        $ok = ($probe['result'] ?? '') === 'ok' && !in_array(false, $components, true);
        $successes = $ok ? min(3, (int)($previous['successes'] ?? 0) + 1) : 0;
        $desired = !$ok ? 'bypass' : ($successes >= 3 ? 'proxy' : ($previous['desired'] ?? 'bypass'));
        $history = $previous['history'] ?? [];
        $history[] = ['at' => $now, 'ok' => $ok, 'http_code' => $probe['http_code'] ?? 0, 'latency_ms' => $probe['latency_ms'] ?? null, 'timed_out' => $probe['timed_out'] ?? false];
        return ['checked_at' => $now, 'healthy' => $ok, 'successes' => $successes, 'desired' => $desired, 'route' => $previous['route'] ?? 'unknown', 'components' => $components, 'probe' => $probe, 'history' => array_slice($history, -60)];
    }
}

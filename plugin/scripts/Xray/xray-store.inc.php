<?php
require_once __DIR__ . '/xray-subscription.inc.php';
final class XrayStore
{
    const DIR = '/usr/local/etc/xray-core/profiles';
    public static function path(string $uuid, string $kind): string
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $uuid) || !in_array($kind, ['prepared', 'applied', 'subscription'], true)) { throw new RuntimeException('Invalid profile identity'); }
        return self::DIR . '/' . $uuid . '-' . $kind . '.json';
    }
    public static function read(string $uuid, string $kind): ?array
    {
        $path = self::path($uuid, $kind);
        return is_file($path) ? json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR) : null;
    }
    public static function write(string $uuid, string $kind, array $value): void
    {
        XrayConfig::atomic(self::path($uuid, $kind), XrayConfig::encode($value));
    }
    public static function profiles(): array
    {
        $root = OPNsense\Core\Config::getInstance()->object();
        $enabled = (string)($root->OPNsense->xray->general->enabled ?? '0') === '1';
        $profiles = [];
        foreach ($root->OPNsense->xray->instances->instance ?? [] as $instance) {
            $uuid = (string)$instance['uuid'];
            self::path($uuid, 'prepared');
            $c = ['inst_uuid' => $uuid, 'enabled' => $enabled && (string)($instance->enabled ?? '1') === '1'];
            foreach (['health_enabled', 'health_interval', 'health_bypass', 'routing_mode', 'routing_rules', 'routing_cidrs', 'routing_asns', 'routing_tun4', 'routing_wan4', 'routing_tun6', 'routing_wan6', 'name', 'config_json', 'outbound_config', 'socks_inbound', 'subscription_url', 'subscription_remarks', 'subscription_hash', 'subscription_baseline', 'subscription_metadata', 'subscription_fetched', 'auto_update', 'update_mode', 'update_hours', 'socks5_listen', 'socks5_port', 'bypass_networks', 'loglevel'] as $field) { $c[$field] = (string)($instance->$field ?? ''); }
            $c['health_interval'] = $c['health_interval'] ?: '60';
            $c['routing_mode'] = $c['routing_mode'] ?: 'off';
            $c['use_config_dns'] = (string)($instance->use_config_dns ?? '1') !== '0';
            $c['tun_iface'] = (string)($instance->tun_interface ?? 'proxytun2socks0');
            $c['mtu'] = (int)(string)($instance->mtu ?? '1500');
            if ($c['config_json'] === '' && $c['outbound_config'] !== '') {
                try {
                    $ob = json_decode($c['outbound_config'], false, 128, JSON_THROW_ON_ERROR);
                    if (!$ob instanceof stdClass) { throw new RuntimeException('Legacy outbound is not an object'); }
                    $c['config_json'] = XrayConfig::encode(XrayConfig::wrap($ob, $c['socks5_listen'] ?: '127.0.0.1', (int)($c['socks5_port'] ?: 10808), $c['bypass_networks'] ?: '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16', $c['loglevel'] === 'loglevel_error' ? 'error' : ($c['loglevel'] ?: 'warning')));
                } catch (Throwable $error) { $c['config_json'] = ''; }
            }
            $c['source_hash'] = hash('sha256', $c['config_json'] . "\0" . $c['subscription_url'] . "\0" . $c['subscription_remarks']);
            $draft = self::read($uuid, 'prepared');
            if ($draft && hash_equals($c['source_hash'], $draft['source_hash'] ?? '')) {
                $c['config_json'] = $draft['config_json'];
                $c['name'] = $draft['name'];
                $c['subscription_metadata'] = json_encode($draft['metadata']);
                $c['subscription_hash'] = $draft['subscription_hash'];
                $c['subscription_baseline'] = $draft['subscription_baseline'] ?? $draft['config_json'];
                $c['subscription_fetched'] = (string)$draft['fetched_at'];
            }
            $profiles[$uuid] = $c;
        }
        return $profiles;
    }

    public static function schedule(array $c, int $now): array
    {
        $meta = json_decode($c['subscription_metadata'] ?: '{}', true) ?: [];
        $hours = $c['update_mode'] === 'manual' ? (int)$c['update_hours'] : ($meta['interval_hours'] ?? null);
        if ($c['auto_update'] !== '1') { return ['due' => false, 'reason' => 'Auto update is off']; }
        if ($c['subscription_url'] === '') { return ['due' => false, 'reason' => 'Import a subscription URL first']; }
        if (!is_int($hours) || $hours < 1 || ($c['update_mode'] === 'manual' && $hours > 24)) { return ['due' => false, 'reason' => 'Missing or invalid positive profile-update-interval header']; }
        $last = (int)$c['subscription_fetched'];
        return ['due' => $now >= $last + $hours * 3600, 'reason' => '', 'next_update' => $last + $hours * 3600];
    }

    public static function update(array $c): void
    {
        $uuid = $c['inst_uuid'];
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0700, true) && !is_dir(self::DIR)) { throw new RuntimeException('Private profile storage unavailable'); }
        $lock = fopen(self::DIR . '/' . $uuid . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) { fclose($lock); } return; }
        try {
            // Re-read after obtaining the profile lock; another tick may have updated it.
            $current = self::profiles()[$uuid] ?? null;
            if (!$current || !self::schedule($current, time())['due']) { return; }
            $download = XraySubscription::fetch($current['subscription_url']);
            $config = XraySubscription::select($download['configurations'], $current['subscription_remarks']);
            $json = XrayConfig::encode($config);
            XrayConfig::socks($config, $current['socks_inbound']);
            XrayConfig::validate($json, $current['use_config_dns']);
            // Reject a response fetched for settings that changed during the download.
            $configLock = OPNsense\Core\Config::getInstance();
            $configLock->lock();
            try {
                $fresh = self::profiles()[$uuid] ?? null;
                if (!$fresh || $fresh['source_hash'] !== $current['source_hash'] || $fresh['use_config_dns'] !== $current['use_config_dns'] || $fresh['socks_inbound'] !== $current['socks_inbound'] || $fresh['auto_update'] !== '1') { return; }
                self::write($uuid, 'prepared', ['source_hash' => $current['source_hash'], 'config_json' => $json, 'name' => XrayConfig::name($config, $current['name']), 'metadata' => $download['metadata'], 'subscription_baseline' => $json, 'subscription_hash' => hash('sha256', XrayConfig::canonical($config)), 'fetched_at' => $download['fetched_at']]);
            } finally { $configLock->unlock(); }
            self::write($uuid, 'subscription', ['result' => 'ok', 'message' => 'Downloaded; Apply or reconnect to activate', 'checked_at' => time()]);
        } catch (Throwable $error) {
            // Never persist exception text from JSON/core/network libraries (may contain secrets).
            self::write($uuid, 'subscription', ['result' => 'failed', 'message' => 'Update rejected; previous prepared and applied configurations retained', 'checked_at' => time()]);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}

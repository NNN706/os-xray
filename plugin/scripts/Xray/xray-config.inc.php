<?php
/** Pure configuration operations shared by GUI, scheduler and runtime. */
final class XrayConfig
{
    public static function decode(string $json): stdClass
    {
        if (strlen($json) > 4194304) {
            throw new RuntimeException('Configuration exceeds 4 MiB');
        }
        $value = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
        if (!$value instanceof stdClass) {
            throw new RuntimeException('Expected a full JSON configuration object');
        }
        if (!isset($value->outbounds) || !is_array($value->outbounds) || !$value->outbounds) {
            throw new RuntimeException('Configuration needs a non-empty outbounds array');
        }
        return $value;
    }

    public static function encode($value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function canonical($value): string
    {
        if ($value instanceof stdClass) {
            $items = get_object_vars($value);
            ksort($items, SORT_STRING);
            $normalized = new stdClass();
            foreach ($items as $key => $item) {
                $normalized->$key = json_decode(self::canonical($item));
            }
            return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map([self::class, 'canonical'], $value)) . ']';
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function effective(string $json, bool $dns): string
    {
        $config = self::decode($json);
        if (!$dns) {
            unset($config->dns);
        }
        return $dns ? $json : self::encode($config);
    }

    public static function name(stdClass $config, string $fallback = ''): string
    {
        foreach (['remarks', 'Remarks'] as $key) {
            $name = $config->$key ?? null;
            if (is_string($name) && trim($name) !== '') { return $name; }
        }
        return $fallback;
    }

    public static function socks(stdClass $config, string $selected = ''): array
    {
        $choices = [];
        foreach ($config->inbounds ?? [] as $index => $inbound) {
            if (!is_object($inbound) || ($inbound->protocol ?? '') !== 'socks') {
                continue;
            }
            $host = $inbound->listen ?? '';
            $port = $inbound->port ?? null;
            $loopback = $host === '::1' || (is_string($host) && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && explode('.', $host)[0] === '127');
            if (!$loopback || !is_int($port) || $port < 1 || $port > 65535 || ($inbound->settings->auth ?? 'noauth') !== 'noauth') {
                continue;
            }
            $tag = (string)($inbound->tag ?? '');
            $choices[] = ['id' => $tag !== '' ? 'tag:' . $tag : 'endpoint:' . $host . ':' . $port, 'tag' => $tag, 'host' => $host, 'port' => $port];
        }
        if ($selected !== '') {
            $matches = array_values(array_filter($choices, static function ($choice) use ($selected) { return $choice['id'] === $selected; }));
            if (count($matches) === 1) { return $matches[0]; }
            throw new RuntimeException('Selected SOCKS inbound is missing, ambiguous or unsuitable');
        }
        $preferred = array_values(array_filter($choices, static function ($c) { return $c['tag'] === 'socks'; }));
        if (count($preferred) === 1) {
            return $preferred[0];
        }
        if (count($choices) === 1) {
            return $choices[0];
        }
        throw new RuntimeException($choices ? 'Select a SOCKS inbound: multiple loopback SOCKS inbounds found' : 'A loopback no-auth SOCKS inbound is required');
    }

    /** Only used for migration / explicit VLESS import, never for full JSON. */
    public static function wrap(stdClass $outbound, string $host = '127.0.0.1', int $port = 10808, string $bypass = '', string $level = 'warning', bool $http = false): stdClass
    {
        $config = (object)[
            'log' => (object)['loglevel' => $level],
            'inbounds' => [(object)['tag' => 'socks', 'listen' => $host, 'port' => $port, 'protocol' => 'socks', 'settings' => (object)['auth' => 'noauth', 'udp' => true]]],
            'outbounds' => [$outbound],
        ];
        if ($http) {
            $config->inbounds[] = (object)['tag' => 'http', 'listen' => '127.0.0.1', 'port' => 10809, 'protocol' => 'http', 'settings' => new stdClass()];
        }
        if ($bypass !== '') {
            $config->outbounds[] = (object)['tag' => 'direct', 'protocol' => 'freedom'];
            $config->routing = (object)['domainStrategy' => 'IPIfNonMatch', 'rules' => [(object)['type' => 'field', 'ip' => array_values(array_filter(array_map('trim', explode(',', $bypass)))), 'outboundTag' => 'direct']]];
        }
        return $config;
    }

    public static function atomic(string $path, string $body): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create private storage');
        }
        $temp = tempnam($dir, '.xray-');
        if ($temp === false) {
            throw new RuntimeException('Cannot stage configuration');
        }
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, $body) !== strlen($body) || !rename($temp, $path)) {
                throw new RuntimeException('Cannot commit configuration');
            }
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
    }

    public static function validate(string $json, bool $dns, string $binary = '/usr/local/bin/xray-core'): void
    {
        $effective = self::effective($json, $dns);
        if (!is_executable($binary)) {
            throw new RuntimeException('Installed Xray-core is unavailable');
        }
        $dir = sys_get_temp_dir() . '/xray-check-' . bin2hex(random_bytes(16));
        if (!mkdir($dir, 0700)) { throw new RuntimeException('Cannot create validation directory'); }
        try {
            self::atomic($dir . '/config.json', $effective);
            // Do not return core output: diagnostics can contain subscription credentials.
            $pipes = [];
            $process = proc_open([$binary, 'run', '-test', '-c', $dir . '/config.json'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot execute Xray validation'); }
            $deadline = microtime(true) + 10;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($status['running']) { proc_terminate($process, 9); }
            $closed = proc_close($process);
            if ($status['running'] || ($status['exitcode'] < 0 ? $closed : $status['exitcode']) !== 0) {
                throw new RuntimeException('Xray-core rejected the effective configuration (or validation timed out)');
            }
        } finally {
            @unlink($dir . '/config.json');
            @rmdir($dir);
        }
    }
}

<?php
require_once __DIR__ . '/xray-config.inc.php';
final class XraySubscription
{
    public static function url(string $url, bool $httpsOnly = true): void
    {
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', $httpsOnly ? ['https'] : ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new RuntimeException($httpsOnly ? 'Subscription URL must be HTTPS without embedded credentials' : 'Check URL must be HTTP or HTTPS without embedded credentials');
        }
    }

    public static function metadata(array $headers): array
    {
        $interval = $headers['profile-update-interval'] ?? '';
        $valid = preg_match('/^[1-9][0-9]*$/D', $interval) && filter_var($interval, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 3600)]]) !== false;
        $hours = $valid ? (int)$interval : null;
        $type = $headers['ping-type'] ?? 'proxy-head';
        $target = $headers['check-url-via-proxy'] ?? 'https://cp.cloudflare.com/generate_204';
        self::url($target, false);
        return ['interval_hours' => $hours, 'interval_reason' => $hours ? '' : 'Missing or invalid positive profile-update-interval header', 'ping_type' => in_array($type, ['proxy', 'proxy-head'], true) ? $type : 'proxy-head', 'check_url' => $target];
    }

    /** Enforce a wall-clock ceiling even on curl builds with blocking resolvers. */
    public static function waitForRequest($process, int $milliseconds): int
    {
        $deadline = microtime(true) + $milliseconds / 1000;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) { return $status['exitcode']; }
            if (microtime(true) >= $deadline) { proc_terminate($process, 9); return 28; }
            usleep(5000);
        } while (true);
    }

    public static function fetch(string $url): array
    {
        self::url($url);
        $dir = sys_get_temp_dir() . '/xray-fetch-' . bin2hex(random_bytes(16));
        if (!mkdir($dir, 0700)) { throw new RuntimeException('Cannot create fetch directory'); }
        try {
            $body = $dir . '/body'; $headers = $dir . '/headers';
            // URL travels over stdin, never argv/configd logs. curl owns one total deadline.
            $config = 'url = ' . json_encode($url, JSON_UNESCAPED_SLASHES) . "\n";
            $pipes = [];
            $process = proc_open(['/usr/local/bin/curl', '--disable', '--config', '-', '--silent', '--fail', '--location', '--max-redirs', '5', '--proto', '=https', '--proto-redir', '=https', '--max-time', '2', '--max-filesize', '4194304', '--user-agent', 'OPNSense', '--dump-header', $headers, '--output', $body], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start subscription request'); }
            fwrite($pipes[0], $config); fclose($pipes[0]);
            $exit = self::waitForRequest($process, 2000);
            $closed = proc_close($process);
            $rc = $exit >= 0 ? $exit : $closed;
            if ($rc !== 0) { throw new RuntimeException($rc === 28 ? 'Subscription timed out (2000 ms)' : 'Subscription download failed (HTTP, TLS or network error)'); }
            $raw = file_get_contents($body);
            if ($raw === false || strlen($raw) > 4194304) { throw new RuntimeException('Invalid subscription size'); }
            $decoded = json_decode($raw, false, 128, JSON_THROW_ON_ERROR);
            $configs = $decoded instanceof stdClass ? [$decoded] : $decoded;
            if (!is_array($configs) || !$configs || count($configs) > 100) { throw new RuntimeException('Expected a configuration object or a non-empty configuration array (maximum 100)'); }
            foreach ($configs as $config) { XrayConfig::decode(XrayConfig::encode($config)); }
            $parsed = [];
            foreach (preg_split('/\r?\n/', (string)file_get_contents($headers)) as $line) {
                if (preg_match('~^HTTP/\S+\s+\d+~', $line)) { $parsed = []; }
                elseif (strpos($line, ':') !== false) { [$key, $value] = explode(':', $line, 2); $parsed[strtolower(trim($key))] = trim($value); }
            }
            return ['configurations' => $configs, 'metadata' => self::metadata($parsed), 'fetched_at' => time()];
        } finally {
            @unlink($dir . '/body'); @unlink($dir . '/headers'); @rmdir($dir);
        }
    }

    public static function select(array $configs, string $remarks): stdClass
    {
        if (count($configs) === 1) { return $configs[0]; }
        $matches = array_values(array_filter($configs, static function ($config) use ($remarks) { return XrayConfig::name($config) === $remarks; }));
        if (count($matches) !== 1 || $remarks === '') { throw new RuntimeException('Subscription selection is missing or ambiguous; import again and choose a unique remarks'); }
        return $matches[0];
    }
}

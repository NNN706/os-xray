<?php
require_once __DIR__ . '/xray-subscription.inc.php';
/** A single request over the applied SOCKS; no ICMP, DNS changes or direct fallback. */
final class XrayProbe
{
    const TARGET = 'https://cp.cloudflare.com/generate_204';
    public static function run(array $socks, string $target = self::TARGET, string $method = 'HEAD', int $timeout = 2000, ?int $expected = 204): array
    {
        XraySubscription::url($target, false);
        if (!in_array($timeout, [1000, 2000], true) || !in_array($method, ['HEAD', 'GET'], true)) { throw new RuntimeException('Invalid probe settings'); }
        $host = $socks['host'] ?? '';
        if (!filter_var($host, FILTER_VALIDATE_IP) || (!preg_match('/^127\./', $host) && $host !== '::1') || !is_int($socks['port'] ?? null) || $socks['port'] < 1 || $socks['port'] > 65535) { throw new RuntimeException('Invalid applied SOCKS endpoint'); }
        $host = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        $args = ['/usr/local/bin/curl', '--disable', '--silent', '--noproxy', '', '--location', '--max-redirs', '5', '--proto', '=http,https', '--proto-redir', '=http,https', '--max-time', (string)($timeout / 1000), '--socks5-hostname', $host . ':' . $socks['port'], '--output', '/dev/null', '--write-out', '%{http_code} %{time_starttransfer}', '--config', '-'];
        if ($method === 'HEAD') { $args[] = '--head'; }
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start connection check'); }
        fwrite($pipes[0], 'url = ' . json_encode($target, JSON_UNESCAPED_SLASHES) . "\n"); fclose($pipes[0]);
        $exit = XraySubscription::waitForRequest($process, $timeout);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $closed = proc_close($process); $rc = $exit >= 0 ? $exit : $closed;
        $values = explode(' ', trim($output)); $code = (int)($values[0] ?? 0);
        $latency = $rc === 0 && $code > 0 && isset($values[1]) ? (int)round((float)$values[1] * 1000) : null;
        $ok = $rc === 0 && ($expected === null ? $code >= 200 && $code < 400 : $code === $expected);
        $message = $rc === 28 ? 'Timeout (' . $timeout . ' ms) — connection check did not complete' : ($ok ? 'OK (' . $code . ') — connection works — HTTP latency: ' . $latency . ' ms' : ($code ? 'HTTP ' . $code . ' — connection check failed' : 'Could not connect through the active SOCKS inbound'));
        return ['result' => $ok ? 'ok' : 'failed', 'http_code' => $code, 'latency_ms' => $latency, 'timed_out' => $rc === 28, 'message' => $message];
    }
}

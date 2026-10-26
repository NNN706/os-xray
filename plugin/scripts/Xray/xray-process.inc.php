<?php
final class XrayProcess
{
    public static function alive(string $pidfile, string $binary, string $config): bool
    {
        if (!is_file($pidfile)) { return false; }
        $pid = (int)trim((string)file_get_contents($pidfile));
        if ($pid <= 0) { return false; }
        $command = trim((string)shell_exec('ps -ww -o command= -p ' . $pid . ' 2>/dev/null'));
        // A reused PID is not sufficient evidence that the managed service is alive.
        return strpos($command, $binary) !== false && strpos($command, $config) !== false;
    }
}

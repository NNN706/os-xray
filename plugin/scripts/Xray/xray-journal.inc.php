<?php
final class XrayJournal
{
    private const DIR = '/var/log/os-xray';
    private const LIMIT = 10485760;
    private const STREAMS = ['core', 'access', 'tunnel', 'subscriptions'];

    public static function path(string $stream): string
    {
        if (!in_array($stream, self::STREAMS, true)) {
            throw new InvalidArgumentException('Unknown Xray journal');
        }
        return self::DIR . '/' . $stream . '.log';
    }

    public static function prepare(): void
    {
        if (!is_dir(self::DIR) && !@mkdir(self::DIR, 0700) && !is_dir(self::DIR)) {
            throw new RuntimeException('Cannot create private Xray journal directory');
        }
        self::verify(self::DIR, 0040700);
        foreach (self::STREAMS as $stream) {
            $path = self::path($stream);
            if (@lstat($path) === false) {
                $mask = umask(0077);
                try {
                    $file = @fopen($path, 'x');
                    if ($file) { fclose($file); }
                } finally { umask($mask); }
            }
            self::verify($path, 0100600);
        }
    }

    private static function verify(string $path, int $mode): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!$stat || $stat['uid'] !== 0 || ($stat['mode'] & 0177777) !== $mode
            || ($mode === 0100600 && $stat['nlink'] !== 1)) {
            throw new RuntimeException('Unsafe Xray journal permissions');
        }
    }

    public static function subscriptionResult(bool $success): void
    {
        try {
            self::prepare();
            $status = $success ? 'Downloaded and staged' : 'Update rejected';
            @file_put_contents(self::path('subscriptions'), gmdate('c') . ' ' . $status . "\n", FILE_APPEND);
        } catch (RuntimeException $error) {
            // Journal availability must not change the subscription result.
        }
    }

    public static function trim(): void
    {
        self::prepare();
        foreach (self::STREAMS as $stream) {
            $file = fopen(self::path($stream), 'r+');
            try {
                // Keep the inode: Xray and daemon keep append descriptors open.
                if (fstat($file)['size'] >= self::LIMIT && !ftruncate($file, 0)) {
                    throw new RuntimeException('Cannot truncate Xray journal');
                }
            } finally { fclose($file); }
        }
    }
}

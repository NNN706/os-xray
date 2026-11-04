#!/usr/local/bin/php
<?php
require_once __DIR__ . '/xray-journal.inc.php';
try {
    $stream = $argv[1] ?? 'core';
    if ($stream === 'trim') {
        XrayJournal::trim();
        exit;
    }
    $path = XrayJournal::path($stream);
    XrayJournal::prepare();
    $file = fopen($path, 'r');
    try {
        $offset = max(0, fstat($file)['size'] - 65536);
        fseek($file, $offset);
        $text = stream_get_contents($file, 65536);
        if ($offset > 0 && ($newline = strpos($text, "\n")) !== false) {
            $text = substr($text, $newline + 1);
        }
        echo $text;
    } finally { fclose($file); }
} catch (Throwable $error) {
    echo "Xray journal unavailable\n";
    exit(1);
}

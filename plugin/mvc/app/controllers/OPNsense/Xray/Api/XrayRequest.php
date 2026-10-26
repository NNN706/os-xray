<?php
namespace OPNsense\Xray\Api;
use OPNsense\Core\Backend;
/** Transfer secret request bodies to configd using opaque tickets, never command arguments. */
final class XrayRequest
{
    public static function run(string $action, array $body): array
    {
        $id = bin2hex(random_bytes(16));
        $path = '/var/tmp/xray-api/' . $id;
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4200000) { return ['result' => 'failed', 'message' => 'Request exceeds 4 MiB']; }
        if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0700); }
        $fd = @fopen($path, 'x');
        if (!$fd) { return ['result' => 'failed', 'message' => 'Private API storage unavailable; reinstall plugin']; }
        try {
            chmod($path, 0600);
            if (fwrite($fd, $encoded) !== strlen($encoded)) { throw new \RuntimeException('Cannot stage request'); }
            fclose($fd); $fd = null;
            $output = (new Backend())->configdRun('xray ' . $action . ' ' . $id);
            return json_decode($output, true) ?: ['result' => 'failed', 'message' => 'No valid response from configd'];
        } finally { if (is_resource($fd)) { fclose($fd); } if (is_file($path)) { unlink($path); } }
    }
}

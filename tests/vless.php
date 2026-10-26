<?php
namespace OPNsense\Base { class ApiControllerBase { public $request; } }
namespace {
    $dir = sys_get_temp_dir() . '/xray-vless-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    try {
        $base = __DIR__ . '/../plugin/mvc/app/controllers/OPNsense/Xray/Api/';
        copy(__DIR__ . '/../plugin/scripts/Xray/xray-config.inc.php', $dir . '/xray-config.inc.php');
        copy($base . 'XrayRequest.php', $dir . '/XrayRequest.php');
        $source = str_replace("'/usr/local/opnsense/scripts/Xray/xray-config.inc.php'", var_export($dir . '/xray-config.inc.php', true), file_get_contents($base . 'ImportController.php'));
        file_put_contents($dir . '/ImportController.php', $source);
        require $dir . '/ImportController.php';
        class FakeRequest {
            public $link;
            function __construct($link) { $this->link = $link; }
            function isPost() { return true; }
            function getPost($key, ...$rest) { return $key === 'link' ? $this->link : ''; }
        }
        function parseLink($suffix) {
            $controller = new OPNsense\Xray\Api\ImportController();
            $controller->request = new FakeRequest('vless://11111111-1111-4111-8111-111111111111@example.test:443?' . $suffix);
            return $controller->parseAction();
        }
        function verify($ok, $message) { if (!$ok) { throw new RuntimeException($message); } echo "PASS $message\n"; }
        $data = parseLink('type=tcp&security=none#Basic');
        $config = json_decode($data['config_json']);
        verify($data['status'] === 'ok' && count($config->inbounds) === 2 && $config->inbounds[0]->port === 10808 && $config->inbounds[1]->port === 10809, 'VLESS creates a complete SOCKS/HTTP configuration');
        verify($config->outbounds[0]->settings->vnext[0]->users[0]->flow === '' && $config->remarks === 'Basic', 'VLESS does not invent Vision or encode remarks as HTML');
        $data = parseLink('type=raw&security=reality&flow=xtls-rprx-vision&fp=invalid&pbk=placeholder');
        $config = json_decode($data['config_json']);
        verify($config->outbounds[0]->settings->vnext[0]->users[0]->flow === 'xtls-rprx-vision' && $config->outbounds[0]->streamSettings->network === 'raw', 'RAW keeps explicit Vision flow');
        verify($config->outbounds[0]->streamSettings->realitySettings->fingerprint === 'chrome', 'Validated fingerprint is actually used');
        $data = parseLink('type=ws&security=tls&host=example.test&path=%2Fws');
        $config = json_decode($data['config_json']);
        verify($config->outbounds[0]->streamSettings->wsSettings->host === 'example.test' && !isset($config->outbounds[0]->streamSettings->wsSettings->headers), 'WebSocket uses host field');
        verify(parseLink('type=h2&security=tls')['status'] === 'error', 'Removed HTTP transport is rejected explicitly');
    } finally { foreach (glob($dir . '/*') as $path) { unlink($path); } rmdir($dir); }
}

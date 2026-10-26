<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;
require_once __DIR__ . '/XrayRequest.php';

/**
 * Service control: start / stop / reconfigure / status / log / testconnect.
 *
 * v3.0.0: все service-actions принимают опциональный $uuid инстанса.
 * Если $uuid пустой — действие применяется ко всем инстансам.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\OPNsense\Xray\General';
    protected static $internalServiceTemplate = 'OPNsense/Xray';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'xray';

    /**
     * Санитизирует UUID аргумент из URL: оставляет только hex-символы и дефисы.
     */
    private function sanitizeUuid(string $uuid): string
    {
        if ($uuid !== '' && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $uuid)) { throw new \OPNsense\Base\UserException('Invalid profile UUID'); }
        return strtolower($uuid);
    }

    /**
     * BUG-12 FIX: возвращает реальный статус reconfigure.
     */
    public function reconfigureAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray reconfigure' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

        if (empty($output)) {
            return ['result' => 'failed', 'message' => 'No response from configd (timeout or service unavailable)'];
        }

        $hasError   = stripos($output, 'ERROR')   !== false
                   || stripos($output, 'failed')  !== false;
        $hasSuccess = stripos($output, 'OK')       !== false
                   || stripos($output, 'disabled') !== false;

        if ($hasError || !$hasSuccess) {
            return ['result' => 'failed', 'message' => $output];
        }

        return ['result' => 'ok', 'message' => $output];
    }

    public function statusAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray status' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $result  = $backend->configdRun($cmd);
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => trim($result)];
    }

    /**
     * GET /api/xray/service/statusAll — статус всех инстансов.
     */
    public function statusAllAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray statusall');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['error' => trim($result)];
    }

    public function startAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray start' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function stopAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray stop' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        return [
            'result'  => empty($output) ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function restartAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray restart' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        $failed  = empty($output)
                || stripos($output, 'ERROR')  !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    /**
     * BUG-5 FIX: POST-only — лог содержит чувствительные данные.
     */
    public function logAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = $backend->configdRun('xray log');
        return ['log' => $output];
    }

    /**
     * BUG-5 FIX: POST-only.
     */
    public function xraylogAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $output  = $backend->configdRun('xray xraylog' . ($uuid !== '' ? ' ' . $uuid : ''));
        return ['log' => $output];
    }

    /**
     * E5: POST /api/xray/service/validate[/{uuid}]
     */
    public function validateAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $body = $this->request->getJsonRawBody(true);
        if (!is_array($body) || !isset($body['config_json'])) {
            return ['result' => 'failed', 'message' => 'Current editor JSON is required'];
        }
        return XrayRequest::run('draftvalidate', $body);
    }

    /**
     * GET /api/xray/service/version
     */
    public function versionAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('xray version');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['version' => 'unknown'];
    }

    /**
     * E4: GET /api/xray/service/diagnostics[/{uuid}]
     */
    public function diagnosticsAction($uuid = '')
    {
        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray ifstats' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));
        if (empty($output)) {
            return ['error' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON from ifstats: ' . $output];
        }
        return $data;
    }

    /**
     * I8: POST /api/xray/service/testconnect[/{uuid}]
     */
    public function testconnectAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'http_code' => 0, 'latency_ms' => null, 'timed_out' => false, 'message' => 'POST required'];
        }

        $uuid    = $this->sanitizeUuid((string)$uuid);
        $cmd     = 'xray testconnect' . ($uuid !== '' ? ' ' . $uuid : '');
        $backend = new Backend();
        $output  = trim($backend->configdRun($cmd));

        $decoded = json_decode($output, true);
        return is_array($decoded) ? $decoded : ['result' => 'failed', 'http_code' => 0, 'latency_ms' => null, 'timed_out' => false, 'message' => 'No valid response from connection check'];
    }
    public function healthAction()
    {
        return json_decode((new Backend())->configdRun('xray healthstatus'), true) ?: ['result'=>'failed', 'message'=>'Health status unavailable'];
    }
    public function routingOptionsAction()
    {
        return json_decode((new Backend())->configdRun('xray routingoptions'), true) ?: ['result'=>'failed', 'message'=>'Routing options unavailable'];
    }
}

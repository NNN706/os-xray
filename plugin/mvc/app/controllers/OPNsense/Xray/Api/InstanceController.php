<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Base\UserException;
require_once __DIR__ . '/XrayRequest.php';
require_once '/usr/local/opnsense/scripts/Xray/xray-subscription.inc.php';
require_once '/usr/local/opnsense/scripts/Xray/xray-health.inc.php';
require_once '/usr/local/opnsense/scripts/Xray/xray-routing.inc.php';

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\Xray\Instance';
    protected static $internalModelName  = 'instance';

    protected function setBaseHook($node)
    {
        $json = (string)$node->config_json;
        try {
            \XrayHealth::settings(['health_interval'=>(string)$node->health_interval, 'routing_mode'=>(string)$node->routing_mode]);
            \XrayRouting::ids((string)$node->routing_rules);
            if ((string)$node->health_bypass === '1' && ((string)$node->health_enabled !== '1' || (string)$node->routing_mode === 'off')) { throw new \RuntimeException('WAN bypass requires heartbeat and enrolled traffic'); }
            $config = \XrayConfig::decode($json);
            if ((string)$node->subscription_baseline !== '') { \XrayConfig::decode((string)$node->subscription_baseline); }
            $url = (string)$node->subscription_url;
            if ($url !== '') { \XraySubscription::url($url); }
            $metadata = json_decode((string)$node->subscription_metadata ?: '{}', false, 16, JSON_THROW_ON_ERROR);
            if (!$metadata instanceof \stdClass) { throw new \RuntimeException('Invalid subscription metadata'); }
            $hours = $metadata->interval_hours ?? null;
            if ($hours !== null && (!is_int($hours) || $hours < 1 || $hours > intdiv(PHP_INT_MAX, 3600))) { throw new \RuntimeException('Invalid subscription interval'); }
            $checkedMetadata = \XraySubscription::metadata(['profile-update-interval' => $hours === null ? '' : (string)$hours, 'ping-type' => (string)($metadata->ping_type ?? 'proxy-head'), 'check-url-via-proxy' => (string)($metadata->check_url ?? 'https://cp.cloudflare.com/generate_204')]);
            $node->subscription_metadata = json_encode($checkedMetadata);
            if (!in_array((string)$node->update_mode, ['manual', 'subscription'], true)) { throw new \RuntimeException('Invalid update interval mode'); }
            $check = XrayRequest::run('draftvalidate', ['config_json' => $json, 'use_config_dns' => (string)$node->use_config_dns === '1', 'socks_inbound' => (string)$node->socks_inbound]);
            if (($check['result'] ?? '') !== 'ok') { throw new \RuntimeException($check['message'] ?? 'Configuration validation failed'); }
            $node->name = \XrayConfig::name($config, (string)$node->name);
        } catch (\Throwable $error) { throw new UserException($error instanceof \JsonException ? 'Invalid JSON configuration' : $error->getMessage()); }
    }

    public function preparedAction($uuid)
    {
        if (!preg_match('/^[a-f0-9-]{36}$/Di', (string)$uuid)) { return ['result' => 'failed']; }
        return json_decode((new Backend())->configdRun('xray prepared ' . $uuid), true) ?: ['result' => 'failed'];
    }

    public function searchItemAction()
    {
        $summary = json_decode((new Backend())->configdRun('xray preparedsummary'), true) ?: [];
        foreach ($summary as $uuid => $item) {
            if (!preg_match('/^[a-f0-9-]{36}$/Di', (string)$uuid) || !is_array($item)) { continue; }
            $node = $this->getModel()->getNodeByReference('instance.' . $uuid);
            if ($node !== null && isset($item['name'])) { $node->name = (string)$item['name']; }
        }
        $response = $this->searchBase('instance', ['enabled', 'name', 'config_json', 'outbound_config']);
        if (!empty($response['rows'])) {
            foreach ($response['rows'] as &$row) {
                $full = json_decode($row['config_json'] ?? '', true);
                $ob = $full['outbounds'][0] ?? json_decode($row['outbound_config'] ?? '', true);
                $vnext = $ob['settings']['vnext'][0] ?? ($ob['settings'] ?? []);
                $row['server_address'] = $vnext['address'] ?? '';
                $row['server_port']    = isset($vnext['port']) ? (string)$vnext['port'] : '';
                if (isset($summary[$row['uuid']])) { $row = array_merge($row, $summary[$row['uuid']]); }
                unset($row['outbound_config'], $row['config_json']);
            }
            unset($row);
        }
        return $response;
    }

    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

    public function getItemAction($uuid = null)
    {
        $result = $this->getBase('instance', 'instance', $uuid);
        if ($uuid && preg_match('/^[a-f0-9-]{36}$/Di', $uuid)) {
            $prepared = json_decode((new Backend())->configdRun('xray prepared ' . $uuid), true);
            if (($prepared['result'] ?? '') === 'ok') {
                foreach (['config_json', 'name', 'subscription_metadata', 'subscription_hash', 'subscription_baseline', 'subscription_fetched'] as $field) {
                    $result['instance'][$field] = $prepared['profile'][$field];
                }
                $result['subscription_status'] = $prepared['schedule'];
                $result['subscription_update'] = $prepared['update'];
            }
        }
        return $result;
    }

    public function addItemAction()
    {
        return $this->addBase('instance', 'instance');
    }

    public function setItemAction($uuid)
    {
        return $this->setBase('instance', 'instance', $uuid);
    }

    public function delItemAction($uuid)
    {
        if (!preg_match('/^[a-f0-9-]{36}$/Di', (string)$uuid)) { return ['result' => 'failed']; }
        $status = json_decode((new Backend())->configdRun('xray status ' . $uuid), true);
        if (($status['xray_core'] ?? '') === 'running' || ($status['tun2socks'] ?? '') === 'running') { return ['result' => 'failed', 'message' => 'Stop the active tunnel before deleting its profile']; }
        return $this->delBase('instance', $uuid);
    }
}

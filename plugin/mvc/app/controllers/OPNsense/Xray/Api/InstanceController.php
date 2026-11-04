<?php

namespace OPNsense\Xray\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
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
            if ($url === '') { $node->auto_update = '0'; }
            if ($url !== '') { \XraySubscription::url($url); }
            \XraySubscription::checkHwid((string)$node->subscription_hwid);
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
            try {
                $prepared = json_decode((string)(new Backend())->configdRun('xray prepared ' . $uuid), true);
            } catch (\Throwable $error) {
                // The persisted profile remains editable if the optional prepared snapshot is unavailable.
                $prepared = null;
            }
            if (($prepared['result'] ?? '') === 'ok' && is_array($prepared['profile'] ?? null)) {
                foreach (['config_json', 'name', 'subscription_metadata', 'subscription_hash', 'subscription_baseline', 'subscription_fetched'] as $field) {
                    if (array_key_exists($field, $prepared['profile'])) { $result['instance'][$field] = $prepared['profile'][$field]; }
                }
                $result['subscription_status'] = $prepared['schedule'];
                $result['subscription_update'] = $prepared['update'];
            }
        }
        return $result;
    }

    public function importSubscriptionAction()
    {
        if (!$this->request->isPost()) { return ['result' => 'failed', 'message' => 'POST required']; }
        $body = $this->request->getJsonRawBody(true);
        $url = (string)($body['url'] ?? '');
        $hwid = (string)($body['hwid'] ?? '');
        $download = XrayRequest::run('subscriptionfetch', ['url' => $url, 'hwid' => $hwid]);
        if (($download['result'] ?? '') !== 'ok') { return $download; }

        $entries = [];
        foreach ($download['configurations'] as $index => $item) {
            $json = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $config = \XrayConfig::decode($json);
            $entries[] = [
                'name' => \XrayConfig::name($config, 'Subscription profile ' . ($index + 1)),
                'config_json' => $json,
                'subscription_url' => $url,
                'subscription_hwid' => $hwid,
                'subscription_remarks' => \XrayConfig::name($config),
                'subscription_baseline' => $json,
                'subscription_metadata' => json_encode($download['metadata'], JSON_THROW_ON_ERROR),
                'subscription_fetched' => (string)$download['fetched_at'],
                'auto_update' => '0',
            ];
        }

        $cfg = Config::getInstance();
        $cfg->lock();
        try {
            $instances = $this->getModel()->instance;
            $existing = [];
            foreach ($instances->iterateItems() as $node) {
                if ((string)$node->subscription_url === $url && (string)$node->subscription_hwid === $hwid) {
                    $key = hash('sha256', (string)$node->subscription_baseline);
                    $existing[$key] = ($existing[$key] ?? 0) + 1;
                }
            }
            $added = 0;
            foreach ($entries as $entry) {
                $key = hash('sha256', $entry['subscription_baseline']);
                if (($existing[$key] ?? 0) > 0) { --$existing[$key]; continue; }
                $node = $instances->Add();
                $node->setNodes($entry);
                if (!empty($this->validate($node, 'instance')['validations'])) {
                    return ['result' => 'failed', 'message' => 'A subscription profile cannot be saved'];
                }
                ++$added;
            }
            if ($added > 0) { $this->save(false, true); }
            return ['result' => 'saved', 'added' => $added, 'total' => count($entries)];
        } finally {
            $cfg->unlock();
        }
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

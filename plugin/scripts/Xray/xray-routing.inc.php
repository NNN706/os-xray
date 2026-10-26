<?php
require_once __DIR__ . '/xray-subscription.inc.php';
/** Only explicitly enrolled classic firewall rules; never writes a gateway or kernel route. */
final class XrayRouting
{
    const DIR = '/usr/local/etc/xray-core/routing';
    public static function ids(string $raw): array
    {
        $ids = array_values(array_unique(preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY)));
        if (count($ids) > 16) { throw new RuntimeException('At most 16 managed firewall rules are supported'); }
        foreach ($ids as $id) { if (!preg_match('/^(?:[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}|tracker:[0-9]{1,20})$/Di', $id)) { throw new RuntimeException('Invalid firewall rule UUID'); } }
        return $ids;
    }
    public static function prefix(string $raw): string
    {
        $pieces = explode('/', $raw);
        if (count($pieces) > 2 || !filter_var($pieces[0], FILTER_VALIDATE_IP)) { throw new RuntimeException('Invalid destination CIDR'); }
        $binary = inet_pton($pieces[0]); $bits = strlen($binary) * 8;
        $mask = $pieces[1] ?? (string)$bits;
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $mask) || (int)$mask > $bits) { throw new RuntimeException('Invalid destination prefix length'); }
        $mask = (int)$mask;
        // /0 must be an explicit All traffic decision, not an accidental ASN expansion.
        if ($mask === 0) { throw new RuntimeException('Use All traffic instead of a default prefix'); }
        for ($i = 0; $i < strlen($binary); $i++) { $keep = max(0, min(8, $mask - $i * 8)); $binary[$i] = chr(ord($binary[$i]) & (255 << (8 - $keep))); }
        return inet_ntop($binary) . '/' . $mask;
    }
    public static function destinations(string $cidrs, string $asns, ?callable $fetch = null): array
    {
        $prefixes = [];
        $tokens = preg_split('/[\s,]+/', trim($cidrs), -1, PREG_SPLIT_NO_EMPTY);
        if (count($tokens) > 10000) { throw new RuntimeException('Too many explicit destination networks'); }
        foreach ($tokens as $token) { $prefixes[self::prefix($token)] = true; }
        $asns = array_unique(preg_split('/[\s,]+/', strtoupper(trim($asns)), -1, PREG_SPLIT_NO_EMPTY));
        if (count($asns) > 10) { throw new RuntimeException('At most 10 ASNs per policy'); }
        foreach ($asns as $asn) {
            if (!preg_match('/^(?:AS)?([1-9][0-9]{0,9})$/D', $asn, $m) || (float)$m[1] > 4294967295) { throw new RuntimeException('Invalid ASN'); }
            $rows = ($fetch ?? [self::class, 'asn'])('AS' . $m[1]);
            if (!$rows) { throw new RuntimeException('ASN returned no prefixes; previous routing retained'); }
            foreach ($rows as $prefix) { $prefixes[self::prefix($prefix)] = true; }
            if (count($prefixes) > 100000) { throw new RuntimeException('Destination policy exceeds 100000 prefixes'); }
        }
        if (!$prefixes) { throw new RuntimeException('Networks mode needs at least one CIDR or ASN'); }
        $result = array_keys($prefixes); sort($result, SORT_STRING); return $result;
    }
    public static function asn(string $asn): array
    {
        $file = tempnam(self::DIR, '.asn-');
        if ($file === false) { throw new RuntimeException('Private routing storage unavailable'); }
        chmod($file, 0600);
        try {
            // Ask for the published history, then keep prefixes visible at its latest data time.
            // Querying wall-clock now can fall outside the collector's delayed snapshot.
            $url = 'https://stat.ripe.net/data/announced-prefixes/data.json?resource=' . $asn;
            $proc = proc_open(['/usr/local/bin/curl', '--disable', '--silent', '--fail', '--proto', '=https', '--max-time', '2', '--max-filesize', '16777216', '--output', $file, $url], [0 => ['file','/dev/null','r'], 1 => ['file','/dev/null','w'], 2 => ['file','/dev/null','w']], $pipes, null, ['PATH'=>'/usr/local/bin:/usr/bin:/bin']);
            if (!is_resource($proc)) { throw new RuntimeException('Cannot fetch ASN prefixes'); }
            $rc = XraySubscription::waitForRequest($proc, 2000); $closed = proc_close($proc);
            if (($rc < 0 ? $closed : $rc) !== 0 || filesize($file) > 16777216) { throw new RuntimeException('ASN lookup failed; previous routing retained'); }
            $json = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            return self::response($json, $asn);
        } finally { unlink($file); }
    }
    public static function response(array $json, string $asn): array
    {
        // RIPEstat returns resource as 3333 even when the query uses AS3333.
        $resource = preg_replace('/^AS/i', '', (string)($json['data']['resource'] ?? ''));
        if (($json['status'] ?? '') !== 'ok' || $resource !== preg_replace('/^AS/i', '', $asn)) { throw new RuntimeException('Invalid ASN response'); }
        return self::announced($json['data']);
    }
    public static function announced(array $data): array
    {
        if (!is_string($data['latest_time'] ?? null) || $data['latest_time'] === '') { throw new RuntimeException('ASN snapshot has no data timestamp'); }
        $latest = strtotime($data['latest_time'] . ' UTC');
        if ($latest === false) { throw new RuntimeException('ASN snapshot has no data timestamp'); }
        $rows = [];
        foreach ($data['prefixes'] ?? [] as $row) {
            foreach ($row['timelines'] ?? [] as $timeline) {
                if (empty($timeline['starttime']) || empty($timeline['endtime'])) { continue; }
                $start = strtotime((string)$timeline['starttime'] . ' UTC');
                $end = strtotime((string)($timeline['endtime'] ?? '') . ' UTC');
                if ($start !== false && $end !== false && $start <= $latest && $end >= $latest) { $rows[] = $row['prefix']; break; }
            }
        }
        return $rows;
    }
    public static function rules($root): array
    {
        $rows = [];
        foreach ($root->filter->rule ?? [] as $rule) {
            $id = (string)$rule['uuid'];
            if ($id === '' && preg_match('/^[0-9]{1,20}$/D', (string)$rule->tracker)) { $id = 'tracker:' . (string)$rule->tracker; }
            if ($id === '') { continue; }
            if (isset($rows[$id])) { throw new RuntimeException('Duplicate firewall rule identity; review required'); }
            $rows[$id] = $rule;
        }
        return $rows;
    }
    public static function checkRule($rule, array $profile, array $gateways): string
    {
        if (!$rule || strpos((string)$rule->descr, '[os-xray]') !== 0 || (string)$rule->type !== 'pass' || isset($rule->disabled) || isset($rule->floating) || (string)$rule->interface === '' || (string)$rule->direction === 'out') { throw new RuntimeException('Only enabled inbound non-floating pass rules marked [os-xray] may be enrolled'); }
        $family = (string)$rule->ipprotocol;
        if (!in_array($family, ['inet','inet6'], true)) { throw new RuntimeException('Managed rules must explicitly select IPv4 or IPv6'); }
        $suffix = $family === 'inet' ? '4' : '6';
        $tun = $gateways[$profile['routing_tun' . $suffix] ?? ''] ?? null;
        $wan = $gateways[$profile['routing_wan' . $suffix] ?? ''] ?? null;
        if (!$tun || !$wan || $tun['ipprotocol'] !== $family || $wan['ipprotocol'] !== $family || $tun['if'] !== $profile['tun_iface'] || $wan['if'] === $profile['tun_iface'] || $wan['if'] === 'lo0') { throw new RuntimeException('Select existing TUN and WAN gateways of the rule address family'); }
        if (!empty($wan['force_down']) && $wan['force_down'] !== '0') { throw new RuntimeException('Selected WAN gateway is administratively down'); }
        $ingress = (string)$rule->interface;
        foreach (explode(',', $ingress) as $name) {
            $device = (string)($root = OPNsense\Core\Config::getInstance()->object())->interfaces->$name->if;
            if ($device === '' || in_array($device, [$tun['if'], $wan['if'], 'lo0'], true)) { throw new RuntimeException('Managed rules require client-side ingress interfaces'); }
        }
        if (!in_array((string)$rule->gateway, [$profile['routing_tun' . $suffix], $profile['routing_wan' . $suffix]], true)) { throw new RuntimeException('Enrolled rule must already use its selected TUN or WAN gateway'); }
        return $suffix;
    }
    public static function prepare(array $profile): array
    {
        if (($profile['routing_mode'] ?? 'off') === 'off') { return $profile; }
        if (!is_dir(self::DIR) && !mkdir(self::DIR, 0700, true)) { throw new RuntimeException('Private routing storage unavailable'); }
        $ids = self::ids($profile['routing_rules'] ?? '');
        if (!$ids) { throw new RuntimeException('Select explicitly marked firewall rules for managed traffic'); }
        $gateways = (new OPNsense\Routing\Gateways())->gatewaysIndexedByName(false, true);
        $rules = self::rules(OPNsense\Core\Config::getInstance()->object());
        foreach ($ids as $id) { self::checkRule($rules[$id] ?? null, $profile, $gateways); }
        $profile['routing_prefixes'] = $profile['routing_mode'] === 'networks' ? self::destinations($profile['routing_cidrs'] ?? '', $profile['routing_asns'] ?? '') : [];
        // Destination changes are permitted only for our own alias or an explicit any destination.
        $alias = 'XRAY_' . substr(str_replace('-', '', $profile['inst_uuid']), 0, 20);
        foreach ($ids as $id) {
            $rule = $rules[$id];
            if (!isset($rule->destination->any) && (string)$rule->destination->address !== $alias) { throw new RuntimeException('Managed rule destination must be any or this profile\'s own alias'); }
            if (isset($rule->destination->not)) { throw new RuntimeException('Negated destinations cannot be enrolled'); }
        }
        $profile['routing_alias'] = $alias;
        return $profile;
    }
    private static function reload(): void
    {
        exec('/usr/local/sbin/configctl filter reload >/dev/null 2>&1', $out, $rc);
        clearstatcache();
        if ($rc !== 0 || is_file('/tmp/rules.debug.error')) { throw new RuntimeException('Firewall reload rejected; routing rollback required'); }
    }
    /** Caller holds the lifecycle lock. A journal restores only fields that we wrote. */
    public static function recover(): void
    {
        $path = self::DIR . '/transition.json';
        if (!is_file($path)) { return; }
        $journal = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $cfg = OPNsense\Core\Config::getInstance(); $cfg->lock();
        try {
            $rules = self::rules($cfg->object());
            foreach ($journal['rules'] as $id => $data) {
                $rule = $rules[$id] ?? null;
                $current = $rule ? ['gateway'=>(string)$rule->gateway, 'destination'=>$rule->destination->asXML()] : null;
                // A crash before config save leaves before; after save leaves after.
                if ($current !== $data['before'] && $current !== $data['after']) { throw new RuntimeException('Managed policy changed during transition; review required'); }
            }
            $alias = null; $entry = $journal['alias'] ?? null;
            if ($entry) {
                $container = $cfg->object()->OPNsense->Firewall->Alias->aliases;
                foreach ($container->alias as $item) { if ((string)$item->name === $entry['name']) { $alias = $item; break; } }
                $current = $alias ? $alias->asXML() : null;
                if ($current !== $entry['before'] && $current !== $entry['after']) { throw new RuntimeException('Managed alias changed during transition; review required'); }
            }
            // Validate all journal entries before touching any configuration in memory.
            foreach ($journal['rules'] as $id => $data) { self::fields($rules[$id], $data['before']); }
            if ($entry && $alias && $alias->asXML() !== $entry['before']) {
                $dom = dom_import_simplexml($alias);
                if ($entry['before'] === null) { $dom->parentNode->removeChild($dom); }
                else { $dom->parentNode->replaceChild($dom->ownerDocument->importNode(dom_import_simplexml(simplexml_load_string($entry['before'])), true), $dom); }
            }
            $cfg->save();
        } finally { $cfg->unlock(); }
        self::reload(); unlink($path);
    }
    private static function fields($rule, array $fields): void
    {
        $rule->gateway = $fields['gateway'];
        $doc = dom_import_simplexml($rule->destination);
        $doc->parentNode->replaceChild($doc->ownerDocument->importNode(dom_import_simplexml(simplexml_load_string($fields['destination'])), true), $doc);
    }
    public static function apply(array $profile, string $mode): void
    {
        if (($profile['routing_mode'] ?? 'off') === 'off') { return; }
        if (!in_array($mode, ['proxy','bypass'], true)) { throw new RuntimeException('Invalid routing transition'); }
        self::recover();
        $cfg = OPNsense\Core\Config::getInstance(); $cfg->lock();
        $journal = ['rules' => []]; $changed = false;
        try {
            $root = $cfg->object(); $rules = self::rules($root);
            $gateways = (new OPNsense\Routing\Gateways())->gatewaysIndexedByName(false, true);
            $ids = self::ids($profile['routing_rules']);
            foreach ($ids as $id) {
                $rule = $rules[$id] ?? null; $family = self::checkRule($rule, $profile, $gateways);
                $before = ['gateway'=>(string)$rule->gateway, 'destination'=>$rule->destination->asXML()];
                $next = new SimpleXMLElement($rule->asXML());
                $next->gateway = $profile[($mode === 'proxy' ? 'routing_tun' : 'routing_wan') . $family];
                unset($next->destination->any, $next->destination->address);
                if ($profile['routing_mode'] === 'networks') { $next->destination->address = $profile['routing_alias']; }
                else { $next->destination->addChild('any'); }
                $after = ['gateway'=>(string)$next->gateway, 'destination'=>$next->destination->asXML()];
                $journal['rules'][$id] = ['before'=>$before, 'after'=>$after];
                $changed = $changed || $before !== $after;
            }
            if ($profile['routing_mode'] === 'networks') {
                // Own native network alias. Other aliases and all rule ordering are preserved.
                foreach (['OPNsense','Firewall','Alias','aliases'] as $name) { if (!isset($root->$name)) { $root->addChild($name); } $root = $root->$name; }
                $alias = null;
                foreach ($root->alias as $item) { if ((string)$item->name === $profile['routing_alias']) { $alias = $item; break; } }
                $owner = '[os-xray] ' . $profile['inst_uuid'];
                if ($alias && (string)$alias->description !== $owner) { throw new RuntimeException('Managed alias name is already owned by another configuration'); }
                $beforeAlias = $alias ? $alias->asXML() : null;
                if (!$alias) { $alias = $root->addChild('alias'); $alias->addAttribute('uuid', $profile['inst_uuid']); }
                $content = implode("\n", $profile['routing_prefixes']);
                $changed = $changed || (string)$alias->content !== $content;
                $alias->enabled = '1'; $alias->name = $profile['routing_alias']; $alias->type = 'network'; $alias->content = $content; $alias->description = $owner;
                $journal['alias'] = ['name'=>$profile['routing_alias'], 'before'=>$beforeAlias, 'after'=>$alias->asXML()];
            }
            if (!$changed) { return; }
            XrayConfig::atomic(self::DIR . '/transition.json', XrayConfig::encode($journal));
            foreach ($journal['rules'] as $id => $data) { self::fields($rules[$id], $data['after']); }
            $cfg->save();
        } finally { $cfg->unlock(); }
        try { self::reload(); }
        catch (Throwable $error) { self::recover(); throw $error; }
        // Kill only states attached to our enrolled rules; never flush the global state table.
        foreach ($journal['rules'] as $id => $data) {
            foreach ([$data['before'], $data['after']] as $fields) {
                $copy = new SimpleXMLElement($rules[$id]->asXML()); self::fields($copy, $fields);
                $row = OPNsense\Core\Config::getInstance()->toArray(null, $copy);
                $label = OPNsense\Firewall\Util::calcRuleHash($row);
                exec('/sbin/pfctl -k label -k ' . escapeshellarg($label) . ' >/dev/null 2>&1', $unused, $rc);
                if ($rc !== 0) { throw new RuntimeException('Could not expire managed states; routing transition needs recovery'); }
            }
        }
        unlink(self::DIR . '/transition.json');
    }
}

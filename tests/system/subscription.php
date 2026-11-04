<?php
/** Run on the marked OPNsense VM with candidate PHP appended by the runner. */
function verifySubscription(array $fixture): void
{
    if (PHP_OS !== 'FreeBSD' || !is_file('/usr/local/etc/inc/config.inc')
        || !is_file('/root/.os-xray-isolated-lab')) {
        throw new RuntimeException('Expected marked OPNsense lab');
    }
    try {
        $download = XraySubscription::fetch($fixture['url'], $fixture['hwid']);
    } catch (Throwable $error) {
        throw new RuntimeException('Download failed');
    }
    if (count($download['configurations']) !== 13) {
        throw new RuntimeException('Expected 13 profiles from the test subscription');
    }
    foreach ($download['configurations'] as $index => $config) {
        try {
            if (XrayConfig::name($config) === '') { throw new RuntimeException(); }
            XrayConfig::socks($config, '');
            XrayConfig::validate(XrayConfig::encode($config), true);
        } catch (Throwable $error) {
            $reason = strtolower((string)($config->log->loglevel ?? '')) === 'none'
                ? ' has logging disabled' : ' failed validation';
            throw new RuntimeException('Profile ' . ($index + 1) . $reason);
        }
    }
    echo "PASS 13 subscription profiles fetched and validated on OPNsense\n";
}

$(function () {
    'use strict';
    let busy = false;
    function refresh() {
        if (busy) return;
        busy = true;
        $.getJSON('/api/xray/service/health').done(data => {
            const state = data.state;
            $('#xray-health-panel').toggleClass('alert-danger', !!state && (state.routing_error || !state.healthy || data.stale)).toggleClass('alert-info', !state || (state.healthy && !data.stale));
            if (data.result !== 'ok') { $('#xray-health-summary').text('Heartbeat status unavailable'); return; }
            if (!data.enabled) { $('#xray-health-summary').text('Heartbeat Off'); return; }
            if (!state) { $('#xray-health-summary').text('Waiting for first check — interval: ' + data.interval + ' s'); return; }
            $('#xray-health-summary').text((data.stale ? 'Stale heartbeat' : state.healthy ? 'Healthy' : 'Check failed') + ' — ' + (state.route === 'bypass' ? 'WAN bypass (without VLESS)' : state.route === 'proxy' ? 'Proxy via TUN' : state.route === 'unmanaged' ? 'Diagnostics only' : 'Routing state unknown') + ' — interval: ' + data.interval + ' s');
            $('#xray-health-components').text(Object.entries(state.components || {}).map(([k,v]) => k + ': ' + (v ? 'up' : 'down')).join(' | ') + ' | recovery: ' + state.successes + '/3' + (state.routing_error ? ' | Routing transition failed; review required' : ''));
            $('#xray-health-last').text(new Date(state.checked_at * 1000).toLocaleString() + ' — ' + state.probe.message);
            $('#xray-health-history').text((state.history || []).slice().reverse().map(s => new Date(s.at * 1000).toLocaleTimeString() + ' | ' + (s.ok ? 'OK' : 'FAILED') + ' | HTTP ' + s.http_code + ' | ' + (s.timed_out ? 'timeout (2000 ms)' : s.latency_ms == null ? 'latency unavailable' : s.latency_ms + ' ms')).join('\n'));
        }).fail(() => $('#xray-health-summary').text('Heartbeat status unavailable')).always(() => { busy = false; });
    }
    refresh(); setInterval(refresh, 5000);
});

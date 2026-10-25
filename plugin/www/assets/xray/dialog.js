/* Full configuration dialog adapter for OPNsense's UIBootgrid form lifecycle. */
$(function () {
    'use strict';
    let editor = null, currentUuid = null, lastFetch = '', opening = false, pollBusy = false;
    const $dialog = $('#DialogInstance');
    const field = name => $dialog.find('[id="instance.' + name + '"]');
    const get = name => field(name).val() || '';
    const checked = name => field(name).prop('checked');
    const set = (name, value) => field(name).val(value == null ? '' : value);
    const post = (url, data) => $.ajax({url, type:'POST', contentType:'application/json', dataType:'json', data:JSON.stringify(data)});
    function status(message, error = false) { $('#xray-import-status').text(message).toggleClass('text-danger', error); }
    function update() {
        if (!editor) return;
        editor.setDns(checked('use_config_dns'));
        const issues = editor.diagnostics();
        $('#xray-editor-diagnostics').text(issues.map(d => d.message).join('\n'));
        const auto = checked('auto_update'), mode = get('update_mode');
        field('update_mode').closest('tr').toggle(auto);
        field('update_hours').closest('tr').toggle(auto && mode === 'manual');
        let metadata = {}; try { metadata = JSON.parse(get('subscription_metadata') || '{}'); } catch (_) { /* handled on save */ }
        $('#xray-update-reason').text(auto && mode !== 'manual' && !metadata.interval_hours ? 'Missing or invalid positive profile-update-interval header; scheduled updates are disabled.' : (auto && !get('subscription_url') ? 'Import a subscription URL first.' : ''));
        let changed = false;
        try {
            const baseline = get('subscription_baseline');
            changed = baseline ? XrayEditor.canonical(JSON.parse(editor.getValue())) !== XrayEditor.canonical(JSON.parse(baseline)) : (!!get('subscription_hash') && XrayEditor.hash(JSON.parse(editor.getValue())) !== get('subscription_hash'));
        } catch (_) { changed = !!(get('subscription_baseline') || get('subscription_hash')); }
        const warn = auto && changed;
        $('#xray-auto-state').text(auto ? 'On' : 'Off');
        field('auto_update').closest('tr').toggleClass('xray-update-warning', warn);
        $('#xray-manual-warning').toggle(warn);
        const managed = get('routing_mode') !== 'off';
        ['routing_rules','routing_tun4','routing_wan4','routing_tun6','routing_wan6'].forEach(k => field(k).closest('tr').toggle(managed));
        ['routing_cidrs','routing_asns'].forEach(k => field(k).closest('tr').toggle(get('routing_mode') === 'networks'));
        field('health_interval').closest('tr').toggle(checked('health_enabled'));
        field('health_bypass').closest('tr').toggle(checked('health_enabled') && managed);
        socksOptions();
    }
    function socksOptions() {
        const $select = field('socks_inbound'), selected = $select.val();
        let config; try { config = JSON.parse(editor.getValue()); } catch (_) { return; }
        const choices = (config.inbounds || []).map(item => ({item, id:item.tag ? 'tag:' + item.tag : 'endpoint:' + item.listen + ':' + item.port})).filter(({item}) => item.protocol === 'socks' && (item.listen === '::1' || /^127\.(?:\d{1,3}\.){2}\d{1,3}$/.test(item.listen || '')) && (!item.settings?.auth || item.settings.auth === 'noauth') && Number.isInteger(item.port) && item.port > 0 && item.port <= 65535);
        $select.empty().append($('<option>').val('').text('Automatic (tag socks preferred)'));
        choices.forEach(({item,id}) => $select.append($('<option>').val(id).text((item.tag || 'untagged') + ' — ' + item.listen + ':' + item.port)));
        $select.val(choices.some(c => c.id === selected) ? selected : '');
    }
    function replaceSelect(name, values) {
        const $old = field(name), value = $old.val();
        const $new = $('<select class="form-control">').attr('id', $old.attr('id')).attr('name', $old.attr('name'));
        values.forEach(([v,label]) => $new.append($('<option>').val(v).text(label)));
        $old.replaceWith($new); $new.val(value || values[0][0]);
    }
    function loadInstance(data, uuid) {
        if (!data?.instance || !editor) return;
        currentUuid = uuid || null;
        editor.setValue(data.instance.config_json || '{}');
        lastFetch = String(data.instance.subscription_fetched || '0');
        // Normal mapper may already have set these. Apply explicitly for asynchronous fetches.
        ['subscription_hash','subscription_baseline','subscription_metadata','subscription_fetched','subscription_remarks'].forEach(k => set(k, data.instance[k]));
        opening = false; update(); set('socks_inbound', data.instance.socks_inbound || '');
    }
    function init() {
        if (editor) return;
        const $text = field('config_json');
        if (!$text.length) return;
        const $tools = $('<div id="xray-editor-tools"><button type="button" id="xray-format" class="btn btn-default btn-sm">Format JSON</button><button type="button" id="xray-import" class="btn btn-default btn-sm">Import JSON / URL / VLESS</button><button type="button" id="xray-validate" class="btn btn-info btn-sm">Validate Config</button><span id="xray-validation-result" role="status"></span></div>');
        $text.before($tools).after('<div id="xray-editor-diagnostics" role="status"></div>');
        editor = XrayEditor.mount($text[0], update);
        field('subscription_url').attr({type:'password', autocomplete:'off', spellcheck:'false', readonly:true});
        ['subscription_hash','subscription_baseline','subscription_metadata','subscription_fetched','subscription_remarks'].forEach(k => field(k).closest('tr').hide());
        replaceSelect('health_interval', [['15','15 seconds'],['30','30 seconds'],['60','1 minute'],['120','2 minutes'],['300','5 minutes']]);
        replaceSelect('routing_mode', [['off','Off — diagnostics only'],['all','All traffic selected by enrolled rules'],['networks','Only destination ASN / CIDR']]);
        $.getJSON('/api/xray/service/routingOptions').done(data => {
            ['4','6'].forEach(family => {
                const choices = (data.gateways || []).filter(g => g.family === (family === '4' ? 'inet' : 'inet6')).map(g => [g.name, g.name + ' (' + g.interface + ')']);
                ['routing_tun','routing_wan'].forEach(prefix => replaceSelect(prefix + family, [['','Select existing gateway'], ...choices]));
            });
            field('routing_rules').closest('td').append($('<div class="help-block">').text('Available marked rules: ' + (data.rules || []).map(r => r.name + ' [' + r.uuid + ']').join('; ')));
        });
        replaceSelect('socks_inbound', [['','Automatic (tag socks preferred)']]);
        replaceSelect('update_mode', [['subscription','From Subscription'],['manual','Manual']]);
        replaceSelect('update_hours', Array.from({length:24}, (_,i) => [String(i+1), (i+1) + ' h']));
        field('auto_update').closest('td').append('<span id="xray-auto-state" style="margin-left:8px"></span><div id="xray-manual-warning" style="display:none" role="status">Конфигурация изменена вручную. При следующем успешном обновлении подписки изменения будут сброшены</div><div id="xray-update-reason" role="status"></div>');
        $dialog.find('.modal-body').prepend('<div id="xray-import-panel" hidden><textarea id="xray-import-input" placeholder="Full JSON, HTTPS subscription URL or vless:// link" spellcheck="false"></textarea><input id="xray-import-file" type="file" accept="application/json,.json"><button id="xray-import-read" type="button" class="btn btn-primary btn-sm">Import</button><select id="xray-import-choice" class="form-control" hidden></select><div id="xray-import-status" role="status"></div></div>');
        $('#xray-format').on('click', () => { try { editor.format(); update(); } catch (_) { update(); } });
        $('#xray-import').on('click', () => { const p = document.getElementById('xray-import-panel'); p.hidden = !p.hidden; });
        $('#xray-import-file').on('change', async function () { if (this.files[0]) $('#xray-import-input').val(await this.files[0].text()); });
        $('#xray-validate').on('click', function () {
            update(); const issues = editor.diagnostics();
            if (issues.length) { $('#xray-validation-result').text(issues[0].message); return; }
            const button = $(this).prop('disabled', true);
            $('#xray-validation-result').text('Validating current editor content…');
            post('/api/xray/service/validate', {config_json:editor.getValue(), use_config_dns:checked('use_config_dns'), socks_inbound:get('socks_inbound')}).done(data => $('#xray-validation-result').text(data.message || 'Validation failed')).fail(() => $('#xray-validation-result').text('Validation request failed')).always(() => button.prop('disabled',false));
        });
        $('#xray-import-read').on('click', async function () {
            const value = $('#xray-import-input').val().trim();
            $(this).prop('disabled',true); status('Reading…');
            try {
                if (value.startsWith('vless://')) {
                    const response = await post('/api/xray/import/parse', {link:value});
                    if (response.status !== 'ok') throw new Error(response.message || 'VLESS import failed');
                    preview([JSON.parse(response.config_json)], null, null);
                } else if (/^https:\/\//.test(value)) {
                    const response = await post('/api/xray/import/subscription', {url:value});
                    if (response.result !== 'ok') throw new Error(response.message || 'Subscription import failed');
                    preview(response.configurations, response, value);
                } else {
                    const parsed = JSON.parse(value); preview(Array.isArray(parsed) ? parsed : [parsed], null, null);
                }
            } catch (error) { status(error.responseJSON?.message || error.message || 'Import failed', true); }
            finally { $(this).prop('disabled',false); }
        });
        $dialog.on('change', 'input,select', update);
    }
    function preview(configs, download, url) {
        if (!configs?.length || configs.some(c => !c || Array.isArray(c) || typeof c !== 'object' || !Array.isArray(c.outbounds) || !c.outbounds.length)) throw new Error('Expected full configuration object(s) with outbounds');
        const $choice = $('#xray-import-choice').empty().prop('hidden', configs.length < 2).off('change');
        const choose = index => {
            const config = configs[index], name = config.remarks || config.Remarks;
            editor.setValue(JSON.stringify(config,null,2));
            set('socks_inbound','');
            if (typeof name === 'string' && name.trim()) set('name',name);
            if (download) {
                set('subscription_url',url); set('subscription_remarks', typeof name === 'string' ? name : '');
                set('subscription_metadata',JSON.stringify(download.metadata || {})); set('subscription_baseline',JSON.stringify(config)); set('subscription_hash',XrayEditor.hash(config));
                set('subscription_fetched', download.fetched_at); lastFetch = String(download.fetched_at);
            }
            update(); status('Imported into the editor. Save, then Apply or reconnect to activate.');
        };
        configs.forEach((c,i) => $choice.append($('<option>').val(i).text(c.remarks || c.Remarks || ('Configuration ' + (i+1)))));
        if (configs.length === 1) choose(0);
        else { $choice.prepend($('<option>').val('').text('Choose a configuration by remarks')).val(''); status('Choose one configuration. Configurations will not be merged.'); $choice.on('change', function () { if (this.value !== '') choose(Number(this.value)); }); }
    }
    window.XrayDialogLoad = function (payload) {
        const data = payload?.frm_DialogInstance || payload;
        if (data?.instance) loadInstance(data, data.instance.uuid || currentUuid);
        return $.Deferred().resolve().promise();
    };
    $dialog.on('show.bs.modal', function () {
        init(); opening = true; currentUuid = null; lastFetch = '';
        $('#xray-validation-result,#xray-import-status,#xray-editor-diagnostics').text('');
        $('#xray-import-input').val(''); $('#xray-import-choice').empty().prop('hidden',true);
        $('#xray-import-panel').prop('hidden',true);
    });
    $dialog.on('shown.bs.modal', function () { if (opening) { editor.setValue(field('config_json').val() || '{}'); opening = false; update(); } editor.view.requestMeasure(); });
    $.ajaxPrefilter(function (settings) {
        const match = settings.url.match(/\/api\/xray\/instance\/getItem(?:\/([a-f0-9-]{36}))?/i);
        if (match) settings.xrayUuid = match[1] || null;
    });
    $(document).ajaxComplete(function (_, xhr, settings) {
        const match = settings.url.match(/\/api\/xray\/instance\/getItem(?:\/([a-f0-9-]{36}))?/i);
        if (match && $dialog.is(':visible')) { loadInstance(xhr.responseJSON, match[1]); }
    });
    // A successful scheduled download replaces even unsaved manual edits, as warned in the UI.
    setInterval(function () {
        if (!currentUuid || !$dialog.hasClass('in') || pollBusy) return;
        pollBusy = true; const uuid = currentUuid;
        $.getJSON('/api/xray/instance/prepared/' + uuid).done(data => {
            if (uuid !== currentUuid || data.result !== 'ok') return;
            const c = data.profile;
            if (lastFetch && String(c.subscription_fetched) !== lastFetch) {
                editor.setValue(c.config_json); ['subscription_hash','subscription_baseline','subscription_metadata','subscription_fetched'].forEach(k => set(k,c[k])); set('name',c.name);
                lastFetch = String(c.subscription_fetched); update(); status('Subscription updated. Prepared configuration replaced; active tunnel unchanged.');
            }
            if (data.update?.result === 'failed') $('#xray-update-reason').text(data.update.message);
        }).always(() => { pollBusy = false; });
    }, 5000);
});

<!-- LOGS -->
<div id="logs" class="tab-pane fade in">

    {# ── Toolbar ─────────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">

                {# Log type sub-tabs #}
                <ul class="nav nav-pills nav-sm" id="logSubTabs" style="margin: 0;">
                    <li class="active">
                        <a data-toggle="tab" href="#logBoot">
                            <i class="fa fa-terminal fa-fw"></i> {{ lang._('Boot Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logCore">
                            <i class="fa fa-file-text-o fa-fw"></i> {{ lang._('Core Log') }}
                        </a>
                    </li>
                    <li><a data-toggle="tab" href="#logAccess">{{ lang._('Access') }}</a></li>
                    <li><a data-toggle="tab" href="#logTunnel">{{ lang._('Tunnel') }}</a></li>
                    <li><a data-toggle="tab" href="#logSubscriptions">{{ lang._('Subscriptions') }}</a></li>
                </ul>

            </div>
        </section>
    </div>

    {# ── Log panes ───────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div class="tab-content" style="padding: 0 15px 15px;">

                <div id="logBoot" class="tab-pane fade in active" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logBootRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ lang._('/tmp/xray_syshook.log — last 150 lines') }}
                        </span>
                    </div>
                    <pre id="logBootContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Switch to this tab to load log.') }}</pre>
                </div>

                <div id="logCore" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logCoreRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                        </button>
                        <span class="text-muted">{{ lang._('Latest 64 KiB. Set log.loglevel in Xray JSON.') }}</span>
                    </div>
                    <pre id="logCoreContent"
                         style="min-height: 300px; max-height: 550px; overflow-y: auto;
                                background: #1e1e1e; color: #d4d4d4; font-family: monospace;
                                font-size: 12px; padding: 12px; border-radius: 4px;
                                border: 1px solid #444;">{{ lang._('Click "Core Log" to load.') }}</pre>
                </div>


                <div id="logAccess" class="tab-pane fade" style="padding-top: 10px;">
                    <button id="logAccessRefreshBtn" class="btn btn-sm btn-default">{{ lang._('Refresh') }}</button>
                    <span class="text-muted">{{ lang._('Latest 64 KiB') }}</span>
                    <pre id="logAccessContent" style="min-height: 300px; max-height: 550px; overflow: auto;">{{ lang._('Switch to this tab to load log.') }}</pre>
                </div>

                <div id="logTunnel" class="tab-pane fade" style="padding-top: 10px;">
                    <button id="logTunnelRefreshBtn" class="btn btn-sm btn-default">{{ lang._('Refresh') }}</button>
                    <span class="text-muted">{{ lang._('Latest 64 KiB') }}</span>
                    <pre id="logTunnelContent" style="min-height: 300px; max-height: 550px; overflow: auto;">{{ lang._('Switch to this tab to load log.') }}</pre>
                </div>

                <div id="logSubscriptions" class="tab-pane fade" style="padding-top: 10px;">
                    <button id="logSubscriptionsRefreshBtn" class="btn btn-sm btn-default">{{ lang._('Refresh') }}</button>
                    <span class="text-muted">{{ lang._('Latest 64 KiB') }}</span>
                    <pre id="logSubscriptionsContent" style="min-height: 300px; max-height: 550px; overflow: auto;">{{ lang._('Switch to this tab to load log.') }}</pre>
                </div>
            </div>
        </section>
    </div>

</div>

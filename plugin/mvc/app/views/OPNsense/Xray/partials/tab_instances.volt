<!-- INSTANCES -->
<div id="instances" class="tab-pane fade in active">

    <p class="alert alert-info">{{ lang._('One active tunnel. Each profile may contain multiple outbounds, routing balancers and observatory settings.') }}</p>

    {# ── Service toolbar ─────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 8px 15px; border-bottom: 1px solid #ddd;
                        display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">

                {# Status badges #}
                <div>
                    <span id="badge_xray" class="label label-default">xray-core: ...</span>
                    <span id="badge_tun"  class="label label-default">tun2socks: ...</span>
                </div>

                <div style="width: 1px; height: 22px; background: #ddd;"></div>

                {# Global service controls #}
                <div style="display: flex; gap: 4px;">
                    <button id="btnStartAll" class="btn btn-xs btn-success"
                            title="{{ lang._('Start the current or only enabled profile; use a row Start button to switch profiles') }}">
                        <i class="fa fa-play fa-fw"></i> {{ lang._('Start') }}
                    </button>
                    <button id="btnStopAll" class="btn btn-xs btn-danger"
                            title="{{ lang._('Stop the active tunnel') }}">
                        <i class="fa fa-stop fa-fw"></i> {{ lang._('Stop') }}
                    </button>
                    <button id="btnRestartAll" class="btn btn-xs btn-warning"
                            title="{{ lang._('Reconnect the active profile and apply its prepared JSON') }}">
                        <i class="fa fa-refresh fa-fw"></i> {{ lang._('Reconnect') }}
                    </button>
                </div>

                <div style="width: 1px; height: 22px; background: #ddd;"></div>

                <button id="btnTestConnect" class="btn btn-xs btn-default"
                        title="{{ lang._('Test through the active Routing and balancer') }}">
                    <i class="fa fa-plug fa-fw"></i> {{ lang._('Test') }}
                </button>
                <span id="testConnectResult" style="font-size: 12px;"></span>

            </div>
        </section>
    </div>

    <div class="alert alert-info" id="xray-health-panel" role="status">
        <strong>Health check / Heartbeat</strong><div id="xray-health-summary">Loading…</div>
        <div id="xray-health-components"></div><div id="xray-health-last"></div>
        <details><summary>Recent checks</summary><pre id="xray-health-history"></pre></details>
    </div>

    {# ── Instances grid ──────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <table id="grid-instances"
                   class="table table-condensed table-hover table-striped"
                   data-editDialog="DialogInstance"
                   data-editAlert="InstanceChangeMessage">
                <thead>
                    <tr>
                        <th data-column-id="uuid"
                            data-type="string"
                            data-identifier="true"
                            data-visible="false">{{ lang._('ID') }}</th>

                        <th data-column-id="enabled"
                            data-width="6em"
                            data-type="string"
                            data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>

                        <th data-column-id="name"
                            data-type="string">{{ lang._('Name') }}</th>

                        <th data-column-id="server_address"
                            data-type="string">{{ lang._('Server') }}</th>

                        <th data-column-id="server_port"
                            data-type="string"
                            data-width="6em">{{ lang._('Port') }}</th>

                        <th data-column-id="inst_status"
                            data-formatter="instanceStatus"
                            data-sortable="false"
                            data-width="10em">{{ lang._('Status') }}</th>

                        <th data-column-id="inst_testresult"
                            data-formatter="instanceTestResult"
                            data-sortable="false"
                            data-width="10em">{{ lang._('Test Result') }}</th>

                        <th data-column-id="commands"
                            data-formatter="commands"
                            data-sortable="false"
                            data-width="11em">{{ lang._('') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td>
                            <button data-action="add" type="button" class="btn btn-xs btn-primary">
                                <span class="fa fa-fw fa-plus"></span>
                            </button>
                            <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default">
                                <span class="fa fa-fw fa-trash-o"></span>
                            </button>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <div id="InstanceChangeMessage" class="alert alert-info" style="display: none;" role="alert">
                {{ lang._('After saving your changes, please click Apply to activate them.') }}
            </div>
        </section>
    </div>

</div>

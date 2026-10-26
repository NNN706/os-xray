# os-xray

**An Xray client for OPNsense, with a full JSON editor, subscriptions and optional WAN fallback.**

[Русская версия](README.ru.md) · [Download v4.0.0-rc.1](https://github.com/NNN706/os-xray/releases/tag/v4.0.0-rc.1) · [Testing status](docs/TESTING.md)

> **Preview release for an isolated test router.** Portable tests run on Linux; real OPNsense/FreeBSD TUN, firewall failover, reboot and runtime rollback still require testing. This is not a stable production release.

## What you can do

- Edit a complete Xray JSON configuration with highlighting, line numbers, folding, search, formatting and a resizable editor.
- Import JSON, a VLESS link or an HTTPS subscription. Keep multiple outbounds, routing rules, balancers and observatory in one configuration.
- Run **one active tunnel**. Multiple saved profiles are available, but only one runs at a time.
- Test the active connection and see its HTTP response and latency.
- Optionally monitor the tunnel and switch explicitly selected client traffic to WAN when the proxy fails.

The plugin does not replace Unbound, change the system DNS, install BIRD/DNSTAP or modify unrelated gateway settings and the global default route.

## Before you install

Use an isolated OPNsense router with a configuration backup and console access. This is an installation archive, **not a native `.pkg` package**.

These executables must already be installed:

| Component | Expected path |
| --- | --- |
| PHP / OPNsense framework | `/usr/local/bin/php` |
| Xray-core | `/usr/local/bin/xray-core` |
| tun2socks | `/usr/local/tun2socks/tun2socks` |
| curl | `/usr/local/bin/curl` |

The installer reports missing dependencies; it does not download or replace Xray-core or tun2socks. Your installed core must support the JSON you import; **Validate** checks that. Node.js is not needed on the router. Debian is a build/test host, not an installation target.

## Install the preview

Download the **versioned release asset**, not GitHub's automatically generated “Source code” archive and not a single `install.sh` from a raw branch. The release includes the complete plugin and the built editor.

In the OPNsense shell, as root:

```sh
workdir=$(mktemp -d /root/os-xray-install.XXXXXX)
cd "$workdir"
fetch -o os-xray-v4.0.0-rc.1.tar.gz https://github.com/NNN706/os-xray/releases/download/v4.0.0-rc.1/os-xray-v4.0.0-rc.1.tar.gz
fetch -o manifest.json https://github.com/NNN706/os-xray/releases/download/v4.0.0-rc.1/manifest.json
fetch -o SHA256SUMS https://github.com/NNN706/os-xray/releases/download/v4.0.0-rc.1/SHA256SUMS
sha256 -q os-xray-v4.0.0-rc.1.tar.gz
sha256 -q manifest.json
cat SHA256SUMS
```

**Compare both displayed hashes with the corresponding SHA256SUMS entries. Stop if either differs.** The checksum detects corruption; it is not an independent signature of the publisher.

After both match:

```sh
tar -xzf os-xray-v4.0.0-rc.1.tar.gz
cd os-xray-v4.0.0-rc.1
sh install.sh
```

Keep the extracted directory for uninstalling later. The installer restarts configd so OPNsense can discover the plugin actions. It does not automatically apply a downloaded subscription or start a new tunnel.

## First connection

1. Refresh the web interface and open **VPN → Xray**.
2. Enable the plugin under **General**. Create or edit a profile under **Instances**.
3. Use **Import JSON / URL / VLESS**, or paste a complete JSON configuration. `remarks` (or `Remarks`) supplies the profile name when available.
4. Ensure the JSON contains a loopback, no-auth SOCKS inbound, such as `127.0.0.1:10808`. The plugin reuses it; it does not silently add one. Select it if multiple candidates are ambiguous.
5. Click **Validate Config**, save the profile, then **Apply** or manually reconnect.
6. Click **Test** to check the active SOCKS connection.

Interface assignments, addresses, gateways and the initial client firewall rules must be configured in OPNsense. Starting the process alone does not route LAN traffic into it. Follow [the routing and heartbeat guide](docs/HEARTBEAT.ru.md) before enabling managed traffic.

### Validate, Test and Apply

| Button | What it does |
| --- | --- |
| **Validate Config** | Checks the open editor, including unsaved edits, with the installed Xray-core. |
| **Test** | Checks the currently applied connection through SOCKS and its routing/balancer; shows HTTP status and time to first byte. Its request deadline is 1000 ms. |
| **Apply / reconnect** | Validates and activates the saved/prepared configuration. A failed startup attempts to restore the previous working version. |

“Use DNS from configuration” is enabled by default. Turning it off keeps `dns` in the editor but excludes it from the runtime copy. It does not change the router's DNS settings.

## Subscriptions and updates

Import an HTTPS URL in the import dialog. Requests are made by the router with `User-Agent: OPNSense`, verified TLS and a 2000 ms request deadline. If a subscription returns several configurations, choose one by its remarks; they are not merged.

**Auto Update Subscription** is off by default. Enable it and select the subscription's advertised interval, or a manual interval of 1–24 hours. A missing/invalid advertised interval prevents scheduled updates and displays the reason.

**A subscription update never restarts the tunnel.** It replaces the prepared JSON; **Apply** or manual reconnect activates it. Successful updates replace manual edits to the prepared JSON, with a warning shown in the dialog. Failed downloads or validation retain the previous version.

## Heartbeat and WAN fallback

Optional heartbeat checks Cloudflare `generate_204` through the existing SOCKS, expecting HTTP 204 within 2 seconds. Choose 15 seconds, 30 seconds, 1 minute, 2 minutes or 5 minutes. Ensure your JSON routes this target through the proxy; a successful path selected by your balancer is sufficient.

One failure requests WAN bypass; three consecutive successes restore proxy routing. The panel shows component state, last result, latency, history and stale/error status. Detection time also depends on the selected interval and transition locks.

Fallback is restricted to explicitly enrolled `[os-xray]` firewall rules and selected existing TUN/WAN gateways. Choose all traffic covered by those rules, or destination ASN/CIDR networks. Xray JSON routing still processes traffic sent into the tunnel. Other gateways, their monitoring/force_down settings and the global default route are preserved.

**WAN bypass skips Xray JSON BLOCK rules too.** Put restrictions that must remain in force in OPNsense firewall rules above the enrolled rules. Existing connections may reconnect when switching paths. Read [the detailed guide](docs/HEARTBEAT.ru.md) before enabling fallback.

## Upgrade or remove

For an upgrade, back up the router configuration and keep the previous release archive. Verify and extract the new versioned release and run its installer. Review migrated profiles, Validate, then Apply. Legacy source fields are retained during conversion. Prepared subscription changes do not become active just because the plugin was upgraded.

To uninstall, use the extracted release directory:

```sh
sh install.sh uninstall
```

The tunnel is stopped; enrolled traffic is moved to WAN when configured. Saved profile data and core binaries are retained. Review previously enrolled firewall rules afterwards. If stopping fails, removal aborts and retains the plugin files.

## Troubleshooting and development

- **Missing dependency:** install the component at the expected path before retrying.
- **Validate fails:** check the reported JSON location or installed-core compatibility.
- **No traffic through the tunnel:** check TUN assignment, gateway and LAN firewall selection; then Test and heartbeat status.
- **Fallback says unknown/stale:** inspect the managed rule/gateway setup and lifecycle status; it is not confirmation of a successful switch.

[Configuration and migration](docs/FULL_JSON.ru.md) · [Build/runner setup](docs/BUILD.md) · [Tests and target-platform checklist](docs/TESTING.md) · [Archived v3 documentation](docs/legacy/README-v3.md)

Fork of [MrTheory/os-xray](https://github.com/MrTheory/os-xray), based on commit `dbaee5bd32ac75a0fe72de69fdbb19cea0715480`. See [LICENSE](LICENSE) and the bundled editor's [third-party licenses](plugin/www/assets/xray/LICENSES.txt).

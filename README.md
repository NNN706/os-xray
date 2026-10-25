# os-xray 4.0.0

OPNsense plugin for one active Xray + tun2socks tunnel. A profile holds the complete Xray JSON configuration, including multiple outbounds, routing balancers, DNS, policy and observatory.

The changes start from commit `dbaee5bd32ac75a0fe72de69fdbb19cea0715480`.

- Locally bundled CodeMirror 6 editor: JSON highlighting, line numbers, folding, search, formatting, resizing and syntax diagnostics.
- Import a full JSON object, a configuration array, HTTPS subscription or VLESS link. Independent configurations are never merged.
- Validate the current unsaved editor content with the installed Xray-core.
- Optional Xray DNS exclusion affects only the runtime copy. System DNS is unchanged.
- Subscription downloads use `User-Agent: OPNSense`, verified HTTPS and a 2000 ms total deadline.
- Scheduled updates replace the prepared configuration only. Apply or reconnect manually to activate it.
- Test uses the applied SOCKS inbound with remote DNS, HEAD/GET, a 1000 ms deadline and HTTP time to first byte.
- Start/Stop/Apply share a lifecycle lock. Failed starts restore the last working snapshot.

Read the [Russian configuration and upgrade guide](docs/FULL_JSON.ru.md), [testing guide](docs/TESTING.md), and [legacy interface documentation](docs/legacy/README-v3.md).

## Installation

Install Xray-core at `/usr/local/bin/xray-core`, tun2socks at `/usr/local/tun2socks/tun2socks`, and curl at `/usr/local/bin/curl` separately. The installer does not download or replace these binaries. It requires the OPNsense framework and root privileges.

The editor bundle is included, so Node.js and a CDN are not needed on OPNsense. For a source build:

```sh
npm ci
npm run build
```

Run `sh install.sh` on the target system only after reviewing a configuration backup and the upgrade guide. Installation checks migration, installs the cron registration and restarts configd. It does not apply a new Xray configuration. Interface assignments, gateways and firewall policy remain administrator-managed.

## Validation status

Local PHP, XML, shell, Chromium and HTTPS/SOCKS checks are available in `tests/`. The full fixture was accepted by official Xray-core v26.10.21 on macOS. Runtime rollback tests use a deterministic platform adapter. OPNsense/FreeBSD daemon, TUN, reboot, cron and MVC integration still require target-platform validation; this branch is not claimed to be fully platform-tested.

Heartbeat / WAN bypass and isolated runner setup: [documentation](docs/HEARTBEAT.ru.md).

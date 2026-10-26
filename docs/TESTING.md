# Verification

## Local checks

Prerequisites: Node.js/npm, PHP CLI with JSON, SimpleXML, filter and process functions, OpenSSL, curl, and Chromium installed by Playwright. No live subscription credentials are used by the test fixtures.

```sh
npm ci
npx playwright install chromium
PHP_BIN=/absolute/path/to/php npm test
```

Optional real-core fixture validation:

```sh
PHP_BIN=/absolute/path/to/php XRAY_BIN=/absolute/path/to/xray-core npm test
```

The checked-in fixture contains SOCKS 10808, HTTP 10809, three outbounds, policy, DNS, observatory and leastLoad `expected: 2`. Its freedom outbounds are synthetic and contain no user credentials.

The suite checks PHP/shell/XML/JavaScript syntax, effective DNS copies, SOCKS selection, semantic comparisons, subscription metadata/scheduling, atomic 0600 writes, browser editor behavior, dialog import/unsaved validation/warnings, HTTPS subscription transport and SOCKS HEAD/GET tests. Local HTTPS tests use a temporary test CA; production TLS verification remains enabled. Total redirect/body and connection-test deadlines are measured with tolerance for process overhead.

The runtime suite executes the production transaction/store logic with a deterministic adapter for processes, sockets and interfaces. It verifies prevalidation, rollback, applied-versus-prepared separation, recovery and manual reconnect. It does **not** emulate FreeBSD daemon/TUN behavior or the complete OPNsense MVC framework.

For the development verification, official Xray-core v26.10.21 macOS amd64 was downloaded from its GitHub release. The archive SHA-256 matched the digest reported by the official GitHub release API. The real core accepted the full fixture with DNS On and Off. The PHP CLI used locally was built in an ignored private tools directory; it is not shipped in the plugin.

## Target-platform acceptance — still required

Use an isolated OPNsense/FreeBSD lab with console access and configuration/package backups. This development task did not deploy to a server. Do not treat the local test suite as a claim that the following have passed.

1. Install the package with existing compatible Xray-core/tun2socks/curl. Verify migration retains the old outbound/bypass source and the converted profile starts with the same routing. Reinstall: no duplicate profiles. Test a rejected migration against a copy of the configuration.
2. Confirm `/var/cron/tabs/root` has minute jobs for `xray schedule` and `xray watchdog`. Confirm configd actions are registered and the web user can create private request tickets after a reboot/RAM-disk reset.
3. Open the real MVC dialog. Validate an unsaved edit; check save/reopen, JSON line/column errors, folding, search, formatting, resizing, DNS dimming and browser back/forward navigation. Check the DOM/bootgrid adapter against the installed OPNsense version.
4. Import a full object, a one-element array and multiple profiles by unique remarks. Confirm original HTTP 10809, SOCKS 10808, sniffing, multiple outbounds and balancing settings in the applied JSON. Check ambiguous and absent SOCKS errors.
5. Test real tun2socks `--config`, PID readiness, TUN creation, MTU, interface IP assignment, firewall/gateway traffic and teardown. Apply a valid new profile: only one Xray/TUN remains active. Check occupied inbound ports and TUN names before changing the working tunnel.
6. Toggle DNS Off/On and compare source and runtime files. Confirm system resolver configuration is unchanged.
7. Use a controlled HTTPS subscription: exact UA OPNSense, redirect, invalid TLS, HTTP error, invalid JSON/core configuration, interval headers, Manual 1–24 h and absent-header reason. Wait for scheduled updates. Confirm both service PIDs and applied file hashes remain unchanged. Confirm prepared manual edits are replaced only on successful update.
8. Stop or kill a service and exercise watchdog/reboot. Recovery must use the last applied snapshot; pending downloaded JSON must remain unapplied. An intentional Stop must not be undone by watchdog.
9. Test HEAD/GET through an actual proxy/balancer, remote DNS, HTTP 200/204, failure and a 1000 ms timeout. Compare displayed TTFB with the response fields.
10. Force startup failure after validation in the lab. Confirm rollback restores previous runtime files and service behavior. Interrupt a transition and confirm journal recovery. Run Start/Stop/Apply concurrently and confirm mutual exclusion. Check private modes and absence of URLs/credentials in configd and watchdog logs.

Record exact OPNsense, FreeBSD, Xray-core and tun2socks versions, results and failures before release. No target-platform PASS is recorded by this repository change.

Heartbeat, ASN/CIDR policy, runner setup and explicit lab lifecycle checks: [HEARTBEAT.ru.md](HEARTBEAT.ru.md). The automated firewall adapter checks rollback and foreign-configuration invariants; target PF/data-plane acceptance remains required.

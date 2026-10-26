## os-xray {{TAG}} — preview for isolated testing

Full JSON configuration editor, JSON/VLESS/HTTPS subscription import, prepared/applied subscription separation, optional single-tunnel heartbeat and scoped WAN bypass. Multiple outbounds and balancers remain in one full JSON configuration.

- Locally bundled editor: no CDN and no Node.js on the router.
- Subscription updates never restart the tunnel; Apply/reconnect activates them.
- Cloudflare HTTP 204 heartbeat through the existing SOCKS, 2000 ms deadline, selectable intervals, one failure to bypass and three successes to recover.
- Optional destination ASN/CIDR selection through explicitly enrolled rules. No replacement of Unbound, system DNS, global default routes or unrelated gateway settings.

**Not a stable production release.** Portable tests and package verification ran on the Debian build runner. Real OPNsense/FreeBSD TUN/PF traffic, remote-proxy failure, recovery, reboot and rollback still require the isolated-router acceptance tests. This archive is not a native `.pkg` and contains no Xray-core/tun2socks binaries.

### Install on an isolated OPNsense router

Have a configuration backup and console access. Existing dependencies: `/usr/local/bin/xray-core`, `/usr/local/tun2socks/tun2socks`, `/usr/local/bin/curl` and the standard OPNsense PHP/framework. Missing dependencies are reported, not downloaded or replaced.

```sh
workdir=$(mktemp -d /root/os-xray-install.XXXXXX)
cd "$workdir"
fetch -o os-xray-{{TAG}}.tar.gz https://github.com/NNN706/os-xray/releases/download/{{TAG}}/os-xray-{{TAG}}.tar.gz
fetch -o manifest.json https://github.com/NNN706/os-xray/releases/download/{{TAG}}/manifest.json
fetch -o SHA256SUMS https://github.com/NNN706/os-xray/releases/download/{{TAG}}/SHA256SUMS
sha256 -q os-xray-{{TAG}}.tar.gz
sha256 -q manifest.json
cat SHA256SUMS
```

Compare BOTH hashes against SHA256SUMS. Stop on a mismatch. After they match:

```sh
tar -xzf os-xray-{{TAG}}.tar.gz
cd os-xray-{{TAG}}
sh install.sh
```

Refresh the web UI, open **VPN → Xray**, enable the plugin, import a profile, Validate, save and Apply. Interface/gateway/client-rule setup remains necessary. Keep the extracted directory: `sh install.sh uninstall` stops/removes the plugin while retaining saved profiles and core binaries.

Use the `.tar.gz` asset below, not GitHub's automatic source archives or a raw script. SHA256SUMS and manifest.json record the exact archive, source commit and bundled file digests. See the included English/Russian README and `docs/BUILD.md` / `docs/TESTING.md` for details.

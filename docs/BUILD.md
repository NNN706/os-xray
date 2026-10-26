# Build and release / Сборка

`VERSION` is the distribution version. `package.json` and the lockfile must match it; XML model versions describe migration schemas and are deliberately independent. This is a PHP/shell plugin with a bundled JavaScript editor, not a native FreeBSD binary build. Xray-core and tun2socks are not built or downloaded here.

## Debian runner: one-time preparation

Use a dedicated runner without access to a production router. The registered runner Admiralteskiy has the `os-xray-build` label in addition to its existing labels. Trusted branch/tag pushes use this runner; all PR jobs use disposable GitHub-hosted Ubuntu workers. No OPNsense SSH secrets are needed for this pipeline.

An administrator installs build prerequisites once (Debian with PHP 8.3+; on older Debian install a suitable supported PHP CLI before continuing):

```sh
sudo apt-get update
sudo apt-get install -y git python3 curl openssl php-cli php-xml php-curl
```

Node.js 22 is supplied by setup-node in Actions. After checkout and `npm ci`, an administrator prepares Chromium system libraries once:

```sh
sudo npx playwright install-deps chromium
```

Run this only for this trusted checkout; normal CI uses `npx playwright install chromium` as the runner user without sudo. Browser downloads require outbound HTTPS. `scripts/preflight.mjs` reports missing executables, PHP version/modules/functions. The browser-launch step reports missing system libraries. CI never installs router packages or changes Unbound/DNS.

## Local build

```sh
node scripts/preflight.mjs
npm ci
npx playwright install chromium
npm test
python3 scripts/release.py pack
python3 scripts/release.py verify
```

Commit/stage new release inputs first: packaging selects tracked files from the allowlist, never arbitrary workspace contents. Uncommitted local previews are marked `source_dirty` in the manifest and cannot be published as a tagged release. Local testing may use `PHP_BIN=/absolute/path/to/php`. Node 22+, PHP CLI 8.3+ with JSON, SimpleXML, DOM, filter and process functions, Python 3.10+, curl, OpenSSL CLI and Git are required.

The archive includes `install.sh`, VERSION, LICENSE, both READMEs, plugin files and documentation. No node_modules, caches, `.tools`, Git metadata, runner files, development fixtures or test outputs are shipped. SHA256SUMS covers the archive and manifest; the manifest records the source commit and every installed source file's SHA-256. Timestamps and file order are deterministic for the same source commit/content.

## Release procedure

1. Review and commit changes. Update VERSION, package.json and package-lock.json together. Review `docs/RELEASE_NOTES.md` for this release.
2. Push a trusted feature/develop branch. Require a successful build/test/package job on Admiralteskiy. Download the Actions artifact if a pre-tag bundle is needed.
3. Create a new matching tag, for example `v4.0.0-rc.1`, on that exact commit and push it. This works from the feature branch without merging the draft PR. Never move a published tag.
4. The tag reruns the complete build and tests. Only success enables a separate publisher with write permission. It creates a draft release, uploads all three assets, then publishes it as prerelease when the version has an rc/beta/alpha suffix. It does not change the repository default branch.
5. The publisher downloads and verifies the public assets again. Reruns compare existing bytes and refuse replacement if any published asset differs. A matching complete release is left unchanged.

Checksums detect corruption; downloading archive and checksums from the same release does not protect against a compromised publisher. Use the exact versioned release URL, not a moving raw branch or a pipe into a shell.

## OPNsense acceptance

Portable CI is not an OPNsense certification. TUN/PF data-plane failover, recovery, reboot and real runtime rollback remain pending. See [TESTING.md](TESTING.md) and [HEARTBEAT.ru.md](HEARTBEAT.ru.md). The separate OPNsense-lab workflow is not part of this release's portable PASS.

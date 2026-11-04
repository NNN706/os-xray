#!/usr/bin/env bash
set -euo pipefail
umask 077

bundle=${1:?Release artifact directory required}
python3 scripts/release.py verify --out "$bundle"
tag=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["tag"])' "$bundle/manifest.json")
commit=$(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); assert not m["source_dirty"]; print(m["commit"])' "$bundle/manifest.json")
[[ "$commit" == "$(git rev-parse HEAD)" ]]
archive="os-xray-$tag.tar.gz"
digest=$(sha256sum "$bundle/$archive")
digest=${digest%% *}

# The runner account owns the identity and pinned host key.
lab=(ssh -T -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=8 -- "${LAB_SSH_TARGET:?Missing private SSH target}")
log=$(mktemp)
trap 'rm -f "$log"' EXIT
check() {
    local phase=$1
    shift
    if "$@" >"$log" 2>&1; then
        printf 'PASS %s\n' "$phase"
    else
        # Private diagnostics are available on the runner, with bounded retention.
        mkdir -p "$HOME/.local/state/os-xray"
        install -m 0600 "$log" "$HOME/.local/state/os-xray/last-lab-failure.log"
        printf 'FAIL %s\n' "$phase" >&2
        return 1
    fi
}

check 'verified noninteractive SSH' "${lab[@]}" true
check 'root on OPNsense/FreeBSD' "${lab[@]}" '/bin/sh -s' <<'REMOTE'
test "$(id -u)" = 0 &&
test "$(uname -s)" = FreeBSD &&
test -f /usr/local/etc/inc/config.inc
REMOTE
check 'isolated test VM marker' "${lab[@]}" '/bin/sh -s' <<'REMOTE'
test -f /root/.os-xray-isolated-lab &&
test ! -L /root/.os-xray-isolated-lab &&
test "$(stat -f "%u:%Lp" /root/.os-xray-isolated-lab)" = 0:600
REMOTE
remote_install=$(cat <<'REMOTE'
set -eu
umask 077
stage=$(mktemp -d /var/tmp/os-xray-acceptance.XXXXXX)
trap 'rm -rf "$stage"' EXIT
cat > "$stage/candidate.tar.gz"
test "$(sha256 -q "$stage/candidate.tar.gz")" = "$1"
tar -xzf "$stage/candidate.tar.gz" -C "$stage"
sh "$stage/os-xray-$2/install.sh" --isolated-lab-upgrade
test "$(cat /usr/local/opnsense/scripts/Xray/version.txt)" = "${2#v}"
REMOTE
)
# Keep the SSH command on one line even when the remote login shell is csh.
encoded=$(printf '%s' "$remote_install" | base64 -w0)
install_command='/bin/sh -c 'code=$(printf %s "$1" | /usr/bin/openssl base64 -d -A) && shift && eval "$code"' sh'
check 'install candidate and verify installed version' "${lab[@]}" \
    "$install_command $encoded $digest $tag" < "$bundle/$archive"
check 'repeat installation is noninteractive and idempotent' "${lab[@]}" \
    "$install_command $encoded $digest $tag" < "$bundle/$archive"
check 'installed dependencies and configd' "${lab[@]}" '/usr/local/bin/php -- installation' < tests/system/preflight.php
check 'prepared policy and framework' "${lab[@]}" '/usr/local/bin/php -- policy' < tests/system/preflight.php
check 'heartbeat bypass, restoration and system invariants' "${lab[@]}"     'env XRAY_ISOLATED_LAB=YES /usr/local/bin/php' < tests/system/failover.php
# This runner-local executable owns the independent LAN probe and live Web UI credentials.
check 'independent client and live UI acceptance' "$HOME/.config/os-xray/lab-acceptance" "$tag"

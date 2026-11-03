#!/bin/sh
# Install the bundled plugin and provision missing dependencies.
set -eu
umask 077
PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH
RELEASE_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/plugin"
VERSION_FILE="/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt"
die() { echo "ERROR: $*" >&2; exit 1; }
[ "$(id -u)" = 0 ] || die "Run this installer as root on OPNsense."
[ "$(uname -s)" = FreeBSD ] && [ -f /usr/local/etc/inc/config.inc ] || die "OPNsense/FreeBSD required."

stage=
cleanup() {
    if [ -n "$stage" ]; then
        find "$stage" -depth -delete
    fi
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
new_stage() {
    stage=$(mktemp -d /var/tmp/os-xray-install.XXXXXX) || die "Cannot create staging directory."
}
download() {
    echo "Downloading $1 ..."
    /usr/bin/fetch -o "$2" "$1" || die "Download failed: $1"
}
verify_sha() {
    [ "$(sha256 -q "$1")" = "$2" ] || die "SHA-256 mismatch: $1"
    echo "SHA-256 verified: ${1##*/}"
}

if [ "${1:-}" = --release ]; then
    [ "$#" = 2 ] || die "Usage: sh install.sh --release vVERSION"
    tag=$2
    printf '%s\n' "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-[a-z]+\.[0-9]+)?$' || die "Invalid release tag."
    new_stage
    archive="os-xray-$tag.tar.gz"
    base="https://github.com/NNN706/os-xray/releases/download/$tag"
    download "$base/$archive" "$stage/$archive"
    download "$base/SHA256SUMS" "$stage/SHA256SUMS"
    hash=$(awk -v name="$archive" '$2 == name {print $1}' "$stage/SHA256SUMS")
    verify_sha "$stage/$archive" "$hash"
    /usr/bin/tar -xf "$stage/$archive" -C "$stage" || die "Cannot extract release."
    /bin/sh "$stage/os-xray-$tag/install.sh"
    exit
fi
# Standalone entry point: install a pinned, verified bundle when run without plugin files.
if [ "$#" = 0 ] && [ ! -f "$RELEASE_ROOT/VERSION" ]; then
    new_stage
    download 'https://codeload.github.com/NNN706/os-xray/tar.gz/a408aa82420c05f26c6555ee99144e0d0fe0539a' "$stage/os-xray.tar.gz"
    verify_sha "$stage/os-xray.tar.gz" '7224f3809bbd577f14e35b582a1865776f617770a55cb336f167f0c69c6f27d3'
    /usr/bin/tar -xzf "$stage/os-xray.tar.gz" -C "$stage" || die "Cannot extract plugin bundle."
    bundle="$stage/os-xray-a408aa82420c05f26c6555ee99144e0d0fe0539a"
    # Use the current installer, not the obsolete installer inside the pinned payload.
    install -m 0600 "$0" "$bundle/install.sh"
    /bin/sh "$bundle/install.sh"
    exit
fi
[ -s "$RELEASE_ROOT/VERSION" ] || die "Extract the complete release archive before running install.sh (VERSION is missing)."
PLUGIN_VERSION="$(cat "$RELEASE_ROOT/VERSION")"


refresh_cron() {
    /usr/local/sbin/configctl cron restart || die "Cannot refresh OPNsense cron; check configd and rerun the installer."
}
if [ "${1:-}" = "uninstall" ]; then
    if [ -x /usr/local/opnsense/scripts/Xray/xray-service-control.php ]; then
        /usr/local/opnsense/scripts/Xray/xray-service-control.php stop || die "Cannot stop tunnel; plugin files retained."
    fi
    rm -rf /usr/local/opnsense/scripts/Xray \
           /usr/local/opnsense/mvc/app/models/OPNsense/Xray \
           /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray \
           /usr/local/opnsense/mvc/app/views/OPNsense/Xray \
           /usr/local/www/assets/xray
    rm -f /usr/local/opnsense/service/conf/actions.d/actions_xray.conf \
          /usr/local/etc/inc/plugins.inc.d/xray.inc \
          /usr/local/etc/rc.syshook.d/start/50-xray \
          /usr/local/etc/newsyslog.conf.d/xray.conf
    service configd restart
    refresh_cron
    echo "Plugin removed. Saved profiles, applied snapshots and core binaries retained."
    exit 0
fi
[ -z "${1:-}" ] || die "Usage: sh install.sh [uninstall | --release vVERSION]"
[ -s "$PLUGIN_DIR/www/assets/xray/editor.js" ] || die "Bundled editor missing; build the release with npm ci && npm run build."
[ ! -L /var/tmp/xray-api ] || die "Private API directory must not be a symlink."

[ -x /usr/local/bin/php ] || die "OPNsense PHP is missing."
visit_layout() {
    "$1" "$PLUGIN_DIR/scripts/Xray" /usr/local/opnsense/scripts/Xray 0755
    "$1" "$PLUGIN_DIR/service/conf/actions.d" /usr/local/opnsense/service/conf/actions.d 0644
    "$1" "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray" /usr/local/opnsense/mvc/app/models/OPNsense/Xray 0644
    "$1" "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray" /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray 0644
    "$1" "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray" /usr/local/opnsense/mvc/app/views/OPNsense/Xray 0644
    "$1" "$PLUGIN_DIR/etc/inc/plugins.inc.d" /usr/local/etc/inc/plugins.inc.d 0644
    "$1" "$PLUGIN_DIR/etc/rc.syshook.d/start" /usr/local/etc/rc.syshook.d/start 0755
    "$1" "$PLUGIN_DIR/etc/newsyslog.conf.d" /usr/local/etc/newsyslog.conf.d 0644
    "$1" "$PLUGIN_DIR/www/assets/xray" /usr/local/www/assets/xray 0644
}
inspect_tree() {
    [ -d "$1" ] || die "Missing plugin source directory: $1"
    find "$1" -type f -exec /bin/sh -ec '
        src=$1; dst=$2; shift 3
        for item do
            rel=${item#"$src"/}
            target="$dst/$rel"
            parent="$target"
            while [ "$parent" != / ]; do
                if [ -L "$parent" ]; then
                    echo "ERROR: Symlink in os-xray destination: $parent. Inspect it manually." >&2
                    exit 1
                fi
                parent=$(dirname "$parent")
            done
            if [ -e "$target" ]; then
                [ -f "$target" ] || { echo "ERROR: Expected a file: $target" >&2; exit 1; }
                if owner=$(/usr/local/sbin/pkg which -q "$target" 2>/dev/null); then
                    echo "ERROR: $target belongs to package $owner. Resolve package ownership before retrying; no packages were removed." >&2
                    exit 1
                fi
                if ! cmp -s "$item" "$target"; then
                    printf "%s\n" "$target"
                fi
            fi
        done
    ' sh "$@" {} +
}
new_stage
visit_layout inspect_tree > "$stage/conflicts" || die "Unsafe os-xray destination; installation stopped before copying files."
for marker in "$VERSION_FILE" /usr/local/opnsense/scripts/Xray/version.txt; do
    [ ! -L "$marker" ] || die "Symlink in os-xray version file: $marker"
    if [ -e "$marker" ]; then
        [ -f "$marker" ] || die "Expected an os-xray version file: $marker"
        if owner=$(/usr/local/sbin/pkg which -q "$marker" 2>/dev/null); then
            die "$marker belongs to package $owner. Resolve package ownership before retrying."
        fi
        cmp -s "$RELEASE_ROOT/VERSION" "$marker" || printf '%s\n' "$marker" >> "$stage/conflicts"
    fi
done
if [ -s "$stage/conflicts" ]; then
    echo "Existing os-xray files differ from this build:"
    cat "$stage/conflicts"
    echo "Proposed action: back up the listed files and OPNsense config, then replace only the listed os-xray files and install any missing plugin files."
    echo "Existing Xray/tun2socks binaries, profiles and interface assignments are retained."
    if ! ( : </dev/tty ) 2>/dev/null; then
        die "Interactive confirmation is required. Rerun from an SSH or local console."
    fi
    printf 'Back up and replace these os-xray files? [y/N] '
    read -r answer </dev/tty || die "No confirmation received."
    case "$answer" in y|Y|yes|YES) ;; *) echo "Cancelled; no installation files changed."; exit 0 ;; esac
    backup=$(mktemp -d /root/os-xray-backup.XXXXXX) || die "Cannot create backup."
    while IFS= read -r target; do
        install -d -m 0700 "$backup$(dirname "$target")"
        cp -p "$target" "$backup$target"
    done < "$stage/conflicts"
    install -m 0600 /conf/config.xml "$backup/config.xml"
    echo "Backup saved to $backup"
else
    echo "No conflicting os-xray files found."
fi

xray=/usr/local/bin/xray-core
t2s=/usr/local/tun2socks/tun2socks
for binary in "$xray" "$t2s" /usr/local/bin/curl; do
    if [ -x "$binary" ]; then
        echo "Already installed: $binary (keeping existing file)"
    elif [ -e "$binary" ] || [ -L "$binary" ]; then
        die "Existing dependency is not executable: $binary; resolve it before retrying."
    fi
done

if [ ! -x "$xray" ] || [ ! -x "$t2s" ]; then
    [ "$(uname -m)" = amd64 ] || die "Automatic binary installation supports FreeBSD amd64; install compatible binaries manually."
fi
if [ ! -x "$xray" ]; then
    download 'https://github.com/XTLS/Xray-core/releases/download/v26.10.31/Xray-freebsd-64.zip' "$stage/xray.zip"
    verify_sha "$stage/xray.zip" '31de1013a74b170ed709f8bc51eae9880296566e8247c0d8b9d5846661d0842b'
    for member in xray geoip.dat geosite.dat; do
        /usr/bin/tar -xOf "$stage/xray.zip" "$member" > "$stage/$member"
        [ -s "$stage/$member" ] || die "Missing Xray archive member: $member"
    done
fi
if [ ! -x "$t2s" ]; then
    download 'https://github.com/xjasonlyu/tun2socks/releases/download/v2.7.0/tun2socks-freebsd-amd64.zip' "$stage/tun2socks.zip"
    verify_sha "$stage/tun2socks.zip" '3ebb747aa83ee3157330beb324bbeaf9a742db6a8d24b9bbc96a4125aaeeeaea'
    /usr/bin/tar -xOf "$stage/tun2socks.zip" tun2socks-freebsd-amd64 > "$stage/tun2socks"
    [ -s "$stage/tun2socks" ] || die "Missing tun2socks binary."
fi
if [ ! -x /usr/local/bin/curl ]; then
    echo "Installing curl from configured OPNsense package repositories ..."
    /usr/local/sbin/pkg install -U -y curl || die "Cannot install curl from configured repositories."
    [ -x /usr/local/bin/curl ] || die "curl executable missing after package installation."
fi
if [ ! -x "$xray" ]; then
    install -d -m 0755 /usr/local/share/xray
    for member in geoip.dat geosite.dat; do
        if [ ! -e "/usr/local/share/xray/$member" ] && [ ! -L "/usr/local/share/xray/$member" ]; then
            install -m 0644 "$stage/$member" "/usr/local/share/xray/$member"
        fi
    done
    install -S -m 0755 "$stage/xray" "$xray"
    echo "Installed Xray-core v26.10.31: $xray"
fi
if [ ! -x "$t2s" ]; then
    install -d -m 0755 /usr/local/tun2socks
    install -S -m 0755 "$stage/tun2socks" "$t2s"
    echo "Installed tun2socks v2.7.0: $t2s"
fi
echo "Registering os-xray files and services in OPNsense ..."

install_tree() {
    _src="$1"; _dst="$2"; _mode="$3"
    [ -d "$_src" ] || die "Missing plugin source directory."
    find "$_src" -type f -exec /bin/sh -ec '
        src=$1; dst=$2; mode=$3; shift 3
        for item do
            rel=${item#"$src"/}
            target="$dst/$rel"
            install -d -m 0755 "$(dirname "$target")"
            if ! cmp -s "$item" "$target"; then
                install -S -m "$mode" "$item" "$target"
            else
                chmod "$mode" "$target"
            fi
        done
    ' sh "$_src" "$_dst" "$_mode" {} +
}
visit_layout install_tree
install -d -m 0700 -o www -g www /var/tmp/xray-api
install -d -m 0700 /usr/local/etc/xray-core/profiles
find /usr/local/etc/xray-core -maxdepth 1 -name 'config*.json' -type f -exec chmod 0600 {} +
find /usr/local/tun2socks -maxdepth 1 -name 'config*.yaml' -type f -exec chmod 0600 {} +

/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-migrate.php || die "Migration failed; original source fields retained."
install -S -m 0644 "$RELEASE_ROOT/VERSION" /usr/local/opnsense/scripts/Xray/version.txt
install -S -m 0644 "$RELEASE_ROOT/VERSION" "$VERSION_FILE"
service configd restart
refresh_cron
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml

echo "os-xray $PLUGIN_VERSION installed. Refresh the browser, review full JSON and Validate."
echo "Save, then Apply or reconnect to activate. Subscription updates never restart the tunnel."
echo "One active tunnel; multiple outbounds and balancers belong inside its JSON."
echo "System interface, gateway and firewall assignments remain administrator-managed."

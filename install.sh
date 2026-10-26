#!/bin/sh
# Install the bundled plugin; dependencies and live tunnel changes are explicit.
set -eu
umask 077
RELEASE_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PLUGIN_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/plugin"
VERSION_FILE="/usr/local/opnsense/mvc/app/models/OPNsense/Xray/version.txt"
die() { echo "ERROR: $*" >&2; exit 1; }
[ -s "$RELEASE_ROOT/VERSION" ] || die "Extract the complete release archive before running install.sh (VERSION is missing)."
PLUGIN_VERSION="$(cat "$RELEASE_ROOT/VERSION")"
[ "$(id -u)" = 0 ] || die "Run this installer as root on OPNsense."
[ -f /usr/local/etc/inc/config.inc ] || die "OPNsense configuration framework not found."

refresh_cron() {
    /usr/local/bin/php -r 'set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path()); require_once("config.inc"); require_once("system.inc"); require_once("plugins.inc"); system_cron_configure();'
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
[ -z "${1:-}" ] || die "Usage: sh install.sh [uninstall]"
for binary in /usr/local/bin/php /usr/local/bin/xray-core /usr/local/tun2socks/tun2socks /usr/local/bin/curl; do
    [ -x "$binary" ] || die "Required dependency is missing: $binary. Install it before running this installer."
done
[ -s "$PLUGIN_DIR/www/assets/xray/editor.js" ] || die "Bundled editor missing; build the release with npm ci && npm run build."
[ ! -L /var/tmp/xray-api ] || die "Private API directory must not be a symlink."

install_tree() {
    _src="$1"; _dst="$2"; _mode="$3"
    [ -d "$_src" ] || die "Missing plugin source directory."
    find "$_src" -type f -exec /bin/sh -ec '
        src=$1; dst=$2; mode=$3; shift 3
        for item do
            rel=${item#"$src"/}
            target="$dst/$rel"
            install -d -m 0755 "$(dirname "$target")"
            install -m "$mode" "$item" "$target"
        done
    ' sh "$_src" "$_dst" "$_mode" {} +
}
install_tree "$PLUGIN_DIR/scripts/Xray" /usr/local/opnsense/scripts/Xray 0755
install_tree "$PLUGIN_DIR/service/conf/actions.d" /usr/local/opnsense/service/conf/actions.d 0644
install_tree "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray" /usr/local/opnsense/mvc/app/models/OPNsense/Xray 0644
install_tree "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray" /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray 0644
install_tree "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray" /usr/local/opnsense/mvc/app/views/OPNsense/Xray 0644
install_tree "$PLUGIN_DIR/etc/inc/plugins.inc.d" /usr/local/etc/inc/plugins.inc.d 0644
install_tree "$PLUGIN_DIR/etc/rc.syshook.d/start" /usr/local/etc/rc.syshook.d/start 0755
install_tree "$PLUGIN_DIR/etc/newsyslog.conf.d" /usr/local/etc/newsyslog.conf.d 0644
install_tree "$PLUGIN_DIR/www/assets/xray" /usr/local/www/assets/xray 0644
install -d -m 0700 -o www -g www /var/tmp/xray-api
install -d -m 0700 /usr/local/etc/xray-core/profiles
find /usr/local/etc/xray-core -maxdepth 1 -name 'config*.json' -type f -exec chmod 0600 {} +
find /usr/local/tun2socks -maxdepth 1 -name 'config*.yaml' -type f -exec chmod 0600 {} +

/usr/local/bin/php /usr/local/opnsense/scripts/Xray/xray-migrate.php || die "Migration failed; original source fields retained."
install -m 0644 "$RELEASE_ROOT/VERSION" /usr/local/opnsense/scripts/Xray/version.txt
echo "$PLUGIN_VERSION" > "$VERSION_FILE"
chmod 0644 "$VERSION_FILE"
service configd restart
refresh_cron
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml

echo "os-xray $PLUGIN_VERSION installed. Refresh the browser, review full JSON and Validate."
echo "Save, then Apply or reconnect to activate. Subscription updates never restart the tunnel."
echo "One active tunnel; multiple outbounds and balancers belong inside its JSON."
echo "System interface, gateway and firewall assignments remain administrator-managed."

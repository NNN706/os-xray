#!/usr/bin/env bash
set -euo pipefail
umask 077
: "${LAB_SSH_TARGET:?Missing lab SSH target}"
: "${LAB_SUBSCRIPTION_FIXTURE:?Missing lab subscription fixture}"

# Execute candidate fetch/validation code without changing installed files.
# The subscription fixture travels inside the SSH stdin stream only.
result=$(
{
    cat plugin/scripts/Xray/xray-config.inc.php
    sed '1,2d' plugin/scripts/Xray/xray-subscription.inc.php
    sed '1d' tests/system/subscription.php
    printf 'try { verifySubscription(json_decode(base64_decode("'
    printf '%s' "$LAB_SUBSCRIPTION_FIXTURE" | base64 -w0
    printf '"), true, 8, JSON_THROW_ON_ERROR)); } catch (Throwable $error) { exit(1); }\n'
} | ssh -T -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10 \
    -- "$LAB_SSH_TARGET" /usr/local/bin/php 2>/dev/null
) ||
    { echo 'FAIL subscription fetch or validation on isolated OPNsense' >&2; exit 1; }
[[ "$result" == 'PASS 13 subscription profiles fetched and validated on OPNsense' ]] ||
    { echo 'FAIL unexpected response from isolated OPNsense' >&2; exit 1; }
printf '%s\n' "$result"

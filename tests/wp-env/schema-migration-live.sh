#!/bin/zsh
# Live checks for the schema-version migrations on the wp-env dev site:
# a site that ran the first 1.13.0 build (db_version already 1.13.0, OAuth
# tables but no grants table, no schema option) gets the grants table and the
# backfill; replaying all steps is a no-op for the data; the pre-1.13.0 path
# still carries the client option over.
#
# All state is prepared and read through `wp db query`, which does not boot
# WordPress: any `wp option` / `wp eval` call would run the plugin's
# migrations itself before executing and hide what the HTTP load does.
#   tests/wp-env/schema-migration-live.sh [base-url]
set -u
cd "$(dirname "$0")/../.."
W() { npx @wordpress/env run cli "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }
Q() { W wp db query "$1" --skip-column-names | tail -1; }
X() { W wp db query "$1" >/dev/null; }
B="${1:-http://localhost:8888}"
P=$(W wp db prefix)
O="${P}options"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "  PASS  $1"; pass=$((pass+1)); else echo "  FAIL  $1: got '$2', want '$3'"; fail=$((fail+1)); fi; }
load() { curl -s -o /dev/null "$B/"; }
opt() { Q "SELECT option_value FROM $O WHERE option_name='$1'"; }
setopt_() { X "INSERT INTO $O (option_name, option_value, autoload) VALUES ('$1', '$2', 'off') ON DUPLICATE KEY UPDATE option_value='$2'"; }
delopt() { X "DELETE FROM $O WHERE option_name='$1'"; }
PLUGIN_VERSION=$(grep -o "IATO_MCP_VERSION', '[0-9.]*" iato-mcp.php | grep -o '[0-9.]*$')

# Seed one grant so the backfill has something to derive.
GRANT=$(python3 -c 'import secrets;print(secrets.token_hex(16))')
NOW=$(date -u +'%Y-%m-%d %H:%M:%S')
X "INSERT INTO ${P}iato_mcp_oauth_tokens (kind, token_hash, grant_id, client_id, user_id, redirect_uri, granted_at, created_at, expires_at) VALUES ('access', SHA2('schema-live-$GRANT', 256), '$GRANT', 'schema-live', 1, 'https://claude.ai/cb', '$NOW', '$NOW', DATE_ADD('$NOW', INTERVAL 1 HOUR))"
TOKENS_BEFORE=$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_tokens")
CLIENTS_BEFORE=$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_clients")
GRANT_IDS=$(Q "SELECT COUNT(DISTINCT grant_id) FROM ${P}iato_mcp_oauth_tokens")

echo "# A. site that ran the first 1.13.0 build: db_version 1.13.0, no grants table, no schema option"
setopt_ iato_mcp_db_version 1.13.0
delopt iato_mcp_schema_version
delopt iato_mcp_oauth_grants_backfilled
X "DROP TABLE IF EXISTS ${P}iato_mcp_oauth_grants"
check "grants table gone before the load" "$(Q "SHOW TABLES LIKE '${P}iato_mcp_oauth_grants'")" ""
check "schema option gone before the load" "$(opt iato_mcp_schema_version)" ""
load
check "schema version recorded" "$(opt iato_mcp_schema_version)" "3"
check "grants table created" "$(Q "SHOW TABLES LIKE '${P}iato_mcp_oauth_grants'")" "${P}iato_mcp_oauth_grants"
check "one grant row per grant_id in the token table" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_grants")" "$GRANT_IDS"
check "seeded grant backfilled with its client and user" "$(Q "SELECT CONCAT(client_id,':',user_id,':',IFNULL(revoked_at,'live')) FROM ${P}iato_mcp_oauth_grants WHERE grant_id='$GRANT'")" "schema-live:1:live"
check "token rows untouched" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_tokens")" "$TOKENS_BEFORE"
check "db_version unchanged (it is not the gate any more)" "$(opt iato_mcp_db_version)" "1.13.0"
check "old one-shot flag retired" "$(opt iato_mcp_oauth_grants_backfilled)" ""

echo "# B. replaying every step is harmless"
delopt iato_mcp_schema_version
load
check "schema version back to 3" "$(opt iato_mcp_schema_version)" "3"
check "grant rows unchanged" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_grants")" "$GRANT_IDS"
check "token rows unchanged" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_tokens")" "$TOKENS_BEFORE"
check "client rows unchanged" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_clients")" "$CLIENTS_BEFORE"

echo "# C. pre-1.13.0 site: option-based clients carried over, flags set"
delopt iato_mcp_schema_version
setopt_ iato_mcp_db_version 1.12.1
delopt iato_mcp_oauth_legacy_key
delopt iato_mcp_oauth_legacy_notice
LEGACY=$(php -r 'echo serialize(["schemaold" => ["client_name" => "Schema Old", "redirect_uris" => ["https://claude.ai/api/mcp/auth_callback"], "created" => time() - 86400]]);')
setopt_ iato_mcp_oauth_clients "$LEGACY"
check "legacy option in place before the load" "$(opt iato_mcp_oauth_clients | grep -c schemaold)" "1"
load
check "legacy client row created and authorized" "$(Q "SELECT CONCAT(client_name,':',IF(authorized_at IS NULL,'pending','authorized')) FROM ${P}iato_mcp_oauth_clients WHERE client_id='schemaold'")" "Schema Old:authorized"
check "legacy option removed" "$(opt iato_mcp_oauth_clients)" ""
check "legacy-key flag set" "$(opt iato_mcp_oauth_legacy_key)" "1"
check "notice flag set" "$(opt iato_mcp_oauth_legacy_notice)" "1"
check "db_version bumped to the plugin version" "$(opt iato_mcp_db_version)" "$PLUGIN_VERSION"
check "schema version 3 again" "$(opt iato_mcp_schema_version)" "3"
load
check "second load: client not duplicated" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_clients WHERE client_id='schemaold'")" "1"


# Cleanup
delopt iato_mcp_oauth_legacy_key
delopt iato_mcp_oauth_legacy_notice
X "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='schemaold'"
X "DELETE FROM ${P}iato_mcp_oauth_tokens WHERE grant_id='$GRANT'"
X "DELETE FROM ${P}iato_mcp_oauth_grants WHERE grant_id='$GRANT'"
echo "# $pass passed, $fail failed"
[ "$fail" -eq 0 ]

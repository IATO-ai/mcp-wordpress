#!/bin/zsh
# Live checks for the schema-version migrations on the wp-env dev site:
# a site that ran a pre-release build (db_version already the plugin version, OAuth
# tables but no grants table, no schema option) gets the grants table and the
# backfill; replaying all steps is a no-op for the data; the pre-1.13.0 path
# still carries the client option over; a site at schema 3 gets the receipt
# actor and call-log credential columns (step 4) with its rows intact; a site
# at schema 4 gets receipts.pruned_at and the cleanup index (step 5).
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

echo "# A. site that ran a pre-release build: db_version already $PLUGIN_VERSION, no grants table, no schema option"
setopt_ iato_mcp_db_version "$PLUGIN_VERSION"
delopt iato_mcp_schema_version
delopt iato_mcp_oauth_grants_backfilled
X "DROP TABLE IF EXISTS ${P}iato_mcp_oauth_grants"
check "grants table gone before the load" "$(Q "SHOW TABLES LIKE '${P}iato_mcp_oauth_grants'")" ""
check "schema option gone before the load" "$(opt iato_mcp_schema_version)" ""
load
check "schema version recorded" "$(opt iato_mcp_schema_version)" "5"
check "grants table created" "$(Q "SHOW TABLES LIKE '${P}iato_mcp_oauth_grants'")" "${P}iato_mcp_oauth_grants"
check "one grant row per grant_id in the token table" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_grants")" "$GRANT_IDS"
check "seeded grant backfilled with its client and user" "$(Q "SELECT CONCAT(client_id,':',user_id,':',IFNULL(revoked_at,'live')) FROM ${P}iato_mcp_oauth_grants WHERE grant_id='$GRANT'")" "schema-live:1:live"
check "token rows untouched" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_tokens")" "$TOKENS_BEFORE"
check "db_version unchanged (it is not the gate any more)" "$(opt iato_mcp_db_version)" "$PLUGIN_VERSION"
check "old one-shot flag retired" "$(opt iato_mcp_oauth_grants_backfilled)" ""

echo "# B. replaying every step is harmless"
delopt iato_mcp_schema_version
load
check "schema version back to 5" "$(opt iato_mcp_schema_version)" "5"
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
check "schema version 5 again" "$(opt iato_mcp_schema_version)" "5"
load
check "second load: client not duplicated" "$(Q "SELECT COUNT(*) FROM ${P}iato_mcp_oauth_clients WHERE client_id='schemaold'")" "1"

echo "# D. site at schema 3: step 4 adds the actor and credential columns, rows intact"
R="${P}iato_change_receipts"
L="${P}iato_mcp_call_log"
col() { Q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1' AND COLUMN_NAME = '$2'"; }
for c in actor_user_id actor_kind actor_client_id rolled_back_by_user_id rolled_back_by_kind rolled_back_by_client_id; do
	[ "$(col "$R" "$c")" = "1" ] && X "ALTER TABLE $R DROP COLUMN $c"
done
for c in credential_kind client_id; do
	[ "$(col "$L" "$c")" = "1" ] && X "ALTER TABLE $L DROP COLUMN $c"
done
OLDCID="wr_$(python3 -c 'import secrets;print(secrets.token_hex(8))')"
X "INSERT INTO $R (change_id, post_id, target_type, field, before_value, after_value, applied_at) VALUES ('$OLDCID', 1, 'post', 'title', 'schema-live-before', 'schema-live-after', UTC_TIMESTAMP())"
RECEIPTS_BEFORE=$(Q "SELECT COUNT(*) FROM $R")
CALLS_BEFORE=$(Q "SELECT COUNT(*) FROM $L")
setopt_ iato_mcp_schema_version 3
check "actor columns gone before the load" "$(col "$R" actor_kind)$(col "$L" credential_kind)" "00"
load
check "schema version 5 (steps 4 and 5 ran)" "$(opt iato_mcp_schema_version)" "5"
for c in actor_user_id actor_kind actor_client_id rolled_back_by_user_id rolled_back_by_kind rolled_back_by_client_id; do
	check "receipts column $c added" "$(col "$R" "$c")" "1"
done
check "call log column credential_kind added" "$(col "$L" credential_kind)" "1"
check "call log column client_id added" "$(col "$L" client_id)" "1"
check "actor_user_id index added" "$(Q "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$R' AND INDEX_NAME = 'actor_user_id'")" "1"
check "one primary key, unchanged" "$(Q "SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$R' AND INDEX_NAME = 'PRIMARY'")" "id"
check "existing receipt kept, actor NULL (recorded before actors)" "$(Q "SELECT CONCAT(after_value, ':', IFNULL(actor_kind, 'NULL'), ':', IFNULL(actor_user_id, 'NULL')) FROM $R WHERE change_id = '$OLDCID'")" "schema-live-after:NULL:NULL"
check "receipt rows unchanged" "$(Q "SELECT COUNT(*) FROM $R")" "$RECEIPTS_BEFORE"
check "call log rows kept (the load adds none: no MCP call)" "$(Q "SELECT COUNT(*) FROM $L")" "$CALLS_BEFORE"
delopt iato_mcp_schema_version
load
check "replaying every step keeps the columns and the rows" "$(opt iato_mcp_schema_version):$(col "$R" actor_kind):$(Q "SELECT COUNT(*) FROM $R")" "5:1:$RECEIPTS_BEFORE"
X "DELETE FROM $R WHERE change_id = '$OLDCID'"

echo "# E. site at schema 4: step 5 adds pruned_at and the cleanup index"
idx() { Q "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$1' AND INDEX_NAME = '$2'"; }
[ "$(col "$R" pruned_at)" = "1" ] && X "ALTER TABLE $R DROP COLUMN pruned_at"
[ "$(idx "$R" target_applied)" = "1" ] && X "ALTER TABLE $R DROP INDEX target_applied"
OLDCID="wr_$(python3 -c 'import secrets;print(secrets.token_hex(8))')"
X "INSERT INTO $R (change_id, post_id, target_type, field, before_value, after_value, applied_at, actor_user_id, actor_kind) VALUES ('$OLDCID', 1, 'elementor_widget', 'elementor:document:patch', '[]', 'sha256:schema-live', UTC_TIMESTAMP(), 1, 'site_key')"
setopt_ iato_mcp_schema_version 4
check "pruned_at and index gone before the load" "$(col "$R" pruned_at)$(idx "$R" target_applied)" "00"
load
check "schema version 5" "$(opt iato_mcp_schema_version)" "5"
check "pruned_at added" "$(col "$R" pruned_at)" "1"
check "index on (target_type, applied_at) added" "$(Q "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$R' AND INDEX_NAME = 'target_applied'")" "target_type,applied_at"
check "existing receipt kept, not pruned" "$(Q "SELECT CONCAT(before_value, ':', IFNULL(pruned_at, 'NULL'), ':', actor_kind) FROM $R WHERE change_id = '$OLDCID'")" "[]:NULL:site_key"
X "DELETE FROM $R WHERE change_id = '$OLDCID'"

# Cleanup
delopt iato_mcp_oauth_legacy_key
delopt iato_mcp_oauth_legacy_notice
X "DELETE FROM ${P}iato_mcp_oauth_clients WHERE client_id='schemaold'"
X "DELETE FROM ${P}iato_mcp_oauth_tokens WHERE grant_id='$GRANT'"
X "DELETE FROM ${P}iato_mcp_oauth_grants WHERE grant_id='$GRANT'"
echo "# $pass passed, $fail failed"
[ "$fail" -eq 0 ]

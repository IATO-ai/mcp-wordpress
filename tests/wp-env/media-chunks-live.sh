#!/bin/zsh
# Live checks for 1.19.0: sha256, decoded_bytes and whole-image checks on create_media,
# and chunked uploads through media_upload_start, media_upload_chunk and
# media_upload_finish: stored bytes, image sizes, retries, refusals, who may do what,
# receipts and rollback, the temp folder's protection and its cleanup.
#   tests/wp-env/media-chunks-live.sh [base-url]
#   tests/wp-env/media-chunks-live.sh --cleanup
# Another site: WPENV_DIR (its wp-env folder) and WPENV_SVC (default cli; tests-cli).
set -u
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${WPENV_DIR:-$REPO}"
export WPENV_SVC="${WPENV_SVC:-cli}"
W() { npx @wordpress/env run "$WPENV_SVC" "$@" 2>/dev/null </dev/null | grep -v '^ℹ' | grep -v '^✔' | grep -v '^$'; }

cleanup() {
	W wp eval '$b=get_option("iato_test_media_chunks_backup");if(is_array($b)){update_option("iato_mcp_key_owner",$b["owner"]);foreach(["iato_mcp_media_max_upload_size","iato_mcp_media_upload_rate_limit"] as $o){if(false===$b[$o]){delete_option($o);}else{update_option($o,$b[$o]);}}delete_option("iato_test_media_chunks_backup");}$d=wp_upload_dir(null,false)["basedir"]."/iato-mcp-tmp";if(is_dir($d)){foreach(scandir($d) as $f){if(is_file("$d/$f")){unlink("$d/$f");}}rmdir($d);}' >/dev/null
	for id in $(W wp post list --post_type=attachment,post --post_status=any,trash --s=media-chunks-live --format=ids | tr ' ' '\n'); do W wp post delete "$id" --force >/dev/null; done
	W wp eval 'global $wpdb;foreach($wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"_transient_iato_mcp_upload_count_%\"") as $o){delete_transient(substr($o,11));}$d=wp_upload_dir()["basedir"];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()&&0===strpos($f->getFilename(),"media-chunks-live")){unlink($f->getPathname());}}' >/dev/null
	for user in admin editor author contributor; do
		W wp user application-password list "$user" --fields=uuid,name --format=csv 2>/dev/null | tail -n +2 | while IFS=, read -r uuid name; do case "$name" in live-chunks-*) W wp user application-password delete "$user" "$uuid" >/dev/null ;; esac; done
	done
	W wp eval 'global $wpdb;foreach(["tokens","grants","clients"] as $t){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}iato_mcp_oauth_{$t} WHERE client_id=%s","live-chunks-client"));}' >/dev/null
}
if [ "${1:-}" = "--cleanup" ]; then
	cleanup
	echo "cleanup done"
	exit 0
fi

export BASE="${1:-http://localhost:8888}"
W wp eval 'add_option("iato_test_media_chunks_backup",["owner"=>get_option("iato_mcp_key_owner"),"iato_mcp_media_max_upload_size"=>get_option("iato_mcp_media_max_upload_size"),"iato_mcp_media_upload_rate_limit"=>get_option("iato_mcp_media_upload_rate_limit")],"","no");' >/dev/null
export KEY=$(W wp option get iato_mcp_key)
for user in editor author contributor; do
	W wp user get "$user" --field=ID >/dev/null 2>&1 || W wp user create "$user" "$user@example.test" --role="$user" --user_pass="$user-pass" >/dev/null
done
STAMP=$(date +%s)
export PW_ADMIN=$(W wp user application-password create admin live-chunks-$STAMP --porcelain)
export PW_EDITOR=$(W wp user application-password create editor live-chunks-$STAMP --porcelain)
export PW_AUTHOR=$(W wp user application-password create author live-chunks-$STAMP --porcelain)
export PW_CONTRIBUTOR=$(W wp user application-password create contributor live-chunks-$STAMP --porcelain)
export ADMIN_ID=$(W wp user get admin --field=ID)
export OAUTH_TOKEN=$(W wp eval '
global $wpdb;
$now=gmdate("Y-m-d H:i:s");$token="iato_at_".wp_generate_password(40,false);$uid=(int)get_user_by("login","admin")->ID;
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_clients",["client_id"=>"live-chunks-client","client_name"=>"media-chunks-live","redirect_uris"=>wp_json_encode(["http://localhost/cb"]),"created_at"=>$now,"authorized_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_grants",["grant_id"=>"live-chunks-grant","client_id"=>"live-chunks-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now]);
$wpdb->insert($wpdb->prefix."iato_mcp_oauth_tokens",["kind"=>"access","token_hash"=>hash("sha256",$token),"grant_id"=>"live-chunks-grant","client_id"=>"live-chunks-client","user_id"=>$uid,"redirect_uri"=>"http://localhost/cb","granted_at"=>$now,"created_at"=>$now,"expires_at"=>gmdate("Y-m-d H:i:s",time()+3600)]);
echo $token;')
python3 -I "$REPO/tests/wp-env/media-chunks-live.py"
rc=$?
cleanup
exit $rc

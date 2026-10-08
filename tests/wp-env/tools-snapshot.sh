#!/bin/zsh
# Create scratch pages, run tools-snapshot.py for one label, delete the scratch pages.
#   tests/wp-env/tools-snapshot.sh <label> <bearer-key> <admin-app-pw> <out-dir>
set -u
LABEL="$1"; KEY="$2"; APPPW="$3"; OUT="$4"
W() { cd /Users/garennebigby/Projects/mcp-wordpress && npx --yes @wordpress/env@latest run cli "$@" 2>/dev/null | grep -v '^ℹ\|^✔' | sed '/^$/d'; }
mk_plain() { W wp post create --post_type=page --post_status=draft --post_title="iato-c0-snapshot-$1" --post_name="iato-c0-snapshot-$1" --post_content='<p>seed</p>' --porcelain | tail -1; }
mk_elem()  { W wp eval "\$src = 10; \$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'iato-c0-snapshot-elem-$1','post_name'=>'iato-c0-snapshot-elem-$1']); update_post_meta(\$id,'_elementor_data', wp_slash(get_post_meta(\$src,'_elementor_data',true))); update_post_meta(\$id,'_elementor_edit_mode','builder'); update_post_meta(\$id,'_elementor_template_type','wp-page'); echo \$id;" | tail -1; }
P1=$(mk_plain "$LABEL-b"); P2=$(mk_plain "$LABEL-a"); E1=$(mk_elem "$LABEL-b"); E2=$(mk_elem "$LABEL-a")
echo "scratch: P=$P1,$P2 E=$E1,$E2"
python3 -I /Users/garennebigby/Projects/mcp-wordpress/tests/wp-env/tools-snapshot.py --label "$LABEL" --bearer "$KEY" --app-user admin --app-pw "$APPPW" --scratch-post "$P1" "$P2" --scratch-elementor "$E1" "$E2" --out "$OUT"
rc=$?
W wp post delete "$P1" "$P2" "$E1" "$E2" $(cat "$OUT/$LABEL-created-ids.txt" 2>/dev/null) --force >/dev/null
# authors of scratch posts are captured inside the snapshot (get_post.author) before deletion
exit $rc

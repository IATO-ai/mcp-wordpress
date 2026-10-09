#!/usr/bin/env python3
"""
Live checks for Part 2 (Elementor data sanitising), driven by
tests/wp-env/elementor-sanitize-live.sh (env: BASE, KEY, PW_ADMIN, PREFIX,
FIXTURE = JSON from create-sanitize-fixture.php). The test mu-plugin's two
option switches force Elementor's save to throw and let the
iato_mcp_allow_unfiltered_html filter return true. Exit 1 on any failure.
"""
import base64, json, os, re, subprocess, sys, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
KEY = os.environ['KEY']
PW_ADMIN = os.environ['PW_ADMIN']
PW_AUTHOR = os.environ.get('PW_AUTHOR', '')
FXA = json.loads(os.environ['FIXTURE_AUTHOR']) if os.environ.get('FIXTURE_AUTHOR') else {}
PAGE_AUTHOR = int(FXA.get('page_id', 0))
PREFIX = os.environ.get('PREFIX', 'wp_')
FX = json.loads(os.environ['FIXTURE'])
PAGE = int(FX['page_id'])
IDS = FX['ids']
PAYLOAD = '<b>Hi</b><script>alert(1)</script><span onclick="alert(1)">x</span>'
passes, fails = 0, []


def record(name, ok, detail=None):
    global passes
    if ok:
        passes += 1; print(f'  PASS  {name}')
    else:
        fails.append(name); print(f'  FAIL  {name}: {json.dumps(detail, default=str)[:600]}')


def info(name, detail):
    print(f'  INFO  {name}: {json.dumps(detail, default=str)[:400]}')


def wp(*args):
    r = subprocess.run(['npx', '@wordpress/env', 'run', 'cli', 'wp', *args], capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def set_opt(name, value):
    wp('option', 'update', name, value)


def del_opt(name):
    wp('option', 'delete', name)


def auth(who):
    if who == 'key':
        return 'Bearer ' + KEY
    if who == 'author':
        return 'Basic ' + base64.b64encode(f'author:{PW_AUTHOR}'.encode()).decode()
    return 'Basic ' + base64.b64encode(f'admin:{PW_ADMIN}'.encode()).decode()


def opt(name):
    return wp('option', 'get', name)


def cap_probe():
    """(during Elementor's save, at request shutdown) as recorded by the test mu-plugin; '' = not recorded."""
    return (opt('iato_mcp_test_cap_during_save'), opt('iato_mcp_test_cap_at_shutdown'))


def clear_probe():
    wp('option', 'delete', 'iato_mcp_test_cap_during_save')
    wp('option', 'delete', 'iato_mcp_test_cap_at_shutdown')


def meta_text_of(page):
    return wp('post', 'meta', 'get', str(page), '_elementor_data')


def others_unchanged(before_tree, after_tree, edited_id):
    """Settings of every widget except edited_id, compared as JSON text (byte for byte)."""
    def flat(tree, out):
        for el in tree:
            out[el.get('id')] = json.dumps(el.get('settings', {}), sort_keys=True)
            flat(el.get('elements', []), out)
    a, b = {}, {}
    flat(before_tree, a); flat(after_tree, b)
    return {k: (a.get(k), b.get(k)) for k in set(a) | set(b) if k != edited_id and a.get(k) != b.get(k)}


def call(who, tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST', headers={'Content-Type': 'application/json', 'Authorization': auth(who)})
    try:
        raw = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    try:
        r = json.loads(raw)
    except ValueError:
        return {'_raw': raw[:300].decode(errors='replace')}
    if 'error' in r:
        return {'error': r['error']}
    res = r.get('result', {}); text = res.get('content', [{}])[0].get('text', '')
    if res.get('isError'):
        return {'isError': True, 'text': text}
    try:
        return json.loads(text)
    except ValueError:
        return {'text': text}


def meta():
    """Stored _elementor_data as a list (handles both string and array storage)."""
    raw = wp('post', 'meta', 'get', str(PAGE), '_elementor_data', '--format=json')
    v = json.loads(raw)
    if isinstance(v, str):
        v = json.loads(v)
    return v


def meta_text():
    return wp('post', 'meta', 'get', str(PAGE), '_elementor_data')


def widget(tree, wid):
    for el in tree:
        if el.get('id') == wid:
            return el
        found = widget(el.get('elements', []), wid)
        if found:
            return found
    return None


def setting(wid, key):
    w = widget(meta(), wid)
    return (w or {}).get('settings', {}).get(key)


def frontend():
    try:
        return urllib.request.urlopen(f'{BASE}/?page_id={PAGE}', timeout=60).read().decode(errors='replace')
    except urllib.error.HTTPError as e:
        return e.read().decode(errors='replace')


H, HTML, ED, BTN = IDS['heading'], IDS['html'], IDS['editor'], IDS['button']
record('fixture: human-authored iframe and script are stored', FX.get('iframe_stored') and FX.get('script_stored') and FX.get('elementor_save') is True, FX)
PRISTINE = meta_text()
PRISTINE_TREE = json.loads(PRISTINE)

# ── A. the generic meta tool ─────────────────────────────────────────────
print('# A. update_post_meta and Elementor keys')
for who in ('key', 'admin'):
    for k in ('_elementor_data', '_elementor_element_cache', '_elementor_css', '_elementor_anything_new'):
        r = call(who, 'update_post_meta', {'id': PAGE, 'key': k, 'value': [{'id': 'x', 'settings': {'title': PAYLOAD}}], 'force': True})
        record(f'{who}: {k} refused with elementor_content_key', r.get('isError') and 'update_elementor_data' in r.get('text', ''), r)
record('meta unchanged after the refusals', meta_text() == PRISTINE)
r = call('key', 'update_post_meta', {'id': PAGE, 'key': '_elementor_edit_mode', 'value': 'builder'})
record('_elementor_edit_mode still writable', not r.get('isError') and r.get('after_value') == 'builder', r)
r = call('key', 'update_post_meta', {'id': PAGE, 'key': '_elementor_page_settings', 'value': {'hide_title': 'yes', 'custom_css': 'h1 > b { color: red } </style><script>alert(1)</script>', 'note': '<i>x</i><script>y</script>', 'plain': 'a & b'}})
ps = json.loads(wp('post', 'meta', 'get', str(PAGE), '_elementor_page_settings', '--format=json') or '{}')
record('_elementor_page_settings: custom_css keeps > and loses </style><script>', '>' in ps.get('custom_css', '') and '</style' not in ps.get('custom_css', '') and '<script' not in ps.get('custom_css', ''), ps)
record('_elementor_page_settings: note loses the script, plain keeps the &', '<script' not in ps.get('note', '') and ps.get('plain') == 'a & b', ps)
record('_elementor_page_settings: sanitized paths reported', r.get('sanitized') is True and any(p.endswith('custom_css') for p in r.get('sanitized_paths', [])), r)
PS_RECEIPT = (r.get('change_receipt') or {}).get('change_id')
r = call('key', 'update_post_meta', {'id': PAGE, 'key': '_elementor_page_settings', 'value': {'hide_title': 'no'}})
PS_RECEIPT2 = (r.get('change_receipt') or {}).get('change_id')
r = call('key', 'update_elementor_data', {'id': PAGE, 'elementor_data': PRISTINE, 'inherit_settings_from': PAGE, 'inherit_keys': ['_elementor_data'], 'dry_run': True})
record('inherit_keys with _elementor_data refused', r.get('isError') and 'elementor_content_key' in json.dumps(r) or 'Elementor builder data' in r.get('text', ''), r)

# ── B. the fallback path: Elementor's save forced to throw ─────────────────
print('# B. fallback path (Elementor save throws)')
set_opt('iato_mcp_test_force_save_fail', '1')
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD, 'dry_run': True})
record('dry run reports what would be sanitised', r.get('dry_run') and r.get('sanitized') is True and r.get('sanitized_paths') == [f'{H}/settings/title'], r)
record('dry run wrote nothing', meta_text() == PRISTINE)
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
record('fallback: elementor_save error, stored by plugin, sanitized path', r.get('elementor_save') == 'error' and r.get('stored_by') == 'plugin' and r.get('sanitized') is True and r.get('sanitized_paths') == [f'{H}/settings/title'], r)
t = setting(H, 'title') or ''
record('fallback: script tag and event handler stripped, <b> kept', '<script' not in t and 'onclick' not in t and '<b>Hi</b>' in t, t)
h = widget(meta(), HTML)['settings']['html']
record('fallback: untouched HTML widget keeps its iframe and script', '<iframe' in h and '<script>var humanAuthored = 1;</script>' in h, h)
fe = frontend()
record('fallback: frontend carries no script tag or event handler from the payload', '<script>alert(1)' not in fe and 'onclick="alert(1)"' not in fe, [l for l in fe.splitlines() if 'alert(1)' in l][:3])
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': 'Tom & Jerry'})
record('& in a heading comes back unchanged and is not reported', setting(H, 'title') == 'Tom & Jerry' and 'sanitized' not in r, (setting(H, 'title'), r))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'link', 'value': {'url': 'https://example.com/?a=1&b=2&c=3', 'is_external': '', 'nofollow': ''}})
record('& in a link URL comes back unchanged', (setting(BTN, 'link') or {}).get('url') == 'https://example.com/?a=1&b=2&c=3' and 'sanitized' not in r, (setting(BTN, 'link'), r))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'link', 'value': {'url': 'javascript:alert(1)', 'is_external': '', 'nofollow': ''}})
record('javascript: URL removed and reported', (setting(BTN, 'link') or {}).get('url') == '' and r.get('sanitized_paths') == [f'{BTN}/settings/link/url'], (setting(BTN, 'link'), r))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'custom_css', 'value': 'selector h1 > b { color: red }'})
record('custom_css with a > selector survives untouched', setting(H, 'custom_css') == 'selector h1 > b { color: red }' and 'sanitized' not in r, (setting(H, 'custom_css'), r))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'custom_css', 'value': 'selector h1 > b { } </style><script>alert(1)</script>'})
c = setting(H, 'custom_css') or ''
record('custom_css loses </style><script> and keeps the selector', '</style' not in c and '<script' not in c and 'h1 > b' in c and r.get('sanitized') is True, (c, r))
full = meta()
clear_probe()
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'text', 'value': 'throw probe'})
during, after_ = cap_probe()
record('fallback (save throws): lock lifted during the save, restored by request end', r.get('elementor_save') == 'error' and during == '1' and after_ == '0', (r.get('elementor_save'), during, after_))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'custom_css', 'value': 'selector h1 { width: 50% }'})
record('custom_css: width: 50% survives sanitize_textarea_field', setting(H, 'custom_css') == 'selector h1 { width: 50% }' and 'sanitized' not in r, (setting(H, 'custom_css'), r))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'custom_css', 'value': 'selector h1 { background: url(data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27%3E%3C/svg%3E) }'})
info('custom_css: a data URI containing %3C after sanitize_textarea_field (Elementor applies the same function)', {'stored': setting(H, 'custom_css'), 'reported': r.get('sanitized_paths')})
full = meta()
widget(full, H)['settings']['title'] = '<em>Full</em><script>x</script>'
r = call('key', 'update_elementor_data', {'id': PAGE, 'elementor_data': json.dumps(full)})
record('update_elementor_data: full tree through the pipeline, only the changed value sanitised', r.get('stored_by') == 'plugin' and r.get('sanitized_paths') == [f'{H}/settings/title'] and (r.get('change_receipt') or {}).get('target_type') == 'elementor_widget', r)
t = setting(H, 'title') or ''
h = widget(meta(), HTML)['settings']['html']
record('update_elementor_data: script gone from the heading, iframe still in the HTML widget', '<script' not in t and '<em>Full</em>' in t and '<iframe' in h, (t, h[:80]))
r = call('key', 'update_elementor_patch', {'id': PAGE, 'ops': [{'op': 'replace', 'path': f'/0/elements/0/settings/title', 'value': 'Patched <u>u</u><script>p</script>'}]})
record('update_elementor_patch reports sanitising', r.get('sanitized') is True and '<script' not in (setting(H, 'title') or ''), r)
r = call('key', 'update_elementor_widgets_bulk', {'updates': [{'id': PAGE, 'widget_id': H, 'settings_patch': {'title': 'Bulk <script>b</script>'}}]})
res0 = (r.get('results') or [{}])[0]
record('update_elementor_widgets_bulk reports sanitising per row', res0.get('sanitized') is True and res0.get('stored_by') == 'plugin', r)
r = call('key', 'set_heading_level', {'id': PAGE, 'widget_id': H, 'level': 'h3'})
record('set_heading_level works on the fallback path without sanitising', not r.get('isError') and 'sanitized' not in r and setting(H, 'header_size') == 'h3', r)
wp('plugin', 'deactivate', 'elementor')
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': '<u>off</u><script>z</script>'})
record('Elementor absent: fallback sanitises', r.get('elementor_save') is None and r.get('stored_by') == 'plugin' and r.get('sanitized') is True and '<script' not in (setting(H, 'title') or ''), r)
wp('plugin', 'activate', 'elementor')

# ── C. Application Password administrator ─────────────────────────────────
print('# C. Application Password administrator')
r = call('admin', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
record('admin App Password, fallback: payload stripped by default', r.get('sanitized') is True and '<script' not in (setting(H, 'title') or ''), r)
set_opt('iato_mcp_test_allow_unfiltered', '1')
r = call('admin', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD + '<!--raw-->'})
record('admin App Password with the filter: raw kept on the fallback path', 'sanitized' not in r and '<script>alert(1)</script>' in (setting(H, 'title') or ''), (r, setting(H, 'title')))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD + '<!--key-->'})
record('the filter does not apply to the site key', r.get('sanitized') is True and '<script' not in (setting(H, 'title') or ''), r)
del_opt('iato_mcp_test_allow_unfiltered')

# ── D. rollback stays verbatim ─────────────────────────────────────────────
print('# D. rollback')
# An HTML-widget change through the generic tool cannot be made (content key refused), so the
# receipt comes from a page-settings write; the byte-for-byte HTML widget check uses a receipt
# row holding the human page, on both paths (fallback here, success path in section E).
set_opt('iato_mcp_test_force_save_fail', '1')
cid_fb = 'wr_' + os.urandom(8).hex()
wp('db', 'query', f"INSERT INTO {PREFIX}iato_change_receipts (change_id, post_id, target_type, field, before_value, after_value, applied_at) VALUES ('{cid_fb}', {PAGE}, 'post_meta', '_elementor_data', '{PRISTINE.replace(chr(92), chr(92)*2).replace(chr(39), chr(39)*2)}', 'sanitize-live-seed', NOW())")
call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': 'before rollback (fallback)'})
r = call('key', 'rollback', {'change_id': cid_fb})
record('fallback path: rollback restores the human page byte for byte, iframe and script included', not r.get('isError') and meta_text() == PRISTINE, (r, meta_text()[:120]))
del_opt('iato_mcp_test_force_save_fail')
r = call('key', 'rollback', {'change_id': PS_RECEIPT2})
ps = json.loads(wp('post', 'meta', 'get', str(PAGE), '_elementor_page_settings', '--format=json') or '{}')
record('rollback of a page-settings receipt restores the stored before value', not r.get('isError') and ps.get('hide_title') == 'yes' and '>' in ps.get('custom_css', ''), (r, ps))
r = call('key', 'rollback', {'change_id': PS_RECEIPT2})
record('a rollback cannot be rolled back again', r.get('isError') and 'already' in r.get('text', '').lower(), r)
# An old receipt for a key update_post_meta now refuses: before_value holds the human page (with the iframe).
cid = 'wr_' + os.urandom(8).hex()
before_sql = PRISTINE.replace('\\', '\\\\').replace("'", "''")
wp('db', 'query', f"INSERT INTO {PREFIX}iato_change_receipts (change_id, post_id, target_type, field, before_value, after_value, applied_at) VALUES ('{cid}', {PAGE}, 'post_meta', '_elementor_data', '{before_sql}', 'sanitize-live-seed', NOW())")
r = call('key', 'rollback', {'change_id': cid})
record('old post_meta receipt for _elementor_data still rolls back', not r.get('isError'), r)
record('and restores the before value verbatim, iframe and script included', meta_text() == PRISTINE, (meta_text()[:120], PRISTINE[:120]))

# ── E. the success path: Elementor's own save, with the kses lock ─────────
print('# E. success path (Elementor saves)')
del_opt('iato_mcp_test_force_save_fail')
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
t = setting(H, 'title') or ''
record('success path: Elementor stored it, our pass reported the change', r.get('elementor_save') is True and r.get('stored_by') == 'elementor' and r.get('sanitized') is True, r)
record('success path: script tag and event handler stripped (Elementor honours the kses lock)', '<script' not in t and 'onclick' not in t, t)
fe = frontend()
record('success path: frontend carries no script tag or event handler from the payload', '<script>alert(1)' not in fe and 'onclick="alert(1)"' not in fe, [l for l in fe.splitlines() if 'alert(1)' in l][:3])
h = widget(meta(), HTML)['settings']['html']
ed = widget(meta(), ED)['settings']['editor']
record('success path: untouched HTML widget keeps its iframe and script byte for byte', h == widget(PRISTINE_TREE, HTML)['settings']['html'], h[:160])
record("success path: untouched text-editor unchanged", ed == widget(PRISTINE_TREE, ED)['settings']['editor'], ed)
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': 'Tom & Jerry'})
record('success path: Tom & Jerry stays unencoded and is not reported', setting(H, 'title') == 'Tom & Jerry' and 'sanitized' not in r, (setting(H, 'title'), r))
during, after_ = cap_probe()
record('success path: lock lifted only during the save (admin holds the capability then), restored by request end', during == '1' and after_ == '0', (during, after_))
# rollback restores the HTML widget byte for byte on the success path too
cid_ok = 'wr_' + os.urandom(8).hex()
wp('db', 'query', f"INSERT INTO {PREFIX}iato_change_receipts (change_id, post_id, target_type, field, before_value, after_value, applied_at) VALUES ('{cid_ok}', {PAGE}, 'post_meta', '_elementor_data', '{PRISTINE.replace(chr(92), chr(92)*2).replace(chr(39), chr(39)*2)}', 'sanitize-live-seed', NOW())")
r = call('key', 'rollback', {'change_id': cid_ok})
record('success path: rollback restores the human page byte for byte, iframe and script included', not r.get('isError') and meta_text() == PRISTINE, (r, meta_text()[:120]))
# reseed state for the Application Password checks that follow
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
set_opt('iato_mcp_test_allow_unfiltered', '1')
r = call('admin', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD + '<!--raw2-->'})
record('admin App Password with the filter: raw kept on the success path too (Elementor sees unfiltered_html)', 'sanitized' not in r and '<script>alert(1)</script>' in (setting(H, 'title') or ''), (r, setting(H, 'title')))
del_opt('iato_mcp_test_allow_unfiltered')

print("# F. an Author's Application Password: the lift never grants unfiltered_html")
if PAGE_AUTHOR and PW_AUTHOR:
    clear_probe()
    r = call('author', 'set_widget_setting', {'id': PAGE_AUTHOR, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
    during, after_ = cap_probe()
    t = (widget(json.loads(meta_text_of(PAGE_AUTHOR)), H) or {}).get('settings', {}).get('title', '')
    record("author: Elementor's own save ran (standard filtering, nothing else)", r.get('elementor_save') is True and r.get('stored_by') == 'elementor', r)
    record('author: payload stripped', '<script' not in t and 'onclick' not in t, t)
    record('author: does not hold unfiltered_html during the save nor at request end', during == '0' and after_ == '0', (during, after_))
    r = call('author', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': 'x'})
    record("author: cannot edit the admin's page (unchanged permission model)", r.get('isError'), r)
else:
    record('author fixture and credential available', False, (PAGE_AUTHOR, bool(PW_AUTHOR)))

print('# G. unbound legacy key (user 0): the 1.12.2 callback guard covers what the lift cannot')
owner_before = opt('iato_mcp_key_owner')
try:
    set_opt('iato_mcp_key_owner', '0')
    del_opt('iato_mcp_test_force_save_fail')
    # reseed the human page so the untouched-widget check is byte for byte
    cid_lg = 'wr_' + os.urandom(8).hex()
    wp('db', 'query', f"INSERT INTO {PREFIX}iato_change_receipts (change_id, post_id, target_type, field, before_value, after_value, applied_at) VALUES ('{cid_lg}', {PAGE}, 'post_meta', '_elementor_data', '{PRISTINE.replace(chr(92), chr(92)*2).replace(chr(39), chr(39)*2)}', 'sanitize-live-seed', NOW())")
    r = call('key', 'rollback', {'change_id': cid_lg})
    record('legacy key: rollback restores the human page byte for byte', not r.get('isError') and meta_text() == PRISTINE, (r, meta_text()[:120]))
    clear_probe()
    r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
    during, after_ = cap_probe()
    record('legacy key: Elementor refuses user 0, the plugin stores the sanitised tree', r.get('elementor_save') is False and r.get('stored_by') == 'plugin' and r.get('sanitized_paths') == [H + '/settings/title'], r)
    t = setting(H, 'title')
    record('legacy key: payload stripped, <b> kept', '<script' not in t and 'onclick' not in t and '<b>Hi</b>' in t, t)
    h = widget(meta(), HTML)['settings']['html']
    record('legacy key: untouched HTML widget keeps its iframe and script byte for byte', h == widget(PRISTINE_TREE, HTML)['settings']['html'], h[:160])
    record('legacy key: untouched text-editor unchanged', widget(meta(), ED)['settings']['editor'] == widget(PRISTINE_TREE, ED)['settings']['editor'], widget(meta(), ED)['settings']['editor'])
    record('legacy key: user 0 never holds unfiltered_html', during == '0' and after_ == '0', (during, after_))
    r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': 'Tom & Jerry'})
    record('legacy key: Tom & Jerry stays unencoded and is not reported', setting(H, 'title') == 'Tom & Jerry' and 'sanitized' not in r, (setting(H, 'title'), r))
    r = call('key', 'update_post_meta', {'id': PAGE, 'key': '_elementor_page_settings', 'value': {'custom_css': 'selector > h1 { color: red }', 'plain': 'a & b'}})
    ps = json.loads(wp('post', 'meta', 'get', str(PAGE), '_elementor_page_settings', '--format=json'))
    record('legacy key: _elementor_page_settings keeps > and & (callback removed for the write)', ps.get('custom_css') == 'selector > h1 { color: red }' and ps.get('plain') == 'a & b', (ps, r))
finally:
    if owner_before:
        set_opt('iato_mcp_key_owner', owner_before)
    else:
        del_opt('iato_mcp_key_owner')
record('key owner restored', opt('iato_mcp_key_owner') == owner_before, (owner_before, opt('iato_mcp_key_owner')))

print(f'\n# {passes} passed, {len(fails)} failed')
if fails:
    print('# failed: ' + '; '.join(fails))
sys.exit(1 if fails else 0)

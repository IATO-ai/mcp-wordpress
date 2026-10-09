#!/usr/bin/env python3
"""
Live checks for 1.12.2, driven by tests/wp-env/elementor-hotfix-live.sh
(env: BASE, KEY, PW_ADMIN, PREFIX, FIXTURE). Checks that edits made with the
site key change only the values they set and that Elementor's own filtering is
in place again after every write. Exit 1 on any failure.
"""
import base64, json, os, subprocess, sys, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
KEY = os.environ['KEY']
PW_ADMIN = os.environ['PW_ADMIN']
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


def wp(*args):
    r = subprocess.run(['npx', '@wordpress/env', 'run', 'cli', 'wp', *args], capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def opt(name):
    return wp('option', 'get', name)


def auth(who):
    return 'Bearer ' + KEY if who == 'key' else 'Basic ' + base64.b64encode(f'admin:{PW_ADMIN}'.encode()).decode()


def call(who, tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST', headers={'Content-Type': 'application/json', 'Authorization': auth(who)})
    try:
        st, raw = 200, urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        st, raw = e.code, e.read()
    try:
        r = json.loads(raw)
    except ValueError:
        return {'_http': st, '_raw': raw[:300].decode(errors='replace')}
    if 'error' in r or st >= 400 or 'code' in r:
        return {'_http': st, 'error': r.get('error') or r}
    res = r.get('result', {}); text = res.get('content', [{}])[0].get('text', '')
    if res.get('isError'):
        return {'isError': True, 'text': text}
    try:
        return json.loads(text)
    except ValueError:
        return {'text': text}


def meta_text():
    return wp('post', 'meta', 'get', str(PAGE), '_elementor_data')


def meta():
    v = json.loads(wp('post', 'meta', 'get', str(PAGE), '_elementor_data', '--format=json'))
    return json.loads(v) if isinstance(v, str) else v


def widget(tree, wid):
    for el in tree:
        if el.get('id') == wid:
            return el
        f = widget(el.get('elements', []), wid)
        if f:
            return f
    return None


def setting(wid, key):
    return (widget(meta(), wid) or {}).get('settings', {}).get(key)


def others_unchanged(before_tree, after_tree, edited_id):
    """Every widget except edited_id has identical settings, byte for byte."""
    def flat(tree, out):
        for el in tree:
            out[el.get('id')] = json.dumps(el.get('settings', {}), sort_keys=True)
            flat(el.get('elements', []), out)
    a, b = {}, {}
    flat(before_tree, a); flat(after_tree, b)
    diff = {k: (a.get(k), b.get(k)) for k in set(a) | set(b) if k != edited_id and a.get(k) != b.get(k)}
    return diff


def reseed():
    global PRISTINE_TREE, PRISTINE
    fx = json.loads(wp('eval-file', 'wp-content/plugins/mcp-wordpress/tests/wp-env/create-sanitize-fixture.php', '--user=admin').splitlines()[-1])
    assert fx['page_id'] == PAGE or True
    # The seeder recreates the page; keep PAGE current.
    globals()['PAGE'] = int(fx['page_id'])
    wp('eval', '--user=admin', f"$m = get_post_meta({PAGE}, '_elementor_data', true); update_post_meta({PAGE}, '_elementor_data', wp_slash(str_replace('Human heading', 'Tom & Jerry', $m))); echo 'ok';")
    PRISTINE_TREE = meta(); PRISTINE = meta_text()


H, HTML, ED, BTN = IDS['heading'], IDS['html'], IDS['editor'], IDS['button']
record('fixture: human-authored iframe and script stored through Elementor', FX.get('iframe_stored') and FX.get('script_stored'), FX)
reseed()
record('fixture: heading holds a raw &', setting(H, 'title') == 'Tom & Jerry')

print('# 1. site key: an edit to the button leaves everything else byte for byte')
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'text', 'value': 'Edited by bearer'})
after = meta()
record('edit applied', setting(BTN, 'text') == 'Edited by bearer', r)
record('no sanitising reported for a plain edit', 'sanitized' not in r, r)
diff = others_unchanged(PRISTINE_TREE, after, BTN)
record('every other widget identical byte for byte (iframe, script, Tom & Jerry)', diff == {}, diff)
h = widget(after, HTML)['settings']['html']
record('iframe and script present in the HTML widget', '<iframe src="https://maps.example.com/embed?q=b2l&z=12"' in h and '<script>var humanAuthored = 1;</script>' in h, h)
record('Tom & Jerry unencoded', setting(H, 'title') == 'Tom & Jerry')
record("Elementor's meta callbacks registered again after the write", int(opt('iato_mcp_test_callbacks_at_shutdown') or 0) >= 2, opt('iato_mcp_test_callbacks_at_shutdown'))

print('# 2. site key: the changed leaf is sanitised')
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
t = setting(H, 'title') or ''
record('script tag and event handler stripped, <b> kept, path reported', '<script' not in t and 'onclick' not in t and '<b>Hi</b>' in t and r.get('sanitized_paths') == [f'{H}/settings/title'], (t, r))
record('HTML widget still intact after that', '<iframe' in widget(meta(), HTML)['settings']['html'])
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'link', 'value': {'url': 'javascript:alert(1)', 'is_external': '', 'nofollow': ''}})
record('javascript: URL removed', (setting(BTN, 'link') or {}).get('url') == '' and r.get('sanitized_paths') == [f'{BTN}/settings/link/url'], (setting(BTN, 'link'), r))
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'link', 'value': {'url': 'https://example.com/?a=1&b=2&c=3', 'is_external': '', 'nofollow': ''}})
record('& in a link URL unchanged', (setting(BTN, 'link') or {}).get('url') == 'https://example.com/?a=1&b=2&c=3' and 'sanitized' not in r, (setting(BTN, 'link'), r))

print('# 3. update_elementor_data full-tree write')
reseed()
full = meta()
widget(full, H)['settings']['title'] = PAYLOAD
widget(full, BTN)['settings']['text'] = 'Full edit'
r = call('key', 'update_elementor_data', {'id': PAGE, 'elementor_data': json.dumps(full)})
after = meta()
record('full tree: changed heading sanitised, path reported, success', r.get('success') and r.get('sanitized_paths') == [f'{H}/settings/title'] and '<script' not in (setting(H, 'title') or ''), r)
diff = others_unchanged(PRISTINE_TREE, after, H)
diff.pop(BTN, None)
record('full tree: untouched widgets byte for byte (iframe, script, &)', diff == {} and '<iframe' in widget(after, HTML)['settings']['html'] and widget(after, ED)['settings']['editor'] == widget(PRISTINE_TREE, ED)['settings']['editor'], diff)

print('# 3b. shapes outside settings, non-list input, non-canonical base64')
r = call('key', 'update_elementor_data', {'id': PAGE, 'elementor_data': json.dumps('<script>alert(1)</script>')})
record('a JSON string literal is refused as elementor_data', r.get('isError') and 'array of elements' in r.get('text', ''), r)
r = call('key', 'update_elementor_patch', {'id': PAGE, 'ops': [{'op': 'add', 'path': f'/0/elements/0/htmlCache', 'value': '<script>cache</script>'}]})
w = widget(meta(), H) or {}
record('a key outside settings on an element is sanitised too', r.get('sanitized') is True and '<script' not in str(w.get('htmlCache', '')) and f'{H}/htmlCache' in r.get('sanitized_paths', []), (r, w.get('htmlCache')))
css = 'a{} </style><script>alert(1)</script>'
unpadded = base64.b64encode(css.encode()).decode().rstrip('=')
r = call('key', 'update_elementor_patch', {'id': PAGE, 'ops': [{'op': 'add', 'path': f'/0/elements/0/styles', 'value': {'s1': {'id': 's1', 'variants': [{'props': {}, 'custom_css': {'raw': unpadded}}]}}}]})
stored_raw = ((widget(meta(), H) or {}).get('styles', {}).get('s1', {}).get('variants', [{}])[0].get('custom_css') or {}).get('raw', '')
decoded_css = base64.b64decode(stored_raw + '=' * (-len(stored_raw) % 4)).decode(errors='replace') if stored_raw else ''
record('non-canonical base64 custom_css.raw is decoded, sanitised and stored canonically', r.get('sanitized') is True and stored_raw and stored_raw == base64.b64encode(decoded_css.encode()).decode() and '</style' not in decoded_css and '<script' not in decoded_css and 'a{}' in decoded_css, (r, stored_raw, decoded_css))

print('# 4. rollback restores the recorded value verbatim')
reseed()
r = call('key', 'update_post_meta', {'id': PAGE, 'key': '_elementor_data', 'value': [{'id': 'zz', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': 'replaced <script>q</script>'}, 'elements': []}]})
cid = (r.get('change_receipt') or {}).get('change_id')
stored_now = meta_text()
record('other meta writes keep Elementor filtering as before', cid and '<script' not in stored_now and 'replaced' in stored_now, (r, stored_now[:200]))
r = call('key', 'rollback', {'change_id': cid})
record('rollback succeeds', not r.get('isError') and r.get('success'), r)
record('rollback restored the original tree byte for byte, iframe included, as a string', meta_text() == PRISTINE, (meta_text()[:160], PRISTINE[:160]))
record("callbacks registered again after the rollback", int(opt('iato_mcp_test_callbacks_at_shutdown') or 0) >= 2)

print('# 5. a forced throw inside Elementor save still restores the callbacks')
wp('option', 'update', 'iato_mcp_test_force_save_fail', '1')
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'text', 'value': 'throw test'})
record('the throw surfaces as an error (1.12.x has no guard around the save)', r.get('_http', 200) >= 500 and 'error' in r, r)
record('the sanitised write before the save had happened', setting(BTN, 'text') == 'throw test')
record("callbacks registered again after the throw", int(opt('iato_mcp_test_callbacks_at_shutdown') or 0) >= 2, opt('iato_mcp_test_callbacks_at_shutdown'))
wp('option', 'delete', 'iato_mcp_test_force_save_fail')
r = call('key', 'update_post_meta', {'id': PAGE, 'key': '_elementor_data', 'value': [{'id': 'zz', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': 'after throw <script>q</script>'}, 'elements': []}]})
record('Elementor filtering is back after the throw', '<script' not in meta_text() and 'after throw' in meta_text(), meta_text()[:160])
call('key', 'rollback', {'change_id': (r.get('change_receipt') or {}).get('change_id')})

print("# 6. Elementor's callback absent: changed leaves are still sanitised by the plugin")
wp('option', 'update', 'iato_mcp_test_drop_elementor_callback', '1')
r = call('key', 'update_post_meta', {'id': PAGE, 'key': '_elementor_data', 'value': [{'id': 'zz', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': 'no callback <script>q</script>'}, 'elements': []}]})
record('control: the test plugin really removed Elementor filtering for this request', '<script' in meta_text(), meta_text()[:160])
call('key', 'rollback', {'change_id': (r.get('change_receipt') or {}).get('change_id')})
r = call('key', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
record("plugin's pass sanitises the changed leaf without Elementor's callback", r.get('sanitized') is True and '<script' not in (setting(H, 'title') or ''), r)
record('and leaves the HTML widget alone', '<iframe' in widget(meta(), HTML)['settings']['html'])
wp('option', 'delete', 'iato_mcp_test_drop_elementor_callback')

print('# 7. Application Password administrator: identical to 1.12.1')
reseed()
r = call('admin', 'set_widget_setting', {'id': PAGE, 'widget_id': BTN, 'key': 'text', 'value': 'Edited by admin'})
after = meta()
record('admin edit applied, nothing reported', setting(BTN, 'text') == 'Edited by admin' and 'sanitized' not in r, r)
record('admin: other widgets byte for byte', others_unchanged(PRISTINE_TREE, after, BTN) == {})
r = call('admin', 'set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': PAYLOAD})
record('admin: payload kept raw in the changed leaf (unfiltered_html, as in 1.12.1)', 'sanitized' not in r and setting(H, 'title') == PAYLOAD, (r, setting(H, 'title')))

print(f'\n# {passes} passed, {len(fails)} failed')
if fails:
    print('# failed: ' + '; '.join(fails))
sys.exit(1 if fails else 0)

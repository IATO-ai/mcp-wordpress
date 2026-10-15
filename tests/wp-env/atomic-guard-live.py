#!/usr/bin/env python3
"""
Live checks for 1.13.1, driven by tests/wp-env/atomic-guard-live.sh
(env: BASE, SVC = cli | tests-cli, KEY, OWNER, ATOMIC_SRC, MIXED_SRC).

Every check runs on a scratch copy of a Sprint A fixture page (published),
through the real MCP endpoint with the bound site key:
  A  plain values on atomic elements are refused and nothing is written
     (the corrupting rows of the Sprint C Step 0 table, plus null, the
     classic header_size key, dry runs, patch forms, bulk, the unbound key)
  B  typed values and patch paths inside an envelope still work
  C  no fallback write when Elementor rejects an atomic element's settings;
     the fallback stays for any other Throwable (test mu-plugin switch)
  D  the read tools flag elements stored without their envelope, with and
     without Elementor's registered schema; valid pages carry no flag
  E  repair: sending a correct typed value for each damaged key stores a
     valid element, renders the link / image again and lets Elementor save
     the page; a page with several damaged elements is repaired one at a time
Exit 1 on any failure.
"""
import json, os, re, subprocess, sys, time, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
SVC = os.environ.get('SVC', 'cli')
KEY = os.environ['KEY']
OWNER = os.environ['OWNER']
ATOMIC_SRC = int(os.environ['ATOMIC_SRC'])
MIXED_SRC = int(os.environ['MIXED_SRC'])
CLASSIC_SRC = int(os.environ['CLASSIC_SRC'])
MSG = BASE + '/wp-json/iato-mcp/v1/message'
STAMP = str(int(time.time()))
EXT = 'https://example.com/photos/external.jpg'
passes, fails = 0, []
counter = [0]


def record(name, ok, detail=None):
    global passes
    if ok:
        passes += 1
        print(f'  PASS  {name}')
    else:
        fails.append(name)
        print(f'  FAIL  {name}: {json.dumps(detail, default=str, ensure_ascii=False)[:700]}')


def wp(*args, user='admin'):
    cmd = ['npx', '@wordpress/env', 'run', SVC, 'wp', *args]
    if user:
        cmd.append(f'--user={user}')
    r = subprocess.run(cmd, capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def wp_json(php):
    out = wp('eval', php)
    try:
        return json.loads(out.splitlines()[-1])
    except (ValueError, IndexError):
        return {'_raw': out[-500:]}


def call(tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST',
                                 headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + KEY})
    try:
        raw = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    try:
        r = json.loads(raw)
    except ValueError:
        return {'_raw': raw[:300].decode(errors='replace')}
    if 'error' in r:
        return {'rpc_error': r['error']}
    res = r.get('result', {})
    text = res.get('content', [{}])[0].get('text', '')
    try:
        payload = json.loads(text)
    except ValueError:
        payload = {'text': text}
    if res.get('isError'):
        payload = payload if isinstance(payload, dict) else {'text': text}
        payload['_isError'] = True
    return payload


def env(t, v):
    return {'$$type': t, 'value': v}


# ── page helpers ───────────────────────────────────────────────────────────

SEED_PHP = r"""
$src = %(src)d;
$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'atomic-guard-live-%(label)s-%(stamp)s','post_name'=>'atomic-guard-live-%(label)s-%(stamp)s']);
$tree = json_decode(get_post_meta($src,'_elementor_data',true), true);
$damage = json_decode('%(damage)s', true);
$apply = function(array &$els) use (&$apply, $damage) { foreach ($els as &$e) { if (isset($damage[$e['id'] ?? ''])) { foreach ($damage[$e['id']] as $k => $v) { $e['settings'][$k] = $v; } } if (!empty($e['elements'])) { $apply($e['elements']); } } };
$apply($tree);
update_post_meta($id,'_elementor_data', wp_slash(wp_json_encode($tree)));
update_post_meta($id,'_elementor_edit_mode','builder');
update_post_meta($id,'_elementor_template_type','wp-page');
update_post_meta($id,'_elementor_version', get_post_meta($src,'_elementor_version',true));
echo $id;
"""


def seed(src, damage=None):
    """Scratch copy of a fixture page; `damage` = {element_id: {key: value stored as IATO 1.13.0 did}}."""
    counter[0] += 1
    php = SEED_PHP % {'src': src, 'label': str(counter[0]), 'stamp': STAMP,
                      'damage': json.dumps(damage or {}).replace('\\', '\\\\').replace("'", "\\'")}
    return int(wp('eval', php).splitlines()[-1])


def meta(pid):
    return wp('post', 'meta', 'get', str(pid), '_elementor_data', user=None)


def node(raw, wid):
    def find(els):
        for e in els or []:
            if e.get('id') == wid:
                return e
            r = find(e.get('elements'))
            if r:
                return r
        return None
    try:
        return find(json.loads(raw))
    except ValueError:
        return None


INSPECT_PHP = r"""
$tree = json_decode(get_post_meta(%(pid)d,'_elementor_data',true), true);
$out = [];
$walk = function($els) use (&$walk, &$out) { foreach ((array) $els as $e) {
  if (str_starts_with((string) ($e['widgetType'] ?? $e['elType'] ?? ''), 'e-')) {
    $n = $e; $n['elements'] = [];
    $el = \Elementor\Plugin::$instance->elements_manager->create_element_instance($n);
    try { $el->get_data_for_save(); $out[$e['id']] = 'ok'; } catch (\Throwable $t) { $out[$e['id']] = $t->getMessage(); }
  }
  if (!empty($e['elements'])) $walk($e['elements']); } };
$walk($tree);
echo wp_json_encode($out);
"""


def elementor_validation(pid):
    """Per atomic element: what Elementor's own save transform says about the stored settings."""
    return wp_json(INSPECT_PHP % {'pid': pid})


EDITOR_SAVE_PHP = r"""
$d = \Elementor\Plugin::$instance->documents->get(%(pid)d, false);
try { $ed = $d->get_elements_raw_data(null, true); $r = $d->save(['elements' => $ed]); echo wp_json_encode(['save' => $r ? 'ok' : 'refused']); }
catch (\Throwable $t) { echo wp_json_encode(['save' => 'THROWS ' . $t->getMessage()]); }
"""


def editor_save(pid):
    """Load the page the way Elementor's editor does and save it again with Elementor ("Update")."""
    return wp_json(EDITOR_SAVE_PHP % {'pid': pid}).get('save')


def front(pid, wid):
    try:
        with urllib.request.urlopen(f'{BASE}/?page_id={pid}', timeout=60) as r:
            html = r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        html = e.read().decode('utf-8', 'replace')
    m = re.search(r'<(\w+)[^>]*data-(?:interaction-)?id="' + re.escape(wid) + r'"[^>]*>', html)
    if not m:
        return {'present': False}
    return {'present': True, 'tag': m.group(1), 'html': re.sub(r'\s+', ' ', html[m.start():m.start() + 500])}


def is_refusal(r, code='atomic_plain_value_unsupported'):
    return isinstance(r, dict) and r.get('_isError') and r.get('error') == code


# ── A. plain values on atomic elements are refused, nothing written ─────────

print('A. Plain values on atomic elements are refused')
P = {'a7e4d01': '/0/elements/0', 'a7e4d02': '/0/elements/1', 'a7e4d03': '/0/elements/2',
     'a7e4d05': '/0/elements/4', 'a7e4d06': '/0/elements/5'}
refusals = [
    ('R2 set_widget_setting tag=h3 (stored tag)', 'set_widget_setting', lambda p: {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'}),
    ('R3 set_widget_setting tag=h3 (default tag)', 'set_widget_setting', lambda p: {'id': p, 'widget_id': 'a7e4d02', 'key': 'tag', 'value': 'h3'}),
    ('R4 update_elementor_widget title plain', 'update_elementor_widget', lambda p: {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'title': 'Tom & Jerry'}}),
    ('R5 update_elementor_widget paragraph plain', 'update_elementor_widget', lambda p: {'id': p, 'widget_id': 'a7e4d03', 'settings_patch': {'paragraph': 'New paragraph'}}),
    ('R6 update_elementor_widget button text plain', 'update_elementor_widget', lambda p: {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'text': 'Go now'}}),
    ('R7 update_elementor_widget link {destination} plain', 'update_elementor_widget', lambda p: {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'link': {'destination': 'https://example.com/new'}}}),
    ('R8 update_elementor_widget external image plain', 'update_elementor_widget', lambda p: {'id': p, 'widget_id': 'a7e4d05', 'settings_patch': {'image': {'src': {'url': EXT, 'alt': 'New alt'}}}}),
    ('R9 set_widget_setting header_size=h3 (classic key)', 'set_widget_setting', lambda p: {'id': p, 'widget_id': 'a7e4d01', 'key': 'header_size', 'value': 'h3'}),
    ('R9b header_size sent as an envelope', 'update_elementor_widget', lambda p: {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'header_size': env('string', 'h3')}}),
    ('null removal', 'set_widget_setting', lambda p: {'id': p, 'widget_id': 'a7e4d01', 'key': 'title', 'value': None}),
    ('dry run is refused too', 'set_widget_setting', lambda p: {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3', 'dry_run': True}),
    ('R14 update_elementor_patch replace …/settings/tag plain', 'update_elementor_patch', lambda p: {'id': p, 'ops': [{'op': 'replace', 'path': P['a7e4d01'] + '/settings/tag', 'value': 'h3'}]}),
    ('patch: whole settings object plain', 'update_elementor_patch', lambda p: {'id': p, 'ops': [{'op': 'replace', 'path': P['a7e4d06'] + '/settings', 'value': {'text': 'Go', 'link': {'destination': 'https://x.test/'}}}]}),
    ('patch: new atomic element with plain settings', 'update_elementor_patch', lambda p: {'id': p, 'ops': [{'op': 'add', 'path': '/0/elements/-', 'value': {'id': 'n0new01', 'elType': 'widget', 'widgetType': 'e-paragraph', 'settings': {'paragraph': 'plain'}, 'elements': []}}]}),
    ('patch dry run', 'update_elementor_patch', lambda p: {'id': p, 'dry_run': True, 'ops': [{'op': 'replace', 'path': P['a7e4d01'] + '/settings/tag', 'value': 'h3'}]}),
]
page = seed(ATOMIC_SRC)
before = meta(page)
for name, tool, make in refusals:
    r = call(tool, make(page))
    record(name + ': refused', is_refusal(r), r)
    record(name + ': nothing written', meta(page) == before)
r = call('set_widget_setting', {'id': page, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
record('the refusal names the element and shows the typed value to send',
       r.get('elements', [{}])[0].get('element_id') == 'a7e4d01'
       and r.get('examples', {}).get('a7e4d01', {}).get('tag') == env('string', 'h3')
       and "Elementor's editor" in r.get('message', '') and 'Nothing was written' in r.get('message', ''), r)
r = call('update_elementor_widget', {'id': page, 'widget_id': 'a7e4d01', 'settings_patch': {'header_size': env('string', 'h3')}})
record('header_size: named as not a setting of e-heading (registered schema)',
       r.get('elements', [{}])[0].get('unknown_keys') == ['header_size'] and 'is not a setting of e-heading (its settings:' in r.get('message', ''), r)

b = call('update_elementor_widgets_bulk', {'updates': [
    {'post_id': page, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': 'h3'}},
    {'post_id': page, 'widget_id': 'a7e4d06', 'settings_patch': {'link': {'destination': 'https://example.com/bulk'}}},
]})
rows = b.get('results', [])
record('B1 bulk: both plain rows fail with the code and its details',
       b.get('failed') == 2 and all(x.get('error') == 'atomic_plain_value_unsupported' and (x.get('error_data') or {}).get('elements') for x in rows), b)
record('B1 bulk: nothing written', meta(page) == before)

h = call('set_heading_level', {'id': page, 'widget_id': 'a7e4d01', 'level': 'h3'})
record('set_heading_level on e-heading: refused with the typed-value hint',
       h.get('_isError') and 'typed value' in h.get('text', '') and '"$$type": "string", "value": "h3"' in h.get('text', ''), h)

wp('option', 'update', 'iato_mcp_key_owner', '0', user=None)
try:
    u = call('set_widget_setting', {'id': page, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
finally:
    wp('option', 'update', 'iato_mcp_key_owner', OWNER, user=None)
record('unbound key: refused the same way', is_refusal(u), u)
record('unbound key: nothing written', meta(page) == before)

classic = call('update_elementor_widget', {'id': seed(MIXED_SRC), 'widget_id': 'c1a5501', 'settings_patch': {'title': 'Classic edited'}})
record('classic widget on a mixed page: written as before', not classic.get('_isError') and classic.get('stored_by') == 'elementor', classic)

# ── B. typed values still work ─────────────────────────────────────────────

print('B. Typed values and paths inside an envelope still work')
p = seed(ATOMIC_SRC)
r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h3')}})
fe = front(p, 'a7e4d01')
record('R10 envelope via update_elementor_widget: stored by Elementor, renders h3',
       r.get('stored_by') == 'elementor' and (node(meta(p), 'a7e4d01') or {}).get('settings', {}).get('tag') == env('string', 'h3') and fe.get('tag') == 'h3', [r, fe])
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d02', 'key': 'tag', 'value': env('string', 'h4')})
record('envelope via set_widget_setting on a default tag', r.get('stored_by') == 'elementor' and front(p, 'a7e4d02').get('tag') == 'h4', r)
r = call('update_elementor_patch', {'id': p, 'ops': [{'op': 'replace', 'path': P['a7e4d05'] + '/settings/image/value/src/value/alt/value', 'value': 'Patched alt'}]})
record('R12 patch path inside the envelope: alt changes', r.get('stored_by') == 'elementor' and 'alt="Patched alt"' in front(p, 'a7e4d05').get('html', ''), r)
r = call('update_elementor_patch', {'id': p, 'ops': [{'op': 'add', 'path': P['a7e4d03'] + '/settings/tag', 'value': env('string', 'span')}]})
record('patch add of a typed value', r.get('stored_by') == 'elementor' and front(p, 'a7e4d03').get('tag') == 'span', r)
r = call('update_elementor_widgets_bulk', {'updates': [{'post_id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'text': env('escaped-html', 'Bulk text')}}]})
record('bulk with a typed value', r.get('succeeded') == 1, r)
record('the page still saves in Elementor', editor_save(p) == 'ok')

# ── C. no fallback when Elementor rejects the change; fallback for other throws ─

print('C. Elementor rejects an atomic element: nothing written')
p = seed(ATOMIC_SRC)
before_p = meta(p)
r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h7')}})
record('invalid typed value (h7): refused with atomic_invalid_settings', is_refusal(r, 'atomic_invalid_settings') and r.get('elements', [{}])[0].get('element_id') == 'a7e4d01', r)
record('invalid typed value: nothing written', meta(p) == before_p)
wp('option', 'update', 'iato_mcp_test_force_save_fail', '1', user=None)
try:
    r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h3')}})
finally:
    wp('option', 'delete', 'iato_mcp_test_force_save_fail', user=None)
record('any other Throwable at save (mu-plugin switch): the fallback still stores the change',
       r.get('stored_by') == 'plugin' and r.get('elementor_save') == 'error' and 'forced by iato-mcp test' in r.get('elementor_save_error', '')
       and (node(meta(p), 'a7e4d01') or {}).get('settings', {}).get('tag') == env('string', 'h3'), r)

print('C2. Whole-tree writes and type changes (independent review, 1.13.1)')
p = seed(ATOMIC_SRC)
before_p = meta(p)
tree = json.loads(before_p)
plain_tree = json.loads(before_p)
plain_tree[0]['elements'][0]['settings']['tag'] = 'h3'
for dry in (True, False):
    r = call('update_elementor_data', {'id': p, 'elementor_data': json.dumps(plain_tree), 'dry_run': dry})
    record(f'update_elementor_data with a plain value{" (dry run)" if dry else ""}: refused', is_refusal(r), r)
record('update_elementor_data refusals: nothing written', meta(p) == before_p)
typed_tree = json.loads(before_p)
typed_tree[0]['elements'][0]['settings']['tag'] = env('string', 'h3')
r = call('update_elementor_data', {'id': p, 'elementor_data': json.dumps(typed_tree)})
record('update_elementor_data with a typed value: stored by Elementor, renders h3', r.get('meta_persisted') is True and r.get('stored_by') == 'elementor' and front(p, 'a7e4d01').get('tag') == 'h3', r)
before_p = meta(p)
bad_tree = json.loads(before_p)
bad_tree[0]['elements'][0]['settings']['tag'] = env('string', 'h9')
r = call('update_elementor_data', {'id': p, 'elementor_data': json.dumps(bad_tree)})
record('update_elementor_data with an invalid typed value (h9): refused, nothing written', is_refusal(r, 'atomic_invalid_settings') and meta(p) == before_p, r)

mx = seed(MIXED_SRC)
before_mx = meta(mx)
for wt, path in (('e-heading', '/0/elements/0/widgetType'),):
    r = call('update_elementor_patch', {'id': mx, 'ops': [{'op': 'replace', 'path': path, 'value': wt}]})
    record(f'patch: classic heading turned into {wt} with its plain settings: refused', is_refusal(r), r)
record('type-change refusal: nothing written', meta(mx) == before_mx)

cp = seed(ATOMIC_SRC)
before_cp = meta(cp)
n = len(json.loads(before_cp)[0]['elements'])
r = call('update_elementor_patch', {'id': cp, 'ops': [
    {'op': 'copy', 'from': P['a7e4d01'], 'path': '/0/elements/-'},
    {'op': 'replace', 'path': f'/0/elements/{n}/settings/tag/value', 'value': 'h9'}]})
record('patch: copy of a heading (same id) given an invalid typed value: refused', is_refusal(r, 'atomic_invalid_settings'), r)
record('copy refusal: nothing written', meta(cp) == before_cp)

print('C3. Second review: the unbound key, dry runs, copies of a damaged element')
p = seed(ATOMIC_SRC)
before_p = meta(p)
wp('option', 'update', 'iato_mcp_key_owner', '0', user=None)
try:
    bad = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h9')}})
    bad_after = meta(p)
    good = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h3')}})
finally:
    wp('option', 'update', 'iato_mcp_key_owner', OWNER, user=None)
record('unbound key, invalid typed value (h9): refused, nothing written', is_refusal(bad, 'atomic_invalid_settings') and bad_after == before_p, bad)
record('unbound key, valid typed value: stored by the plugin (Elementor refuses user 0)', good.get('stored_by') == 'plugin' and good.get('elementor_save') is False and front(p, 'a7e4d01').get('tag') == 'h3', good)

p = seed(ATOMIC_SRC)
before_p = meta(p)
n = len(json.loads(before_p)[0]['elements'])
tree = json.loads(before_p)
tree[0]['elements'][0]['settings']['tag'] = env('string', 'h9')
for name, tool, args in [
    ('update_elementor_widget', 'update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h9')}, 'dry_run': True}),
    ('set_widget_setting', 'set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': env('string', 'h9'), 'dry_run': True}),
    ('update_elementor_patch', 'update_elementor_patch', {'id': p, 'dry_run': True, 'ops': [{'op': 'copy', 'from': P['a7e4d01'], 'path': '/0/elements/-'}, {'op': 'replace', 'path': f'/0/elements/{n}/settings/tag/value', 'value': 'h9'}]}),
    ('update_elementor_data', 'update_elementor_data', {'id': p, 'elementor_data': json.dumps(tree), 'dry_run': True}),
]:
    r = call(tool, args)
    record(f'{name} dry run with an invalid typed value: reports the refusal the write would give', is_refusal(r, 'atomic_invalid_settings'), r)
r = call('update_elementor_widgets_bulk', {'dry_run': True, 'updates': [{'post_id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h9')}}]})
record('bulk dry run with an invalid typed value: the row reports the refusal', (r.get('results') or [{}])[0].get('error') == 'atomic_invalid_settings', r)
record('dry runs wrote nothing', meta(p) == before_p)

p = seed(ATOMIC_SRC)
link = env('link', {'destination': env('url', 'example.com/no-scheme'), 'isTargetBlank': env('boolean', False)})
d = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'link': link}, 'dry_run': True})
w = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'link': link}})
record('a link without a scheme: the dry run and the write agree (both accepted after sanitising)', d.get('dry_run') is True and not d.get('_isError') and not w.get('_isError'), [d, w])

dp = seed(ATOMIC_SRC, {'a7e4d05': {'image': {'src': {'url': EXT, 'alt': 'Damaged'}}}})
before_dp = meta(dp)
r = call('update_elementor_patch', {'id': dp, 'ops': [{'op': 'copy', 'from': P['a7e4d05'], 'path': '/0/elements/-'}]})
record('patch: copy of an element damaged by 1.13.0 (same id): refused, the damage does not spread', is_refusal(r) and meta(dp) == before_dp, r)
r = call('update_elementor_patch', {'id': dp, 'ops': [{'op': 'copy', 'from': P['a7e4d01'], 'path': '/0/elements/-'}]})
record('patch: copy of a valid element on the same page: stored', not r.get('_isError') and r.get('stored_by') in ('elementor', 'plugin'), r)

sk = seed(ATOMIC_SRC, {'a7e4d01': {'header_size': env('string', 'h3')}})
r = call('update_elementor_patch', {'id': sk, 'ops': [{'op': 'copy', 'from': P['a7e4d01'], 'path': '/0/elements/-'}]})
record('patch: copy of an element carrying a stale non-schema key: accepted (checked like the read flag)', not r.get('_isError') and r.get('stored_by') == 'elementor', r)

tw = seed(ATOMIC_SRC)
n_tw = len(json.loads(meta(tw))[0]['elements'])
r = call('update_elementor_patch', {'id': tw, 'ops': [{'op': 'copy', 'from': P['a7e4d01'], 'path': '/0/elements/-'}]})
record('a page with duplicate ids prepared by copying a valid heading', not r.get('_isError'), r)
TWIN_PHP = r'''$t = json_decode(get_post_meta(%(pid)d,'_elementor_data',true), true); $t[0]['elements'][%(n)d]['settings']['tag'] = 'h3'; update_post_meta(%(pid)d,'_elementor_data', wp_slash(wp_json_encode($t))); echo 'ok';'''
wp('eval', TWIN_PHP % {'pid': tw, 'n': n_tw})
r = call('update_elementor_widget', {'id': tw, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': env('string', 'h4')}})
record('duplicate ids, damaged twin: editing the valid one is not blocked by the twin', not r.get('_isError') and front(tw, 'a7e4d01').get('tag') == 'h4', r)

print('C4. Reading a classic page does not start Elementor\'s widget registry')
wp('option', 'update', 'iato_mcp_test_record_widget_registration', '1', user=None)
try:
    reg = {}
    for label, src in (('classic-only page', CLASSIC_SRC), ('atomic page (control)', ATOMIC_SRC)):
        wp('option', 'delete', 'iato_mcp_test_widgets_registered', user=None)
        for fmt in ('summary', 'raw', 'compact'):
            call('get_elementor_data', {'id': src, 'format': fmt})
        call('list_elementor_widgets', {'id': src})
        reg[label] = wp('option', 'get', 'iato_mcp_test_widgets_registered', user=None)
finally:
    wp('option', 'delete', 'iato_mcp_test_record_widget_registration', user=None)
    wp('option', 'delete', 'iato_mcp_test_widgets_registered', user=None)
record('classic-only page reads (get_elementor_data in every format, list_elementor_widgets): no widget registration', reg['classic-only page'] == '', reg)
print(f"  INFO  atomic page reads triggered widget registration: {reg['atomic page (control)'] == '1'}")

# ── D. read flags ──────────────────────────────────────────────────────────

print('D. Read tools flag elements stored without their envelope')
DAMAGE = {'a7e4d01': {'tag': 'h3'},
          'a7e4d06': {'link': {'destination': 'https://example.com/new'}},
          'a7e4d05': {'image': {'src': {'url': EXT, 'alt': 'New alt'}}}}
EXPECT = {'a7e4d01': ['tag'], 'a7e4d06': ['link'], 'a7e4d05': ['image']}
dp = seed(ATOMIC_SRC, DAMAGE)
raw_before = meta(dp)


def flags_in(nodes):
    out = {}
    def walk(ns):
        for n in ns or []:
            if n.get('stored_value_invalid'):
                out[n.get('widget_id')] = n.get('invalid_keys')
            walk(n.get('children'))
    walk(nodes)
    return out


def check_reads(label):
    s = call('get_elementor_data', {'id': dp, 'format': 'summary'})
    record(f'{label}: get_elementor_data summary flags the elements', flags_in(s.get('tree')) == EXPECT, flags_in(s.get('tree')))
    record(f'{label}: summary lists invalid_elements', {e['widget_id']: e['invalid_keys'] for e in s.get('invalid_elements', [])} == EXPECT, s.get('invalid_elements'))
    for fmt in ('raw', 'compact'):
        d = call('get_elementor_data', {'id': dp, 'format': fmt})
        record(f'{label}: get_elementor_data {fmt} lists invalid_elements', {e['widget_id']: e['invalid_keys'] for e in d.get('invalid_elements', [])} == EXPECT, d.get('invalid_elements'))
    d = call('get_elementor_data', {'id': dp, 'format': 'raw'})
    record(f'{label}: raw data is returned exactly as stored', d.get('elementor_data') == raw_before)
    lst = call('list_elementor_widgets', {'id': dp})
    items = lst.get('widgets') or lst.get('items') or lst.get('elements') or []
    record(f'{label}: list_elementor_widgets flags the elements', flags_in(items) == EXPECT, list(lst.keys()))
    f = call('find_elementor_widgets', {'post_ids': [dp], 'filter': {'type': 'e-button'}})
    matches = f.get('matches') or f.get('results') or []
    record(f'{label}: find_elementor_widgets flags the element', flags_in(matches) == {'a7e4d06': ['link']}, f)
    w = call('get_elementor_widget', {'id': dp, 'widget_id': 'a7e4d06'})
    record(f'{label}: get_elementor_widget flags the element and shows the stored value as stored',
           w.get('stored_value_invalid') is True and w.get('invalid_keys') == ['link']
           and w.get('settings_plain', {}).get('link') == {'destination': 'https://example.com/new'}
           and 'link' not in (w.get('peek') or {}) and w.get('tag') == 'button', w)
    i = call('get_elementor_widget', {'id': dp, 'widget_id': 'a7e4d05'})
    record(f'{label}: the damaged image reports no image_url / image_alt', i.get('invalid_keys') == ['image'] and 'image_url' not in (i.get('peek') or {}), i)


check_reads('with Elementor (registered schema)')
wp('plugin', 'deactivate', 'elementor', user=None)
try:
    check_reads('Elementor deactivated (stored data only)')
finally:
    wp('plugin', 'activate', 'elementor', user=None)
for src, label in ((ATOMIC_SRC, 'atomic'), (MIXED_SRC, 'mixed')):
    vp = seed(src)
    s = json.dumps([call('get_elementor_data', {'id': vp, 'format': f}) for f in ('summary', 'raw', 'compact')] + [call('list_elementor_widgets', {'id': vp})])
    record(f'valid {label} page: no flag anywhere', 'stored_value_invalid' not in s and 'invalid_elements' not in s)
record('the damaged page was not changed by any read', meta(dp) == raw_before)

# ── E. repair by sending a correct typed value ─────────────────────────────

print('E. Repair: send a correct typed value for each damaged key')
REPAIRS = {
    'tag': ('a7e4d01', {'tag': 'h3'}, {'tag': env('string', 'h2')}, lambda fe: fe.get('tag') == 'h2'),
    'title': ('a7e4d01', {'title': 'Tom & Jerry'}, {'title': env('escaped-html', 'Tom & Jerry')}, lambda fe: 'Tom &amp; Jerry' in fe.get('html', '')),
    'link': ('a7e4d06', {'link': {'destination': 'https://example.com/new'}},
             {'link': env('link', {'destination': env('url', 'https://example.com/fixed'), 'isTargetBlank': env('boolean', False)})},
             lambda fe: fe.get('tag') == 'a' and 'href="https://example.com/fixed"' in fe.get('html', '')),
    'image': ('a7e4d05', {'image': {'src': {'url': EXT, 'alt': 'New alt'}}},
              {'image': env('image', {'src': env('image-src', {'id': None, 'url': env('url', EXT), 'alt': env('string', 'Repaired alt')}), 'size': env('string', 'full')})},
              lambda fe: fe.get('present') and 'alt="Repaired alt"' in fe.get('html', '')),
}
for key, (wid, damage, fix, renders) in REPAIRS.items():
    rp = seed(ATOMIC_SRC, {wid: damage})
    record(f'{key}: the damaged element fails Elementor\'s validation first', elementor_validation(rp).get(wid, 'ok') != 'ok')
    r = call('update_elementor_widget', {'id': rp, 'widget_id': wid, 'settings_patch': fix})
    record(f'{key}: repair accepted and stored by Elementor', not r.get('_isError') and r.get('stored_by') == 'elementor', r)
    record(f'{key}: the element now validates', elementor_validation(rp).get(wid) == 'ok', elementor_validation(rp))
    record(f'{key}: no flag after the repair', 'stored_value_invalid' not in json.dumps(call('get_elementor_widget', {'id': rp, 'widget_id': wid})))
    fe = front(rp, wid)
    record(f'{key}: renders correctly again', renders(fe), fe)
    record(f'{key}: Elementor saves the page again', editor_save(rp) == 'ok')

rp = seed(ATOMIC_SRC, {'a7e4d01': {'tag': 'h3'}})
r = call('update_elementor_patch', {'id': rp, 'ops': [{'op': 'replace', 'path': P['a7e4d01'] + '/settings/tag', 'value': env('string', 'h2')}]})
record('repair through update_elementor_patch with a typed value', r.get('stored_by') == 'elementor' and editor_save(rp) == 'ok', r)
rp = seed(ATOMIC_SRC, {'a7e4d06': {'link': {'destination': 'https://example.com/new'}}})
r = call('set_widget_setting', {'id': rp, 'widget_id': 'a7e4d06', 'key': 'link',
                                'value': env('link', {'destination': env('url', 'https://example.com/fixed'), 'isTargetBlank': env('boolean', True)})})
record('repair through set_widget_setting with a typed value', r.get('stored_by') == 'elementor' and front(rp, 'a7e4d06').get('tag') == 'a', r)

print('E2. A page with several damaged elements, repaired one at a time')
mp = seed(ATOMIC_SRC, DAMAGE)
order = [('a7e4d01', REPAIRS['tag'][2]), ('a7e4d06', REPAIRS['link'][2]), ('a7e4d05', REPAIRS['image'][2])]
for n, (wid, fix) in enumerate(order, 1):
    r = call('update_elementor_widget', {'id': mp, 'widget_id': wid, 'settings_patch': fix})
    remaining = {k: v for k, v in EXPECT.items() if k not in [o[0] for o in order[:n]]}
    if remaining:
        record(f'repair {n} ({wid}): written although other elements are still damaged, and they are named',
               not r.get('_isError') and r.get('stored_by') == 'plugin'
               and {e['widget_id']: e['invalid_keys'] for e in r.get('invalid_elements', [])} == remaining, r)
    else:
        record(f'repair {n} ({wid}): last repair, Elementor stores the page itself', r.get('stored_by') == 'elementor' and 'invalid_elements' not in r, r)
record('several damaged elements: every element validates at the end', set(elementor_validation(mp).values()) == {'ok'}, elementor_validation(mp))
record('several damaged elements: Elementor saves the page again', editor_save(mp) == 'ok')
record('several damaged elements: link and image render again', front(mp, 'a7e4d06').get('tag') == 'a' and front(mp, 'a7e4d05').get('present'))

print(f'\n{passes} passed, {len(fails)} failed')
if fails:
    for f in fails:
        print('  -', f)
    sys.exit(1)

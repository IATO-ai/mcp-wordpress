#!/usr/bin/env python3
"""
Live checks for 1.13.1, driven by tests/wp-env/unregistered-types-live.sh
(env: BASE, SVC, ENV_DIR, KEY, OWNER). Element types Elementor cannot load
right now must survive every IATO Elementor write:
  A  everything registered: Elementor's own save still runs, unchanged
  B  a widget from a deactivated addon (the test plugin iato-test-addon):
     every Elementor write tool and rollback keep it byte for byte, report
     elementor_save "skipped" with the type and element id, clear the post's
     caches and verify the untouched elements; dry runs report the skip and
     write nothing; reactivating the addon brings the widget back
  C  atomic elements while the Atomic Editor is off (only where Elementor does
     not register e-heading, e.g. the legacy site): a classic edit on a mixed
     page and a typed-value edit on an atomic page keep every element
Exit 1 on any failure.
"""
import json, os, re, subprocess, sys, time, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
SVC = os.environ.get('SVC', 'cli')
ENV_DIR = os.environ['ENV_DIR']
KEY = os.environ['KEY']
MSG = BASE + '/wp-json/iato-mcp/v1/message'
STAMP = str(int(time.time()))
ADDON = 'iato-test-addon-widget'
passes, fails = 0, []


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
    r = subprocess.run(cmd, capture_output=True, text=True, stdin=subprocess.DEVNULL, cwd=ENV_DIR)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def call(tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST',
                                 headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + KEY})
    try:
        raw = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    r = json.loads(raw)
    if 'error' in r:
        return {'rpc_error': r['error']}
    res = r.get('result', {})
    text = res.get('content', [{}])[0].get('text', '')
    try:
        payload = json.loads(text)
    except ValueError:
        payload = {'text': text}
    if res.get('isError'):
        payload['_isError'] = True
    return payload


def meta(pid):
    return wp('post', 'meta', 'get', str(pid), '_elementor_data', user=None)


def nodes(raw):
    out = {}
    def walk(els):
        for e in els or []:
            n = dict(e)
            n.pop('elements', None)
            out[e.get('id')] = json.dumps(n, sort_keys=True)
            walk(e.get('elements'))
    walk(json.loads(raw))
    return out


def front(pid):
    try:
        with urllib.request.urlopen(f'{BASE}/?page_id={pid}', timeout=60) as r:
            return r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.read().decode('utf-8', 'replace')


SEED_PHP = r"""
$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'unregistered-live-%(label)s-%(stamp)s','post_name'=>'unregistered-live-%(label)s-%(stamp)s']);
update_post_meta($id, '_elementor_edit_mode', 'builder');
update_post_meta($id, '_elementor_template_type', 'wp-page');
$elements = [[ 'id' => 's1', 'elType' => 'section', 'settings' => [], 'elements' => [[ 'id' => 'col1', 'elType' => 'column', 'settings' => ['_column_size' => 100], 'elements' => [
  [ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Classic heading', 'header_size' => 'h2' ], 'elements' => [] ],
  [ 'id' => 'ad1', 'elType' => 'widget', 'widgetType' => 'iato-test-addon-widget', 'settings' => [ 'label' => 'Addon content & more' ], 'elements' => [] ],
  [ 'id' => 'h2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Second heading' ], 'elements' => [] ],
]]]]];
$saved = \Elementor\Plugin::$instance->documents->get($id, false)->save(['elements' => $elements]);
echo wp_json_encode(['id' => $id, 'saved' => $saved]);
"""

FIXTURE_PHP = r"""
$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'unregistered-live-%(label)s-%(stamp)s','post_name'=>'unregistered-live-%(label)s-%(stamp)s']);
update_post_meta($id, '_elementor_data', wp_slash(file_get_contents(WP_PLUGIN_DIR . '/mcp-wordpress/tests/fixtures/elementor/%(fixture)s.json')));
update_post_meta($id, '_elementor_edit_mode', 'builder');
update_post_meta($id, '_elementor_template_type', 'wp-page');
echo $id;
"""

counter = [0]


def seed_addon_page():
    counter[0] += 1
    out = wp('eval', SEED_PHP % {'label': f'addon-{counter[0]}', 'stamp': STAMP})
    return json.loads(out.splitlines()[-1])


def seed_fixture(fixture):
    counter[0] += 1
    return int(wp('eval', FIXTURE_PHP % {'label': f'{fixture}-{counter[0]}', 'stamp': STAMP, 'fixture': fixture}).splitlines()[-1])


def skipped_ok(r, before_raw, after_raw, types, changed=()):
    """The skip report, every element kept, the untouched ones byte for byte."""
    b, a = nodes(before_raw), nodes(after_raw)
    untouched_same = all(a.get(k) == v for k, v in b.items() if k not in changed)
    reported = {t['type']: t['element_ids'] for t in r.get('unregistered_element_types', [])}
    v = r.get('verification', {})
    return (r.get('elementor_save') == 'skipped' and r.get('skipped_reason') == 'unregistered_element_types'
            and r.get('stored_by') == 'plugin' and all(t in reported for t in types)
            and set(a) == set(b) and untouched_same
            and v.get('untouched_identical') is True and v.get('element_count_before') == v.get('element_count_after') == len(b))


# ── A. everything registered ───────────────────────────────────────────────

print('A. Everything registered: Elementor\'s save runs as before')
page = seed_addon_page()
record('the test addon page is saved by Elementor', page.get('saved') is True, page)
pid = page['id']
record('the addon widget renders while the addon is active', 'iato-test-addon-marker' in front(pid))
r = call('update_elementor_widget', {'id': pid, 'widget_id': 'h2', 'settings_patch': {'title': 'Edited while registered'}})
record('edit with everything registered: stored by Elementor, no skip', r.get('stored_by') == 'elementor' and r.get('elementor_save') is True and 'skipped_reason' not in r, r)

# ── B. a deactivated addon ─────────────────────────────────────────────────

print('B. Widget from a deactivated addon')
wp('plugin', 'deactivate', 'iato-test-addon', user=None)
addon_node = nodes(meta(pid))['ad1']
wp('post', 'meta', 'update', str(pid), '_elementor_element_cache', 'stale cached html', user=None)

before = meta(pid)
r = call('update_elementor_widget', {'id': pid, 'widget_id': 'h1', 'settings_patch': {'title': 'Edited while the addon is off'}})
after = meta(pid)
record('update_elementor_widget: skip reported, every element kept, untouched elements identical', skipped_ok(r, before, after, [ADDON], changed=('h1',)), r)
record('update_elementor_widget: the addon widget is reported with its element id',
       {'type': ADDON, 'element_ids': ['ad1']} in r.get('unregistered_element_types', []), r.get('unregistered_element_types'))
record('the addon widget is stored byte for byte', nodes(after).get('ad1') == addon_node)
record('the edit itself was stored', 'Edited while the addon is off' in after)
record('the post\'s CSS and element cache were cleared', wp('post', 'meta', 'get', str(pid), '_elementor_element_cache', user=None) == '' and wp('post', 'meta', 'get', str(pid), '_elementor_css', user=None) == '' and r.get('cache_cleared') == {'post_css': True, 'element_cache': True, 'atomic_styles': True}, r.get('cache_cleared'))

rb = call('rollback', {'change_id': r['change_receipt']['change_id']})
after_rb = meta(pid)
record('rollback: succeeds and keeps the addon widget', rb.get('success') is True and nodes(after_rb).get('ad1') == addon_node and 'Classic heading' in after_rb, rb)

for name, tool, args, changed in [
    ('set_heading_level', 'set_heading_level', {'id': pid, 'widget_id': 'h1', 'level': 'h3'}, ('h1',)),
    ('set_widget_setting', 'set_widget_setting', {'id': pid, 'widget_id': 'h2', 'key': 'title', 'value': 'Set while off'}, ('h2',)),
    ('update_elementor_patch', 'update_elementor_patch', {'id': pid, 'ops': [{'op': 'replace', 'path': '/0/elements/0/elements/0/settings/title', 'value': 'Patched while off'}]}, ('h1',)),
]:
    before = meta(pid)
    r = call(tool, args)
    record(f'{name}: skip reported, every element kept', skipped_ok(r, before, meta(pid), [ADDON], changed=changed), r)

before = meta(pid)
r = call('update_elementor_widgets_bulk', {'updates': [{'post_id': pid, 'widget_id': 'h2', 'settings_patch': {'title': 'Bulk while off'}}]})
row = (r.get('results') or [{}])[0]
record('update_elementor_widgets_bulk: skip reported on the row, every element kept', skipped_ok(row, before, meta(pid), [ADDON], changed=('h2',)), r)

before = meta(pid)
tree = json.loads(before)
tree[0]['elements'][0]['elements'][2]['settings']['title'] = 'Replaced while off'
r = call('update_elementor_data', {'id': pid, 'elementor_data': json.dumps(tree)})
record('update_elementor_data: skip reported, every element kept', skipped_ok(r, before, meta(pid), [ADDON], changed=('h2',)), r)

before = meta(pid)
dry = [
    ('update_elementor_widget', call('update_elementor_widget', {'id': pid, 'widget_id': 'h1', 'settings_patch': {'title': 'dry'}, 'dry_run': True})),
    ('update_elementor_patch', call('update_elementor_patch', {'id': pid, 'dry_run': True, 'ops': [{'op': 'replace', 'path': '/0/elements/0/elements/0/settings/title', 'value': 'dry'}]})),
    ('update_elementor_data', call('update_elementor_data', {'id': pid, 'elementor_data': json.dumps(tree), 'dry_run': True})),
]
for name, d in dry:
    record(f'{name} dry run: reports the skip', d.get('dry_run') is True and d.get('skipped_reason') == 'unregistered_element_types'
           and {'type': ADDON, 'element_ids': ['ad1']} in d.get('unregistered_element_types', []), d)
record('dry runs wrote nothing', meta(pid) == before)

registered_here = wp('eval', 'echo \\Elementor\\Plugin::$instance->widgets_manager->get_widget_types("e-heading") ? "yes" : "no";').endswith('yes')
if registered_here:
    MIX_PHP = r'''
$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'unregistered-live-atomic-addon-%(stamp)s','post_name'=>'unregistered-live-atomic-addon-%(stamp)s']);
$tree = [[ 'id' => 'fx1', 'elType' => 'e-flexbox', 'settings' => [], 'elements' => [
  [ 'id' => 'ah1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [ 'tag' => [ '$$type' => 'string', 'value' => 'h2' ], 'title' => [ '$$type' => 'escaped-html', 'value' => 'Atomic next to an addon' ] ], 'elements' => [] ],
  [ 'id' => 'ad2', 'elType' => 'widget', 'widgetType' => 'iato-test-addon-widget', 'settings' => [ 'label' => 'Addon in an atomic container' ], 'elements' => [] ],
]]];
update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($tree)));
update_post_meta($id, '_elementor_edit_mode', 'builder');
update_post_meta($id, '_elementor_template_type', 'wp-page');
echo $id;
'''
    ap = int(wp('eval', MIX_PHP % {'stamp': STAMP}).splitlines()[-1])
    before = meta(ap)
    r = call('update_elementor_widget', {'id': ap, 'widget_id': 'ah1', 'settings_patch': {'tag': {'$$type': 'string', 'value': 'h9'}}})
    record('addon off + atomic heading, invalid typed value (h9): refused although Elementor\'s save is skipped', r.get('_isError') and r.get('error') == 'atomic_invalid_settings', r)
    record('invalid typed value: nothing written', meta(ap) == before)
    r = call('update_elementor_widget', {'id': ap, 'widget_id': 'ah1', 'settings_patch': {'tag': {'$$type': 'string', 'value': 'h3'}}})
    record('addon off + atomic heading, valid typed value: stored, addon kept', skipped_ok(r, before, meta(ap), [ADDON], changed=('ah1',)), r)

wp('plugin', 'activate', 'iato-test-addon', user=None)
html = front(pid)
record('reactivated: the addon widget renders again with its content', 'iato-test-addon-marker' in html and 'Addon content &amp; more' in html, re.findall(r'<div class="iato-test-addon-marker">[^<]*</div>', html))
r = call('update_elementor_widget', {'id': pid, 'widget_id': 'h2', 'settings_patch': {'title': 'Edited after reactivation'}})
record('reactivated: the next edit goes through Elementor\'s save again and keeps the widget',
       r.get('stored_by') == 'elementor' and 'skipped_reason' not in r and 'ad1' in nodes(meta(pid)), r)

# ── B2. an e-addons-style classic widget named e-iframe ────────────────────

print('B2. Classic widget with an e- name (e-addons style), registered and deactivated')
EIFRAME_PHP = r'''
$id = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'unregistered-live-eiframe-%(stamp)s','post_name'=>'unregistered-live-eiframe-%(stamp)s']);
update_post_meta($id, '_elementor_edit_mode', 'builder');
update_post_meta($id, '_elementor_template_type', 'wp-page');
$elements = [[ 'id' => 's1', 'elType' => 'section', 'settings' => [], 'elements' => [[ 'id' => 'col1', 'elType' => 'column', 'settings' => ['_column_size' => 100], 'elements' => [
  [ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Heading next to e-iframe' ], 'elements' => [] ],
  [ 'id' => 'ei1', 'elType' => 'widget', 'widgetType' => 'e-iframe', 'settings' => [ 'url' => 'https://maps.example.test/' ], 'elements' => [] ],
]]]]];
$saved = \Elementor\Plugin::$instance->documents->get($id, false)->save(['elements' => $elements]);
echo wp_json_encode(['id' => $id, 'saved' => $saved]);
'''
ep = json.loads(wp('eval', EIFRAME_PHP % {'stamp': STAMP}).splitlines()[-1])
record('e-iframe page saved by Elementor (the widget is registered)', ep.get('saved') is True, ep)
eid = ep['id']


def no_flags(pid):
    blob = json.dumps([call('get_elementor_data', {'id': pid, 'format': f}) for f in ('summary', 'raw', 'compact')]
                      + [call('list_elementor_widgets', {'id': pid}), call('get_elementor_widget', {'id': pid, 'widget_id': 'ei1'}),
                         call('find_elementor_widgets', {'post_ids': [pid], 'filter': {'type': 'e-iframe'}})])
    return 'stored_value_invalid' not in blob and 'invalid_elements' not in blob


r = call('update_elementor_widget', {'id': eid, 'widget_id': 'ei1', 'settings_patch': {'url': 'https://other.example.test/'}})
record('registered e-iframe: a plain edit goes through as before (stored by Elementor)', not r.get('_isError') and r.get('stored_by') == 'elementor', r)
record('registered e-iframe: the read tools flag nothing', no_flags(eid))
w = call('get_elementor_widget', {'id': eid, 'widget_id': 'ei1'})
record('registered e-iframe: reported as schema classic, with no atomic fields', w.get('schema') == 'classic' and 'settings_plain' not in w, w)
wp('plugin', 'deactivate', 'iato-test-addon', user=None)
try:
    ei_node = nodes(meta(eid))['ei1']
    before = meta(eid)
    r = call('update_elementor_widget', {'id': eid, 'widget_id': 'h1', 'settings_patch': {'title': 'Edited while e-iframe is unregistered'}})
    record('unregistered e-iframe: the heading edit keeps it byte for byte (skip path)',
           skipped_ok(r, before, meta(eid), ['e-iframe'], changed=('h1',)) and nodes(meta(eid)).get('ei1') == ei_node, r)
    record('unregistered e-iframe with plain settings: the read tools flag nothing', no_flags(eid))
    before = meta(eid)
    r = call('update_elementor_widget', {'id': eid, 'widget_id': 'ei1', 'settings_patch': {'url': 'https://third.example.test/'}})
    record('unregistered e-iframe: a plain edit to it is not refused as atomic, and the page keeps every element',
           not r.get('_isError') and skipped_ok(r, before, meta(eid), ['e-iframe'], changed=('ei1',)), r)
finally:
    wp('plugin', 'activate', 'iato-test-addon', user=None)
record('reactivated: e-iframe renders with its last URL', 'https://third.example.test/' in front(eid))

# ── C. atomic elements while the Atomic Editor is off ──────────────────────

print('C. Atomic elements while the Atomic Editor is off')
registered = wp('eval', 'echo \\Elementor\\Plugin::$instance->widgets_manager->get_widget_types("e-heading") ? "yes" : "no";')
if registered.endswith('yes'):
    print('  SKIP  this site registers e-heading (Atomic Editor on); run the suite against the legacy site for this section')
else:
    mixed = seed_fixture('mixed')
    before = meta(mixed)
    r = call('update_elementor_widget', {'id': mixed, 'widget_id': 'c1a5501', 'settings_patch': {'title': 'Classic edited, Atomic Editor off'}})
    after = meta(mixed)
    record('mixed page, classic edit: skip reported, all elements kept', skipped_ok(r, before, after, ['e-flexbox', 'e-heading'], changed=('c1a5501',)), r)
    lost = sorted(set(nodes(before)) - set(nodes(after)))
    record(f'mixed page: element count {len(nodes(before))} before and {len(nodes(after))} after, none lost (1.13.0 lost six)', not lost and len(nodes(before)) == len(nodes(after)) == 11, lost)
    atomic = seed_fixture('atomic-only')
    before = meta(atomic)
    r = call('update_elementor_widget', {'id': atomic, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': {'$$type': 'string', 'value': 'h3'}}})
    record('atomic page, typed-value edit: skip reported, every atomic element kept', skipped_ok(r, before, meta(atomic), ['e-flexbox', 'e-heading'], changed=('a7e4d01',)), r)
    r = call('update_elementor_widget', {'id': atomic, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': 'h3'}})
    record('atomic page, plain value: still refused by the guard', r.get('_isError') and r.get('error') == 'atomic_plain_value_unsupported', r)
    before = meta(atomic)
    r = call('update_elementor_patch', {'id': atomic, 'ops': [{'op': 'replace', 'path': '/0/elements/0/settings', 'value': {'tag': 'h3', 'title': 'Plain title'}}]})
    record('Atomic Editor off: replacing every setting of an atomic element with plain values is refused', r.get('_isError') and r.get('error') == 'atomic_plain_value_unsupported' and meta(atomic) == before, r)

print(f'\n{passes} passed, {len(fails)} failed')
if fails:
    for f in fails:
        print('  -', f)
    sys.exit(1)

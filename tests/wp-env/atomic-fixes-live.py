#!/usr/bin/env python3
"""
Live checks for 1.14.0, driven by tests/wp-env/atomic-fixes-live.sh
(env: BASE, SVC, KEY, OWNER, PREFIX, ADMIN_ID, AUTHOR_ID, PW_ADMIN, PW_AUTHOR,
OAUTH_TOKEN, ATOMIC_SRC, MIXED_SRC, CLASSIC_SRC).

Every check runs on scratch copies of the Sprint A fixture pages, through the
real MCP endpoint:
  A  the four fix types on atomic elements (heading level, text, link URL,
     external-image alt; library alt routed to the attachment), on published
     and draft pages: result fields, the stored meta, settings_plain, the
     rendered page, Elementor's next save of the page
  B  rollback of each change; refused after an outside edit unless forced;
     dry runs write nothing
  C  refusals write nothing (invalid level, classic key, javascript: link,
     relative image URL, global classes)
  D  forced Throwables (test mu-plugin): a validation throw at save is a
     refusal, another throw falls back to the plugin's write, a throwing
     resolver hook names the failure, a throw inside a tool is a clear error
  E  the Part 2 probe: a classic HTML widget (iframe, script) and a classic
     "Tom & Jerry" heading survive an atomic edit byte for byte under the
     bound key, OAuth, an admin Application Password and an Author's on the
     Author's own post; script and onclick sent in the atomic value are
     stripped
  F  permissions: Author on an admin page, the unbound key; unfiltered_html
     at shutdown and inside Elementor's save
  G  concurrency: lock + unsaved transient → 409; lock alone → written; a
     newer autosave → written and reported
  H  CSS: another page's CSS survives; the edited page's CSS and element
     cache are cleared; Elementor's atomic styles clear for this post only
  I  repair of pages damaged by IATO 1.13.0, one element at a time
  J  Elementor's load migrations run first; a dry run writes nothing
  K  bulk: a page's atomic rows in one save, a bad row fails alone
  M  update_elementor_patch / update_elementor_data convert plain values
  N  classic writes are unchanged apart from the three additive keys
  S  how often the page check sent a write through the plugin's own write
Exit 1 on any failure.
"""
import base64, json, os, re, subprocess, sys, time, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
SVC = os.environ.get('SVC', 'cli')
KEY = os.environ['KEY']
OWNER = os.environ['OWNER']
PREFIX = os.environ.get('PREFIX', 'wp_')
ADMIN_ID = int(os.environ['ADMIN_ID'])
AUTHOR_ID = int(os.environ['AUTHOR_ID'])
CREDS = {
    'key': 'Bearer ' + KEY,
    'oauth': 'Bearer ' + os.environ['OAUTH_TOKEN'],
    'admin_ap': 'Basic ' + base64.b64encode(f"admin:{os.environ['PW_ADMIN']}".encode()).decode(),
    'author_ap': 'Basic ' + base64.b64encode(f"author:{os.environ['PW_AUTHOR']}".encode()).decode(),
}
ATOMIC_SRC = int(os.environ['ATOMIC_SRC'])
MIXED_SRC = int(os.environ['MIXED_SRC'])
CLASSIC_SRC = int(os.environ['CLASSIC_SRC'])
MSG = BASE + '/wp-json/iato-mcp/v1/message'
STAMP = str(int(time.time()))
EXT = 'https://example.com/photos/external.jpg'
passes, fails = 0, []
counter = [0]
writes = {'atomic': 0, 'pages': set(), 'triggered': {}, 'trigger_pages': {}}


def record(name, ok, detail=None):
    global passes
    if ok:
        passes += 1
        print(f'  PASS  {name}')
    else:
        fails.append(name)
        print(f'  FAIL  {name}: {json.dumps(detail, default=str, ensure_ascii=False)[:900]}')


def wp(*args, user='admin'):
    cmd = ['npx', '@wordpress/env', 'run', SVC, 'wp', *args]
    if user:
        cmd.append(f'--user={user}')
    r = subprocess.run(cmd, capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def wp_json(php, user='admin'):
    out = wp('eval', php, user=user)
    try:
        return json.loads(out.splitlines()[-1])
    except (ValueError, IndexError):
        return {'_raw': out[-500:]}


def opt(name):
    return wp('option', 'get', name, user=None)


def set_opt(name, value):
    wp('option', 'update', name, str(value), user=None)


def del_opt(*names):
    wp('option', 'delete', *names, user=None)


def call(tool, args, who='key'):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST',
                                 headers={'Content-Type': 'application/json', 'Authorization': CREDS[who]})
    try:
        raw = urllib.request.urlopen(req, timeout=180).read()
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
    if not isinstance(payload, dict):
        payload = {'value': payload}
    if res.get('isError'):
        payload['_isError'] = True
    tally(args, payload)
    return payload


def tally(args, r):
    """Every atomic write: did the page check route it through the plugin's own write?"""
    if r.get('schema') == 'atomic' and r.get('current_revision') and not r.get('dry_run') and r.get('applied_patch'):
        pid = args.get('id') or args.get('post_id')
        writes['atomic'] += 1
        writes['pages'].add(pid)
        reason = r.get('skipped_reason')
        if reason in ('elementor_would_alter_untouched', 'invalid_elements_on_page'):
            writes['triggered'][reason] = writes['triggered'].get(reason, 0) + 1
            writes['trigger_pages'].setdefault(reason, set()).add(pid)


def env(t, v):
    return {'$$type': t, 'value': v}


def ok(r):
    return isinstance(r, dict) and not r.get('_isError') and 'rpc_error' not in r and r.get('verified') is True


def refused(r, code):
    return isinstance(r, dict) and r.get('_isError') and r.get('error') == code


# ── page helpers ───────────────────────────────────────────────────────────

SEED_PHP = r"""
$tree = json_decode(<<<'IATOJSON'
%(tree)s
IATOJSON, true);
$id = wp_insert_post(['post_type'=>'%(type)s','post_status'=>'%(status)s','post_author'=>%(author)d,'post_title'=>'atomic-fixes-live-%(label)s-%(stamp)s','post_name'=>'atomic-fixes-live-%(label)s-%(stamp)s']);
update_post_meta($id,'_elementor_data', wp_slash(wp_json_encode($tree)));
update_post_meta($id,'_elementor_edit_mode','builder');
update_post_meta($id,'_elementor_template_type','wp-%(type)s');
update_post_meta($id,'_elementor_version', get_post_meta(%(src)d,'_elementor_version',true));
echo $id;
"""


def tree_of(pid):
    return json.loads(meta(pid))


def seed(src, damage=None, status='publish', author=None, post_type='page', mutate=None):
    """Scratch copy of a fixture page; `damage` = {element_id: {key: value stored as IATO 1.13.0 did}}."""
    counter[0] += 1
    tree = tree_of(src)

    def walk(els):
        for e in els:
            for k, v in (damage or {}).get(e.get('id'), {}).items():
                if not isinstance(e.get('settings'), dict):
                    e['settings'] = {}
                e['settings'][k] = v
            walk(e.get('elements') or [])
    walk(tree)
    if mutate:
        mutate(tree)
    php = SEED_PHP % {'tree': json.dumps(tree), 'type': post_type, 'status': status, 'author': author or ADMIN_ID,
                      'label': str(counter[0]), 'stamp': STAMP, 'src': src}
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
        return find(json.loads(raw) if isinstance(raw, str) else raw)
    except ValueError:
        return None


def setting(pid, wid, key):
    return ((node(meta(pid), wid) or {}).get('settings') or {}).get(key)


RENDER_PHP = r"""echo wp_json_encode(['html' => \Elementor\Plugin::$instance->frontend->get_builder_content_for_display(%(pid)d)]);"""


def html_of(pid, published=True):
    if published:
        try:
            with urllib.request.urlopen(f'{BASE}/?page_id={pid}', timeout=60) as r:
                return r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.read().decode('utf-8', 'replace')
    return wp_json(RENDER_PHP % {'pid': pid}).get('html', '')


def front(pid, wid, published=True):
    html = html_of(pid, published)
    m = re.search(r'<(\w+)[^>]*data-(?:interaction-)?id="' + re.escape(wid) + r'"[^>]*>', html)
    if not m:
        return {'present': False}
    return {'present': True, 'tag': m.group(1), 'html': re.sub(r'\s+', ' ', html[m.start():m.start() + 600])}


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
    return wp_json(INSPECT_PHP % {'pid': pid})


EDITOR_SAVE_PHP = r"""
$d = \Elementor\Plugin::$instance->documents->get(%(pid)d, false);
try { $ed = $d->get_elements_raw_data(null, true); $r = $d->save(['elements' => $ed]); echo wp_json_encode(['save' => $r ? 'ok' : 'refused']); }
catch (\Throwable $t) { echo wp_json_encode(['save' => 'THROWS ' . $t->getMessage()]); }
"""


def editor_save(pid):
    """Load the page the way Elementor's editor does and save it again with Elementor ("Update")."""
    return wp_json(EDITOR_SAVE_PHP % {'pid': pid}).get('save')


OUTSIDE_EDIT_PHP = r"""
$d = \Elementor\Plugin::$instance->documents->get(%(pid)d, false);
$ed = $d->get_elements_raw_data(null, true);
$set = function(array &$els) use (&$set) { foreach ($els as &$e) { if (($e['id'] ?? '') === '%(wid)s') { $e['settings']['%(key)s'] = json_decode('%(value)s', true); } if (!empty($e['elements'])) $set($e['elements']); } };
$set($ed);
echo wp_json_encode(['save' => $d->save(['elements' => $ed]) ? 'ok' : 'refused']);
"""


def outside_edit(pid, wid, key, value):
    """A person saving a change in Elementor's editor."""
    return wp_json(OUTSIDE_EDIT_PHP % {'pid': pid, 'wid': wid, 'key': key, 'value': json.dumps(value).replace("'", "\\'")}).get('save')


def plain(pid, wid):
    r = call('get_elementor_widget', {'id': pid, 'widget_id': wid})
    return r.get('settings_plain') or {}


# ── A. the four fix types ──────────────────────────────────────────────────

print('A. Fix types on atomic elements')
changes = {}
for status in ('publish', 'draft'):
    pub = status == 'publish'
    p = seed(ATOMIC_SRC, status=status)
    original = tree_of(p)
    label = f'[{status}]'
    r = call('set_heading_level', {'id': p, 'widget_id': 'a7e4d01', 'level': 'h3'})
    record(f'{label} heading level (stored tag): converted, live, verified',
           ok(r) and r.get('schema') == 'atomic' and r.get('state') == 'live' and r.get('applied_via') == 'iato-native'
           and r.get('applied') == [{'key': 'tag', 'from': 'h1', 'to': 'h3'}] and setting(p, 'a7e4d01', 'tag') == env('string', 'h3'), r)
    changes.setdefault(status, []).append((p, r.get('change_receipt', {}).get('change_id'), 'a7e4d01', 'tag'))
    r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d02', 'key': 'tag', 'value': 'h4'})
    record(f'{label} heading level (defaulted tag): added', ok(r) and r.get('applied_patch', [{}])[0].get('op') == 'add' and setting(p, 'a7e4d02', 'tag') == env('string', 'h4'), r)
    changes[status].append((p, r.get('change_receipt', {}).get('change_id'), 'a7e4d02', 'tag'))
    r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'title': 'Tom & Jerry'}})
    record(f'{label} heading title: stored as Elementor stores it (&amp;), not reported as sanitised',
           ok(r) and setting(p, 'a7e4d01', 'title') == env('escaped-html', 'Tom &amp; Jerry') and 'sanitized_paths' not in r, r)
    changes[status].append((p, r.get('change_receipt', {}).get('change_id'), 'a7e4d01', 'title'))
    r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d03', 'settings_patch': {'paragraph': 'New paragraph with <strong>bold</strong>'}})
    record(f'{label} paragraph text', ok(r) and setting(p, 'a7e4d03', 'paragraph') == env('escaped-html', 'New paragraph with <strong>bold</strong>'), r)
    r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d07', 'key': 'text', 'value': 'Button text'})
    record(f'{label} button text', ok(r) and setting(p, 'a7e4d07', 'text') == env('escaped-html', 'Button text'), r)
    r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'link': {'destination': 'https://example.com/fixed'}}})
    link = setting(p, 'a7e4d06', 'link') or {}
    record(f'{label} link URL: spliced, isTargetBlank kept',
           ok(r) and link.get('value', {}).get('destination') == env('url', 'https://example.com/fixed')
           and link.get('value', {}).get('isTargetBlank') == node(original, 'a7e4d06')['settings']['link']['value']['isTargetBlank'], [r, link])
    changes[status].append((p, r.get('change_receipt', {}).get('change_id'), 'a7e4d06', 'link'))
    r = call('update_alt_text', {'post_id': p, 'element_id': 'a7e4d05', 'alt': 'Fixed external alt'})
    src = ((setting(p, 'a7e4d05', 'image') or {}).get('value') or {}).get('src', {}).get('value', {})
    record(f'{label} external image alt (update_alt_text with element_id): url and size kept',
           ok(r) and src.get('alt') == env('string', 'Fixed external alt') and src.get('url') == env('url', EXT)
           and (setting(p, 'a7e4d05', 'image') or {}).get('value', {}).get('size') == env('string', 'full'), [r, src])
    changes[status].append((p, r.get('change_receipt', {}).get('change_id'), 'a7e4d05', 'image'))
    sp = plain(p, 'a7e4d05')
    record(f'{label} settings_plain re-read', sp.get('image', {}).get('src', {}).get('alt') == 'Fixed external alt', sp)

    fe = {w: front(p, w, pub) for w in ('a7e4d01', 'a7e4d02', 'a7e4d03', 'a7e4d05', 'a7e4d06', 'a7e4d07')}
    record(f'{label} rendered: h3 and h4 with the new title', fe['a7e4d01'].get('tag') == 'h3' and 'Tom &amp; Jerry' in fe['a7e4d01'].get('html', '') and fe['a7e4d02'].get('tag') == 'h4', fe['a7e4d01'])
    record(f'{label} rendered: paragraph, button text', 'New paragraph with <strong>bold</strong>' in fe['a7e4d03'].get('html', '') and 'Button text' in fe['a7e4d07'].get('html', ''), [fe['a7e4d03'], fe['a7e4d07']])
    record(f'{label} rendered: link href and new tab', 'href="https://example.com/fixed"' in fe['a7e4d06'].get('html', '') and 'target="_blank"' in fe['a7e4d06'].get('html', ''), fe['a7e4d06'])
    record(f'{label} rendered: image with the new alt', 'alt="Fixed external alt"' in fe['a7e4d05'].get('html', '') and EXT in fe['a7e4d05'].get('html', ''), fe['a7e4d05'])
    record(f'{label} every element validates in Elementor', set(elementor_validation(p).values()) == {'ok'}, elementor_validation(p))
    record(f'{label} Elementor saves the page afterwards', editor_save(p) == 'ok')

p = seed(ATOMIC_SRC)
before_lib = wp('post', 'meta', 'get', '5', '_wp_attachment_image_alt', user=None)
before_meta = meta(p)
r = call('update_alt_text', {'post_id': p, 'element_id': 'a7e4d04', 'alt': 'Library alt via element'})
record('library image alt: routed to the attachment, page not written',
       r.get('routed_to') == 'attachment_alt' and r.get('scope') == 'all uses of attachment 5'
       and wp('post', 'meta', 'get', '5', '_wp_attachment_image_alt', user=None) == 'Library alt via element' and meta(p) == before_meta, r)
rb = call('rollback', {'change_id': r.get('change_receipt', {}).get('change_id', '')})
record('library image alt: rolled back through the image receipt', rb.get('success') and wp('post', 'meta', 'get', '5', '_wp_attachment_image_alt', user=None) == before_lib, rb)
r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d04', 'settings_patch': {'image': {'src': {'alt': 'X'}, 'size': 'large'}}})
record('library image alt with another change: refused', refused(r, 'atomic_library_alt_separate') and meta(p) == before_meta, r)
r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d04', 'settings_patch': {'image': {'src': {'url': EXT, 'alt': 'Now external'}}}})
src = ((setting(p, 'a7e4d04', 'image') or {}).get('value') or {}).get('src', {}).get('value', {})
record('a URL on a library image clears its attachment id', ok(r) and 'id' not in src and src.get('url') == env('url', EXT), [r, src])

# ── B. rollback ────────────────────────────────────────────────────────────

print('B. Rollback')
for status, rows in changes.items():
    for p, cid, wid, key in reversed(rows):
        want = (node(tree_of(ATOMIC_SRC), wid)['settings'] or {}).get(key)
        dry = call('rollback', {'change_id': cid, 'dry_run': True})
        before = meta(p)
        record(f'[{status}] rollback dry run {wid}.{key}: writes nothing', dry.get('dry_run') and dry.get('would_roll_back') and meta(p) == before, dry)
        rb = call('rollback', {'change_id': cid})
        record(f'[{status}] rollback {wid}.{key}: restored verbatim', rb.get('success') and setting(p, wid, key) == want, [rb, setting(p, wid, key), want])
p = seed(ATOMIC_SRC)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
cid = r.get('change_receipt', {}).get('change_id', '')
record('outside edit saved by Elementor', outside_edit(p, 'a7e4d01', 'tag', env('string', 'h5')) == 'ok')
rb = call('rollback', {'change_id': cid})
record('rollback after an outside edit: refused with the values', refused(rb, 'rollback_conflict')
       and rb.get('conflict_check', {}).get('current_value') == {'tag': 'h5'} and setting(p, 'a7e4d01', 'tag') == env('string', 'h5'), rb)
rb = call('rollback', {'change_id': cid, 'force': True})
record('rollback with force: restored', rb.get('success') and rb.get('conflict_check', {}).get('forced') and setting(p, 'a7e4d01', 'tag') == env('string', 'h1'), rb)

# ── C. refusals ────────────────────────────────────────────────────────────

print('C. Refusals write nothing')
p = seed(ATOMIC_SRC)
before = meta(p)
for name, tool, args, code in [
    ('invalid level h7', 'set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h7'}, 'atomic_invalid_settings'),
    ('classic header_size', 'set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'header_size', 'value': 'h3'}, 'atomic_prop_not_supported'),
    ('javascript: link', 'update_elementor_widget', {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'link': {'destination': 'javascript:alert(1)'}}}, 'atomic_url_rejected'),
    ('relative image URL', 'update_elementor_widget', {'id': p, 'widget_id': 'a7e4d05', 'settings_patch': {'image': {'src': {'url': '/wp-content/x.jpg'}}}}, 'atomic_url_rejected'),
    ('global classes', 'update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'classes': ['g-123']}}, 'atomic_prop_not_supported'),
    ('unknown link field', 'update_elementor_widget', {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'link': {'url': 'https://x.test/'}}}, 'atomic_prop_not_supported'),
]:
    r = call(tool, args)
    record(f'{name}: {code}', refused(r, code) and r.get('element_id') and 'Nothing was written' in r.get('message', ''), r)
record('refusals: nothing written', meta(p) == before)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'header_size', 'value': 'h3'})
record('header_size: names `tag`', 'the heading level is `tag`' in r.get('message', ''), r)

# ── D. forced Throwables ───────────────────────────────────────────────────

print('D. Forced Throwables')
p = seed(ATOMIC_SRC)
before = meta(p)
set_opt('iato_mcp_test_force_save_fail', 1)
set_opt('iato_mcp_test_force_save_fail_message', 'Settings validation failed. tag: invalid_value')
try:
    r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
    record('"Settings validation failed" at save: refused, nothing written', refused(r, 'elementor_validation_failed') and meta(p) == before, r)
    del_opt('iato_mcp_test_force_save_fail_message')
    r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
    record('another throw at save: the plugin stores the validated tree', r.get('elementor_save') == 'error' and r.get('stored_by') == 'plugin' and ok(r)
           and setting(p, 'a7e4d01', 'tag') == env('string', 'h3'), r)
    record('another throw at save: this page\'s CSS and element cache cleared', (r.get('css') or {}).get('element_cache') is True and (r.get('css') or {}).get('scope') == 'post', r.get('css'))
finally:
    del_opt('iato_mcp_test_force_save_fail', 'iato_mcp_test_force_save_fail_message')
before = meta(p)
set_opt('iato_mcp_test_throw_resolver', 1)
try:
    r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h4'})
finally:
    del_opt('iato_mcp_test_throw_resolver')
record('a throwing resolver hook: atomic_write_unavailable naming the failure, nothing written',
       refused(r, 'atomic_write_unavailable') and 'failed to load' in r.get('message', '') and meta(p) == before, r)
set_opt('iato_mcp_test_throw_in_tool', 1)
try:
    r = call('get_site_info', {})
finally:
    del_opt('iato_mcp_test_throw_in_tool')
record('a throw inside a tool: tool_exception, not a fatal', refused(r, 'tool_exception') and r.get('tool') == 'get_site_info' and 'test mu-plugin' in r.get('message', ''), r)

# ── E. Part 2 probe ────────────────────────────────────────────────────────

print('E. Untouched classic content survives atomic edits byte for byte')
HTML_WIDGET = {'id': 'h7m1001', 'elType': 'widget', 'widgetType': 'html',
               'settings': {'html': '<iframe src="https://maps.example.com/embed?q=b2l" width="300"></iframe><script>window.iatoProbe = 1;</script>'}, 'elements': []}


def probe_tree(tree):
    tree[0]['elements'].append(HTML_WIDGET)
    for e in tree[0]['elements']:
        if e['id'] == 'c1a5501':
            e['settings']['title'] = 'Tom & Jerry'


for who in ('key', 'oauth', 'admin_ap', 'author_ap'):
    author = AUTHOR_ID if who == 'author_ap' else ADMIN_ID
    ptype = 'post' if who == 'author_ap' else 'page'
    p = seed(MIXED_SRC, author=author, post_type=ptype, mutate=probe_tree)
    seeded = tree_of(p)
    del_opt('iato_mcp_test_cap_during_save', 'iato_mcp_test_cap_at_shutdown')
    r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'title': '<b onclick="steal()">Hi</b> & bye<script>alert(1)</script>'}}, who=who)
    after = tree_of(p)
    content = lambda n: json.dumps({k: (n or {}).get(k) for k in ('id', 'elType', 'widgetType', 'settings')}, sort_keys=True)
    same = all(content(node(after, w)) == content(node(seeded, w)) for w in ('h7m1001', 'c1a5501', 'c1a5502', 'c1a5503', 'c1a5504'))
    record(f'[{who}] atomic edit written ({r.get("stored_by")}, {r.get("skipped_reason") or "Elementor saved"})', ok(r), r)
    record(f'[{who}] classic HTML widget (iframe, script) and "Tom & Jerry" byte for byte', same, [node(after, 'h7m1001'), node(after, 'c1a5501')])
    title = ((node(after, 'a7e4d01') or {}).get('settings') or {}).get('title', {}).get('value', '')
    record(f'[{who}] script and onclick in the atomic value stripped', 'script' not in title and 'onclick' not in title and 'Hi' in title, title)
    record(f'[{who}] unfiltered_html is off at the end of the request', opt('iato_mcp_test_cap_at_shutdown') == '0', opt('iato_mcp_test_cap_at_shutdown'))
    during = opt('iato_mcp_test_cap_during_save')
    if who == 'author_ap':
        record('[author_ap] inside Elementor\'s save (if it ran) the Author does not hold unfiltered_html', during in ('', '0'), during)
    elif r.get('elementor_save') is True:
        record(f'[{who}] inside Elementor\'s save the administrator holds unfiltered_html', during == '1', during)

# Review B1: Elementor's kses for a user without unfiltered_html would turn
# the & of an untouched link URL of the edited element into &amp;.
p = seed(ATOMIC_SRC, author=AUTHOR_ID, post_type='post',
         damage={'a7e4d06': {'link': env('link', {'destination': env('url', 'https://example.com/go?a=1&b=2'), 'isTargetBlank': env('boolean', True)})}})
r = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'text': 'Author text'}}, who='author_ap')
link = (setting(p, 'a7e4d06', 'link') or {}).get('value', {}).get('destination')
record('[author_ap] an untouched link URL of the edited element keeps its & (no kses from Elementor\'s save)', ok(r) and link == env('url', 'https://example.com/go?a=1&b=2')
       and setting(p, 'a7e4d06', 'text') == env('escaped-html', 'Author text'), [r.get('skipped_reason'), link])

# ── F. permissions ─────────────────────────────────────────────────────────

print('F. Permissions')
p = seed(ATOMIC_SRC)
before = meta(p)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'}, who='author_ap')
record('Author Application Password on an admin page: refused, nothing written', r.get('_isError') and meta(p) == before, r)
wp('option', 'update', 'iato_mcp_key_owner', '0', user=None)
try:
    r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
finally:
    wp('option', 'update', 'iato_mcp_key_owner', OWNER, user=None)
record('unbound key: refused (assign an owner), nothing written', r.get('_isError') and 'not assigned to a WordPress user' in r.get('text', r.get('message', '')) and meta(p) == before, r)
record('key owner restored', opt('iato_mcp_key_owner') == OWNER)

# ── G. concurrency ─────────────────────────────────────────────────────────

print('G. Concurrent editing')
p = seed(ATOMIC_SRC)
wp('eval', f"require_once ABSPATH . 'wp-admin/includes/post.php'; wp_set_post_lock({p});", user='author')
wp('eval', f"set_transient('_elementor_editor_unsaved_{p}', {AUTHOR_ID}, 300);", user=None)
before = meta(p)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
record('lock + unsaved changes in the editor: 409 elementor_editor_unsaved_changes', refused(r, 'elementor_editor_unsaved_changes') and r.get('post_id') == p and meta(p) == before, r)
wp('eval', f"delete_transient('_elementor_editor_unsaved_{p}');", user=None)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
record('lock alone: written', ok(r), r)
AUTOSAVE_PHP = r"""$r = _wp_put_post_revision(get_post(%(pid)d), true); global $wpdb; $t = gmdate('Y-m-d H:i:s', time() + 3600); $wpdb->update($wpdb->posts, ['post_modified_gmt' => $t, 'post_modified' => $t], ['ID' => $r]); clean_post_cache($r); echo $r;"""
wp('eval', AUTOSAVE_PHP % {'pid': p}, user='author')
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h4'})
record('a newer autosave by another user: written and reported', ok(r) and r.get('editor_autosave_pending') is True and (r.get('editor_autosave') or {}).get('user') == 'author', r)

# ── H. CSS ─────────────────────────────────────────────────────────────────

print('H. CSS and caches for the edited page only')
other = seed(ATOMIC_SRC)
target = seed(ATOMIC_SRC)
html_of(other)
html_of(target)
CSS_PHP = r"""$u = wp_upload_dir(); echo wp_json_encode(['meta' => (bool) get_post_meta(%(pid)d, '_elementor_css', true), 'file' => file_exists($u['basedir'] . '/elementor/css/post-%(pid)d.css'), 'element_cache' => (bool) get_post_meta(%(pid)d, '_elementor_element_cache', true)]);"""
o_before = wp_json(CSS_PHP % {'pid': other}, user=None)
t_before = wp_json(CSS_PHP % {'pid': target}, user=None)
record('setup: both pages have CSS after a page view', o_before.get('meta') and t_before.get('meta'), [o_before, t_before])
set_opt('iato_mcp_test_record_styles_clear', 1)
del_opt('iato_mcp_test_styles_cleared')
try:
    r = call('set_widget_setting', {'id': target, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
finally:
    del_opt('iato_mcp_test_record_styles_clear')
o_after = wp_json(CSS_PHP % {'pid': other}, user=None)
t_after = wp_json(CSS_PHP % {'pid': target}, user=None)
cleared = json.loads(opt('iato_mcp_test_styles_cleared') or '[]')
record('another page\'s CSS survives (no global flush)', o_after == o_before, [o_before, o_after])
record('the edited page\'s CSS and element cache are deleted', not t_after.get('meta') and not t_after.get('file') and not t_after.get('element_cache'), t_after)
record('Elementor\'s atomic styles cleared for this post only', ['local', target] in cleared and all(target in c for c in cleared), cleared)
record('the result says what was cleared', (r.get('css') or {}) == {'scope': 'post', 'post_css': True, 'element_cache': True, 'atomic_styles': True}, r.get('css'))

# ── I. repair ──────────────────────────────────────────────────────────────

print('I. Repair of pages damaged by IATO 1.13.0')
damage = {'a7e4d01': {'tag': 'h3'}, 'a7e4d06': {'link': {'destination': 'https://example.com/go'}},
          'a7e4d05': {'image': {'src': {'url': EXT, 'alt': 'External image alt text'}}}}
p = seed(ATOMIC_SRC, damage=damage)
v = elementor_validation(p)
record('setup: Elementor rejects the three damaged elements', all(v.get(w, '').startswith('Settings validation failed') for w in damage), v)
r1 = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h3'})
record('repair 1 (tag): written while two damaged elements remain; they are named',
       ok(r1) and r1.get('skipped_reason') == 'invalid_elements_on_page' and sorted(x['widget_id'] for x in r1.get('invalid_elements', [])) == ['a7e4d05', 'a7e4d06'], r1)
r2 = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d06', 'settings_patch': {'link': {'destination': 'https://example.com/go'}}})
record('repair 2 (link)', ok(r2), r2)
r3 = call('update_elementor_widget', {'id': p, 'widget_id': 'a7e4d05', 'settings_patch': {'image': {'src': {'url': EXT, 'alt': 'External image alt text'}}}})
record('repair 3 (image): Elementor saves the page again', ok(r3) and r3.get('elementor_save') is True, r3)
v = elementor_validation(p)
record('after the repair every element validates', set(v.values()) == {'ok'}, v)
fe6, fe5 = front(p, 'a7e4d06'), front(p, 'a7e4d05')
record('the link renders again', fe6.get('tag') == 'a' and 'href="https://example.com/go"' in fe6.get('html', ''), fe6)
record('the image renders again', EXT in fe5.get('html', '') and 'alt="External image alt text"' in fe5.get('html', ''), fe5)
record('Elementor saves the repaired page', editor_save(p) == 'ok')

# ── J. migrations ──────────────────────────────────────────────────────────

print("J. Elementor's load migrations")
old_title = {'$$type': 'html-v3', 'value': {'content': {'$$type': 'string', 'value': 'Old shape title'}, 'children': []}}
p = seed(ATOMIC_SRC, damage={'a7e4d02': {'title': old_title}})
STATE_PHP = r"""echo wp_json_encode(array_values(array_filter(array_keys(get_post_meta(%(pid)d)), fn($k) => str_starts_with($k, '_elementor_migrations_state'))));"""
before, state_before = meta(p), wp_json(STATE_PHP % {'pid': p}, user=None)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h4', 'dry_run': True})
record('dry run: would_migrate', r.get('dry_run') and r.get('migrated_by_elementor') == 'would_migrate', r)
record('dry run: _elementor_data and the migration state unchanged', meta(p) == before and wp_json(STATE_PHP % {'pid': p}, user=None) == state_before, state_before)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h7'})
record('a refused call does not store the migration either', refused(r, 'atomic_invalid_settings') and meta(p) == before, r)
r = call('set_widget_setting', {'id': p, 'widget_id': 'a7e4d01', 'key': 'tag', 'value': 'h4'})
record('write: migrated first, then changed', ok(r) and r.get('migrated_by_elementor') is True and (setting(p, 'a7e4d02', 'title') or {}).get('$$type') == 'escaped-html'
       and setting(p, 'a7e4d01', 'tag') == env('string', 'h4'), [r, setting(p, 'a7e4d02', 'title')])

# ── K. bulk ────────────────────────────────────────────────────────────────

print('K. Bulk')
p = seed(ATOMIC_SRC)
rev = call('get_elementor_data', {'id': p, 'format': 'summary'}).get('revision')
set_opt('iato_mcp_test_saves', 0)
b = call('update_elementor_widgets_bulk', {'updates': [
    {'post_id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': 'h3'}, 'if_revision': rev},
    {'post_id': p, 'widget_id': 'a7e4d03', 'settings_patch': {'paragraph': 'Bulk paragraph'}, 'if_revision': rev},
    {'post_id': p, 'widget_id': 'a7e4d07', 'settings_patch': {'text': 'Bulk button'}},
    {'post_id': p, 'widget_id': 'a7e4d01', 'settings_patch': {'tag': 'h9'}},
]})
res = b.get('results', [])
record('bulk: three rows written (same if_revision), the invalid row fails alone', b.get('succeeded') == 3 and b.get('failed') == 1 and res[3].get('error') == 'atomic_invalid_settings', b)
record('bulk: one Elementor save for the page', opt('iato_mcp_test_saves') == '1', opt('iato_mcp_test_saves'))
record('bulk: each row carries its element\'s receipt', all(x.get('change_receipt', {}).get('target_type') == 'elementor_atomic' for x in res[:3]), res)
record('bulk: stored', setting(p, 'a7e4d01', 'tag') == env('string', 'h3') and setting(p, 'a7e4d03', 'paragraph') == env('escaped-html', 'Bulk paragraph'))

# ── M. patch and update_elementor_data ─────────────────────────────────────

print('M. Whole-tree tools convert plain values')
p = seed(ATOMIC_SRC)
r = call('update_elementor_patch', {'id': p, 'ops': [{'op': 'replace', 'path': '/0/elements/0/settings/tag', 'value': 'h5'}]})
record('update_elementor_patch: plain tag converted, whole-document receipt', not r.get('_isError') and r.get('converted_elements') == ['a7e4d01']
       and setting(p, 'a7e4d01', 'tag') == env('string', 'h5') and r.get('change_receipt', {}).get('target_type') == 'elementor_widget', r)
tree = tree_of(p)
node(tree, 'a7e4d02')['settings']['title'] = 'Data title'
r = call('update_elementor_data', {'id': p, 'elementor_data': json.dumps(tree)})
record('update_elementor_data: plain title converted', not r.get('_isError') and setting(p, 'a7e4d02', 'title') == env('escaped-html', 'Data title'), r)
r = call('update_elementor_patch', {'id': p, 'ops': [{'op': 'replace', 'path': '/0/elements/0/settings/tag', 'value': 'h9'}]})
record('update_elementor_patch: invalid value refused', refused(r, 'atomic_invalid_settings'), r)

# ── N. classic writes ──────────────────────────────────────────────────────

print('N. Classic writes')
p = seed(CLASSIC_SRC)
r = call('update_elementor_widget', {'id': p, 'widget_id': 'c1a5501', 'settings_patch': {'title': 'Classic edited'}})
record('classic widget: Elementor saves it, schema classic, applied_via, state', r.get('stored_by') == 'elementor' and r.get('schema') == 'classic'
       and r.get('applied_via') == 'iato-native' and r.get('state') == 'live' and r.get('change_receipt', {}).get('target_type') == 'elementor_widget', r)
r = call('set_heading_level', {'id': p, 'widget_id': 'c1a5501', 'level': 'h4'})
record('classic set_heading_level: header_size', setting(p, 'c1a5501', 'header_size') == 'h4' and r.get('schema') == 'classic', r)

# ── S. the page check ──────────────────────────────────────────────────────

print('S. How often the page check used the plugin\'s own write')
print(f"  atomic writes: {writes['atomic']} on {len(writes['pages'])} pages")
for reason, n in writes['triggered'].items():
    print(f"  {reason}: {n} writes on {len(writes['trigger_pages'][reason])} pages")
if not writes['triggered']:
    print('  never')

print(f'\n{passes} passed, {len(fails)} failed')
for f in fails:
    print('  - ' + f)
sys.exit(1 if fails else 0)

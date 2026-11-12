"""update_elementor_patch on a real site (1.21.1). Run through patch-ops-live.sh.

Until 1.21.1, add at a list index replaced the element there (the field report: a widget
silently deleted), move to an index deleted one, and removing a middle element left a gap
stored as a JSON object. stored_matches_input compared key order, could not read a `/-`
op back, and read a quote rewrite as a change.
"""
import json
import os
import subprocess
import urllib.error
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
KEY = os.environ['KEY']
T = 'patch-ops-live'
results = []


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:900]}'))


def wp(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    lines = [line.strip('\r') for line in out.splitlines() if line and not line.startswith(('ℹ', '✔'))]
    return lines[-1] if lines else ''


def call(tool, args):
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + KEY})
    try:
        raw = urllib.request.urlopen(req, timeout=180).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    res = json.loads(raw).get('result', {})
    text = res.get('content', [{}])[0].get('text', '{}')
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


def refused(r, code):
    return bool(r.get('isError')) and code in r.get('_text', '')


def stored_raw(pid):
    return wp('post', 'meta', 'get', str(pid), '_elementor_data')


def stored(pid):
    return json.loads(stored_raw(pid) or '[]')


def ids(pid):
    return [e.get('id') for e in stored(pid)[0]['elements']]


def visitor(pid):
    try:
        with urllib.request.urlopen(f'{BASE}/?page_id={pid}&nocache={os.urandom(4).hex()}', timeout=120) as r:
            return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')


def widget(wid, text, html=None):
    return {'id': wid, 'elType': 'widget', 'widgetType': 'html', 'settings': {'html': html or f'<p>{T} {text}</p>'}, 'elements': []}


def page():
    p = call('create_post', {'title': f'{T} page', 'post_type': 'page', 'status': 'publish', 'content': f'<p>{T}</p>'})
    pid = p.get('id') or p.get('post_id')
    tree = [{'id': 'pq0c01', 'elType': 'container', 'settings': {}, 'isInner': False, 'elements': [widget('pq0a01', 'A'), widget('pq0b01', 'B'), widget('pq0c02', 'C')]}]
    r = call('update_elementor_data', {'id': pid, 'elementor_data': json.dumps(tree)})
    return pid, r


def patch(pid, ops, **extra):
    return call('update_elementor_patch', {'id': pid, 'ops': ops, **extra})


def rollback(r):
    cid = (r.get('change_receipt') or {}).get('change_id')
    return call('rollback', {'change_id': cid}) if cid else {'isError': True, '_text': 'no receipt'}


A, B, C, WID = 'pq0a01', 'pq0b01', 'pq0c02', 'pq0w01'
pid, made = page()
print(f'Elementor {wp("plugin", "get", "elementor", "--field=version")}, WordPress {wp("core", "version")}, page {pid}, elementor_save {made.get("elementor_save")}')
record('the page holds [A,B,C]', ids(pid) == [A, B, C], ids(pid))

# 1. The field report: [A,B,C] + add at index 1 => [A,W,B,C].
d = patch(pid, [{'op': 'add', 'path': '/0/elements/1', 'value': widget(WID, 'W')}], dry_run=True)
lst = (d.get('applied_patch') or [{}])[0].get('list') or {}
record('dry run: list shows [A,W,B,C] before anything is written', lst.get('ids_after') == [A, WID, B, C] and lst.get('index') == 1 and lst.get('count_before') == 3 and lst.get('count_after') == 4, d)
record('...and wrote nothing', ids(pid) == [A, B, C], ids(pid))
r = patch(pid, [{'op': 'add', 'path': '/0/elements/1', 'value': widget(WID, 'W')}])
record('add at index 1 inserts: stored [A,W,B,C]', ids(pid) == [A, WID, B, C], [ids(pid), r])
record('...and the response says so', ((r.get('applied_patch') or [{}])[0].get('list') or {}).get('ids_after') == [A, WID, B, C], r.get('applied_patch'))
status, html = visitor(pid)
positions = [html.find(f'{T} {x}</p>') for x in ('A', 'W', 'B', 'C')]
record('the page renders all four, in order', status == 200 and all(p > 0 for p in positions) and positions == sorted(positions), [status, positions])
x = rollback(r)
record('rollback puts back [A,B,C]', x.get('success') is True and ids(pid) == [A, B, C], [x, ids(pid)])

# 2. Past the end, refused; nothing written.
for extra in ({'dry_run': True}, {}):
    d = patch(pid, [{'op': 'add', 'path': '/0/elements/4', 'value': widget(WID, 'W')}], **extra)
    record(f'add past the end {extra or ""}: invalid_patch_path', refused(d, 'past the end'), d)
record('...and nothing was written', ids(pid) == [A, B, C], ids(pid))

# 3. Move to an index keeps every element (it deleted one until 1.21.1).
r = patch(pid, [{'op': 'move', 'from': '/0/elements/2', 'path': '/0/elements/0'}])
record('move C to the front: [C,A,B]', ids(pid) == [C, A, B], [ids(pid), r])
r = patch(pid, [{'op': 'move', 'from': '/0/elements/0', 'path': '/0/elements/2'}])
record('and back: [A,B,C]', ids(pid) == [A, B, C], [ids(pid), r])
d = patch(pid, [{'op': 'move', 'from': '/0', 'path': '/0/elements/0'}], dry_run=True)
record('moving an element into its own children is refused', refused(d, 'into itself'), d)

# 4. Remove a middle element, then address the shifted index in the same patch.
r = patch(pid, [{'op': 'remove', 'path': '/0/elements/1'}, {'op': 'replace', 'path': '/0/elements/1/settings/html', 'value': f'<p>{T} C2</p>'}])
tree = stored(pid)
record('remove then edit the shifted index: [A,C], C edited', r.get('isError') is not True and ids(pid) == [A, C] and tree[0]['elements'][1]['settings']['html'] == f'<p>{T} C2</p>', [r, tree])
record('...and the elements are stored as a JSON list', f'"elements":[{{"id":"{A}"' in stored_raw(pid).replace(' ', ''), stored_raw(pid)[:300])
x = rollback(r)
record('rollback puts back [A,B,C]', x.get('success') is True and ids(pid) == [A, B, C], [x, ids(pid)])

# 5. A `-` op is read back, even when a later op in the patch shifts it.
r = patch(pid, [{'op': 'add', 'path': '/0/elements/-', 'value': widget('pq0x01', 'X')}, {'op': 'add', 'path': '/0/elements/0', 'value': widget('pq0y01', 'Y')}])
ops = r.get('applied_patch') or [{}, {}]
record('[Y,A,B,C,X] stored', ids(pid) == ['pq0y01', A, B, C, 'pq0x01'], ids(pid))
record('the - op says what was stored', 'stored_matches_input' in ops[0] and ops[0].get('list', {}).get('index') == 3, ops[0])
record('...about X, not whatever sits at its old index', 'changed' not in (ops[0].get('stored_difference') or []), ops[0])
x = rollback(r)
record('rollback', x.get('success') is True and ids(pid) == [A, B, C], [x, ids(pid)])

# 6. Key order is not a difference: an element with exactly the keys Elementor stores, in reverse.
like = stored(pid)[0]['elements'][0]
copy = {k: like[k] for k in reversed(list(like.keys()))}
copy['id'] = 'pq0k01'
copy['settings'] = {'html': f'<p>{T} K</p>'}
d = patch(pid, [{'op': 'add', 'path': '/0/elements/-', 'value': copy}], dry_run=True)
record('dry run: an element with its keys reordered matches', (d.get('applied_patch') or [{}])[0].get('stored_matches_input') is True, d.get('applied_patch'))
r = patch(pid, [{'op': 'add', 'path': '/0/elements/-', 'value': copy}])
op = (r.get('applied_patch') or [{}])[0]
record('real write: it matches too (the field report: false, same bytes)', op.get('stored_matches_input') is True, {k: v for k, v in op.items() if k != 'value'})
rollback(r)

# 7. Elementor's save drops isInner from a widget: named, not "changed".
r = patch(pid, [{'op': 'add', 'path': '/0/elements/-', 'value': dict(widget('pq0i01', 'I'), isInner=False)}])
op = (r.get('applied_patch') or [{}])[0]
kept = [e for e in stored(pid)[0]['elements'] if e.get('id') == 'pq0i01']
if kept and 'isInner' not in kept[0]:
    record('a key the save dropped is keys_removed', op.get('stored_difference') == ['keys_removed'] and op.get('removed_paths') == ['/0/elements/3/isInner'], op)
else:
    record('this Elementor keeps isInner on a widget: it matches', op.get('stored_matches_input') is True, op)
rollback(r)

# 8. A quote rewrite is markup_normalized; a removed script is changed, with sanitized_report.
r = patch(pid, [{'op': 'replace', 'path': '/0/elements/0/settings/html', 'value': f"<p class='q'>{T} A &amp; b</p>"}])
op = (r.get('applied_patch') or [{}])[0]
notes = [e.get('note', '') for e in (r.get('sanitized_report') or [])]
if op.get('stored_matches_input') is True:
    # WordPress 6.8's kses keeps single quotes; 7.1's writes double quotes.
    record('single-quoted attributes are stored as sent here, and nothing is reported', not r.get('sanitized_report'), r.get('sanitized_report'))
else:
    record('single-quoted attributes rewritten: false, markup_normalized', op.get('stored_difference') == ['markup_normalized'], op)
    record('...and sanitized_report says nothing was removed (it was an empty entry)', notes and all('Nothing was removed' in n for n in notes) and not any(e.get('removed_tags') or e.get('removed_attributes') for e in r.get('sanitized_report') or []), r.get('sanitized_report'))
rollback(r)
r = patch(pid, [{'op': 'replace', 'path': '/0/elements/1/settings/html', 'value': f'<p>{T} B</p><script>alert(1)</script>'}])
op = (r.get('applied_patch') or [{}])[0]
record('a removed script: changed, and sanitized_report names it', 'changed' in (op.get('stored_difference') or []) and r.get('sanitized_report'), [op, r.get('sanitized_report')])
rollback(r)

# 9. return: minimal keeps the list and the difference.
r = patch(pid, [{'op': 'add', 'path': '/0/elements/1', 'value': widget(WID, 'W', f"<p class='m'>{T} W</p>")}], dry_run=True, **{'return': 'minimal'})
op = (r.get('applied_patch') or [{}])[0]
record('minimal: the list stays, values become hashes', op.get('list', {}).get('ids_after') == [A, WID, B, C] and 'value' not in op and op.get('value_sha256') and 'stored_value' not in op, op)
record('...and a difference, when there is one, stays', op.get('stored_matches_input') is True or op.get('stored_difference'), op)
record('the page is back to [A,B,C]', ids(pid) == [A, B, C], ids(pid))

failed = [name for name, ok in results if not ok]
print(f"# {len(results) - len(failed)} passed, {len(failed)} failed" + (': ' + '; '.join(failed) if failed else ''))
raise SystemExit(1 if failed else 0)

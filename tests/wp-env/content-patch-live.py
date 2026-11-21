"""Driven by content-patch-live.sh."""
import base64
import hashlib
import json
import os
import subprocess
import urllib.error
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
ADMIN_ID = int(os.environ['ADMIN_ID'])
CONTRIBUTOR_ID = int(os.environ['CONTRIBUTOR_ID'])
T = 'content-patch-live'
PASSWORDS = {'editor': os.environ['PW_EDITOR'], 'author': os.environ['PW_AUTHOR'], 'contributor': os.environ['PW_CONTRIBUTOR']}
results = []

ARTICLE = ('<p>The quick brown fox jumps over the lazy dog.</p>\n\n'
           + ''.join(f'<p>Paragraph {i}: filler text that makes this a long classic post, as a real article would be.</p>\n\n' for i in range(60))
           + '<p>Foxes are quick. Dogs are lazy.</p>')


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:600]}'))


def run(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    return [line.strip('\r') for line in out.splitlines() if line and not line.startswith(('ℹ', '✔'))]


def wp(*args):
    lines = run('wp', *args)
    return lines[-1] if lines else ''


def php(code):
    return wp('eval', code, '--user=admin')


def rpc(method, params, who='key'):
    auth = 'Bearer ' + KEY if 'key' == who else 'Basic ' + base64.b64encode(f'{who}:{PASSWORDS[who]}'.encode()).decode()
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth})
    return json.loads(urllib.request.urlopen(req, timeout=120).read())['result']


def call(tool, args, who='key'):
    res = rpc('tools/call', {'name': tool, 'arguments': args}, who)
    text = res['content'][0]['text']
    try:
        data = json.loads(text)
    except ValueError:
        data = {'text': text}
    if not isinstance(data, dict):
        data = {'value': data}
    data['_bytes'] = len(text.encode())
    if res.get('isError'):
        data['isError'] = True
        data['_text'] = text
    return data


def refused(result, code):
    return bool(result.get('isError')) and result.get('error') == code


def b64(text):
    return base64.b64encode(text.encode()).decode()


def fixture(title, content, status='publish', author=None, meta=None, post_type='post'):
    """A post whose content is stored byte for byte, past the save filters, so the checks start from known bytes."""
    meta_code = ''.join(f"update_post_meta($id,'{k}',wp_slash(base64_decode('{b64(v)}')));" for k, v in (meta or {}).items())
    return int(php(
        f"global $wpdb;$id=wp_insert_post(['post_title'=>'{T} {title}','post_content'=>'x','post_status'=>'{status}','post_type'=>'{post_type}','post_author'=>{author or ADMIN_ID}]);"
        f"$wpdb->update($wpdb->posts,['post_content'=>base64_decode('{b64(content)}')],['ID'=>$id]);clean_post_cache($id);{meta_code}echo $id;"
    ))


def stored(post_id):
    return base64.b64decode(php(f"echo base64_encode(get_post_field('post_content',{post_id},'raw'));")).decode('utf-8', 'surrogateescape')


def set_stored(post_id, content):
    php(f"global $wpdb;$wpdb->update($wpdb->posts,['post_content'=>base64_decode('{b64(content)}')],['ID'=>{post_id}]);clean_post_cache({post_id});")


def receipts(post_id):
    return int(php(f"global $wpdb;echo (int)$wpdb->get_var($wpdb->prepare(\"SELECT COUNT(*) FROM {{$wpdb->prefix}}iato_change_receipts WHERE post_id=%d\",{post_id}));") or 0)


def sha(text):
    return 'sha256:' + hashlib.sha256(text.encode('utf-8', 'surrogateescape')).hexdigest()


def visitor(post_id):
    try:
        with urllib.request.urlopen(f'{BASE}/?p={post_id}', timeout=60) as response:
            return response.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as error:
        return f'HTTP {error.code}'


def patch(post_id, ops, who='key', **extra):
    return call('update_post_content_patch', {'id': post_id, 'ops': ops, **extra}, who)


def rollback(result, who='key', **extra):
    return call('rollback', {'change_id': result['change_receipt']['change_id'], **extra}, who)


print('# the tool is listed')
names = [t['name'] for t in rpc('tools/list', {})['tools']]
record('update_post_content_patch is in tools/list', 'update_post_content_patch' in names)

print('# get_post gives the content hash')
P = fixture('article', ARTICLE)
g = call('get_post', {'id': P})
record('content_hash is the sha256 of the stored content', g.get('content_hash') == sha(ARTICLE) and g.get('content_bytes') == len(ARTICLE.encode()), {k: g.get(k) for k in ('content_hash', 'content_bytes')})

print('# replace, with a dry run first')
r0 = receipts(P)
op = [{'op': 'replace', 'find': 'the lazy dog.</p>', 'replace': 'the sleepy cat.</p>'}]
d = patch(P, op, dry_run=True, if_revision=g['content_hash'])
expected = ARTICLE.replace('the lazy dog.</p>', 'the sleepy cat.</p>')
record('the dry run previews hashes, sizes and the span', d.get('dry_run') is True and d.get('action') == 'would_update' and d.get('content_hash_before') == sha(ARTICLE)
       and d.get('content_hash_after') == sha(expected) and d.get('bytes_after') == len(expected.encode()) and d['spans'][0]['removed'] == 'the lazy dog.</p>' and d['spans'][0]['inserted'] == 'the sleepy cat.</p>', d)
record('the dry run writes nothing and has no cache object', stored(P) == ARTICLE and receipts(P) == r0 and 'cache' not in d, d)
r = patch(P, op, if_revision=g['content_hash'])
record('the write stores exactly the patched content', stored(P) == expected, r)
record('its hash is the one the dry run predicted', r.get('content_hash_after') == d.get('content_hash_after') == sha(stored(P)), r)
record('the response carries no content', 'Paragraph 30' not in json.dumps(r) and r['_bytes'] < 2500, r['_bytes'])
record('the span has about 80 characters of context each side', len(r['spans'][0]['context_after']) == 80 and r['spans'][0]['context_before'].endswith('jumps over '), r['spans'][0])
record('it has a slim receipt with an actor', r.get('change_receipt', {}).get('change_id', '').startswith('wr_') and 'before_value' not in r['change_receipt'] and r['change_receipt'].get('actor', {}).get('kind'), r.get('change_receipt'))
record('the receipt row keeps the full before and after', php(f"global $wpdb;$r=$wpdb->get_row($wpdb->prepare(\"SELECT before_value,after_value FROM {{$wpdb->prefix}}iato_change_receipts WHERE change_id=%s\",'{r['change_receipt']['change_id']}'),ARRAY_A);echo md5($r['before_value']).md5($r['after_value']);")
       == hashlib.md5(ARTICLE.encode()).hexdigest() + hashlib.md5(expected.encode()).hexdigest())
record('the cache object is there', isinstance(r.get('cache'), dict), r.get('cache'))
record('visitors see it', 'the sleepy cat.' in visitor(P))
record('a stale if_revision is refused with the current hash', refused(patch(P, op, if_revision=g['content_hash']), 'revision_conflict') and patch(P, op, if_revision=g['content_hash']).get('current_revision') == sha(expected))

print('# insert_before, insert_after and append, in order, in one call')
before = stored(P)
r = patch(P, [
    {'op': 'insert_before', 'anchor': '<p>Foxes are quick.', 'html': '<h2>Foxes</h2>\n'},
    {'op': 'insert_after', 'anchor': 'Dogs are lazy.</p>', 'html': '\n\n<p>Cats nap.</p>'},
    {'op': 'append', 'html': '\n\n<p>The end.</p>'},
])
want = before.replace('<p>Foxes are quick.', '<h2>Foxes</h2>\n<p>Foxes are quick.').replace('Dogs are lazy.</p>', 'Dogs are lazy.</p>\n\n<p>Cats nap.</p>') + '\n\n<p>The end.</p>'
record('all three apply, in order', stored(P) == want, r)
record('touching edits are one span', len(r.get('spans', [])) == 2 and r['spans'][1]['ops'] == [1, 2], r.get('spans'))
r_rb = rollback(r)
record('rollback restores the content byte for byte', not r_rb.get('isError') and stored(P) == before, r_rb)

print('# matching exactly once, all or nothing')
now = stored(P)
r = patch(P, [{'op': 'replace', 'find': 'quick', 'replace': 'fast'}])
record('several matches are refused with the count and where', refused(r, 'patch_multiple_matches') and r.get('matches') == 2 and len(r.get('match_contexts', [])) == 2, r)
r = patch(P, [{'op': 'replace', 'find': 'sleepy cat', 'replace': 'tired cat'}, {'op': 'insert_after', 'anchor': 'no such text', 'html': 'x'}])
record('no match is refused with the op index', refused(r, 'patch_no_match') and r.get('op_index') == 1 and r.get('matches') == 0, r)
record('and the first op was not applied either', stored(P) == now)
r = patch(P, [{'op': 'replace', 'find': 'Foxes are quick. Dogs', 'replace': 'Foxes are quick. Dogs'}])
record('a patch that changes nothing writes nothing', r.get('action') == 'no_change' and stored(P) == now and 'change_receipt' not in r, r)
record('bad ops are refused', refused(patch(P, []), 'invalid_patch_ops') and refused(patch(P, [{'op': 'delete', 'find': 'x'}]), 'invalid_patch_op'))

print('# the dry run predicts what is stored')
CASES = [
    ('a script is removed', '<p>s</p><script>alert(1)</script>'),
    ('aria and a button', '<button type="button" aria-expanded="false">Menu</button>'),
    ('an ampersand', '<p>Tom & Jerry</p>'),
    ('an emoji', '<p>Done 😀</p>'),
    ('a backslash', '<p>C:\\Users\\x and \\"quoted\\"</p>'),
    ('Windows line breaks', '<p>a</p>\r\n<p>b</p>'),
    ('an onclick', '<a href="https://example.com" onclick="x()">link</a>'),
]
for name, html in CASES:
    Q = fixture(f'predict {name}', '<p>Start.</p>')
    d = patch(Q, [{'op': 'append', 'html': html}], dry_run=True)
    if d.get('action') == 'no_change':
        record(f'{name}: a dry run of a change sanitising removes says no_change', stored(Q) == '<p>Start.</p>', d)
        continue
    r = patch(Q, [{'op': 'append', 'html': html}])
    record(f'{name}: the predicted hash is the stored one', d.get('content_hash_after') == r.get('content_hash_after') == sha(stored(Q)), {'dry': d, 'real': r, 'stored': stored(Q)})
    if 'script' in html or 'onclick' in html:
        record(f'{name}: it is reported as removed', 'sanitized_report' in r and r.get('stored_matches_patch') is False and 'script>' not in stored(Q) and 'onclick' not in stored(Q), r)
    if 'backslash' in name:
        record(f'{name}: backslashes are kept', '\\Users\\x' in stored(Q), stored(Q))

print('# sanitising that removes everything the patch added')
Q = fixture('script only', '<p>Start.</p>')
r = patch(Q, [{'op': 'insert_after', 'anchor': 'Start.', 'html': '<script>x()</script>'}])
record('is no_change, with the report', r.get('action') == 'no_change' and 'sanitized_report' in r and stored(Q) == '<p>Start.</p>' and receipts(Q) == 0, r)

print('# markup sanitising would remove from the post as it stands')
IFRAME = '<p>Watch this.</p>\n<iframe src="https://www.youtube.com/embed/x" width="560" height="315"></iframe>\n<p>Thanks for watching.</p>'
F = fixture('iframe', IFRAME)
op = [{'op': 'replace', 'find': 'Thanks for watching.', 'replace': 'Thanks!'}]
d = patch(F, op, dry_run=True)
record('a dry run is refused and names the iframe', refused(d, 'existing_markup_would_be_removed') and 'iframe' in d.get('removed_tags', []), d)
r = patch(F, op)
record('so is the write, and nothing changes', refused(r, 'existing_markup_would_be_removed') and stored(F) == IFRAME and receipts(F) == 0, r)
r = patch(F, op, allow_markup_removal=True)
record('allow_markup_removal writes it and says what went', r.get('action') == 'updated' and 'iframe' not in stored(F) and 'Thanks!' in stored(F) and 'iframe' in r.get('existing_markup_removed', {}).get('removed_tags', []), r)
r_rb = rollback(r)
record('rollback brings the iframe back, byte for byte', not r_rb.get('isError') and stored(F) == IFRAME, r_rb)

print('# rollback conflict')
Q = fixture('conflict', '<p>One.</p><p>Two.</p>')
r = patch(Q, [{'op': 'replace', 'find': 'Two.', 'replace': 'Deux.'}])
set_stored(Q, '<p>One.</p><p>Deux. Edited in wp-admin.</p>')
r_rb = rollback(r)
record('a later edit makes rollback a conflict', refused(r_rb, 'rollback_conflict') and 'Edited in wp-admin' in stored(Q), r_rb)

print('# idempotency')
Q = fixture('idempotency', '<p>One.</p>')
args = [{'op': 'append', 'html': '<p>Two.</p>'}]
r1 = patch(Q, args, idempotency_key=f'{T}-k1')
r2 = patch(Q, args, idempotency_key=f'{T}-k1')
record('a replay returns the first response and appends once', r2.get('idempotency_replay') is True and stored(Q) == '<p>One.</p><p>Two.</p>' and receipts(Q) == 1 and r2.get('change_receipt', {}).get('change_id') == r1['change_receipt']['change_id'], r2)
r3 = patch(Q, [{'op': 'append', 'html': '<p>Three.</p>'}], idempotency_key=f'{T}-k1')
record('the same key with other arguments is refused', refused(r3, 'idempotency_replay') and stored(Q) == '<p>One.</p><p>Two.</p>', r3)
d = patch(Q, [{'op': 'append', 'html': '<p>Four.</p>'}], dry_run=True, idempotency_key=f'{T}-k2')
r4 = patch(Q, [{'op': 'append', 'html': '<p>Four.</p>'}], idempotency_key=f'{T}-k2')
record('a dry run does not take the key from the write after it', d.get('dry_run') and r4.get('action') == 'updated', r4)

print('# page builders')
E = fixture('elementor', '<p>Elementor text.</p>', meta={'_elementor_edit_mode': 'builder', '_elementor_data': '[{"id":"a1","elType":"container","settings":[],"elements":[]}]'})
r = patch(E, [{'op': 'replace', 'find': 'Elementor text', 'replace': 'x'}])
record('Elementor posts are refused, pointing to the Elementor tools', refused(r, 'elementor_active') and 'update_elementor_widget' in r.get('use_tools', []) and stored(E) == '<p>Elementor text.</p>', r)
E_OFF = fixture('elementor off', '<p>Visitors see this.</p>', meta={'_elementor_data': '[{"id":"a1","elType":"container","settings":[],"elements":[]}]'})
r = patch(E_OFF, [{'op': 'replace', 'find': 'Visitors see this.', 'replace': 'Visitors see that.'}])
record('a page with Elementor turned off can be patched', r.get('action') == 'updated', r)
B = fixture('beaver', '<p>Beaver text.</p>', meta={'_fl_builder_enabled': '1'})
record('Beaver Builder posts are refused', refused(patch(B, [{'op': 'replace', 'find': 'Beaver text', 'replace': 'x'}]), 'beaver_builder_active') and stored(B) == '<p>Beaver text.</p>')
D = fixture('divi', '[et_pb_section][et_pb_text]<p>Divi text.</p>[/et_pb_text][/et_pb_section]', meta={'_et_pb_use_builder': 'on'})
r = patch(D, [{'op': 'replace', 'find': 'Divi text.', 'replace': 'Divi words.'}])
record('Divi text can be patched, with a notice', r.get('action') == 'updated' and 'Divi words.' in stored(D) and 'Divi' in r.get('notice', ''), r)
V = fixture('wpbakery', '[vc_row][vc_column][vc_column_text]<p>Bakery text.</p>[/vc_column_text][/vc_column][/vc_row]')
r = patch(V, [{'op': 'replace', 'find': 'Bakery text.', 'replace': 'Bakery words.'}])
record('WPBakery text can be patched, with a notice', r.get('action') == 'updated' and 'WPBakery' in r.get('notice', ''), r)
BLOCKS = '<!-- wp:paragraph -->\n<p>Block one.</p>\n<!-- /wp:paragraph -->'
G = fixture('blocks', BLOCKS)
r = patch(G, [{'op': 'append', 'html': '\n\n<!-- wp:paragraph -->\n<p>Block two.</p>\n<!-- /wp:paragraph -->'}])
record('block posts keep their block comments', stored(G) == BLOCKS + '\n\n<!-- wp:paragraph -->\n<p>Block two.</p>\n<!-- /wp:paragraph -->' and 'notice' not in r, r)

print('# who may patch what')
record('an Author cannot patch the administrator\'s post', patch(P, [{'op': 'append', 'html': 'x'}], 'author').get('isError') is True)
C = fixture('contributor draft', '<p>Draft.</p>', status='draft', author=CONTRIBUTOR_ID)
r = patch(C, [{'op': 'replace', 'find': 'Draft.', 'replace': 'Draft, edited.'}], 'contributor')
record('a Contributor can patch their own draft', r.get('action') == 'updated' and stored(C) == '<p>Draft, edited.</p>', r)
r = patch(P, [{'op': 'replace', 'find': 'Paragraph 7:', 'replace': 'Paragraph seven:'}], 'editor')
record('an Editor can patch it', r.get('action') == 'updated', r)

print('# update_post return: "minimal"')
U = fixture('update minimal', ARTICLE)
d = call('update_post', {'id': U, 'content': ARTICLE + '<p>More.</p>', 'return': 'minimal', 'dry_run': True})
wc = d.get('would_change', {}).get('content', {})
record('a minimal dry run gives the content as bytes and sha256', wc.get('before', {}).get('bytes') == len(ARTICLE.encode()) and 'sha256' in wc.get('after', {}) and 'Paragraph 30' not in json.dumps(d), d)
record('with the content hashes', d.get('content_hash_before') == sha(ARTICLE) and d.get('content_hash_after') == sha(ARTICLE + '<p>More.</p>'), d)
r = call('update_post', {'id': U, 'content': ARTICLE + '<p>More.</p>', 'title': f'{T} update minimal 2', 'return': 'minimal'})
recs = r.get('change_receipts', [])
record('a minimal write gives slim receipts', len(recs) == 2 and all('before_value' not in x and 'after_value' not in x and x.get('change_id') for x in recs) and r['_bytes'] < 2500, r)
record('and the content hashes', r.get('content_hash_after') == sha(stored(U)) and r.get('bytes_before') == len(ARTICLE.encode()), r)
content_receipt = next(x for x in recs if x.get('field') == 'content')
r_rb = call('rollback', {'change_id': content_receipt['change_id']})
record('its content receipt still rolls back', not r_rb.get('isError') and stored(U) == ARTICLE, r_rb)
r = call('update_post', {'id': U, 'content': ARTICLE + '<p>Again.</p>'})
record('full stays the default', r.get('change_receipt', {}).get('before_value') == ARTICLE and r.get('content_hash_before') == sha(ARTICLE), {k: r.get(k) for k in ('content_hash_before', 'change_receipt')})
record('an unknown return is refused', refused(call('update_post', {'id': U, 'title': 'x', 'return': 'tiny'}), 'invalid_return'))

passed = sum(1 for _, ok in results if ok)
print(f'\n{passed}/{len(results)} passed')
raise SystemExit(0 if passed == len(results) else 1)

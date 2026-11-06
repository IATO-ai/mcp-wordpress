"""Driven by write-safety-live.sh."""
import base64
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
PW = os.environ['PW_AUTHOR']
AUTHOR = os.environ['AUTHOR_ID']
T = 'write-safety-live'
results = []


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:400]}'))


def wp(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    lines = [l.strip('\r') for l in out.splitlines() if l and not l.startswith(('ℹ', '✔'))]
    return lines[-1] if lines else ''


def raw_call(tool, args, who='key'):
    auth = 'Bearer ' + KEY if who == 'key' else 'Basic ' + base64.b64encode(f'author:{PW}'.encode()).decode()
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth})
    return urllib.request.urlopen(req, timeout=120).read()


def call(tool, args, who='key'):
    res = json.loads(raw_call(tool, args, who))['result']
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
    return bool(result.get('isError')) and (result.get('error') == code or code in result.get('_text', ''))


def says(result, phrase):
    """Refused, and for the reason named: any other refusal is a failed check."""
    return bool(result.get('isError')) and phrase in result.get('_text', '')


def php(code):
    return wp('eval', code)


def state(pid):
    return json.loads(php(f"$p=get_post({pid});echo json_encode(['content'=>$p->post_content,'title'=>$p->post_title,'excerpt'=>$p->post_excerpt,'flag'=>get_post_meta({pid},'_elementor_edit_mode',true),'tree'=>get_post_meta({pid},'_elementor_data',true),'status'=>$p->post_status]);"))


def set_raw(pid, content=None, title=None, excerpt=None):
    """Stores text as a person with unfiltered_html would, past every filter."""
    cols = {k: v for k, v in (('post_content', content), ('post_title', title), ('post_excerpt', excerpt)) if v is not None}
    arr = ','.join(f"'{k}'=>base64_decode('{base64.b64encode(v.encode()).decode()}')" for k, v in cols.items())
    php(f"global $wpdb;$wpdb->update($wpdb->posts,[{arr}],['ID'=>{pid}]);clean_post_cache({pid});")


def set_meta(pid, key, value):
    php(f"update_post_meta({pid},'{key}',wp_slash(base64_decode('{base64.b64encode(value.encode()).decode()}')));")


def page(status='publish', ptype='page', content='start', author=None):
    args = ['post', 'create', f'--post_type={ptype}', f'--post_status={status}', f'--post_title={T} {ptype}', f'--post_content={content}', '--porcelain']
    if author:
        args.append(f'--post_author={author}')
    return int(wp(*args))


def visitor(pid):
    try:
        r = urllib.request.urlopen(f'{BASE}/?p={pid}&v={os.urandom(4).hex()}', timeout=60)
        return r.status, r.read().decode(errors='replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(errors='replace')


def tree(text='T', i='1', widget='heading', key='title'):
    return [{'id': 'aa' + i, 'elType': 'container', 'settings': {}, 'elements': [{'id': 'bb' + i, 'elType': 'widget', 'widgetType': widget, 'settings': {key: text}, 'elements': []}], 'isInner': False}]


LOOKS = chr(0xFF3F)  # the look-alike of the underscore that begins a protected key
HAND = '<p>Hand</p><iframe src="https://a.example/embed"></iframe><script>var a=1;</script>'
print('Elementor', wp('plugin', 'get', 'elementor', '--field=version'), 'WordPress', wp('core', 'version'), 'on', BASE)

print('# update_post: columns the call did not set stay as stored')
pid = page(ptype='page')
set_raw(pid, content=HAND, excerpt='<em>Kept</em><iframe src="/e"></iframe>', title='Kept <b>bold</b>')
d = call('update_post', {'id': pid, 'title': f'{T} one', 'dry_run': True})
record('the preview names only the title', list(d['would_change'].keys()) == ['title'], d)
r = call('update_post', {'id': pid, 'title': f'{T} one'})
s = state(pid)
record('a title change leaves the content alone', s['content'] == HAND, s)
record('and the excerpt', s['excerpt'] == '<em>Kept</em><iframe src="/e"></iframe>', s)
record('and has one receipt, for the title', r.get('change_receipt', {}).get('field') == 'title', r)
set_raw(pid, title='Kept <b>bold</b>')
r = call('update_post', {'id': pid, 'status': 'draft'})
s = state(pid)
record('a status change leaves the title and content alone', s['content'] == HAND and s['title'] == 'Kept <b>bold</b>' and s['status'] == 'draft', s)
x = call('rollback', {'change_id': r['change_receipt']['change_id']})
s = state(pid)
record('its rollback too', x.get('success') is True and s['content'] == HAND and s['title'] == 'Kept <b>bold</b>' and s['status'] == 'publish', [x, s])
call('update_post', {'id': pid, 'excerpt': 'New words'})
record('an excerpt change leaves the content alone', state(pid)['content'] == HAND)

print('# other posts saved during the write keep their own values')
menu = wp('menu', 'create', f'{T} menu', '--porcelain')
php(f"$o=(array)get_option('nav_menu_options',[]);$o['auto_add']=[{menu}];update_option('nav_menu_options',$o);")
try:
    fresh = page(status='draft', ptype='page', content='<p>The page words</p>')
    set_raw(fresh, excerpt='The page excerpt')
    r = call('update_post', {'id': fresh, 'status': 'publish'})
    item = json.loads(php(f"$i=wp_get_nav_menu_items({menu});$p=$i?get_post($i[0]->ID):null;echo json_encode($p?['content'=>$p->post_content,'excerpt'=>$p->post_excerpt,'parent'=>$i[0]->object_id]:null);") or 'null')
    record('publishing added the page to the menu', item is not None and int(item['parent']) == fresh, [r, item])
    record('the menu item did not take the page\'s content or excerpt', item is not None and item['content'].strip() == '' and item['excerpt'] == '', item)
    record('the page has its own', state(fresh)['content'] == '<p>The page words</p>' and state(fresh)['excerpt'] == 'The page excerpt', state(fresh))
finally:
    php("$o=(array)get_option('nav_menu_options',[]);$o['auto_add']=[];update_option('nav_menu_options',$o);")
    wp('menu', 'delete', str(menu))

print('# every save of the post, not just update_post')
built = page(ptype='page', content='<p>x</p>')
call('update_elementor_data', {'id': built, 'elementor_data': json.dumps(tree('Heading'))})
set_raw(built, title='Kept <span class="a">span</span>', excerpt='<em>E</em><iframe src="/e"></iframe>')
r = call('update_elementor_widget', {'id': built, 'widget_id': 'bb1', 'settings_patch': {'title_color': '#111111'}})
s = state(built)
record('an Elementor edit leaves the title and excerpt alone', r.get('change_receipt') is not None and s['title'] == 'Kept <span class="a">span</span>' and s['excerpt'] == '<em>E</em><iframe src="/e"></iframe>', [r, s])
x = call('rollback', {'change_id': r['change_receipt']['change_id']})
s = state(built)
record('and so does its rollback', x.get('success') is True and s['title'] == 'Kept <span class="a">span</span>' and s['excerpt'] == '<em>E</em><iframe src="/e"></iframe>', [x, s])
att = int(wp('post', 'create', '--post_type=attachment', '--post_status=inherit', '--post_mime_type=image/png', f'--post_title={T} att', '--post_content=x', '--porcelain'))
set_raw(att, content='<p>Description</p><iframe src="/d"></iframe>')
r = call('update_post', {'id': att, 'title': f'{T} att two'})
record('an attachment\'s description stays when its title changes', state(att)['content'] == '<p>Description</p><iframe src="/d"></iframe>', [r, state(att)])
wp('post', 'delete', str(att), '--force')
made = call('create_post', {'title': f'{T} made', 'content': 'x', 'status': 'publish', 'post_type': 'page'})
set_raw(made['id'], content=HAND)
x = call('rollback', {'change_id': made['change_receipt']['change_id'], 'force': True})
s = state(made['id'])
record('trashing a created post keeps what it holds', x.get('success') is True and s['status'] == 'trash' and s['content'] == HAND, [x, s])

revised = page(ptype='page', content='<p>x</p>')
set_raw(revised, content=HAND)
call('update_post', {'id': revised, 'title': f'{T} revised'})
newest = json.loads(php(f"$r=wp_get_post_revisions({revised},['posts_per_page'=>1]);$p=$r?reset($r):null;echo json_encode($p?$p->post_content:null);"))
record('the revision a title change makes holds the same content', newest == HAND, newest)
wp('post', 'delete', str(revised), '--force')
only = page(ptype='post', content='x')
IFRAME = '<iframe src="https://a.example/only"></iframe>'
php(f"global $wpdb;$wpdb->update($wpdb->posts,['post_title'=>'','post_excerpt'=>'','post_content'=>base64_decode('{base64.b64encode(IFRAME.encode()).decode()}')],['ID'=>{only}]);clean_post_cache({only});")
r = call('update_post', {'id': only, 'status': 'draft'})
s = state(only)
record('a post holding only an iframe can change status', not r.get('isError') and s['status'] == 'draft' and s['content'] == IFRAME, [r, s])
wp('post', 'delete', str(only), '--force')
made2 = call('create_post', {'title': f'{T} made two', 'content': 'x', 'status': 'publish', 'post_type': 'post'})
php(f"global $wpdb;$wpdb->update($wpdb->posts,['post_title'=>'','post_excerpt'=>'','post_content'=>base64_decode('{base64.b64encode(IFRAME.encode()).decode()}')],['ID'=>{made2['id']}]);clean_post_cache({made2['id']});")
x = call('rollback', {'change_id': made2['change_receipt']['change_id'], 'force': True})
s = state(made2['id'])
record('and can be rolled back to the trash', x.get('success') is True and s['status'] == 'trash' and s['content'] == IFRAME, [x, s])
wp('post', 'delete', str(made2['id']), '--force')

print('# rollback restores a post field as the database held it')
r = call('update_post', {'id': pid, 'content': '<p>new</p>'})
record('the new content is stored', state(pid)['content'] == '<p>new</p>', r)
x = call('rollback', {'change_id': r['change_receipt']['change_id']})
record('rollback puts the iframe and script back', x.get('success') is True and state(pid)['content'] == HAND, [x, state(pid)])
r = call('update_post', {'id': pid, 'title': f'{T} two'})
set_raw(pid, content=HAND)
x = call('rollback', {'change_id': r['change_receipt']['change_id']})
record('rolling back a title leaves the content alone', x.get('success') is True and state(pid)['title'] == 'Kept <b>bold</b>' and state(pid)['content'] == HAND, [x, state(pid)])

plain = page(ptype='page', content='<p>x</p>')
r = call('update_post', {'id': plain, 'excerpt': 'Now has one'})
x = call('rollback', {'change_id': r['change_receipt']['change_id']})
record('rolling back to an empty excerpt works', x.get('success') is True and state(plain)['excerpt'] == '' and state(plain)['content'] == '<p>x</p>', [x, state(plain)])
r = call('update_post', {'id': plain, 'title': ''})
if r.get('change_receipt'):
    x = call('rollback', {'change_id': r['change_receipt']['change_id']})
    record('and a title back from empty', x.get('success') is True, x)
wp('post', 'delete', str(plain), '--force')

print('# a meta key reaches only the row it names')
own = page(ptype='post', author=AUTHOR)
d = call('update_elementor_data', {'id': own, 'elementor_data': json.dumps(tree('Safe', widget='html', key='html'))}, 'author')
record('the Author can edit this post, so what follows tests the key and not the role', d.get('success') is True, d)
before = state(own)
payload = json.dumps(tree('WS_MARKER', widget='html', key='html'))
FORCE_OR_MISMATCH = ('force=true', 'treats meta key')
for key, why in [
    (LOOKS + 'elementor_data', FORCE_OR_MISMATCH),
    ('_' + chr(0xE9) + 'lementor_data', FORCE_OR_MISMATCH),
    ('_ELEMENTOR_DATA', ('cannot be written',)),
    ('_elementor_data ', ('cannot be written', 'outside ASCII')),
]:
    for extra in ({}, {'force': True}, {'dry_run': True}, {'force': True, 'dry_run': True}):
        d = call('update_post_meta', {'id': own, 'key': key, 'value': payload, **extra}, 'author')
        record(f'{key!r} {extra or ""}: refused', any(says(d, w) for w in why), d)
    d = call('update_post_meta', {'id': own, 'key': key, 'value': payload, 'force': True}, 'author')
    record(f'{key!r} with force: not by the role or the allowlist', any(says(d, w) for w in why) and 'force=true' not in d.get('_text', ''), d)
    record(f'{key!r}: the page data is untouched', state(own)['tree'] == before['tree'], state(own))
status, html = visitor(own)
record('nothing reached the page', 'WS_MARKER' not in html and status == 200, status)
for key in [LOOKS + 'elementor_edit_mode', LOOKS + 'wp_page_template']:
    wp('post', 'meta', 'update', str(own), '_' + key[1:], 'stored')
    d = call('update_post_meta', {'id': own, 'key': key, 'value': 'x', 'force': True}, 'author')
    record(f'{key!r} is refused while the real row exists', says(d, 'treats meta key'), d)
    record(f'{key!r} left the real row alone', wp('post', 'meta', 'get', str(own), '_' + key[1:]) == 'stored')
wp('post', 'meta', 'update', str(own), '_elementor_edit_mode', 'builder')
d = call('update_post_meta', {'id': own, 'key': LOOKS + 'elementor_edit_mode', 'value': None, 'force': True}, 'author')
record('a look-alike cannot delete the real row', says(d, 'treats meta key') and wp('post', 'meta', 'get', str(own), '_elementor_edit_mode') == 'builder', d)
d = call('update_post_meta', {'id': own, 'key': 'x' * 256, 'value': 'x'})
record('a key too long to store is refused, not reported as applied', says(d, 'at most 255'), d)
d = call('update_post_meta', {'id': own, 'key': 'ws_' + chr(0xE9) + 't' + chr(0xE9), 'value': 'x'})
record('a new key outside ASCII needs force', says(d, 'outside ASCII'), d)
d = call('update_post_meta', {'id': own, 'key': 'ws_' + chr(0xE9) + 't' + chr(0xE9), 'value': 'x', 'force': True})
record('and then is stored', d.get('after_value') == 'x', d)
d = call('update_post_meta', {'id': own, 'key': 'ws_plain', 'value': 'first'})
d2 = call('update_post_meta', {'id': own, 'key': 'ws_plain', 'value': 'second'})
record('an ordinary key still writes', d2.get('before_value') == 'first' and d2.get('after_value') == 'second', [d, d2])
src = page(ptype='page')
set_meta(src, 'ws_plain', 'copied')
d = call('update_elementor_data', {'id': own, 'elementor_data': json.dumps(tree('Inh')), 'inherit_settings_from': src, 'inherit_keys': [LOOKS + 'wp_page_template']})
record('inherit_keys has the same check', says(d, 'treats meta key'), d)
wp('post', 'delete', str(src), '--force')

print('# a backslash or another case does not reach the row')
B = chr(92)
own3 = page(ptype='post', author=AUTHOR)
call('update_elementor_data', {'id': own3, 'elementor_data': json.dumps(tree('Safe', widget='html', key='html'))}, 'author')
wp('post', 'meta', 'update', str(own3), '_thumbnail_id', '123')
before = state(own3)
for key in [B + '_elementor_data', '_elementor' + B + '_data', B + '_elementor' + B + '_element_cache', B + '_elementor_edit_mode', B + '_thumbnail_id', B + B + '_elementor_data']:
    for extra in ({}, {'force': True}, {'dry_run': True}):
        d = call('update_post_meta', {'id': own3, 'key': key, 'value': payload, **extra}, 'author')
        record(f'{key!r} {extra or ""}: a backslash is refused', says(d, 'holds a backslash'), d)
    d = call('update_post_meta', {'id': own3, 'key': key, 'value': None, 'force': True}, 'author')
    record(f'{key!r}: and cannot delete', says(d, 'holds a backslash'), d)
s = state(own3)
record('the tree and flag are untouched', s == before, [s, before])
record('other keys too', wp('post', 'meta', 'get', str(own3), '_thumbnail_id') == '123')
status, html = visitor(own3)
record('nothing reached the page', 'WS_MARKER' not in html, status)
d = call('update_post_meta', {'id': own3, 'key': '_ELEMENTOR_EDIT_MODE', 'value': 'builder'})
record('another case of the flag makes no row of its own', says(d, 'cannot be written'), d)
d = call('update_post_meta', {'id': own3, 'key': '_ELEMENTOR_EDIT_MODE', 'value': 'builder', 'force': True})
record('with force neither', says(d, 'cannot be written'), d)
plain_new = page(ptype='post', author=AUTHOR)
elsewhere = page(ptype='page')
wp('post', 'meta', 'update', str(elsewhere), 'ws_elsewhere_key', 'real')
wp('post', 'meta', 'update', str(elsewhere), '_ws_secret_token_like', 'real')
d = call('update_post_meta', {'id': plain_new, 'key': 'WS_ELSEWHERE_KEY', 'value': 'x'}, 'author')
record('a new key is refused when another spelling is stored elsewhere', says(d, 'treats meta key'), d)
d = call('update_post_meta', {'id': plain_new, 'key': 'ws_' + chr(0xE9) + 'lsewhere_key', 'value': 'x', 'force': True}, 'author')
record('an accented spelling too, with force', says(d, 'treats meta key'), d)
d = call('update_post_meta', {'id': plain_new, 'key': '_' + chr(0xE9) + 'lementor_data', 'value': 'x', 'force': True}, 'author')
record('and it cannot start a row that later catches the page data', says(d, 'treats meta key'), d)
d = call('update_post_meta', {'id': plain_new, 'key': 'ws_elsewhere_key', 'value': 'mine'}, 'author')
record('the real spelling still writes', d.get('after_value') == 'mine', d)
d = call('update_post_meta', {'id': plain_new, 'key': '0', 'value': 'x'})
record('a key of 0 is refused, as WordPress stores nothing', says(d, 'must not be empty or 0'), d)
wp('post', 'delete', str(plain_new), '--force')
wp('post', 'delete', str(elsewhere), '--force')
d = call('update_post_meta', {'id': own3, 'key': '_YOAST_WPSEO_TITLE', 'value': 'x'})
record('another case of an allowed prefix needs force', says(d, 'force=true'), d)

print('# a revision is not a target')
rev_page = page(ptype='page', content='<p>Hand written</p>')
call('update_elementor_data', {'id': rev_page, 'elementor_data': json.dumps(tree('Era A'))})
call('update_elementor_data', {'id': rev_page, 'elementor_data': json.dumps(tree('Era B'))})
rev = php(f"$r=wp_get_post_revisions({rev_page},['posts_per_page'=>1]);echo array_key_first($r);")
wp('post', 'meta', 'delete', str(rev_page), '_elementor_edit_mode')
call('update_post', {'id': rev_page, 'content': '<p>Hand again</p>'})
before = state(rev_page)
for tool, args in (
    ('update_post_meta', {'key': '_elementor_edit_mode', 'value': 'builder'}),
    ('update_post_meta', {'key': 'ws_rev', 'value': 'x'}),
    ('update_elementor_data', {'elementor_data': json.dumps(tree('Via rev'))}),
    ('update_elementor_widget', {'widget_id': 'bb1', 'settings_patch': {'title': 'x'}}),
    ('update_elementor_patch', {'ops': [{'op': 'replace', 'path': '/0/id', 'value': 'zz'}]}),
    ('set_widget_setting', {'widget_id': 'bb1', 'key': 'title', 'value': 'x'}),
    ('set_page_settings', {'settings': {'hide_title': True}}),
    ('update_post', {'title': 'x'}),
):
    d = call(tool, {'id': int(rev), **args})
    record(f'{tool} refuses a revision id', says(d, 'is a revision'), d)
s = state(rev_page)
record('the parent is as it was: Elementor off, content kept', s == before, [s, before])
d = call('get_post_meta', {'id': int(rev), 'key': '_elementor_data'})
record('a revision can still be read', not d.get('isError') and 'Era' in json.dumps(d), d)
record('nothing was added to the parent', wp('post', 'meta', 'get', str(rev_page), 'ws_rev') == '')

print('# trees Elementor cannot render are refused')
bad = ['{"a":1}', '{"elements":[]}', '[1]', '[null]', '["x"]', '[{}]', '[[]]', '[{"id":"a","elType":"widget","settings":{}}]', '[{"id":"a","elType":"container","settings":1}]',
       '[{"id":"a","elType":"container","settings":{},"elements":[1]}]', '{"iato_no_elementor_data":true,"post_content":"x"}']
shape = page(ptype='page', content='<p>Words</p>')
call('update_elementor_data', {'id': shape, 'elementor_data': json.dumps(tree('Healthy'))})
before = state(shape)
for j in bad:
    for extra in ({}, {'dry_run': True}):
        raw = raw_call('update_elementor_data', {'id': shape, 'elementor_data': j, **extra})
        ok_json = raw.lstrip().startswith(b'{"jsonrpc')
        d = call('update_elementor_data', {'id': shape, 'elementor_data': j, **extra})
        record(f'{j} {extra or ""}: elementor_invalid_tree, and the response is JSON', ok_json and refused(d, 'elementor_invalid_tree'), d)
s = state(shape)
record('the page is as it was', s == before, [s, before])
record('and still renders', visitor(shape)[0] == 200)
for op in ({'op': 'replace', 'path': '/0', 'value': 1}, {'op': 'add', 'path': '/iato_no_elementor_data', 'value': True}, {'op': 'remove', 'path': '/0/elType'}):
    for extra in ({}, {'dry_run': True}):
        d = call('update_elementor_patch', {'id': shape, 'ops': [op], **extra})
        record(f'patch {json.dumps(op)} {extra or ""}: elementor_invalid_tree', refused(d, 'elementor_invalid_tree'), d)
record('patches changed nothing', state(shape)['tree'] == before['tree'])
d = call('update_elementor_data', {'id': shape, 'elementor_data': tree('As an array')})
record('elementor_data sent as an array: refused plainly', says(d, 'must be a string'), d)
for j in ['[{"id":"a1","elType":"container","elements":[]}]', '[{"elType":"container","settings":{},"elements":[]}]', '[{"id":"a1","elType":"widget","widgetType":"nosuch-widget","settings":{"a":1},"elements":[]}]']:
    d = call('update_elementor_data', {'id': shape, 'elementor_data': j, 'dry_run': True})
    record(f'{j}: still allowed', not d.get('isError'), d)
for j in ['[{"id":"a1","elType":"container","settings":null,"elements":[]}]', '[{"id":"a1","elType":"widget","widgetType":"heading","settings":null,"elements":[]}]']:
    d = call('update_elementor_data', {'id': shape, 'elementor_data': j})
    record(f'{j}: elementor_invalid_tree', refused(d, 'elementor_invalid_tree'), d)
gaps = json.dumps([{'id': 'g1', 'elType': 'container', 'settings': {}, 'elements': {'0': tree('Gap one', 'x')[0]['elements'][0], '2': tree('Gap two', 'y')[0]['elements'][0]}}])
set_meta(shape, '_elementor_data', gaps)
record('a stored page with gaps in its numbering renders', visitor(shape)[0] == 200)
for extra in ({'dry_run': True}, {}):
    d = call('update_elementor_widget', {'id': shape, 'widget_id': 'bbx', 'settings_patch': {'title': 'Edited'}, **extra})
    record(f'and can be edited {extra or ""}', not d.get('isError'), d)
set_meta(shape, '_elementor_data', '[{"id":"z1"}]')
for extra in ({'dry_run': True}, {}):
    d = call('update_elementor_patch', {'id': shape, 'ops': [{'op': 'add', 'path': '/0/x', 'value': 1}], **extra})
    record(f'a stored page that cannot render: patch {extra or ""} says so', refused(d, 'elementor_invalid_tree'), d)
d = call('update_elementor_data', {'id': shape, 'elementor_data': json.dumps(tree('Repaired'))})
record('and a whole-tree write repairs it', d.get('success') is True, d)
record('so it renders', visitor(shape)[0] == 200)
wp('post', 'delete', str(shape), '--force')

print('# a receipt holds what was stored, not what the data claims')
own2 = page(ptype='post', author=AUTHOR, content='<p>Own</p>')
forged = json.dumps({'iato_no_elementor_data': True, 'post_content': '<p>f</p><script>window.WS_FORGE=1</script>', 'edit_mode': '', 'elementor_data': json.dumps(tree('WS_FORGE', widget='html', key='html'))})
d = call('update_elementor_data', {'id': own2, 'elementor_data': forged}, 'author')
record('an object is refused as a tree', refused(d, 'elementor_invalid_tree'), d)
# The state a site could already be in, from before this release.
set_meta(own2, '_elementor_data', forged)
wp('post', 'meta', 'update', str(own2), '_elementor_edit_mode', 'builder')
d = call('update_elementor_data', {'id': own2, 'elementor_data': json.dumps(tree('Second'))}, 'author')
record('a real tree over it is stored', d.get('success') is True, d)
cid = d['change_receipt']['change_id']
dry = call('rollback', {'change_id': cid, 'dry_run': True}, 'author')
record('its dry run promises no tree', dry.get('would_restore_revision') is None and dry.get('would_restore') == 'no_elementor_data', dry)
x = call('rollback', {'change_id': cid}, 'author')
s = state(own2)
status, html = visitor(own2)
record('rollback does not store the text inside as the tree', x.get('success') is True and s['tree'] == '', [x, s])
record('nor the script in the content', '<script' not in s['content'], s)
record('and nothing runs on the page', 'WS_FORGE=1</script>' not in html, status)

print('# a write to a post with no elements keeps what visitors saw')
flagged = page(ptype='page', content='<p>Hand written, shown to visitors</p>')
wp('post', 'meta', 'update', str(flagged), '_elementor_edit_mode', 'builder')
set_meta(flagged, '_elementor_data', '[]')
d = call('update_elementor_data', {'id': flagged, 'elementor_data': json.dumps(tree('Now built'))})
record('the write goes through', d.get('success') is True, d)
record('and replaces the content', 'Hand written' not in state(flagged)['content'], state(flagged))
x = call('rollback', {'change_id': d['change_receipt']['change_id']})
s = state(flagged)
record('rollback brings the content back', x.get('success') is True and s['content'] == '<p>Hand written, shown to visitors</p>', [x, s])
record('with the flag and the empty tree', s['flag'] == 'builder' and s['tree'] == '[]', s)
record('and says what it restored', x.get('restored') == 'content', x)
d = call('update_elementor_patch', {'id': flagged, 'ops': [{'op': 'add', 'path': '/0', 'value': tree('Patched')[0]}]})
record('a patch goes through', d.get('change_receipt') is not None and not d.get('isError'), d)
x = call('rollback', {'change_id': d['change_receipt']['change_id']})
s = state(flagged)
record('and its rollback brings the content back too', x.get('success') is True and s['content'] == '<p>Hand written, shown to visitors</p>' and s['tree'] == '[]', [x, s])
for unreadable in ('not json', 'null'):
    odd = page(ptype='page', content='<p>Shown to visitors</p>')
    wp('post', 'meta', 'update', str(odd), '_elementor_edit_mode', 'builder')
    set_meta(odd, '_elementor_data', unreadable)
    d = call('update_elementor_data', {'id': odd, 'elementor_data': json.dumps(tree('Now built'))})
    x = call('rollback', {'change_id': d['change_receipt']['change_id']})
    s = state(odd)
    record(f'unreadable data {unreadable!r}: rollback brings back the content and the data', x.get('success') is True and s['content'] == '<p>Shown to visitors</p>' and s['tree'] == unreadable, [x, s])
    wp('post', 'delete', str(odd), '--force')
blank = page(ptype='post', content='x')
php(f"global $wpdb;$wpdb->update($wpdb->posts,['post_title'=>'','post_content'=>'','post_excerpt'=>''],['ID'=>{blank}]);clean_post_cache({blank});")
d = call('update_elementor_data', {'id': blank, 'elementor_data': json.dumps(tree('On a blank post'))})
x = call('rollback', {'change_id': d['change_receipt']['change_id']})
s = state(blank)
record('a post with nothing in it rolls back to nothing', x.get('success') is True and s['content'] == '' and s['tree'] == '', [x, s])
again = call('rollback', {'change_id': d['change_receipt']['change_id']})
record('and the receipt is marked', refused(again, 'already_rolled_back'), again)
wp('post', 'delete', str(blank), '--force')

print('# turning Elementor back on over a later edit')
back = page(ptype='page', content='<p>x</p>')
call('update_elementor_data', {'id': back, 'elementor_data': json.dumps(tree('Built'))})
d = call('update_post_meta', {'id': back, 'key': '_elementor_edit_mode', 'value': None})
record('Elementor is turned off', state(back)['flag'] == '', d)
dry = call('rollback', {'change_id': d['change_receipt']['change_id'], 'dry_run': True})
record('with no edit since, nothing stands in the way', dry.get('would_roll_back') is True, dry)
php("sleep(2);")
upd = call('update_post', {'id': back, 'content': '<p>Edited in the WordPress editor</p>'})
x = call('rollback', {'change_id': d['change_receipt']['change_id']})
record('after an edit it is a conflict', refused(x, 'rollback_conflict') and state(back)['flag'] == '', x)
x = call('rollback', {'change_id': d['change_receipt']['change_id'], 'force': True})
record('force still can', x.get('success') is True and state(back)['flag'] == 'builder', x)

print('# term previews match the write')
wp('term', 'create', 'category', f'{T} Food & Drink')
d = call('create_term', {'taxonomy': 'category', 'name': f'{T} Food & Drink', 'dry_run': True})
real = call('create_term', {'taxonomy': 'category', 'name': f'{T} Food & Drink'})
record('a repeated name with & is refused both ways', d.get('isError') is True and real.get('isError') is True, [d, real])
other = wp('term', 'create', 'category', f'{T} Other', '--porcelain')
d = call('create_term', {'taxonomy': 'category', 'name': f'{T} Food & Drink', 'slug': f'{T}-other', 'dry_run': True})
real = call('create_term', {'taxonomy': 'category', 'name': f'{T} Food & Drink', 'slug': f'{T}-other'})
record('a repeated name with another term\'s slug is refused both ways', d.get('isError') is True and real.get('isError') is True, [d, real])
d = call('create_term', {'taxonomy': 'category', 'name': f'{T} Food & Drink', 'slug': f'{T}-unused', 'dry_run': True})
real = call('create_term', {'taxonomy': 'category', 'name': f'{T} Food & Drink', 'slug': f'{T}-unused'})
record('a repeated name with a free slug goes ahead both ways', not d.get('isError') and not real.get('isError'), [d, real])
tid = int(other)
d = call('update_term', {'term_id': tid, 'taxonomy': 'category', 'parent': tid, 'dry_run': True})
record('a term as its own parent is no change', d.get('action') == 'no_change', d)
d = call('update_term', {'term_id': tid, 'taxonomy': 'category', 'slug': '', 'dry_run': True})
record('an empty slug previews the slug the name gives', d.get('action') == 'no_change', d)
wp('term', 'update', 'category', str(tid), f'--name={T} A & B')
d = call('update_term', {'term_id': tid, 'taxonomy': 'category', 'name': f'{T} A & B', 'dry_run': True})
record('the same name with & is no change', d.get('action') == 'no_change', d)

print('# SEO text keeps what plugins read as variables')
active = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', 'plugin', 'list', '--status=active', '--field=name'], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
if 'wordpress-seo' not in active:
    wp('plugin', 'install', 'wordpress-seo', '--activate')
    if 'wordpress-seo' not in subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', 'plugin', 'list', '--status=active', '--field=name'], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout:
        wp('plugin', 'install', 'https://downloads.wordpress.org/plugin/wordpress-seo.26.9.zip', '--activate')
    open('/tmp/iato-write-safety-installed-yoast', 'w').close()
seo = page(ptype='page')
TITLE = '%%category%% %%date%% %%cf_price%% <b>Sale</b>'
CLEAN = '%%category%% %%date%% %%cf_price%% Sale'
d = call('update_seo_data', {'id': seo, 'title': TITLE, 'description': '%%date%% only', 'dry_run': True})
record('the preview keeps them', d['would_change']['title']['after'] == CLEAN and d['would_change']['description']['after'] == '%%date%% only', d)
r = call('update_seo_data', {'id': seo, 'title': TITLE, 'description': '%%date%% only'})
record('the write stores them', wp('post', 'meta', 'get', str(seo), '_yoast_wpseo_title') == CLEAN and wp('post', 'meta', 'get', str(seo), '_yoast_wpseo_metadesc') == '%%date%% only', r)
r2 = call('update_seo_data', {'id': seo, 'title': 'Replaced'})
rid = r2['change_receipt']['change_id'] if 'change_receipt' in r2 else r2['change_receipts'][0]['change_id']
x = call('rollback', {'change_id': rid})
record('rollback restores them', x.get('success') is True and wp('post', 'meta', 'get', str(seo), '_yoast_wpseo_title') == CLEAN, x)
read = call('get_seo_data', {'id': seo})
record('and the read shows them', read.get('title') == CLEAN or CLEAN in json.dumps(read), read)

print('# what update_post says about visitors')
classic = page()
d = call('update_post', {'id': classic, 'content': '<p>Edited</p>'})
record('with Elementor off for the post, it says visitors see the edit', 'do see' in d.get('notice', '') and 'will NOT' not in d.get('notice', ''), d)
built2 = page()
call('update_elementor_data', {'id': built2, 'elementor_data': json.dumps(tree('Notice'))})
d = call('update_post', {'id': built2, 'content': '<p>Edited</p>'})
record('with Elementor on, it says they do not', 'will NOT' in d.get('notice', ''), d)
for pid in (classic, built2):
    wp('post', 'delete', str(pid), '--force')

fails = [n for n, ok in results if not ok]
print(f'# {len(results) - len(fails)} passed, {len(fails)} failed')
if fails:
    print('failed:', fails)
sys.exit(1 if fails else 0)

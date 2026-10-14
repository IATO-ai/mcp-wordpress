#!/usr/bin/env python3
"""
Live checks for Part 3: receipts record who made each change (WordPress user,
credential kind, OAuth client); only that user or an administrator may roll a
change back; a rollback whose value has changed since is refused unless
force; dry_run reports without writing; Elementor changes roll back byte for
byte; the call log records the credential. Driven by rollback-actor-live.sh
(env: BASE, KEY, KEY_OWNER, PW_ADMIN, PW_EDITOR, PW_AUTHOR, OAUTH_TOKEN,
OAUTH_CLIENT, PREFIX, *_ID, fixtures). Exit 1 on any failure.
"""
import base64, hashlib, json, os, secrets, subprocess, sys, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
ROLLBACK = BASE + '/wp-json/iato-mcp/v1/rollback'
PREFIX = os.environ.get('PREFIX', 'wp_')
R = PREFIX + 'iato_change_receipts'
L = PREFIX + 'iato_mcp_call_log'
ADMIN, EDITOR, AUTHOR = (int(os.environ[k]) for k in ('ADMIN_ID', 'EDITOR_ID', 'AUTHOR_ID'))
AD, AP, APOST, CAT, TAG, MI = (int(os.environ[k]) for k in ('AUTHOR_DRAFT', 'ADMIN_PAGE', 'ADMIN_POST', 'CAT', 'TAG', 'MENU_ITEM'))
FX = json.loads(os.environ['FIXTURE'])
EP, H, HTML = int(FX['page_id']), FX['ids']['heading'], FX['ids']['html']
CLIENT = os.environ['OAUTH_CLIENT']
passes, fails = 0, []


def record(name, ok, detail=None):
    global passes
    if ok:
        passes += 1; print(f'  PASS  {name}')
    else:
        fails.append(name); print(f'  FAIL  {name}: {json.dumps(detail, default=str)[:700]}')


def wp(*args):
    r = subprocess.run(['npx', '@wordpress/env', 'run', 'cli', 'wp', *args], capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def q(sql):
    """One row of a SELECT as a list of strings ('NULL' for NULL)."""
    out = wp('db', 'query', sql, '--skip-column-names')
    line = out.splitlines()[-1] if out else ''
    return line.split('\t') if line else []


def x(sql):
    wp('db', 'query', sql)


def auth(who):
    if who == 'key':
        return 'Bearer ' + os.environ['KEY']
    if who == 'oauth':
        return 'Bearer ' + os.environ['OAUTH_TOKEN']
    return 'Basic ' + base64.b64encode(f"{who}:{os.environ['PW_' + who.upper()]}".encode()).decode()


def post(url, body, who):
    req = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST', headers={'Content-Type': 'application/json', 'Authorization': auth(who)})
    try:
        r = urllib.request.urlopen(req, timeout=120)
        return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def call(who, tool, args):
    st, raw = post(MSG, {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}, who)
    try:
        r = json.loads(raw)
    except ValueError:
        return {'_http': st, '_raw': raw[:300].decode(errors='replace')}
    if 'error' in r:
        return {'_http': st, 'error': r['error']}
    res = r.get('result', {}); text = res.get('content', [{}])[0].get('text', '')
    try:
        body = json.loads(text)
    except ValueError:
        body = {'text': text}
    if res.get('isError'):
        return {'isError': True, 'text': text, 'body': body if isinstance(body, dict) else {}}
    return body


def rest_rollback(who, change_id, before_value, **extra):
    st, raw = post(ROLLBACK, {'change_id': change_id, 'before_value': before_value, **extra}, who)
    try:
        return st, json.loads(raw)
    except ValueError:
        return st, {'_raw': raw[:300].decode(errors='replace')}


def receipt(r, field=None):
    rs = [r['change_receipt']] if 'change_receipt' in r else r.get('change_receipts', [])
    for one in rs:
        if field is None or one.get('field') == field:
            return one
    return {}


def err(r):
    return (r.get('body') or {}).get('error') if r.get('isError') else None


def ok(r):
    return isinstance(r, dict) and not r.get('isError') and 'error' not in r and '_http' not in r


def title(pid):
    return wp('post', 'get', str(pid), '--field=post_title')


def seed(pid, target, field, before, after, kind=None, user=None):
    """Insert a receipt row directly; kind None = recorded before 1.13.0 (no actor)."""
    cid = 'wr_' + secrets.token_hex(8)
    lit = lambda v: 'NULL' if v is None else "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"
    cols = 'change_id, post_id, target_type, field, before_value, after_value, applied_at'
    vals = f"'{cid}', {pid if pid else 'NULL'}, '{target}', '{field}', {lit(before)}, {lit(after)}, UTC_TIMESTAMP()"
    if kind is not None:
        cols += ', actor_user_id, actor_kind'
        vals += f', {int(user)}, {lit(kind)}'
    x(f'INSERT INTO {R} ({cols}) VALUES ({vals})')
    return cid


def rev(text):
    return 'sha256:' + hashlib.sha256(text.encode()).hexdigest()


# ── A. who made the change ─────────────────────────────────────────────────
print('# A. receipts record who made the change')
r_author = call('author', 'update_post', {'id': AD, 'title': 'rollback-live by author'})
ra = receipt(r_author)
record('author: response receipt carries the actor', ra.get('actor', {}).get('user_id') == AUTHOR and ra['actor'].get('kind') == 'application_password' and ra['actor'].get('user_login') == 'author', ra)
row = q(f"SELECT actor_user_id, actor_kind, IFNULL(actor_client_id,'NULL') FROM {R} WHERE change_id='{ra.get('change_id')}'")
record('author: receipt row stores user, kind, no client', row == [str(AUTHOR), 'application_password', 'NULL'], row)

r_editor = call('editor', 'update_post', {'id': AD, 'title': 'rollback-live by editor'})
re_ = receipt(r_editor)
record('editor on the author\'s draft: actor is the editor', re_.get('actor', {}).get('user_id') == EDITOR, re_)

r_key = call('key', 'update_post', {'id': AP, 'title': 'rollback-live by site key'})
rk = receipt(r_key)
record('bound site key: actor is the key owner, kind site_key', rk.get('actor', {}).get('user_id') == int(os.environ['KEY_OWNER']) and rk['actor'].get('kind') == 'site_key', rk)

r_oauth = call('oauth', 'update_post', {'id': AP, 'title': 'rollback-live by oauth'})
ro = receipt(r_oauth)
record('OAuth token: actor is the approving admin, kind oauth, client id and name', ro.get('actor', {}).get('user_id') == ADMIN and ro['actor'].get('kind') == 'oauth' and ro['actor'].get('client_id') == CLIENT and ro['actor'].get('client_name') == 'Rollback Live Client', ro)
row = q(f"SELECT actor_user_id, actor_kind, actor_client_id FROM {R} WHERE change_id='{ro.get('change_id')}'")
record('OAuth receipt row stores the client id', row == [str(ADMIN), 'oauth', CLIENT], row)

calls = wp('db', 'query', f"SELECT IFNULL(credential_kind,'NULL'), IFNULL(client_id,'NULL'), auth_user_id FROM {L} WHERE tool_name='update_post' ORDER BY id DESC LIMIT 4", '--skip-column-names').splitlines()
record('call log rows carry credential kind and client (newest first: oauth, site key, editor, author)',
       calls == [f'oauth\t{CLIENT}\t{ADMIN}', f"site_key\tNULL\t{os.environ['KEY_OWNER']}", f'application_password\tNULL\t{EDITOR}', f'application_password\tNULL\t{AUTHOR}'], calls)

# ── B. same user or an administrator ───────────────────────────────────────
print('# B. rollback by the same user or an administrator')
r = call('author', 'rollback', {'change_id': re_['change_id']})
record("author cannot roll back the editor's change on the author's own draft", err(r) == 'rollback_actor_mismatch' and r['body'].get('actor', {}).get('user_login') == 'editor', r)
st, body = rest_rollback('author', re_['change_id'], re_.get('before_value'))
record('same refusal through the REST route (403)', st == 403 and body.get('code') == 'rollback_actor_mismatch', (st, body))

r = call('editor', 'rollback', {'change_id': re_['change_id']})
record('editor rolls back their own change', ok(r) and r.get('restored_value') == 'rollback-live by author' and r.get('actor', {}).get('user_id') == EDITOR and r.get('rolled_back_by', {}).get('user_id') == EDITOR and r.get('conflict_check', {}).get('status') == 'match', r)
row = q(f"SELECT rolled_back_by_user_id, rolled_back_by_kind FROM {R} WHERE change_id='{re_['change_id']}'")
record('receipt row records who rolled it back', row == [str(EDITOR), 'application_password'], row)
r = call('editor', 'rollback', {'change_id': re_['change_id']})
record('a second rollback is refused and names who rolled it back', err(r) == 'already_rolled_back' and r['body'].get('rolled_back_by', {}).get('user_id') == EDITOR, r)

r = call('editor', 'rollback', {'change_id': ro['change_id']})
record("editor cannot roll back the OAuth admin's change on the admin page (edit_others_pages is not enough)", err(r) == 'rollback_actor_mismatch', r)
r = call('admin', 'rollback', {'change_id': ro['change_id']})
record("admin Application Password rolls back the OAuth connection's change (administrator)", ok(r) and r.get('actor', {}).get('kind') == 'oauth' and r.get('rolled_back_by', {}).get('kind') == 'application_password', r)
r = call('oauth', 'rollback', {'change_id': rk['change_id']})
record("OAuth connection rolls back the site key's change (administrator)", ok(r) and r.get('rolled_back_by', {}).get('client_id') == CLIENT, r)

ra2 = receipt(call('author', 'update_post', {'id': AD, 'title': 'rollback-live by author 2'}))
r = call('key', 'rollback', {'change_id': ra2['change_id']})
record("bound site key (administrator) rolls back the author's change", ok(r) and r.get('restored_value') == 'rollback-live by author', r)

cur = title(AD)
legacy = seed(AD, 'post', 'title', 'rollback-live-seed legacy', cur)
r = call('editor', 'rollback', {'change_id': legacy})
record('receipt recorded before 1.13.0 (no actor): editor may roll it back under the object rules', ok(r) and r.get('actor') is None and title(AD) == 'rollback-live-seed legacy', r)
legacy_admin = seed(AP, 'post', 'title', 'rollback-live-seed legacy 2', title(AP))
r = call('author', 'rollback', {'change_id': legacy_admin})
record('...and the object rules still apply: author cannot (edit_post on the admin page)', bool(r.get('isError')) and 'edit_post' in r.get('text', ''), r)

print('# B2. unbound legacy site key (user 0)')
owner = os.environ['KEY_OWNER']
try:
    wp('option', 'update', 'iato_mcp_key_owner', '0')
    rz = receipt(call('key', 'update_post', {'id': AD, 'title': 'rollback-live by unbound key'}))
    record('unbound key: actor user 0, kind site_key, no login', rz.get('actor') == {'user_id': 0, 'user_login': None, 'kind': 'site_key'}, rz)
    r = call('author', 'rollback', {'change_id': rz['change_id']})
    record("author cannot roll back the unbound key's change; the message says so", err(r) == 'rollback_actor_mismatch' and 'site key (no user)' in r['body'].get('message', ''), r)
    ra3 = receipt(call('author', 'update_post', {'id': AD, 'title': 'rollback-live by author 3'}))
    r = call('key', 'rollback', {'change_id': ra3['change_id']})
    record("unbound key (full access) rolls back the author's change", ok(r) and r.get('rolled_back_by') == {'user_id': 0, 'user_login': None, 'kind': 'site_key'}, r)
    r = call('key', 'rollback', {'change_id': rz['change_id']})
    record('unbound key rolls back its own change', ok(r), r)
finally:
    wp('option', 'update', 'iato_mcp_key_owner', owner)
record('key owner restored', wp('option', 'get', 'iato_mcp_key_owner') == owner)

# ── C. conflict check ──────────────────────────────────────────────────────
print('# C. a changed value is not overwritten without force')
start = title(AD)
r1 = receipt(call('author', 'update_post', {'id': AD, 'title': 'rollback-live C1'}))
r2 = receipt(call('admin', 'update_post', {'id': AD, 'title': 'rollback-live C2'}))
r = call('author', 'rollback', {'change_id': r1['change_id'], 'dry_run': True})
record('dry run reports the conflict and would not roll back', ok(r) and r.get('dry_run') is True and r.get('would_roll_back') is False and r['conflict_check'].get('status') == 'conflict' and r['conflict_check'].get('current_value') == 'rollback-live C2' and r['conflict_check'].get('expected_value') == 'rollback-live C1', r)
row = q(f"SELECT IFNULL(rolled_back_at,'NULL') FROM {R} WHERE change_id='{r1['change_id']}'")
record('dry run wrote nothing', row == ['NULL'] and title(AD) == 'rollback-live C2', (row, title(AD)))
r = call('author', 'rollback', {'change_id': r1['change_id']})
record('rollback refused: rollback_conflict with current and expected values', err(r) == 'rollback_conflict' and r['body']['conflict_check'].get('current_value') == 'rollback-live C2' and 'force' in r['body'].get('message', ''), r)
st, body = rest_rollback('author', r1['change_id'], start)
record('REST route: 409 rollback_conflict', st == 409 and body.get('code') == 'rollback_conflict', (st, body))
r = call('author', 'rollback', {'change_id': r1['change_id'], 'force': True})
record('force rolls back anyway and reports it', ok(r) and r['conflict_check'].get('forced') is True and title(AD) == start, (r, title(AD)))
r = call('admin', 'rollback', {'change_id': r2['change_id']})
record("the admin's later change now conflicts too (its value was overwritten)", err(r) == 'rollback_conflict', r)

e1 = receipt(call('editor', 'update_post', {'id': AD, 'title': 'rollback-live E1'}))
e2 = receipt(call('editor', 'update_post', {'id': AD, 'title': 'rollback-live E2'}))
r = call('editor', 'rollback', {'change_id': e1['change_id']})
record('out of order: the earlier change is refused', err(r) == 'rollback_conflict', r)
r = call('editor', 'rollback', {'change_id': e2['change_id']})
record('latest first: E2 rolls back to E1', ok(r) and title(AD) == 'rollback-live E1', (r, title(AD)))
r = call('editor', 'rollback', {'change_id': e1['change_id']})
record('then E1 rolls back to the start', ok(r) and title(AD) == start, (r, title(AD)))

e3 = receipt(call('editor', 'update_post', {'id': AD, 'title': 'rollback-live E3'}))
wp('post', 'update', str(AD), '--post_title=rollback-live edited in wp-admin')
r = call('editor', 'rollback', {'change_id': e3['change_id']})
record('an edit made outside MCP is detected', err(r) == 'rollback_conflict' and r['body']['conflict_check'].get('current_value') == 'rollback-live edited in wp-admin', r)

amp = call('author', 'update_post', {'id': AD, 'title': 'rollback-live Tom & Jerry', 'content': '<p>Tom & Jerry <b>bold</b></p>'})
r = call('author', 'rollback', {'change_id': receipt(amp, 'title')['change_id'], 'dry_run': True})
record('a value WordPress filtered on save (& in a title) matches right after the write', ok(r) and r['conflict_check'].get('status') == 'match', (receipt(amp, 'title'), r))
r = call('author', 'rollback', {'change_id': receipt(amp, 'content')['change_id'], 'dry_run': True})
record('...and so does filtered post content', ok(r) and r['conflict_check'].get('status') == 'match', r)

pm = receipt(call('admin', 'update_post_meta', {'id': APOST, 'key': 'rollback_live_key', 'value': 'one'}))
wp('post', 'meta', 'update', str(APOST), 'rollback_live_key', 'two')
r = call('admin', 'rollback', {'change_id': pm['change_id']})
record('post meta changed outside MCP: conflict', err(r) == 'rollback_conflict' and r['body']['conflict_check'].get('current_value') == 'two', r)
r = call('admin', 'rollback', {'change_id': pm['change_id'], 'force': True})
record('force restores the before value (the key did not exist: deleted)', ok(r) and wp('post', 'meta', 'get', str(APOST), 'rollback_live_key') == '', r)

print('# C2. taxonomy receipts')
cats_before = sorted(wp('post', 'term', 'list', str(APOST), 'category', '--field=term_id').split())
tg = receipt(call('admin', 'assign_term', {'post_id': APOST, 'term_id': TAG, 'taxonomy': 'post_tag'}))
r = call('admin', 'rollback', {'change_id': tg['change_id']})
tags_after = wp('post', 'term', 'list', str(APOST), 'post_tag', '--field=term_id')
cats_after = sorted(wp('post', 'term', 'list', str(APOST), 'category', '--field=term_id').split())
record('rolling back a tag added to a post with no tags removes the tag', ok(r) and tags_after == '', (r, tags_after))
record('...and leaves the categories alone (the old rollback emptied them)', cats_after == cats_before and len(cats_before) == 2, (cats_before, cats_after))

old_name = wp('term', 'get', 'category', str(CAT), '--field=name')
ut = receipt(call('admin', 'update_term', {'term_id': CAT, 'taxonomy': 'category', 'name': 'rollback-live renamed'}))
record('update_term receipt names the term', json.loads(ut.get('before_value') or '{}').get('term_id') == CAT, ut)
r = call('admin', 'rollback', {'change_id': ut['change_id']})
record('rolling back update_term restores the name (it used to do nothing)', ok(r) and wp('term', 'get', 'category', str(CAT), '--field=name') == old_name, (r, old_name))
lt = seed(None, 'taxonomy', 'update_term', json.dumps({'name': 'x', 'slug': 'x', 'description': '', 'parent': 0, 'taxonomy': 'category'}), 'rollback-live-seed', 'application_password', ADMIN)
r = call('admin', 'rollback', {'change_id': lt})
record('an update_term receipt without the term id is refused as rollback_unavailable, not marked done', err(r) == 'rollback_unavailable' and q(f"SELECT IFNULL(rolled_back_at,'NULL') FROM {R} WHERE change_id='{lt}'") == ['NULL'], r)

mi = call('admin', 'update_menu_item_details', {'menu_item_id': MI, 'title': 'Tom & Jerry live'})
r = call('admin', 'rollback', {'change_id': receipt(mi)['change_id'], 'dry_run': True})
record('menu item title with & matches right after the write', ok(r) and r['conflict_check'].get('status') == 'match', (receipt(mi), r))

# ── D. Elementor ───────────────────────────────────────────────────────────
print('# D. Elementor changes roll back byte for byte')
PRISTINE = wp('post', 'meta', 'get', str(EP), '_elementor_data')
record('fixture: human HTML (iframe and script) on the page', '<iframe' in PRISTINE and '<script>var humanAuthored' in PRISTINE, PRISTINE[:200])
w1 = call('key', 'set_widget_setting', {'id': EP, 'widget_id': H, 'key': 'title', 'value': 'rollback-live First'})
w1r = receipt(w1)
record('Elementor response receipt is slim (no tree) and carries the actor', 'before_value' not in w1r and w1r.get('actor', {}).get('kind') == 'site_key', w1r)
row = q(f"SELECT SHA2(before_value, 256), after_value FROM {R} WHERE change_id='{w1r.get('change_id')}'")
record('receipt row: before = the stored tree as it was, after = revision hash', row and row[0] == hashlib.sha256(PRISTINE.encode()).hexdigest() and row[1] == w1.get('current_revision'), (row, w1.get('current_revision')))
AFTER_FIRST = wp('post', 'meta', 'get', str(EP), '_elementor_data')
w2r = receipt(call('key', 'set_widget_setting', {'id': EP, 'widget_id': H, 'key': 'title', 'value': 'rollback-live Second'}))
r = call('key', 'rollback', {'change_id': w1r['change_id']})
record('the earlier Elementor change conflicts (revision moved on)', err(r) == 'rollback_conflict' and str(r['body']['conflict_check'].get('current_value', '')).startswith('sha256:'), r)
r = call('key', 'rollback', {'change_id': w2r['change_id'], 'dry_run': True})
record('dry run reports the revision it would restore', ok(r) and r.get('would_restore_revision') == rev(AFTER_FIRST) and r['conflict_check'].get('status') == 'match', r)
r = call('key', 'rollback', {'change_id': w2r['change_id']})
record('latest change rolls back: the page is byte for byte as after the first change', ok(r) and r.get('restored_revision') == rev(AFTER_FIRST) and 'restored_value' not in r and wp('post', 'meta', 'get', str(EP), '_elementor_data') == AFTER_FIRST, r)
r = call('key', 'rollback', {'change_id': w1r['change_id']})
now = wp('post', 'meta', 'get', str(EP), '_elementor_data')
record('then the first: the human page comes back byte for byte, iframe and script included', ok(r) and now == PRISTINE, (r, now[:200]))
r = call('editor', 'rollback', {'change_id': w1r['change_id']})
record('rolled-back Elementor receipt cannot be applied again', err(r) in ('already_rolled_back', 'rollback_actor_mismatch'), r)
lh = seed(EP, 'elementor_widget', 'elementor:document:patch', 'sha256:' + '0' * 64, 'rollback-live-seed', 'site_key', 1)
r = call('key', 'rollback', {'change_id': lh})
record('an Elementor receipt from before 1.13.0 (hash only) is refused as rollback_unavailable, pointing at Elementor revisions', err(r) == 'rollback_unavailable' and 'Revisions' in r['body'].get('message', ''), r)
pt = call('key', 'update_elementor_patch', {'id': EP, 'ops': [{'op': 'replace', 'path': '/0/elements/0/settings/title', 'value': 'rollback-live patched'}]})  # container 0, heading first
if ok(pt) and receipt(pt).get('change_id'):
    r = call('key', 'rollback', {'change_id': receipt(pt)['change_id']})
    record('update_elementor_patch rolls back byte for byte', ok(r) and wp('post', 'meta', 'get', str(EP), '_elementor_data') == PRISTINE, r)
else:
    print(f'  INFO  update_elementor_patch not exercised: {json.dumps(pt)[:300]}')

# ── E. paths without a plugin dependency that the first version did not cover ─
print('# E. menu create/delete, create edited later, no prior Elementor data, REST Elementor rollback')
import time
MENU, PLAIN = int(os.environ['MENU']), int(os.environ['PLAIN_PAGE'])
mc = call('admin', 'create_menu_item', {'menu_id': MENU, 'title': 'rollback-live created item', 'url': 'https://example.com/created'})
mcr = receipt(mc)
r = call('admin', 'rollback', {'change_id': mcr.get('change_id'), 'dry_run': True})
record('menu item create: matches right after the write', ok(r) and r['conflict_check'].get('status') == 'match', (mc, r))
time.sleep(1.2)
wp('menu', 'item', 'update', str(mc.get('menu_item_id')), '--title=rollback-live renamed in wp-admin')
r = call('admin', 'rollback', {'change_id': mcr.get('change_id')})
record('menu item create: renamed afterwards, so deleting it conflicts', err(r) == 'rollback_conflict' and 'modified after it was created' in r['body'].get('message', ''), r)
r = call('admin', 'rollback', {'change_id': mcr.get('change_id'), 'force': True})
record('...force deletes it', ok(r) and wp('post', 'get', str(mc.get('menu_item_id')), '--field=ID') == '', r)
md = call('admin', 'create_menu_item', {'menu_id': MENU, 'title': 'rollback-live deleted item', 'url': 'https://example.com/deleted'})
dl = call('admin', 'delete_menu_item', {'menu_item_id': md.get('menu_item_id')})
r = call('admin', 'rollback', {'change_id': receipt(dl).get('change_id')})
items = wp('menu', 'item', 'list', str(MENU), '--fields=title,link', '--format=json')
record('menu item delete: rollback recreates it (match: still deleted)', ok(r) and r['conflict_check'].get('status') == 'match' and 'rollback-live deleted item' in items, (r, items))

cp = call('author', 'create_post', {'title': 'rollback-live created by author', 'content': '<p>x</p>'})
cpr = receipt(cp)
time.sleep(1.2)
wp('post', 'update', str(cp.get('id')), '--post_content=edited later in wp-admin')
r = call('author', 'rollback', {'change_id': cpr.get('change_id')})
record('post create: edited afterwards, so trashing it conflicts', err(r) == 'rollback_conflict', r)
r = call('author', 'rollback', {'change_id': cpr.get('change_id'), 'force': True})
record('...force trashes it', ok(r) and wp('post', 'get', str(cp.get('id')), '--field=post_status') == 'trash', r)

plain_content = wp('post', 'get', str(PLAIN), '--field=post_content')
record('fixture: the plain page carries a human-authored iframe', '<iframe' in plain_content, plain_content)
tree = json.dumps([{'id': 'pl1', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'pl2', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': 'rollback-live heading'}, 'elements': []}]}])
ed = call('key', 'update_elementor_data', {'id': PLAIN, 'elementor_data': tree})
edr = receipt(ed)
row = q(f"SELECT IFNULL(before_value, 'NULL') FROM {R} WHERE change_id='{edr.get('change_id')}'")
record('update_elementor_data on a page with no Elementor data: the receipt keeps the original content', ok(ed) and row != ['NULL'] and wp('post', 'get', str(PLAIN), '--field=post_content') != plain_content, (ed, row[:1]))
r = call('key', 'rollback', {'change_id': edr.get('change_id')})
data_left = wp('post', 'meta', 'get', str(PLAIN), '_elementor_data')
mode_left = wp('post', 'meta', 'get', str(PLAIN), '_elementor_edit_mode')
record('...rollback removes _elementor_data and _elementor_edit_mode', ok(r) and data_left == '' and mode_left == '' and r.get('restored') == 'no_elementor_data', (r, data_left[:80], mode_left))
content_now = wp('post', 'get', str(PLAIN), '--field=post_content')
record('...and puts the original post content back byte for byte, iframe and entity included', content_now == plain_content, (plain_content, content_now[:200]))

before_text = wp('post', 'meta', 'get', str(EP), '_elementor_data')
rr = receipt(call('key', 'set_widget_setting', {'id': EP, 'widget_id': H, 'key': 'title', 'value': 'rollback-live REST'}))
st, body = rest_rollback('key', rr.get('change_id'), 'not the tree')
record('REST route: an Elementor receipt with the wrong before_value is rejected (400)', st == 400 and 'mismatch' in json.dumps(body), (st, body))
st, body = rest_rollback('key', rr.get('change_id'), before_text)
record('REST route: with the whole stored tree as before_value it rolls back byte for byte', st == 200 and body.get('restored_revision') == rev(before_text) and wp('post', 'meta', 'get', str(EP), '_elementor_data') == before_text, (st, body))

print(f'\n# {passes} passed, {len(fails)} failed')
sys.exit(1 if fails else 0)

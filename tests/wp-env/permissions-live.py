#!/usr/bin/env python3
"""
Live permission checks for the security patch on fix/rollback-route-cap, run
against a wp-env site through the real MCP endpoint with Contributor, Author,
Editor and Administrator Application Passwords and the site Bearer key.

Driven by tests/wp-env/permissions-live.sh, which provisions the users and
fixtures and passes everything in as environment variables:
  BASE, KEY, PW_CONTRIBUTOR, PW_AUTHOR, PW_EDITOR, PW_ADMIN,
  ADMIN_PAGE (published page owned by admin), CONTRIB_DRAFT, CONTRIB_DRAFT2,
  AUTHOR_DRAFT, ATTACHMENT (admin's upload), ELEMENTOR_PAGE (admin's, published).
Exit code 1 if any check fails.
"""
import base64, json, os, re, sys, urllib.error, urllib.parse, urllib.request

BS = chr(92)
BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
ROLLBACK = BASE + '/wp-json/iato-mcp/v1/rollback'
KEY = os.environ['KEY']
IDS = {k: int(os.environ[k]) for k in ['ADMIN_PAGE', 'CONTRIB_DRAFT', 'CONTRIB_DRAFT2', 'AUTHOR_DRAFT', 'ATTACHMENT', 'ELEMENTOR_PAGE', 'ADMIN_DRAFT', 'ADMIN_PRIVATE', 'PASSWORD_PAGE', 'TEMPLATE', 'REV_PASSWORD', 'REV_TEMPLATE']}
PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
passes, fails = 0, []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None

FOLLOW = urllib.request.build_opener()

def http(method, url, body=None, headers=None, opener=None):
    h = dict(headers or {}); data = None
    if isinstance(body, (dict, list)):
        data = json.dumps(body).encode(); h.setdefault('Content-Type', 'application/json')
    elif isinstance(body, str):
        data = body.encode(); h.setdefault('Content-Type', 'application/x-www-form-urlencoded')
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    op = opener or urllib.request.build_opener(NoRedirect)
    try:
        r = op.open(req, timeout=90)
        return r.status, {k.lower(): v for k, v in r.headers.items()}, r.read()
    except urllib.error.HTTPError as e:
        return e.code, {k.lower(): v for k, v in e.headers.items()}, e.read()

def auth(who):
    if who == 'key':
        return 'Bearer ' + KEY
    return 'Basic ' + base64.b64encode(f"{who}:{os.environ['PW_' + who.upper()]}".encode()).decode()

def call(who, tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    st, _, raw = http('POST', MSG, body, {'Authorization': auth(who)})
    try:
        r = json.loads(raw)
    except ValueError:
        return {'_http': st, '_raw': raw[:300].decode(errors='replace')}
    if 'error' in r:
        return {'_http': st, 'error': r['error']}
    if not isinstance(r, dict) or 'result' not in r:
        return {'_http': st, 'rest': r}
    res = r['result']; text = res['content'][0]['text']
    if res.get('isError'):
        return {'isError': True, 'text': text}
    try:
        return json.loads(text)
    except ValueError:
        return {'text': text}

def record(name, ok, detail):
    global passes
    if ok:
        passes += 1; print(f'  PASS  {name}')
    else:
        fails.append(name); print(f'  FAIL  {name}: {json.dumps(detail, default=str)[:500]}')

def forbidden(name, r, cap):
    record(name, bool(r.get('isError')) and 'required capability' in r.get('text', '') and cap in r.get('text', ''), r)

def ok(name, r):
    record(name, not r.get('isError') and 'error' not in r and '_http' not in r, r)

def receipts(r):
    if 'change_receipt' in r:
        return [r['change_receipt']]
    return r.get('change_receipts', [])

def first_widget_id(tree):
    if isinstance(tree, dict):
        if 'widget_id' in tree and isinstance(tree['widget_id'], str):
            return tree['widget_id']
        if tree.get('elType') == 'widget' and isinstance(tree.get('id'), str):
            return tree['id']
        for v in tree.values():
            w = first_widget_id(v)
            if w:
                return w
    if isinstance(tree, list):
        for v in tree:
            w = first_widget_id(v)
            if w:
                return w
    return None

# ── Finding 1: object-level checks on every write tool ────────────────────
print('# Finding 1: object-level capability checks')
P, CD, CD2, AD, ATT, EP = (IDS[k] for k in ['ADMIN_PAGE', 'CONTRIB_DRAFT', 'CONTRIB_DRAFT2', 'AUTHOR_DRAFT', 'ATTACHMENT', 'ELEMENTOR_PAGE'])
widgets = call('admin', 'list_elementor_widgets', {'id': EP})
WID = first_widget_id(widgets)
record('fixture: Elementor widget id found', WID is not None, widgets)
widget_type = None
if isinstance(widgets, dict):
    for w in widgets.get('widgets', []):
        if w.get('widget_id') == WID or w.get('id') == WID:
            widget_type = w.get('widget_type') or w.get('widgetType')

c = 'contributor'
forbidden('contributor update_post on admin page', call(c, 'update_post', {'id': P, 'title': 'x'}), 'edit_post')
ok('contributor update_post own draft title', call(c, 'update_post', {'id': CD, 'title': 'secpatch contributor draft (edited)'}))
forbidden('contributor update_post own draft status=publish', call(c, 'update_post', {'id': CD, 'status': 'publish'}), 'publish_posts')
forbidden('contributor create_post page', call(c, 'create_post', {'title': 'x', 'post_type': 'page'}), 'edit_pages')
forbidden('contributor create_post status=publish', call(c, 'create_post', {'title': 'x', 'status': 'publish'}), 'publish_posts')
created = call(c, 'create_post', {'title': 'secpatch contributor created', 'content': 'hi'})
ok('contributor create_post draft', created)
CREATE_RECEIPT = (receipts(created) or [{}])[0].get('change_id')
forbidden('contributor update_alt_text on admin attachment', call(c, 'update_alt_text', {'id': ATT, 'alt': 'x'}), 'edit_post')
forbidden('contributor update_seo_data on admin page', call(c, 'update_seo_data', {'id': P, 'title': 'x'}), 'edit_post')
forbidden('contributor update_canonical on admin page', call(c, 'update_canonical', {'post_id': P, 'canonical_url': 'https://example.com/'}), 'edit_post')
forbidden('contributor update_structured_data on admin page', call(c, 'update_structured_data', {'post_id': P, 'schema_json': '{"@type":"Thing"}'}), 'edit_post')
forbidden('contributor set_featured_image on admin page', call(c, 'set_featured_image', {'id': P, 'attachment_id': ATT}), 'edit_post')
forbidden('contributor update_post_meta on admin page', call(c, 'update_post_meta', {'id': P, 'key': 'secpatch', 'value': '1'}), 'edit_post')
forbidden('contributor get_post_meta on admin page', call(c, 'get_post_meta', {'id': P}), 'edit_post')
forbidden('contributor set_page_settings on admin page', call(c, 'set_page_settings', {'id': P, 'settings': {'hide_title': True}}), 'edit_post')
forbidden('contributor assign_term on admin page', call(c, 'assign_term', {'post_id': P, 'term_id': 1}), 'edit_post')
forbidden('contributor update_taxonomy on admin page', call(c, 'update_taxonomy', {'post_id': P, 'taxonomy': 'category', 'terms': [1]}), 'edit_post')
forbidden('contributor update_term', call(c, 'update_term', {'taxonomy': 'category', 'term_id': 1, 'description': 'x'}), 'manage_categories')
forbidden('contributor update_elementor_widget on admin page', call(c, 'update_elementor_widget', {'id': EP, 'widget_id': WID, 'settings_patch': {'title': 'x'}, 'dry_run': True}), 'edit_post')
forbidden('contributor update_elementor_patch on admin page', call(c, 'update_elementor_patch', {'id': EP, 'ops': [], 'dry_run': True}), 'edit_post')
forbidden('contributor set_heading_level on admin page', call(c, 'set_heading_level', {'id': EP, 'widget_id': WID, 'level': 'h2', 'dry_run': True}), 'edit_post')
forbidden('contributor set_widget_setting on admin page', call(c, 'set_widget_setting', {'id': EP, 'widget_id': WID, 'key': 'title', 'value': 'x', 'dry_run': True}), 'edit_post')
forbidden('contributor update_elementor_data on admin page', call(c, 'update_elementor_data', {'id': EP, 'elementor_data': '[]', 'dry_run': True}), 'edit_post')
bulk = call(c, 'update_elementor_widgets_bulk', {'updates': [{'post_id': EP, 'widget_id': WID, 'settings_patch': {'title': 'x'}}], 'dry_run': True})
record('contributor update_elementor_widgets_bulk: per-row forbidden', isinstance(bulk, dict) and bulk.get('failed') == 1 and (bulk.get('results') or [{}])[0].get('error') == 'iato_mcp_forbidden', bulk)

a = 'author'
forbidden('author update_post on admin page', call(a, 'update_post', {'id': P, 'title': 'x'}), 'edit_post')
forbidden('author create_media attach_to_post admin page', call(a, 'create_media', {'filename': 'secpatch.png', 'mime_type': 'image/png', 'source': {'type': 'base64', 'data': PNG}, 'attach_to_post': P}), 'edit_post')
AATT = int(os.environ['AUTHOR_ATTACHMENT'])
if AATT:
    ok('author update_alt_text on own upload', call(a, 'update_alt_text', {'id': AATT, 'alt': 'mine'}))
    forbidden('contributor update_alt_text on author upload', call(c, 'update_alt_text', {'id': AATT, 'alt': 'x'}), 'edit_post')
ok('author assign_term on own draft', call(a, 'assign_term', {'post_id': AD, 'term_id': 1}))
forbidden('author assign_term on admin page', call(a, 'assign_term', {'post_id': P, 'term_id': 1}), 'edit_post')
forbidden('author update_elementor_widget on admin page', call(a, 'update_elementor_widget', {'id': EP, 'widget_id': WID, 'settings_patch': {'title': 'x'}, 'dry_run': True}), 'edit_post')

e = 'editor'
ok('editor update_post on admin page (edit_others_pages)', call(e, 'update_post', {'id': P, 'excerpt': 'secpatch excerpt'}))
ok('editor update_term (edit_term)', call(e, 'update_term', {'taxonomy': 'category', 'term_id': 1, 'description': 'secpatch'}))
ok('editor set_widget_setting dry_run on admin Elementor page', call(e, 'set_widget_setting', {'id': EP, 'widget_id': WID, 'key': 'title', 'value': 'x', 'dry_run': True}))
ecp = call(e, 'create_post', {'title': 'secpatch editor page', 'post_type': 'page', 'status': 'publish'})
ok('editor create_post page publish (publish_pages)', ecp)

ok('admin update_post on contributor draft', call('admin', 'update_post', {'id': CD, 'excerpt': 'admin was here'}))
ok('site key update_post on admin page', call('key', 'update_post', {'id': P, 'excerpt': 'secpatch key'}))
ok('site key get_post_meta on admin page', call('key', 'get_post_meta', {'id': P}))
ok('site key assign_term on contributor draft', call('key', 'assign_term', {'post_id': CD, 'term_id': 1}))
nf = call('key', 'update_post', {'id': 999999, 'title': 'x'})
record('site key missing post is not_found, not forbidden', bool(nf.get('isError')) and 'not found' in nf.get('text', '').lower(), nf)

# ── Finding 2: rollback of status / create receipts ───────────────────────
print('# Finding 2: status and create receipts')
pub = call(e, 'update_post', {'id': CD2, 'status': 'publish'})
ok('editor publishes contributor draft', pub)
unpub = call(e, 'update_post', {'id': CD2, 'status': 'draft'})
ok('editor unpublishes it again', unpub)
rid = next((r['change_id'] for r in receipts(unpub) if r.get('field') == 'status'), None)
record('status receipt recorded with before=publish', rid is not None and any(r.get('before_value') == 'publish' for r in receipts(unpub)), unpub)
forbidden('contributor rollback of unpublish (would re-publish) via MCP tool', call(c, 'rollback', {'change_id': rid}), 'publish_posts')
st, _, raw = http('POST', ROLLBACK, {'change_id': rid, 'before_value': 'publish'}, {'Authorization': auth(c)})
record('contributor rollback of unpublish via REST route is 403', st == 403 and b'publish_posts' in raw, (st, raw[:200]))
ok('contributor rollback via tool of the title edit on own draft', call(c, 'rollback', {'change_id': (receipts(call(c, 'update_post', {'id': CD, 'title': 'secpatch t2'})) or [{}])[0].get('change_id')}))

pub = call(e, 'update_post', {'id': AD, 'status': 'publish'}); ok('editor publishes author draft', pub)
unpub = call(e, 'update_post', {'id': AD, 'status': 'draft'}); ok('editor unpublishes author post', unpub)
rid = next((r['change_id'] for r in receipts(unpub) if r.get('field') == 'status'), None)
# 1.13.0: only the user who made a change or an administrator may roll it back.
rb = call(a, 'rollback', {'change_id': rid})
record("author cannot roll back the editor's unpublish of the author's post (1.13.0 actor rule)", bool(rb.get('isError')) and 'rollback_actor_mismatch' in rb.get('text', ''), rb)
pub = call(a, 'update_post', {'id': AD, 'status': 'publish'}); ok('author republishes own post', pub)
unpub = call(a, 'update_post', {'id': AD, 'status': 'draft'}); ok('author unpublishes own post', unpub)
rid = next((r['change_id'] for r in receipts(unpub) if r.get('field') == 'status'), None)
rb = call(a, 'rollback', {'change_id': rid})
ok('author rollback re-publishes own post (publish_posts)', rb)
gp = call('admin', 'get_post', {'id': AD})
record('author post is published after rollback', gp.get('status') == 'publish' or gp.get('post_status') == 'publish', gp)

ok('contributor rollback of own create (delete_post on own draft)', call(c, 'rollback', {'change_id': CREATE_RECEIPT}))
adm_created = call('admin', 'create_post', {'title': 'secpatch admin created'})
arid = (receipts(adm_created) or [{}])[0].get('change_id')
forbidden('author rollback of admin create (delete_post)', call(a, 'rollback', {'change_id': arid}), 'delete_post')
st, _, raw = http('POST', ROLLBACK, {'change_id': arid, 'before_value': None}, {'Authorization': auth('admin')})
record('admin rollback of own create via REST route', st == 200, (st, raw[:200]))
kp = call('key', 'update_post', {'id': CD2, 'status': 'publish'}); ok('site key publishes contributor draft', kp)
ku = call('key', 'update_post', {'id': CD2, 'status': 'draft'}); ok('site key unpublishes it', ku)
krid = next((r['change_id'] for r in receipts(ku) if r.get('field') == 'status'), None)
ok('site key rollback of the status receipt (full access kept)', call('key', 'rollback', {'change_id': krid}))

# ── Finding 4: structured data ────────────────────────────────────────────
print('# Finding 4: structured data output and validation')
PAYLOAD = '{"@context":"https://schema.org","@type":"Article","name":"x</script><script>alert(1)</script>"}'
r = call(e, 'update_structured_data', {'post_id': P, 'schema_json': PAYLOAD})
record('editor write of </script> payload is rejected (unsafe_schema_json)', bool(r.get('isError')) and 'script' in r.get('text', ''), r)
st, _, page = http('GET', f'{BASE}/?page_id={P}', opener=FOLLOW)
html = page.decode(errors='replace')
m = re.search(r'<script type="application/ld\+json">(.*?)</script>', html, re.S)
record('pre-stored dangerous value: ld+json block present', st == 200 and m is not None, (st, html[:200] if not m else m.group(1)[:200]))
block = m.group(1) if m else ''
record('pre-stored dangerous value: no raw <script>alert in page', '<script>alert(1)' not in html, html.count('alert(1)'))
record('pre-stored dangerous value: angle brackets hex-escaped in block', '<' not in block and BS + 'u003C' in block and 'alert(1)' in block, block[:200])
ok('editor writes a valid schema', call(e, 'update_structured_data', {'post_id': P, 'schema_json': '{"@context":"https://schema.org","@type":"Organization","name":"Acme & Sons","url":"https://acme.example/"}'}))
st, _, page = http('GET', f'{BASE}/?page_id={P}', opener=FOLLOW)
m = re.search(r'<script type="application/ld\+json">(.*?)</script>', page.decode(errors='replace'), re.S)
record('valid schema renders as JSON-LD and parses', m is not None and json.loads(m.group(1)).get('name') == 'Acme & Sons', m.group(1)[:200] if m else None)
record('valid schema: ampersand hex-escaped in output', m is not None and '&' not in m.group(1), m.group(1)[:200] if m else None)

# OAuth (consent screen, PKCE, codes, tokens, revocation) lives in tests/wp-env/oauth-live.sh since 1.13.0.

# ── Read tools: read_post, password-protected, templates, list filtering ──
print('# Read tools: read_post / password / template gating')
AD_, AP_, PWP, TPL, RPW, RTP = (IDS[k] for k in ['ADMIN_DRAFT', 'ADMIN_PRIVATE', 'PASSWORD_PAGE', 'TEMPLATE', 'REV_PASSWORD', 'REV_TEMPLATE'])
raw6 = call('admin', 'get_elementor_data', {'id': 6, 'format': 'raw'})
seed = next((v for v in (raw6.values() if isinstance(raw6, dict) else []) if isinstance(v, str) and v.lstrip().startswith('[')), None)
record('fixture: Elementor data read from page 6', seed is not None, raw6 if seed is None else 'ok')
for pid, label in ((CD, 'contributor draft'), (AD_, 'admin draft'), (TPL, 'template')):
    ok(f'admin seeds Elementor data on the {label}', call('admin', 'update_elementor_data', {'id': pid, 'elementor_data': seed}))
tw = call('admin', 'list_elementor_widgets', {'id': TPL}); TWID = first_widget_id(tw)
cw = call('admin', 'list_elementor_widgets', {'id': CD}); CWID = first_widget_id(cw)
wtype = None
for w in (cw.get('widgets', []) if isinstance(cw, dict) else []):
    if (w.get('widget_id') or w.get('id')) == CWID:
        wtype = w.get('widget_type') or w.get('widgetType') or w.get('type')
record('fixture: widget ids on template and contributor draft', TWID is not None and CWID is not None and wtype is not None, (tw, cw))

c = 'contributor'
own = call(c, 'get_post', {'id': CD})
record('contributor get_post own draft (content returned)', 'content' in own and not own.get('isError'), own)
forbidden("contributor get_post another user's draft", call(c, 'get_post', {'id': AD_}), 'read_post')
forbidden('contributor get_post private post', call(c, 'get_post', {'id': AP_}), 'read_post')
forbidden('contributor get_post password-protected page', call(c, 'get_post', {'id': PWP}), 'edit_post')
forbidden('contributor get_post template', call(c, 'get_post', {'id': TPL}), 'manage_options')
forbidden("contributor get_seo_data another user's draft", call(c, 'get_seo_data', {'id': AD_}), 'read_post')
forbidden('contributor get_seo_data private post', call(c, 'get_seo_data', {'id': AP_}), 'read_post')
forbidden("contributor get_page_builder another user's draft", call(c, 'get_page_builder', {'id': AD_}), 'read_post')
forbidden('contributor get_page_builder password-protected page', call(c, 'get_page_builder', {'id': PWP}), 'edit_post')
forbidden("contributor get_elementor_data another user's draft", call(c, 'get_elementor_data', {'id': AD_}), 'read_post')
forbidden('contributor get_elementor_data template', call(c, 'get_elementor_data', {'id': TPL}), 'manage_options')
forbidden("contributor list_elementor_widgets another user's draft", call(c, 'list_elementor_widgets', {'id': AD_}), 'read_post')
forbidden('contributor list_elementor_widgets template', call(c, 'list_elementor_widgets', {'id': TPL}), 'manage_options')
forbidden('contributor get_elementor_widget template', call(c, 'get_elementor_widget', {'id': TPL, 'widget_id': TWID}), 'manage_options')
ok('contributor get_elementor_data own draft', call(c, 'get_elementor_data', {'id': CD, 'format': 'summary'}))
ok('contributor list_elementor_widgets own draft', call(c, 'list_elementor_widgets', {'id': CD}))
ok('contributor get_elementor_widget own draft', call(c, 'get_elementor_widget', {'id': CD, 'widget_id': CWID}))
ok('contributor get_page_builder own draft', call(c, 'get_page_builder', {'id': CD}))
ok('contributor get_seo_data published page', call(c, 'get_seo_data', {'id': P}))
lst = call(c, 'get_posts', {'post_type': 'any', 'status': 'any', 'per_page': 100})
ids = {p['id'] for p in lst.get('posts', [])} if isinstance(lst, dict) else set()
record('contributor get_posts status=any lists own draft', CD in ids, lst)
record("contributor get_posts status=any hides another user's draft and the private post", AD_ not in ids and AP_ not in ids and lst.get('hidden', 0) >= 2, {'ids': sorted(ids), 'hidden': lst.get('hidden')})
fw = call(c, 'find_elementor_widgets', {'post_ids': [CD, AD_, TPL], 'filter': {'type': wtype}})
record('contributor find_elementor_widgets: unreadable ids skipped and reported', isinstance(fw, dict) and set(fw.get('skipped_unreadable', [])) == {AD_, TPL} and fw.get('scanned') == 1 and all(m.get('post_id') == CD for m in fw.get('matches', [])), fw)
forbidden('contributor find_elementor_widgets include_templates', call(c, 'find_elementor_widgets', {'post_ids': [], 'include_templates': True, 'filter': {'type': wtype}}), 'manage_options')
forbidden('contributor get_post revision of password-protected page', call(c, 'get_post', {'id': RPW}), 'edit_post')
forbidden('contributor get_post revision of template', call(c, 'get_post', {'id': RTP}), 'manage_options')
forbidden('contributor get_seo_data revision of password-protected page', call(c, 'get_seo_data', {'id': RPW}), 'edit_post')
forbidden('contributor get_page_builder revision of template', call(c, 'get_page_builder', {'id': RTP}), 'manage_options')
fw = call(c, 'find_elementor_widgets', {'post_ids': [RTP, RPW], 'filter': {'type': wtype}})
record('contributor find_elementor_widgets: revision inputs reported by input id, not parent', isinstance(fw, dict) and sorted(fw.get('skipped_unreadable', [])) == sorted([RTP, RPW]) and fw.get('scanned') == 0, fw)
forbidden('contributor update_elementor_data inherit_settings_from a password-protected page', call(c, 'update_elementor_data', {'id': CD, 'elementor_data': seed, 'inherit_settings_from': PWP, 'dry_run': True}), 'edit_post')
fw = call(c, 'find_elementor_widgets', {'post_ids': [], 'filter': {'type': wtype}})
record('contributor find_elementor_widgets scan: unreadable posts counted, not listed', isinstance(fw, dict) and fw.get('skipped_unreadable_count', 0) >= 1 and 'skipped_unreadable' not in fw, {k: v for k, v in fw.items() if k != 'matches'} if isinstance(fw, dict) else fw)

for who in ('admin', 'key'):
    for pid, label in ((AD_, 'draft'), (AP_, 'private post'), (PWP, 'password-protected page'), (TPL, 'template'), (RPW, 'revision of the password-protected page'), (RTP, 'revision of the template')):
        r = call(who, 'get_post', {'id': pid})
        record(f'{who} get_post {label} (content returned)', 'content' in r and not r.get('isError'), r)
    ok(f'{who} list_elementor_widgets template', call(who, 'list_elementor_widgets', {'id': TPL}))
    lst = call(who, 'get_posts', {'post_type': 'any', 'status': 'any', 'per_page': 100})
    ids = {p['id'] for p in lst.get('posts', [])} if isinstance(lst, dict) else set()
    record(f'{who} get_posts status=any lists everything, no hidden key', {CD, AD_, AP_} <= ids and 'hidden' not in lst, {'ids': sorted(ids), 'hidden': lst.get('hidden')})
    fw = call(who, 'find_elementor_widgets', {'post_ids': [CD, AD_, TPL], 'filter': {'type': wtype}})
    record(f'{who} find_elementor_widgets scans all three, nothing skipped', isinstance(fw, dict) and fw.get('scanned') == 3 and 'skipped_unreadable' not in fw, fw)
    fw = call(who, 'find_elementor_widgets', {'post_ids': [], 'include_templates': True, 'filter': {'type': wtype}})
    record(f'{who} find_elementor_widgets include_templates scan, nothing counted', isinstance(fw, dict) and not fw.get('isError') and 'skipped_unreadable_count' not in fw, {k: v for k, v in fw.items() if k != 'matches'} if isinstance(fw, dict) else fw)

# ── Part 4 (1.13.0): read tools and the bridge tools ──────────────────────
print('# Part 4: read permissions and the bridge tools')
P4 = {k: int(os.environ[k]) for k in ['C_PUBLIC', 'C_HELD', 'C_SPAM', 'C_DRAFT', 'C_PRIVATE', 'C_PASSWORD', 'SP_MENU', 'MI_CUSTOM', 'MI_PAGE', 'MI_DRAFT', 'MI_PRIVATE']}
ADRAFT, APRIV, PWPAGE = IDS['ADMIN_DRAFT'], IDS['ADMIN_PRIVATE'], IDS['PASSWORD_PAGE']

def err_text(r):
    return r.get('text', '') if isinstance(r, dict) else ''

def comment_ids(r):
    return {x['id'] for x in r.get('comments', [])} if isinstance(r, dict) else set()

forbidden('contributor get_media (upload_files)', call(c, 'get_media', {}), 'upload_files')
for who in (a, e, 'admin', 'key'):
    ok(f'{who} get_media', call(who, 'get_media', {'per_page': 5}))

forbidden('contributor get_comments status=hold', call(c, 'get_comments', {'status': 'hold'}), 'moderate_comments')
forbidden('author get_comments status=spam', call(a, 'get_comments', {'status': 'spam'}), 'moderate_comments')
r = call(a, 'get_comments', {'status': 'nonsense'})
record('get_comments unknown status is an error', bool(r.get('isError')) and 'status must be one of' in err_text(r), r)
ids = comment_ids(call(a, 'get_comments', {'per_page': 100}))
record('author get_comments: public comment listed; draft, private and password-protected posts left out', P4['C_PUBLIC'] in ids and not ({P4['C_DRAFT'], P4['C_PRIVATE'], P4['C_PASSWORD']} & ids), sorted(ids))
forbidden('author get_comments on the password-protected page (edit_post)', call(a, 'get_comments', {'post_id': PWPAGE}), 'edit_post')
record('author get_comments on the public page', P4['C_PUBLIC'] in comment_ids(call(a, 'get_comments', {'post_id': P})), None)
ids = comment_ids(call(e, 'get_comments', {'per_page': 100}))
record('editor get_comments: drafts, private and password-protected posts are readable to an Editor', {P4['C_PUBLIC'], P4['C_DRAFT'], P4['C_PRIVATE'], P4['C_PASSWORD']} <= ids, sorted(ids))
record('editor get_comments status=hold (moderate_comments)', P4['C_HELD'] in comment_ids(call(e, 'get_comments', {'status': 'hold', 'per_page': 100})), None)
record('site key get_comments status=all', {P4['C_HELD'], P4['C_PUBLIC']} <= comment_ids(call('key', 'get_comments', {'status': 'all', 'per_page': 100})), None)

for who in (c, a):
    r = call(who, 'get_menu_items', {'menu_id': P4['SP_MENU']})
    got = {x['id'] for x in r.get('items', [])} if isinstance(r, dict) else set()
    record(f'{who} get_menu_items: custom link and published page listed; draft and private targets left out and counted',
           {P4['MI_CUSTOM'], P4['MI_PAGE']} <= got and not ({P4['MI_DRAFT'], P4['MI_PRIVATE']} & got) and r.get('omitted_items') == 2, r)
r = call(e, 'get_menu_items', {'menu_id': P4['SP_MENU']})
got = {x['id'] for x in r.get('items', [])} if isinstance(r, dict) else set()
record('editor get_menu_items: every item, nothing omitted', {P4['MI_CUSTOM'], P4['MI_PAGE'], P4['MI_DRAFT'], P4['MI_PRIVATE']} <= got and 'omitted_items' not in r, r)

r = call(e, 'get_site_info', {})
record('editor get_site_info: plugin and template counts restricted', 'plugin_count' not in r and r.get('restricted_fields') == ['plugin_count', 'theme_builder_template_count'] and r.get('active_theme'), r)
for who in ('admin', 'key'):
    r = call(who, 'get_site_info', {})
    record(f'{who} get_site_info: plugin and template counts present', 'plugin_count' in r and 'theme_builder_template_count' in r and 'restricted_fields' not in r, r)

def route(who, url):
    r = call(who, 'resolve_url', {'url': url})
    return (r.get('route_type'), r.get('rendering_post_id')) if isinstance(r, dict) else (None, None)
record('author resolve_url of a draft by ID: 404', route(a, f'/?page_id={ADRAFT}') == ('404', None), route(a, f'/?page_id={ADRAFT}'))
record('admin resolve_url of the same draft resolves', route('admin', f'/?page_id={ADRAFT}')[1] == ADRAFT, route('admin', f'/?page_id={ADRAFT}'))
record('author resolve_url of a private post: 404', route(a, f'/?p={APRIV}') == ('404', None), route(a, f'/?p={APRIV}'))
record('editor resolve_url of the private post resolves (read_private_posts)', route(e, f'/?p={APRIV}')[1] == APRIV, route(e, f'/?p={APRIV}'))
record('author resolve_url of a published page resolves', route(a, f'/?page_id={P}')[1] == P, route(a, f'/?page_id={P}'))

BRIDGE = ['get_iato_sitemap', 'get_iato_nav_audit', 'get_iato_orphan_pages', 'get_iato_taxonomy', 'get_iato_seo_fixes', 'get_iato_content_gaps', 'get_iato_broken_links', 'get_iato_suggestions', 'get_iato_perf_report', 'get_iato_crawl_status', 'list_iato_crawls', 'start_iato_crawl']
for tool in BRIDGE:
    for who in (c, e):
        forbidden(f'{who} {tool} (manage_options)', call(who, tool, {}), 'manage_options')
r = call('admin', 'list_iato_crawls', {})
record('admin list_iato_crawls passes the capability check', 'required capability' not in err_text(r), r)

r = call(e, 'update_post_meta', {'id': EP, 'key': '_elementor_template_type', 'value': os.environ['EP_TEMPLATE_TYPE']})
forbidden('editor update_post_meta _elementor_template_type (manage_options)', r, 'manage_options')
ok('admin update_post_meta _elementor_template_type (unchanged value)', call('admin', 'update_post_meta', {'id': EP, 'key': '_elementor_template_type', 'value': os.environ['EP_TEMPLATE_TYPE']}))
r = call(e, 'update_elementor_data', {'id': EP, 'elementor_data': '[]', 'dry_run': True, 'inherit_settings_from': P, 'inherit_keys': ['_elementor_template_type']})
record('editor update_elementor_data inherit: template type skipped with the reason', any(x.get('key') == '_elementor_template_type' and x.get('reason') == 'requires_manage_options' for x in r.get('inherited_skipped', [])), r)

r = call(e, 'update_post_meta', {'id': P, 'key': 'secpatch_array', 'value': {'keep': 'plain & simple', 'html': '<b>x</b><script>alert(1)</script>'}})
record('editor update_post_meta array: markup filtered in the changed string and reported, plain text kept',
       r.get('sanitized_paths') == ['secpatch_array/html'] and '<script' not in json.dumps(r.get('after_value')) and (r.get('after_value') or {}).get('keep') == 'plain & simple', r)

print(f'# {passes} passed, {len(fails)} failed')
for f in fails:
    print('   -', f)
sys.exit(1 if fails else 0)

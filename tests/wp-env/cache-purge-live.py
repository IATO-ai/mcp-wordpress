#!/usr/bin/env python3
"""
Live checks for 1.15.0, driven by cache-purge-live.sh (env: BASE, SVC, KEY, PW_ADMIN,
PW_EDITOR, FIXTURE_A, FIXTURE_B, LIBRARY, KIT, PLAIN, PAGE_ONE, PAGE_TWO, PAGE_DRAFT,
MENU, PLUGINS). The test mu-plugin (mu-cache-spy.php) records purges, Elementor's
global clear and the loopback. Exit 1 on any failure.
"""
import base64, json, os, re, subprocess, sys, time, urllib.error, urllib.request

BASE = os.environ['BASE'].rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
SVC = os.environ.get('SVC', 'cli')
A = json.loads(os.environ['FIXTURE_A'])
B = json.loads(os.environ['FIXTURE_B'])
PAGE_A, PAGE_B = int(A['page_id']), int(B['page_id'])
H = A['ids']['heading']
LIBRARY, KIT, PLAIN = int(os.environ['LIBRARY']), int(os.environ['KIT']), int(os.environ['PLAIN'])
ONE, TWO, DRAFT, MENU = int(os.environ['PAGE_ONE']), int(os.environ['PAGE_TWO']), int(os.environ['PAGE_DRAFT']), int(os.environ['MENU'])
passes, fails, notes = 0, [], []


def record(name, ok, detail=None):
    global passes
    if ok:
        passes += 1; print(f'  PASS  {name}')
    else:
        fails.append(name); print(f'  FAIL  {name}: {json.dumps(detail, default=str)[:800]}')


def info(text):
    notes.append(text); print(f'  INFO  {text}')


def wp(*args, user='admin'):
    cmd = ['npx', '@wordpress/env', 'run', SVC, 'wp', *args]
    if user:
        cmd.append(f'--user={user}')
    r = subprocess.run(cmd, capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def wp_json(php):
    out = wp('eval', php)
    return json.loads(out.splitlines()[-1]) if out else None


def opt(name):
    return wp('option', 'get', name, user=None)


def set_opt(name, value):
    wp('option', 'update', name, str(value), user=None)


def del_opt(name):
    wp('option', 'delete', name, user=None)


def auth(who):
    if who == 'key':
        return 'Bearer ' + os.environ['KEY']
    if who == 'editor':
        return 'Basic ' + base64.b64encode(f"cacheeditor:{os.environ['PW_EDITOR']}".encode()).decode()
    return 'Basic ' + base64.b64encode(f"admin:{os.environ['PW_ADMIN']}".encode()).decode()


def post(url, body, who):
    req = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST', headers={'Content-Type': 'application/json', 'Authorization': auth(who)})
    try:
        resp = urllib.request.urlopen(req, timeout=120)
    except urllib.error.HTTPError as e:
        resp = e
    return resp.read(), resp.headers


LAST_HEADERS = {}


def call(tool, args=None, who='admin'):
    global LAST_HEADERS
    raw, LAST_HEADERS = post(MSG, {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args or {}}}, who)
    r = json.loads(raw)
    if 'error' in r:
        return {'error': r['error']}
    res = r['result']; text = res['content'][0]['text']
    try:
        body = json.loads(text)
    except ValueError:
        body = {'text': text}
    if res.get('isError'):
        return {'isError': True, 'text': text, 'body': body if isinstance(body, dict) else {}}
    return body


def purges():
    out = wp('option', 'get', 'iato_mcp_test_purges', '--format=json', user=None)
    return json.loads(out) if out else []


def clear_spy():
    del_opt('iato_mcp_test_purges')
    del_opt('iato_mcp_test_global_clears')


def global_clears():
    return int(opt('iato_mcp_test_global_clears') or 0)


def receipt(r, field=None):
    rs = [r['change_receipt']] if 'change_receipt' in r else r.get('change_receipts', [])
    for one in rs:
        if field is None or one.get('field') == field:
            return one
    return {}


CSS_PHP = r"""$u = wp_upload_dir(); $m = get_post_meta(%(pid)d, '_elementor_css', true); echo wp_json_encode(['status' => is_array($m) ? ($m['status'] ?? null) : null, 'time' => is_array($m) ? ($m['time'] ?? null) : null, 'file' => file_exists($u['basedir'] . '/elementor/css/post-%(pid)d.css'), 'element_cache' => (bool) get_post_meta(%(pid)d, '_elementor_element_cache', true)]);"""


def css(pid):
    return wp_json(CSS_PHP % {'pid': pid})


def view(pid):
    """Load the page as a visitor, so Elementor builds its CSS."""
    url = wp('post', 'url', str(pid), user=None)
    urllib.request.urlopen(url, timeout=60).read()


def stored_widget(pid, wid):
    tree = json.loads(wp('post', 'meta', 'get', str(pid), '_elementor_data', user=None))
    stack = list(tree)
    while stack:
        el = stack.pop()
        if el.get('id') == wid:
            return el
        stack.extend(el.get('elements', []))
    return None


stamp = str(int(time.time()))
original = {k: wp('option', 'get', k, user=None) for k in ('blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts')}

# ── A. Classic Elementor writes: per-post CSS, scope post ──────────────────
print('# classic Elementor write: only this post')
view(PAGE_A); view(PAGE_B)
b_before = css(PAGE_B); a_before = css(PAGE_A)
record('setup: both pages have CSS after a page view', b_before['status'] in ('file', 'inline', 'empty') and a_before['status'] in ('file', 'inline', 'empty'), [a_before, b_before])
time.sleep(1)
clear_spy()
r = call('set_widget_setting', {'id': PAGE_A, 'widget_id': H, 'key': 'title', 'value': 'Cache live ' + stamp})
c = r.get('cache') or {}
record('write response has a cache object', set(c) >= {'detected', 'purged', 'scope', 'notes'}, r)
record('scope post', c.get('scope') == 'post', c)
record('no cache plugin: detected empty, purged null', c.get('detected') == [] and c.get('purged') is None, c)
record('Elementor\'s global clear did not run', global_clears() == 0, global_clears())
record('the action ran once, for this post', purges() == [{'scope': 'post', 'post_id': PAGE_A, 'results': []}], purges())
b_after = css(PAGE_B); a_after = css(PAGE_A)
record('the other page keeps its CSS, unchanged', b_after == b_before, [b_before, b_after])
record('this page\'s CSS was rebuilt straight away', a_after['status'] in ('file', 'inline', 'empty') and (a_after['time'] or 0) > (a_before['time'] or 0), [a_before, a_after])
record('this page\'s element cache is gone', not a_after['element_cache'], a_after)
page_html = urllib.request.urlopen(wp('post', 'url', str(PAGE_A), user=None), timeout=60).read().decode()
record('the page renders the new text', 'Cache live ' + stamp in page_html, page_html[:200])

for label, tool, args in [
    ('update_elementor_widget', 'update_elementor_widget', {'id': PAGE_A, 'widget_id': H, 'settings_patch': {'title': 'Cache two ' + stamp}}),
    ('update_elementor_widgets_bulk', 'update_elementor_widgets_bulk', {'updates': [{'id': PAGE_A, 'widget_id': H, 'settings_patch': {'title': 'Cache three ' + stamp}}, {'id': PAGE_B, 'widget_id': H, 'settings_patch': {'title': 'Cache three ' + stamp}}]}),
    ('set_heading_level', 'set_heading_level', {'id': PAGE_A, 'widget_id': H, 'level': 'h4'}),
]:
    clear_spy()
    r = call(tool, args)
    record(f'{label}: scope post, no global clear', (r.get('cache') or {}).get('scope') == 'post' and global_clears() == 0, r)
r_bulk_args = {'updates': [{'id': PAGE_A, 'widget_id': H, 'settings_patch': {'title': 'Cache four ' + stamp}}, {'id': PAGE_B, 'widget_id': H, 'settings_patch': {'title': 'Cache four ' + stamp}}]}
clear_spy()
r = call('update_elementor_widgets_bulk', r_bulk_args)
record('bulk on two pages: one purge per page, post_ids listed', sorted(p['post_id'] for p in purges()) == sorted([PAGE_A, PAGE_B]) and sorted((r.get('cache') or {}).get('post_ids', [])) == sorted([PAGE_A, PAGE_B]), (purges(), r.get('cache')))

clear_spy()
r = call('update_post_meta', {'id': PAGE_A, 'key': '_elementor_page_settings', 'value': {'hide_title': 'yes'}})
record('update_post_meta on an Elementor key: scope post, no global clear', (r.get('cache') or {}).get('scope') == 'post' and global_clears() == 0, r)
rollback_id = receipt(r).get('change_id')
clear_spy()
r = call('rollback', {'change_id': rollback_id})
record('rollback of it: scope post, no global clear', (r.get('cache') or {}).get('scope') == 'post' and global_clears() == 0, r)

# ── B. Writes other pages render ───────────────────────────────────────────
print('# writes other pages render: global clear + full purge')
view(PAGE_B)
b_before = css(PAGE_B)
clear_spy()
r = call('update_post_meta', {'id': KIT, 'key': '_elementor_page_settings', 'value': {'site_name': 'cache-live ' + stamp}})
c = r.get('cache') or {}
record('the active kit: scope all', c.get('scope') == 'all', r)
record('...and Elementor\'s global clear ran once', global_clears() == 1, global_clears())
record('...which removes other pages\' CSS', css(PAGE_B)['file'] is False or css(PAGE_B)['status'] is None, css(PAGE_B))
record('...one full purge', [p['scope'] for p in purges()] == ['all'], purges())
clear_spy()
r = call('update_elementor_data', {'id': LIBRARY, 'elementor_data': json.dumps([{'id': 'cl1aaaa', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'cl2aaaa', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': 'Saved section ' + stamp}, 'elements': []}]}])})
record('a saved template: scope all, global clear', (r.get('cache') or {}).get('scope') == 'all' and global_clears() == 1, (r, global_clears()))

print('# site-wide writes that change no CSS: full purge, no global clear')
term = None
for label, tool, args in [
    ('create_menu_item', 'create_menu_item', {'menu_id': MENU, 'title': 'cache-live item', 'url': 'https://example.com/'}),
    ('create_term', 'create_term', {'taxonomy': 'category', 'name': 'cache-live term ' + stamp}),
    ('update_redirect', 'update_redirect', {'from_url': '/cache-live-' + stamp, 'to_url': '/', 'type': 301}),
    ('assign_term', 'assign_term', {'post_id': PLAIN, 'term_id': None, 'taxonomy': 'category'}),
]:
    if tool == 'assign_term':
        args['term_id'] = term
    clear_spy()
    r = call(tool, args)
    if tool == 'create_term':
        term = r.get('term_id')
    record(f'{label}: scope all, no global clear', (r.get('cache') or {}).get('scope') == 'all' and global_clears() == 0, r)
clear_spy()
r = call('assign_term', {'post_id': PLAIN, 'term_id': term, 'taxonomy': 'category'})
record('assign_term again, nothing changed: no purge', purges() == [] and (r.get('cache') or {}).get('scope') is None, (purges(), r))

# ── C. Plain posts and what they report ────────────────────────────────────
print('# plain posts')
clear_spy()
r = call('update_post', {'id': PLAIN, 'title': 'cache-live plain ' + stamp})
record('update_post: scope post', (r.get('cache') or {}).get('scope') == 'post' and purges()[0]['post_id'] == PLAIN, (r, purges()))
clear_spy()
r = call('update_post', {'id': PLAIN, 'title': 'cache-live plain ' + stamp})
record('update_post with nothing new: a cache object, no purge', (r.get('cache') or {}).get('purged') is None and (r.get('cache') or {}).get('scope') is None and purges() == [], r)
clear_spy()
r = call('update_post', {'id': PLAIN, 'slug': 'cache-live-moved-' + stamp, 'confirm_url_break': True})
record('a slug change: scope all', (r.get('cache') or {}).get('scope') == 'all', r)
clear_spy()
r = call('create_post', {'title': 'cache-live created ' + stamp, 'content': '<p>x</p>', 'status': 'publish', 'post_type': 'post'})
created = r.get('id')
record('create_post: scope post', (r.get('cache') or {}).get('scope') == 'post', r)
clear_spy()
r = call('rollback', {'change_id': receipt(r).get('change_id')})
record('rollback of create_post: scope all', (r.get('cache') or {}).get('scope') == 'all', r)

print('# dry runs and reads')
clear_spy()
for tool, args in [
    ('set_widget_setting', {'id': PAGE_A, 'widget_id': H, 'key': 'title', 'value': 'dry', 'dry_run': True}),
    ('update_post_meta', {'id': PLAIN, 'key': 'cache_live', 'value': 'x', 'dry_run': True}),
    ('update_redirect', {'from_url': '/dry-' + stamp, 'to_url': '/', 'type': 301, 'dry_run': True}),
    ('update_site_settings', {'title': 'dry', 'dry_run': True}),
]:
    r = call(tool, args)
    record(f'{tool} dry run: no cache object', 'cache' not in r and r.get('dry_run') is True, r)
record('no dry run purged anything', purges() == [] and global_clears() == 0, purges())
r = call('get_post', {'id': PLAIN})
record('a read has no cache object', 'cache' not in r, list(r)[:8])
r = call('get_site_info')
record('get_site_info lists what it found', r.get('cache', {}).get('detected') == [] and 'notes' in r.get('cache', {}), r.get('cache'))

# ── D. Settings and filter ─────────────────────────────────────────────────
print('# opting out')
set_opt('iato_mcp_cache_purge_enabled', '0')
clear_spy()
r = call('update_post', {'id': PLAIN, 'title': 'cache-live off ' + stamp})
c = r.get('cache') or {}
record('setting off: no purge, and the response says so', purges() == [] and 'switched off' in ' '.join(c.get('notes', [])), (purges(), c))
clear_spy()
r = call('update_post_meta', {'id': KIT, 'key': '_elementor_page_settings', 'value': {'site_name': 'cache-live off ' + stamp}})
record('setting off: a kit write still clears Elementor CSS', global_clears() == 1 and purges() == [], (global_clears(), purges()))
del_opt('iato_mcp_cache_purge_enabled')
set_opt('iato_mcp_test_purge_filter_off', '1')
clear_spy()
r = call('update_post', {'id': PLAIN, 'title': 'cache-live filter ' + stamp})
record('the filter can skip a purge', purges() == [] and 'iato_mcp_cache_purge' in ' '.join((r.get('cache') or {}).get('notes', [])), (purges(), r.get('cache')))
del_opt('iato_mcp_test_purge_filter_off')

print('# caches IATO cannot purge')
wp('transient', 'delete', 'iato_mcp_cache_edge', user=None)
del_opt('iato_mcp_test_head_requests')
r = call('get_site_info')
record('get_site_info makes no loopback request', not opt('iato_mcp_test_head_requests'), opt('iato_mcp_test_head_requests'))
set_opt('iato_mcp_test_edge_headers', json.dumps({'X-Hcdn-Cache-Status': 'MISS', 'Server': 'hcdn', 'cf-ray': 'abc'}))
r = call('update_post', {'id': PLAIN, 'title': 'cache-live edge ' + stamp})
n = ' '.join((r.get('cache') or {}).get('notes', []))
record('hCDN and Cloudflare seen on the loopback become notes', 'hPanel' in n and 'Cloudflare' in n, r.get('cache'))
record('the loopback ran once', opt('iato_mcp_test_head_requests') == '1', opt('iato_mcp_test_head_requests'))
r = call('update_post', {'id': PLAIN, 'title': 'cache-live edge two ' + stamp})
record('...and is cached', opt('iato_mcp_test_head_requests') == '1' and 'hPanel' in ' '.join((r.get('cache') or {}).get('notes', [])), opt('iato_mcp_test_head_requests'))
r = call('get_site_info')
record('get_site_info now reports those notes', 'hPanel' in ' '.join(r['cache']['notes']), r['cache'])
del_opt('iato_mcp_test_edge_headers')
wp('transient', 'delete', 'iato_mcp_cache_edge', user=None)

# ── E. update_site_settings ────────────────────────────────────────────────
print('# update_site_settings')
r = call('update_site_settings', {'title': 'no'}, who='editor')
record('an editor is refused', r.get('isError') and 'manage_options' in r.get('text', ''), r)
r = call('update_site_settings', {'title': 'x', 'siteurl': 'https://evil.example'})
record('another key is refused', r.get('isError') and 'siteurl' in r.get('text', '') and wp('option', 'get', 'blogname', user=None) == original['blogname'], r)
r = call('update_site_settings', {'homepage_displays': 'page'})
record('page without a front page is refused', r.get('isError') and 'front_page_id' in r.get('text', ''), r)
r = call('update_site_settings', {'homepage_displays': 'page', 'front_page_id': DRAFT})
record('a draft cannot be the front page, and nothing was written', r.get('isError') and wp('option', 'get', 'show_on_front', user=None) == original['show_on_front'], r)
r = call('update_site_settings', {'front_page_id': ONE, 'posts_page_id': ONE})
record('one page cannot be both', r.get('isError') and 'both' in r.get('text', ''), r)

clear_spy()
r = call('update_site_settings', {'title': 'Q&A <b>cache</b> 100%ab ' + stamp, 'tagline': 'A tagline ' + stamp, 'homepage_displays': 'page', 'front_page_id': ONE, 'posts_page_id': TWO})
stored = wp('option', 'get', 'blogname', user=None)
record('five options, five receipts', len(r.get('change_receipts', [])) == 5, r)
record('tags stripped, %xx kept, WordPress encoded the ampersand', stored == 'Q&amp;A cache 100%ab ' + stamp, stored)
record('response and receipt hold what was stored', r['changes']['title']['after'] == stored and receipt(r, 'blogname')['after_value'] == stored, r['changes']['title'])
record('front page set', wp('option', 'get', 'show_on_front', user=None) == 'page' and wp('option', 'get', 'page_on_front', user=None) == str(ONE) and wp('option', 'get', 'page_for_posts', user=None) == str(TWO), r['changes'])
record('scope all, no global clear', (r.get('cache') or {}).get('scope') == 'all' and global_clears() == 0, r.get('cache'))
s = call('get_site_settings')
record('get_site_settings agrees', s.get('show_on_front') == 'page' and s.get('page_on_front') == ONE and s.get('page_for_posts') == TWO and s.get('title') == stored, s)
home = urllib.request.urlopen(BASE + '/', timeout=60).read().decode()
record('the home page now shows the front page', f'page-id-{ONE}' in home, home[:300])
blog = urllib.request.urlopen(wp('post', 'url', str(TWO), user=None), timeout=60).read().decode()
record('the posts page serves the blog', 'blog' in blog[:5000], blog[:300])

r2 = call('update_site_settings', {'front_page_id': ONE})
record('sending the same value changes nothing', r2.get('changes') == {} and 'change_receipt' not in r2 and (r2.get('cache') or {}).get('scope') is None, r2)
r2 = call('update_site_settings', {'posts_page_id': ONE})
record('the front page cannot become the posts page', r2.get('isError') and 'both' in r2.get('text', ''), r2)

ids = {x['field']: x['change_id'] for x in r['change_receipts']}
r2 = call('rollback', {'change_id': ids['blogname'], 'dry_run': True})
record('rollback dry run: matches, writes nothing', r2.get('conflict_check', {}).get('status') == 'match' and r2.get('would_restore') == original['blogname'] and wp('option', 'get', 'blogname', user=None) == stored, r2)
wp('option', 'update', 'blogdescription', 'changed in wp-admin', user=None)
r2 = call('rollback', {'change_id': ids['blogdescription']})
record('a newer change blocks the rollback', r2.get('isError') and r2['body'].get('error') == 'rollback_conflict', r2)
r2 = call('rollback', {'change_id': ids['blogdescription'], 'force': True})
record('...force restores the old tagline', wp('option', 'get', 'blogdescription', user=None) == original['blogdescription'], (r2, wp('option', 'get', 'blogdescription', user=None)))
wp('post', 'update', str(ONE), '--post_status=draft', user=None)
r2 = call('rollback', {'change_id': ids['page_for_posts']})
record('posts page rolled back to its old value', wp('option', 'get', 'page_for_posts', user=None) == original['page_for_posts'], r2)
r3 = call('update_site_settings', {'front_page_id': TWO})
wp('post', 'update', str(ONE), '--post_status=publish', user=None)
one_receipt = r3.get('change_receipt', {})
wp('post', 'update', str(ONE), '--post_status=draft', user=None)
r2 = call('rollback', {'change_id': one_receipt.get('change_id')})
record('a page no longer published cannot be restored', r2.get('isError') and r2['body'].get('error') == 'rollback_unavailable', r2)
wp('post', 'update', str(ONE), '--post_status=publish', user=None)
r2 = call('rollback', {'change_id': one_receipt.get('change_id')})
record('...and can once it is published again', wp('option', 'get', 'page_on_front', user=None) == str(ONE), r2)
clear_spy()
r2 = call('rollback', {'change_id': ids['blogname']})
record('title rolled back, scope all', wp('option', 'get', 'blogname', user=None) == original['blogname'] and (r2.get('cache') or {}).get('scope') == 'all', r2)
raw, _ = post(BASE + '/wp-json/iato-mcp/v1/rollback', {'change_id': ids['show_on_front'], 'before_value': original['show_on_front']}, 'admin')
rest = json.loads(raw)
record('the REST rollback route also reports cache', rest.get('success') is True and rest.get('cache', {}).get('scope') == 'all', rest)
call('rollback', {'change_id': ids['page_on_front'], 'force': True})
for k, v in original.items():
    if wp('option', 'get', k, user=None) != v:
        wp('option', 'update', k, v, user=None)
record('site settings restored', all(wp('option', 'get', k, user=None) == v for k, v in original.items()), original)

# ── F. The real cache plugins ──────────────────────────────────────────────
if os.environ.get('PLUGINS') == '--plugins':
    print('# real cache plugins, one at a time')
    SLUGS = {'litespeed-cache': 'litespeed', 'wp-super-cache': 'wp-super-cache', 'w3-total-cache': 'w3-total-cache', 'wp-fastest-cache': 'wp-fastest-cache', 'sg-cachepress': 'sg-optimizer'}
    wp('option', 'update', 'show_on_front', 'page', user=None)
    wp('option', 'update', 'page_on_front', str(PAGE_B), user=None)
    for plugin, slug in SLUGS.items():
        wp('plugin', 'install', plugin, '--activate', user=None)
        version = wp('plugin', 'get', plugin, '--field=version', user=None)
        status = wp('plugin', 'get', plugin, '--field=status', user=None)
        if status != 'active':
            info(f'{plugin} {version} would not activate here ({status}): not run live')
            wp('plugin', 'uninstall', plugin, '--deactivate', user=None)
            continue
        print(f'  -- {plugin} {version}')
        s = call('get_site_info')
        if slug not in s.get('cache', {}).get('detected', []):
            info(f'{plugin} {version} is active but its purge API is not loaded on this host ({s.get("cache")}): not run live')
            wp('plugin', 'uninstall', plugin, '--deactivate', user=None)
            continue
        record(f'{slug}: get_site_info detects it', True)
        clear_spy()
        r = call('update_post', {'id': PLAIN, 'title': f'cache-live {slug} {stamp}'})
        c = r.get('cache') or {}
        record(f'{slug}: a post write purges without error', c.get('detected') == [slug] and c.get('purged') is True and c.get('scope') == 'post', c)
        if slug == 'litespeed':
            h = LAST_HEADERS.get('X-LiteSpeed-Purge', '')
            record('litespeed: the MCP response carries a purge header for the post', bool(h) and 'public' in h, dict(LAST_HEADERS))
        r = call('set_widget_setting', {'id': PAGE_B, 'widget_id': H, 'key': 'title', 'value': f'cache-live front {slug} {stamp}'})
        c = r.get('cache') or {}
        record(f'{slug}: a front page write purges without error', c.get('purged') is True and c.get('scope') == 'post', c)
        r = call('update_redirect', {'from_url': f'/cache-live-{slug}-{stamp}', 'to_url': '/', 'type': 301})
        c = r.get('cache') or {}
        record(f'{slug}: a full purge runs without error', c.get('purged') is True and c.get('scope') == 'all', c)
        if slug == 'litespeed':
            h = LAST_HEADERS.get('X-LiteSpeed-Purge', '')
            # A site-wide purge goes out as the tag every page of the site carries (Purge::_append_prefix()).
            record('litespeed: the full purge header names the whole site', re.fullmatch(r'public,[0-9a-f]{3}_', h) is not None, dict(LAST_HEADERS))
            msg = wp('option', 'get', 'litespeed.admin_display.messages', '--format=json', user=None)
            record('litespeed: no admin notice queued', msg in ('', '[]', 'null'), msg)
        r = call('update_post', {'id': PLAIN, 'title': f'cache-live {slug} dry {stamp}', 'dry_run': False})
        r = call('update_redirect', {'from_url': f'/cache-live-{slug}-dry', 'to_url': '/', 'type': 301, 'dry_run': True})
        record(f'{slug}: a dry run has no cache object', 'cache' not in r, r)
        wp('plugin', 'deactivate', plugin, user=None)
        wp('plugin', 'uninstall', plugin, user=None)
    wp('option', 'update', 'show_on_front', original['show_on_front'], user=None)
    wp('option', 'update', 'page_on_front', original['page_on_front'], user=None)

print(f'\n# {passes} passed, {len(fails)} failed')
for n in notes:
    print('# note: ' + n)
if fails:
    print('# failed: ' + '; '.join(fails))
sys.exit(1 if fails else 0)

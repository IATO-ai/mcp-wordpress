"""Driven by theme-builder-live.sh."""
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
PW = {'admin': os.environ['PW_ADMIN'], 'editor': os.environ['PW_EDITOR']}
T = 'theme-builder-live'
GENERAL = [{'type': 'include', 'name': 'general'}]
results = []


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:500]}'))


def wp(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    lines = [l.strip('\r') for l in out.splitlines() if l and not l.startswith(('ℹ', '✔'))]
    return lines[-1] if lines else ''


def php(code):
    return wp('eval', code, '--user=admin')


def rpc(method, params, who='admin'):
    auth = 'Bearer ' + KEY if who == 'key' else 'Basic ' + base64.b64encode(f'{who}:{PW[who]}'.encode()).decode()
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth})
    return json.loads(urllib.request.urlopen(req, timeout=180).read())['result']


def call(tool, args, who='admin'):
    res = rpc('tools/call', {'name': tool, 'arguments': args}, who)
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


def create(title, kind='header', **more):
    return call('create_elementor_template', {'title': f'{T} {title}', 'template_type': kind, **more})


def set_conditions(tid, conditions, **more):
    return call('set_elementor_template_conditions', {'id': tid, 'conditions': conditions, **more})


def undo(receipt, **opts):
    return call('rollback', {'change_id': receipt['change_id'], **opts})


def tree(marker):
    return json.dumps([{'id': 'tb1aaaa', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'tb2aaaa', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': marker}, 'elements': []}]}])


def visitor(path):
    """A logged-out visitor, past any page cache, following redirects."""
    sep = '&' if '?' in path else '?'
    try:
        r = urllib.request.urlopen(f'{BASE}{path}{sep}v={os.urandom(4).hex()}', timeout=90)
        return r.status, r.read().decode(errors='replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(errors='replace')


def status_of(tid):
    return wp('post', 'get', str(tid), '--field=post_status')


def cache_option():
    return json.loads(php("echo wp_json_encode(get_option('elementor_pro_theme_builder_conditions',[]));") or '[]')


def in_cache(location, tid):
    cached = cache_option()
    return isinstance(cached, dict) and str(tid) in (cached.get(location) or {})


def count_templates():
    return int(php(f"echo count(get_posts(['post_type'=>'elementor_library','post_status'=>'any','s'=>'{T}','numberposts'=>-1,'fields'=>'ids']));") or 0)


print('Elementor', wp('plugin', 'get', 'elementor', '--field=version'), 'WordPress', wp('core', 'version'), 'theme', wp('option', 'get', 'stylesheet'), 'on', BASE)
pro = '1' == php("echo class_exists('\\ElementorPro\\Modules\\ThemeBuilder\\Module')?1:0;")

print('# who may')
r = call('create_elementor_template', {'title': f'{T} editor', 'template_type': 'page'}, 'editor')
record('an Editor cannot create one', refused(r, 'manage_options') and count_templates() == 0, r)
owner = wp('option', 'get', 'iato_mcp_key_owner') or '0'
r = call('create_elementor_template', {'title': f'{T} key', 'template_type': 'page'}, 'key')
if owner in ('', '0'):
    record('an unbound key is refused, as Elementor would refuse it', refused(r, 'not assigned') and count_templates() == 0, r)
else:
    print(f'  note: the site key is bound to user {owner}, so the unbound refusal was not tested')
    if r.get('change_receipt'):
        undo(r['change_receipt'], force=True)

print('# refusals')
r = create('x', 'nosuch')
record('an unknown type lists what the site offers', refused(r, 'unknown_template_type') and 'page' in r.get('allowed', []), r)
r = create('x', 'page', conditions=['include/general'])
record('conditions on a page are refused', refused(r, 'conditions_not_supported'), r)
r = create('x', 'page', elementor_data='[{"id":"a"}]')
record('a tree that would crash the page is refused', refused(r, 'elementor_invalid_tree') and count_templates() == 0, r)

print('# a free type')
page_t = create('library page', 'page', elementor_data=tree('tb-library-page'))
record('a page template is made without Pro', isinstance(page_t.get('id'), int) and page_t.get('status') == 'draft', page_t)
record('it has no verified, having no location', 'verified' not in page_t, page_t)
r = undo(page_t['change_receipt'])
record('rollback trashes it', r.get('success') is True and status_of(page_t['id']) == 'trash', r)

print('# another plugin throws during the publish')
wp('option', 'update', 'iato_mcp_test_template_throw', '1')
try:
    r = create('throwing', 'page', status='publish')
    record('the error names it, and the template is taken down', refused(r, 'template_create_failed') and 'a hook failed on publish' in r.get('message', '') and r.get('trashed_template_id') and status_of(r['trashed_template_id']) == 'trash', r)
finally:
    wp('option', 'delete', 'iato_mcp_test_template_throw')

if not pro:
    print('  note: Elementor Pro is not active on this site, so the Theme Builder checks were skipped. Run this on the Pro site for them.')
    r = create('header', 'header')
    record('a header needs Elementor Pro, and says so', refused(r, 'elementor_pro_required') and 'Elementor Pro' in r.get('message', ''), r)
    r = set_conditions(1, GENERAL)
    record('so do conditions', refused(r, 'elementor_pro_required'), r)
else:
    print('# create_elementor_template: dry run')
    r = create('dry', 'header', conditions=GENERAL, status='publish', elementor_data=tree('tb-dry'), dry_run=True)
    record('a dry run says would_create and writes nothing', r.get('action') == 'would_create' and count_templates() == 0 and 'cache' not in r, r)
    r = create('dry empty', 'header', conditions=GENERAL, status='publish', dry_run=True)
    record('a dry run refuses an empty published header, as the real call does', refused(r, 'empty_template') and count_templates() == 0, r)

    print('# what it leaves behind, against wp-admin')
    made = create('header', 'header')
    hid = made['id']
    meta = json.loads(php(f"$m=[];foreach(get_post_meta({hid}) as $k=>$v){{$m[$k]=$v[0];}}echo wp_json_encode(['meta'=>$m,'terms'=>wp_get_object_terms({hid},'elementor_library_type',['fields'=>'slugs']),'type'=>get_post_type({hid})]);"))
    record('a draft elementor_library post', status_of(hid) == 'draft' and meta['type'] == 'elementor_library', meta)
    record('edit mode, template type and versions, as wp-admin leaves them', meta['meta'].get('_elementor_edit_mode') == 'builder' and meta['meta'].get('_elementor_template_type') == 'header' and meta['meta'].get('_elementor_version') and meta['meta'].get('_elementor_pro_version'), meta)
    record('the library type term', meta['terms'] == ['header'], meta)
    record('it has an edit URL', f'post={hid}&action=elementor' in made.get('edit_url', ''), made)
    record('it purged the whole site', made.get('cache', {}).get('scope') == 'all', made.get('cache'))

    print('# set_elementor_template_conditions')
    r = set_conditions(hid, [{'type': 'include', 'name': 'nosuch'}])
    record('an unknown condition returns what Pro registers', refused(r, 'unknown_condition') and 'front_page' in r.get('registered', {}).get('singular', []), r)
    r = set_conditions(hid, GENERAL, dry_run=True)
    record('a dry run says would_update and writes nothing', r.get('action') == 'would_update' and r.get('after') == ['include/general'] and not in_cache('header', hid) and 'cache' not in r, r)
    r = set_conditions(hid, GENERAL)
    record('it stores them', r.get('after') == ['include/general'] and r.get('before') == [], r)
    record('Pro\'s cache has them', in_cache('header', hid), cache_option())
    record('the stored form is what Pro stores', php(f"echo wp_json_encode(get_post_meta({hid},'_elementor_conditions',true));") == '["include\\/general"]')
    record('a draft is not verified', r.get('verified') is False and r.get('verified_reason') == 'not_published', r)
    code, html = visitor('/')
    record('and a visitor does not see it', 'elementor-location-header' not in html, html[:200])
    rev = r.get('conditions_revision')
    r = set_conditions(hid, GENERAL)
    record('the same again is no change, with no receipt', r.get('action') == 'no_change' and 'change_receipt' not in r and r.get('cache', {}).get('purged') is None, r)
    r = set_conditions(hid, ['include/singular'], if_revision='sha256:stale')
    record('a stale if_revision is refused', refused(r, 'revision_conflict') and r.get('conditions_revision') == rev, r)
    listed = call('list_elementor_templates', {'template_type': 'header', 'status': 'any'}).get('templates', [])
    mine = [t for t in listed if t['id'] == hid]
    record('list_elementor_templates gives the revision', mine and mine[0].get('conditions_revision') == rev, listed)

    print('# filled, published, and on the site')
    r = call('update_post', {'id': hid, 'status': 'publish'})
    record('update_post will not publish it empty', refused(r, 'empty_template') and status_of(hid) == 'draft', r)
    r = call('update_post', {'id': hid, 'status': 'publish', 'dry_run': True})
    record('nor will its dry run say it would', refused(r, 'empty_template'), r)
    filled = call('update_elementor_data', {'id': hid, 'elementor_data': tree('tb-marker-header')})
    record('update_elementor_data fills it', filled.get('success') is True, filled)
    pub = call('update_post', {'id': hid, 'status': 'publish'})
    record('update_post publishes it', status_of(hid) == 'publish', pub)
    page = int(wp('post', 'create', '--post_type=page', '--post_status=publish', f'--post_title={T} page', f'--post_content={T} body', '--porcelain'))
    post = int(wp('post', 'create', '--post_type=post', '--post_status=publish', f'--post_title={T} post', f'--post_content={T} body', '--porcelain'))
    for label, path in (('the home page', '/'), ('a page', f'/?page_id={page}'), ('a post', f'/?p={post}')):
        code, html = visitor(path)
        record(f'a logged-out visitor sees it on {label}', code == 200 and 'tb-marker-header' in html and 'elementor-location-header' in html, (code, html[:200]))
    url = php(f"echo get_permalink({page});")
    r = call('resolve_url', {'url': url})
    record('resolve_url names it for that page', (r.get('theme_locations') or {}).get('header', {}).get('template_id') == hid, r)
    record('and the page is still what renders', r.get('effective_render_id') == page and r.get('rendering_template_id') is None and r.get('route_type') == 'page', r)
    r = set_conditions(hid, ['include/singular'])
    record('verified on a narrower condition', r.get('verified') is True, r)
    r = set_conditions(hid, GENERAL)
    record('verified on Entire Site, by the home page', r.get('verified') is True, r)
    back = r['change_receipt']

    print('# overlap')
    second = create('second header', 'header', conditions=GENERAL, status='publish', elementor_data=tree('tb-marker-second'))
    record('a second Entire Site header is warned about', any(o['template_id'] == hid and o['relation'] == 'same' for o in second.get('overlap_warnings', [])), second)
    code, html = visitor('/')
    winner = (call('resolve_url', {'url': php("echo home_url('/');")}).get('theme_locations') or {}).get('header', {}).get('template_id')
    record('resolve_url agrees with what a visitor sees', winner == (second['id'] if 'tb-marker-second' in html else hid), (winner, 'tb-marker-second' in html, 'tb-marker-header' in html))
    record('verified agrees with that', second.get('verified') == (winner == second['id']), second)
    r = undo(second['change_receipt'])
    record('an untouched template rolls back with no force', r.get('success') is True and status_of(second['id']) == 'trash' and not in_cache('header', second['id']), r)

    print('# what is stored but cannot do what it says')
    r = create('empty header', 'header', conditions=GENERAL, status='publish')
    record('an empty header cannot be published with conditions', refused(r, 'empty_template') and "theme's header" in r.get('message', ''), r)
    empty = create('empty on purpose', 'header', status='publish')
    record('with no conditions it can', empty.get('status') == 'publish', empty)
    r = set_conditions(empty['id'], ['include/singular/page/999999'])
    record('conditions on it warn, and are not verified', r.get('verified_reason') == 'empty_template' and r.get('warnings'), r)
    undo(r['change_receipt'])
    undo(empty['change_receipt'], force=True)
    single = create('single on an archive', 'single-page', conditions=['include/archive'], status='publish', elementor_data=tree('tb-marker-single'))
    record('an archive condition on a single template warns, and is not refused', single.get('verified_reason') == 'condition_cannot_render' and single.get('warnings'), single)
    undo(single['change_receipt'], force=True)

    print('# a stale cache')
    php(f"update_option('elementor_pro_theme_builder_conditions',[]);")
    r = set_conditions(hid, GENERAL, dry_run=True)
    record('a dry run says would_repair_cache', r.get('would_repair_cache') is True and not in_cache('header', hid), r)
    r = set_conditions(hid, GENERAL)
    record('the same conditions rebuild it', r.get('cache_repaired') is True and in_cache('header', hid) and 'change_receipt' not in r, r)
    record('purging the whole site', r.get('cache', {}).get('scope') == 'all', r.get('cache'))

    print('# popups and sections')
    p1 = create('popup one', 'popup', conditions=GENERAL, status='publish')
    p2 = create('popup two', 'popup', conditions=GENERAL, status='publish')
    record('popups keep conditions in the same cache', in_cache('popup', p1['id']) and in_cache('popup', p2['id']), cache_option())
    record('two on Entire Site do not compete', 'overlap_warnings' not in p2, p2)
    for x in (p2, p1):
        undo(x['change_receipt'])
    sec = create('section', 'section')
    r = set_conditions(sec['id'], GENERAL)
    record('a section has no location, so conditions are refused', refused(r, 'conditions_not_supported'), r)
    undo(sec['change_receipt'])

    print('# pages that hide the header')
    canvas = int(wp('post', 'create', '--post_type=page', '--post_status=publish', f'--post_title={T} canvas', f'--post_content={T} body', '--porcelain'))
    wp('post', 'meta', 'update', str(canvas), '_wp_page_template', 'elementor_canvas')
    code, html = visitor(f'/?page_id={canvas}')
    record('a page on elementor_canvas shows no header', 'tb-marker-header' not in html, html[:200])
    r = call('set_page_settings', {'id': canvas, 'settings': {'page_template': 'elementor_header_footer'}})
    code, html = visitor(f'/?page_id={canvas}')
    record('elementor_header_footer through set_page_settings shows it', 'tb-marker-header' in html, r)
    wp('post', 'meta', 'update', str(canvas), '_wp_page_template', 'elementor_canvas')
    c = call('resolve_url', {'url': php(f"echo get_permalink({canvas});")})
    record('resolve_url says Canvas hides them', c.get('theme_locations') == {'header': None, 'footer': None, 'hidden_by_page_template': 'elementor_canvas'}, c)
    saved_front = (wp('option', 'get', 'show_on_front'), wp('option', 'get', 'page_on_front'))
    wp('option', 'update', 'show_on_front', 'page')
    wp('option', 'update', 'page_on_front', str(canvas))
    try:
        v = set_conditions(hid, ['include/general', 'include/singular'])
        v = set_conditions(hid, GENERAL)
        record('a Canvas home page means it is not verified', v.get('verified') is False and v.get('verified_reason') == 'home_page_template_hides_it', v)
    finally:
        wp('option', 'update', 'show_on_front', saved_front[0])
        wp('option', 'update', 'page_on_front', saved_front[1] or '0')

    print('# rollback of conditions')
    r = undo(back)
    record('rollback puts the conditions back through Pro', r.get('success') is True and php(f"echo wp_json_encode(get_post_meta({hid},'_elementor_conditions',true));") == '["include\\/singular"]', r)
    code, html = visitor('/')
    record('so the home page has it no more', 'tb-marker-header' not in html, html[:200])

    print('# rollback of a filled template')
    set_conditions(hid, GENERAL)
    r = undo(made['change_receipt'])
    record('a filled live header needs force, and the refusal says why', refused(r, 'rollback_conflict') and 'live site-wide header' in r.get('message', '') and 'force: true' in r.get('message', ''), r)
    code, html = visitor('/')
    record('and it is still on the site', 'tb-marker-header' in html, html[:200])
    r = undo(made['change_receipt'], force=True)
    record('force trashes it', r.get('success') is True and status_of(hid) == 'trash', r)
    record('and takes it out of Pro\'s cache', not in_cache('header', hid), cache_option())
    record('purging the whole site', r.get('cache', {}).get('scope') == 'all', r.get('cache'))
    code, html = visitor('/')
    record('so a visitor has it no more', 'tb-marker-header' not in html and 'elementor-location-header' not in html, html[:200])

    print('# the whole workflow: a header with a menu, in one call')
    menu = call('create_menu', {'name': f'{T} nav'})
    call('create_menu_item', {'menu_id': menu['menu_id'], 'title': 'Contact', 'url': '#contact'})
    nav = json.dumps([{'id': 'tb3aaaa', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'tb4aaaa', 'elType': 'widget', 'widgetType': 'nav-menu', 'settings': {'menu': menu['slug'], 'layout': 'horizontal'}, 'elements': []}]}])
    whole = create('nav header', 'header', conditions=GENERAL, status='publish', elementor_data=nav)
    record('it is published and verified', whole.get('status') == 'publish' and whole.get('verified') is True, whole)
    record('Elementor stored the tree', whole.get('elementor_save') is True and whole.get('stored_by') == 'elementor', whole)
    code, html = visitor(f'/?p={post}')
    record('the Nav Menu widget renders the menu, with its hamburger', 'href="#contact"' in html and 'elementor-menu-toggle' in html, html[:200])
    r = undo(whole['change_receipt'])
    record('nothing happened since, so no force is needed', r.get('success') is True, r)
    code, html = visitor(f'/?p={post}')
    record('and the header is gone', 'href="#contact"' not in html and 'elementor-location-header' not in html, html[:200])
    undo(menu['change_receipt'], force=True)

failed = [n for n, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('failed:', '; '.join(failed)[:800])
sys.exit(1 if failed else 0)

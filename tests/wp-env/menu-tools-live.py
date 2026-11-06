"""Driven by menu-tools-live.sh."""
import base64
import json
import os
import subprocess
import sys
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
PW = os.environ['PW_AUTHOR']
MARKER = os.environ['THEME_MARKER']
T = 'menu-tools-live'
A, B = 'iato-live-a', 'iato-live-b'
results = []


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:400]}'))


def wp(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    lines = [l.strip('\r') for l in out.splitlines() if l and not l.startswith(('ℹ', '✔'))]
    return lines[-1] if lines else ''


def php(code):
    return wp('eval', code, '--user=admin')


def rpc(method, params, who='key'):
    auth = 'Bearer ' + KEY if who == 'key' else 'Basic ' + base64.b64encode(f'author:{PW}'.encode()).decode()
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
    if res.get('isError'):
        data['isError'] = True
        data['_text'] = text
    return data


def refused(result, code):
    return bool(result.get('isError')) and (result.get('error') == code or code in result.get('_text', ''))


def forbidden(result):
    return bool(result.get('isError')) and 'edit_theme_options' in result.get('_text', '')


def held(location):
    """What the theme mod really holds, past the plugin."""
    return int(php(f"$l=get_nav_menu_locations();echo (int)($l['{location}']??0);") or 0)


def menu_exists(menu_id):
    return '1' == php(f"echo is_nav_menu({int(menu_id)})?1:0;")


def menus_named(name):
    return int(php(f"echo count(array_filter(wp_get_nav_menus(),fn($m)=>$m->name==='{name}'));") or 0)


def undo(receipt, **opts):
    return call('rollback', {'change_id': receipt['change_id'], **opts})


print('Elementor', wp('plugin', 'get', 'elementor', '--field=version'), 'WordPress', wp('core', 'version'), 'theme', wp('option', 'get', 'stylesheet'), 'on', BASE)

print('# get_menus')
d = call('get_menus', {})
slots = {l['slug']: l for l in d.get('locations', [])}
record('locations lists what the theme registers, with labels', slots.get(A, {}).get('label') == 'Live test A' and slots.get(B, {}).get('label') == 'Live test B', d)
record('an empty location is null', slots.get(A, {}).get('menu_id') is None, slots)
record('menus is still a list', isinstance(d.get('menus'), list), d)
record('an Author can read them, as before', 'locations' in call('get_menus', {}, 'author'))

print('# create_menu: refusals')
name = f'{T} main'
r = call('create_menu', {'name': name}, 'author')
record('an Author is refused', forbidden(r) and menus_named(name) == 0, r)
r = call('create_menu', {'name': '  '})
record('an empty name is refused', refused(r, 'missing_name'), r)
r = call('create_menu', {'name': name, 'location': 'no-such-slot'})
record('an unknown location is refused and lists the real ones', refused(r, 'unknown_menu_location') and A in [l['slug'] for l in r.get('locations', [])], r)
record('and the menu was not made', menus_named(name) == 0)

print('# create_menu: dry run')
r = call('create_menu', {'name': name, 'location': A, 'dry_run': True})
record('it says what would happen', r.get('dry_run') is True and r.get('action') == 'would_create' and r.get('location') == A, r)
record('it wrote nothing', menus_named(name) == 0 and held(A) == 0)
record('it has no cache object', 'cache' not in r, r)

print('# create_menu')
made = call('create_menu', {'name': name})
mid = made.get('menu_id')
record('it returns the ID, name, slug and no location', isinstance(mid, int) and made.get('name') == name and made.get('slug') == 'menu-tools-live-main' and made.get('location') is None, made)
record('the menu exists', menu_exists(mid))
record('it purged the whole site', made.get('cache', {}).get('scope') == 'all', made.get('cache'))
record('it has a receipt', made.get('change_receipt', {}).get('target_type') == 'menu', made.get('change_receipt'))
r = call('create_menu', {'name': name, 'dry_run': True})
record('a name in use is refused in a dry run', refused(r, 'menu_exists') and r.get('menu_id') == mid, r)
r = call('create_menu', {'name': name})
record('and for real', refused(r, 'menu_exists') and menus_named(name) == 1, r)
amp = call('create_menu', {'name': f'{T} a & b'})
record('a name is returned as stored', amp.get('name') == f'{T} a &amp; b', amp)
r = call('create_menu', {'name': f'{T} a & b'})
record('and compared as stored', refused(r, 'menu_exists'), r)
record('that menu rolls back', undo(amp['change_receipt']).get('success') is True and not menu_exists(amp['menu_id']))
slashed = f'{T} C:' + chr(92) + 'Users'
r = call('create_menu', {'name': slashed})
record('a backslash in a name survives', r.get('name') == slashed and php(f"echo wp_get_nav_menu_object({r.get('menu_id', 0)})->name;") == slashed, r)
record('and a name with one is found again', refused(call('create_menu', {'name': slashed}), 'menu_exists'))
undo(r['change_receipt'])

print('# create_menu_item on a menu made in the same session')
page = int(wp('post', 'create', '--post_type=page', '--post_status=publish', f'--post_title={T} page', '--porcelain'))
i1 = call('create_menu_item', {'menu_id': mid, 'title': 'Contact', 'url': '#contact', 'position': 1})
i2 = call('create_menu_item', {'menu_id': mid, 'title': 'Our page', 'post_id': page, 'position': 2})
i3 = call('create_menu_item', {'menu_id': mid, 'title': 'Child', 'url': 'https://example.test/child/', 'position': 3, 'parent_id': i2.get('menu_item_id', 0)})
record('three items were added', all(x.get('menu_item_id') for x in (i1, i2, i3)), [i1, i2, i3])
items = call('get_menu_items', {'menu_id': mid}).get('items', [])
record('they come back in order', [x['title'] for x in items] == ['Contact', 'Our page', 'Child'], items)
record('an #anchor URL is kept', items and items[0]['url'] == '#contact', items)
record('the page link points at the page', len(items) > 1 and items[1]['post_id'] == page, items)
record('the child has its parent', len(items) > 2 and items[2]['parent_id'] == i2.get('menu_item_id'), items)

print('# assign_menu_location')
r = call('assign_menu_location', {'location': A, 'menu_id': mid}, 'author')
record('an Author is refused', forbidden(r) and held(A) == 0, r)
r = call('assign_menu_location', {'location': 'no-such-slot', 'menu_id': mid})
record('an unknown location is refused', refused(r, 'unknown_menu_location'), r)
r = call('assign_menu_location', {'location': A})
record('leaving menu_id out does not clear anything', refused(r, 'invalid_menu_id'), r)
r = call('assign_menu_location', {'location': A, 'menu_id': 999999})
record('a menu that does not exist is refused', refused(r, 'not_found'), r)
r = call('assign_menu_location', {'location': A, 'menu_id': mid, 'dry_run': True})
record('a dry run says would_assign and writes nothing', r.get('action') == 'would_assign' and held(A) == 0 and 'cache' not in r, r)
first = call('assign_menu_location', {'location': A, 'menu_id': mid})
record('it assigns', held(A) == mid and first.get('before') == {'menu_id': None} and first.get('after') == {'menu_id': mid}, first)
record('it purged the whole site', first.get('cache', {}).get('scope') == 'all', first.get('cache'))
record('get_menus agrees', {l['slug']: l['menu_id'] for l in call('get_menus', {})['locations']}.get(A) == mid)
html = base64.b64decode(php(f"echo base64_encode((string)wp_nav_menu(['theme_location'=>'{A}','echo'=>false,'fallback_cb'=>false]));")).decode()
record('it really renders, with the anchor, the page and a nested list', all(x in html for x in ('href="#contact"', 'Our page', 'sub-menu', 'example.test/child/')), html[:400])
r = call('assign_menu_location', {'location': A, 'menu_id': mid})
record('the same menu again is no change, with no receipt', r.get('action') == 'no_change' and 'change_receipt' not in r and r.get('cache', {}).get('purged') is None, r)

print('# assign_menu_location: rollback')
other = call('create_menu', {'name': f'{T} other'})
moved = call('assign_menu_location', {'location': A, 'menu_id': other['menu_id']})
record('moving a menu in', held(A) == other['menu_id'] and moved['before'] == {'menu_id': mid}, moved)
d = undo(moved['change_receipt'], dry_run=True)
record('a rollback dry run changes nothing', d.get('would_roll_back') is True and held(A) == other['menu_id'], d)
r = undo(moved['change_receipt'])
record('rollback puts the old menu back', r.get('success') is True and held(A) == mid, r)
r = undo(moved['change_receipt'])
record('and cannot run twice', refused(r, 'already_rolled_back'), r)
cleared = call('assign_menu_location', {'location': A, 'menu_id': 0})
record('zero clears the location, and the menu stays', held(A) == 0 and cleared['after'] == {'menu_id': None} and menu_exists(mid), cleared)
r = undo(cleared['change_receipt'])
record('rollback of a clear puts it back', r.get('success') is True and held(A) == mid, r)
moved = call('assign_menu_location', {'location': B, 'menu_id': mid})
call('assign_menu_location', {'location': B, 'menu_id': other['menu_id']})
r = undo(moved['change_receipt'])
record('a location changed since is a conflict', refused(r, 'rollback_conflict') and held(B) == other['menu_id'], r)
r = undo(moved['change_receipt'], force=True)
record('force rolls it back anyway', r.get('success') is True and held(B) == 0, r)

print('# rollback after a theme switch')
theme = wp('option', 'get', 'stylesheet')
listed = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', 'theme', 'list', '--status=inactive', '--field=name'], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
inactive = [l.strip() for l in listed.splitlines() if l.strip() and not l.startswith(('ℹ', '✔'))]
if not inactive:
    print('  note: no second theme is installed, so the theme switch was not tested')
else:
    on_a = call('assign_menu_location', {'location': B, 'menu_id': mid})
    made_b = call('create_menu', {'name': f'{T} themed', 'location': B})
    with open(MARKER, 'w') as f:
        f.write(theme)
    wp('theme', 'activate', inactive[0])
    try:
        # WordPress copies same-named locations to the new theme (_wp_menus_changed()).
        on_new_theme = held(B)
        r = undo(on_a['change_receipt'])
        record('a location rollback is unavailable, and names both themes', refused(r, 'rollback_unavailable') and theme in r.get('message', '') and inactive[0] in r.get('message', ''), r)
        r = undo(on_a['change_receipt'], force=True)
        record('force does not get past it', refused(r, 'rollback_unavailable'), r)
        r = undo(made_b['change_receipt'], force=True)
        record('nor for a menu made with a location', refused(r, 'rollback_unavailable') and menu_exists(made_b['menu_id']), r)
        record('the new theme\'s location was not touched', held(B) == on_new_theme, held(B))
    finally:
        wp('theme', 'activate', theme)
        os.remove(MARKER)
    record('back on the first theme, the location is as it was', held(B) == made_b['menu_id'])
    r = undo(made_b['change_receipt'])
    record('and the rollback works again, giving the location back', r.get('success') is True and held(B) == mid and not menu_exists(made_b['menu_id']), r)
    call('assign_menu_location', {'location': B, 'menu_id': 0})

print('# create_menu: rollback')
held_menu = call('create_menu', {'name': f'{T} replacing', 'location': A})
record('a menu made with a location takes it and says what it replaced', held(A) == held_menu['menu_id'] and held_menu.get('replaced_menu_id') == mid, held_menu)
r = undo(held_menu['change_receipt'])
record('rollback deletes it and puts the old menu back', r.get('success') is True and held(A) == mid and not menu_exists(held_menu['menu_id']), r)

d = undo(made['change_receipt'], dry_run=True)
record('a menu with items: the dry run says it would not', d.get('would_roll_back') is False and d.get('conflict_check', {}).get('status') == 'conflict', d)
r = undo(made['change_receipt'])
record('a menu with items is refused, naming how many', refused(r, 'rollback_conflict') and '3 items' in r.get('message', '') and 'permanently' in r.get('message', ''), r)
record('and it still has them', len(call('get_menu_items', {'menu_id': mid}).get('items', [])) == 3)
r = undo(i3['change_receipt'])
record('rolling back an item works', r.get('success') is True, r)
r = undo(made['change_receipt'], force=True)
record('force deletes the menu', r.get('success') is True and r.get('conflict_check', {}).get('forced') is True and not menu_exists(mid), r)
leftovers = php(f"echo count(get_posts(['post_type'=>'nav_menu_item','post_status'=>'any','post__in'=>[{i1['menu_item_id']},{i2['menu_item_id']}],'numberposts'=>-1]));")
record('and its items', leftovers == '0', leftovers)
record('a location it held is empty', held(A) == 0)
later = call('assign_menu_location', {'location': B, 'menu_id': other['menu_id']})
r = undo(other['change_receipt'])
record('a menu put in a location later is not deleted out of it', refused(r, 'rollback_conflict') and 'Roll back the location change first' in r.get('message', '') and menu_exists(other['menu_id']), r)
r = undo(later['change_receipt'])
record('undoing the location first works', r.get('success') is True and held(B) == 0, r)
r = undo(other['change_receipt'])
record('and then the menu rolls back with no force', r.get('success') is True and not menu_exists(other['menu_id']), r)

print('# resolve_url and site-wide templates')
def template(title, kind, conditions):
    tid = int(wp('post', 'create', '--post_type=elementor_library', '--post_status=publish', f'--post_title={T} {title}', '--porcelain'))
    php(f"update_post_meta({tid},'_elementor_template_type','{kind}');update_post_meta({tid},'_elementor_conditions',{json.dumps(conditions).replace('[', 'array(').replace(']', ')')});")
    return tid
url = php(f"echo get_permalink({page});")
tid = template('entire site header', 'header', ['include/general'])
r = call('resolve_url', {'url': url})
record('a header on Entire Site does not shadow a page', r.get('rendering_template_id') is None and r.get('effective_render_id') == page and r.get('route_type') == 'page', r)
d = call('get_post', {'id': page, 'include_shadowing': True})
record('get_post does not blame it', not d.get('is_shadowed_by'), d.get('is_shadowed_by'))
wp('post', 'delete', str(tid), '--force')
tid = template('single page', 'single-page', [f'include/singular/page/{page}'])
r = call('resolve_url', {'url': url})
record('a single-page template for that page still does', r.get('rendering_template_id') == tid and r.get('shadowed_post_id') == page and r.get('route_type') == 'theme_builder', r)
record('its template field keeps its shape', sorted(r.get('template', {}).keys()) == ['builder', 'condition_matched', 'template_id', 'template_type'], r.get('template'))
wp('post', 'delete', str(tid), '--force')

pro = '1' == php("echo class_exists('\\ElementorPro\\Modules\\ThemeBuilder\\Module')?1:0;")
if not pro:
    print('  note: no Elementor Pro on this site, so theme_locations is only checked to be null')
    r = call('resolve_url', {'url': url})
    record('without Pro, theme_locations is null', 'theme_locations' in r and r['theme_locations'] is None, r)
else:
    print('# theme_locations with real Elementor Pro')
    manager = "\\ElementorPro\\Modules\\ThemeBuilder\\Module::instance()->get_conditions_manager()"
    def pro_template(kind, conditions):
        tid = int(php(f"$d=\\Elementor\\Plugin::$instance->documents->create('{kind}',['post_title'=>'{T} pro {kind}','post_status'=>'publish'],[]);echo $d->get_main_id();"))
        rules = ','.join(f"['type'=>'{a}','name'=>'{b}','sub_name'=>'{c}','sub_id'=>'{e}']" for a, b, c, e in conditions)
        php(f"{manager}->save_conditions({tid},[{rules}]);")
        return tid
    header = pro_template('header', [('include', 'general', '', '')])
    footer = pro_template('footer', [('include', 'general', '', ''), ('exclude', 'singular', 'page', str(page))])
    home = php("echo home_url('/');")
    r = call('resolve_url', {'url': home}).get('theme_locations') or {}
    record('the home page gets the header and footer', r.get('header', {}).get('template_id') == header and r.get('footer', {}).get('template_id') == footer, r)
    r2 = call('resolve_url', {'url': url})
    record('the page gets the header, and is still what renders', r2['theme_locations']['header']['template_id'] == header and r2.get('rendering_template_id') is None, r2)
    record('and not the footer that excludes it', r2['theme_locations']['footer'] is None, r2['theme_locations'])
    record('the matching condition is there', r2['theme_locations']['header']['condition_matched'] == 'include/general', r2['theme_locations'])
    wp('post', 'update', str(header), '--post_status=draft')
    r3 = call('resolve_url', {'url': url})['theme_locations']
    record('a draft does not count', r3['header'] is None, r3)
    wp('post', 'update', str(header), '--post_status=publish')
    php(f"{manager}->save_conditions({header},[['type'=>'include','name'=>'singular','sub_name'=>'child_of','sub_id'=>'{page}']]);")
    r4 = call('resolve_url', {'url': url})['theme_locations']
    record('a condition it cannot judge is named', r4['header'] is None and any(n.get('template_id') == header for n in r4.get('not_evaluated', [])), r4)
    r6 = call('resolve_url', {'url': url}, 'author')['theme_locations']
    record('to an Author, only that the answer may be incomplete', r6.get('not_evaluated') == [{'location': 'header'}], r6)
    php(f"wp_trash_post({header});wp_trash_post({footer});{manager}->get_cache()->regenerate();")
    r5 = call('resolve_url', {'url': url})['theme_locations']
    record('trashed and regenerated, both are gone', r5['header'] is None and r5['footer'] is None, r5)
    for x in (header, footer):
        wp('post', 'delete', str(x), '--force')

print('# what WordPress says a URL is')
def route(path):
    return call('resolve_url', {'url': BASE + path}).get('route_type')
record('a term that exists is its archive', route('/category/uncategorized/') == 'category_archive')
record('a term that does not exist is a 404', route('/category/menu-tools-live-no-such-term/') == '404')
record('a later page past the end is a 404', route('/category/uncategorized/page/99/') == '404')
record('a search URL', route('/?s=menu-tools-live') == 'search_results' and route('/search/menu-tools-live/') == 'search_results')
record('an author that does not exist is a 404', route('/author/menu-tools-live-nobody/') == '404')
record('an author that exists', route('/author/admin/') == 'author_archive')
record('a category by query string', route('/?cat=1') == 'category_archive')
record('a page that does not exist', route('/menu-tools-live-no-such-page/') == '404')
other = call('resolve_url', {'url': 'https://other.example/about/'})
record('another host is nothing here', other.get('route_type') == '404' and other.get('theme_locations') is None, other)
saved_front = (wp('option', 'get', 'show_on_front'), wp('option', 'get', 'page_on_front'))
front = int(wp('post', 'create', '--post_type=page', '--post_status=publish', f'--post_title={T} front', '--porcelain'))
wp('option', 'update', 'show_on_front', 'page')
wp('option', 'update', 'page_on_front', str(front))
try:
    home_says = call('resolve_url', {'url': BASE + '/'})
    record('a static front page is the page', home_says.get('rendering_post_id') == front, home_says)
    q = call('resolve_url', {'url': BASE + '/?cat=1'})
    record('its URL with ?cat=1 is the category, not the page', q.get('rendering_post_id') is None and q.get('route_type') == 'category_archive', q)
finally:
    wp('option', 'update', 'show_on_front', saved_front[0])
    wp('option', 'update', 'page_on_front', saved_front[1] or '0')

print('# the upgrade backfill')
saved = php("echo json_encode(get_option('iato_mcp_tools',null));")
version = wp('option', 'get', 'iato_mcp_db_version')
php("update_option('iato_mcp_tools',['get_menus','rollback']);update_option('iato_mcp_db_version','1.16.0',false);")
try:
    listed = [t['name'] for t in rpc('tools/list', {}).get('tools', [])]  # this request runs the migration
    listed = [t['name'] for t in rpc('tools/list', {}).get('tools', [])]  # and this one sees its result
    record('a site upgraded with a saved tool list gets the new tools', all(t in listed for t in ('create_menu', 'assign_menu_location', 'create_elementor_template', 'set_elementor_template_conditions')), listed)
finally:
    restore = "delete_option('iato_mcp_tools');" if saved in ('null', '') else f"update_option('iato_mcp_tools',json_decode(base64_decode('{base64.b64encode(saved.encode()).decode()}'),true));"
    php(restore + f"update_option('iato_mcp_db_version','{version}',false);")

print('# what clients are told')
text = rpc('initialize', {'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'menu-tools-live', 'version': '1'}}).get('instructions', '')
record('the menu workflow is in the instructions', 'create_menu' in text and 'assign_menu_location' in text, text[:200])
record('so is theme_locations', 'theme_locations' in text, text[:200])

failed = [n for n, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('failed:', '; '.join(failed)[:800])
sys.exit(1 if failed else 0)

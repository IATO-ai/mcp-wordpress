"""Driven by site-icon-live.sh."""
import base64
import json
import os
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
ADMIN_ID = int(os.environ['ADMIN_ID'])
MARKER = os.environ['THEME_MARKER']
T = 'site-icon-live'
PASSWORDS = {'admin': os.environ['PW_ADMIN'], 'editor': os.environ['PW_EDITOR'], 'author': os.environ['PW_AUTHOR']}
results = []


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:500]}'))


def run(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    return [line.strip('\r') for line in out.splitlines() if line and not line.startswith(('ℹ', '✔'))]


def wp(*args):
    lines = run('wp', *args)
    return lines[-1] if lines else ''


def php(code):
    return wp('eval', code, '--user=admin')


def php_all(code):
    return '\n'.join(run('wp', 'eval', code, '--user=admin'))


def rpc(method, params, who='key'):
    if 'key' == who:
        auth = 'Bearer ' + KEY
    elif 'oauth' == who:
        auth = 'Bearer ' + os.environ['OAUTH_TOKEN']
    else:
        auth = 'Basic ' + base64.b64encode(f'{who}:{PASSWORDS[who]}'.encode()).decode()
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
    return bool(result.get('isError')) and result.get('error') == code


def get(url):
    try:
        with urllib.request.urlopen(url, timeout=60) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as error:
        return error.code, b''


def png_size(data):
    return struct.unpack('>II', data[16:24]) if data[:8] == b'\x89PNG\r\n\x1a\n' else None


def icon():
    return int(php("echo (int)get_option('site_icon');") or 0)


def logo():
    return int(php("echo (int)get_theme_mod('custom_logo',0);") or 0)


def sizes_of(attachment):
    return json.loads(php(f"$m=wp_get_attachment_metadata({attachment});echo wp_json_encode(array_keys($m['sizes']??[]));") or '[]')


def exists(attachment):
    return '1' == php(f"echo get_post({attachment})?1:0;")


def home():
    return get(BASE + '/?nocache=' + str(time.time()))[1].decode('utf-8', 'replace')


KINDS = {'png': ('png', 'image/png'), 'jpeg': ('jpg', 'image/jpeg'), 'gif': ('gif', 'image/gif'), 'webp': ('webp', 'image/webp')}


def upload(name, width, height, kind='png', **extra):
    ext, mime = KINDS[kind]
    data = php(f"$i=imagecreatetruecolor({width},{height});imagefill($i,0,0,imagecolorallocate($i,30,120,200));ob_start();image{kind}($i);echo base64_encode(ob_get_clean());")
    result = call('create_media', {'filename': f'{T}-{name}.{ext}', 'mime_type': mime, 'title': f'{T} {name}', 'alt_text': 'test image', 'source': {'type': 'base64', 'data': data}, **extra})
    if not result.get('attachment_id'):
        print(f'  FAIL  upload {name}: {json.dumps(result)[:300]}')
        sys.exit(1)
    return result


def insert_file(name, ext, mime, content):
    """An attachment create_media would refuse, as any other route could leave one."""
    return int(php(f"$f=wp_upload_dir()['path'].'/{T}-{name}.{ext}';file_put_contents($f,'{content}');echo wp_insert_attachment(['post_mime_type'=>'{mime}','post_title'=>'{T} {name}','post_status'=>'inherit'],$f);"))


# ── Setting up ──────────────────────────────────────────────────────────────
print('# Setting up')
EXPECTED = json.loads(php("require_once ABSPATH.'wp-admin/includes/class-wp-site-icon.php';echo wp_json_encode(array_keys((new WP_Site_Icon())->additional_sizes([])));"))
record('WordPress lists the sizes it expects', 'site_icon-32' in EXPECTED and 'site_icon-192' in EXPECTED and 'site_icon-512' not in EXPECTED, EXPECTED)
wp('option', 'update', 'site_icon', '0')
php("remove_theme_mod('custom_logo');")
KIT = int(php("echo (int)get_option('elementor_active_kit');") or 0)
if KIT:
    php(f"$s=get_post_meta({KIT},'_elementor_page_settings',true);$s=is_array($s)?$s:[];unset($s['site_favicon'],$s['site_logo']);update_post_meta({KIT},'_elementor_page_settings',wp_slash($s));echo 1;")

listed = rpc('tools/list', {})['tools']
tool = next(t for t in listed if t['name'] == 'update_site_settings')
record('tools/list offers site_icon and custom_logo', tool['inputSchema']['properties']['site_icon']['type'] == 'integer' and tool['inputSchema']['properties']['custom_logo']['type'] == 'integer', tool['inputSchema'])
record('its description says favicon, square, 512x512, and 0 clears it', all(word in tool['description'] for word in ('favicon', 'square', '512x512', '0 clears it')), tool['description'])

SQUARE = upload('square', 512, 512)['attachment_id']
OTHER = upload('other', 640, 640)['attachment_id']

# ── dry_run ─────────────────────────────────────────────────────────────────
print('# dry_run')
meta_before = php(f"echo wp_json_encode(wp_get_attachment_metadata({SQUARE}));")
r = call('update_site_settings', {'site_icon': SQUARE, 'dry_run': True})
record('reports before and after', r.get('dry_run') is True and r['changes']['site_icon']['before'] == {'id': 0, 'url': None} and r['changes']['site_icon']['after']['id'] == SQUARE, r)
record('lists the sizes it would create', sorted(r.get('would_create_sizes', [])) == sorted(EXPECTED) and r.get('not_checked'), r)
record('no receipt and no purge', 'change_receipt' not in r and 'cache' not in r, r)
record('writes nothing', 0 == icon() and meta_before == php(f"echo wp_json_encode(wp_get_attachment_metadata({SQUARE}));"), icon())

# ── A valid square PNG ──────────────────────────────────────────────────────
print('# A valid square PNG')
mtime = php(f"$m=wp_get_attachment_metadata({SQUARE});$f=dirname(get_attached_file({SQUARE})).'/'.$m['sizes']['thumbnail']['file'];touch($f,time()-5000);clearstatcache();echo filemtime($f);")
r = call('update_site_settings', {'site_icon': SQUARE})
record('the option holds the ID', SQUARE == icon(), r)
change = r.get('changes', {}).get('site_icon', {})
record('before and after, with ID and URL', change.get('before') == {'id': 0, 'url': None} and change.get('after', {}).get('id') == SQUARE and f'{T}-square' in change.get('after', {}).get('url', ''), change)
record('a receipt: site_setting, site_icon, 0 to the ID', r.get('change_receipt', {}).get('target_type') == 'site_setting' and r['change_receipt'].get('field') == 'site_icon' and r['change_receipt'].get('before_value') == '0' and r['change_receipt'].get('after_value') == str(SQUARE), r.get('change_receipt'))
record('the whole site is purged', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
record('no warnings at 512', r.get('warnings') == [], r.get('warnings'))
record("WordPress's sizes were created", sorted(r.get('sizes_created', [])) == sorted(EXPECTED) and set(EXPECTED) <= set(sizes_of(SQUARE)), r.get('sizes_created'))
record('nothing else was rewritten', mtime == php(f"$m=wp_get_attachment_metadata({SQUARE});$f=dirname(get_attached_file({SQUARE})).'/'.$m['sizes']['thumbnail']['file'];clearstatcache();echo filemtime($f);"), mtime)
for size in (32, 192):
    url = r.get(f'icon_url_{size}') or ''
    status, data = get(url) if url else (0, b'')
    record(f'icon_url_{size} is a real {size}x{size} PNG', url.endswith(f'-{size}x{size}.png') and status == 200 and png_size(data) == (size, size), url)
s = call('get_site_settings', {})
record('get_site_settings shows the same ID and a URL', s.get('site_icon', {}).get('id') == SQUARE and f'{T}-square' in s['site_icon'].get('url', ''), s.get('site_icon'))

raw = php_all('wp_site_icon();')
record('wp_site_icon() prints link rel="icon" with the sized files', 'rel="icon"' in raw and '-32x32.png' in raw and '-192x192.png' in raw, raw)
page = home()
record('the logged-out home page has link rel="icon" tags at that image', 'rel="icon"' in page and f'{T}-square-32x32.png' in page and f'{T}-square-192x192.png' in page, page[:300])
record('...and the Apple and tile icons', f'{T}-square-180x180.png' in page and f'{T}-square-270x270.png' in page)

# ── Refusals ────────────────────────────────────────────────────────────────
print('# Refusals')
A_PAGE = int(wp('post', 'create', '--post_type=page', '--post_status=publish', f'--post_title={T} page', '--porcelain') or 0)
WIDE_UPLOAD = upload('wide', 600, 400)
WIDE = WIDE_UPLOAD['attachment_id']
SVG = insert_file('svg', 'svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>')
PDF = insert_file('text', 'txt', 'text/plain', 'not an image')
LIAR = insert_file('liar', 'png', 'image/png', 'not an image')
TRASHED = upload('trashed', 512, 512)['attachment_id']
php(f"wp_update_post(['ID'=>{TRASHED},'post_status'=>'trash']);echo 1;")
NO_META = upload('nometa', 512, 512)['attachment_id']
php(f"delete_post_meta({NO_META},'_wp_attachment_metadata');echo 1;")
NO_FILE = upload('nofile', 512, 512)['attachment_id']
php(f"delete_post_meta({NO_FILE},'_wp_attachment_metadata');unlink(get_attached_file({NO_FILE}));echo 1;")

for label, value, code in (
    ('a missing ID', 99999999, 'attachment_not_found'),
    ('a page, not an attachment', A_PAGE, 'not_an_attachment'),
    ('a non-image attachment', PDF, 'unsupported_image_type'),
    ('an SVG', SVG, 'unsupported_image_type'),
    ('a file that is not what it says', LIAR, 'unsupported_image_type'),
    ('an image in the trash', TRASHED, 'attachment_in_trash'),
    ('an image with no metadata yet', NO_META, 'attachment_not_ready'),
    ('no metadata and no file', NO_FILE, 'attachment_unreadable'),
    ('a non-square image', WIDE, 'image_not_square'),
):
    r = call('update_site_settings', {'site_icon': value, 'title': f'{T} must not be written'})
    r2 = call('update_site_settings', {'site_icon': value, 'dry_run': True})
    record(f'{label} is refused with {code}, dry run too', refused(r, code) and refused(r2, code), r)
record('nothing was written by any of them', SQUARE == icon() and T not in wp('option', 'get', 'blogname'), icon())
r = call('update_site_settings', {'site_icon': WIDE})
record('the non-square refusal gives the dimensions', r.get('width') == 600 and r.get('height') == 400 and '600x400' in r.get('message', ''), r)
r = call('update_site_settings', {'site_icon': SVG})
record('the type refusal says what it is and what is allowed', r.get('mime_type') == 'image/svg+xml' and 'image/png' in r.get('allowed', []), r)
for bad in (-1, 'icon', 1.5, True):
    r = call('update_site_settings', {'site_icon': bad})
    record(f'{bad!r} is not an ID', r.get('isError') and 'attachment ID' in r.get('_text', ''), r)
r = call('update_site_settings', {'site_icon': SQUARE, 'siteurl': 'https://evil.example'})
record('another key is still refused', r.get('isError') and 'siteurl' in r.get('_text', ''), r)

# ── Under 512 ───────────────────────────────────────────────────────────────
print('# Under 512')
SMALL = upload('small', 100, 100)['attachment_id']
r = call('update_site_settings', {'site_icon': SMALL, 'dry_run': True})
record('the dry run warns too', '100x100' in ' '.join(r.get('warnings', [])) and SQUARE == icon(), r)
r = call('update_site_settings', {'site_icon': SMALL})
record('written', SMALL == icon(), r)
record('with a warning naming the size', 1 == len(r.get('warnings', [])) and '100x100' in r['warnings'][0], r.get('warnings'))
record('only sizes smaller than the image were made', 'site_icon-32' in r.get('sizes_created', []) and 'site_icon-192' not in r.get('sizes_created', []), r.get('sizes_created'))
record('the 32px URL is sized, the 192px URL is the image itself', r.get('icon_url_32', '').endswith('-32x32.png') and r.get('icon_url_192', '').endswith(f'{T}-small.png'), r)

# ── Other types and sizes ───────────────────────────────────────────────────
print('# Other types')
for kind in ('jpeg', 'gif', 'webp'):
    uploaded = upload(f'type-{kind}', 512, 512, kind)
    r = call('update_site_settings', {'site_icon': uploaded['attachment_id']})
    url = r.get('icon_url_32') or ''
    status, _ = get(url) if url else (0, b'')
    record(f'{kind} is accepted with a sized file', uploaded['attachment_id'] == icon() and '-32x32.' in url and status == 200, r)
LARGE = upload('large', 2600, 2600)['attachment_id']
r = call('update_site_settings', {'site_icon': LARGE})
url = r.get('icon_url_32') or ''
status, data = get(url) if url else (0, b'')
record('an image WordPress scaled down still gets the sizes', LARGE == icon() and url.endswith('-32x32.png') and png_size(data) == (32, 32), r)
s = call('get_site_settings', {})
record('from 512 up the URL is the full image', f'{T}-large' in s['site_icon']['url'] and '-512x512' not in s['site_icon']['url'], s['site_icon'])

# ── Clearing and rollback ───────────────────────────────────────────────────
print('# Clearing and rollback')
wp('option', 'update', 'site_icon', str(SQUARE))
r = call('update_site_settings', {'site_icon': 0, 'dry_run': True})
record('a clear can be previewed', r['changes']['site_icon']['after'] == {'id': 0, 'url': None} and SQUARE == icon(), r)
clear = call('update_site_settings', {'site_icon': 0})
record('0 clears the option', 0 == icon() and '0' == wp('option', 'get', 'site_icon'), clear)
record('the attachment is still there', exists(SQUARE))
record('the home page has no icon tags', 'rel="icon"' not in home())
record('get_site_settings says null', call('get_site_settings', {}).get('site_icon') is None)
record('clearing again is no change', 'change_receipt' not in call('update_site_settings', {'site_icon': 0}))
r = call('rollback', {'change_id': clear['change_receipt']['change_id'], 'dry_run': True})
record('rollback dry run: matches, writes nothing', r.get('conflict_check', {}).get('status') == 'match' and str(r.get('would_restore')) == str(SQUARE) and 0 == icon(), r)
r = call('rollback', {'change_id': clear['change_receipt']['change_id']})
record('rollback of the clear puts the icon back', SQUARE == icon() and 'rel="icon"' in home(), r)

set_other = call('update_site_settings', {'site_icon': OTHER})
record('another icon, recording the previous one', set_other['change_receipt']['before_value'] == str(SQUARE), set_other.get('change_receipt'))
r = call('rollback', {'change_id': set_other['change_receipt']['change_id']})
record('rollback restores the previous attachment', SQUARE == icon() and (r.get('cache') or {}).get('scope') == 'all', r)
record('a receipt cannot be used twice', refused(call('rollback', {'change_id': set_other['change_receipt']['change_id']}), 'already_rolled_back'))

wp('option', 'update', 'site_icon', '0')
first = call('update_site_settings', {'site_icon': OTHER})
r = call('rollback', {'change_id': first['change_receipt']['change_id']})
record('rollback restores 0', 0 == icon() and '0' == wp('option', 'get', 'site_icon'), r)

first = call('update_site_settings', {'site_icon': OTHER})
wp('option', 'update', 'site_icon', str(SQUARE))
r = call('rollback', {'change_id': first['change_receipt']['change_id']})
record('a newer icon blocks the rollback', refused(r, 'rollback_conflict') and SQUARE == icon(), r)
r = call('rollback', {'change_id': first['change_receipt']['change_id'], 'force': True})
record('...force restores it', 0 == icon(), r)

wp('option', 'update', 'site_icon', '')
first = call('update_site_settings', {'site_icon': OTHER})
record('an empty option is recorded as 0', first['change_receipt']['before_value'] == '0', first.get('change_receipt'))
cleared = call('update_site_settings', {'site_icon': 0})
wp('option', 'update', 'site_icon', '')
r = call('rollback', {'change_id': cleared['change_receipt']['change_id']})
record('0 stored as an empty string is no conflict', OTHER == icon(), r)

GONE = upload('gone', 512, 512)['attachment_id']
wp('option', 'update', 'site_icon', str(GONE))
moved = call('update_site_settings', {'site_icon': SQUARE})
wp('post', 'delete', str(GONE), '--force')
for label, extra in (('', {}), (', even forced', {'force': True}), (', and in a dry run', {'dry_run': True})):
    r = call('rollback', {'change_id': moved['change_receipt']['change_id'], **extra})
    record(f'a previous image that has gone is refused{label}', refused(r, 'rollback_unavailable') and SQUARE == icon(), r)
wp('option', 'update', 'site_icon', '999999')
s = call('get_site_settings', {})
record('an option pointing at nothing shows a null url', s.get('site_icon') == {'id': 999999, 'url': None}, s.get('site_icon'))
record('...and no icon tags', 'rel="icon"' not in home())
r = call('update_site_settings', {'site_icon': SQUARE})
record('...and can be replaced', SQUARE == icon() and r['changes']['site_icon']['before'] == {'id': 999999, 'url': None}, r.get('changes'))

# ── An icon set outside this tool ───────────────────────────────────────────
print('# The same ID, for an icon set elsewhere')
REPAIR = upload('repair', 512, 512)['attachment_id']
wp('option', 'update', 'site_icon', str(REPAIR))
url = php('echo get_site_icon_url(32);')
record("without the sizes, WordPress's 32px URL is a thumbnail", '-150x150' in url and '-32x32' not in url, url)
r = call('update_site_settings', {'site_icon': REPAIR, 'dry_run': True})
record('a dry run lists what it would create', sorted(r.get('would_create_sizes', [])) == sorted(EXPECTED) and not r.get('changes'), r)
r = call('update_site_settings', {'site_icon': REPAIR})
record('sending the same ID creates them', sorted(r.get('sizes_created', [])) == sorted(EXPECTED), r)
record('and purges, because cached pages link to the larger images', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
record('no option change and no receipt', not r.get('changes') and 'change_receipt' not in r and REPAIR == icon(), r)
record('the URL is now sized', r.get('icon_url_32', '').endswith('-32x32.png'), r.get('icon_url_32'))
r = call('update_site_settings', {'site_icon': REPAIR})
record('and again there is nothing to do', 'sizes_created' not in r and (r.get('cache') or {}).get('scope') is None, r)

# ── create_media with defer_subsizes ────────────────────────────────────────
print('# create_media with defer_subsizes')
uploaded = upload('deferred', 512, 512, defer_subsizes=True)
record('the upload was deferred', uploaded.get('subsizes_deferred') is True, uploaded)
run('wp', 'cron', 'event', 'run', 'iato_mcp_generate_subsizes')
r = call('update_site_settings', {'site_icon': uploaded['attachment_id']})
record('once its sizes exist, it is accepted', uploaded['attachment_id'] == icon() and r.get('icon_url_32', '').endswith('-32x32.png'), r)
run('wp', 'cron', 'event', 'run', 'iato_mcp_generate_subsizes')
record('...and the icon sizes are still there after cron runs again', set(EXPECTED) <= set(sizes_of(uploaded['attachment_id'])), sizes_of(uploaded['attachment_id']))

# ── Who may ─────────────────────────────────────────────────────────────────
print('# Credentials')
wp('option', 'update', 'site_icon', str(SQUARE))
for who in ('editor', 'author'):
    r = call('update_site_settings', {'site_icon': OTHER}, who)
    record(f'{who} is refused', r.get('isError') and 'manage_options' in r.get('_text', '') and SQUARE == icon(), r)
    r = call('update_site_settings', {'site_icon': OTHER, 'dry_run': True}, who)
    record(f'{who} is refused a dry run too', r.get('isError') and 'manage_options' in r.get('_text', ''), r)

for who, kind in (('admin', 'application_password'), ('oauth', 'oauth')):
    uploaded = upload(f'by-{who}', 256, 256)['attachment_id']
    r = call('update_site_settings', {'site_icon': uploaded}, who)
    actor = r.get('change_receipt', {}).get('actor', {})
    record(f'{who}: sets it, with sizes, as that user', uploaded == icon() and r.get('sizes_created') and actor.get('kind') == kind and actor.get('user_id') == ADMIN_ID, r)
owner = wp('option', 'get', 'iato_mcp_key_owner')
for label, value in (('bound site key', str(ADMIN_ID)), ('unbound site key', '0')):
    wp('option', 'update', 'iato_mcp_key_owner', value)
    uploaded = upload(f'key-{value}', 256, 256)['attachment_id']
    r = call('update_site_settings', {'site_icon': uploaded})
    record(f'{label}: sets it, with sizes', uploaded == icon() and r.get('sizes_created') and r.get('icon_url_32', '').endswith('-32x32.png'), r)
    r2 = call('rollback', {'change_id': r['change_receipt']['change_id']})
    record(f'{label}: rolls it back', uploaded != icon(), r2)
wp('option', 'update', 'iato_mcp_key_owner', owner)

# ── custom_logo ─────────────────────────────────────────────────────────────
print('# custom_logo')
r = call('update_site_settings', {'custom_logo': WIDE, 'dry_run': True})
record('a dry run writes nothing', r['changes']['custom_logo']['after']['id'] == WIDE and 0 == logo(), r)
r = call('update_site_settings', {'custom_logo': WIDE})
record('a logo does not have to be square', WIDE == logo() and r['changes']['custom_logo']['before'] == {'id': 0, 'url': None}, r)
record('it makes no icon sizes', 'sizes_created' not in r and 'icon_url_32' not in r and not set(EXPECTED) & set(sizes_of(WIDE)), r)
record('the whole site is purged', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
record('the theme can print it', f'{T}-wide' in php_all('echo get_custom_logo();'))
s = call('get_site_settings', {})
record('get_site_settings shows it', s.get('custom_logo', {}).get('id') == WIDE and f'{T}-wide' in s['custom_logo'].get('url', ''), s.get('custom_logo'))
for label, value, code in (('an SVG', SVG, 'unsupported_image_type'), ('a missing ID', 99999999, 'attachment_not_found'), ('a page', A_PAGE, 'not_an_attachment'), ('the trash', TRASHED, 'attachment_in_trash')):
    record(f'{label} is refused', refused(call('update_site_settings', {'custom_logo': value}), code) and WIDE == logo())
r2 = call('rollback', {'change_id': r['change_receipt']['change_id']})
record('rollback restores no logo', 0 == logo(), r2)
record('...and clears what the block themes read', 'false' == php("echo var_export(get_option('site_logo'),true);"), php("echo var_export(get_option('site_logo'),true);"))
r = call('update_site_settings', {'custom_logo': WIDE})
r3 = call('update_site_settings', {'custom_logo': SQUARE})
r2 = call('rollback', {'change_id': r3['change_receipt']['change_id']})
record('rollback restores the previous logo', WIDE == logo(), r2)
php(f"set_theme_mod('custom_logo',{SQUARE});echo 1;")
r2 = call('rollback', {'change_id': r['change_receipt']['change_id']})
record('a newer logo blocks the rollback', refused(r2, 'rollback_conflict') and SQUARE == logo(), r2)
r2 = call('rollback', {'change_id': r['change_receipt']['change_id'], 'force': True})
record('...force restores it', 0 == logo(), r2)
r = call('update_site_settings', {'custom_logo': WIDE})
r2 = call('update_site_settings', {'custom_logo': 0})
record('0 clears it and the attachment stays', 0 == logo() and exists(WIDE), r2)
r2 = call('rollback', {'change_id': r2['change_receipt']['change_id']})
record('rollback of the clear puts it back', WIDE == logo(), r2)

inactive = [line.strip() for line in run('wp', 'theme', 'list', '--status=inactive', '--field=name') if line.strip()]
if inactive:
    theme = wp('option', 'get', 'stylesheet')
    with open(MARKER, 'w') as f:
        f.write(theme)
    wp('theme', 'activate', inactive[0])
    try:
        for label, extra in (('', {}), (', even forced', {'force': True})):
            r2 = call('rollback', {'change_id': r['change_receipt']['change_id'], **extra})
            record(f'after a theme switch a logo rollback is unavailable{label}, naming both', refused(r2, 'rollback_unavailable') and theme in r2.get('message', '') and inactive[0] in r2.get('message', ''), r2)
    finally:
        wp('theme', 'activate', theme)
        os.remove(MARKER)
else:
    print('  note: no inactive theme, theme switch not checked')
php("set_theme_mod('custom_logo',0);echo 1;")

# As a user: Elementor's own sync of the title refuses a request with no user.
both = call('update_site_settings', {'site_icon': OTHER, 'custom_logo': WIDE, 'title': wp('option', 'get', 'blogname') + ' x'}, 'admin')
record('all at once: a receipt each', 3 == len(both.get('change_receipts', [])) and OTHER == icon() and WIDE == logo(), both)
for receipt in both.get('change_receipts', []):
    call('rollback', {'change_id': receipt['change_id']}, 'admin')
record('each rolls back', 0 == logo() and not wp('option', 'get', 'blogname').endswith(' x'), logo())

# Elementor's own hook on the title throws for a request with no user, after the title is
# stored. That is older than these settings; what matters here is what it leaves.
if KIT:
    wp('option', 'update', 'iato_mcp_key_owner', '0')
    name = wp('option', 'get', 'blogname')
    wp('option', 'update', 'site_icon', str(SQUARE))
    r = call('update_site_settings', {'title': name + ' y', 'site_icon': OTHER, 'custom_logo': WIDE})
    if r.get('isError'):
        record('if the title fails on an unbound key, the images are not touched', SQUARE == icon() and 0 == logo(), r)
    else:
        print('  note: the title did not fail on an unbound key here')
        php("set_theme_mod('custom_logo',0);echo 1;")
    wp('option', 'update', 'blogname', name)
    wp('option', 'update', 'iato_mcp_key_owner', owner)

# ── Elementor's Site Settings ───────────────────────────────────────────────
print("# Elementor's Site Settings")
if not KIT:
    print('  note: no active kit, Elementor checks not run')
else:
    def kit():
        return json.loads(php(f"echo wp_json_encode(get_post_meta({KIT},'_elementor_page_settings',true));") or '{}') or {}

    def save_in_kit(values):
        encoded = base64.b64encode(json.dumps(values).encode()).decode()
        php(f"$s=get_post_meta({KIT},'_elementor_page_settings',true);$s=is_array($s)?$s:[];foreach(json_decode(base64_decode('{encoded}'),true) as $k=>$v){{$s[$k]=$v;}}update_post_meta({KIT},'_elementor_page_settings',wp_slash($s));echo 1;")

    def publish():
        """What publishing Site Settings sends: the saved settings and the status."""
        php(f"$s=get_post_meta({KIT},'_elementor_page_settings',true);$s=is_array($s)?$s:[];$d=" + chr(92) + "Elementor" + chr(92) + "Plugin::$instance->documents->get(" + str(KIT) + ",false);$d->save(['settings'=>array_merge($s,['post_status'=>'publish'])]);echo 1;")

    wp('option', 'update', 'site_icon', '0')
    r = call('update_site_settings', {'site_icon': SQUARE})
    record('with nothing saved, the kit is left alone', 'elementor_settings_synced' not in r and 'site_favicon' not in kit(), r)
    publish()
    record('...and publishing keeps the icon', SQUARE == icon(), icon())

    note = 'C:' + chr(92) + 'temp & "quotes"'
    save_in_kit({'site_favicon': {'url': 'https://example.test/old.png', 'id': OTHER, 'alt': 'kept', 'source': 'library'}, 'iato_live_note': note})
    r = call('update_site_settings', {'site_icon': WIDE, 'dry_run': True})
    record('a bad image does not touch the kit', refused(r, 'image_not_square') and kit()['site_favicon']['id'] == OTHER, r)
    wp('option', 'update', 'site_icon', str(SQUARE))
    uploaded = upload('kit', 512, 512)['attachment_id']
    r = call('update_site_settings', {'site_icon': uploaded, 'dry_run': True})
    record('a dry run reports the sync and does not do it', r.get('would_sync_elementor_settings') == ['site_favicon'] and kit()['site_favicon']['id'] == OTHER, r)
    r = call('update_site_settings', {'site_icon': uploaded})
    saved = kit()
    record('the saved favicon follows', r.get('elementor_settings_synced') == ['site_favicon'] and int(saved['site_favicon']['id']) == uploaded and f'{T}-kit' in saved['site_favicon']['url'], (r, saved.get('site_favicon')))
    record('its other fields and other settings are kept, backslash included', saved['site_favicon'].get('alt') == 'kept' and saved.get('iato_live_note') == note, saved.get('iato_live_note'))
    publish()
    record('publishing Site Settings no longer puts the old one back', uploaded == icon(), icon())
    r2 = call('rollback', {'change_id': r['change_receipt']['change_id']})
    record('rollback restores both', SQUARE == icon() and int(kit()['site_favicon']['id']) == SQUARE, r2)
    publish()
    record('...and publishing keeps that too', SQUARE == icon(), icon())

    r = call('update_site_settings', {'site_icon': 0})
    record('clearing empties the saved favicon', '' == kit()['site_favicon']['id'] and '' == kit()['site_favicon']['url'], kit().get('site_favicon'))
    publish()
    record('...and publishing leaves no icon', 0 == icon() and call('get_site_settings', {}).get('site_icon') is None, wp('option', 'get', 'site_icon'))
    record('Elementor stored that as empty, which is still no change', 'change_receipt' not in call('update_site_settings', {'site_icon': 0}))
    r2 = call('rollback', {'change_id': r['change_receipt']['change_id']})
    record('rollback of the clear works from there', SQUARE == icon(), r2)

    save_in_kit({'site_logo': {'url': 'https://example.test/old-logo.png', 'id': OTHER, 'alt': '', 'source': 'library'}})
    r = call('update_site_settings', {'custom_logo': WIDE})
    record('a saved logo follows too', r.get('elementor_settings_synced') == ['site_logo'] and int(kit()['site_logo']['id']) == WIDE, (r, kit().get('site_logo')))
    publish()
    record('...and publishing keeps it', WIDE == logo(), logo())
    r2 = call('rollback', {'change_id': r['change_receipt']['change_id']})
    record('rollback restores the logo in both', 0 == logo() and '' == kit()['site_logo']['id'], (r2, kit().get('site_logo')))
    php(f"$s=get_post_meta({KIT},'_elementor_page_settings',true);unset($s['site_favicon'],$s['site_logo'],$s['iato_live_note']);update_post_meta({KIT},'_elementor_page_settings',wp_slash($s));echo 1;")

# ── Rolling back the upload of an image in use ──────────────────────────────
print('# Rolling back the upload of an image in use')
uploaded = upload('in-use', 512, 512)
image = uploaded['attachment_id']
call('update_site_settings', {'site_icon': image})
r = call('rollback', {'change_id': uploaded['change_receipt']['change_id']})
record('refused while it is the icon, and says why', refused(r, 'rollback_conflict') and "the site's icon" in r.get('message', '') and 'force' in r.get('message', '') and exists(image), r)
r = call('rollback', {'change_id': uploaded['change_receipt']['change_id'], 'dry_run': True})
record('a dry run shows the same conflict', refused(r, 'rollback_conflict') or r.get('conflict_check', {}).get('status') == 'conflict', r)
r = call('rollback', {'change_id': uploaded['change_receipt']['change_id'], 'force': True})
record('force deletes it and clears the icon', not exists(image) and 0 == icon(), r)
record('the whole site is purged', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
record('no icon tags left', 'rel="icon"' not in home())

uploaded = upload('logo-in-use', 512, 512)
image = uploaded['attachment_id']
call('update_site_settings', {'custom_logo': image})
r = call('rollback', {'change_id': uploaded['change_receipt']['change_id']})
record('refused while it is the logo', refused(r, 'rollback_conflict') and "the site's logo" in r.get('message', '') and exists(image), r)
r = call('rollback', {'change_id': uploaded['change_receipt']['change_id'], 'force': True})
record('force deletes it and clears the logo', not exists(image) and 0 == logo(), r)

uploaded = upload('not-in-use', 512, 512)
r = call('rollback', {'change_id': uploaded['change_receipt']['change_id']})
record('an image not in use rolls back as before', not exists(uploaded['attachment_id']), r)

failed = [name for name, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('failed:', '; '.join(failed)[:800])
sys.exit(1 if failed else 0)

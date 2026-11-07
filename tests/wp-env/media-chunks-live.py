"""Driven by media-chunks-live.sh."""
import base64
import hashlib
import json
import os
import random
import struct
import subprocess
import urllib.error
import urllib.request
import zlib

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
ADMIN_ID = int(os.environ['ADMIN_ID'])
T = 'media-chunks-live'
PASSWORDS = {'admin': os.environ['PW_ADMIN'], 'editor': os.environ['PW_EDITOR'], 'author': os.environ['PW_AUTHOR'], 'contributor': os.environ['PW_CONTRIBUTOR']}
CHUNK = 3000
results = []
random.seed(20261107)


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


def rpc(method, params, who='key'):
    if 'key' == who:
        auth = 'Bearer ' + KEY
    elif 'oauth' == who:
        auth = 'Bearer ' + os.environ['OAUTH_TOKEN']
    else:
        auth = 'Basic ' + base64.b64encode(f'{who}:{PASSWORDS[who]}'.encode()).decode()
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth})
    return json.loads(urllib.request.urlopen(req, timeout=180).read())['result']


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


def forbidden(result, cap=''):
    return bool(result.get('isError')) and 'capability' in result.get('_text', '') and cap in result.get('_text', '')


def status_of(url):
    try:
        with urllib.request.urlopen(url, timeout=60) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code


def sha(data):
    return hashlib.sha256(data).hexdigest()


def png_chunk(kind, data):
    return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)


def png(width, height, noise=True, noise_rows=None):
    """A real PNG. Noise barely compresses, so its size can be chosen."""
    rows = (height if noise else 0) if noise_rows is None else noise_rows
    raw = b''.join(b'\0' + (bytes(random.getrandbits(8) for _ in range(width * 3)) if y < rows else bytes(width * 3)) for y in range(height))
    return b'\x89PNG\r\n\x1a\n' + png_chunk(b'IHDR', struct.pack('>IIBBBBB', width, height, 8, 2, 0, 0, 0)) + png_chunk(b'IDAT', zlib.compress(raw)) + png_chunk(b'IEND', b'')


def gd_image(kind, width, height):
    data = php(f"$i=imagecreatetruecolor({width},{height});for($n=0;$n<400;$n++){{imagesetpixel($i,mt_rand(0,{width}-1),mt_rand(0,{height}-1),imagecolorallocate($i,mt_rand(0,255),mt_rand(0,255),mt_rand(0,255)));}}ob_start();image{kind}($i);echo base64_encode(ob_get_clean());")
    return base64.b64decode(data)


def b64(data):
    return base64.b64encode(data).decode()


def pieces(data, size=CHUNK):
    return [data[i:i + size] for i in range(0, len(data), size)]


def start(data, name='image.png', mime='image/png', who='key', **extra):
    return call('media_upload_start', {'filename': f'{T}-{name}', 'mime_type': mime, 'total_bytes': len(data), 'sha256': sha(data), **extra}, who)


def send_all(upload_id, data, who='key', size=CHUNK):
    last = {}
    for index, piece in enumerate(pieces(data, size)):
        last = call('media_upload_chunk', {'upload_id': upload_id, 'index': index, 'data': b64(piece)}, who)
        if last.get('isError'):
            return last
    return last


def chunked(data, name='image.png', mime='image/png', who='key', finish=None, **extra):
    begun = start(data, name, mime, who, **extra)
    send_all(begun['upload_id'], data, who)
    return call('media_upload_finish', {'upload_id': begun['upload_id'], **(finish or {})}, who), begun['upload_id']


def stored_sha(attachment):
    return php(f"echo hash_file('sha256',get_attached_file({attachment}));")


def exists(attachment):
    return '1' == php(f"echo get_post({attachment})?1:0;")


def sizes_of(attachment):
    return json.loads(php(f"$m=wp_get_attachment_metadata({attachment});echo wp_json_encode(array_keys($m['sizes']??[]));") or '[]')


def tmp_files():
    return json.loads(php("$d=wp_upload_dir(null,false)['basedir'].'/iato-mcp-tmp';echo wp_json_encode(is_dir($d)?array_values(array_diff(scandir($d),['.','..'])):[]);") or '[]')


def attachments():
    return int(php("global $wpdb;echo (int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'\");") or 0)


wp('option', 'delete', 'iato_mcp_media_max_upload_size')
wp('option', 'update', 'iato_mcp_media_upload_rate_limit', '1000')
OWNER = wp('option', 'get', 'iato_mcp_key_owner')
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))
print(f'# on {BASE}, key owner was {OWNER}')

SMALL = png(32, 32)
LARGE = png(400, 300, noise_rows=33)  # about 40 KB, and big enough to get image sizes
print(f'  note: the large PNG is {len(LARGE)} bytes')

# ── create_media ────────────────────────────────────────────────────────────
print('# create_media')


def create(data, name='direct.png', mime='image/png', who='key', **extra):
    return call('create_media', {'filename': f'{T}-{name}', 'mime_type': mime, 'title': f'{T} {name}', 'source': {'type': 'base64', 'data': b64(data)}, **extra}, who)


r = create(SMALL, sha256=sha(SMALL))
record('a right digest: created, and it says what arrived', r.get('attachment_id') and r.get('sha256') == sha(SMALL) and r.get('decoded_bytes') == len(SMALL), r)
record('the stored file matches', stored_sha(r['attachment_id']) == sha(SMALL), r)
record('a small image with no sizes gets no warning', 'warnings' not in r and 'missing_sizes' not in r, r)
r = create(SMALL)
record('no digest: it still says what arrived, so a caller can compare', r.get('sha256') == sha(SMALL) and r.get('decoded_bytes') == len(SMALL), r)
r = create(SMALL, dry_run=True)
record('so does a dry run', r.get('dry_run') and r.get('sha256') == sha(SMALL) and r.get('decoded_bytes') == len(SMALL), r)

before = attachments()
r = create(SMALL, sha256=sha(b'something else'))
record('a wrong digest: checksum_mismatch, with both digests, and nothing created', refused(r, 'checksum_mismatch') and r.get('sha256') == sha(SMALL) and r.get('expected_sha256') == sha(b'something else') and r.get('decoded_bytes') == len(SMALL) and attachments() == before, r)
r = create(SMALL, sha256='abc')
record('a digest that is not one: invalid_sha256', refused(r, 'invalid_sha256') and attachments() == before, r)
r = create(SMALL, sha256=sha(SMALL).upper())
record('upper case is fine', r.get('attachment_id'), r)
before = attachments()

damaged = bytearray(SMALL)
damaged[SMALL.index(b'IDAT') + 20] ^= 0x01
r = create(bytes(damaged))
record('a PNG with one changed byte, no digest: invalid_image, nothing created', refused(r, 'invalid_image') and 'IDAT' in r.get('reason', '') and r.get('sha256') == sha(bytes(damaged)) and attachments() == before, r)
r = create(SMALL[:-20])
record('a cut-short PNG: invalid_image', refused(r, 'invalid_image') and attachments() == before, r)
JPEG = gd_image('jpeg', 300, 300)
r = create(JPEG, 'photo.jpg', 'image/jpeg', sha256=sha(JPEG))
record('a real JPEG passes', r.get('attachment_id') and r.get('intermediate_sizes'), r)
before = attachments()
r = create(JPEG[:len(JPEG) // 2], 'cut.jpg', 'image/jpeg')
record('a cut-short JPEG: invalid_image', refused(r, 'invalid_image') and attachments() == before, r)
GIF = gd_image('gif', 300, 300)
r = create(GIF, 'anim.gif', 'image/gif', sha256=sha(GIF))
record('a real GIF passes', r.get('attachment_id'), r)
before = attachments()
r = create(GIF[:-1], 'cut.gif', 'image/gif')
record('a cut-short GIF: invalid_image', refused(r, 'invalid_image') and attachments() == before, r)
if 'yes' == php("echo function_exists('imagewebp')?'yes':'no';"):
    WEBP = gd_image('webp', 300, 300)
    r = create(WEBP, 'pic.webp', 'image/webp', sha256=sha(WEBP))
    record('a real WebP passes', r.get('attachment_id'), r)
    before = attachments()
    r = create(WEBP[:-40], 'cut.webp', 'image/webp')
    record('a cut-short WebP: invalid_image', refused(r, 'invalid_image') and attachments() == before, r)

r = create(SMALL, 'liar.png', 'image/jpeg')
record('mime_type says JPEG, the bytes are PNG: invalid_image', refused(r, 'invalid_image') and r.get('declared_mime') == 'image/jpeg' and r.get('actual_mime') == 'image/png' and attachments() == before, r)
r = create(b'plain text, not an image at all', 'text.png')
record('not an image at all is refused', r.get('isError') and attachments() == before, r)
r = create(JPEG, 'jpg.jpg', 'image/jpg')
record('image/jpg is taken as image/jpeg', r.get('attachment_id'), r)
PIC = png(400, 300, noise=False)
r = create(PIC, 'sized.png')
record('normal sizes: no warning', r.get('intermediate_sizes') and 'warnings' not in r, r)

# Sizes WordPress cannot make: the file is fine, the image editor refuses to work.
P = php("$f=WP_CONTENT_DIR.'/mu-plugins/iato-chunks-test.php';@mkdir(dirname($f));file_put_contents($f,'<?php add_filter(\"wp_image_editors\",fn()=>[]);');echo $f;")
r = create(png(400, 300, noise=False), 'nosizes.png')
record('sizes that cannot be made: a warning naming them, not a silent empty list', r.get('attachment_id') and r.get('missing_sizes') and 'could not create' in (r.get('warnings') or [''])[0], r)
php(f"unlink('{P}');echo 1;")
r = create(png(400, 300, noise=False), 'sizes-back.png')
record('...and the image is still created, and next time it is fine', r.get('intermediate_sizes') and 'warnings' not in r, r)

# ── From the brief ──────────────────────────────────────────────────────────
print('# A 40 KB PNG in chunks')
begun = start(LARGE, 'large.png', alt_text='Alt text', title=f'{T} Large', caption='The caption')
record('start gives an upload_id', len(begun.get('upload_id', '')) == 32 and begun.get('total_bytes') == len(LARGE), begun)
UPLOAD = begun['upload_id']
parts = pieces(LARGE)
r = call('media_upload_chunk', {'upload_id': UPLOAD, 'index': 0, 'data': b64(parts[0]), 'chunk_sha256': sha(parts[0])})
record('a chunk says how far it has got', r.get('bytes_received') == CHUNK and r.get('next_index') == 1 and r.get('complete') is False, r)
record('chunk tools carry the cache object, with nothing to purge', (r.get('cache') or {}).get('purged') is None, r.get('cache'))
for index in range(1, len(parts)):
    r = call('media_upload_chunk', {'upload_id': UPLOAD, 'index': index, 'data': b64(parts[index]), 'chunk_sha256': sha(parts[index])})
record('the last says it is complete', r.get('complete') is True and r.get('bytes_received') == len(LARGE), r)
log_rows = int(php("global $wpdb;echo (int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}iato_mcp_call_log WHERE tool_name='media_upload_chunk' AND response_status='success'\");") or 0)
record('chunks that worked stay out of the call log', 0 == log_rows, log_rows)
r = call('media_upload_finish', {'upload_id': UPLOAD, 'dry_run': True})
record('a dry run finish checks it and creates nothing', r.get('dry_run') and r.get('sha256') == sha(LARGE) and 'attachment_id' not in r, r)
FINISHED = call('media_upload_finish', {'upload_id': UPLOAD})
A = FINISHED.get('attachment_id')
record('finish creates the attachment, in the shape create_media returns', A and FINISHED.get('url') and FINISHED.get('filename') and FINISHED.get('mime_type') == 'image/png' and FINISHED.get('width') == 400, FINISHED)
record('the stored file\'s sha256 matches', stored_sha(A) == sha(LARGE) and FINISHED.get('sha256') == sha(LARGE) and FINISHED.get('decoded_bytes') == len(LARGE), stored_sha(A))
record('it has intermediate sizes', 'thumbnail' in sizes_of(A) and 'thumbnail' in FINISHED.get('intermediate_sizes', {}) and 'warnings' not in FINISHED, (sizes_of(A), FINISHED.get('intermediate_sizes')))
record('the alt text, title and caption were kept', php(f"echo get_post_meta({A},'_wp_attachment_image_alt',true);") == 'Alt text' and php(f"echo get_post({A})->post_title;") == f'{T} Large' and php(f"echo get_post({A})->post_excerpt;") == 'The caption', A)
record('it has a receipt', FINISHED.get('change_receipt', {}).get('target_type') == 'attachment' and FINISHED['change_receipt'].get('field') == 'create', FINISHED.get('change_receipt'))
record('the image is served', 200 == status_of(FINISHED['url']), FINISHED['url'])
AGAIN = call('media_upload_finish', {'upload_id': UPLOAD})
record('finish again gives the same answer and no second image', AGAIN.get('attachment_id') == A and AGAIN.get('already_finished') is True and AGAIN.get('change_receipt', {}).get('change_id') == FINISHED['change_receipt']['change_id'], AGAIN)
r = call('media_upload_chunk', {'upload_id': UPLOAD, 'index': 0, 'data': b64(parts[0])})
record('a finished upload takes no more chunks', refused(r, 'upload_finished'), r)
r = call('rollback', {'change_id': FINISHED['change_receipt']['change_id']})
record('rollback deletes the attachment and its file', not exists(A) and r.get('success'), r)

altered = bytearray(LARGE)
altered[len(altered) // 2] ^= 0xFF
begun = call('media_upload_start', {'filename': f'{T}-altered.png', 'mime_type': 'image/png', 'total_bytes': len(LARGE), 'sha256': sha(LARGE)})
send_all(begun['upload_id'], bytes(altered))
before = attachments()
r = call('media_upload_finish', {'upload_id': begun['upload_id']})
record('one byte altered: checksum_mismatch, nothing created', refused(r, 'checksum_mismatch') and attachments() == before and r.get('sha256') == sha(bytes(altered)) and r.get('expected_sha256') == sha(LARGE), r)
record('...the upload is gone, and it says so', r.get('upload_removed') is True and refused(call('media_upload_finish', {'upload_id': begun['upload_id']}), 'upload_not_found'), r)

print('# Other image types, and a damaged one with a right digest')
for label, data, name, mime in (('JPEG', JPEG, 'c.jpg', 'image/jpeg'), ('GIF', GIF, 'c.gif', 'image/gif')):
    r, _ = chunked(data, name, mime)
    record(f'a {label} in chunks', r.get('attachment_id') and stored_sha(r['attachment_id']) == sha(data), r)
r, _ = chunked(bytes(altered), 'damaged.png')
record('a damaged PNG sent whole, with its own digest: invalid_image', refused(r, 'invalid_image') and 'IDAT' in r.get('reason', '') and attachments() == before + 2 and r.get('upload_removed') is True, r)
r, _ = chunked(SMALL, 'liar.jpg', 'image/jpeg')
record('a PNG that says it is a JPEG: refused', refused(r, 'invalid_image') or refused(r, 'mime_mismatch'), r)
r, _ = chunked(png(30, 30, noise=False), 'one.png')
record('a file that fits one chunk works', r.get('attachment_id'), r)

print('# Chunk rules')
data = png(70, 70)
begun = start(data, 'rules.png')
U = begun['upload_id']
parts = pieces(data)
send = lambda index, chunk, **extra: call('media_upload_chunk', {'upload_id': U, 'index': index, 'data': chunk, **extra})
r = send(1, b64(parts[1]))
record('out of order: refused, and it says which is next', refused(r, 'chunk_out_of_order') and r.get('next_index') == 0, r)
r = send(0, b64(parts[0]), dry_run=True)
record('a dry run chunk stores nothing', r.get('dry_run') and r.get('bytes_received') == 0 and r.get('would_receive') == CHUNK, r)
r = send(0, b64(parts[0]))
record('the first chunk goes in', r.get('bytes_received') == CHUNK, r)
r = send(0, b64(parts[0]))
record('sent again with the same contents: fine, nothing added', r.get('already_received') is True and r.get('bytes_received') == CHUNK, r)
r = send(0, b64(parts[1]))
record('sent again with other contents: chunk_conflict', refused(r, 'chunk_conflict'), r)
r = send(1, b64(parts[1][:1000]))
record('a different size mid-way: chunk_size_mismatch', refused(r, 'chunk_size_mismatch') and r.get('chunk_bytes') == CHUNK, r)
r = send(1, b64(os.urandom(4097)))
record('over the limit: chunk_too_large', refused(r, 'chunk_too_large') and r.get('max_chunk_bytes') == 4096, r)
r = send(1, '***not base64***')
record('not base64: invalid_base64', refused(r, 'invalid_base64'), r)
r = send(1, b64(parts[1]), chunk_sha256=sha(b'other'))
record('a chunk that arrived altered: checksum_mismatch, naming it, and it can be sent again', refused(r, 'checksum_mismatch') and r.get('index') == 1, r)
r = send(1, b64(parts[1]))
record('...which works', r.get('bytes_received') == 2 * CHUNK, r)
r = send('one', b64(parts[1]))
record('an index that is not a number: invalid_chunk_index', refused(r, 'invalid_chunk_index'), r)
r = call('media_upload_finish', {'upload_id': U})
record('finish too early: upload_incomplete, saying how far it got', refused(r, 'upload_incomplete') and r.get('next_index') == 2 and r.get('bytes_received') == 2 * CHUNK, r)
for index in range(2, len(parts)):
    send(index, b64(parts[index]))
r = call('media_upload_finish', {'upload_id': U})
record('...and it can carry on and finish', r.get('attachment_id') and stored_sha(r['attachment_id']) == sha(data), r)
over = start(SMALL, 'over.png')
r = call('media_upload_chunk', {'upload_id': over['upload_id'], 'index': 0, 'data': b64(SMALL + b'x')})
record('past the declared size: upload_too_long', refused(r, 'upload_too_long'), r)

print('# Refused at the start')
for label, args, code in (
    ('a script filename', {'filename': f'{T}-x.php.png'}, 'unsafe_filename'),
    ('an SVG', {'filename': f'{T}-x.svg', 'mime_type': 'image/svg+xml'}, 'svg_not_supported'),
    ('not an image type', {'filename': f'{T}-x.pdf', 'mime_type': 'application/pdf'}, 'mime_not_allowed'),
    ('the wrong extension', {'filename': f'{T}-x.jpg'}, 'mime_mismatch'),
    ('no size', {'total_bytes': 0}, 'invalid_total_bytes'),
    ('over 15 MB', {'total_bytes': 15728641}, 'file_too_large'),
    ('a bad digest', {'sha256': 'abc'}, 'invalid_sha256'),
    ('a post that does not exist', {'attach_to_post': 99999999}, 'not_found'),
    ('a title of 10 KB and a byte', {'title': 'x' * 10001}, 'invalid_field'),
    ('a list as alt text', {'alt_text': ['x']}, 'invalid_field'),
    ('a list as a filename', {'filename': ['x.png']}, 'missing_filename'),
):
    r = call('media_upload_start', {'filename': f'{T}-x.png', 'mime_type': 'image/png', 'total_bytes': 100, 'sha256': sha(b'x'), **args})
    record(f'{label}: {code}', refused(r, code) and r.get('message'), r)
r = call('media_upload_start', {'filename': f'{T}-dry.png', 'mime_type': 'image/png', 'total_bytes': 100, 'sha256': sha(b'x'), 'dry_run': True})
record('a dry run start says the limit and starts nothing', r.get('dry_run') and r.get('max_bytes') and 'upload_id' not in r, r)
limit = int(r.get('max_bytes', 0))
site = int(php("echo min(15728640,wp_max_upload_size(),10485760);") or 0)
record('the limit is the lowest of 15 MB, the site and the plugin setting', limit == site, (limit, site))
wp('option', 'update', 'iato_mcp_media_max_upload_size', '20000')
r = call('media_upload_start', {'filename': f'{T}-x.png', 'mime_type': 'image/png', 'total_bytes': 30000, 'sha256': sha(b'x')})
record('the plugin\'s own size setting binds', refused(r, 'file_too_large') and r.get('max_bytes') == 20000, r)
wp('option', 'delete', 'iato_mcp_media_max_upload_size')

print('# Who may do what')
r = start(SMALL, who='contributor')
record('a Contributor, without upload_files, cannot start', forbidden(r, 'upload_files'), r)
begun = start(SMALL, 'owned.png', who='author')
U = begun.get('upload_id', '')
record('an Author can', U, begun)
r = call('media_upload_chunk', {'upload_id': U, 'index': 0, 'data': b64(SMALL)}, 'editor')
record("another user cannot add to it, and it looks like nothing's there", refused(r, 'upload_not_found'), r)
r = call('media_upload_finish', {'upload_id': U}, 'admin')
record('not even an administrator', refused(r, 'upload_not_found'), r)
r = call('media_upload_chunk', {'upload_id': U, 'index': 0, 'data': b64(SMALL)}, 'contributor')
record('a Contributor is stopped by the capability first', forbidden(r, 'upload_files'), r)
call('media_upload_chunk', {'upload_id': U, 'index': 0, 'data': b64(SMALL)}, 'author')
r = call('media_upload_finish', {'upload_id': U}, 'author')
record('the Author can finish it', r.get('attachment_id'), r)
AP = int(php(f"echo wp_insert_post(['post_title'=>'{T} admins','post_status'=>'publish','post_author'=>{ADMIN_ID}]);"))
r = start(SMALL, 'attach.png', who='author', attach_to_post=AP)
record("an Author cannot attach to someone else's post", forbidden(r, 'edit_post'), r)
r, _ = chunked(SMALL, 'attached.png', attach_to_post=AP)
record('an administrator can', php(f"echo get_post({r.get('attachment_id', 0)})->post_parent;") == str(AP), r)

print('# Every credential')
for label, who, owner in (('administrator password', 'admin', None), ('OAuth', 'oauth', None), ('bound site key', 'key', str(ADMIN_ID)), ('unbound site key', 'key', '0')):
    if owner is not None:
        wp('option', 'update', 'iato_mcp_key_owner', owner)
    data = png(60, 60)
    r, _ = chunked(data, f'cred-{who}-{owner}.png', who=who)
    record(f'{label}: uploads in chunks', r.get('attachment_id') and stored_sha(r['attachment_id']) == sha(data) and r.get('change_receipt'), r)
    r2 = call('rollback', {'change_id': r['change_receipt']['change_id']}, who)
    record(f'{label}: rolls back', not exists(r['attachment_id']), r2)
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))

print('# The temp folder')
begun = start(SMALL, 'folder.png')
U = begun['upload_id']
call('media_upload_chunk', {'upload_id': U, 'index': 0, 'data': b64(SMALL[:40])})
files = tmp_files()
record('it holds index.php and .htaccess, and names are the ID alone', 'index.php' in files and '.htaccess' in files and f'{U}.part' in files and f'{U}.json' in files and not any(T in f for f in files), files)
record('.htaccess denies it', 'Require all denied' in php("echo file_get_contents(wp_upload_dir(null,false)['basedir'].'/iato-mcp-tmp/.htaccess');") or 'denied' in ''.join(run('wp', 'eval', "echo file_get_contents(wp_upload_dir(null,false)['basedir'].'/iato-mcp-tmp/.htaccess');", '--user=admin')), files)
base = php("echo wp_upload_dir(null,false)['baseurl'];")
code = status_of(f'{base}/iato-mcp-tmp/{U}.part')
record('the web server refuses the bytes', 403 == code, code)
code = status_of(f'{base}/iato-mcp-tmp/{U}.json')
record('and the session', 403 == code, code)
record('the folder itself shows nothing', status_of(f'{base}/iato-mcp-tmp/') in (200, 403), status_of(f'{base}/iato-mcp-tmp/'))

print('# A finish that stops part way')
MU = "WP_CONTENT_DIR.'/mu-plugins/iato-chunks-stop.php'"
php(f"@mkdir(dirname({MU}));file_put_contents({MU},'<?php add_filter(\"intermediate_image_sizes_advanced\",function(){{exit;}});');echo 1;")
data = png(400, 300, noise_rows=4)
begun = start(data, 'stops.png', alt_text='Alt after stopping')
send_all(begun['upload_id'], data)
count = attachments()
try:
    call('media_upload_finish', {'upload_id': begun['upload_id']})
except Exception as error:  # The request ended with no JSON, as a time limit would.
    print(f'  note: the first finish ended with {type(error).__name__}')
record('the request stopped after making the attachment', attachments() == count + 1, attachments())
php(f"unlink({MU});echo 1;")
r = call('media_upload_finish', {'upload_id': begun['upload_id'], 'dry_run': True})
record('a dry run says it already exists', r.get('already_created') is True, r)
r = call('media_upload_finish', {'upload_id': begun['upload_id']})
record('finish again uses that attachment: no second image, and the receipt it never had', attachments() == count + 1 and r.get('recovered') is True and r.get('change_receipt') and 'stopped part way' in (r.get('warnings') or [''])[0], r)
record('the bytes are right, and the alt text arrived', stored_sha(r['attachment_id']) == sha(data) and php(f"echo get_post_meta({r['attachment_id']},'_wp_attachment_image_alt',true);") == 'Alt after stopping', r)
record('its sizes are left to WP-Cron', r.get('subsizes_deferred') is True, r)
wp('cron', 'event', 'run', 'iato_mcp_generate_subsizes')
record('...which makes them', 'thumbnail' in sizes_of(r['attachment_id']), sizes_of(r['attachment_id']))
again = call('media_upload_finish', {'upload_id': begun['upload_id']})
record('and it is settled now', again.get('already_finished') is True and again.get('attachment_id') == r['attachment_id'] and attachments() == count + 1, again)
call('rollback', {'change_id': r['change_receipt']['change_id']})

print('# Limits and cleanup')
for i in range(6):
    r = start(SMALL, f'open-{i}.png', who='editor')
    if refused(r, 'too_many_uploads'):
        break
record('five open uploads per user, and it lists them', refused(r, 'too_many_uploads') and 5 == len(r.get('open_uploads', [])), r)
EDITORS = r.get('open_uploads', [])
OLD = EDITORS[0]
php(f"$d=wp_upload_dir(null,false)['basedir'].'/iato-mcp-tmp';touch(\"$d/{OLD}.part\",time()-3700);touch(\"$d/{OLD}.json\",time()-3700);echo 1;")
r = start(SMALL, 'after-idle.png', who='editor')
record('an upload idle for an hour is removed when another starts, which frees a place', r.get('upload_id') and f'{OLD}.part' not in tmp_files(), r)
SECOND = EDITORS[1]
php(f"$d=wp_upload_dir(null,false)['basedir'].'/iato-mcp-tmp';touch(\"$d/{SECOND}.part\",time()-3700);touch(\"$d/{SECOND}.json\",time()-3700);echo 1;")
wp('cron', 'event', 'run', 'iato_mcp_media_chunks_cleanup')
files = tmp_files()
record('the cron job removes one too', f'{SECOND}.part' not in files and f'{SECOND}.json' not in files, files)
record('...and leaves the others and the guards', f'{EDITORS[2]}.part' in files and 'index.php' in files and '.htaccess' in files, files)
r = call('media_upload_chunk', {'upload_id': OLD, 'index': 0, 'data': b64(SMALL)}, 'editor')
record('a removed upload says to start again', refused(r, 'upload_not_found') and 'media_upload_start' in r.get('message', ''), r)
scheduled = int(php("echo (int)wp_next_scheduled('iato_mcp_media_chunks_cleanup');") or 0)
record('the job is scheduled', scheduled > 0, scheduled)
wp('option', 'update', 'iato_mcp_media_upload_rate_limit', '1')
php("delete_transient('iato_mcp_upload_count_0');delete_transient('iato_mcp_upload_count_1');echo 1;")
first, _ = chunked(png(30, 30, noise=False), 'rate-one.png')
r = start(SMALL, 'rate-two.png')
record('the rate limit is counted when one finishes and checked when one starts', first.get('attachment_id') and refused(r, 'rate_limited'), (first, r))
wp('option', 'update', 'iato_mcp_media_upload_rate_limit', '1000')

wp('option', 'update', 'iato_mcp_key_owner', OWNER)

failed = [name for name, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('failed:', '; '.join(failed)[:800])
    raise SystemExit(1)

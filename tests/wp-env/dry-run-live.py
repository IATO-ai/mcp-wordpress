"""Driven by dry-run-live.sh."""
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
PW = os.environ['PW_CONTRIBUTOR']
results = []


def wp(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    lines = [l.strip('\r') for l in out.splitlines() if l and not l.startswith(('ℹ', '✔'))]
    return lines[-1] if lines else ''


PREFIX = wp('db', 'prefix')


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:400]}'))


def call(tool, args, who='key'):
    auth = 'Bearer ' + KEY if who == 'key' else 'Basic ' + base64.b64encode(f'contributor:{PW}'.encode()).decode()
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth})
    res = json.loads(urllib.request.urlopen(req, timeout=120).read())['result']
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


def receipts():
    return wp('db', 'query', f'SELECT COUNT(*) FROM {PREFIX}iato_change_receipts', '--skip-column-names')


def refused(result, code):
    return bool(result.get('isError')) and (result.get('error') == code or code in result.get('_text', ''))


def ok_preview(result, action):
    return result.get('dry_run') is True and result.get('action') == action and not result.get('isError') and 'change_receipt' not in result and 'cache' not in result


T = 'dry-run-live'

print('# create_post')
before = receipts()
posts_before = wp('post', 'list', '--post_type=post,page', '--post_status=any', f'--s={T}', '--format=count')
d = call('create_post', {'title': f'{T} new', 'content': '<p>Hello</p><style>.x{}</style>', 'status': 'draft', 'dry_run': True})
record('it previews', ok_preview(d, 'would_create') and d.get('title') == f'{T} new' and d.get('content') == '<p>Hello</p>', d)
record('it reports what sanitising would remove', any('style' in e.get('removed_tags', []) for e in d.get('sanitized_report', [])), d)
record('it lists what it could not check', isinstance(d.get('not_checked'), list) and d['not_checked'], d)
record('no post was created', wp('post', 'list', '--post_type=post,page', '--post_status=any', f'--s={T}', '--format=count') == posts_before)
record('no receipt', receipts() == before)
record('a bad post type is refused the same way', refused(call('create_post', {'title': 'x', 'post_type': 'nope', 'dry_run': True}), 'post_type must be'))
record('nothing to create is refused the same way', refused(call('create_post', {'title': '   ', 'dry_run': True}), 'empty_content') or refused(call('create_post', {'title': '   ', 'dry_run': True}), 'Content, title, and excerpt are empty'))
record('a contributor cannot publish, in a dry run either', call('create_post', {'title': f'{T} c', 'status': 'publish', 'dry_run': True}, 'contributor').get('isError') is True)
real = call('create_post', {'title': f'{T} new', 'content': '<p>Hello</p><style>.x{}</style>', 'status': 'draft'})
POST = real['id']
record('the real call stores what the preview said', wp('post', 'get', str(POST), '--field=post_title') == d['title'] and wp('post', 'get', str(POST), '--field=post_content') == d['content'])

print('# update_post')
before = receipts()
d = call('update_post', {'id': POST, 'title': f'{T} changed', 'excerpt': 'A summary', 'slug': f'{T}-changed', 'status': 'draft', 'dry_run': True})
wc = d.get('would_change', {})
record('it previews each changed field', ok_preview(d, 'would_update') and wc.get('title', {}).get('after') == f'{T} changed' and wc.get('excerpt', {}).get('after') == 'A summary' and wc.get('slug', {}).get('after') == f'{T}-changed', d)
record('it leaves out what would not change', 'status' not in wc, wc)
record('nothing was written', wp('post', 'get', str(POST), '--field=post_title') == f'{T} new' and receipts() == before)
same = call('update_post', {'id': POST, 'title': f'{T} new', 'dry_run': True})
record('no change is reported as such', same.get('action') == 'no_change' and same.get('would_change') == {}, same)
OTHER = call('create_post', {'title': f'{T} other', 'status': 'publish', 'content': 'x'})['id']
other_slug = wp('post', 'get', str(OTHER), '--field=post_name')
record('a taken slug is refused the same way', refused(call('update_post', {'id': POST, 'slug': other_slug, 'dry_run': True}), 'already used'), other_slug)
record('a live slug change needs confirmation here too', 'confirm_url_break' in call('update_post', {'id': OTHER, 'slug': f'{T}-moved', 'dry_run': True}).get('_text', ''))
record('a confirmed one previews', call('update_post', {'id': OTHER, 'slug': f'{T}-moved', 'confirm_url_break': True, 'dry_run': True}).get('action') == 'would_update')
record('a contributor cannot edit it', call('update_post', {'id': OTHER, 'title': 'x', 'dry_run': True}, 'contributor').get('isError') is True)
real = call('update_post', {'id': POST, 'title': f'{T} changed', 'excerpt': 'A summary', 'slug': f'{T}-changed'})
record('the real call stores what the preview said', wp('post', 'get', str(POST), '--field=post_title') == wc['title']['after'] and wp('post', 'get', str(POST), '--field=post_name') == wc['slug']['after'] and wp('post', 'get', str(POST), '--field=post_excerpt') == wc['excerpt']['after'])

print('# update_seo_data')
def seo_is_active():
    return 'wordpress-seo' in subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', 'plugin', 'list', '--status=active', '--field=name'], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout


if not seo_is_active():
    d = call('update_seo_data', {'id': POST, 'title': 'x', 'dry_run': True})
    record('with no SEO plugin it is refused the same way', refused(d, 'SEO plugin') or d.get('isError') is True, d)
    wp('plugin', 'install', 'wordpress-seo', '--activate')
    # The current Yoast needs a recent WordPress, and an older site gets no version
    # from the plugin API at all, so the fallback has to be the zip's own URL.
    if not seo_is_active():
        wp('plugin', 'install', 'https://downloads.wordpress.org/plugin/wordpress-seo.26.9.zip', '--activate')
    open('/tmp/iato-dry-run-installed-yoast', 'w').close()
before = receipts()
d = call('update_seo_data', {'id': POST, 'title': f'{T} seo <b>title</b>', 'description': 'Meta text', 'dry_run': True})
wc = d.get('would_change', {})
record('it previews, sanitised', ok_preview(d, 'would_update') and wc.get('title', {}).get('after') == f'{T} seo title' and wc.get('description', {}).get('after') == 'Meta text', d)
record('nothing was written', wp('post', 'meta', 'get', str(POST), '_yoast_wpseo_title') == '' and receipts() == before)
real = call('update_seo_data', {'id': POST, 'title': f'{T} seo <b>title</b>', 'description': 'Meta text'})
record('the real call stores what the preview said', wp('post', 'meta', 'get', str(POST), '_yoast_wpseo_title') == wc['title']['after'] and wp('post', 'meta', 'get', str(POST), '_yoast_wpseo_metadesc') == wc['description']['after'], real)
same = call('update_seo_data', {'id': POST, 'title': f'{T} seo title', 'dry_run': True})
record('no change is reported as such', same.get('action') == 'no_change', same)

print('# create_term')
before = receipts()
d = call('create_term', {'taxonomy': 'category', 'name': f'{T} cat', 'description': 'About', 'dry_run': True})
record('it previews', ok_preview(d, 'would_create') and d.get('name') == f'{T} cat' and d.get('slug') == f'{T}-cat', d)
record('nothing was written', wp('term', 'list', 'category', f'--search={T}', '--format=count') == '0' and receipts() == before)
record('a missing parent is refused the same way', refused(call('create_term', {'taxonomy': 'category', 'name': f'{T} kid', 'parent': 999999, 'dry_run': True}), 'missing_parent') or 'parent' in call('create_term', {'taxonomy': 'category', 'name': f'{T} kid', 'parent': 999999, 'dry_run': True}).get('_text', '').lower())
record('a contributor cannot create one', call('create_term', {'taxonomy': 'category', 'name': f'{T} c', 'dry_run': True}, 'contributor').get('isError') is True)
real = call('create_term', {'taxonomy': 'category', 'name': f'{T} cat', 'description': 'About'})
CAT = real['term_id']
record('the real call stores what the preview said', real.get('slug') == d['slug'] and real.get('name') == d['name'], real)
dup = call('create_term', {'taxonomy': 'category', 'name': f'{T} cat', 'dry_run': True})
record('a term that exists is refused the same way', refused(dup, 'term_exists') or 'already exists' in dup.get('_text', ''), dup)
real_dup = call('create_term', {'taxonomy': 'category', 'name': f'{T} cat'})
record('which is what the real call says', real_dup.get('isError') is True, real_dup)
TAG = call('create_term', {'taxonomy': 'post_tag', 'name': f'{T} tag'})['term_id']

print('# update_term')
before = receipts()
d = call('update_term', {'taxonomy': 'category', 'term_id': CAT, 'name': f'{T} renamed', 'dry_run': True})
record('it previews', ok_preview(d, 'would_update') and d.get('would_change', {}).get('name', {}).get('after') == f'{T} renamed', d)
record('nothing was written', wp('term', 'get', 'category', str(CAT), '--field=name') == f'{T} cat' and receipts() == before)
same = call('update_term', {'taxonomy': 'category', 'term_id': CAT, 'name': f'{T} cat', 'dry_run': True})
record('no change is reported as such', same.get('action') == 'no_change', same)
OTHER_CAT = call('create_term', {'taxonomy': 'category', 'name': f'{T} other'})['term_id']
other_term_slug = wp('term', 'get', 'category', str(OTHER_CAT), '--field=slug')
dupe = call('update_term', {'taxonomy': 'category', 'term_id': CAT, 'slug': other_term_slug, 'dry_run': True})
record('a slug in use is refused the same way', refused(dupe, 'duplicate_term_slug') or 'already in use' in dupe.get('_text', ''), dupe)
record('so is an empty name', call('update_term', {'taxonomy': 'category', 'term_id': CAT, 'name': '   ', 'dry_run': True}).get('isError') is True)
record('and a missing parent', call('update_term', {'taxonomy': 'category', 'term_id': CAT, 'parent': 999999, 'dry_run': True}).get('isError') is True)
real = call('update_term', {'taxonomy': 'category', 'term_id': CAT, 'name': f'{T} renamed'})
record('the real call stores what the preview said', wp('term', 'get', 'category', str(CAT), '--field=name') == d['would_change']['name']['after'], real)

print('# assign_term')
before = receipts()
d = call('assign_term', {'post_id': POST, 'term_id': TAG, 'taxonomy': 'post_tag', 'dry_run': True})
record('it previews', ok_preview(d, 'would_assign') and d.get('term_name') == f'{T} tag' and TAG in d.get('after_ids', []), d)
record('nothing was written', TAG not in [int(x) for x in wp('post', 'term', 'list', str(POST), 'post_tag', '--field=term_id', '--format=ids').split() if x.isdigit()] and receipts() == before)
record('a missing term is refused the same way', call('assign_term', {'post_id': POST, 'term_id': 999999, 'taxonomy': 'post_tag', 'dry_run': True}).get('isError') is True)
real = call('assign_term', {'post_id': POST, 'term_id': TAG, 'taxonomy': 'post_tag'})
same = call('assign_term', {'post_id': POST, 'term_id': TAG, 'taxonomy': 'post_tag', 'dry_run': True})
record('assigned already is reported as no change', same.get('action') == 'no_change', same)

print('# delete_term')
before = receipts()
d = call('delete_term', {'taxonomy': 'post_tag', 'term_id': TAG, 'dry_run': True})
record('it previews', ok_preview(d, 'would_delete') and d.get('name') == f'{T} tag' and isinstance(d.get('post_count'), int), d)
record('nothing was written', wp('term', 'get', 'post_tag', str(TAG), '--field=name') == f'{T} tag' and receipts() == before)
default_cat = int(wp('option', 'get', 'default_category'))
record('a key with an owner cannot delete the default category, dry run or not (core\'s capability check)', refused(call('delete_term', {'taxonomy': 'category', 'term_id': default_cat, 'dry_run': True}), 'delete_term') and refused(call('delete_term', {'taxonomy': 'category', 'term_id': default_cat}), 'delete_term'))
owner = wp('option', 'get', 'iato_mcp_key_owner')
wp('option', 'delete', 'iato_mcp_key_owner')
try:
    before = receipts()
    record('an unbound key: the dry run refuses the default category', refused(call('delete_term', {'taxonomy': 'category', 'term_id': default_cat, 'dry_run': True}), 'default category'))
    real = call('delete_term', {'taxonomy': 'category', 'term_id': default_cat})
    record('an unbound key: the real call refuses it, where it used to report it deleted', refused(real, 'default category') and receipts() == before, real)
finally:
    wp('option', 'update', 'iato_mcp_key_owner', owner)
record('it still exists', wp('term', 'get', 'category', str(default_cat), '--field=term_id') == str(default_cat))
real = call('delete_term', {'taxonomy': 'post_tag', 'term_id': TAG})
record('a real delete works and can be rolled back', real.get('deleted') is True and call('rollback', {'change_id': real['change_receipt']['change_id']}).get('success') is True, real)

print('# a tool with no dry run')
before = receipts()
for tool, args in (('get_site_info', {}), ('get_post', {'id': POST}), ('search_posts', {'query': 'x'})):
    r = call(tool, {**args, 'dry_run': True})
    record(f'{tool} is not run', refused(r, 'dry_run_unsupported'), r)
r = call('get_site_info', {'dry_run': False})
record('dry_run: false is ignored', not r.get('isError'), r)
r = call('get_site_info', {})
record('and without the flag it runs', not r.get('isError'), r)
record('no receipts', receipts() == before)

print('# rollback still previews')
u = call('update_post', {'id': POST, 'title': f'{T} once more'})
r = call('rollback', {'change_id': u['change_receipt']['change_id'], 'dry_run': True})
record('its dry run works as before', r.get('dry_run') is True, r)

failed = [n for n, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('# failed: ' + '; '.join(failed))
sys.exit(1 if failed else 0)

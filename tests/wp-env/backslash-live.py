#!/usr/bin/env python3
"""
Backslashes survive every MCP write path and every rollback (1.13.0).
Driven by tests/wp-env/backslash-live.sh (env: BASE, KEY, POST, PAGE, MENU;
Yoast SEO active). The site key (bound to an administrator) makes the writes.
Exit 1 on any failure.
"""
import base64, json, os, subprocess, sys, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
KEY = os.environ['KEY']
POST, PAGE, MENU = (int(os.environ[k]) for k in ('POST', 'PAGE', 'MENU'))
B = chr(92)
V = f'a{B}b C:{B}Users {B}d+ quote{B}" esc{B}{B}'            # a\b C:\Users \d+ quote\" esc\\
CSS = f'.x:before {{ content: "{B}201C"; }}'               # .x:before { content: "\201C"; }
PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
passes, fails = 0, []


def record(name, ok, detail=None):
    global passes
    if ok:
        passes += 1; print(f'  PASS  {name}')
    else:
        fails.append(name); print(f'  FAIL  {name}: {json.dumps(detail, default=str)[:500]}')


def wp(*args):
    r = subprocess.run(['npx', '@wordpress/env', 'run', 'cli', 'wp', *args], capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def call(tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST', headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + KEY})
    try:
        raw = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    r = json.loads(raw)
    res = r.get('result', {})
    text = res.get('content', [{}])[0].get('text', '')
    try:
        out = json.loads(text)
    except ValueError:
        out = {'text': text}
    if res.get('isError') or 'error' in r:
        return {'isError': True, 'text': text or json.dumps(r.get('error'))}
    return out


def receipt(r, field=None):
    rs = [r['change_receipt']] if 'change_receipt' in r else r.get('change_receipts', [])
    return next((x for x in rs if field is None or x.get('field') == field), {})


def rollback(r, field=None):
    cid = receipt(r, field).get('change_id')
    out = call('rollback', {'change_id': cid})
    return not out.get('isError'), out


def field(pid, name):
    return wp('post', 'get', str(pid), f'--field={name}')


def meta(pid, key, as_json=False):
    if as_json:
        v = wp('post', 'meta', 'get', str(pid), key, '--format=json')
        return json.loads(v) if v else None
    return wp('post', 'meta', 'get', str(pid), key)


def same(name, sent, stored):
    record(name, stored == sent, {'sent': sent, 'stored': stored})


print('# writes keep backslashes')
up = call('update_post', {'id': POST, 'title': V, 'content': f'<p>{V}</p>', 'excerpt': V})
same('update_post title', V, field(POST, 'post_title'))
same('update_post content', f'<p>{V}</p>', field(POST, 'post_content'))
same('update_post excerpt', V, field(POST, 'post_excerpt'))
cp = call('create_post', {'title': V, 'content': f'<p>{V}</p>'})
same('create_post title', V, field(cp.get('id'), 'post_title'))
same('create_post content', f'<p>{V}</p>', field(cp.get('id'), 'post_content'))
ms = call('update_post_meta', {'id': POST, 'key': 'backslash_string', 'value': V})
same('update_post_meta string', V, meta(POST, 'backslash_string'))
ma = call('update_post_meta', {'id': POST, 'key': 'backslash_array', 'value': {'v': V, 'deep': {'v': V}}})
same('update_post_meta array (nested)', {'v': V, 'deep': {'v': V}}, meta(POST, 'backslash_array', True))
seo = call('update_seo_data', {'id': POST, 'title': V, 'description': V})
same('update_seo_data title (Yoast)', V, meta(POST, '_yoast_wpseo_title'))
same('update_seo_data description (Yoast)', V, meta(POST, '_yoast_wpseo_metadesc'))
ps = call('set_page_settings', {'id': PAGE, 'settings': {'elementor_page_settings': {'custom_css': CSS}}})
same('set_page_settings: Elementor custom_css escape', CSS, (meta(PAGE, '_elementor_page_settings', True) or {}).get('custom_css'))
pm = call('update_post_meta', {'id': PAGE, 'key': '_elementor_page_settings', 'value': {'custom_css': CSS, 'note': V}})
same('update_post_meta on _elementor_page_settings', {'custom_css': CSS, 'note': V}, meta(PAGE, '_elementor_page_settings', True))
media = call('create_media', {'filename': 'backslash-live.png', 'mime_type': 'image/png', 'source': {'type': 'base64', 'data': PNG}, 'title': V, 'caption': V, 'alt_text': V, 'description': f'<p>{V}</p>'})
mid = media.get('attachment_id')
same('create_media title', V, field(mid, 'post_title'))
same('create_media caption', V, field(mid, 'post_excerpt'))
same('create_media description', f'<p>{V}</p>', field(mid, 'post_content'))
same('create_media alt text', V, meta(mid, '_wp_attachment_image_alt'))
alt = call('update_alt_text', {'id': mid, 'alt': V + ' 2'})
same('update_alt_text', V + ' 2', meta(mid, '_wp_attachment_image_alt'))
mi = call('create_menu_item', {'menu_id': MENU, 'title': V, 'url': 'https://example.com/backslash'})
same('create_menu_item title', V, field(mi.get('menu_item_id'), 'post_title'))
md = call('update_menu_item_details', {'menu_item_id': mi.get('menu_item_id'), 'title': V + ' 2'})
same('update_menu_item_details title', V + ' 2', field(mi.get('menu_item_id'), 'post_title'))
ct = call('create_term', {'taxonomy': 'category', 'name': 'backslash-live ' + V, 'description': V})
tid = ct.get('term_id')
same('create_term name', 'backslash-live ' + V, wp('term', 'get', 'category', str(tid), '--field=name'))
same('create_term description', V, wp('term', 'get', 'category', str(tid), '--field=description'))
ut = call('update_term', {'term_id': tid, 'taxonomy': 'category', 'description': V + ' 2'})
same('update_term description', V + ' 2', wp('term', 'get', 'category', str(tid), '--field=description'))

print('# rollbacks restore backslashes')
r = call('update_post', {'id': POST, 'title': 'backslash-live replaced', 'content': '<p>replaced</p>'})
ok, out = rollback(r, 'title'); record('rollback of update_post title', ok and field(POST, 'post_title') == V, out)
ok, out = rollback(r, 'content'); record('rollback of update_post content', ok and field(POST, 'post_content') == f'<p>{V}</p>', out)
r = call('update_post_meta', {'id': POST, 'key': 'backslash_array', 'value': {'v': 'replaced'}})
ok, out = rollback(r); record('rollback of an array meta value', ok and meta(POST, 'backslash_array', True) == {'v': V, 'deep': {'v': V}}, out)
r = call('update_post_meta', {'id': POST, 'key': 'backslash_string', 'value': 'replaced'})
ok, out = rollback(r); record('rollback of a string meta value', ok and meta(POST, 'backslash_string') == V, out)
r = call('update_seo_data', {'id': POST, 'title': 'replaced'})
ok, out = rollback(r, 'title'); record('rollback of the SEO title', ok and meta(POST, '_yoast_wpseo_title') == V, out)
r = call('set_page_settings', {'id': PAGE, 'settings': {'elementor_page_settings': {'custom_css': 'x'}}})
ok, out = rollback(r); record('rollback of Elementor page settings', ok and (meta(PAGE, '_elementor_page_settings', True) or {}).get('custom_css') == CSS, out)
r = call('update_alt_text', {'id': mid, 'alt': 'replaced'})
ok, out = rollback(r); record('rollback of alt text', ok and meta(mid, '_wp_attachment_image_alt') == V + ' 2', out)
r = call('update_menu_item_details', {'menu_item_id': mi.get('menu_item_id'), 'title': 'replaced'})
ok, out = rollback(r); record('rollback of a menu item title', ok and field(mi.get('menu_item_id'), 'post_title') == V + ' 2', out)
r = call('update_term', {'term_id': tid, 'taxonomy': 'category', 'description': 'replaced'})
ok, out = rollback(r); record('rollback of a term description', ok and wp('term', 'get', 'category', str(tid), '--field=description') == V + ' 2', out)
r = call('delete_term', {'term_id': tid, 'taxonomy': 'category'})
ok, out = rollback(r)
back = wp('term', 'list', 'category', '--search=backslash-live', '--fields=name,description', '--format=json')
record('rollback of delete_term recreates the name and description', ok and any(t.get('name') == 'backslash-live ' + V and t.get('description') == V + ' 2' for t in json.loads(back or '[]')), (out, back))

print(f'\n# {passes} passed, {len(fails)} failed')
sys.exit(1 if fails else 0)

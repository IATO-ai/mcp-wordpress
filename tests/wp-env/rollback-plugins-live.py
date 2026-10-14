#!/usr/bin/env python3
"""
Live rollback checks that need third-party plugins (driven by
rollback-plugins-live.sh; env: BASE, KEY, PW_ADMIN, PREFIX, SEO_POST):
SEO title, description and canonical receipts with Yoast SEO, and redirect
receipts on the fallback handler, Safe Redirect Manager and Redirection.
Exit 1 on any failure.
"""
import base64, json, os, subprocess, sys, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
POST = int(os.environ['SEO_POST'])
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


def auth(who):
    if who == 'key':
        return 'Bearer ' + os.environ['KEY']
    return 'Basic ' + base64.b64encode(f"admin:{os.environ['PW_ADMIN']}".encode()).decode()


def call(who, tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST', headers={'Content-Type': 'application/json', 'Authorization': auth(who)})
    try:
        raw = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
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


def ok(r):
    return isinstance(r, dict) and not r.get('isError') and 'error' not in r


def err(r):
    return (r.get('body') or {}).get('error') if r.get('isError') else None


def receipt(r, field=None):
    rs = [r['change_receipt']] if 'change_receipt' in r else r.get('change_receipts', [])
    for one in rs:
        if field is None or one.get('field') == field:
            return one
    return {}


def meta(key):
    return wp('post', 'meta', 'get', str(POST), key)


# ── SEO with Yoast ─────────────────────────────────────────────────────────
print('# SEO receipts (Yoast SEO)')
r = call('key', 'update_seo_data', {'id': POST, 'title': 'rollback-plugins <b>Tom</b> & Jerry', 'description': 'rollback-plugins description one'})
record('update_seo_data works with Yoast active', ok(r) and r.get('plugin') == 'yoast', r)
t, d = receipt(r, 'title'), receipt(r, 'meta_description')
record('title receipt records the stored, sanitised value', t.get('after_value') == meta('_yoast_wpseo_title') == 'rollback-plugins Tom & Jerry', (t, meta('_yoast_wpseo_title')))
r2 = call('key', 'rollback', {'change_id': t.get('change_id'), 'dry_run': True})
record('title: matches right after the write', ok(r2) and r2['conflict_check']['status'] == 'match', r2)
r2 = call('key', 'rollback', {'change_id': t.get('change_id')})
record('title: rollback deletes the key (there was no SEO title before)', ok(r2) and meta('_yoast_wpseo_title') == '', (r2, meta('_yoast_wpseo_title')))
wp('post', 'meta', 'update', str(POST), '_yoast_wpseo_metadesc', 'changed in wp-admin')
r2 = call('key', 'rollback', {'change_id': d.get('change_id')})
record('description changed outside MCP: conflict with the current value', err(r2) == 'rollback_conflict' and r2['body']['conflict_check'].get('current_value') == 'changed in wp-admin', r2)
r2 = call('key', 'rollback', {'change_id': d.get('change_id'), 'force': True})
record('...force deletes it', ok(r2) and meta('_yoast_wpseo_metadesc') == '', r2)

c1 = receipt(call('key', 'update_canonical', {'post_id': POST, 'canonical_url': 'https://example.com/a?x=1&y=2'}))
record('canonical receipt records the stored URL', c1.get('after_value') == meta('_yoast_wpseo_canonical') == 'https://example.com/a?x=1&y=2', (c1, meta('_yoast_wpseo_canonical')))
c2 = receipt(call('key', 'update_canonical', {'post_id': POST, 'canonical_url': 'https://example.com/b'}))
r2 = call('key', 'rollback', {'change_id': c1.get('change_id')})
record('canonical: the earlier change conflicts', err(r2) == 'rollback_conflict', r2)
r2 = call('key', 'rollback', {'change_id': c2.get('change_id')})
record('canonical: latest first restores the first URL', ok(r2) and meta('_yoast_wpseo_canonical') == 'https://example.com/a?x=1&y=2', r2)
r2 = call('key', 'rollback', {'change_id': c1.get('change_id')})
record('canonical: then the first deletes it', ok(r2) and meta('_yoast_wpseo_canonical') == '', r2)


def redirect_suite(handler, label, read_to):
    print(f'# redirects: {label}')
    frm = f'/rollback-plugins-{handler}'
    rc = call('admin', 'update_redirect', {'from_url': frm, 'to_url': 'https://example.com/one'})
    record(f'{label}: handler detected', ok(rc) and rc.get('handler') == handler, rc)
    r = call('admin', 'rollback', {'change_id': receipt(rc).get('change_id'), 'dry_run': True})
    record(f'{label}: a created redirect matches right after the write', ok(r) and r['conflict_check']['status'] == ('unverifiable' if handler == 'redirection' else 'match'), r)
    r = call('admin', 'rollback', {'change_id': receipt(rc).get('change_id')})
    record(f'{label}: rolling back a created redirect is refused (removal is not supported, the receipt stays open)', bool(r.get('isError')) and 'from_url' in r.get('text', ''), r)
    if handler == 'redirection':
        return
    u2 = receipt(call('admin', 'update_redirect', {'from_url': frm, 'to_url': 'https://example.com/two'}))
    u3 = receipt(call('admin', 'update_redirect', {'from_url': frm, 'to_url': 'https://example.com/three'}))
    r = call('admin', 'rollback', {'change_id': u2.get('change_id')})
    record(f'{label}: an older redirect change conflicts', err(r) == 'rollback_conflict' and r['body']['conflict_check'].get('current_value', {}).get('to_url') == 'https://example.com/three', r)
    r = call('admin', 'rollback', {'change_id': u3.get('change_id')})
    record(f'{label}: latest first restores the previous target', ok(r) and read_to(frm) == 'https://example.com/two', (r, read_to(frm)))
    r = call('admin', 'rollback', {'change_id': u2.get('change_id')})
    record(f'{label}: then the older one restores the first target', ok(r) and read_to(frm) == 'https://example.com/one', (r, read_to(frm)))


def fallback_to(frm):
    rules = json.loads(wp('option', 'get', 'iato_mcp_redirects', '--format=json') or '[]')
    return next((x.get('to_url') for x in rules if '/' + x.get('from_url', '').lstrip('/') == frm), None)


def srm_to(frm):
    ids = wp('post', 'list', '--post_type=redirect_rule', '--meta_key=_redirect_rule_from', f'--meta_value={frm}', '--format=ids')
    return wp('post', 'meta', 'get', ids.split()[0], '_redirect_rule_to') if ids else None


redirect_suite('fallback', 'built-in fallback', fallback_to)
wp('plugin', 'activate', 'safe-redirect-manager')
redirect_suite('safe_redirect_manager', 'Safe Redirect Manager', srm_to)
wp('plugin', 'deactivate', 'safe-redirect-manager')
wp('plugin', 'activate', 'redirection')
wp('redirection', 'database', 'install')  # the plugin creates its tables and default group in its setup step
redirect_suite('redirection', 'Redirection', None)
wp('plugin', 'deactivate', 'redirection')

print(f'\n# {passes} passed, {len(fails)} failed')
sys.exit(1 if fails else 0)

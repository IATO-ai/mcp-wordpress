#!/usr/bin/env python3
"""
Live OAuth checks for 1.13.0, driven by tests/wp-env/oauth-live.sh (env:
BASE, KEY, PREFIX). Users: admin/password (wp-env default), oauth2admin/
oauth2-pass (administrator, demoted and deleted during the run), oauthed/
oauthed-pass (editor). Uses WP-CLI through wp-env for role changes and for
backdating rows. Exit code 1 if any check fails.
"""
import base64, hashlib, json, os, re, secrets, subprocess, sys, threading, urllib.error, urllib.parse, urllib.request
from http.cookiejar import CookieJar

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
KEY = os.environ['KEY']
PREFIX = os.environ.get('PREFIX', 'wp_')
CB = 'https://claude.ai/api/mcp/auth_callback'
RES = BASE + '/wp-json/iato-mcp/v1/message'
passes, fails = 0, []
captured = []  # every OAuth endpoint response (status, headers, body) for the final site-key sweep


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


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
        out = r.status, {k.lower(): v for k, v in r.headers.items()}, r.read()
    except urllib.error.HTTPError as e:
        out = e.code, {k.lower(): v for k, v in e.headers.items()}, e.read()
    if '/oauth/' in url or '/.well-known/' in url:
        captured.append((url, out[0], out[1], out[2]))
    return out


def record(name, ok, detail):
    global passes
    if ok:
        passes += 1; print(f'  PASS  {name}')
    else:
        fails.append(name); print(f'  FAIL  {name}: {json.dumps(detail, default=str)[:600]}')


def wp(*args):
    """Run a WP-CLI command through wp-env; returns stdout without the wp-env chatter."""
    r = subprocess.run(['npx', '@wordpress/env', 'run', 'cli', 'wp', *args], capture_output=True, text=True, stdin=subprocess.DEVNULL)
    lines = [l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))]
    return '\n'.join(lines).strip()


def sha(raw):
    return hashlib.sha256(raw.encode()).hexdigest()


def db(sql):
    return wp('db', 'query', sql, '--skip-column-names')


def login(user, pw):
    jar = CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
    http('GET', BASE + '/wp-login.php', opener=op)
    st, hd, _ = http('POST', BASE + '/wp-login.php', urllib.parse.urlencode({'log': user, 'pwd': pw, 'wp-submit': 'Log In', 'redirect_to': BASE + '/wp-admin/', 'testcookie': '1'}), opener=op)
    ok = st == 302 and any(c.name.startswith('wordpress_logged_in') for c in jar)
    return op if ok else None


def call(bearer, tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    st, hd, raw = http('POST', MSG, body, {'Authorization': 'Bearer ' + bearer})
    try:
        r = json.loads(raw)
    except ValueError:
        return st, hd, {'_raw': raw[:300].decode(errors='replace')}
    return st, hd, r


def pkce():
    v = secrets.token_urlsafe(48)
    c = base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b'=').decode()
    return v, c


def register(uris, name='oauth-live', extra=None):
    body = {'client_name': name, 'redirect_uris': uris, 'grant_types': ['authorization_code', 'refresh_token'], 'response_types': ['code'], 'token_endpoint_auth_method': 'none'}
    body.update(extra or {})
    st, hd, raw = http('POST', BASE + '/oauth/register', body)
    try:
        return st, hd, json.loads(raw)
    except ValueError:
        return st, hd, {'_raw': raw[:300].decode(errors='replace')}


def authorize_url(client_id, redirect_uri, challenge, state='s1', **extra):
    q = {'response_type': 'code', 'client_id': client_id, 'redirect_uri': redirect_uri, 'state': state, 'code_challenge': challenge, 'code_challenge_method': 'S256', 'resource': RES}
    q.update(extra)
    q = {k: v for k, v in q.items() if v is not None}
    return f'{BASE}/oauth/authorize?' + urllib.parse.urlencode(q)


def consent(op, url):
    st, hd, raw = http('GET', url, opener=op)
    nonce = re.search(rb'name="_wpnonce" value="([^"]+)"', raw)
    return st, hd, raw, (nonce.group(1).decode() if nonce else '')


def decide(op, url, nonce, decision):
    st, hd, raw = http('POST', url, urllib.parse.urlencode({'_wpnonce': nonce, 'decision': decision}), opener=op)
    loc = hd.get('location', '')
    q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(loc).query)) if loc else {}
    return st, loc, q


def token(**fields):
    st, hd, raw = http('POST', BASE + '/oauth/token', urllib.parse.urlencode(fields))
    try:
        return st, json.loads(raw)
    except ValueError:
        return st, {'_raw': raw[:300].decode(errors='replace')}


def grant(op, client_id, redirect_uri=CB, state='s1'):
    """Full flow as the logged-in admin `op`; returns dict with code/access/refresh."""
    v, c = pkce()
    url = authorize_url(client_id, redirect_uri, c, state)
    st, hd, raw, nonce = consent(op, url)
    st2, loc, q = decide(op, url, nonce, 'approve')
    st3, body = token(grant_type='authorization_code', code=q.get('code', ''), redirect_uri=redirect_uri, client_id=client_id, code_verifier=v, resource=RES)
    return {'consent_status': st, 'approve_status': st2, 'code': q.get('code'), 'token_status': st3, 'body': body,
            'access': body.get('access_token'), 'refresh': body.get('refresh_token'), 'verifier': v}


# ── 0. setup ─────────────────────────────────────────────────────────────
db(f"DELETE FROM {PREFIX}options WHERE option_name LIKE '_transient_iato_mcp_oauth_reg_%' OR option_name LIKE '_transient_timeout_iato_mcp_oauth_reg_%'")
admin = login('admin', os.environ.get('ADMIN_LOGIN_PW', 'password'))
record('setup: admin cookie login', admin is not None, None)
if admin is None:
    print('# cannot continue without an admin session'); sys.exit(1)

# ── 1. metadata and discovery ────────────────────────────────────────────
print('# 1. metadata')
st, hd, raw = http('GET', BASE + '/.well-known/oauth-protected-resource')
prm = json.loads(raw)
record('PRM at root', st == 200 and prm.get('resource') == RES and prm.get('authorization_servers') == [BASE], prm)
st, hd, raw = http('GET', BASE + '/.well-known/oauth-protected-resource/wp-json/iato-mcp/v1/message')
record('PRM at the path-aware URL', st == 200 and json.loads(raw) == prm, raw[:200])
st, hd, raw = http('GET', BASE + '/.well-known/oauth-authorization-server')
meta = json.loads(raw)
record('AS metadata: issuer, revocation, grant types, S256, iss', meta.get('issuer') == BASE and meta.get('revocation_endpoint') == BASE + '/oauth/revoke'
       and meta.get('grant_types_supported') == ['authorization_code', 'refresh_token'] and meta.get('code_challenge_methods_supported') == ['S256']
       and meta.get('authorization_response_iss_parameter_supported') is True and 'none' in meta.get('token_endpoint_auth_methods_supported', []), meta)
record('AS metadata: CIMD not advertised', 'client_id_metadata_document_supported' not in meta, meta)
record('metadata: no-store', 'no-store' in hd.get('cache-control', ''), hd.get('cache-control'))
st, hd, raw = http('POST', MSG, {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'})
record('401 without a token carries resource_metadata', st == 401 and 'resource_metadata="' + BASE + '/.well-known/oauth-protected-resource"' in hd.get('www-authenticate', ''), hd.get('www-authenticate'))
st, hd, raw = http('POST', MSG, {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'}, {'Authorization': 'Bearer iato_at_not-a-token'})
record('401 with a bad token adds error="invalid_token"', st == 401 and 'error="invalid_token"' in hd.get('www-authenticate', ''), hd.get('www-authenticate'))

# ── 2. registration ──────────────────────────────────────────────────────
print('# 2. registration')
st, hd, b = register([CB])
client_id = b.get('client_id')
record('register https redirect: 201 with RFC 7591 fields', st == 201 and client_id and b.get('token_endpoint_auth_method') == 'none' and b.get('client_id_issued_at') and b.get('client_name') == 'oauth-live', b)
st, hd, b = register(['http://evil.example/cb'])
record('register http non-loopback: 400 invalid_redirect_uri', st == 400 and b.get('error') == 'invalid_redirect_uri', b)
st, hd, b = register(['myapp://cb'])
record('register custom scheme: 400', st == 400 and b.get('error') == 'invalid_redirect_uri', b)
st, hd, b = register(['http://localhost/callback', 'http://127.0.0.1/callback'], name='oauth-live-code')
loop_client = b.get('client_id')
record('register loopback redirects (Claude Code shape): 201', st == 201 and loop_client, b)
st, hd, b = register([f'https://claude.ai/cb{i}' for i in range(11)])
record('register with 11 redirect_uris: 400', st == 400 and b.get('error') == 'invalid_client_metadata', b)
st, hd, b = register([CB], extra={'token_endpoint_auth_method': 'client_secret_basic'})
record('register confidential client: 400', st == 400 and b.get('error') == 'invalid_client_metadata', b)
st, hd, raw = http('POST', BASE + '/oauth/register', 'not json', {'Content-Type': 'application/json'})
record('register invalid JSON: 400', st == 400, raw[:120])
st, hd, raw = http('GET', BASE + '/oauth/register')
record('register GET: 405', st == 405, st)

# ── 3. consent screen ────────────────────────────────────────────────────
print('# 3. consent screen')
v, c = pkce()
url = authorize_url(client_id, CB, c)
st, hd, raw, nonce = consent(admin, url)
page = raw.decode(errors='replace')
record('consent renders for admin with nonce and Approve/Deny', st == 200 and nonce and 'value="approve"' in page and 'value="deny"' in page, (st, page[:200]))
record('consent shows client name and redirect host', 'oauth-live' in page and '<code>claude.ai</code>' in page, page[:500])
record('consent names the approver', re.search(r'\(admin\), you', page) is not None, re.findall(r'auth-actor.*?</p>', page, re.S)[:1])
record('consent: X-Frame-Options DENY', hd.get('x-frame-options', '').upper() == 'DENY', hd.get('x-frame-options'))
record("consent: CSP frame-ancestors 'none'", "frame-ancestors 'none'" in hd.get('content-security-policy', ''), hd.get('content-security-policy'))
record('consent: no-cache', 'no-cache' in hd.get('cache-control', '') or 'no-store' in hd.get('cache-control', ''), hd.get('cache-control'))
editor = login('oauthed', 'oauthed-pass')
st, hd, raw = http('GET', url, opener=editor) if editor else (0, {}, b'')
record('logged-in editor gets a 403 page, not a login redirect', editor is not None and st == 403 and b'Only administrators can approve' in raw and b'oauthed' in raw, (st, raw[:200]))
st, hd, raw = http('POST', url, urllib.parse.urlencode({'_wpnonce': nonce, 'decision': 'approve'}), opener=editor) if editor else (0, {}, b'')
record('editor cannot approve with an admin nonce either (403)', st == 403, (st, raw[:100]))
st, hd, raw = http('GET', url)
record('anonymous is sent to wp-login', st == 302 and 'wp-login.php' in hd.get('location', ''), (st, hd.get('location')))
st, hd, raw = http('GET', authorize_url('no-such-client', CB, c), opener=admin)
record('unknown client: JSON 400 invalid_client', st == 400 and b'invalid_client' in raw, raw[:120])
st, hd, raw = http('GET', authorize_url(client_id, 'https://evil.example/cb', c), opener=admin)
record('unregistered redirect_uri: JSON 400, no redirect', st == 400 and b'redirect_uri' in raw, raw[:120])
st, hd, raw = http('GET', authorize_url(client_id, CB, c, code_challenge_method='plain'), opener=admin)
q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(hd.get('location', '')).query))
record('plain PKCE: redirect with error, state and iss', st == 302 and q.get('error') == 'invalid_request' and q.get('state') == 's1' and q.get('iss') == BASE, (st, hd.get('location')))
st, hd, raw = http('GET', authorize_url(client_id, CB, c, code_challenge_method=None), opener=admin)
q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(hd.get('location', '')).query))
record('missing code_challenge_method: redirect with invalid_request', st == 302 and q.get('error') == 'invalid_request' and 'S256' in q.get('error_description', ''), (st, hd.get('location')))
st, hd, raw = http('GET', authorize_url(client_id, CB, c, resource='https://other.example/mcp'), opener=admin)
q = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(hd.get('location', '')).query))
record('foreign resource: redirect with invalid_target', st == 302 and q.get('error') == 'invalid_target', (st, hd.get('location')))
st, hd, raw = http('GET', authorize_url(client_id, CB, c, resource=RES.replace('http://', 'https://')), opener=admin)
record('https resource on an http site is accepted', st == 200, (st, hd.get('location')))
st, hd, raw = http('GET', authorize_url(client_id, CB, c, resource=RES + '/'), opener=admin)
record('resource with trailing slash is accepted', st == 200, (st, hd.get('location')))

# ── 4. approve / deny ────────────────────────────────────────────────────
print('# 4. approve and deny')
st, loc, q = decide(admin, url, nonce, 'deny')
record('deny redirects with access_denied + state + iss', st == 302 and loc.startswith(CB + '?') and q.get('error') == 'access_denied' and q.get('state') == 's1' and q.get('iss') == BASE, (st, loc))
st, hd, raw, nonce = consent(admin, url)
st, loc, q = decide(admin, url, nonce, 'approve')
code = q.get('code', '')
record('approve redirects with code + state + iss', st == 302 and loc.startswith(CB + '?') and code and q.get('state') == 's1' and q.get('iss') == BASE, (st, loc))
record('code is random, 43 chars, not the site key', re.fullmatch(r'[A-Za-z0-9_-]{43}', code) is not None and code != KEY, code)
st, hd, raw = http('POST', url, urllib.parse.urlencode({'_wpnonce': 'bad', 'decision': 'approve'}), opener=admin)
record('approve without a valid nonce is refused', st in (403, 400) or b'link you followed has expired' in raw, (st, raw[:120]))
# nonce bound to the request: a nonce rendered for challenge A cannot approve challenge B, another redirect URI, or another client
v_b, c_b = pkce()
st, hd, raw, nonce_a = consent(admin, authorize_url(client_id, CB, c))
st, loc, q = decide(admin, authorize_url(client_id, CB, c_b), nonce_a, 'approve')
record('consent nonce is bound to the code_challenge', st == 403 and not q.get('code'), (st, loc))
st, loc, q = decide(admin, authorize_url(loop_client, 'http://localhost:3118/callback', c), nonce_a, 'approve')
record('consent nonce is bound to the client and redirect_uri', st == 403 and not q.get('code'), (st, loc))
st, loc, q = decide(admin, authorize_url(client_id, CB, c), nonce_a, 'approve')
record('the same nonce approves the request it was rendered for', st == 302 and q.get('code'), (st, loc))

# ── 5. token exchange ────────────────────────────────────────────────────
print('# 5. token exchange')
st, b = token(grant_type='authorization_code', code=code, redirect_uri=CB, client_id=client_id, code_verifier='wrong-verifier-wrong-verifier-wrong-verifier-wrong', resource=RES)
record('wrong verifier: 400 invalid_grant', st == 400 and b.get('error') == 'invalid_grant', b)
st, b = token(grant_type='authorization_code', code=code, redirect_uri=CB, client_id=client_id, code_verifier=v, resource=RES)
record('the code is dead after a failed attempt', st == 400 and b.get('error') == 'invalid_grant', b)
g = grant(admin, client_id)
record('fresh code exchanges: 200 with access, refresh, expires_in', g['token_status'] == 200 and g['access'] and g['refresh'] and g['body'].get('expires_in') == 3600 and g['body'].get('token_type') == 'Bearer', g['body'])
record('access token is prefixed and is not the site key', g['access'] and g['access'].startswith('iato_at_') and g['access'] != KEY, g['access'])
record('refresh token is prefixed', g['refresh'] and g['refresh'].startswith('iato_rt_'), g['refresh'])
st, b = token(grant_type='authorization_code', code=g['code'], redirect_uri=CB, client_id=client_id, code_verifier=g['verifier'], resource=RES)
record('code replay: 400 invalid_grant', st == 400 and b.get('error') == 'invalid_grant' and 'already used' in b.get('error_description', ''), b)
st, hd, r = call(g['access'], 'get_site_info', {})
record('replay revoked the issued token (401)', st == 401 and 'error="invalid_token"' in hd.get('www-authenticate', ''), (st, hd.get('www-authenticate'), r))
g2 = grant(admin, client_id, state='x y&z=1')
record('state with spaces and ampersands round-trips', g2['token_status'] == 200, g2['body'])
st, b = token(grant_type='authorization_code', code='nope', redirect_uri=CB, client_id=client_id, code_verifier=v)
record('unknown code: invalid_grant', st == 400 and b.get('error') == 'invalid_grant', b)
st, b = token(grant_type='authorization_code', code=KEY, redirect_uri=CB, client_id=client_id, code_verifier=v)
record('the site key is not a code', st == 400 and b.get('error') == 'invalid_grant', b)
st, b = token(grant_type='client_credentials', client_id=client_id)
record('client_credentials: unsupported_grant_type', st == 400 and b.get('error') == 'unsupported_grant_type', b)
st, hd, raw = http('POST', BASE + '/oauth/token', {'grant_type': 'authorization_code', 'code': 'nope', 'client_id': client_id}, {'Content-Type': 'application/json'})
record('token endpoint accepts a JSON body too', st == 400 and b'invalid_grant' in raw, raw[:120])
# wrong client / redirect on a fresh code
g3 = {'code': None}
v3, c3 = pkce(); url3 = authorize_url(client_id, CB, c3)
st, hd, raw, n3 = consent(admin, url3); st, loc, q3 = decide(admin, url3, n3, 'approve')
st, b = token(grant_type='authorization_code', code=q3.get('code', ''), redirect_uri=CB, client_id='someone-else', code_verifier=v3)
record('other client_id: invalid_grant', st == 400 and b.get('error') == 'invalid_grant' and 'client_id' in b.get('error_description', ''), b)

# ── 6. using the token ───────────────────────────────────────────────────
print('# 6. the token at the MCP endpoint')
st, hd, r = call(g2['access'], 'get_site_info', {})
record('get_site_info with the access token', st == 200 and 'result' in r and not r['result'].get('isError'), (st, r))
st, hd, r = call(g2['access'], 'create_post', {'title': 'oauth-live post', 'content': '<p>hi</p>', 'status': 'draft', 'post_type': 'post'})
post_id = None
try:
    post_id = json.loads(r['result']['content'][0]['text']).get('id')
except Exception:
    pass
record('create_post with the access token', st == 200 and post_id, (st, r))
author = wp('post', 'get', str(post_id), '--field=post_author') if post_id else ''
admin_id = wp('user', 'get', 'admin', '--field=ID')
record('the post is attributed to the approving administrator', post_id and author == admin_id, (author, admin_id))
if post_id:
    wp('post', 'delete', str(post_id), '--force')
st, hd, r = call(KEY, 'get_site_info', {})
record('the site key still works', st == 200 and 'result' in r, (st, r))
st, hd, r = call(g2['refresh'], 'get_site_info', {})
record('a refresh token is not a bearer credential (401)', st == 401, (st, r))
listing = db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_tokens WHERE token_hash='{sha(g2['access'])}' AND last_used_at IS NOT NULL")
record('last_used_at recorded on the access row', listing.strip() == '1', listing)
hashes = db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_tokens WHERE token_hash IN ('{g2['access']}','{g2['refresh']}','{g2['code']}')")
record('raw tokens are not stored (only hashes)', hashes.strip() == '0', hashes)

# ── 7. refresh ───────────────────────────────────────────────────────────
print('# 7. refresh rotation')
st, b = token(grant_type='refresh_token', refresh_token=g2['refresh'], client_id=client_id, resource=RES)
record('refresh: 200 with a new pair', st == 200 and b.get('access_token') and b.get('refresh_token') and b['access_token'] != g2['access'] and b['refresh_token'] != g2['refresh'], b)
new_access, new_refresh = b.get('access_token'), b.get('refresh_token')
st, hd, r = call(g2['access'], 'get_site_info', {})
record('old access token is dead after rotation', st == 401, st)
st, hd, r = call(new_access, 'get_site_info', {})
record('new access token works', st == 200, st)
st, b = token(grant_type='refresh_token', refresh_token=g2['refresh'], client_id=client_id)
record('old refresh token reuse: invalid_grant', st == 400 and b.get('error') == 'invalid_grant', b)
st, hd, r = call(new_access, 'get_site_info', {})
record('reuse revoked the whole grant (new access token 401)', st == 401, st)
st, b = token(grant_type='refresh_token', refresh_token=new_refresh, client_id=client_id)
record('and the new refresh token too', st == 400 and b.get('error') == 'invalid_grant', b)
g4 = grant(admin, client_id)
st, b = token(grant_type='refresh_token', refresh_token=g4['refresh'], client_id='someone-else')
record('refresh with another client_id: invalid_grant, grant survives', st == 400 and b.get('error') == 'invalid_grant' and call(g4['access'], 'get_site_info', {})[0] == 200, b)

# ── 7b. grant-level revocation: the grant row is the authority ──────────
print('# 7b. grant-level revocation')
g5 = grant(admin, client_id)
grant_id5 = db(f"SELECT grant_id FROM {PREFIX}iato_mcp_oauth_tokens WHERE token_hash='{sha(g5['access'])}'").strip()
record('grant row exists for a new authorization', db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_grants WHERE grant_id='{grant_id5}' AND revoked_at IS NULL").strip() == '1', grant_id5)
db(f"UPDATE {PREFIX}iato_mcp_oauth_grants SET revoked_at='2026-01-01 00:00:00', revoked_reason='test' WHERE grant_id='{grant_id5}'")
record('token rows untouched by the direct grant revoke', db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_tokens WHERE grant_id='{grant_id5}' AND revoked_at IS NULL").strip() == '3', None)
st, hd, r = call(g5['access'], 'get_site_info', {})
record('access token under a revoked grant: 401 even though its row is live', st == 401, (st, r))
st, b = token(grant_type='refresh_token', refresh_token=g5['refresh'], client_id=client_id)
record('refresh under a revoked grant: invalid_grant', st == 400 and b.get('error') == 'invalid_grant' and 'revoked' in b.get('error_description', ''), b)
record('and it was not spent (refused before the claim)', db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_tokens WHERE token_hash='{sha(g5['refresh'])}' AND used_at IS NULL").strip() == '1', None)
reasons = db(f"SELECT DISTINCT revoked_reason FROM {PREFIX}iato_mcp_oauth_grants WHERE revoked_reason IS NOT NULL")
record('revocation paths so far wrote their reasons on the grant rows', all(x in reasons for x in ('code_replay', 'refresh_reuse')), reasons)

# ── 7c. parallel refreshes: exactly one wins, the grant dies ────────────
print('# 7c. concurrent refresh')
g7 = grant(admin, client_id)
results = []
lock = threading.Lock()
def worker():
    st, b = token(grant_type='refresh_token', refresh_token=g7['refresh'], client_id=client_id)
    with lock:
        results.append((st, b))
threads = [threading.Thread(target=worker) for _ in range(20)]
for t in threads: t.start()
for t in threads: t.join()
ok_count = sum(1 for st, b in results if st == 200)
record('20 parallel refreshes with one token: exactly one succeeds', len(results) == 20 and ok_count == 1, [(st, b.get('error') or 'ok') for st, b in results])
grant_id7 = db(f"SELECT grant_id FROM {PREFIX}iato_mcp_oauth_tokens WHERE token_hash='{sha(g7['refresh'])}'").strip()
record('the grant ends up revoked (reuse detected by the losers)', db(f"SELECT revoked_reason FROM {PREFIX}iato_mcp_oauth_grants WHERE grant_id='{grant_id7}'").strip() == 'refresh_reuse', None)
winner = next((b for st, b in results if st == 200), {})
record("the winner's new access token is refused too", bool(winner.get('access_token')) and call(winner['access_token'], 'get_site_info', {})[0] == 401, None)

# ── 8. expiry (backdated rows) ───────────────────────────────────────────
print('# 8. expiry')
db(f"UPDATE {PREFIX}iato_mcp_oauth_tokens SET expires_at='2000-01-01 00:00:00' WHERE token_hash='{sha(g4['access'])}'")
st, hd, r = call(g4['access'], 'get_site_info', {})
record('expired access token: 401 invalid_token', st == 401 and 'error="invalid_token"' in hd.get('www-authenticate', ''), (st, hd.get('www-authenticate')))
st, b = token(grant_type='refresh_token', refresh_token=g4['refresh'], client_id=client_id)
record('refresh still works after the access token expired', st == 200, b)
g4b_refresh = b.get('refresh_token')
db(f"UPDATE {PREFIX}iato_mcp_oauth_tokens SET expires_at='2000-01-01 00:00:00' WHERE token_hash='{sha(g4b_refresh)}'")
st, b = token(grant_type='refresh_token', refresh_token=g4b_refresh, client_id=client_id)
record('expired refresh token: invalid_grant', st == 400 and b.get('error') == 'invalid_grant' and 'expired' in b.get('error_description', ''), b)
v5, c5 = pkce(); url5 = authorize_url(client_id, CB, c5)
st, hd, raw, n5 = consent(admin, url5); st, loc, q5 = decide(admin, url5, n5, 'approve')
db(f"UPDATE {PREFIX}iato_mcp_oauth_tokens SET expires_at='2000-01-01 00:00:00' WHERE token_hash='{sha(q5['code'])}'")
st, b = token(grant_type='authorization_code', code=q5['code'], redirect_uri=CB, client_id=client_id, code_verifier=v5)
record('expired code: invalid_grant', st == 400 and b.get('error') == 'invalid_grant' and 'expired' in b.get('error_description', ''), b)
g6 = grant(admin, client_id)
db(f"UPDATE {PREFIX}iato_mcp_oauth_grants SET granted_at='2000-01-01 00:00:00' WHERE grant_id=(SELECT grant_id FROM {PREFIX}iato_mcp_oauth_tokens WHERE token_hash='{sha(g6['refresh'])}')")
st, b = token(grant_type='refresh_token', refresh_token=g6['refresh'], client_id=client_id)
record('absolute grant lifetime exceeded: invalid_grant, re-consent required', st == 400 and b.get('error') == 'invalid_grant' and 'approve' in b.get('error_description', ''), b)
record('and the access token of that grant is revoked', call(g6['access'], 'get_site_info', {})[0] == 401, None)

# ── 9. revocation ────────────────────────────────────────────────────────
print('# 9. revocation')
ga = grant(admin, client_id)
gb = grant(admin, client_id)
st, hd, raw = http('POST', BASE + '/oauth/revoke', urllib.parse.urlencode({'token': ga['access'], 'client_id': client_id}))
record('revocation endpoint: 200', st == 200, (st, raw[:80]))
record('revoked access token: 401', call(ga['access'], 'get_site_info', {})[0] == 401, None)
st, b = token(grant_type='refresh_token', refresh_token=ga['refresh'], client_id=client_id)
record('revoked grant cannot refresh', st == 400 and b.get('error') == 'invalid_grant', b)
record('the other grant still works', call(gb['access'], 'get_site_info', {})[0] == 200, None)
st, hd, raw = http('POST', BASE + '/oauth/revoke', urllib.parse.urlencode({'token': 'iato_at_unknown'}))
record('revoking an unknown token: 200', st == 200, st)
st, hd, raw = http('POST', BASE + '/oauth/revoke', urllib.parse.urlencode({'token': KEY}))
record('revoking the site key is a no-op (200, key still works)', st == 200 and call(KEY, 'get_site_info', {})[0] == 200, st)
# Settings screen: Revoke button (admin-post with a per-grant nonce)
gb_row = db(f"SELECT grant_id FROM {PREFIX}iato_mcp_oauth_tokens WHERE token_hash='{sha(gb['access'])}'").strip()
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp', opener=admin)
page = raw.decode(errors='replace')
record('Settings lists the connected client', st == 200 and 'Connected OAuth clients' in page and gb_row in page and 'oauth-live' in page and 'claude.ai' in page, (st, gb_row, page.count('oauth-live')))
m = re.search(r'name="grant_id" value="' + re.escape(gb_row) + r'" />\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([^"]+)"', page)
nonce_gb = m.group(1) if m else ''
record('Settings: revoke form carries a nonce', bool(nonce_gb), page[page.find(gb_row) - 200: page.find(gb_row) + 300] if gb_row in page else None)
st, hd, raw = http('POST', BASE + '/wp-admin/admin-post.php', urllib.parse.urlencode({'action': 'iato_mcp_oauth_revoke', 'grant_id': gb_row, '_wpnonce': nonce_gb}), opener=admin)
record('Settings Revoke redirects back to settings', st == 302 and 'page=iato-mcp' in hd.get('location', ''), (st, hd.get('location')))
record('Settings Revoke: token 401 afterwards', call(gb['access'], 'get_site_info', {})[0] == 401, None)
st, hd, raw = http('POST', BASE + '/wp-admin/admin-post.php', urllib.parse.urlencode({'action': 'iato_mcp_oauth_revoke', 'grant_id': gb_row, '_wpnonce': 'bad'}), opener=admin)
record('Settings Revoke without a valid nonce is refused', st in (403, 400) or b'link you followed has expired' in raw, (st, raw[:100]))
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp', opener=admin)
record('Settings no longer lists the revoked grant', gb_row not in raw.decode(errors='replace'), None)
# Settings screen: Disconnect all
gc = grant(admin, client_id)
gd = grant(admin, client_id)
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp', opener=admin)
page = raw.decode(errors='replace')
m = re.search(r'value="iato_mcp_oauth_revoke_all" />\s*<input type="hidden" id="_wpnonce" name="_wpnonce" value="([^"]+)"', page)
nonce_all = m.group(1) if m else ''
record('Settings: Disconnect all form is present with a nonce', 'Disconnect all' in page and bool(nonce_all), page.count('Disconnect all'))
st, hd, raw = http('POST', BASE + '/wp-admin/admin-post.php', urllib.parse.urlencode({'action': 'iato_mcp_oauth_revoke_all', '_wpnonce': 'bad'}), opener=admin)
record('Disconnect all without a valid nonce is refused', st in (403, 400) or b'link you followed has expired' in raw, (st, raw[:100]))
record('and nothing was revoked by the bad request', call(gc['access'], 'get_site_info', {})[0] == 200, None)
st, hd, raw = http('POST', BASE + '/wp-admin/admin-post.php', urllib.parse.urlencode({'action': 'iato_mcp_oauth_revoke_all', '_wpnonce': nonce_all}), opener=admin)
record('Disconnect all redirects back to settings', st == 302 and 'page=iato-mcp' in hd.get('location', ''), (st, hd.get('location')))
record('Disconnect all: both tokens 401', call(gc['access'], 'get_site_info', {})[0] == 401 and call(gd['access'], 'get_site_info', {})[0] == 401, None)
st, b = token(grant_type='refresh_token', refresh_token=gd['refresh'], client_id=client_id)
record('Disconnect all: refresh fails', st == 400 and b.get('error') == 'invalid_grant', b)
record('Disconnect all: the site key still works', call(KEY, 'get_site_info', {})[0] == 200, None)
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp', opener=admin)
page = raw.decode(errors='replace')
record('Settings shows no connected clients and no Disconnect all button', 'No OAuth clients are connected' in page and 'iato_mcp_oauth_revoke_all' not in page, page.count('Disconnect all'))
log = db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_call_log WHERE rpc_method='oauth' AND tool_name='revoke' AND request_args LIKE '%admin_all%'")
record('Disconnect all was logged', int(log or 0) >= 1, log)
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp&tab=diagnostics', opener=admin)
page = raw.decode(errors='replace')
record('Diagnostics shows the OAuth connections row and the revoke log entries', 'OAuth connections' in page and 'revoke' in page, page.count('revoke'))

# ── 10. user checks ──────────────────────────────────────────────────────
print('# 10. approving user demoted / deleted')
second = login('oauth2admin', 'oauth2-pass')
record('second admin cookie login', second is not None, None)
if second:
    gs = grant(second, client_id)
    record('second admin can approve and use a token', gs['token_status'] == 200 and call(gs['access'], 'get_site_info', {})[0] == 200, gs['body'])
    wp('user', 'set-role', 'oauth2admin', 'editor')
    st, hd, r = call(gs['access'], 'get_site_info', {})
    record('demoted approver: 401', st == 401 and 'invalid_token' in hd.get('www-authenticate', ''), (st, r))
    wp('user', 'set-role', 'oauth2admin', 'administrator')
    st, hd, r = call(gs['access'], 'get_site_info', {})
    record('the grant was revoked, so restoring the role does not revive it', st == 401, st)
    st, b = token(grant_type='refresh_token', refresh_token=gs['refresh'], client_id=client_id)
    record('refresh after demotion: invalid_grant', st == 400 and b.get('error') == 'invalid_grant', b)
    log = db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_call_log WHERE rpc_method='auth' AND error_code='iato_mcp_token_user_demoted'")
    record('demotion was logged', int(log or 0) >= 1, log)
    second = login('oauth2admin', 'oauth2-pass')
    gs2 = grant(second, client_id) if second else {'token_status': 0}
    record('second admin reconnects after being restored', gs2['token_status'] == 200, gs2.get('body'))
    wp('user', 'delete', 'oauth2admin', '--yes', '--reassign=1')
    st, hd, r = call(gs2.get('access') or 'iato_at_x', 'get_site_info', {})
    record('deleted approver: 401', st == 401, (st, r))
    st, b = token(grant_type='refresh_token', refresh_token=gs2.get('refresh') or 'x', client_id=client_id)
    record('refresh after deletion: invalid_grant', st == 400 and b.get('error') == 'invalid_grant', b)

# ── 11. Claude Code shape: loopback with an ephemeral port ──────────────
print('# 11. loopback redirect')
gl = grant(admin, loop_client, redirect_uri='http://localhost:3118/callback')
record('registered http://localhost/callback matches http://localhost:3118/callback', gl['approve_status'] == 302 and gl['token_status'] == 200, (gl['approve_status'], gl['body']))
st, hd, raw, n = consent(admin, authorize_url(loop_client, 'http://localhost:3118/other', pkce()[1]))
record('loopback with another path is refused', st == 400, (st, raw[:100]))
st, hd, raw, n = consent(admin, authorize_url(loop_client, 'http://localhost:3118/callback', pkce()[1]))
record('loopback consent shows the local-port warning', st == 200 and b'runs on this computer' in raw and b'<code>localhost:3118</code>' in raw, raw[:100])

# ── 12. client pruning ──────────────────────────────────────────────────
print('# 12. client pruning')
st, hd, b = register([CB], name='oauth-live-pending')
pending = b.get('client_id')
db(f"UPDATE {PREFIX}iato_mcp_oauth_clients SET created_at='2000-01-01 00:00:00' WHERE client_id='{pending}'")
st, hd, b = register([CB], name='oauth-live-idle')
idle = b.get('client_id')
gi = grant(admin, idle)
db(f"UPDATE {PREFIX}iato_mcp_oauth_clients SET authorized_at='2000-01-01 00:00:00' WHERE client_id='{idle}'")
db(f"UPDATE {PREFIX}iato_mcp_oauth_tokens SET expires_at='2000-01-01 00:00:00', created_at='2000-01-01 00:00:00' WHERE client_id='{idle}'")
register([CB], name='oauth-live-trigger')
record('never-approved client older than 24h is pruned', db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_clients WHERE client_id='{pending}'").strip() == '0', pending)
record('approved client with no live grant for 30 days is pruned', db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_clients WHERE client_id='{idle}'").strip() == '0', idle)
record('approved client with a live grant is kept', db(f"SELECT COUNT(*) FROM {PREFIX}iato_mcp_oauth_clients WHERE client_id='{client_id}'").strip() == '1', client_id)

# ── 13. rate limit (last: it poisons later registrations) ───────────────
print('# 13. registration rate limit')
db(f"DELETE FROM {PREFIX}options WHERE option_name LIKE '_transient_iato_mcp_oauth_reg_%' OR option_name LIKE '_transient_timeout_iato_mcp_oauth_reg_%'")
codes = [register([CB], name='oauth-live-rl')[0] for _ in range(11)]
st, hd, b = register([CB], name='oauth-live-rl')
record('11th registration in 10 minutes: 429 with Retry-After', codes[:10] == [201] * 10 and codes[10] == 429 and st == 429 and hd.get('retry-after'), (codes, hd.get('retry-after')))
db(f"DELETE FROM {PREFIX}options WHERE option_name LIKE '_transient_iato_mcp_oauth_reg_%' OR option_name LIKE '_transient_timeout_iato_mcp_oauth_reg_%'")
record('counters cleared: registration works again', register([CB], name='oauth-live-rl')[0] == 201, None)

# ── 14. the site key never appears in an OAuth response ─────────────────
print('# 14. site key sweep')
leaks = [u for (u, s, h, b) in captured if KEY.encode() in b or any(KEY in v for v in h.values())]
record(f'no OAuth/metadata response contains the site key ({len(captured)} responses checked)', not leaks, leaks[:5])

# ── 15. legacy-key flag: hint and badge survive the notice dismissal; only Regenerate clears them ──
print('# 15. legacy-key flag and Regenerate')
wp('option', 'update', 'iato_mcp_oauth_legacy_key', '1')
wp('option', 'update', 'iato_mcp_oauth_legacy_notice', '1')
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp', opener=admin)
page = raw.decode(errors='replace')
record('Settings card hint shown while the legacy-key flag is set', 'Connections made before version 1.13.0' in page, None)
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp&tab=diagnostics', opener=admin)
record('Diagnostics badge shown while the legacy-key flag is set', 'pre-1.13.0 OAuth connections hold the site key' in raw.decode(errors='replace'), None)
st, hd, raw = http('GET', BASE + '/wp-admin/index.php', opener=admin)
page = raw.decode(errors='replace')
m = re.search(r'href="([^"]*iato_mcp_dismiss_oauth_notice[^"]*)"', page)
import html as _html
if m:
    http('GET', _html.unescape(m.group(1)), opener=admin)
record('dismissing the notice hides it', m is not None and 'before version 1.13.0 hold' not in http('GET', BASE + '/wp-admin/index.php', opener=admin)[2].decode(errors='replace'), None)
record('but leaves the legacy-key flag in place', wp('option', 'get', 'iato_mcp_oauth_legacy_key') == '1', None)
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp', opener=admin)
page = raw.decode(errors='replace')
record('card hint still shown after the dismissal', 'Connections made before version 1.13.0' in page, None)
m = re.search(r'href="([^"]*iato_mcp_regenerate_key[^"]*)"', page)
gr = grant(admin, client_id)
st, hd, raw = http('GET', _html.unescape(m.group(1)), opener=admin) if m else (0, {}, b'')
record('Regenerate (admin-post) redirects back to settings', st == 302, (st, hd.get('location')))
NEW_KEY = wp('option', 'get', 'iato_mcp_key')
record('Regenerate issued a new key', NEW_KEY and NEW_KEY != KEY and call(NEW_KEY, 'get_site_info', {})[0] == 200 and call(KEY, 'get_site_info', {})[0] == 401, None)
record('Regenerate cleared the legacy-key flag', wp('option', 'get', 'iato_mcp_oauth_legacy_key') == '', None)
record('OAuth grants survive Regenerate', call(gr['access'], 'get_site_info', {})[0] == 200, None)
st, hd, raw = http('GET', BASE + '/wp-admin/options-general.php?page=iato-mcp', opener=admin)
record('card hint gone after Regenerate', 'Connections made before version 1.13.0' not in raw.decode(errors='replace'), None)

print(f'\n# {passes} passed, {len(fails)} failed')
if fails:
    print('# failed: ' + '; '.join(fails))
sys.exit(1 if fails else 0)

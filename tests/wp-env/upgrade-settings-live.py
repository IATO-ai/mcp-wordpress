"""Settings across an update from WordPress.org. Run through upgrade-settings-live.sh.

PHASE=upgrade: the previous release from WordPress.org is active. Turn the inline markup
setting on through the real form, update with `wp plugin update`, then check the setting,
the rendered form, and a save of that form with two tools switched on.
PHASE=branch: the mounted checkout is active again (its options restored). The save
message names a reconnect only when the tools changed.
"""
import base64
import http.cookiejar
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
KEY = os.environ['KEY']
PHASE = os.environ.get('PHASE', 'upgrade')
FROM_VERSION = os.environ.get('FROM_VERSION', '1.20.0')
SETTINGS = '/wp-admin/options-general.php?page=iato-mcp'
INLINE = 'iato_mcp_inline_markup_enabled'
results = []


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:700]}'))


def wp(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    lines = [line.strip('\r') for line in out.splitlines() if line and not line.startswith(('ℹ', '✔'))]
    return lines[-1] if lines else ''


def option(name):
    return wp('option', 'get', name)


def tool_list():
    try:
        return json.loads(wp('option', 'get', 'iato_mcp_tools', '--format=json'))
    except ValueError:
        return None


def server_version():
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {'protocolVersion': '2025-06-18'}}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + KEY})
    try:
        raw = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    return json.loads(raw).get('result', {}).get('serverInfo', {}).get('version')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def fetch(url, opener, data=None):
    req = urllib.request.Request(BASE + url, data=data, headers={'Cache-Control': 'no-cache'})
    try:
        with opener.open(req, timeout=120) as r:
            return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')


def admin_session():
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
    fetch('/wp-login.php', opener)
    data = urllib.parse.urlencode({'log': 'admin', 'pwd': os.environ.get('ADMIN_PASS', 'password'), 'wp-submit': 'Log In', 'testcookie': '1', 'redirect_to': BASE + '/wp-admin/'}).encode()
    status, _ = fetch('/wp-login.php', opener, data)
    return opener, status in (302, 303)


class SettingsForm(HTMLParser):
    """The fields of the <form action="options.php"> as a browser holds them."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.inside = False
        self.fields = []
        self.textarea = None
        self.select = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.inside = self.inside or a.get('action') == 'options.php'
            return
        if not self.inside or 'disabled' in a:
            return
        name = a.get('name')
        if tag == 'input' and name:
            kind = (a.get('type') or 'text').lower()
            if kind in ('submit', 'button', 'image', 'reset', 'file'):
                return
            if kind in ('checkbox', 'radio'):
                self.fields.append({'name': name, 'value': a.get('value') or 'on', 'box': True, 'checked': 'checked' in a})
            else:
                self.fields.append({'name': name, 'value': a.get('value') or ''})
        elif tag == 'textarea' and name:
            self.textarea = {'name': name, 'value': ''}
        elif tag == 'select' and name:
            self.select = {'name': name, 'value': None, 'first': None}
        elif tag == 'option' and self.select is not None:
            value = a.get('value') or ''
            if self.select['first'] is None:
                self.select['first'] = value
            if 'selected' in a:
                self.select['value'] = value

    def handle_data(self, data):
        if self.textarea is not None:
            self.textarea['value'] += data

    def handle_endtag(self, tag):
        if tag == 'form':
            self.inside = False
        elif tag == 'textarea' and self.textarea is not None:
            self.fields.append(self.textarea)
            self.textarea = None
        elif tag == 'select' and self.select is not None:
            value = self.select['value'] if self.select['value'] is not None else self.select['first']
            self.fields.append({'name': self.select['name'], 'value': value or ''})
            self.select = None


def read_form(opener):
    status, html = fetch(SETTINGS, opener)
    form = SettingsForm()
    form.feed(html)
    nonce = re.search(r'"saveNonce":"([0-9a-f]+)"', html)
    return status, form.fields, nonce.group(1) if nonce else None


def box(fields, name, value):
    return next((f for f in fields if f.get('box') and f['name'] == name and f['value'] == value), None)


def save(opener, fields, nonce):
    """What the page's script sends: FormData of the form, minus the options.php fields."""
    pairs = [(f['name'], f['value']) for f in fields if not f.get('box') or f['checked']]
    pairs = [p for p in pairs if p[0] not in ('option_page', '_wpnonce', '_wp_http_referer')]
    pairs += [('action', 'iato_mcp_save_settings'), ('nonce', nonce)]
    status, body = fetch('/wp-admin/admin-ajax.php', opener, urllib.parse.urlencode(pairs).encode())
    try:
        return json.loads(body)
    except ValueError:
        return {'status': status, 'body': body[:300]}


opener, logged_in = admin_session()
record('logged in to wp-admin', logged_in)

if PHASE == 'upgrade':
    record(f'the WordPress.org release {FROM_VERSION} is active', server_version() == FROM_VERSION, server_version())
    status, fields, nonce = read_form(opener)
    inline = box(fields, INLINE, '1')
    record(f'{FROM_VERSION}: the settings form renders with the inline checkbox, off', status == 200 and inline is not None and not inline['checked'] and nonce, [status, inline, bool(nonce)])
    if inline and nonce:
        inline['checked'] = True
        r = save(opener, fields, nonce)
        record(f'{FROM_VERSION}: ticking it and saving turns it on', r.get('success') is True and option(INLINE) == '1', r)
    tools_before = tool_list()

    # WordPress's own updater, given the release WordPress.org offers. The update check is
    # answered here rather than asked: api.wordpress.org's update endpoint can lag behind a
    # release, and with the mounted copy installed too it answers for that one.
    to_version = os.environ.get('TO_VERSION', '')
    if not to_version:
        info = json.loads(urllib.request.urlopen('https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=iato-mcp', timeout=60).read())
        to_version = info['version']
    wp('eval', "$t = get_site_transient( 'update_plugins' ); if ( ! is_object( $t ) ) { $t = new stdClass(); } "
       "$t->last_checked = time(); $t->checked = []; foreach ( get_plugins() as $f => $d ) { $t->checked[ $f ] = $d['Version']; } "
       "$t->response = (array) ( $t->response ?? [] ); $t->response['iato-mcp/iato-mcp.php'] = (object) [ 'id' => 'w.org/plugins/iato-mcp', 'slug' => 'iato-mcp', 'plugin' => 'iato-mcp/iato-mcp.php', "
       f"'new_version' => '{to_version}', 'url' => 'https://wordpress.org/plugins/iato-mcp/', 'package' => 'https://downloads.wordpress.org/plugin/iato-mcp.{to_version}.zip' ]; "
       "unset( $t->no_update['iato-mcp/iato-mcp.php'] ); set_site_transient( 'update_plugins', $t );")
    print('  ' + wp('plugin', 'update', 'iato-mcp'))
    version = wp('plugin', 'get', 'iato-mcp', '--field=version')
    record(f'updated from WordPress.org to {version}', version not in ('', FROM_VERSION), version)
    record('the server answers as the new version', server_version() == version, server_version())
    record('the inline setting is still on after the update and a request', option(INLINE) == '1', option(INLINE))
    record('the tool selection is unchanged', tool_list() == tools_before and tools_before, tools_before)

    status, fields, nonce = read_form(opener)
    inline = box(fields, INLINE, '1')
    record('the form renders it ticked', status == 200 and inline is not None and inline['checked'], inline)
    new_tools = [box(fields, 'iato_mcp_tools[]', t) for t in ('get_page_css', 'set_page_css')]
    record('the new tools render unticked', all(t is not None and not t['checked'] for t in new_tools), new_tools)
    for t in new_tools:
        if t:
            t['checked'] = True
    r = save(opener, fields, nonce)
    tools = tool_list() or []
    record('saving the form with two new tools on succeeds', r.get('success') is True, r)
    record('...and switches them on', 'set_page_css' in tools and 'get_page_css' in tools, tools)
    record('...and keeps the inline setting on', option(INLINE) == '1', option(INLINE))
    status, fields, nonce = read_form(opener)
    r = save(opener, fields, nonce)
    record('saving it again unchanged keeps it on', r.get('success') is True and option(INLINE) == '1', [r, option(INLINE)])

else:
    status, fields, nonce = read_form(opener)
    record('the checkout renders the settings form', status == 200 and nonce and box(fields, INLINE, '1') is not None, status)
    inline_before = option(INLINE)
    r = save(opener, fields, nonce)
    message = (r.get('data') or {}).get('message', '')
    record('a save that changes no tool says only that it saved', r.get('success') is True and message == 'Settings saved.' and (r.get('data') or {}).get('values', {}).get('tools_changed') is False, r)
    status, fields, nonce = read_form(opener)
    target = box(fields, 'iato_mcp_tools[]', 'get_page_fonts')
    if target:
        target['checked'] = not target['checked']
    r = save(opener, fields, nonce)
    message = (r.get('data') or {}).get('message', '')
    record('a save that changes a tool says to reconnect', r.get('success') is True and 'reconnect' in message and (r.get('data') or {}).get('values', {}).get('tools_changed') is True, r)
    _, html = fetch(SETTINGS, opener)
    record('the Tools card says clients read the list when they connect', 'AI clients read this list when they connect' in html)
    record('neither save touched the inline setting', option(INLINE) == inline_before, [inline_before, option(INLINE)])

failed = [name for name, ok in results if not ok]
print(f"# {len(results) - len(failed)} passed, {len(failed)} failed" + (': ' + '; '.join(failed) if failed else ''))
raise SystemExit(1 if failed else 0)

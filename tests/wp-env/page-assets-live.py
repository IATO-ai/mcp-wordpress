"""Driven by page-assets-live.sh. Prints `# N passed, M failed` last."""
import base64
import hashlib
import http.cookiejar
import json
import os
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
KEY = os.environ['KEY']
PASSWORDS = {'admin': os.environ['PW_ADMIN'], 'assetseditor': os.environ['PW_EDITOR'], 'assetsauthor': os.environ['PW_AUTHOR'], 'assetscontrib': os.environ['PW_CONTRIB']}
PLUGINS = os.environ.get('PLUGINS') == '1'
T = 'page-assets-live'
ASSET_TOOLS = ['get_page_css', 'set_page_css', 'get_page_fonts', 'set_page_fonts', 'create_preview_link']
results = []


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail, default=str)[:700]}'))


def run(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    return [line.strip('\r') for line in out.splitlines() if line and not line.startswith(('ℹ', '✔'))]


def wp(*args):
    lines = run('wp', *args)
    return lines[-1] if lines else ''


def php(code):
    return wp('eval', code, '--user=admin')


def rpc(method, params, who='key'):
    auth = 'Bearer ' + KEY if who == 'key' else 'Basic ' + base64.b64encode(f'{who}:{PASSWORDS[who]}'.encode()).decode()
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth})
    try:
        raw = urllib.request.urlopen(req, timeout=180).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    r = json.loads(raw)
    return r.get('result', r)


def call(tool, args, who='key'):
    res = rpc('tools/call', {'name': tool, 'arguments': args}, who)
    if 'content' not in res:
        return {'isError': True, '_text': json.dumps(res)}
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


def refused(r, code):
    return bool(r.get('isError')) and (r.get('error') == code or r.get('code') == code or code in r.get('_text', ''))


def receipt_of(r):
    return (r.get('change_receipt') or {}).get('change_id')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


ANON = urllib.request.build_opener(NoRedirect)


def fetch(url, opener=ANON, data=None):
    """Status, headers and body, without following a redirect."""
    url = url if url.startswith('http') else BASE + url
    req = urllib.request.Request(url, data=data, headers={'Cache-Control': 'no-cache'})
    try:
        with opener.open(req, timeout=90) as r:
            return r.status, r.headers, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode('utf-8', 'replace')


def page(title, status='draft', who='key', ptype='page'):
    r = call('create_post', {'title': f'{T} {title}', 'post_type': ptype, 'status': status, 'content': f'<p>{T} {title} body</p>'}, who)
    return r


def tools_listed():
    return {t['name'] for t in rpc('tools/list', {}).get('tools', [])}


def admin_session():
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
    fetch('/wp-login.php', opener)
    data = urllib.parse.urlencode({'log': 'admin', 'pwd': os.environ.get('ADMIN_PASS', 'password'), 'wp-submit': 'Log In', 'testcookie': '1', 'redirect_to': BASE + '/wp-admin/'}).encode()
    status, headers, _ = fetch('/wp-login.php', opener, data)
    return opener, status in (302, 303)


def forms(html):
    """The small POST forms the Page assets tab prints: action and hidden fields."""
    out = []
    for m in re.finditer(r'<form method="post" action="[^"]*admin-post\.php"[^>]*>(.*?)</form>', html, re.S):
        fields = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="([^"]+)" value="([^"]*)"', m.group(1)))
        out.append(fields)
    return out


# ── A. The tools ship switched off ─────────────────────────────────────────────

def tools_off_by_default():
    print('A. Off by default')
    php('update_option("iato_mcp_tools", []);')
    listed = tools_listed()
    record('a fresh install lists none of the page asset tools', not (set(ASSET_TOOLS) & listed) and 'update_custom_css' in listed, sorted(set(ASSET_TOOLS) & listed))
    php('$t=array_values(array_diff((new ReflectionClassConstant("IATO_MCP_Settings","TOOL_NAMES"))->getValue(), IATO_MCP_Settings::DEFAULT_OFF_TOOLS)); update_option("iato_mcp_tools", $t);')
    record('a saved list from before 1.21.0 does not hold them either', not (set(ASSET_TOOLS) & tools_listed()))
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_unique(array_merge($t, ' + json.dumps(ASSET_TOOLS).replace('[', 'array(').replace(']', ')') + '))));')
    record('switched on, they are listed', set(ASSET_TOOLS) <= tools_listed())


# ── B. Page CSS ────────────────────────────────────────────────────────────────

CSS = "@property --p{syntax:'<number>';inherits:true;initial-value:84}\n.page-assets-live-box{color:rgb(1, 2, 3)}"


def page_css(p1, p2):
    print('B. Page CSS')
    d = call('set_page_css', {'id': p1, 'css': CSS, 'dry_run': True})
    record('a dry run writes nothing', d.get('dry_run') is True and d.get('action') == 'would_update' and call('get_page_css', {'id': p1}).get('bytes') == 0, d)
    r = call('set_page_css', {'id': p1, 'css': '@import url(https://fonts.example/a.css);.x{color:red}'})
    record('@import is refused and nothing is stored', refused(r, 'css_not_allowed') and call('get_page_css', {'id': p1}).get('bytes') == 0, r)
    r = call('set_page_css', {'id': p1, 'css': CSS + '</style >'})
    g = call('get_page_css', {'id': p1})
    record('stored as sent, the closing tag removed and reported', g.get('css') == CSS and g.get('sha256') == hashlib.sha256(CSS.encode()).hexdigest() and r.get('sanitized_report', [{}])[0].get('removed_css') == ['</style'], {'r': r, 'g': g})
    record('the cache object purges that page only', (r.get('cache') or {}).get('scope') in ('post', None) and receipt_of(r), r.get('cache'))
    first = receipt_of(r)
    link = call('create_preview_link', {'id': p1})
    status, _, html = fetch(link.get('url', '/'))
    record('the CSS prints in the draft\'s head, as it is', status == 200 and f'<style id="iato-mcp-page-css">{CSS}</style>' in html, (status, html[:300]))
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('...and on no other page', status == 200 and 'iato-mcp-page-css' not in html, status)

    print('B. who may write it')
    record('an editor may (holds edit_css)', not call('set_page_css', {'id': p1, 'append_css': '.e{}'}, 'assetseditor').get('isError'))
    r = call('set_page_css', {'id': p1, 'css': '.a{}'}, 'assetsauthor')
    record('an author may not', r.get('isError') and call('get_page_css', {'id': p1}).get('css', '').endswith('.e{}'), r)
    wp('option', 'update', 'iato_mcp_key_owner', '0')
    try:
        r = call('set_page_css', {'id': p1, 'css': '.a{}'})
        record('a site key with no owner may not', r.get('isError') and 'not assigned to a WordPress user' in r.get('_text', ''), r)
    finally:
        wp('option', 'update', 'iato_mcp_key_owner', os.environ.get('OWNER', '1') or '1')

    print('B. checked again when printed, and out of reach of the meta tools')
    wp('post', 'meta', 'update', str(p2), '_iato_mcp_page_css', '.page-assets-live-forged{background:url(https://evil.example/f.png)}')
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('CSS forged straight into the meta does not print', 'evil.example' not in html and 'page-assets-live-forged' not in html, status)
    wp('post', 'meta', 'delete', str(p2), '_iato_mcp_page_css')
    r = call('update_post_meta', {'id': p2, 'key': '_iato_mcp_page_css', 'value': '.x{}', 'force': True})
    record('update_post_meta may not write it, even with force', r.get('isError'), r)
    r = call('update_post_meta', {'id': p2, 'key': '_iato_mcp_page_script', 'value': 'alert(1)', 'force': True})
    record('...nor a page script', r.get('isError'), r)
    r = call('get_post_meta', {'id': p1, 'key': '_iato_mcp_page_css', 'include_protected': True})
    record('get_post_meta does not read it', r.get('isError') or CSS not in json.dumps(r), r)
    old = php(f'$r = IATO_MCP_Change_Receipt::record( {p2}, "post_meta", "_iato_mcp_page_css", null, ".x{{}}" ); echo $r["change_id"] ?? "";')
    rb = call('rollback', {'change_id': old})
    record('an older post_meta receipt cannot put page CSS back', old.startswith('wr_') and refused(rb, 'rollback_unavailable'), rb)

    print('B. rollback')
    r = call('set_page_css', {'id': p1, 'css': '.second{}'})
    rb = call('rollback', {'change_id': receipt_of(r)})
    record('rollback puts the previous CSS back', rb.get('success') and call('get_page_css', {'id': p1}).get('css', '').startswith(CSS), rb)
    rb = call('rollback', {'change_id': first})
    record('a rollback over a later change is a conflict', rb.get('isError') and 'rollback_conflict' in rb.get('_text', ''), rb)
    return first


# ── C. Page fonts ──────────────────────────────────────────────────────────────

def page_fonts(p2, other):
    print('C. Page fonts')
    wp('option', 'update', 'elementor_local_google_fonts', '0')
    r = call('set_page_fonts', {'id': p2, 'fonts': ['Comic Sans Nonexistent']})
    record('an unknown family is refused', refused(r, 'unknown_font'), r)
    r = call('set_page_fonts', {'id': p2, 'fonts': ['JetBrains Mono']})
    record('a Google family is accepted, with its type', r.get('types', {}).get('JetBrains Mono') == 'googlefonts' and receipt_of(r), r)
    status, _, html = fetch(wp('post', 'url', str(p2)))
    # Elementor serves a local copy once it has one, even with the setting off.
    record('it is requested on that page', re.search(r'<link[^>]+jetbrains', html, re.I), re.findall(r'<link[^>]+(?:fonts\.googleapis|google-fonts)[^>]*>', html)[:3])
    status, _, html = fetch(wp('post', 'url', str(other)))
    record('...and on no other page', not re.search(r'<link[^>]+jetbrains', html, re.I), status)
    wp('option', 'update', 'elementor_local_google_fonts', '1')
    status, _, html = fetch(wp('post', 'url', str(p2)))
    links = re.findall(r'<link[^>]+(?:jetbrains|JetBrains)[^>]*>', html)
    record('with Google fonts loaded locally, the browser asks this site, not Google', links and all('fonts.googleapis.com' not in l for l in links) and any('google-fonts' in l for l in links), links)
    wp('option', 'update', 'elementor_local_google_fonts', '0')
    g = call('get_page_fonts', {'id': p2})
    record('get_page_fonts reads them back', g.get('fonts') == ['JetBrains Mono'], g)
    r = call('set_page_fonts', {'id': p2, 'fonts': []})
    rb = call('rollback', {'change_id': receipt_of(r)})
    record('clearing them rolls back', rb.get('success') and call('get_page_fonts', {'id': p2}).get('fonts') == ['JetBrains Mono'], rb)


# ── D. Preview links ───────────────────────────────────────────────────────────

def preview_links(p1, p2, p3):
    print('D. Preview links')
    status, _, _ = fetch(f'/?page_id={p1}')
    record('a draft is a 404 without a link', status == 404, status)
    link = call('create_preview_link', {'id': p1, 'ttl_seconds': 600})
    url = link.get('url', '')
    record('a link comes back with its expiry and receipt', url and 'iato_preview=' in url and link.get('ttl_seconds') == 600 and receipt_of(link), link)
    token = url.split('iato_preview=')[-1]
    status, headers, html = fetch(url)
    record('anyone with it sees the draft', status == 200 and f'{T} draft one body' in html, (status, html[:200]))
    cc = headers.get('Cache-Control', '') if headers else ''
    record('...with no-store, noindex and no-referrer', 'no-store' in cc and 'noindex' in (headers.get('X-Robots-Tag') or '') and headers.get('Referrer-Policy') == 'no-referrer' and re.search(r'<meta name=.robots. content=.[^>]*noindex', html), {'cc': cc, 'robots': headers.get('X-Robots-Tag'), 'ref': headers.get('Referrer-Policy')})
    if PLUGINS:
        # No local site runs a LiteSpeed server, where LiteSpeed Cache sends its own headers:
        # the probe mu-plugin shows the action it listens on fired for this request.
        listening = php('echo has_action("litespeed_control_set_nocache") > 1 || count( $GLOBALS["wp_filter"]["litespeed_control_set_nocache"]->callbacks ?? [] ) > 1 ? 1 : 0;')
        record('LiteSpeed is told not to cache it', 'preview' in (headers.get('X-Iato-Test-Litespeed-Nocache') or '').lower() and listening == '1', {'probe': headers.get('X-Iato-Test-Litespeed-Nocache'), 'litespeed_listens': listening})
    status, _, _ = fetch(f'/?page_id={p3}&iato_preview={token}')
    record('the token opens only its own post', status == 404, status)
    status, _, _ = fetch(f'/?page_id={p1}&iato_preview={token[:-1]}x')
    record('a wrong token is a 404', status == 404, status)
    r = call('create_preview_link', {'id': p2})
    record('a published post needs no link', refused(r, 'preview_not_needed'), r)
    r = call('create_preview_link', {'id': p1, 'ttl_seconds': 4000})
    record('more than an hour is refused', refused(r, 'invalid_ttl'), r)
    own = page('author draft', who='assetsauthor', ptype='post')  # authors write posts, not pages
    r = call('create_preview_link', {'id': own.get('id')}, 'assetsauthor')
    record('an author may link their own draft (they can publish it)', r.get('url'), r)
    mine = page('contributor draft', who='assetscontrib', ptype='post')
    r = call('create_preview_link', {'id': mine.get('id')}, 'assetscontrib')
    record('a contributor may not (they cannot publish)', r.get('isError'), r)
    second = call('create_preview_link', {'id': p1})
    php(f'$l=get_post_meta({p1},"_iato_mcp_preview_links",true); foreach($l as $i=>$x){{ $l[$i]["expires"]=time()-1; }} update_post_meta({p1},"_iato_mcp_preview_links",$l);')
    status, _, _ = fetch(second.get('url', '/'))
    record('an expired link is a 404', status == 404, status)
    third = call('create_preview_link', {'id': p1})
    status, _, _ = fetch(third.get('url', '/'))
    rb = call('rollback', {'change_id': receipt_of(third)})
    status2, _, _ = fetch(third.get('url', '/'))
    record('rolling a link back revokes it', status == 200 and rb.get('success') and status2 == 404, (status, rb, status2))
    stored = wp('post', 'meta', 'get', str(p1), '_iato_mcp_preview_links', '--format=json')
    record('the token itself is never stored', token not in stored, stored[:200])

    # 1.21.0 review: the posts page lists posts, so a link must never become a listing.
    fourth = call('create_preview_link', {'id': p3})
    front = wp('option', 'get', 'show_on_front')
    wp('option', 'update', 'show_on_front', 'page')
    wp('option', 'update', 'page_for_posts', str(p3))
    try:
        r = call('create_preview_link', {'id': p3})
        record('the posts page gets no preview link', refused(r, 'preview_not_supported'), r)
        status, _, html = fetch(fourth.get('url', '/'))
        # WordPress lists posts there; the token must not make it list drafts.
        record('a link made before the page became the posts page lists no draft', f'{T} draft one body' not in html and 'contributor draft' not in html and 'author draft' not in html, status)
    finally:
        wp('option', 'update', 'page_for_posts', '0')
        wp('option', 'update', 'show_on_front', front or 'posts')
    status, _, _ = fetch(f"/?page_id={p3}&p={p1}&iato_preview={(fourth.get('url') or '=').split('iato_preview=')[-1]}")
    record('a URL naming two posts is refused', status == 404, status)
    fifth = call('create_preview_link', {'id': p3})
    call('update_post', {'id': p3, 'status': 'publish'})
    call('update_post', {'id': p3, 'status': 'draft'})
    status, _, _ = fetch(fifth.get('url', '/'))
    record('publishing a post ends its links, even if it goes back to draft', status == 404, status)
    return link


# ── E. A Canvas page that is the front page ────────────────────────────────────

def front_page_canvas():
    print('E. Front page with the Canvas template')
    tree = [{'id': 'pal0001', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'pal0002', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': 'Canvas front'}, 'elements': []}]}]
    front = page('canvas front', 'publish')['id']
    call('update_elementor_data', {'id': front, 'elementor_data': json.dumps(tree)})
    wp('post', 'meta', 'update', str(front), '_wp_page_template', 'elementor_canvas')
    call('set_page_css', {'id': front, 'css': '.canvas-front-live{color:red}'})
    call('set_page_fonts', {'id': front, 'fonts': ['JetBrains Mono']})
    wp('option', 'update', 'show_on_front', 'page')
    wp('option', 'update', 'page_on_front', str(front))
    status, _, html = fetch('/')
    seen = {'css': '.canvas-front-live{color:red}' in html, 'fonts': bool(re.search(r'<link[^>]+jetbrains', html, re.I)), 'canvas': 'elementor-template-canvas' in html, 'front': f'page-id-{front}' in html}
    record('the front page prints its CSS and fonts in Canvas', status == 200 and all(seen.values()), (status, seen, re.findall(r'<body[^>]*>', html)))
    draft = page('canvas draft')['id']
    call('update_elementor_data', {'id': draft, 'elementor_data': json.dumps(tree)})
    wp('post', 'meta', 'update', str(draft), '_wp_page_template', 'elementor_canvas')
    call('set_page_css', {'id': draft, 'css': '.canvas-draft-live{color:blue}'})
    link = call('create_preview_link', {'id': draft})
    status, _, html = fetch(link.get('url', '/'))
    record('a preview of a Canvas draft prints its CSS', status == 200 and '.canvas-draft-live{color:blue}' in html, status)
    return front


# ── F. Switching tools off, the Page assets tab and the Plugins screen ─────────

def admin_side(p1):
    print('F. wp-admin')
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_diff($t, array("set_page_css","get_page_css"))));')
    link = call('create_preview_link', {'id': p1})
    status, _, html = fetch(link.get('url', '/'))
    record('switching the tool off keeps the stored CSS printing', 'iato-mcp-page-css' in html, status)
    record('...and hides the tool', 'set_page_css' not in tools_listed())
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_unique(array_merge($t, array("set_page_css","get_page_css")))));')

    opener, ok = admin_session()
    record('setup: logged in to wp-admin', ok)
    status, _, html = fetch('/wp-admin/options-general.php?page=iato-mcp&tab=assets', opener)
    record('the Page assets tab lists the post', status == 200 and f'<code>{p1}</code>' in html, status)
    target = next((f for f in forms(html) if f.get('action') == 'iato_mcp_remove_page_css' and f.get('post_id') == str(p1)), None)
    record('...with a Remove CSS button', target is not None, forms(html)[:3])
    if target:
        status, headers, _ = fetch('/wp-admin/admin-post.php', opener, urllib.parse.urlencode(target).encode())
        record('Remove CSS takes it off', status in (302, 303) and call('get_page_css', {'id': p1}).get('bytes') == 0, status)
        rid = php(f'global $wpdb; echo $wpdb->get_var("SELECT change_id FROM {{$wpdb->prefix}}iato_change_receipts WHERE post_id={p1} AND target_type=\'page_css\' AND actor_kind=\'wordpress\' ORDER BY id DESC LIMIT 1");')
        rb = call('rollback', {'change_id': rid})
        record('...records a receipt, whose rollback brings it back', rid.startswith('wr_') and rb.get('success') and call('get_page_css', {'id': p1}).get('bytes', 0) > 0, rb)
        bad = dict(target, _wpnonce='0000000000')
        status, _, _ = fetch('/wp-admin/admin-post.php', opener, urllib.parse.urlencode(bad).encode())
        record('a wrong nonce is refused', status in (403, 200) and call('get_page_css', {'id': p1}).get('bytes', 0) > 0, status)
    count = php('echo IATO_MCP_Page_Assets_Admin::count();')
    status, _, html = fetch('/wp-admin/plugins.php', opener)
    record('the Plugins screen warns, with the count', status == 200 and 'iato-mcp-assets-row' in html and f'data-iato-mcp-assets="{count}"' in html and int(count) >= 2, {'count': count, 'status': status})


# ── G. The report points at set_page_css ───────────────────────────────────────

def report_pointer():
    print('G. The report')
    tree = [{'id': 'pal0101', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'pal0102', 'elType': 'widget', 'widgetType': 'html', 'settings': {'html': '<p>x</p>'}, 'elements': []}]}]
    pid = page('report')['id']
    call('update_elementor_data', {'id': pid, 'elementor_data': json.dumps(tree)})
    r = call('set_widget_setting', {'id': pid, 'widget_id': 'pal0102', 'key': 'html', 'value': '<style>.a{}</style><p>x</p>'})
    note = (r.get('sanitized_report') or [{}])[0].get('note', '')
    record('a removed style block names set_page_css', 'set_page_css' in note, note)
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_diff($t, array("set_page_css"))));')
    r = call('set_widget_setting', {'id': pid, 'widget_id': 'pal0102', 'key': 'html', 'value': '<style>.b{}</style><p>y</p>'})
    note = (r.get('sanitized_report') or [{}])[0].get('note', '')
    record('...and, while it is off, where an administrator turns it on', 'Settings > IATO MCP > Tools' in note, note)
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_unique(array_merge($t, array("set_page_css")))));')
    r = call('set_widget_setting', {'id': pid, 'widget_id': 'pal0102', 'key': 'html', 'value': '<script>x()</script><p>z</p>'})
    note = (r.get('sanitized_report') or [{}])[0].get('note', '')
    record('a removed script names no page script tool while that is off', 'set_page_script' not in note, note)


# ── H. Page scripts ────────────────────────────────────────────────────────────

SCRIPT = "document.documentElement.setAttribute('data-iato-live', 'ran');"


def purged(post_id):
    log = php('echo wp_json_encode( get_option( "iato_mcp_test_purges", [] ) );')
    try:
        entries = json.loads(log or '[]')
    except ValueError:
        entries = []
    return [e for e in entries if e.get('post_id') == post_id or e.get('scope') == 'all']


def clear_purges():
    php('delete_option("iato_mcp_test_purges");')


def page_scripts(p2, opener):
    print('H. Page scripts')
    record('the script tools ship switched off', not ({'get_page_script', 'set_page_script'} & tools_listed()))
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_unique(array_merge($t, array("get_page_script","set_page_script")))));')
    wp('option', 'update', 'iato_mcp_page_scripts_enabled', '0')
    r = call('set_page_script', {'id': p2, 'script': SCRIPT})
    record('with the setting off, set_page_script refuses', refused(r, 'page_scripts_disabled'), r)
    wp('option', 'update', 'iato_mcp_page_scripts_enabled', '1')
    r = call('set_page_script', {'id': p2, 'script': "x = '</script><img src=x onerror=alert(1)>';"})
    record('a closing script tag is refused', refused(r, 'script_not_allowed'), r)
    r = call('set_page_script', {'id': p2, 'script': 'if (mode != "view\u202e \u2066// admins\u2069 \u2066") { x(); }'})
    record('a direction-changing character is refused (what is read must be what runs)', refused(r, 'script_not_allowed') and 'U+202E' in r.get('_text', ''), r)
    record('an editor may not', call('set_page_script', {'id': p2, 'script': SCRIPT}, 'assetseditor').get('isError'))
    r = call('set_page_script', {'id': p2, 'script': SCRIPT})
    record('stored, pending, and the response says it needs approval', r.get('status') == 'pending' and 'approve' in r.get('note', '') and receipt_of(r), r)
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('a pending script does not print', 'iato-mcp-page-script' not in html and SCRIPT not in html, status)

    status, _, tab = fetch('/wp-admin/options-general.php?page=iato-mcp&tab=assets', opener)
    record('the tab shows the whole script', SCRIPT.replace("'", '&#039;') in tab or SCRIPT in tab, status)
    form = None
    m = re.search(r'<form method="post" action="[^"]*admin-post\.php">\s*<input type="hidden" name="action" value="iato_mcp_approve_page_script">(.*?)</form>', tab, re.S)
    if m:
        form = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="([^"]+)" value="([^"]*)"', m.group(1)))
        form['action'] = 'iato_mcp_approve_page_script'
    record('...with an Approve form asking for the password', form and form.get('sha256') == hashlib.sha256(SCRIPT.encode()).hexdigest() and 'name="iato_password"' in tab, form)
    if not form:
        return
    status, headers, _ = fetch('/wp-admin/admin-post.php', opener, urllib.parse.urlencode(dict(form, iato_password='wrong-password')).encode())
    record('a wrong password approves nothing', 'iato_script_error=password' in (headers.get('Location') or '') and call('get_page_script', {'id': p2}).get('status') == 'pending', headers.get('Location'))
    status, headers, _ = fetch('/wp-admin/admin-post.php', opener, urllib.parse.urlencode(dict(form, iato_password=os.environ.get('ADMIN_PASS', 'password'))).encode())
    g = call('get_page_script', {'id': p2})
    display = php('echo get_userdata(1)->display_name;')
    record('the right password approves it', g.get('status') == 'approved' and g.get('approved_by') == display, g)
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('an approved script prints in the footer of its page', f'id="iato-mcp-page-script-{p2}"' in html and SCRIPT in html, status)
    status, _, html = fetch(BASE + '/')  # the Canvas front page from section E
    record('...and on no other page', SCRIPT not in html, status)
    count = php('echo IATO_MCP_Page_Assets_Admin::count();')
    status, _, plugins = fetch('/wp-admin/plugins.php', opener)
    record('the deactivation warning counts it', f'data-iato-mcp-assets="{count}"' in plugins, count)

    r = call('set_page_script', {'id': p2, 'script': SCRIPT + ' /* changed */'})
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('a change waits for approval again', r.get('status') == 'pending' and SCRIPT not in html, r.get('status'))
    rb = call('rollback', {'change_id': receipt_of(r)})
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('rolling back to the approved text runs it again', rb.get('success') and SCRIPT in html, rb)

    replay = form.copy()
    replay['iato_password'] = os.environ.get('ADMIN_PASS', 'password')
    call('set_page_script', {'id': p2, 'script': 'var other = 1;'})
    status, headers, _ = fetch('/wp-admin/admin-post.php', opener, urllib.parse.urlencode(replay).encode())
    record('an approval for one text cannot approve another', 'iato_script_error=changed' in (headers.get('Location') or '') and call('get_page_script', {'id': p2}).get('status') == 'pending', headers.get('Location'))
    call('set_page_script', {'id': p2, 'script': SCRIPT})  # back to the approved text

    print('H. switches and caches')
    clear_purges()
    wp('option', 'update', 'iato_mcp_page_scripts_enabled', '0')
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('turning the setting off stops it and purges its page', SCRIPT not in html and purged(p2), purged(p2))
    if PLUGINS:
        record('...through LiteSpeed Cache', any('litespeed' in json.dumps(e.get('results', {})).lower() for e in purged(p2)), purged(p2))
    wp('option', 'update', 'iato_mcp_page_scripts_enabled', '1')
    clear_purges()
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_diff($t, array("set_page_script"))));')
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('switching the tool off stops it and purges its page', SCRIPT not in html and purged(p2), purged(p2))
    php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_unique(array_merge($t, array("set_page_script")))));')
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('switching it back on runs it again', SCRIPT in html, status)
    clear_purges()
    wp('plugin', 'deactivate', 'mcp-wordpress')
    record('deactivating the plugin purges its page', purged(p2), purged(p2))
    wp('plugin', 'activate', 'mcp-wordpress')

    tree = [{'id': 'pal0201', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'pal0202', 'elType': 'widget', 'widgetType': 'html', 'settings': {'html': '<p>x</p>'}, 'elements': []}]}]
    pid = page('script report')['id']
    call('update_elementor_data', {'id': pid, 'elementor_data': json.dumps(tree)})
    r = call('set_widget_setting', {'id': pid, 'widget_id': 'pal0202', 'key': 'html', 'value': '<script>x()</script><p>z</p>'})
    note = (r.get('sanitized_report') or [{}])[0].get('note', '')
    record('with both on, a removed script names set_page_script', 'set_page_script' in note, note)

    status, _, tab = fetch('/wp-admin/options-general.php?page=iato-mcp&tab=assets', opener)
    revoke = next((f for f in forms(tab) if f.get('action') == 'iato_mcp_revoke_page_script' and f.get('post_id') == str(p2)), None)
    if revoke:
        fetch('/wp-admin/admin-post.php', opener, urllib.parse.urlencode(revoke).encode())
    status, _, html = fetch(wp('post', 'url', str(p2)))
    record('revoking the approval stops it', revoke and SCRIPT not in html and call('get_page_script', {'id': p2}).get('status') == 'pending', status)


# ── I. Pages for the browser check (KEEP=1 only) ───────────────────────────────

SPEC_SVG = "<svg width='160' height='160' viewBox='0 0 16 16' role='img' aria-hidden='true' class='pal-svg'><title>t</title><defs><linearGradient id='g1'><stop offset='0' stop-color='#fff'></stop></linearGradient><marker id='m1' orient='auto' markerWidth='5' markerHeight='5'><path d='M0 0L4 2L0 4Z'></path></marker></defs><g transform='rotate(-14 8 8)'><path d='M3 8h10' fill='none' stroke='currentColor' stroke-width='1.75' marker-end='url(#m1)'></path><circle cx='8' cy='8' r='5'></circle><rect x='1' y='5' width='8' height='6'></rect><ellipse class='pal-ellipse' cx='8' cy='8' rx='6' ry='4'></ellipse><line x1='0' y1='0' x2='16' y2='16'></line><text x='2' y='12' text-anchor='middle' font-size='8'>A</text><use href='#g1'></use></g></svg>"
SPEC_STYLE = "<style>@property --p{syntax:'<number>';inherits:true;initial-value:84}.pal-prop{width:calc(var(--p) * 1px)}</style><div class='pal-prop'>p</div>"


def for_browser(opener):
    print('I. Pages for the browser check')
    wp('option', 'update', 'iato_mcp_inline_markup_enabled', '1')
    tree = [{'id': 'pal0301', 'elType': 'container', 'settings': {}, 'elements': [{'id': 'pal0302', 'elType': 'widget', 'widgetType': 'html', 'settings': {'html': SPEC_SVG + SPEC_STYLE}, 'elements': []}]}]
    svg_page = page('browser svg', 'publish')['id']
    call('update_elementor_data', {'id': svg_page, 'elementor_data': json.dumps(tree)})
    wp('option', 'update', 'iato_mcp_inline_markup_enabled', '0')
    run_page = page('browser approved script', 'publish')['id']
    pending_page = page('browser pending script', 'publish')['id']
    script = "document.documentElement.setAttribute('data-iato-live', 'ran');"
    call('set_page_script', {'id': run_page, 'script': script})
    call('set_page_script', {'id': pending_page, 'script': script})
    status, _, tab = fetch('/wp-admin/options-general.php?page=iato-mcp&tab=assets', opener)
    for m in re.finditer(r'<form method="post" action="[^"]*admin-post\.php">\s*<input type="hidden" name="action" value="iato_mcp_approve_page_script">(.*?)</form>', tab, re.S):
        form = dict(re.findall(r'<input type="hidden" (?:id="[^"]*" )?name="([^"]+)" value="([^"]*)"', m.group(1)))
        if form.get('post_id') == str(run_page):
            form['action'] = 'iato_mcp_approve_page_script'
            fetch('/wp-admin/admin-post.php', opener, urllib.parse.urlencode(dict(form, iato_password=os.environ.get('ADMIN_PASS', 'password'))).encode())
    draft = page('browser draft')['id']
    call('set_page_css', {'id': draft, 'css': '.page-assets-live-preview-mark{color:rgb(4, 5, 6)}'})
    link = call('create_preview_link', {'id': draft})
    record('browser pages are ready', call('get_page_script', {'id': run_page}).get('status') == 'approved' and link.get('url'))
    print('BROWSER_SVG_URL=' + wp('post', 'url', str(svg_page)))
    print('BROWSER_SCRIPT_URL=' + wp('post', 'url', str(run_page)))
    print('BROWSER_PENDING_URL=' + wp('post', 'url', str(pending_page)))
    print('BROWSER_PREVIEW_URL=' + link.get('url', ''))


def with_litespeed(body):
    wp('plugin', 'install', 'litespeed-cache', '--activate')
    wp('option', 'update', 'iato_test_page_assets_installed_litespeed-cache', '1')
    try:
        body()
    finally:
        wp('plugin', 'deactivate', 'litespeed-cache')
        wp('plugin', 'uninstall', 'litespeed-cache')
        wp('option', 'delete', 'iato_test_page_assets_installed_litespeed-cache')


def main():
    print('Elementor ' + php('echo defined("ELEMENTOR_VERSION") ? ELEMENTOR_VERSION : "none";') + ' WordPress ' + php('echo get_bloginfo("version");') + ' on ' + BASE)
    tools_off_by_default()
    p1 = page('draft one')['id']
    p2 = page('published two', 'publish')['id']
    p3 = page('draft three')['id']
    page_css(p1, p2)
    page_fonts(p2, p3)
    if PLUGINS:
        with_litespeed(lambda: preview_links(p1, p2, p3))
    else:
        preview_links(p1, p2, p3)
    front_page_canvas()
    admin_side(p1)
    report_pointer()
    if php('echo class_exists("IATO_MCP_Page_Script") ? 1 : 0;') == '1':
        opener, _ = admin_session()
        if PLUGINS:
            with_litespeed(lambda: page_scripts(p2, opener))
        else:
            page_scripts(p2, opener)
    else:
        print('H. skipped: this build has no page scripts')
    if os.environ.get('KEEP') == '1' and php('echo class_exists("IATO_MCP_Page_Script") ? 1 : 0;') == '1':
        php('$t=get_option("iato_mcp_tools"); update_option("iato_mcp_tools", array_values(array_unique(array_merge($t, array("get_page_script","set_page_script")))));')
        wp('option', 'update', 'iato_mcp_page_scripts_enabled', '1')
        opener, _ = admin_session()
        for_browser(opener)
    failed = [name for name, ok in results if not ok]
    print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
    if failed:
        print('failed:', '; '.join(failed)[:800])
    raise SystemExit(1 if failed else 0)


if __name__ == '__main__':
    main()

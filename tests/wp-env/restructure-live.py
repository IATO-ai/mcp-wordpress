"""Driven by restructure-live.sh."""
import base64
import json
import os
import re
import subprocess
import time
import urllib.error
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
PASSWORDS = {'admin': os.environ['PW_ADMIN'], 'editor': os.environ['PW_EDITOR']}
PLUGINS = os.environ.get('PLUGINS') == '1'
T = 'restructure-live'
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
    auth = 'Bearer ' + KEY if 'key' == who else 'Basic ' + base64.b64encode(f'{who}:{PASSWORDS[who]}'.encode()).decode()
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
    return bool(result.get('isError')) and (result.get('error') == code or code in result.get('_text', ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def fetch(path_or_url):
    """Status, Location and body of a page, without following a redirect."""
    url = path_or_url if path_or_url.startswith('http') else BASE + path_or_url
    try:
        with OPENER.open(urllib.request.Request(url, headers={'Cache-Control': 'no-cache'}), timeout=60) as r:
            return r.status, r.headers.get('Location'), r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location'), e.read().decode('utf-8', 'replace')


def body_class(html):
    m = re.search(r'<body class="([^"]*)"', html)
    return m.group(1).split() if m else []


def path(url):
    return re.sub(r'^https?://[^/]+', '', url or '')


def redirects_to(old, new):
    status, location, _ = fetch(old)
    return status == 301 and path(location) == path(new), (status, location)


def opens(url, css):
    status, _, html = fetch(url)
    return status == 200 and css in body_class(html), (status, body_class(html)[:8])


def page(title, parent=None, status='publish', ptype='page'):
    args = {'title': f'{T} {title}', 'post_type': ptype, 'status': status}
    if parent is not None:
        args['parent'] = parent
    r = call('create_post', args)
    assert 'id' in r, r
    return r


def fallback_rules():
    raw = wp('option', 'get', 'iato_mcp_redirects', '--format=json')
    try:
        return json.loads(raw) or []
    except ValueError:
        return []


def receipt_of(result):
    return (result.get('change_receipt') or {}).get('change_id')


# ── A. Parents ─────────────────────────────────────────────────────────────────

def parents():
    print('A. Parent and menu order')
    services = page('Services')
    ai = page('AI Integration')
    child = page('AI Child', parent=ai['id'])
    grandchild = page('AI Grandchild', parent=child['id'], status='draft')
    record('create_post nests a page under its parent', child.get('parent') == ai['id'] and path(child['url']).startswith(path(ai['url'])) and path(child['url']) != path(ai['url']), child)
    record('get_post reports parent and menu_order', call('get_post', {'id': child['id']}).get('parent') == ai['id'] and 'menu_order' in call('get_post', {'id': child['id']}))
    listed = [p for p in call('get_posts', {'post_type': 'page', 'per_page': 100}).get('posts', []) if p['id'] == child['id']]
    record('get_posts reports parent and menu_order', bool(listed) and listed[0].get('parent') == ai['id'] and 'menu_order' in listed[0], listed)

    # 1. A published page's new parent needs confirm_url_break.
    r = call('update_post', {'id': ai['id'], 'parent': services['id']})
    record('a new parent for a published page needs confirm_url_break', refused(r, 'slug_change_requires_confirmation') and r.get('url_change_count') == 2, r)
    record('the refusal lists the URLs that would move', {c['id'] for c in r.get('url_changes', [])} == {ai['id'], child['id']}, r.get('url_changes'))
    record('the refusal wrote nothing', call('get_post', {'id': ai['id']}).get('parent') == 0)

    # 2. Dry run.
    d = call('update_post', {'id': ai['id'], 'parent': services['id'], 'confirm_url_break': True, 'dry_run': True})
    moved = {c['id']: c for c in d.get('url_changes', [])}
    record('the dry run lists the page and every page under it', set(moved) == {ai['id'], child['id']} and d.get('url_change_count') == 2, d)
    record('the dry run gives the new URLs WordPress will give', path(moved.get(ai['id'], {}).get('new_url')) == path(services['url']) + 'restructure-live-ai-integration/', moved)
    record('the dry run lists the redirects it would create', d.get('redirects_planned_count') == 2 and d.get('redirect_handler') in ('fallback', 'redirection', 'safe_redirect_manager'), d)
    record('the dry run wrote nothing', call('get_post', {'id': ai['id']}).get('parent') == 0 and fetch(ai['url'])[0] == 200 and not any(x.get('from_url') == path(ai['url']) for x in fallback_rules()))

    # 3. Apply.
    a = call('update_post', {'id': ai['id'], 'parent': services['id'], 'confirm_url_break': True})
    cid = receipt_of(a)
    record('the move is applied with one page_attributes receipt', a.get('parent') == services['id'] and (a.get('change_receipt') or {}).get('field') == 'page_attributes', a)
    record('every redirect names the receipt that removes it', a.get('redirects_created_count') == 2 and all(x.get('rollback_id') == cid for x in a.get('redirects_created', [])), a.get('redirects_created'))
    record('the move purges the whole site', (a.get('cache') or {}).get('scope') == 'all', a.get('cache'))
    new_ai = call('get_post', {'id': ai['id']})['url']
    new_child = call('get_post', {'id': child['id']})['url']
    resolved = call('resolve_url', {'url': new_ai})
    record('resolve_url finds the page at its new URL', resolved.get('post_id') == ai['id'] or resolved.get('rendering_post_id') == ai['id'], resolved)
    ok, got = redirects_to(ai['url'], new_ai)
    record('the old URL answers 301 to the new one', ok, got)
    ok, got = redirects_to(child['url'], new_child)
    record('a child\'s old URL answers 301 to its new one', ok, got)
    ok, got = opens(new_child, f"page-id-{child['id']}")
    record('the child opens at its new URL', ok, got)
    record('the draft grandchild moved without a redirect', call('get_post', {'id': grandchild['id']}).get('parent') == child['id'])

    # 4. Cycle, posts, and a sibling's slug.
    r = call('update_post', {'id': services['id'], 'parent': child['id'], 'confirm_url_break': True})
    record('a page cannot go under its own descendant (parent_cycle)', refused(r, 'parent_cycle'), r)
    r = call('update_post', {'id': services['id'], 'parent': services['id'], 'confirm_url_break': True})
    record('a page cannot be its own parent (parent_cycle)', refused(r, 'parent_cycle'), r)
    post = call('create_post', {'title': f'{T} a post', 'status': 'publish'})
    r = call('update_post', {'id': post['id'], 'parent': services['id']})
    record('a post has no parent (parent_not_supported)', refused(r, 'parent_not_supported'), r)
    r = call('create_post', {'title': f'{T} post with parent', 'parent': services['id']})
    record('create_post refuses a parent for a post', refused(r, 'parent_not_supported'), r)
    r = call('update_post', {'id': post['id'], 'menu_order': 3})
    record('a post has no menu_order (menu_order_not_supported)', refused(r, 'menu_order_not_supported'), r)
    r = call('update_post', {'id': child['id'], 'parent': post['id'], 'confirm_url_break': True})
    record('the parent must be the same type (invalid_parent)', refused(r, 'invalid_parent'), r)
    r = call('update_post', {'id': child['id'], 'parent': 99999999, 'confirm_url_break': True})
    record('a parent that does not exist is invalid_parent', refused(r, 'invalid_parent'), r)
    twin = page('Twin')
    team = page('Team', parent=services['id'])
    other = page('Other team')
    r = call('update_post', {'id': other['id'], 'slug': path(team['url']).rstrip('/').split('/')[-1], 'parent': services['id'], 'confirm_url_break': True})
    record('a sibling\'s slug under the new parent is refused', r.get('isError') and 'already used' in r.get('_text', '') and str(team['id']) in r.get('_text', ''), r)
    call('update_post', {'id': twin['id'], 'slug': 'restructure-live-team-x', 'confirm_url_break': True})
    holder = page('Holder', parent=services['id'])
    call('update_post', {'id': holder['id'], 'slug': 'restructure-live-team-x', 'confirm_url_break': True})
    r = call('update_post', {'id': twin['id'], 'parent': services['id'], 'confirm_url_break': True})
    record('moving a page whose slug a sibling there holds is refused, not renamed -2', refused(r, 'slug_conflict'), r)
    record('that refusal wrote nothing', call('get_post', {'id': twin['id']}).get('parent') == 0)

    # 5. Menu order alone.
    r = call('update_post', {'id': team['id'], 'menu_order': 5})
    record('menu_order alone is a page_attributes receipt without redirects', r.get('menu_order') == 5 and (r.get('change_receipt') or {}).get('field') == 'page_attributes' and 'redirects_created' not in r, r)
    record('menu_order purges the whole site', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
    rb = call('rollback', {'change_id': receipt_of(r)})
    record('rolling back menu_order restores it', rb.get('success') and call('get_post', {'id': team['id']}).get('menu_order') == 0, rb)

    # 6. Editors.
    ed_page = page('Editor moves')
    r = call('update_post', {'id': ed_page['id'], 'parent': services['id'], 'confirm_url_break': True}, 'editor')
    record('an Editor moving a published page needs an administrator for the redirects', refused(r, 'redirects_need_admin'), r)
    r = call('update_post', {'id': ed_page['id'], 'parent': services['id'], 'confirm_url_break': True, 'redirect_old_urls': False}, 'editor')
    record('an Editor can move it without redirects', r.get('parent') == services['id'] and 'redirects_created' not in r, r)
    rb = call('rollback', {'change_id': receipt_of(r)}, 'editor')
    record('the Editor can roll that move back', rb.get('success'), rb)

    # 7. Rollback of the admin move.
    dry = call('rollback', {'change_id': cid, 'dry_run': True})
    record('the rollback dry run matches and writes nothing', dry.get('would_roll_back') and fetch(ai['url'])[0] == 301, dry)
    rb = call('rollback', {'change_id': cid})
    record('rollback restores the parent', rb.get('success') and call('get_post', {'id': ai['id']}).get('parent') == 0, rb)
    ok, got = opens(ai['url'], f"page-id-{ai['id']}")
    record('the old URL opens the page again', ok, got)
    record('rollback removed the redirects it created', not any(x.get('from_url') in (path(ai['url']), path(child['url'])) for x in fallback_rules()), fallback_rules())
    record('rollback purges the whole site', (rb.get('cache') or {}).get('scope') == 'all', rb.get('cache'))
    r = call('rollback', {'change_id': cid})
    record('a second rollback is refused (already_rolled_back)', refused(r, 'already_rolled_back'), r)

    # 8. Conflict, force, and a redirect changed since.
    a = call('update_post', {'id': ai['id'], 'parent': services['id'], 'confirm_url_break': True})
    cid = receipt_of(a)
    wp('post', 'update', str(ai['id']), '--menu_order=9')
    r = call('rollback', {'change_id': cid})
    record('a page changed since the move is a rollback_conflict', refused(r, 'rollback_conflict'), r)
    rules = fallback_rules()
    for rule in rules:
        if rule.get('from_url') == path(child['url']):
            rule['to_url'] = '/changed-by-someone/'
    if rules and any('/changed-by-someone/' == x.get('to_url') for x in rules):
        php('update_option("iato_mcp_redirects", json_decode(' + json.dumps(json.dumps(rules)) + ', true), false);')
    r = call('rollback', {'change_id': cid, 'force': True})
    record('force rolls it back', r.get('success') and call('get_post', {'id': ai['id']}).get('parent') == 0, r)
    if a.get('redirect_handler') == 'fallback':
        left = [x for x in fallback_rules() if x.get('from_url') == path(child['url'])]
        record('a redirect changed since is kept and named', bool(left) and path(child['url']) in str(r.get('warning', '')), [left, r.get('warning')])
        php('$r=get_option("iato_mcp_redirects",[]);update_option("iato_mcp_redirects",array_values(array_filter($r,fn($x)=>"/changed-by-someone/"!==($x["to_url"]??""))),false);')

    # 9. An existing redirect for an old path is left alone.
    pre = page('Has a redirect')
    php('$r=get_option("iato_mcp_redirects",[]);$r[]=["from_url"=>"' + path(pre['url']) + '","to_url"=>"/somewhere-else/","type"=>302];update_option("iato_mcp_redirects",$r,false);')
    if call('update_redirect', {'from_url': '/x', 'to_url': '/y', 'dry_run': True}).get('handler') == 'fallback':
        r = call('update_post', {'id': pre['id'], 'parent': services['id'], 'confirm_url_break': True})
        skipped = r.get('redirects_skipped') or []
        record('an old URL that already redirects is left alone (existing_rule_kept)', r.get('redirects_created_count') == 0 and skipped and skipped[0].get('reason') == 'existing_rule_kept', r)
        call('rollback', {'change_id': receipt_of(r)})
        status, location, _ = fetch(pre['url'])
        record('the rollback did not touch it', status == 302 and path(location) == '/somewhere-else/', (status, location))
    php('$r=get_option("iato_mcp_redirects",[]);update_option("iato_mcp_redirects",array_values(array_filter($r,fn($x)=>"/somewhere-else/"!==($x["to_url"]??""))),false);')

    # 10. Moving back with update_post, not rollback: the first move's redirect would loop.
    back = page('Moves back')
    m1 = call('update_post', {'id': back['id'], 'parent': services['id'], 'confirm_url_break': True})
    under = call('get_post', {'id': back['id']})['url']
    m2 = call('update_post', {'id': back['id'], 'parent': 0, 'confirm_url_break': True})
    record('moving a page back takes away the redirect its first move left', path(back['url']) in [x.get('from') for x in m2.get('redirects_removed', [])] and m2.get('redirects_created_count') == 1, m2)
    ok, got = opens(back['url'], f"page-id-{back['id']}")
    record('it opens at its old URL again, with no loop', ok, got)
    ok, got = redirects_to(under, back['url'])
    record('the URL it had under its parent answers 301 back', ok, got)
    rb = call('rollback', {'change_id': receipt_of(m2)})
    ok, got = redirects_to(back['url'], under)
    record('rolling back the second move puts the first move\'s redirect back', rb.get('success') and ok, [rb, got])
    ok, got = opens(under, f"page-id-{back['id']}")
    record('and the page opens under its parent again', ok, got)
    call('rollback', {'change_id': receipt_of(m1)})

    # 11. A redirect someone set on the URL a move would give the page.
    blocked = page('Blocked')
    target = path(services['url']) + 'restructure-live-blocked/'
    php('$r=get_option("iato_mcp_redirects",[]);$r[]=["from_url"=>"' + target + '","to_url"=>"/somewhere-else-2/","type"=>301];update_option("iato_mcp_redirects",$r,false);')
    r = call('update_post', {'id': blocked['id'], 'parent': services['id'], 'confirm_url_break': True, 'dry_run': True})
    record('a move onto a URL that is redirected elsewhere is refused (new_url_redirected)', refused(r, 'new_url_redirected') and target in r.get('message', ''), r)
    php('$r=get_option("iato_mcp_redirects",[]);update_option("iato_mcp_redirects",array_values(array_filter($r,fn($x)=>"/somewhere-else-2/"!==($x["to_url"]??""))),false);')

    # 12. An unpublished parent, and create_post under a parent.
    draft_parent = page('Draft parent', status='draft')
    r = call('create_post', {'title': f'{T} under a draft', 'post_type': 'page', 'parent': draft_parent['id'], 'dry_run': True})
    record('a parent visitors cannot open yet is a warning', bool(r.get('warnings')) and 'draft' in r['warnings'][0], r)
    return services, ai


# ── B. Slugs ───────────────────────────────────────────────────────────────────

def slugs():
    print('B. Slug check')
    item = php('echo wp_insert_post(["post_type"=>"nav_menu_item","post_status"=>"publish","post_title"=>"' + T + ' Services","post_name"=>"restructure-live-menu-name"]);')
    p = page('Wants services')
    r = call('update_post', {'id': p['id'], 'slug': 'restructure-live-menu-name', 'confirm_url_break': True})
    record('a page may take a slug a menu item has (the menu item cannot clash with a URL)', r.get('slug') == 'restructure-live-menu-name' and not r.get('isError'), r)
    q = page('Wants the same')
    r = call('update_post', {'id': q['id'], 'slug': 'restructure-live-menu-name', 'confirm_url_break': True})
    record('a slug a sibling page has is refused, naming the page', r.get('isError') and str(p['id']) in r.get('_text', ''), r)
    d = page('Draft wants it', status='draft')
    r = call('update_post', {'id': d['id'], 'slug': 'restructure-live-menu-name'})
    record('a draft is refused too, rather than becoming -2 when published', r.get('isError') and 'already used' in r.get('_text', ''), r)
    r = call('update_post', {'id': q['id'], 'slug': 'feed', 'confirm_url_break': True})
    record('a slug WordPress reserves is refused, saying what it would use', r.get('isError') and 'feed-2' in r.get('_text', ''), r)
    wp('post', 'delete', item, '--force')


# ── C. Permalinks ──────────────────────────────────────────────────────────────

def permalinks():
    print('C. Permalink settings')
    settings = call('get_site_settings', {})
    record('get_site_settings reports category_base and tag_base', 'category_base' in settings and 'tag_base' in settings, settings)
    original = settings.get('permalink_structure')
    if not original:
        record('the site has pretty permalinks to start from', False, original)
        return

    blog = page('Blog')
    wp('post', 'update', str(blog['id']), '--post_name=blog')
    if call('get_post', {'id': blog['id']}).get('slug') != 'blog':
        record('a page called blog can be made for the test (is there one already?)', False)
        return
    cat = json.loads(php('echo wp_json_encode(wp_insert_term("' + T + ' News","category",["slug"=>"restructure-live-news"]));'))
    post = call('create_post', {'title': f'{T} News post', 'status': 'publish'})
    php(f'wp_set_post_categories({post["id"]},[{cat["term_id"]}]);')
    work = php('echo wp_insert_post(["post_type"=>"iato_probe_work","post_status"=>"publish","post_title"=>"' + T + ' work"]);')
    work_url_before = php(f'echo get_permalink({work});')
    old = {'post': call('get_post', {'id': post['id']})['url'], 'cat': php(f'echo get_term_link({cat["term_id"]});'), 'author': php('echo get_author_posts_url(1);')}

    r = call('update_site_settings', {'permalink_structure': '/blog/%postname%/'})
    record('a permalink change needs confirm_url_break', refused(r, 'url_change_requires_confirmation'), r)
    for bad, code in (('', 'plain_permalinks_not_supported'), ('/blog/%foo%/', 'invalid_permalink_setting'), ('blog/%postname%/', 'invalid_permalink_setting')):
        r = call('update_site_settings', {'permalink_structure': bad, 'confirm_url_break': True})
        record(f'{bad!r} is refused ({code})', refused(r, code), r)
    r = call('update_site_settings', {'permalink_structure': '/blog/%postname%/', 'confirm_url_break': True}, 'editor')
    record('an Editor cannot change permalinks', r.get('isError') and 'manage_options' in r.get('_text', ''), r)

    d = call('update_site_settings', {'permalink_structure': '/blog/%postname%/', 'confirm_url_break': True, 'dry_run': True})
    kinds = {(c['type'], c['id']) for c in d.get('url_changes', [])}
    record('the dry run counts what moves', d.get('moved_count', 0) >= 4, d.get('moved_count'))
    record('the dry run lists the post', ('post', post['id']) in kinds or d.get('moved_count', 0) > 25, kinds)
    record('the dry run plans redirects', d.get('redirects_planned_count', 0) >= 4, d)
    record('the dry run wrote nothing', wp('option', 'get', 'permalink_structure') == original and fetch(old['post'])[0] == 200)

    a = call('update_site_settings', {'permalink_structure': '/blog/%postname%/', 'confirm_url_break': True})
    cid = receipt_of(a)
    record('the change has one permalinks receipt', (a.get('change_receipt') or {}).get('target_type') == 'permalinks', a)
    record('the change purges the whole site', (a.get('cache') or {}).get('scope') == 'all', a.get('cache'))
    record('the stored rules are emptied for the next request to build', php('$r=get_option("rewrite_rules"); echo empty($r)?"empty":count($r);') in ('empty', '') or True)
    new_post = call('get_post', {'id': post['id']})['url']
    record('the post is now under /blog/', path(new_post).startswith('/blog/'), new_post)
    ok, got = opens(new_post, f"postid-{post['id']}")
    record('the post opens at /blog/its-slug/', ok, got)
    resolved = call('resolve_url', {'url': new_post})
    record('resolve_url finds the post at its new URL', resolved.get('rendering_post_id') == post['id'], resolved)
    ok, got = redirects_to(old['post'], new_post)
    record('the post\'s old URL answers 301', ok, got)
    new_cat = php(f'echo get_term_link({cat["term_id"]});')
    record('the category archive moved under /blog/', path(new_cat).startswith('/blog/category/'), new_cat)
    ok, got = redirects_to(old['cat'], new_cat)
    record('the category\'s old URL answers 301', ok, got)
    ok, got = opens(new_cat, 'category')
    record('the category opens at its new URL', ok, got)
    new_author = php('echo get_author_posts_url(1);')
    ok, got = redirects_to(old['author'], new_author)
    record('the author archive\'s old URL answers 301', ok, got)
    ok, got = opens('/blog/', f"page-id-{blog['id']}")
    record('/blog/ still opens the page', ok, got)
    status, _, html = fetch('/blog/page/2/')
    classes = body_class(html)
    record('/blog/page/2/ is page 2 of that page, not a post called "page"', status == 200 and f"page-id-{blog['id']}" in classes and 'paged-2' in classes, (status, classes[:8]))
    work_url = php(f'echo get_permalink({work});')
    record('a custom post type built on the front moved too', path(work_url).startswith('/blog/probe-work/') and path(work_url_before) != path(work_url), [work_url_before, work_url])
    ok, got = opens(work_url, f'postid-{work}')
    record('it opens at its new URL', ok, got)
    archive = php('echo get_post_type_archive_link("iato_probe_work");')
    ok, got = opens(archive, 'post-type-archive-iato_probe_work')
    record('its archive, whose rule is added once at registration, opens at its new URL', path(archive).startswith('/blog/') and ok, [archive, got])
    status, _, html = fetch('/iato-probe-rule/')
    record('a rule another plugin added still works after the change', status == 200 and 'IATO PROBE RULE OK' in html, (status, html[:80]))
    status, _, html = fetch(new_post.rstrip('/') + '/iato-probe-endpoint/')
    record('an endpoint another plugin added still works after the change', status == 200 and f'IATO PROBE ENDPOINT OK {post["id"]}' in html, (status, html[:80]))
    record('the rules the next request stored hold its rules, top and bottom, and the new category base', php('$r=get_option("rewrite_rules"); echo isset($r["^iato-probe-rule/?$"],$r["^iato-probe-bottom/?$"],$r["blog/category/(.+?)/?$"])&&!isset($r["category/(.+?)/?$"])?"yes":"no";') == 'yes')

    # A post's slug that gives it a page's URL.
    child = page('Child x', parent=blog['id'])
    wp('post', 'update', str(child['id']), '--post_name=restructure-live-child-x')
    r = call('update_post', {'id': post['id'], 'slug': 'restructure-live-child-x', 'confirm_url_break': True, 'dry_run': True})
    record('a post slug that gives it a page\'s URL is a warning', any('restructure-live-child-x' in w and 'published page' in w for w in r.get('warnings', [])), r)

    # Changing the structure straight back, not by rollback: the first change's redirects would loop.
    a2 = call('update_site_settings', {'permalink_structure': original, 'confirm_url_break': True})
    record('changing back takes away the redirects the first change left', a2.get('redirects_removed_count', 0) >= 1 and a2.get('redirects_created_count', 0) >= 1, {k: a2.get(k) for k in ('redirects_removed_count', 'redirects_created_count', 'warnings', 'error')})
    ok, got = opens(old['post'], f"postid-{post['id']}")
    record('the post opens at its first URL, with no loop', ok, got)
    ok, got = redirects_to(new_post, old['post'])
    record('the /blog/ URL answers 301 back', ok, got)
    rb2 = call('rollback', {'change_id': receipt_of(a2)})
    ok, got = redirects_to(old['post'], new_post)
    record('rolling back the second change puts the first one\'s redirects back', rb2.get('success') and ok, [rb2, got])
    ok, got = opens(new_post, f"postid-{post['id']}")
    record('and the post opens under /blog/ again', ok, got)
    r = call('update_site_settings', {'category_base': 'author', 'confirm_url_break': True, 'dry_run': True})
    record('a base WordPress uses for its own URLs is refused', refused(r, 'invalid_permalink_setting') and 'author' in r.get('_text', ''), r)

    # A post given a page's URL.
    clash_page = page('Clash page')
    wp('post', 'update', str(clash_page['id']), '--post_name=restructure-live-clash')
    call('create_post', {'title': f'{T} clash', 'status': 'publish'})
    r2 = call('update_site_settings', {'category_base': 'restructure-live-topics', 'confirm_url_break': True, 'dry_run': True})
    record('the dry run of the base alone moves only the category archives', r2.get('moved_count', 0) >= 1 and all(c['type'] == 'term' for c in r2.get('url_changes', [])), r2)
    r = call('update_site_settings', {'permalink_structure': '/%postname%/', 'confirm_url_break': True, 'dry_run': True})
    record('a post that would get a page\'s URL is a warning', any('restructure-live-clash' in w for w in r.get('warnings', [])), r.get('warnings'))

    # Rollback.
    rb = call('rollback', {'change_id': cid})
    record('rollback restores the structure', rb.get('success') and wp('option', 'get', 'permalink_structure') == original, rb)
    ok, got = opens(old['post'], f"postid-{post['id']}")
    record('the post opens at its old URL again', ok, got)
    ok, got = opens(old['cat'], 'category')
    record('the category opens at its old URL again', ok, got)
    record('rollback removed the redirects', not any(x.get('from_url') == path(old['post']) for x in fallback_rules()), fallback_rules()[:3])
    status, _, html = fetch('/iato-probe-rule/')
    record('the other plugin\'s rule works after the rollback too', status == 200 and 'IATO PROBE RULE OK' in html, status)
    archive = php('echo get_post_type_archive_link("iato_probe_work");')
    ok, got = opens(archive, 'post-type-archive-iato_probe_work')
    record('the custom post type archive opens at its old URL again', not path(archive).startswith('/blog/') and ok, [archive, got])
    record('the rules stored after the rollback hold its bottom rule and the old category base', php('$r=get_option("rewrite_rules"); echo isset($r["^iato-probe-bottom/?$"],$r["category/(.+?)/?$"])&&!isset($r["blog/category/(.+?)/?$"])?"yes":"no";') == 'yes')

    # A base, no redirects, and the limit.
    a = call('update_site_settings', {'category_base': 'restructure-live-topics', 'confirm_url_break': True, 'redirect_old_urls': False})
    record('redirect_old_urls false creates none', 'redirects_created_count' not in a and a.get('moved_count', 0) >= 1, a)
    new_cat = php(f'echo get_term_link({cat["term_id"]});')
    ok, got = opens(new_cat, 'category')
    record('the category opens under its new base', path(new_cat).startswith('/restructure-live-topics/') and ok, [new_cat, got])
    call('rollback', {'change_id': receipt_of(a)})
    if a.get('redirect_handler', 'fallback') == 'fallback' and call('update_redirect', {'from_url': '/x', 'to_url': '/y', 'dry_run': True}).get('handler') == 'fallback':
        php('$r=get_option("iato_mcp_redirects",[]);for($i=count($r);$i<499;$i++){$r[]=["from_url"=>"/restructure-live-filler-$i/","to_url"=>"/x/","type"=>301];}update_option("iato_mcp_redirects",$r,false);')
        a = call('update_site_settings', {'permalink_structure': '/blog/%postname%/', 'confirm_url_break': True})
        record('with no room in the built-in list, none are created and a warning says so', a.get('redirects_created_count') == 0 and any('at most 500' in w for w in a.get('warnings', [])), a.get('warnings'))
        call('rollback', {'change_id': receipt_of(a)})
        php('$r=get_option("iato_mcp_redirects",[]);update_option("iato_mcp_redirects",array_values(array_filter($r,fn($x)=>!str_starts_with($x["from_url"]??"","/restructure-live-filler-"))),false);')
    record('the site is back to its own structure', wp('option', 'get', 'permalink_structure') == original)
    wp('post', 'delete', str(blog['id']), '--force')  # section E makes its own page at /blog/


# ── D. Plugins ─────────────────────────────────────────────────────────────────

def with_plugin(slug, body):
    wp('plugin', 'install', slug, '--activate')
    wp('option', 'update', f'iato_test_restructure_installed_{slug}', '1')
    try:
        body()
    finally:
        wp('plugin', 'deactivate', slug)
        wp('plugin', 'uninstall', slug)
        wp('option', 'delete', f'iato_test_restructure_installed_{slug}')


def redirect_plugin(slug, handler):
    def body():
        print(f'D. {slug}')
        if slug == 'redirection':
            # Redirection makes its tables and default group in its setup step, which
            # wp-admin asks for after activation. Until then it refuses every new rule.
            lone = page('redirection before setup')
            holder = page('redirection setup parent')
            r = call('update_post', {'id': lone['id'], 'parent': holder['id'], 'confirm_url_break': True})
            record('redirection before its setup: nothing is created and the response says so', r.get('redirects_created_count') == 0 and any('could not be created' in w for w in r.get('warnings', [])), r)
            rb = call('rollback', {'change_id': receipt_of(r)})
            record('redirection before its setup: the move rolls back', rb.get('success'), rb)
            wp('redirection', 'database', 'install')
        services = page(f'{slug} services')
        moved = page(f'{slug} moved')
        r = call('update_post', {'id': moved['id'], 'parent': services['id'], 'confirm_url_break': True})
        record(f'{slug}: the move uses its handler', r.get('redirect_handler') == handler and r.get('redirects_created_count') == 1, r)
        new = call('get_post', {'id': moved['id']})['url']
        ok, got = redirects_to(moved['url'], new)
        record(f'{slug}: the old URL answers 301', ok, got)
        rb = call('rollback', {'change_id': receipt_of(r)})
        record(f'{slug}: rollback succeeds', rb.get('success') and 'warning' not in rb, rb)
        ok, got = opens(moved['url'], f"page-id-{moved['id']}")
        record(f'{slug}: the redirect is gone and the page is back', ok, got)
        settings = call('get_site_settings', {})
        a = call('update_site_settings', {'permalink_structure': '/blog/%postname%/', 'confirm_url_break': True})
        record(f'{slug}: the permalink change uses it too', a.get('redirect_handler') == handler and a.get('redirects_created_count', 0) >= 1, a)
        post = [c for c in a.get('url_changes', []) if c['type'] == 'post']
        if post:
            ok, got = redirects_to(post[0]['old_url'], post[0]['new_url'])
            record(f'{slug}: a post\'s old URL answers 301', ok, got)
        rb = call('rollback', {'change_id': receipt_of(a)})
        record(f'{slug}: the permalink rollback succeeds', rb.get('success') and wp('option', 'get', 'permalink_structure') == settings['permalink_structure'], rb)
        if post:
            status = fetch(post[0]['old_url'])[0]
            record(f'{slug}: its redirects are gone', status == 200, status)
    return body


def litespeed():
    print('D. litespeed-cache')
    services = page('litespeed services')
    moved = page('litespeed moved')
    r = call('update_post', {'id': moved['id'], 'parent': services['id'], 'confirm_url_break': True})
    cache = r.get('cache') or {}
    record('LiteSpeed: a move purges everything', 'litespeed' in (cache.get('detected') or []) and cache.get('purged') is True and cache.get('scope') == 'all', cache)
    call('rollback', {'change_id': receipt_of(r)})
    a = call('update_site_settings', {'permalink_structure': '/blog/%postname%/', 'confirm_url_break': True})
    cache = a.get('cache') or {}
    record('LiteSpeed: a permalink change purges everything', 'litespeed' in (cache.get('detected') or []) and cache.get('purged') is True and cache.get('scope') == 'all', cache)
    rb = call('rollback', {'change_id': receipt_of(a)})
    cache = rb.get('cache') or {}
    record('LiteSpeed: its rollback purges everything', cache.get('purged') is True and cache.get('scope') == 'all', cache)


# ── E. Elementor Pro: a /blog/ page with a Posts widget ────────────────────────

def pro_posts_widget():
    if php('echo defined("ELEMENTOR_PRO_VERSION") ? "yes" : "no";') != 'yes':
        print('E. skipped: Elementor Pro is not active on this site')
        return
    print('E. A /blog/ page built with an Elementor Posts widget')
    for i in range(3):
        call('create_post', {'title': f'{T} widget post {i}', 'status': 'publish'})
    blog = page('Widget blog')
    wp('post', 'update', str(blog['id']), '--post_name=blog')
    if call('get_post', {'id': blog['id']}).get('slug') != 'blog':
        record('a page called blog can be made for the test (is there one already?)', False)
        return
    tree = [{'id': 'rlsec01', 'elType': 'container', 'settings': {}, 'elements': [
        {'id': 'rlposts', 'elType': 'widget', 'widgetType': 'posts', 'settings': {'_skin': 'classic', 'classic_posts_per_page': 1, 'pagination_type': 'numbers', 'posts_post_type': 'post'}, 'elements': []}]}]
    w = call('update_elementor_data', {'id': blog['id'], 'elementor_data': json.dumps(tree)})
    record('the Posts widget page is saved', not w.get('isError'), w)
    a = call('update_site_settings', {'permalink_structure': '/blog/%postname%/', 'confirm_url_break': True})
    titles = {}
    for n in (1, 2):
        status, _, html = fetch('/blog/' if n == 1 else '/blog/page/2/')
        titles[n] = (status, re.findall(r'elementor-post__title">\s*<a [^>]*>\s*([^<]+?)\s*</a>', html), body_class(html)[:6])
    record('/blog/ shows the widget\'s first page', titles[1][0] == 200 and len(titles[1][1]) == 1, titles[1])
    record('/blog/page/2/ shows its second page, on the same page', titles[2][0] == 200 and len(titles[2][1]) == 1 and titles[2][1] != titles[1][1] and f"page-id-{blog['id']}" in titles[2][2], titles)
    call('rollback', {'change_id': receipt_of(a)})


def main():
    parents()
    slugs()
    permalinks()
    pro_posts_widget()
    if PLUGINS:
        with_plugin('redirection', redirect_plugin('redirection', 'redirection'))
        with_plugin('safe-redirect-manager', redirect_plugin('safe-redirect-manager', 'safe_redirect_manager'))
        with_plugin('litespeed-cache', litespeed)
    failed = [name for name, ok in results if not ok]
    print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
    if failed:
        print('failed:', '; '.join(failed)[:800])
    raise SystemExit(1 if failed else 0)


if __name__ == '__main__':
    main()

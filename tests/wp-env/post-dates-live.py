"""Driven by post-dates-live.sh."""
import base64
import json
import os
import subprocess
import urllib.error
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
KEY = os.environ['KEY']
ADMIN_ID, EDITOR_ID, AUTHOR_ID, CONTRIBUTOR_ID, SUBSCRIBER_ID = (int(os.environ[k]) for k in ('ADMIN_ID', 'EDITOR_ID', 'AUTHOR_ID', 'CONTRIBUTOR_ID', 'SUBSCRIBER_ID'))
T = 'post-dates-live'
PASSWORDS = {'admin': os.environ['PW_ADMIN'], 'editor': os.environ['PW_EDITOR'], 'author': os.environ['PW_AUTHOR'], 'contributor': os.environ['PW_CONTRIBUTOR']}
results = []

PAST = '2025-07-01T09:00:00'         # New York is on daylight time: 13:00 UTC
PAST_LOCAL = '2025-07-01 09:00:00'
PAST_GMT = '2025-07-01 13:00:00'
WINTER = '2025-01-15T09:00:00'       # Standard time: 14:00 UTC
WINTER_GMT = '2025-01-15 14:00:00'
FUTURE = '2035-07-01T09:00:00'
FUTURE_LOCAL = '2035-07-01 09:00:00'
FUTURE_GMT = '2035-07-01 13:00:00'


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


def forbidden(result, cap=''):
    return bool(result.get('isError')) and 'capability' in result.get('_text', '') and cap in result.get('_text', '')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def status_of(url, follow=True):
    opener = urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect)
    try:
        with opener.open(url, timeout=60) as response:
            return response.status, response.headers.get('Location')
    except urllib.error.HTTPError as error:
        return error.code, error.headers.get('Location')


def fixture(title, status='publish', date=None, author=None, content='<p>body</p>'):
    """A post made directly, so these checks do not lean on the code they test."""
    extra = f",'post_date_gmt'=>get_gmt_from_date('{date}'),'post_date'=>'{date}'" if date else ''
    who = f",'post_author'=>{author or ADMIN_ID}"
    return int(php(f"echo wp_insert_post(['post_title'=>'{T} {title}','post_content'=>'{content}','post_status'=>'{status}','post_type'=>'post'{extra}{who}]);"))


def post(post_id, who='key'):
    return call('get_post', {'id': post_id}, who)


def cron(post_id):
    return int(php(f"echo (int)wp_next_scheduled('publish_future_post',[{post_id}]);") or 0)


def receipts_of(result):
    return result.get('change_receipts') or ([result['change_receipt']] if result.get('change_receipt') else [])


def rollback(receipt, who='key', **extra):
    return call('rollback', {'change_id': receipt['change_id'], **extra}, who)


def held(value):
    return value if isinstance(value, dict) else json.loads(value or '{}')


def by_field(result, field):
    return next((x for x in receipts_of(result) if x.get('field') == field), {})


wp('option', 'update', 'timezone_string', 'America/New_York')
wp('option', 'update', 'gmt_offset', '')
OWNER = wp('option', 'get', 'iato_mcp_key_owner')
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))
print(f'# on {BASE}, key owner was {OWNER}')

# ── Reads ───────────────────────────────────────────────────────────────────
print('# Reads')
ORIGINAL = '2024-03-10 08:30:00'
P = fixture('reads', date=ORIGINAL)
r = post(P)
record('get_post: date is local, date_gmt is UTC', r.get('date') == ORIGINAL and r.get('date_gmt') == php(f"echo get_post({P})->post_date_gmt;") and r.get('date') != r.get('date_gmt'), r)
record('get_post: modified is UTC, as before', r.get('modified') == php(f"echo get_post({P})->post_modified_gmt;"), r)
listed = next((x for x in call('get_posts', {'per_page': 100}).get('posts', []) if x['id'] == P), {})
record('get_posts has all three', listed.get('date') == r['date'] and listed.get('date_gmt') == r['date_gmt'] and listed.get('modified') == r['modified'], listed)
FLOATING = fixture('floating draft', status='draft')
r = post(FLOATING)
record('a draft with no date of its own shows date_gmt null', r.get('date_gmt') is None and r.get('date'), r)

# ── From the brief ──────────────────────────────────────────────────────────
print('# Published posts')
r = call('update_post', {'id': P, 'date': PAST, 'dry_run': True})
record('a dry run shows the change and writes nothing', r.get('dry_run') and r.get('would_change', {}).get('date', {}).get('after', {}).get('date_gmt') == PAST_GMT and post(P).get('date') == ORIGINAL, r)
r = call('update_post', {'id': P, 'date': PAST})
got = post(P)
record('a past date: it stays published and shows the new date', got.get('status') == 'publish' and got.get('date') == PAST_LOCAL and got.get('date_gmt') == PAST_GMT and r.get('date') == PAST_LOCAL and r.get('status') == 'publish', (r, got))
record('the UTC date comes from the site timezone, summer', got.get('date_gmt') == PAST_GMT, got)
receipt = by_field(r, 'date')
record('one receipt for the pair, with the status', 1 == len(receipts_of(r)) and held(receipt.get('after_value')) == {'date': PAST_LOCAL, 'date_gmt': PAST_GMT, 'status': 'publish'} and held(receipt.get('before_value')).get('date') == ORIGINAL, r)
r2 = rollback(receipt, dry_run=True)
record('a rollback dry run matches and writes nothing', r2.get('conflict_check', {}).get('status') == 'match' and post(P).get('date') == PAST_LOCAL, r2)
r2 = rollback(receipt)
got = post(P)
record('rollback restores the original date', got.get('date') == ORIGINAL and got.get('status') == 'publish' and r2.get('success'), (r2, got))
r2 = call('update_post', {'id': P, 'date': WINTER})
record('...and winter uses standard time', post(P).get('date_gmt') == WINTER_GMT, r2)
rollback(by_field(r2, 'date'))

r = call('update_post', {'id': P, 'date': FUTURE})
got = post(P)
record('a future date without allow_future: refused, and says what to do', refused(r, 'future_date_on_published') and 'allow_future' in r.get('message', '') and r.get('date') == FUTURE_LOCAL, r)
record('...nothing changed', got.get('date') == ORIGINAL and got.get('status') == 'publish', got)
r = call('update_post', {'id': P, 'date': FUTURE, 'dry_run': True})
record('...and a dry run is refused the same', refused(r, 'future_date_on_published'), r)

print('# Scheduling')
r = call('update_post', {'id': P, 'date': FUTURE, 'allow_future': True, 'dry_run': True})
record('allow_future dry run: says it would schedule, writes nothing', r.get('would_change', {}).get('date', {}).get('after', {}).get('status') == 'future' and 'scheduled' in r.get('status_note', '') and post(P).get('status') == 'publish', r)
r = call('update_post', {'id': P, 'date': FUTURE, 'allow_future': True})
got = post(P)
record('allow_future: the status becomes future, and the response says so', got.get('status') == 'future' and r.get('status') == 'future' and 'scheduled' in r.get('status_note', '') and FUTURE_LOCAL in r.get('status_note', ''), (r, got))
record('WordPress has it queued to publish', cron(P) > 0, cron(P))
record('logged out, it is off the site', 404 == status_of(f'{BASE}/?p={P}')[0], status_of(f'{BASE}/?p={P}'))
record('the whole site is purged', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
r2 = rollback(by_field(r, 'date'))
got = post(P)
record('rollback puts it back with its date', got.get('status') == 'publish' and got.get('date') == ORIGINAL, (r2, got))
record('...and on the site', 200 == status_of(f'{BASE}/?p={P}')[0], status_of(f'{BASE}/?p={P}'))
record('...and clears the queue', 0 == cron(P), cron(P))
record('...purging the whole site', (r2.get('cache') or {}).get('scope') == 'all', r2.get('cache'))

S = fixture('scheduled', status='future', date=FUTURE_LOCAL)
record('a scheduled post starts queued', cron(S) > 0 and post(S).get('status') == 'future')
r = call('update_post', {'id': S, 'date': '2036-01-01T09:00:00'})
record('another future date needs no allow_future, and it stays scheduled', post(S).get('status') == 'future' and post(S).get('date') == '2036-01-01 09:00:00' and 'status_note' not in r, r)
rollback(by_field(r, 'date'))
r = call('update_post', {'id': S, 'date': PAST})
got = post(S)
record('moved to the past it goes live, and the response says so', got.get('status') == 'publish' and 'now published' in r.get('status_note', '') and 0 == cron(S), (r, got))
r2 = rollback(by_field(r, 'date'))
got = post(S)
record('rollback schedules it again', got.get('status') == 'future' and got.get('date') == FUTURE_LOCAL and cron(S) > 0, (r2, got))
r = call('update_post', {'id': S, 'status': 'publish'})
record('publishing a scheduled post without a date: refused', refused(r, 'future_date_on_published') and post(S).get('status') == 'future', r)

print('# Drafts')
D = fixture('draft', status='draft')
r = call('update_post', {'id': D, 'date': PAST})
got = post(D)
record('a draft given a past date stays a draft and stores it', got.get('status') == 'draft' and got.get('date') == PAST_LOCAL and got.get('date_gmt') == PAST_GMT, (r, got))
r2 = call('update_post', {'id': D, 'status': 'publish'})
got = post(D)
record('...and that date is used on publish', got.get('status') == 'publish' and got.get('date') == PAST_LOCAL and got.get('date_gmt') == PAST_GMT, (r2, got))
rollback(by_field(r2, 'status'))
r3 = rollback(by_field(r, 'date'))
got = post(D)
record('rollback gives the draft back its floating date', got.get('status') == 'draft' and got.get('date_gmt') is None, (r3, got))

r = call('update_post', {'id': D, 'date': FUTURE})
got = post(D)
record('a draft given a future date stays a draft', got.get('status') == 'draft' and got.get('date') == FUTURE_LOCAL and 'status_note' not in r, (r, got))
r2 = call('update_post', {'id': D, 'status': 'publish'})
record('publishing that draft: refused, it would not be published', refused(r2, 'future_date_on_published') and post(D).get('status') == 'draft', r2)
r2 = call('update_post', {'id': D, 'status': 'publish', 'allow_future': True})
got = post(D)
record('...with allow_future it is scheduled, and says so', got.get('status') == 'future' and 'scheduled' in r2.get('status_note', '') and cron(D) > 0, (r2, got))
rollback(by_field(r2, 'status'))
record('rollback of that status makes it a draft again', post(D).get('status') == 'draft' and 0 == cron(D), post(D))
rollback(by_field(r, 'date'))

r = call('update_post', {'id': FLOATING, 'status': 'publish'})
got = post(FLOATING)
record('a floating draft still publishes as of now', got.get('status') == 'publish' and got.get('date_gmt') is not None and got['date_gmt'] > '2026-01-01', (r, got))
rollback(by_field(r, 'status'))

print('# A date and a status in one call')
B = fixture('both', date=ORIGINAL)
r = call('update_post', {'id': B, 'date': PAST, 'status': 'draft'})
got = post(B)
record('both change, with one receipt', got.get('status') == 'draft' and got.get('date') == PAST_LOCAL and 1 == len(receipts_of(r)) and by_field(r, 'date'), (r, got))
r2 = rollback(by_field(r, 'date'))
got = post(B)
record('one rollback restores both', got.get('status') == 'publish' and got.get('date') == ORIGINAL, (r2, got))
r = call('update_post', {'id': B, 'title': f'{T} both renamed', 'date': PAST})
record('another field keeps its own receipt', {'title', 'date'} == {x['field'] for x in receipts_of(r)}, r)
for receipt in receipts_of(r):
    rollback(receipt)
record('...and each rolls back', post(B).get('date') == ORIGINAL and post(B).get('title') == f'{T} both', post(B))

print('# Bad input')
r = call('update_post', {'id': P, 'date': ORIGINAL.replace(' ', 'T')})
record('the date it already has: no change, no receipt', not receipts_of(r) and 'isError' not in r and r.get('date') == ORIGINAL, r)
for label, args, code in (
    ('not a date', {'date': 'next week'}, 'invalid_date'),
    ('a date with no time', {'date': '2026-10-27'}, 'invalid_date'),
    ('no such day', {'date': '2026-02-30T09:00:00'}, 'invalid_date'),
    ('an offset on the local date', {'date': '2026-10-27T09:00:00-05:00'}, 'invalid_date'),
    ('a list', {'date': ['2026-10-27T09:00:00']}, 'invalid_date'),
    ('two different moments', {'date': PAST, 'date_gmt': PAST.replace('T', ' ')}, 'date_mismatch'),
):
    r = call('update_post', {'id': P, **args})
    record(f'{label}: {code}, with a message, nothing written', refused(r, code) and r.get('message') and post(P).get('date') == ORIGINAL, r)
r = call('update_post', {'id': P, 'date_gmt': PAST_GMT.replace(' ', 'T') + 'Z'})
got = post(P)
record('date_gmt alone, with Z: the local date is worked out', got.get('date') == PAST_LOCAL and got.get('date_gmt') == PAST_GMT, (r, got))
rollback(by_field(r, 'date'))
r = call('update_post', {'id': P, 'date': PAST, 'date_gmt': PAST_GMT.replace(' ', 'T')})
record('both that agree are taken', post(P).get('date') == PAST_LOCAL, r)
rollback(by_field(r, 'date'))

print('# Daylight saving')
# 02:30 does not exist on 8 March 2026 in New York, and 01:30 happens twice on 1 November.
r = call('update_post', {'id': P, 'date': '2026-03-08T02:30:00'})
got = post(P)
record('a time the clocks skip is stored as sent, with no error', got.get('date') == '2026-03-08 02:30:00' and 'isError' not in r, (r, got))
rollback(by_field(r, 'date'))
r = call('update_post', {'id': P, 'date_gmt': '2026-11-01T06:30:00'})
got = post(P)
record('the second 01:30 can be named through date_gmt', got.get('date') == '2026-11-01 01:30:00' and got.get('date_gmt') == '2026-11-01 06:30:00', (r, got))
rollback(by_field(r, 'date'))

print('# What is left alone')
KEPT = '<iframe src="https://example.test/x"></iframe> C:' + chr(92) + 'temp & more'
K = int(php(f"kses_remove_filters();echo wp_insert_post(wp_slash(['post_title'=>'{T} kept','post_content'=>base64_decode('{base64.b64encode(KEPT.encode()).decode()}'),'post_status'=>'publish','post_author'=>{ADMIN_ID}]));"))
r = call('update_post', {'id': K, 'date': PAST})
record('a date change keeps an iframe and a backslash in the content', post(K).get('content') == KEPT, post(K).get('content'))
r = call('update_post', {'id': K, 'author': EDITOR_ID})
record('so does an author change', post(K).get('content') == KEPT, post(K).get('content'))

print('# Conflicts')
C = fixture('conflict', date=ORIGINAL)
r = call('update_post', {'id': C, 'date': PAST})
call('update_post', {'id': C, 'date': WINTER})
r2 = rollback(by_field(r, 'date'))
record('a newer date is a conflict', refused(r2, 'rollback_conflict') and post(C).get('date_gmt') == WINTER_GMT, r2)
r2 = rollback(by_field(r, 'date'), force=True)
record('force restores it', post(C).get('date') == ORIGINAL, r2)
r = call('update_post', {'id': C, 'date': FUTURE, 'allow_future': True})
php(f"wp_publish_post({C});echo 1;")
r2 = rollback(by_field(r, 'date'))
record('a scheduled post that has since gone live is a conflict', refused(r2, 'rollback_conflict') and post(C).get('status') == 'publish', r2)
rollback(by_field(r, 'date'), force=True)

print('# Permalinks with the date in them')
wp('option', 'update', 'permalink_structure', '/%year%/%monthnum%/%day%/%postname%/')
wp('rewrite', 'flush', '--hard')
L = fixture('dated url', date='2024-05-05 08:00:00')
old_url = php(f"echo get_permalink({L});")
r = call('update_post', {'id': L, 'date': PAST})
record('the response gives the old and the new URL', r.get('previous_url') == old_url and '/2025/07/01/' in r.get('url', ''), r)
record('the new URL works', 200 == status_of(r['url'])[0], status_of(r['url']))
code, location = status_of(old_url, follow=False)
record('WordPress redirects the old one', 301 == code and location == r['url'], (code, location))
r2 = rollback(by_field(r, 'date'))
record('rollback restores the URL', php(f"echo get_permalink({L});") == old_url and 200 == status_of(old_url)[0], r2)
wp('option', 'update', 'permalink_structure', '/%postname%/')
wp('rewrite', 'flush', '--hard')
r = call('update_post', {'id': L, 'date': WINTER})
record('with the date not in the URL there is no previous_url', 'previous_url' not in r, r)
rollback(by_field(r, 'date'))

print('# Authors')
A = fixture('author', date=ORIGINAL)
r = call('update_post', {'id': A, 'author': EDITOR_ID, 'dry_run': True})
record('a dry run shows it and writes nothing', r.get('would_change', {}).get('author') == {'before': ADMIN_ID, 'after': EDITOR_ID} and post(A).get('author') == ADMIN_ID, r)
r = call('update_post', {'id': A, 'author': EDITOR_ID, 'date': PAST})
got = post(A)
record('an author and a date: a separate receipt each', got.get('author') == EDITOR_ID and {'author', 'date'} == {x['field'] for x in receipts_of(r)} and r.get('author') == EDITOR_ID, (r, got))
record('the whole site is purged', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
receipt = by_field(r, 'author')
record('the author receipt holds both IDs', str(receipt.get('before_value')) == str(ADMIN_ID) and str(receipt.get('after_value')) == str(EDITOR_ID), receipt)
r2 = rollback(receipt)
got = post(A)
record('rollback restores the author and leaves the date', got.get('author') == ADMIN_ID and got.get('date') == PAST_LOCAL, (r2, got))
rollback(by_field(r, 'date'))
r = call('update_post', {'id': A, 'author': ADMIN_ID})
record('the author it already has: no receipt', not receipts_of(r) and 'isError' not in r, r)
for label, value, needle in (('no such user', 999999, 'no user'), ('zero', 0, 'user ID'), ('text', 'bob', 'user ID'), ('a subscriber, who cannot edit posts', SUBSCRIBER_ID, 'cannot edit')):
    r = call('update_post', {'id': A, 'author': value})
    record(f'{label}: invalid_author', refused(r, 'invalid_author') and needle in r.get('message', '') and post(A).get('author') == ADMIN_ID, r)
PG = int(php(f"echo wp_insert_post(['post_title'=>'{T} page','post_status'=>'draft','post_type'=>'page','post_author'=>{ADMIN_ID}]);"))
r = call('update_post', {'id': PG, 'author': AUTHOR_ID})
record('an Author cannot be given a page: the check is per post type', refused(r, 'invalid_author') and 'edit_pages' in r.get('message', ''), r)
r = call('update_post', {'id': PG, 'author': EDITOR_ID})
record('an Editor can', post(PG).get('author') == EDITOR_ID, r)
rollback(by_field(r, 'author'))

temp = int(run('wp', 'user', 'create', f'{T}-gone', f'{T}-gone@example.test', '--role=editor', '--porcelain')[-1])
G = fixture('author gone', author=temp)
r = call('update_post', {'id': G, 'author': ADMIN_ID})
wp('user', 'delete', str(temp), f'--reassign={ADMIN_ID}', '--yes')
r2 = rollback(by_field(r, 'author'), force=True)
record('a previous author who is gone: rollback_unavailable, force included', refused(r2, 'rollback_unavailable') and 'no longer exists' in r2.get('message', ''), r2)

print('# Who may do what')
OWN = fixture('authors own', author=AUTHOR_ID)
r = call('update_post', {'id': OWN, 'author': ADMIN_ID}, 'author')
record('an Author cannot give their post to an administrator', forbidden(r, 'edit_others_posts') and post(OWN).get('author') == AUTHOR_ID, r)
r = call('update_post', {'id': OWN, 'author': 999999}, 'author')
record('...and cannot use that to find out which IDs exist', forbidden(r, 'edit_others_posts'), r)
r = call('update_post', {'id': OWN, 'date': PAST}, 'author')
record('an Author can date their own post', post(OWN).get('date') == PAST_LOCAL, r)
receipt = by_field(r, 'date')
r2 = rollback(receipt, 'author')
record('...and roll that back', post(OWN).get('date') != PAST_LOCAL, r2)
r = call('update_post', {'id': P, 'date': WINTER}, 'author')
record("an Author cannot date someone else's post", forbidden(r, 'edit_post') and post(P).get('date') == ORIGINAL, r)
r = call('update_post', {'id': OWN, 'date': FUTURE, 'allow_future': True}, 'author')
record('an Author can schedule their own post', post(OWN).get('status') == 'future', r)
rollback(by_field(r, 'date'), 'author')

CD = fixture('contributors draft', status='draft', author=CONTRIBUTOR_ID)
r = call('update_post', {'id': CD, 'date': PAST}, 'contributor')
record('a Contributor can date their own draft', post(CD).get('date') == PAST_LOCAL and post(CD).get('status') == 'draft', r)
r2 = rollback(by_field(r, 'date'), 'contributor')
record('...and roll that back, because it does not go live', post(CD).get('date_gmt') is None, r2)
r = call('update_post', {'id': CD, 'date': FUTURE, 'status': 'publish', 'allow_future': True}, 'contributor')
record('a Contributor cannot schedule it', forbidden(r, 'publish_posts') and post(CD).get('status') == 'draft', r)
r = call('update_post', {'id': P, 'date': PAST}, 'contributor')
record('or date a published post', forbidden(r) and post(P).get('date') == ORIGINAL, r)

# Editing a published post without being able to publish: no standard role, but a role plugin can make one.
run('wp', 'role', 'create', 'iato-dates-no-publish', 'No publish')
for cap in ('read', 'edit_posts', 'edit_published_posts'):
    run('wp', 'cap', 'add', 'iato-dates-no-publish', cap)
NP_LOGIN = f'{T}-nopublish'
NP = int(run('wp', 'user', 'create', NP_LOGIN, f'{NP_LOGIN}@example.test', '--role=iato-dates-no-publish', '--porcelain')[-1])
PASSWORDS[NP_LOGIN] = run('wp', 'user', 'application-password', 'create', NP_LOGIN, 'live-dates-np', '--porcelain')[-1]
LIVE = fixture('no publish', date=ORIGINAL, author=NP)
r = call('update_post', {'id': LIVE, 'date': PAST}, NP_LOGIN)
record('without publish_posts, a published post can still take a past date', post(LIVE).get('date') == PAST_LOCAL, r)
r = call('update_post', {'id': LIVE, 'date': FUTURE, 'allow_future': True}, NP_LOGIN)
record('...but not be scheduled, which takes it off the site', forbidden(r, 'publish_posts') and post(LIVE).get('status') == 'publish', r)
HELD = fixture('no publish scheduled', status='future', date=FUTURE_LOCAL, author=NP)
r = call('update_post', {'id': HELD, 'date': PAST}, NP_LOGIN)
record('...and a scheduled post cannot be moved to the past, which publishes it', forbidden(r, 'publish_posts') and post(HELD).get('status') == 'future', r)
r = call('update_post', {'id': HELD, 'date': '2036-01-01T09:00:00'}, NP_LOGIN)
record('...though it can take another future date', post(HELD).get('date') == '2036-01-01 09:00:00' and post(HELD).get('status') == 'future', r)

EDIT = fixture('editors', date=ORIGINAL)
r = call('update_post', {'id': EDIT, 'author': AUTHOR_ID}, 'editor')
record('an Editor can reassign a post', post(EDIT).get('author') == AUTHOR_ID, r)
r2 = rollback(by_field(r, 'author'), 'author')
record('the new author cannot undo that', r2.get('isError') and post(EDIT).get('author') == AUTHOR_ID, r2)
rollback(by_field(r, 'author'), 'editor')

revision = int(php(f"wp_update_post(['ID'=>{EDIT},'post_title'=>'{T} editors two']);$r=wp_get_post_revisions({EDIT});echo $r?array_key_first($r):0;") or 0)
if revision:
    r = call('update_post', {'id': revision, 'date': PAST})
    record('a revision is refused', r.get('isError') and 'revision' in r.get('_text', ''), r)

print('# Every credential')
for label, who, owner in (('administrator password', 'admin', None), ('OAuth', 'oauth', None), ('bound site key', 'key', str(ADMIN_ID)), ('unbound site key', 'key', '0')):
    if owner is not None:
        wp('option', 'update', 'iato_mcp_key_owner', owner)
    r = call('update_post', {'id': P, 'date': PAST, 'author': EDITOR_ID}, who)
    got = post(P)
    record(f'{label}: date and author written', got.get('date') == PAST_LOCAL and got.get('author') == EDITOR_ID and 2 == len(receipts_of(r)), (r, got))
    for receipt in receipts_of(r):
        rollback(receipt, who)
    got = post(P)
    record(f'{label}: both rolled back', got.get('date') == ORIGINAL and got.get('author') == ADMIN_ID, got)
    c = call('create_post', {'title': f'{T} made by {label}', 'status': 'publish', 'date': PAST, 'author': EDITOR_ID}, who)
    got = post(c.get('id', 0))
    record(f'{label}: create_post takes them too', got.get('date') == PAST_LOCAL and got.get('author') == EDITOR_ID and got.get('status') == 'publish', (c, got))
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))

print('# create_post')
r = call('create_post', {'title': f'{T} dry', 'date': PAST, 'author': EDITOR_ID, 'dry_run': True})
record('a dry run reports them and makes nothing', r.get('dry_run') and r.get('date') == PAST_LOCAL and r.get('date_gmt') == PAST_GMT and r.get('author') == EDITOR_ID and not php(f"$q=get_posts(['s'=>'{T} dry','post_status'=>'any']);echo $q?1:'';"), r)
r = call('create_post', {'title': f'{T} new', 'status': 'publish', 'date': PAST})
got = post(r['id'])
record('published with a past date', got.get('status') == 'publish' and got.get('date') == PAST_LOCAL and r.get('date') == PAST_LOCAL and r.get('status') == 'publish', (r, got))
r2 = rollback(r['change_receipt'])
record('rollback trashes it, as before', 'trash' == wp('post', 'get', str(r['id']), '--field=post_status'), r2)
r = call('create_post', {'title': f'{T} draft', 'date': PAST})
got = post(r['id'])
record('a draft keeps its status and its date', got.get('status') == 'draft' and got.get('date_gmt') == PAST_GMT, (r, got))
r = call('create_post', {'title': f'{T} future', 'status': 'publish', 'date': FUTURE})
record('publish with a future date: refused', refused(r, 'future_date_on_published'), r)
r = call('create_post', {'title': f'{T} future', 'status': 'publish', 'date': FUTURE, 'allow_future': True})
got = post(r.get('id', 0))
record('...with allow_future it is scheduled, and says so', got.get('status') == 'future' and r.get('status') == 'future' and 'scheduled' in r.get('status_note', ''), (r, got))
r = call('create_post', {'title': f'{T} future draft', 'date': FUTURE})
record('a draft with a future date needs no allow_future', post(r.get('id', 0)).get('status') == 'draft', r)
r = call('create_post', {'title': f'{T} bad', 'date': 'soon'})
record('a bad date: invalid_date', refused(r, 'invalid_date'), r)
r = call('create_post', {'title': f'{T} bad', 'author': 999999})
record('a bad author: invalid_author', refused(r, 'invalid_author'), r)
r = call('create_post', {'title': f'{T} by author', 'author': ADMIN_ID}, 'author')
record("an Author cannot make a post in someone else's name", forbidden(r, 'edit_others_posts'), r)
r = call('create_post', {'title': f'{T} by author two', 'author': AUTHOR_ID}, 'author')
record('...but can name themselves', post(r.get('id', 0)).get('author') == AUTHOR_ID, r)
r = call('create_post', {'title': f'{T} page', 'post_type': 'page', 'author': AUTHOR_ID})
record('the post type is checked here too', refused(r, 'invalid_author'), r)

print('# Cache')
r = call('update_post', {'id': D, 'date': PAST})
record('a draft date purges only that draft', (r.get('cache') or {}).get('scope') in ('post', None), r.get('cache'))
rollback(by_field(r, 'date'))
r = call('update_post', {'id': D, 'author': EDITOR_ID})
record('so does a draft author', (r.get('cache') or {}).get('scope') in ('post', None), r.get('cache'))
rollback(by_field(r, 'author'))

wp('option', 'update', 'iato_mcp_key_owner', OWNER)

failed = [name for name, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('failed:', '; '.join(failed)[:800])
    raise SystemExit(1)

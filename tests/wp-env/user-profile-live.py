"""Driven by user-profile-live.sh."""
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
ADMIN_ID, EDITOR_ID, AUTHOR_ID, SUBSCRIBER_ID = (int(os.environ[k]) for k in ('ADMIN_ID', 'EDITOR_ID', 'AUTHOR_ID', 'SUBSCRIBER_ID'))
T = 'user-profile-live'
PASSWORDS = {'admin': os.environ['PW_ADMIN'], 'editor': os.environ['PW_EDITOR'], 'author': os.environ['PW_AUTHOR'], 'contributor': os.environ['PW_CONTRIBUTOR'], 'subscriber': os.environ['PW_SUBSCRIBER']}
results = []
BS = chr(92)


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


def auth_for(who):
    if 'key' == who:
        return 'Bearer ' + KEY
    if 'oauth' == who:
        return 'Bearer ' + os.environ['OAUTH_TOKEN']
    return 'Basic ' + base64.b64encode(f'{who}:{PASSWORDS[who]}'.encode()).decode()


def rpc(method, params, who='key'):
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': method, 'params': params}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': auth_for(who)})
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


def profile(user_id):
    return json.loads(php(f"$u=get_userdata({user_id});echo wp_json_encode(['display_name'=>$u->display_name,'nickname'=>$u->nickname,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'description'=>$u->description,'url'=>$u->user_url,'email'=>$u->user_email,'login'=>$u->user_login,'pass'=>$u->user_pass,'roles'=>array_values($u->roles)]);"))


def rollback(receipt, who='key', **extra):
    return call('rollback', {'change_id': receipt['change_id'], **extra}, who)


def new_user(name, role='author'):
    return int(run('wp', 'user', 'create', f'{T}-{name}', f'{T}-{name}@example.test', f'--role={role}', '--first_name=Jane', '--last_name=Smith', '--nickname=Jay', f'--display_name={T}-{name}', '--porcelain')[-1])


OWNER = wp('option', 'get', 'iato_mcp_key_owner')
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))
print(f'# on {BASE}, key owner was {OWNER}')
U = new_user('main')
START = profile(U)

print('# Changing a profile')
r = call('update_user_profile', {'user_id': U, 'nickname': 'New nick', 'dry_run': True})
record('a dry run shows it and writes nothing', r.get('dry_run') and r.get('would_change', {}).get('nickname') == {'before': 'Jay', 'after': 'New nick'} and profile(U) == START, r)
r = call('update_user_profile', {'user_id': U, 'nickname': 'New nick', 'first_name': 'Pat', 'description': 'About me', 'url': 'https://example.test/me'})
now = profile(U)
record('the fields change', now['nickname'] == 'New nick' and now['first_name'] == 'Pat' and now['description'] == 'About me' and now['url'] == 'https://example.test/me', (r, now))
record('the response gives before and after of each', r.get('changes', {}).get('nickname') == {'before': 'Jay', 'after': 'New nick'} and r.get('user_id') == U, r)
record('nothing else moved', all(now[k] == START[k] for k in ('email', 'login', 'pass', 'roles', 'display_name', 'last_name')), now)
receipt = r.get('change_receipt', {})
record('one receipt for the call', receipt.get('target_type') == 'user_profile' and receipt.get('field') == 'profile', receipt)
record('the whole site is purged', (r.get('cache') or {}).get('scope') == 'all', r.get('cache'))
r2 = rollback(receipt)
record('rollback restores the previous values', profile(U) == START and r2.get('success'), (r2, profile(U)))
record('...purging the whole site', (r2.get('cache') or {}).get('scope') == 'all', r2.get('cache'))
r = call('update_user_profile', {'user_id': U, 'nickname': 'Jay', 'first_name': 'Jane'})
record('values it already has: no receipt', 'change_receipt' not in r and 'isError' not in r, r)

print('# The display name')
OFFERED = ['Jay', f'{T}-main', 'Jane', 'Smith', 'Jane Smith', 'Smith Jane']
for name in OFFERED[0:1] + OFFERED[2:]:
    r = call('update_user_profile', {'user_id': U, 'display_name': name})
    record(f'{name}: offered, so accepted', profile(U)['display_name'] == name, r)
    rollback(r['change_receipt'])
r = call('update_user_profile', {'user_id': U, 'display_name': 'Somebody Else'})
record('another name: refused, with the list', refused(r, 'display_name_not_offered') and 'Jane Smith' in r.get('offered', []) and profile(U) == START, r)
r = call('update_user_profile', {'user_id': U, 'display_name': 'Build Co', 'nickname': 'Build Co'})
now = profile(U)
record('...unless the nickname is set to the same value', now['display_name'] == 'Build Co' and now['nickname'] == 'Build Co', (r, now))
page = int(php(f"echo wp_insert_post(['post_title'=>'{T} byline','post_status'=>'publish','post_author'=>{U}]);"))
record('WordPress shows the new name as the author', php(f"echo get_the_author_meta('display_name',{U});") == 'Build Co', page)
rollback(r['change_receipt'])
record('rollback restores both', profile(U) == START, profile(U))
r = call('update_user_profile', {'user_id': U, 'display_name': 'Pat Lee', 'first_name': 'Pat', 'last_name': 'Lee'})
record('a name made from first and last names set in the same call is accepted', profile(U)['display_name'] == 'Pat Lee', r)
rollback(r['change_receipt'])
r = call('update_user_profile', {'user_id': U, 'display_name': 'Q & A', 'nickname': 'Q & A'})
now = profile(U)
record('an ampersand is stored as WordPress stores it, in both', now['display_name'] == 'Q &amp; A' and now['nickname'] == 'Q &amp; A', (r, now))
r2 = rollback(r['change_receipt'])
record('...and comes back out', profile(U) == START, (r2, profile(U)))

print('# What is never allowed')
for key, value in (('email', 'x@example.test'), ('user_email', 'x@example.test'), ('password', 'hunter2'), ('user_pass', 'hunter2'), ('role', 'administrator'), ('roles', ['administrator']), ('capabilities', {'manage_options': True}), ('user_login', 'other'), ('user_nicename', 'other'), ('ID', 1), ('meta_input', {'x': 'y'})):
    r = call('update_user_profile', {'user_id': U, 'nickname': 'Sneaky', key: value})
    record(f'{key}: refused, naming it, and nothing written', refused(r, 'field_not_allowed') and key in r.get('fields', []) and 'nickname' in r.get('allowed', []) and profile(U) == START, r)
r = call('update_user_profile', {'user_id': U})
record('no fields: refused', refused(r, 'no_fields'), r)

print('# Bad values')
for label, args, code in (
    ('an empty nickname', {'nickname': ''}, 'empty_field'),
    ('an empty display name', {'display_name': ' '}, 'empty_field'),
    ('a list', {'nickname': ['x']}, 'invalid_field'),
    ('a number', {'first_name': 5}, 'invalid_field'),
    ('a javascript URL', {'url': 'javascript:alert(1)'}, 'invalid_url'),
    ('a URL with no scheme', {'url': 'example.test'}, 'invalid_url'),
    ('a URL too long for the column', {'url': 'https://example.test/' + 'x' * 100}, 'field_too_long'),
    ('a display name too long for the column', {'display_name': 'x' * 251, 'nickname': 'x' * 251}, 'field_too_long'),
):
    r = call('update_user_profile', {'user_id': U, **args})
    record(f'{label}: {code}, nothing written', refused(r, code) and r.get('message') and profile(U) == START, r)

print('# Text')
PATH = f'C:{BS}temp {BS}"quoted{BS}"'
r = call('update_user_profile', {'user_id': U, 'description': PATH, 'last_name': PATH})
now = profile(U)
record('backslashes survive', now['description'] == PATH, (r, now['description']))
rollback(r['change_receipt'])
record('...and rollback', profile(U) == START, profile(U))
r = call('update_user_profile', {'user_id': U, 'description': '<script>alert(1)</script><a href="https://example.test">kept</a>'})
now = profile(U)
record('a script tag is removed, and reported', '<script' not in now['description'] and 'kept' in now['description'] and r.get('sanitized_report'), (r, now['description']))
rollback(r['change_receipt'])
r = call('update_user_profile', {'user_id': U, 'nickname': '<img src=x onerror=alert(1)>Name'})
record('tags in a name are removed', '<img' not in profile(U)['nickname'] and 'onerror' not in profile(U)['nickname'], (r, profile(U)['nickname']))
rollback(r['change_receipt'])

RICH = '<iframe src="https://example.test/x"></iframe> a person with more rights wrote this'
php(f"update_user_meta({U},'description',{json.dumps(RICH)});echo 1;")
r = call('update_user_profile', {'user_id': U, 'nickname': 'Other'})
record('changing another field leaves a richer description alone', profile(U)['description'] == RICH, profile(U)['description'])
rollback(r['change_receipt'])
record('...and so does its rollback', profile(U)['description'] == RICH, profile(U)['description'])
r = call('update_user_profile', {'user_id': U, 'url': 'https://example.test/a'})
record('so does changing a column, which saves everything again', profile(U)['description'] == RICH and profile(U)['url'] == 'https://example.test/a', profile(U)['description'])
rollback(r['change_receipt'])
r = call('update_user_profile', {'user_id': U, 'description': 'Plain'})
r2 = rollback(r['change_receipt'])
record('rollback restores it exactly, though the filters would change it', profile(U)['description'] == RICH, (r2, profile(U)['description']))
php(f"update_user_meta({U},'description','');echo 1;")
r = call('update_user_profile', {'user_id': U, 'url': 'https://example.test/x'})
call('update_user_profile', {'user_id': U, 'url': ''})
record('a URL can be cleared', '' == profile(U)['url'])
php(f"wp_update_user(['ID'=>{U},'user_url'=>'']);echo 1;")

print('# Conflicts')
r = call('update_user_profile', {'user_id': U, 'nickname': 'First change'})
php(f"update_user_meta({U},'nickname','Changed in wp-admin');echo 1;")
r2 = rollback(r['change_receipt'])
record('a newer change is a conflict', refused(r2, 'rollback_conflict') and profile(U)['nickname'] == 'Changed in wp-admin', r2)
r3 = rollback(r['change_receipt'], dry_run=True)
record('...a dry run says so', refused(r3, 'rollback_conflict') or r3.get('conflict_check', {}).get('status') == 'conflict', r3)
r2 = rollback(r['change_receipt'], force=True)
record('force restores it', profile(U)['nickname'] == 'Jay', r2)
GONE = new_user('gone')
r = call('update_user_profile', {'user_id': GONE, 'nickname': 'Going'})
wp('user', 'delete', str(GONE), '--yes')
r2 = rollback(r['change_receipt'], force=True)
record('a user who is gone: rollback_unavailable, force included', refused(r2, 'rollback_unavailable'), r2)

print('# Who may do what')
wp('user', 'meta', 'update', str(AUTHOR_ID), 'nickname', 'authornick')
r = call('update_user_profile', {'nickname': 'Own nick'}, 'author')
record('with no user_id it changes the caller', r.get('user_id') == AUTHOR_ID and profile(AUTHOR_ID)['nickname'] == 'Own nick', r)
r2 = rollback(r['change_receipt'], 'author')
record('...who can roll it back', profile(AUTHOR_ID)['nickname'] == 'authornick', r2)
r = call('update_user_profile', {'user_id': AUTHOR_ID, 'nickname': 'Own nick'}, 'author')
record('naming yourself is the same', profile(AUTHOR_ID)['nickname'] == 'Own nick', r)
own = r['change_receipt']
r2 = rollback(own, 'editor')
record("an Editor cannot undo someone else's", r2.get('isError') and profile(AUTHOR_ID)['nickname'] == 'Own nick', r2)
r2 = rollback(own, 'admin')
record('an administrator can', profile(AUTHOR_ID)['nickname'] == 'authornick', r2)
for who, label in (('author', 'an Author'), ('editor', 'an Editor'), ('contributor', 'a Contributor')):
    r = call('update_user_profile', {'user_id': ADMIN_ID, 'nickname': 'Hacked'}, who)
    record(f"{label} cannot change someone else's", forbidden(r, 'edit_user') and profile(ADMIN_ID)['nickname'] != 'Hacked', r)
    r = call('update_user_profile', {'user_id': 999999, 'nickname': 'x'}, who)
    record(f'{label} cannot use that to find out which IDs exist', forbidden(r, 'edit_user'), r)
r = call('update_user_profile', {'user_id': 999999, 'nickname': 'x'}, 'admin')
record('an administrator is told there is no such user', refused(r, 'invalid_user'), r)
for bad in (0, -1, 'bob', ['x']):
    r = call('update_user_profile', {'user_id': bad, 'nickname': 'x'}, 'admin')
    record(f'user_id {bad!r}: invalid_user', refused(r, 'invalid_user'), r)
r = call('update_user_profile', {'user_id': U, 'nickname': 'By admin'}, 'admin')
record('an administrator can change someone else\'s', profile(U)['nickname'] == 'By admin', r)
r2 = rollback(r['change_receipt'], 'author')
record('...and another user cannot undo that', r2.get('isError') and profile(U)['nickname'] == 'By admin', r2)
rollback(r['change_receipt'], 'admin')

wp('user', 'meta', 'update', 'contributor', 'nickname', 'connick')
r = call('update_user_profile', {'nickname': 'Con nick'}, 'contributor')
CONTRIBUTOR = r.get('user_id')
record('a Contributor can change their own', CONTRIBUTOR and profile(CONTRIBUTOR)['nickname'] == 'Con nick', r)
r2 = rollback(r['change_receipt'], 'contributor')
record('...and undo it', profile(CONTRIBUTOR)['nickname'] == 'connick' and r2.get('success'), r2)
try:
    r = call('update_user_profile', {'nickname': 'Sub nick'}, 'subscriber')
    record('a Subscriber cannot reach MCP at all', False, r)
except urllib.error.HTTPError as error:
    record('a Subscriber cannot reach MCP at all', 403 == error.code and profile(SUBSCRIBER_ID)['nickname'] != 'Sub nick', error.code)

print('# Every credential')
wp('option', 'update', 'iato_mcp_key_owner', '0')
r = call('update_user_profile', {'nickname': 'x'})
record('an unbound key with no user_id: it has no user to change', refused(r, 'iato_mcp_token_unbound') and 'user_id' in r.get('message', ''), r)
r = call('update_user_profile', {'user_id': U, 'nickname': 'By key'})
record('an unbound key naming a user can', profile(U)['nickname'] == 'By key', r)
r2 = rollback(r['change_receipt'])
record('...and roll it back', profile(U)['nickname'] == 'Jay', r2)
wp('option', 'update', 'iato_mcp_key_owner', str(ADMIN_ID))
for label, who in (('administrator password', 'admin'), ('OAuth', 'oauth'), ('bound site key', 'key')):
    before = profile(ADMIN_ID)['nickname']
    r = call('update_user_profile', {'nickname': f'Nick {who}'}, who)
    record(f'{label}: changes its own user', r.get('user_id') == ADMIN_ID and profile(ADMIN_ID)['nickname'] == f'Nick {who}', r)
    r2 = rollback(r['change_receipt'], who)
    record(f'{label}: rolls back', profile(ADMIN_ID)['nickname'] == before, r2)

wp('option', 'update', 'iato_mcp_key_owner', OWNER)

failed = [name for name, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('failed:', '; '.join(failed)[:800])
    raise SystemExit(1)

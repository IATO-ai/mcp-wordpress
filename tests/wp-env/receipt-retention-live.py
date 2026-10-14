#!/usr/bin/env python3
"""
Receipt retention live checks (driven by receipt-retention-live.sh; env:
BASE, KEY, PREFIX, PLAIN, FIXTURE). Exit 1 on any failure.
"""
import datetime, json, os, subprocess, sys, urllib.error, urllib.request

BASE = os.environ.get('BASE', 'http://localhost:8888').rstrip('/')
MSG = BASE + '/wp-json/iato-mcp/v1/message'
KEY = os.environ['KEY']
R = os.environ.get('PREFIX', 'wp_') + 'iato_change_receipts'
PLAIN = int(os.environ['PLAIN'])
FX = json.loads(os.environ['FIXTURE'])
EP, H = int(FX['page_id']), FX['ids']['heading']
HOOK = 'iato_mcp_prune_receipts'
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


def q(sql):
    out = wp('db', 'query', sql, '--skip-column-names')
    return out.splitlines()[-1].split('\t') if out else []


def call(tool, args):
    body = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': tool, 'arguments': args}}
    req = urllib.request.Request(MSG, data=json.dumps(body).encode(), method='POST', headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + KEY})
    try:
        raw = urllib.request.urlopen(req, timeout=120).read()
    except urllib.error.HTTPError as e:
        raw = e.read()
    res = json.loads(raw).get('result', {})
    text = res.get('content', [{}])[0].get('text', '')
    try:
        body = json.loads(text)
    except ValueError:
        body = {'text': text}
    return {'isError': True, 'body': body, 'text': text} if res.get('isError') else body


def edit(value):
    r = call('set_widget_setting', {'id': EP, 'widget_id': H, 'key': 'title', 'value': value})
    return r['change_receipt']['change_id']


def backdate(cid, days):
    wp('db', 'query', f"UPDATE {R} SET applied_at = DATE_SUB(applied_at, INTERVAL {days} DAY) WHERE change_id = '{cid}'")


def state(cid):
    """(has a page copy, pruned_at) for a receipt."""
    row = q(f"SELECT IF(before_value IS NULL, 'empty', 'copy'), IFNULL(pruned_at, 'NULL') FROM {R} WHERE change_id = '{cid}'")
    return tuple(row)


def run_cron():
    wp('cron', 'event', 'run', HOOK)


def last_run():
    v = wp('option', 'get', 'iato_mcp_receipt_prune_last', '--format=json')
    return json.loads(v) if v else {}


print('# schedule')
events = wp('cron', 'event', 'list', '--fields=hook,recurrence', '--format=json')
record('the daily event is scheduled', any(e.get('hook') == HOOK and 'day' in str(e.get('recurrence', '')) for e in json.loads(events or '[]')), events)

print('# the daily run clears old Elementor page copies only')
e1, e2, e3 = edit('retention-live one'), edit('retention-live two'), edit('retention-live three')
p1 = call('update_post', {'id': PLAIN, 'title': 'retention-live post renamed'})['change_receipt']['change_id']
for cid in (e1, e2, p1):
    backdate(cid, 91)
record('before the run every receipt holds its copy', [state(c)[0] for c in (e1, e2, e3)] == ['copy'] * 3, [state(c) for c in (e1, e2, e3)])
run_cron()
s1, s2, s3, sp = state(e1), state(e2), state(e3), state(p1)
record('Elementor receipts older than 90 days: copy cleared, pruned_at set', s1[0] == 'empty' and s1[1] != 'NULL' and s2[0] == 'empty', (s1, s2))
record('a recent Elementor receipt keeps its copy', s3 == ('copy', 'NULL'), s3)
record('an old receipt of another type is untouched', sp == ('copy', 'NULL'), sp)
lr = last_run()
record('the run is recorded (days, cutoff, count)', lr.get('days') == 90 and lr.get('pruned', 0) >= 2 and lr.get('cutoff'), lr)
row = q(f"SELECT target_type, field, actor_kind FROM {R} WHERE change_id = '{e1}'")
record('the cleared receipt stays as an audit row (type, field, who)', len(row) == 3 and row[0] == 'elementor_widget' and row[1].startswith(f'elementor:{H}') and row[2] == 'site_key', row)

print('# a cleared receipt is not rolled back')
r = call('rollback', {'change_id': e2})
record('rollback refused: rollback_unavailable, pointing at Elementor revisions', r.get('isError') and r['body'].get('error') == 'rollback_unavailable' and 'Revisions' in r['body'].get('message', ''), r)
record('...and the receipt stays open', q(f"SELECT IFNULL(rolled_back_at, 'NULL') FROM {R} WHERE change_id = '{e2}'") == ['NULL'])
r = call('rollback', {'change_id': e3})
record('the recent receipt still rolls back', not r.get('isError') and r.get('success') is True, r)

print('# the period follows the filter')
wp('option', 'update', 'iato_mcp_test_retention_days', '30')
e4 = edit('retention-live four')
backdate(e4, 31)
run_cron()
record('30 days: a 31-day-old copy is cleared', state(e4)[0] == 'empty' and last_run().get('days') == 30, (state(e4), last_run()))
wp('option', 'update', 'iato_mcp_test_retention_days', '0')
e5 = edit('retention-live five')
backdate(e5, 400)
run_cron()
record('0: nothing is cleared, even a 400-day-old copy', state(e5) == ('copy', 'NULL') and last_run().get('days') == 0, (state(e5), last_run()))
wp('option', 'delete', 'iato_mcp_test_retention_days')

print('# overdue job: one batch at the end of an MCP write, no cron involved')
# The receipt is made and backdated while the job is not overdue (any receipt
# write checks), then the last run is moved three days back.
e6 = edit('retention-live six')
backdate(e6, 91)
three_days_ago = (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=3)).strftime('%Y-%m-%d %H:%M:%S')
wp('option', 'update', 'iato_mcp_receipt_prune_last', json.dumps({'at': three_days_ago, 'pruned': 0, 'cutoff': None, 'days': 90}), '--format=json')
record('before the write: the old copies are still there', state(e6)[0] == 'copy' and state(e5)[0] == 'copy', (state(e6), state(e5)))
call('update_post', {'id': PLAIN, 'title': 'retention-live post renamed again'})
record('after an unrelated MCP write: cleared', state(e6)[0] == 'empty' and state(e5)[0] == 'empty', (state(e6), state(e5)))
record('...and the run time is updated', last_run().get('at', '') > three_days_ago, last_run())

print('# deactivation removes the event, the next request after activation restores it')
wp('plugin', 'deactivate', 'mcp-wordpress')
events = wp('cron', 'event', 'list', '--fields=hook', '--format=json')
record('deactivated: no event', HOOK not in events, events)
wp('plugin', 'activate', 'mcp-wordpress')
wp('option', 'get', 'siteurl')  # one request after activation runs init
events = wp('cron', 'event', 'list', '--fields=hook', '--format=json')
record('reactivated: scheduled again', HOOK in events, events)

print(f'\n# {passes} passed, {len(fails)} failed')
sys.exit(1 if fails else 0)

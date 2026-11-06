"""Driven by receipt-storage-live.sh. Restores every table change, also after a failure."""
import json
import os
import subprocess
import sys
import urllib.request

REPO = os.environ.get('WPENV_DIR') or os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
SVC = os.environ.get('WPENV_SVC', 'cli')
BASE = os.environ.get('BASE', 'http://localhost:8888')
results = []


def wp(*args):
    out = subprocess.run(['npx', '@wordpress/env', 'run', SVC, 'wp', *args], cwd=REPO, capture_output=True, text=True, stdin=subprocess.DEVNULL).stdout
    lines = [l.strip('\r') for l in out.splitlines() if l and not l.startswith(('ℹ', '✔'))]
    return lines[-1] if lines else ''


def sql(query):
    return wp('db', 'query', query, '--skip-column-names')


PREFIX = wp('db', 'prefix')
R = PREFIX + 'iato_change_receipts'
L = PREFIX + 'iato_mcp_call_log'
TMP = PREFIX + 'iato_change_receipts_hidden'
KEY = wp('option', 'get', 'iato_mcp_key')
COLUMN_DEFS = {
    'actor_user_id': 'BIGINT UNSIGNED DEFAULT NULL',
    'actor_kind': 'VARCHAR(30) DEFAULT NULL',
    'actor_client_id': 'VARCHAR(64) DEFAULT NULL',
    'rolled_back_by_user_id': 'BIGINT UNSIGNED DEFAULT NULL',
    'rolled_back_by_kind': 'VARCHAR(30) DEFAULT NULL',
    'rolled_back_by_client_id': 'VARCHAR(64) DEFAULT NULL',
}


def table_exists(table):
    return sql(f"SHOW TABLES LIKE '{table}'") == table


def has_column(table, column):
    return sql(f"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{table}' AND COLUMN_NAME='{column}'") == '1'


def restore():
    if table_exists(TMP):
        sql(f'RENAME TABLE {TMP} TO {R}')
    for column, definition in COLUMN_DEFS.items():
        if not has_column(R, column):
            sql(f'ALTER TABLE {R} ADD COLUMN {column} {definition}')
    if not has_column(L, 'credential_kind'):
        sql(f'ALTER TABLE {L} ADD COLUMN credential_kind VARCHAR(30) DEFAULT NULL')
    collation = wp('option', 'get', 'iato_test_receipts_collation')
    if collation:
        sql(f'ALTER TABLE {R} CONVERT TO CHARACTER SET utf8mb4 COLLATE {collation}')
        wp('option', 'delete', 'iato_test_receipts_collation')
    sql(f'ALTER TABLE {R} FORCE')
    sql(f'ALTER TABLE {L} FORCE')
    wp('option', 'delete', 'iato_mcp_receipt_failures')


if '--cleanup' in sys.argv:
    restore()
    for found in wp('post', 'list', '--post_type=post,page', '--post_status=any,trash', '--s=receipt-storage-live', '--format=ids').split():
        wp('post', 'delete', found, '--force')
    print('cleanup done')
    sys.exit(0)


def record(name, ok, detail=None):
    results.append((name, bool(ok)))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + ('' if ok else f': {json.dumps(detail)[:400]}'))


def call(name, args):
    body = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {'name': name, 'arguments': args}}).encode()
    req = urllib.request.Request(BASE + '/wp-json/iato-mcp/v1/message', data=body, headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + KEY})
    res = json.loads(urllib.request.urlopen(req, timeout=120).read())['result']
    text = res['content'][0]['text']
    try:
        data = json.loads(text)
    except Exception:
        data = {'text': text}
    if not isinstance(data, dict):
        data = {'value': data}
    if res.get('isError'):
        data['isError'] = True
    return data


def receipt_of(resp):
    return resp.get('change_receipt') or (resp.get('change_receipts') or [{}])[0]


def rows_for(change_id):
    return sql(f"SELECT COUNT(*) FROM {R} WHERE change_id='{change_id}'")


def failures():
    return wp('option', 'get', 'iato_mcp_receipt_failures', '--format=json')


title = 'receipt-storage-live'
POST = call('create_post', {'title': title, 'status': 'draft', 'content': 'x'})['id']
tree = json.dumps([{'id': 'c1store', 'elType': 'container', 'isInner': False, 'settings': {}, 'elements': [{'id': 'h1store', 'elType': 'widget', 'widgetType': 'heading', 'settings': {'title': 'Before'}, 'elements': []}]}])
PAGE = wp('post', 'create', '--post_type=page', '--post_status=publish', f'--post_title={title} page', '--porcelain')
wp('post', 'meta', 'update', PAGE, '_elementor_data', tree)
wp('post', 'meta', 'update', PAGE, '_elementor_edit_mode', 'builder')
wp('post', 'meta', 'update', PAGE, '_elementor_template_type', 'wp-page')
PAGE = int(PAGE)

try:
    print('# A. a healthy table: long meta keys get a real undo record')
    for size in (100, 101, 150, 255):
        key = 'k' * size
        r = call('update_post_meta', {'id': POST, 'key': key, 'value': 'v'})
        got = receipt_of(r)
        record(f'{size}-character key: the returned ID exists', got.get('change_id') and rows_for(got['change_id']) == '1', r)
        record(f'{size}-character key: no warning', 'warning' not in got, got)
        rb = call('rollback', {'change_id': got.get('change_id')})
        record(f'{size}-character key: rollback removes it', rb.get('success') and wp('post', 'meta', 'get', str(POST), key) == '', rb)
    record('no failures counted', failures() in ('', 'false', '[]'), failures())

    print('# B. an actor column missing: the undo works, who made it is not recorded')
    for column in ('actor_user_id', 'actor_kind', 'actor_client_id'):
        sql(f'ALTER TABLE {R} FORCE')
        sql(f'ALTER TABLE {R} DROP COLUMN {column}')
        try:
            r = call('update_post', {'id': POST, 'title': f'{title} {column}'})
            got = receipt_of(r)
            record(f'{column}: the write succeeded', not r.get('isError'), r)
            record(f'{column}: the returned ID exists', got.get('change_id') and rows_for(got['change_id']) == '1', got)
            record(f'{column}: the warning says who was not recorded', 'same-user rule' in got.get('warning', ''), got)
            rb = call('rollback', {'change_id': got.get('change_id')})
            record(f'{column}: rollback restores the title', rb.get('success') and wp('post', 'get', str(POST), '--field=post_title') == title, rb)
        finally:
            sql(f'ALTER TABLE {R} ADD COLUMN {column} {COLUMN_DEFS[column]}')
    record('the failures are counted', 'count' in failures(), failures())
    wp('option', 'delete', 'iato_mcp_receipt_failures')

    print('# C. a rolled-back-by column missing: the rollback is still marked')
    for column in ('rolled_back_by_user_id', 'rolled_back_by_kind', 'rolled_back_by_client_id'):
        r = call('update_post', {'id': POST, 'title': f'{title} {column}'})
        cid = receipt_of(r).get('change_id')
        sql(f'ALTER TABLE {R} FORCE')
        sql(f'ALTER TABLE {R} DROP COLUMN {column}')
        try:
            rb = call('rollback', {'change_id': cid})
            record(f'{column}: rollback succeeded with no warning', rb.get('success') and 'warning' not in rb, rb)
            again = call('rollback', {'change_id': cid})
            record(f'{column}: a second rollback is refused as already done', again.get('isError') and 'already_rolled_back' in json.dumps(again), again)
        finally:
            sql(f'ALTER TABLE {R} ADD COLUMN {column} {COLUMN_DEFS[column]}')

    print('# D. the table takes no rows: no ID, a warning, and the change still reported')
    sql(f'RENAME TABLE {R} TO {TMP}')
    try:
        r = call('update_post', {'id': POST, 'title': f'{title} no table'})
        got = receipt_of(r)
        record('update_post: no ID', got.get('change_id') is None and got.get('stored') is False, got)
        record('update_post: the warning says rollback cannot undo it', 'cannot undo' in got.get('warning', ''), got)
        record('update_post: the change was applied', wp('post', 'get', str(POST), '--field=post_title') == f'{title} no table')
        w = call('update_elementor_widget', {'id': PAGE, 'widget_id': 'h1store', 'settings_patch': {'title': 'After'}})
        got = w.get('change_receipt', {})
        record('update_elementor_widget: no ID, and the warning survives the slim receipt', got.get('change_id') is None and got.get('stored') is False and 'cannot undo' in got.get('warning', ''), w)
        b = call('update_elementor_widgets_bulk', {'updates': [{'id': PAGE, 'widget_id': 'h1store', 'settings_patch': {'title': 'Bulk'}}]})
        row = (b.get('results') or [{}])[0]
        record('update_elementor_widgets_bulk: the row keeps the receipt that warns', 'cannot undo' in row.get('change_receipt', {}).get('warning', ''), b)
    finally:
        sql(f'RENAME TABLE {TMP} TO {R}')
    record('the failures are counted, with the database message', 'count' in failures() and "doesn't exist" in failures(), failures())
    wp('option', 'delete', 'iato_mcp_receipt_failures')
    b = call('update_elementor_widgets_bulk', {'updates': [{'id': PAGE, 'widget_id': 'h1store', 'settings_patch': {'title': 'Bulk again'}}]})
    row = (b.get('results') or [{}])[0]
    record('with a healthy table, bulk rows stay lean', row.get('success') and 'change_receipt' not in row, b)

    print('# E. an emoji in a value, receipts table in 3-byte utf8mb3')
    collation = sql(f"SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{R}'")
    wp('option', 'update', 'iato_test_receipts_collation', collation)
    sql(f'ALTER TABLE {R} CONVERT TO CHARACTER SET utf8mb3')
    r = call('update_post', {'id': POST, 'title': f'{title} \U0001F389'})
    got = receipt_of(r)
    record('no ID, because the row was not saved', got.get('change_id') is None and got.get('stored') is False, got)
    sql(f'ALTER TABLE {R} CONVERT TO CHARACTER SET utf8mb4 COLLATE {collation}')
    wp('option', 'delete', 'iato_test_receipts_collation')
    wp('option', 'delete', 'iato_mcp_receipt_failures')

    print('# F. the call log with a column missing keeps logging')
    sql(f'ALTER TABLE {L} FORCE')
    sql(f'ALTER TABLE {L} DROP COLUMN credential_kind')
    try:
        marker = f'receipt-storage-{POST}'
        call('search_posts', {'query': marker})
        found = sql(f"SELECT COUNT(*) FROM {L} WHERE tool_name='search_posts' AND request_args LIKE '%{marker}%'")
        record('the call was logged', found not in ('', '0'), found)
    finally:
        sql(f'ALTER TABLE {L} ADD COLUMN credential_kind VARCHAR(30) DEFAULT NULL')
finally:
    restore()
    wp('post', 'delete', str(POST), '--force')
    wp('post', 'delete', str(PAGE), '--force')

failed = [n for n, ok in results if not ok]
print(f'# {len(results) - len(failed)} passed, {len(failed)} failed')
if failed:
    print('# failed: ' + '; '.join(failed))
sys.exit(1 if failed else 0)

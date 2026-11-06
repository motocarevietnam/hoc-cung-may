"""Copy the app to a disposable directory, with a fake model and a dedicated test DB.
Never use production database credentials. This script does not modify the source app.
"""
import argparse
import json
import pathlib
import shutil

p = argparse.ArgumentParser()
p.add_argument('destination', type=pathlib.Path)
p.add_argument('--db-port', type=int, default=3307)
p.add_argument('--db-name', default='may_issue47_test')
args = p.parse_args()
assert args.db_name.startswith('may_issue47_test'), 'A dedicated test database is required'
assert not args.destination.exists(), 'Choose a fresh test directory'
source = pathlib.Path(__file__).resolve().parents[1]
shutil.copytree(source, args.destination, ignore=shutil.ignore_patterns('.git', 'config.php', 'backups', 'node_modules'))
config = {'db_host':'127.0.0.1', 'db_port':args.db_port, 'db_name':args.db_name,
          'db_user':'root', 'db_pass':'', 'app_pin':'test-child', 'parent_pin':'test-parent',
          'daily_limit':10000, 'timezone':'Asia/Ho_Chi_Minh'}
(args.destination/'app/config.php').write_text('<?php return json_decode(' + repr(json.dumps(config)) + ', true);')
lib = args.destination/'app/lib.php'
lib.write_text(lib.read_text().replace('function ask_claude(', 'function real_ask_claude('))
(args.destination/'tests/router.php').write_text('''<?php
function ask_claude(string $model, string $system, array $messages, int $maxTokens, int $timeout=100, string $effort='medium'): string {
    if (($messages[count($messages)-1]['content'] ?? '') === 'fixture_model_failure') throw new UpstreamError('fixture_model_failure');
    file_put_contents(__DIR__.'/model_calls.jsonl', json_encode(['system'=>$system,'messages'=>$messages], JSON_UNESCAPED_UNICODE)."\\n", FILE_APPEND);
    if (str_contains($system, 'người bạn đồng hành')) return 'Mây nghe Kem kể rồi. Kem thử giải thích một ý bằng lời của mình nhé?';
    $qs=[];
    for($i=1;$i<=12;$i++) $qs[]=['q'=>"Có {$i} viên bi, thêm {$i} viên nữa thì có bao nhiêu viên?",'options'=>[(string)($i*2),(string)($i*3)],'answer'=>0,'explain'=>'Cộng hai nhóm bằng nhau.','hint'=>'Cộng hai số.'];
    return json_encode(['questions'=>$qs,'bad'=>[]]);
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/api/')) { require __DIR__.'/../public/api.php'; return true; }
if (is_file(__DIR__.'/../public'.$path)) return false;
require __DIR__.'/../public/index.html';
''')
print(args.destination)

"""Exercise the TV word and Science modules preserved from the existing VPS release.
Run against the disposable fixture server only, after the main API/browser suites.
"""
import json
import os
import shlex
import subprocess
import urllib.request
import uuid

base = os.environ.get('MAY_TEST_URL', 'http://127.0.0.1:8082')
app = os.environ['MAY_TEST_APP']
assert base.startswith(('http://127.0.0.1:', 'http://localhost:'))
php = r'''<?php
require $argv[1].'/app/lib.php';
if (!str_starts_with((string)cfg('db_name'),'may_issue47_test')) throw new RuntimeException('Dedicated test DB required');
$tv=['title'=>'Fixture word activity','words'=>[['w'=>'mèo','type'=>'dt','why'=>'con vật'],['w'=>'chạy','type'=>'đt','why'=>'hoạt động']],
 'pairs'=>[['a'=>'Trời mưa.','b'=>'Em mang ô.','example'=>'Vì trời mưa nên em mang ô.']]];
$sci=['title'=>'Fixture experiment','materials'=>['water'], 'steps'=>['Look at the water.','Describe it.'],
 'question'=>'What do you see?', 'answer'=>'private answer', 'answer_vi'=>'private explanation','words'=>[]];
$ids=[];
foreach (['tvword'=>['tv',$tv], 'sciexp'=>['csci',$sci]] as $kind=>$d) {
 q('INSERT INTO readings (day, kind, subject, topic, level, data, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)',[today(),$kind,$d[0],'fixture',json_encode($d[1],JSON_UNESCAPED_UNICODE),time(),time()]);
 $ids[$kind]=(int)db()->lastInsertId();
}
echo json_encode($ids);
'''
ids = json.loads(subprocess.check_output(shlex.split(os.environ.get('MAY_TEST_PHP','php'))+['--',app], input=php.encode()))

def call(route, data=None):
    req=urllib.request.Request(base+'/api/'+route,data=json.dumps(data or {}).encode(),headers={'Content-Type':'application/json','x-app-pin':'test-child'})
    return json.load(urllib.request.urlopen(req))

d=call('companion/session')
catalog={s['id']:s for s in d['context']['subjects']}
assert 'tvw' in catalog['tv']['modes'] and 'sci' in catalog['csci']['modes']
for view,subject,kind in [('tvw','tv','tvword'),('sci','csci','sciexp')]:
    d=call('companion/turn',{'session_id':d['session']['id'],'revision':d['session']['revision'],'request_id':str(uuid.uuid4()),'type':'learning','card':{'view':view,'subject':subject}})
    call('companion/checkpoint',{'session_id':d['session']['id'],'revision':d['session']['revision'],'view':view,'ref':ids[kind]})
    r=call('companion/activity',{'session_id':d['session']['id']})['activity']
    assert r['id']==ids[kind]
    if view=='tvw': assert 'type' not in r['words'][0] and r['sort'] is None
    else: assert 'answer' not in r['exp'] and 'answer_vi' not in r['exp']
print('PASS VPS compatibility: TV/Science discoverable, accepted, checkpointed and resumed without leaking solutions')

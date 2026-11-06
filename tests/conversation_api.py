"""Integration checks against the disposable PHP server from prepare_integration.py."""
import json
import os
import urllib.error
import urllib.request
import uuid

base = os.environ.get('MAY_TEST_URL', 'http://127.0.0.1:8082')
assert base.startswith(('http://127.0.0.1:', 'http://localhost:')), 'Use only a local test server'

def call(route, data=None, parent=False, auth=True, expected=200):
    headers = {'Content-Type':'application/json'}
    if auth:
        headers['x-parent-pin' if parent else 'x-app-pin'] = 'test-parent' if parent else 'test-child'
    req = urllib.request.Request(base+'/api/'+route, data=json.dumps(data or {}).encode(), headers=headers)
    try:
        res=urllib.request.urlopen(req)
    except urllib.error.HTTPError as e:
        res=e
    assert res.status==expected, (route, res.status, res.read().decode())
    return json.load(res)

call('companion/session',auth=False,expected=401)
call('parent/clear',{'what':'all'},parent=True)
call('parent/schedule',{'save':{'timetable':{str(n):'Toán, Science' for n in range(1,8)}}},parent=True)
d=call('companion/session')
initial=d['session']
assert d['context']['expected'][0]['label']=='Toán'
assert call('companion/session')['session']==initial, 'Reload must not append greetings'
call('companion/turn',{'session_id':initial['id'],'revision':initial['revision'],'request_id':str(uuid.uuid4()),'type':'text','text':'fixture_model_failure'},expected=502)
assert call('companion/session')['session']==initial, 'A failed model response must not partially commit the turn'

def turn(kind, **extra):
    global d
    req={'session_id':d['session']['id'],'revision':d['session']['revision'], 'request_id':str(uuid.uuid4()),'type':kind,**extra}
    d=call('companion/turn',req)
    return req

turn('recap')
assert d['session']['state']['phase']=='SCHOOL_RECAP'
assert not d['session']['state']['recaps']
turn('text',text='Hôm nay cô dạy tổng và hiệu')
assert d['session']['state']['phase']=='KNOWLEDGE_RECALL'
evidence=d['session']['state']['recaps'][-1]
assert evidence['source']=='child_report' and not evidence['verified'] and evidence['message_id']
turn('text',text='Mình tìm số bé bằng hiệu và tổng')
assert d['session']['state']['phase']=='MISSION_OFFER'
assert not d['session']['state']['active_card']
stars=d['stars']
req=turn('rest')
assert d['session']['state']['phase']=='REST' and d['stars']==stars
assert call('companion/turn',req)['session']==d['session'], 'Timed-out retry is idempotent'
call('companion/turn',{**req,'request_id':str(uuid.uuid4())},expected=409)
turn('text',text='Làm bài luôn')
assert d['session']['state']['phase']=='MISSION_OFFER'
turn('learning',card={'view':'quiz','subject':'toan','topic':'Tổng và hiệu'})
quiz=call('quiz',{'subject':'toan','topic':'Tổng và hiệu'})
assert 'answer' not in quiz['questions'][0]
checkpoint={'session_id':d['session']['id'],'revision':d['session']['revision'],'view':'quiz','ref':quiz['quiz_id']}
call('companion/checkpoint',checkpoint)
call('answer',{'quiz_id':quiz['quiz_id'],'index':0,'picked':0})
restored=call('companion/activity',{'session_id':d['session']['id']})
assert restored['quiz']['i']==1 and len(restored['quiz']['results'])==1
assert 'answer' not in restored['quiz']['qs'][1], 'Restore must not leak unanswered solutions'
for i in range(1,len(quiz['questions'])):
    completed=call('answer',{'quiz_id':quiz['quiz_id'],'index':i,'picked':0})
repeated=call('answer',{'quiz_id':quiz['quiz_id'],'index':len(quiz['questions'])-1,'picked':0})
assert repeated['stars']==completed['stars'], 'Repeated submit must not award twice'
assert call('companion/session')['stars']==completed['stars'], 'Restoring chat must not award again'
turn('reflection')
call('companion/checkpoint',checkpoint,expected=409)
parent=call('parent/conversation',{'id':d['session']['id']},parent=True)
assert parent['learning_memory']['recaps'][-1]['source']=='child_report'
call('parent/conversation',{'id':d['session']['id']},expected=401)
turn('text',text='Hôm nay đổi tiết, mình nghỉ học')
assert d['session']['state']['selected'] is None
assert len(d['session']['state']['schedule_changes'])==1
old=d['session']['id']
call('parent/clear',{'what':'chats'},parent=True)
call('companion/turn',{'session_id':old,'revision':d['session']['revision'],'request_id':str(uuid.uuid4()),'type':'rest'},expected=404)
new=call('companion/session')
assert new['session']['id']!=old and not new['session']['state']['recaps']
assert new['session']['revision']==0 and len(new['session']['messages'])==1
print('PASS API: auth, schedule, recall provenance, rest, retries, revision conflicts, activity resume, parent access and cascading deletion')

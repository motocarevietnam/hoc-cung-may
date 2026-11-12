'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const path=process.argv[2]||'public/index.html';
const html=fs.readFileSync(path,'utf8');
const start=html.indexOf('async function startQuiz(){');
const end=html.indexOf('async function startReview(){',start);
assert.ok(start>=0&&end>start);
const buttons=[{dataset:{quizLesson:'0'},disabled:false}];
const els={retry:{},quizRecoveryReview:{},quizRecoveryError:{textContent:''}};
const requests=[];
const sandbox={state:{view:'quiz',subject:'csci',topic:'',quiz:null},console,
  ready:()=>true,go:()=>{},render:()=>{},copyFor:x=>x,
  esc:x=>String(x).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
  startReview:()=>{},document:{getElementById:id=>els[id]},
  app:{querySelectorAll:()=>buttons},api:async(p,b)=>{requests.push({p,b});return{};}
};
vm.createContext(sandbox);vm.runInContext(html.slice(start,end),sandbox);
const lesson={topic:'UNIT 2 - ENERGY - HEAT Students will study heat.',school_day:'2026-11-06',period:7,requires_confirmation:true,available_count:10};
const q={kind:'quiz',error:'old',errorCode:'quiz_lesson_confirmation_required',recovery:{choices:[lesson],approved_subject_count:72}};
const markup=sandbox.quizRecoveryMarkup(q,{lang:'en'});
assert.match(markup,/I have studied this/);
assert.match(markup,/72/);
assert.match(markup,/06\/11\/2026/);
assert.doesNotMatch(markup,/Mây chưa tìm đủ 10/);
assert.equal(requests.length,0,'rendering cannot confirm a lesson');
const malicious={...q,recovery:{choices:[{...lesson,topic:'<img src=x onerror=alert(1)>'}],approved_subject_count:1}};
assert.ok(!sandbox.quizRecoveryMarkup(malicious,{lang:'vi'}).includes('<img'));
(async()=>{
  sandbox.state.quiz=q;
  let starts=0;sandbox.startQuiz=async()=>{starts++;};
  sandbox.wireQuizRecovery(q,{lang:'en'});
  await buttons[0].onclick();
  assert.equal(requests.length,1);
  assert.equal(requests[0].p,'/api/companion/confirm_lesson');
  assert.equal(requests[0].b.day,'2026-11-06');assert.equal(requests[0].b.period,7);
  assert.equal(requests[0].b.status,'taught');assert.equal(starts,1);
  assert.equal(sandbox.state.topic,lesson.topic);
  await buttons[0].onclick();assert.equal(requests.length,1,'double click is ignored');
  q.recoveryBusy=false;q.recovery.choices[0]={...lesson,requires_confirmation:false};
  sandbox.wireQuizRecovery(q,{lang:'en'});await buttons[0].onclick();
  assert.equal(requests.length,1,'already confirmed lesson does not write confirmation again');
  assert.equal(starts,2);
  vm.runInContext(html.slice(start,end),sandbox); // restore real startQuiz
  sandbox.state.quiz=null;
  sandbox.api=async()=>{throw {code:'quiz_lesson_confirmation_required',data:q.recovery};};
  await sandbox.startQuiz();
  assert.equal(sandbox.state.quiz.recovery,q.recovery,'error details survive the UI request');
  assert.equal(sandbox.state.quiz.errorCode,'quiz_lesson_confirmation_required');
  console.log('PASS UI: recovery copy, safe escaping, explicit confirmation, full topic, double-click guard, existing confirmation, API error payload');
})().catch(e=>{console.error(e);process.exitCode=1;});

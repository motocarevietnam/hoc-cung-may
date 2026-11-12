/* Chat shell around the existing learning renderers. No grading or reward decisions here. */
const Companion = (() => {
  const C = {session:null, context:null, loading:false, busy:false, error:'', draft:'', pending:null,
    picker:false, schedule:false, weekStart:null, weekData:null, weekBusy:false, reviewOpen:false, reviewData:null, reviewBusy:false, actions:null, scrollTop:null, atBottom:true, feedRevision:null, activitySubject:null, mounted:false, mic:null, fields:{}, epoch:0, checkpoint:null,proactiveDismissed:false};
  const activityViews = ['quiz','homework','write','read','listen','wp','speak','chat','check','tvw','sci','ipa'];
  const enabled = () => !PARENT_MODE && me.chatFirst !== false;
  const uuid = () => globalThis.crypto?.randomUUID?.() || Date.now().toString(36)+'_'+Math.random().toString(36).slice(2)+'_request';
  const card = () => C.session?.state.active_card;
  const modeView = v => v === 'review' ? 'quiz' : v;
  const activeHere = () => enabled() && card() && activityViews.includes(state.view);
  function saveDrafts(){
    if(!C.session) return;
    try { localStorage.setItem('may_companion_draft', JSON.stringify({id:C.session.id, at:Date.now(),
      expires:Date.now()+C.context.retention_days*86400000, day:C.context.day, draft:C.draft.slice(0,1500), pending:C.pending, checkpoint:C.checkpoint, fields:C.fields, ref:card()?.ref||0, view:card()?.view||''})); } catch {}
  }
  function restoreDrafts(){
    try {
      const d=JSON.parse(localStorage.getItem('may_companion_draft')||'null');
      if(d?.id===C.session.id && d.day===C.context.day && Date.now()-d.at < C.context.retention_days*86400000){
        C.draft=String(d.draft||''); C.pending=d.pending||null; C.checkpoint=d.checkpoint||null;
        if(d.ref===(card()?.ref||0) && d.view===(card()?.view||'')) C.fields=d.fields||{};
      } else if(d) { C.draft='';C.fields={};C.pending=null;C.checkpoint=null;localStorage.removeItem('may_companion_draft'); }
    } catch {}
  }
  function accept(d){
    const oldId=C.session?.id;
    C.session=d.session; C.context=d.context;
    if(oldId && oldId!==d.session.id){ C.fields={}; C.draft=''; C.pending=null; C.checkpoint=null; }
    if(typeof d.stars==='number') setStars(d.stars);
  }
  async function refreshReviewSuggestions(){
    if(!C.session || !ready())return;
    try{
      const d=await api('/api/companion/review_plan');
      C.reviewData=d.plan;
      if(state.view==='home')render();
    }catch(e){ /* Suggestions are optional; chat must remain usable. */ }
  }
  function proactiveCard(){
    const messages=C.session?.messages||[];
    const turns=messages.filter(m=>m.role==='user');
    const last=turns.at(-1)?.content||'';
    if(turns.length<2||C.proactiveDismissed||C.reviewOpen||card()||C.session?.state.phase==='REST')return '';
    if(/(?:không muốn|đừng|thôi|nghỉ|hết rồi|mệt|chỉ muốn nói chuyện)/iu.test(last))return '';
    const plan=C.reviewData;
    if(!plan)return '';
    const ready=plan.items||[],pending=plan.awaiting_confirmation||[];
    const x=ready[0]||pending[0];
    if(!x)return '';
    const confirmed=ready.length>0;
    const label=confirmed?'Mây thấy có một bài đến hạn ôn, mình thử 5 phút nhé?':'Mây thấy một bài trong lịch cũ. Kem đã học bài này chưa?';
    return '<section class="cc-review-card cc-proactive" aria-label="Mây gợi ý ôn tập">'+
      '<div class="cc-matrix-head"><strong>☁️ Mây rủ Kem ôn bài</strong><button class="cc-tool" id="ccProactiveDismiss" aria-label="Để sau">Để sau ✕</button></div>'+
      '<p>'+esc(label)+'</p><p><b>'+esc(x.subject_label)+'</b> · '+esc(x.topic)+'</p>'+
      '<div class="cc-lesson-actions">'+(confirmed?
       '<button class="btn primary" data-cc-suggest-start="'+esc(x.key)+'">Cùng ôn 5 phút</button>':
       '<button class="btn primary" data-cc-suggest-confirm="'+esc(x.key)+'">Đã học · Cùng ôn</button>')+
      '<button class="btn" id="ccProactiveMore">Xem các môn khác</button></div></section>';
  }
  async function load(){
    if(C.loading || !ready()) return;
    C.loading=true; C.error=''; const epoch=C.epoch;
    try { const d=await api('/api/companion/session'); if(epoch!==C.epoch)return;accept(d); restoreDrafts(); await refreshReviewSuggestions(); }
    catch(e){ C.error=copyFor(e.code); }
    finally { C.loading=false; if(state.view==='home') render(); }
  }
  async function turn(type='text', extra={}){
    if(C.busy || !ready() || !C.session) return false;
    const box=document.getElementById('ccBox'); if(box) C.draft=box.value;
    const text=type==='text' ? C.draft.trim() : '';
    if(type==='text' && !text) return false;
    const request={session_id:C.session.id, revision:C.session.revision, request_id:uuid(), type, ...extra};
    if(type==='text') request.text=text;
    // Retry an uncertain network response with the same nonce, rather than append another message.
    if(C.pending && JSON.stringify(C.pending.intent)===JSON.stringify({type,extra,text})) Object.assign(request,C.pending.request);
    C.pending={request,intent:{type,extra,text}};
    const epoch=C.epoch;
    C.busy=true; C.error=''; C.schedule=false; C.actions=false; stopMic(); stopVoice(); saveDrafts(); render();
    try {
      const d=await api('/api/companion/turn',request);
      if(epoch!==C.epoch)return false;accept(d);
      if(type==='text'){
        const t=text.toLowerCase();
        if(/lịch|lich/.test(t)&&/tuần sau|tuần tới|tuan sau|tuan toi|tuần này|tuan nay/.test(t))
          openWeek(weekMonday(C.context.day,/tuần sau|tuần tới|tuan sau|tuan toi/.test(t)?1:0));
        C.draft='';
      }
      C.pending=null; C.picker=false; saveDrafts();
      if(type==='text' && !C.proactiveDismissed)await refreshReviewSuggestions();
      if(autoRead) speak(C.session.messages.at(-1)?.content||'', 'vi');
      return true;
    } catch(e){
      if(['conversation_conflict','session_gone'].includes(e.code)){
        C.pending=null;
        await load();
        C.error='Cuộc trò chuyện đã được cập nhật. Nội dung Kem đang nhập vẫn ở đây; Kem có thể gửi lại nhé.';
      } else C.error=copyFor(e.code);
      return false;
    } finally { C.busy=false; render(); }
  }
  function weekMonday(day,shift=0){
    const d=new Date(day+'T12:00:00');d.setDate(d.getDate()-((d.getDay()+6)%7)+shift*7);
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
  }
  function dateLabel(day){return day.slice(8)+'/'+day.slice(5,7);}
  async function openWeek(start){
    C.weekStart=start;C.weekData=null;C.weekBusy=true;render();
    try{const result=await api('/api/companion/school_week',{start});if(C.weekStart===start)C.weekData=result;}
    catch(e){C.error=copyFor(e.code)||'Chưa tải được lịch học';}
    finally{C.weekBusy=false;render();}
  }
  function weekMatrix(){
    if(!C.weekStart)return '';
    let content='<p>Đang tải lịch…</p>';
    if(C.weekData){
      const days=Array.from({length:5},(_,i)=>{const d=new Date(C.weekStart+'T12:00:00');d.setDate(d.getDate()+i);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');});
      const map=new Map(C.weekData.lessons.map(x=>[x.day+'_'+x.period,x]));
      content=C.weekData.lessons.length?'<div class="cc-matrix-scroll"><table class="cc-matrix"><thead><tr><th>Tiết</th>'+days.map((d,i)=>'<th>T'+(i+2)+'<small>'+dateLabel(d)+'</small></th>').join('')+'</tr></thead><tbody>'+
      Array.from({length:8},(_,i)=>'<tr><th>'+(i+1)+'</th>'+days.map(d=>{const x=map.get(d+'_'+(i+1));return '<td>'+(x?'<button class="cc-matrix-cell" data-cc-cell="'+esc(d+'_'+(i+1))+'">'+esc(x.subject)+'</button>':'—')+'</td>';}).join('')+'</tr>').join('')+'</tbody></table></div>':'<p>Chưa có lịch tuần này.</p>';
    }
    return '<section class="cc-matrix-card card"><div class="cc-matrix-head"><b>📅 Lịch '+dateLabel(C.weekStart)+' – '+(C.weekData?dateLabel(C.weekData.end):'')+'</b><button class="cc-tool" id="ccWeekClose">✕</button></div><p class="muted">Chạm vào môn để xem bài học dự kiến.</p>'+content+'<div id="ccCellDetail"></div></section>';
  }
  async function openReview(){
    C.reviewOpen=true;C.reviewBusy=true;render();
    try{const d=await api('/api/companion/review_plan');C.reviewData=d.plan;}
    catch(e){C.error=copyFor(e.code)||'Chưa tải được kế hoạch ôn';}
    finally{C.reviewBusy=false;render();}
  }
  function reviewCard(){
    if(!C.reviewOpen)return '';
    const plan=C.reviewData||{},ready=plan.items||[],pending=plan.awaiting_confirmation||[];
    const icons={toan:'🧮',tv:'✍️',cmath:'📐',ceng:'📖',csci:'🔬'};
    const card=(x,confirmed)=>{
      const icon=icons[x.subject]||'📘';
      const label=confirmed?'Đã xác nhận học':'Chưa xác nhận đã học';
      const action=confirmed?'<button class="btn primary" data-cc-review="'+esc(x.key)+'">Bắt đầu ôn tập</button>':
        '<button class="btn primary" data-cc-confirm="'+esc(x.key)+'" data-answer="taught">Đã học · Bắt đầu ôn</button><button class="btn" data-cc-confirm="'+esc(x.key)+'" data-answer="not_taught">Chưa học</button>';
      return '<article class="cc-lesson-card"><div class="cc-lesson-icon" aria-hidden="true">'+icon+'</div><div class="cc-lesson-main"><strong>'+esc(x.subject_label)+'</strong><p class="cc-lesson-topic">'+esc(x.topic)+'</p><small>'+dateLabel(x.source_day)+' · '+label+'</small><div class="cc-lesson-actions">'+action+'</div></div></article>';
    };
    const extra=plan.extra_review?'<details class="cc-extra-review"><summary>📒 Ôn bổ sung · '+plan.extra_review.count+' câu từng sai</summary><p>Phần này riêng với bài đã học trên trường.</p><button class="btn" id="ccOpenMistakes">Ôn câu sai</button></details>':'';
    return '<section class="cc-review-card" aria-label="Ôn tập bài học trên trường"><div class="cc-matrix-head"><h2>📚 Ôn tập bài đã học</h2><button class="cc-tool" id="ccReviewClose" aria-label="Đóng ôn tập">✕</button></div>'+
      '<p class="cc-review-intro">Chọn bài Kem đã học ở trường. Mây mở bài luyện tập đúng chủ đề đã học. Kem có thể chọn kể lại kiến thức sau.</p>'+
      (C.reviewBusy?'<p role="status">Đang tải kế hoạch…</p>':
      (ready.length?'<h3>Đã học · Sẵn sàng ôn</h3><div class="cc-lesson-list">'+ready.map(x=>card(x,true)).join('')+'</div>':'')+
      '<h3>Bài theo lịch trường</h3><div class="cc-lesson-list">'+(pending.length?pending.map(x=>card(x,false)).join(''):'<p class="muted">Chưa có bài nào đang chờ xác nhận.</p>')+'</div>'+extra)+
      '</section>';
  }
  function history(){
    const messages=C.session?.messages||[];
    return `<div class="cc-history" role="log" aria-label="Trò chuyện với Mây" aria-live="polite" aria-relevant="additions text">${messages.map(m=>
      `<div class="msg ${m.role==='user'?'me':'buddy'}">${m.role==='user'?'':mascot()}<div class="bubble">${fmt(m.content)}${m.role==='assistant'?`<button class="speak" data-cc-say="${m.id}" aria-label="Đọc lời Mây">🔊</button>`:''}</div></div>`).join('')}
      ${C.busy?'<p role="status">Mây đang nghe và nghĩ cùng Kem…</p>':''}</div>`;
  }
  function subjectChoices(){
    const expected=C.context?.expected||[], catalog=C.context?.subjects||[];
    const keys=new Set(expected.map(x=>x.subject));
    const button=(key,label)=>`<button class="chip" data-cc-subject="${esc(key)}" ${C.busy||!C.session?'disabled':''}>${esc(label)}</button>`;
    return `<div class="cc-subjects">${expected.map(x=>button(x.key,x.label)).join('')}</div>
      <details class="cc-other-subjects"><summary>Môn khác</summary><div class="cc-subjects">${catalog.filter(x=>!keys.has(x.id)).map(x=>button(x.id,x.name)).join('')}</div></details>`;
  }
  function quickVisible(){ return C.actions ?? !C.session?.messages.some(m=>m.role==='user'); }
  function scheduleDialog(){
    if(!C.schedule) return '';
    const note=C.context?.day_off?'Hôm nay được đánh dấu là ngày nghỉ.':(C.context?.expected?.length?'Đây là lịch dự kiến. Nếu đổi tiết, Kem cứ nói Mây nhé.':'Chưa có lịch hôm nay. Kem có thể chọn môn khác hoặc nghỉ.');
    return `<dialog id="ccScheduleDialog" class="cc-dialog" aria-labelledby="ccScheduleTitle" aria-describedby="ccScheduleNote">
      <div class="cc-dialog-head"><h2 id="ccScheduleTitle">Lịch học hôm nay</h2><button class="btn" id="ccScheduleClose" aria-label="Đóng lịch học">✕</button></div>
      <p id="ccScheduleNote" class="muted">${note}</p>${subjectChoices()}</dialog>`;
  }
  function activities(){
    const subjects=C.context?.subjects||[];
    const selected=subjects.find(s=>s.id===C.activitySubject)||subjects.find(s=>s.id===C.session?.state.selected?.subject);
    const actual=C.session?.state.recaps.filter(r=>r.kind==='lesson_report' && r.subject===selected?.id).at(-1);
    const en=['ceng','cmath','csci'].includes(selected?.id);
    return `<section class="cc-activities card" aria-label="Chọn hoạt động"><h3>${en?'Learn '+(selected?.id==='cmath'?'Maths':selected?.id==='csci'?'Science':'English')+' with Mây':'Học cùng Mây từng chút một'}</h3>
      <p class="muted">${en?'Choose an activity to practise '+(selected?.id==='cmath'?'Maths':selected?.id==='csci'?'Science':'English')+'. Mây is here to help!':'Kem chọn bài cô giao, phần muốn ôn hoặc hoạt động mình thích nhé.'}</p>
      <div class="chips">${subjects.map(s=>`<button class="chip" data-cc-pick="${esc(s.id)}" aria-pressed="${selected?.id===s.id}">${esc(s.name)}</button>`).join('')}</div>
      ${selected?`<div class="cc-modes">${Object.entries(selected.modes).map(([view,label])=>`<button class="btn" data-cc-start="${view}" data-subject="${esc(selected.id)}">${esc(label)}</button>`).join('')}</div>
        ${actual?`<p class="muted">Theo điều Kem kể: ${esc(actual.text)}. Với bài dài, Kem có thể mở rộng vùng làm bài.</p>`:''}`:'<p>Chọn môn để xem hoạt động có sẵn.</p>'}
      <button class="btn" data-cc-start="review">${en?'📒 Review mistakes':'📒 Ôn câu đã làm'}</button>
      <button class="btn" data-cc-turn="joy">${en?'Keep chatting with Mây':'Mình muốn trò chuyện tiếp'}</button></section>`;
  }
  function markup(workspace=false){
    const s=C.session?.state, has=C.session;
    const isRecap=s && ['SCHOOL_RECAP','KNOWLEDGE_RECALL'].includes(s.phase);
    return `<div class="cc-shell ${workspace?'cc-with-workspace':''}"><section class="cc-conversation" aria-label="Mây và Kem">
      <h1 class="sr-only">Trò chuyện cùng Mây</h1>
      <nav class="cc-toolbar" aria-label="Lựa chọn trò chuyện"><button class="cc-tool" id="ccSchedule" aria-haspopup="dialog" aria-expanded="${C.schedule}">📅 Lịch học</button>
        <button class="cc-tool" id="ccReview">📚 Ôn tập</button><button class="cc-tool" id="ccActions" aria-expanded="${quickVisible()}" aria-controls="ccQuick">☰ Đổi hoạt động</button></nav>
      <div class="cc-feed">
        ${noSampleNotice()}
        ${C.loading?'<p role="status">Mây đang mở cuộc trò chuyện…</p>':''}
        ${history()}
        ${weekMatrix()}
        ${reviewCard()}
        ${proactiveCard()}
        ${C.error?`<div class="notice" role="alert">${esc(C.error)} ${!has?'<button class="btn" id="ccRetry">Thử kết nối lại</button>':''}</div>`:''}
        ${quickVisible()?`<div class="cc-quick" id="ccQuick" aria-label="Kem muốn làm gì?">${[['joy','😊 Có chuyện vui!'],['recap','📚 Kể chuyện học'],['direct','⭐ Kiếm thêm Sao'],['rest','😴 Hơi mệt rồi']].map(([type,label])=>`<button class="btn" data-cc-turn="${type}" ${C.busy||!has?'disabled':''}>${label}</button>`).join('')}</div>`:''}
        ${isRecap?`<section class="cc-recap" aria-label="Kể bài học"><div class="cc-recap-heading"><span>${s.selected?`Đang kể: <b>${esc(s.selected.label)}</b>`:'Kem muốn kể môn nào?'} </span><button class="cc-tool" id="ccChangeSubject">Đổi môn</button></div>
          <div class="cc-recap-actions">${s.phase==='SCHOOL_RECAP'?'<button class="btn primary" id="ccRecapDone">Kể xong rồi</button>':''}<button class="btn ${s.phase==='KNOWLEDGE_RECALL'?'primary':''}" data-cc-turn="offer">Cùng ôn bài này</button><button class="cc-tool" data-cc-turn="skip">Bỏ qua</button></div></section>`:''}
        ${s?.phase==='REST'?'<button class="btn" data-cc-turn="resume">Mình muốn trò chuyện tiếp</button>':''}
        ${card()&&!workspace?`<div class="cc-resume"><p>Bài đang làm vẫn ở đây.</p><button class="btn primary" id="ccResume">▶ Tiếp tục bài</button><button class="cc-tool" data-cc-turn="reflection">Quay lại trò chuyện</button></div>`:''}
        ${C.picker||s?.phase==='MISSION_OFFER'?activities():''}
      </div>
      <div class="cc-compose"><label class="sr-only" for="ccBox">Nhắn cho Mây</label><textarea id="ccBox" rows="1" maxlength="1500" placeholder="Kể Mây nghe nhé…" ${C.busy?'readonly':''}>${esc(C.draft)}</textarea>
        <button class="btn" id="ccMic" aria-label="Nói với Mây" aria-pressed="false" ${C.busy||!has?'disabled':''}>🎤</button><button class="btn primary" id="ccSend" ${C.busy||!has?'disabled':''}>Gửi</button>
        <p id="ccMicStatus" class="muted" role="status">${navigator.onLine?'':'Đang mất mạng. Nội dung đang nhập được giữ trên máy; kết nối lại để gửi nhé.'}</p></div>
      </section>${workspace?'<section id="ccWorkspace" class="cc-workspace" aria-label="Vùng làm bài"><div class="cc-workspace-tools"><button class="btn" id="ccExpand" aria-pressed="false">⛶ Mở rộng</button><button class="btn" id="ccPause">⏸ Tạm dừng</button><button class="btn" id="ccReflect">Quay lại trò chuyện</button></div><div id="ccActivity"></div></section>':''}${scheduleDialog()}</div>`;
  }
  function syncViewport(){
    document.documentElement.style.setProperty('--cc-visible-height',`${window.visualViewport?.height||window.innerHeight}px`);
    const header=document.querySelector('.bar');
    if(header) document.documentElement.style.setProperty('--cc-header-height',`${header.getBoundingClientRect().bottom}px`);
    const feed=app.querySelector('.cc-feed');
    if(C.atBottom && feed)requestAnimationFrame(()=>{if(feed.isConnected)feed.scrollTop=feed.scrollHeight;});
  }
  function chrome(workspace=false){
    document.body.classList.add('chat-first');
    document.body.classList.toggle('cc-home',!workspace);
    document.querySelector('.bar .title').textContent=`Mây & ${nick()==='bạn'?'Kem':nick()}`;
    syncViewport();
  }
  function resizeBox(){
    const box=document.getElementById('ccBox');
    if(box){box.style.height='auto';box.style.height=Math.min(box.scrollHeight,116)+'px';}
  }
  function restoreFeed(){
    const feed=app.querySelector('.cc-feed');if(!feed)return;
    const newest=C.scrollTop===null || C.atBottom || C.feedRevision!==C.session?.revision;
    const top=C.scrollTop;
    C.feedRevision=C.session?.revision;
    requestAnimationFrame(()=>{if(feed.isConnected)feed.scrollTop=newest?feed.scrollHeight:top;});
    feed.addEventListener('scroll',()=>{C.scrollTop=feed.scrollTop;C.atBottom=feed.scrollHeight-feed.clientHeight-feed.scrollTop<32;},{passive:true});
  }
  function wire(){
    const on=(id,fn)=>{const e=document.getElementById(id);if(e)e.onclick=fn;};
    document.querySelectorAll('[data-cc-turn]').forEach(b=>b.onclick=async()=>{
      const type=b.dataset.ccTurn;
      if(type==='rest'){ stopMic(); stopVoice(); }
      if(await turn(type)) { if(type==='direct'||type==='rest') go('home'); }
    });
    document.querySelectorAll('[data-cc-subject]').forEach(b=>b.onclick=()=>turn('subject',{subject_key:b.dataset.ccSubject}));
    document.querySelectorAll('[data-cc-pick]').forEach(b=>b.onclick=()=>{ C.activitySubject=b.dataset.ccPick; render(); });
    document.querySelectorAll('[data-cc-start]').forEach(b=>b.onclick=()=>start(b.dataset.ccStart,b.dataset.subject));
    document.querySelectorAll('[data-cc-say]').forEach(b=>b.onclick=()=>speak(C.session.messages.find(m=>String(m.id)===b.dataset.ccSay)?.content||'', 'vi'));
    on('ccRecapDone',()=>C.draft.trim()?turn():turn('offer'));
    on('ccSend',()=>turn()); on('ccRetry',load); on('ccResume',resume);
    const openSchedule=()=>{C.schedule=true;render();};
    on('ccSchedule',()=>openWeek(weekMonday(C.context?.day||new Date().toISOString().slice(0,10))));
    on('ccChangeSubject',openSchedule);
    on('ccWeekClose',()=>{C.weekStart=null;C.weekData=null;render();});
    on('ccProactiveDismiss',()=>{C.proactiveDismissed=true;render();});
    on('ccProactiveMore',openReview);
    document.querySelectorAll('[data-cc-suggest-start]').forEach(b=>b.onclick=async()=>{
      const x=C.reviewData?.items.find(v=>v.key===b.dataset.ccSuggestStart);
      if(!x)return;C.proactiveDismissed=true;await start('quiz',x.subject,x.topic);
    });
    document.querySelectorAll('[data-cc-suggest-confirm]').forEach(b=>b.onclick=async()=>{
      const x=C.reviewData?.awaiting_confirmation.find(v=>v.key===b.dataset.ccSuggestConfirm);
      if(!x)return;
      try{
        const d=await api('/api/companion/confirm_lesson',{day:x.source_day,period:x.period,status:'taught'});
        C.reviewData=d.plan;C.proactiveDismissed=true;
        await start('quiz',x.subject,x.topic);
      }catch(e){C.error=copyFor(e.code)||'Chưa xác nhận được bài học';render();}
    });
    on('ccReview',openReview);
    on('ccReviewClose',()=>{C.reviewOpen=false;render();});
    on('ccOpenMistakes',()=>{C.reviewOpen=false;render();start('review');});
    document.querySelectorAll('[data-cc-confirm]').forEach(b=>b.onclick=async()=>{
      const x=C.reviewData?.awaiting_confirmation.find(v=>v.key===b.dataset.ccConfirm);
      if(!x||C.reviewBusy)return;
      C.reviewBusy=true;render();
      try{
        const answer=b.dataset.answer;
        const d=await api('/api/companion/confirm_lesson',{day:x.source_day,period:x.period,status:answer});
        C.reviewData=d.plan;
        if(answer==='taught'){
          C.reviewOpen=false;
          C.draft='';
          C.reviewBusy=false;
          render();
          await start('quiz',x.subject,x.topic);
          return;
        }
      }
      catch(e){C.error=copyFor(e.code)||'Không lưu được xác nhận';}
      finally{C.reviewBusy=false;render();}
    });
    document.querySelectorAll('[data-cc-review]').forEach(b=>b.onclick=async()=>{
      const x=C.reviewData?.items.find(v=>v.key===b.dataset.ccReview);
      if(!x || !x.subject)return;
      C.reviewOpen=false;
      C.draft='';
      render();
      await start('quiz',x.subject,x.topic);
    });
    document.querySelectorAll('[data-cc-cell]').forEach(b=>b.onclick=()=>{
      const x=C.weekData?.lessons.find(v=>v.day+'_'+v.period===b.dataset.ccCell);
      const el=document.getElementById('ccCellDetail');
      if(x&&el)el.innerHTML='<div class="cc-cell-detail"><b>'+esc(x.subject)+' · '+dateLabel(x.day)+' · Tiết '+x.period+'</b><p>'+esc(x.content||'Chưa có nội dung chi tiết')+'</p>'+(x.note?'<p>Dặn dò: '+esc(x.note)+'</p>':'')+'</div>';
    });
    on('ccActions',()=>{C.actions=!quickVisible();if(C.actions)C.atBottom=true;render();});
    const closeSchedule=()=>{C.schedule=false;render();document.getElementById('ccSchedule')?.focus();};
    on('ccScheduleClose',closeSchedule);
    const dialog=document.getElementById('ccScheduleDialog');
    if(dialog){
      dialog.onclose=()=>{if(dialog.isConnected){C.schedule=false;render();document.getElementById('ccSchedule')?.focus();}};
      dialog.onclick=e=>{if(e.target===dialog){const b=dialog.getBoundingClientRect();if(e.clientX<b.left||e.clientX>b.right||e.clientY<b.top||e.clientY>b.bottom)closeSchedule();}};
      if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
    }
    on('ccMic',mic);
    on('ccPause',()=>go('home'));
    on('ccReflect',async()=>{ if(await turn('reflection')){ C.fields={}; go('home'); saveDrafts(); } });
    on('ccExpand',()=>{const e=document.querySelector('.cc-shell');const expanded=e.classList.toggle('cc-expanded');document.getElementById('ccExpand').setAttribute('aria-pressed',String(expanded));});
    const box=document.getElementById('ccBox');
    if(box){box.oninput=()=>{C.draft=box.value;resizeBox();saveDrafts();};box.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();turn();}};}
  }
  function renderHome(){
    chrome();
    if(!C.session && !C.draft){
      try { const local=JSON.parse(localStorage.getItem('may_companion_draft')||'null');
        if(local?.expires>Date.now()) C.draft=String(local.draft||'');
        else localStorage.removeItem('may_companion_draft');
      } catch {}
    }
    stopMic();
    app.innerHTML=markup(); wire();
    resizeBox();restoreFeed();
    if(!C.session && !C.loading && !C.error && ready()) load();
  }
  function decorate(){
    if(!activeHere()) return;
    chrome(true);
    stopMic();
    const nodes=Array.from(app.childNodes);
    app.innerHTML=markup(true);
    const host=document.getElementById('ccActivity'); nodes.forEach(n=>host.appendChild(n)); wire();
    // Restore unsent text only; answers, grades, photos and audio are never restored from client storage.
    Object.entries(C.fields).forEach(([id,value])=>{
      const input=document.getElementById(id);
      if(input && host.contains(input) && ['TEXTAREA','INPUT'].includes(input.tagName) && !['file','password','checkbox','radio'].includes(input.type)){
        input.value=value; input.dispatchEvent(new Event('input',{bubbles:true}));
      }
    });
    C.mounted=true;
    resizeBox();restoreFeed();
  }
  async function start(view,subject,chosenTopic=''){
    if(C.busy) return;
    if(card() && !confirm('Bài đang làm vẫn có thể tiếp tục. Kem muốn mở hoạt động khác không?')) return;
    const actual=C.session.state.recaps.filter(r=>r.kind==='lesson_report'&&r.subject===subject).at(-1);
    const selected={view,subject,topic:chosenTopic ? chosenTopic.slice(0,80) : (actual?.text.slice(0,80)||'')};
    if(!await turn('learning',{card:selected})) return;
    C.fields={}; saveDrafts(); launch(card());
  }
  function launch(a){
    state.subject=a.subject;state.topic=a.topic||'';
    switch(a.view){
      case 'quiz': return startQuiz();
      case 'review': return startReview();
      case 'homework': return openHomework(a.subject);
      case 'write': return openWrite(a.subject);
      case 'read': return openRead();
      case 'listen': return openListen();
      case 'wp': return openWp();
      case 'tvw': return openTvw();
      case 'sci': return openSci();
      case 'speak': return openSpeak(null,a.topic);
      case 'ipa': return go('ipa');
      case 'chat': state.turns=[];state.convId=null;state.vidShown=null;return go('chat');
    }
  }
  async function resume(){
    if(C.busy||!card()) return;
    if(C.checkpoint && !await flushCheckpoint()){render();return;}
    const a=card();
    if(state.view==='home' && C.mounted && state[({quiz:'quiz',review:'quiz',write:'wr',read:'rd',listen:'ls',wp:'wp',speak:'sp',homework:'hw',chat:'turns',tvw:'tv',sci:'sci'})[a.view]]){
      state.subject=a.subject;state.topic=a.topic||'';return go(modeView(a.view));
    }
    C.busy=true;C.error='';render();
    try{
      const d=await api('/api/companion/activity',{session_id:C.session.id});
      state.subject=a.subject;state.topic=a.topic||'';
      if(d.quiz) state.quiz=d.quiz;
      else if(d.writing){d.writing.skew=d.writing.now-Math.floor(Date.now()/1000);state.wr={subject:a.subject,phase:'setup',prompt:d.writing.prompt,answers:d.writing.answers||[],photos:[],w:d.writing};}
      else if(d.speaking) state.sp=d.speaking;
      else if(d.chat){state.turns=d.chat.messages;state.convId=d.chat.id;state.vidShown=true;}
      else if(d.activity){
        const r=d.activity, key={read:'rd',listen:'ls',wp:'wp',tvw:'tv',sci:'sci'}[a.view];
        state[key]={r,level:r.level,phase:r.score!==null?'done':({read:'read',listen:'listen',wp:'work',tvw:'work',sci:'work'})[a.view],
          err:'',sel:r.answers||[],typed:'',plays:0,dplays:0,first:{},val:{},chk:{},pick:{},join:{},text:'',wordsSeen:false};
      } else if(a.view==='homework'&&a.ref){openHomework(a.subject);await loadHomeworkList(a.ref);return;}
      else {launch(a);return;}
      go(modeView(a.view));C.mounted=true;
    } catch(e){C.error=e.code==='activity_gone'?'Bài cũ không còn được lưu. Kem có thể quay lại trò chuyện và chọn bài khác.':copyFor(e.code);}
    finally {C.busy=false;render();}
  }
  async function afterApi(path,body,data){
    if(!enabled()||!C.session||!card()||path.startsWith('/api/companion/')) return;
    const refs={quiz:data.quiz_id,write:data.writing?.id,read:data.reading?.id,wp:data.wp?.id,
      listen:data.listening?.id,speak:data.session_id,homework:data.id,chat:data.conv_id,tvw:data.tvw?.id,sci:data.sci?.id};
    const ref=refs[card().view];
    if(!ref || card().ref===ref) return;
    C.checkpoint={session_id:C.session.id,revision:C.session.revision,view:card().view,ref};saveDrafts();
    await flushCheckpoint();
  }
  async function flushCheckpoint(){
    const cp=C.checkpoint;
    if(!cp)return true;
    if(cp.session_id!==C.session?.id || cp.revision!==C.session?.revision || cp.view!==card()?.view){C.checkpoint=null;saveDrafts();return true;}
    try{
      await api('/api/companion/checkpoint',cp);
      if(C.checkpoint===cp && cp.revision===C.session.revision){card().ref=cp.ref;C.checkpoint=null;C.error='';saveDrafts();}
      return true;
    }catch{
      C.error='Chưa lưu được vị trí bài trên máy chủ. Nội dung đang làm vẫn còn; kết nối lại rồi bấm Tiếp tục bài để lưu lại nhé.';
      return false;
    }
  }
  function stopMic(){
    const r=C.mic; C.mic=null;
    if(r){r.onend=null;r.onresult=null;try{r.abort();}catch{}}
  }
  function mic(){
    if(C.mic){C.mic.stop();return;}
    const status=document.getElementById('ccMicStatus');
    if(!SR){status.textContent='Trình duyệt chưa hỗ trợ nói trực tiếp. Kem có thể dùng micro trên bàn phím hoặc gõ nhé.';return;}
    stopVoice();
    const r=new SR(), box=document.getElementById('ccBox'), base=box.value.trim();let final='';
    C.mic=r;r.lang='vi-VN';r.interimResults=true;r.continuous=false;
    r.onstart=()=>{document.getElementById('ccMic')?.setAttribute('aria-pressed','true');status.textContent='Mây đang nghe. Nói xong Kem xem lại chữ rồi bấm Gửi nhé.';};
    r.onresult=e=>{let interim='';for(let i=e.resultIndex;i<e.results.length;i++){if(e.results[i].isFinal)final+=e.results[i][0].transcript;else interim+=e.results[i][0].transcript;}
      C.draft=[base,final+interim].filter(Boolean).join(' ').slice(0,1500);box.value=C.draft;resizeBox();saveDrafts();};
    r.onerror=e=>{status.textContent=e.error==='not-allowed'?'Micro chưa được cấp quyền. Nội dung đang gõ vẫn còn; Kem có thể tiếp tục gõ nhé.':'Mây chưa nghe rõ. Kem thử lại hoặc gõ nhé.';};
    r.onend=()=>{C.mic=null;document.getElementById('ccMic')?.setAttribute('aria-pressed','false');};
    try{r.start();}catch{C.mic=null;status.textContent='Micro chưa sẵn sàng. Kem gõ hoặc thử lại nhé.';}
  }
  function request(path,body){
    if(enabled() && C.session && card() && ['/api/read_start','/api/wp_start','/api/listen_start'].includes(path)) return {...body,companion_id:C.session.id};
    return body;
  }
  function beforeGo(view){
    const box=document.getElementById('ccBox');if(box) C.draft=box.value;
    document.body.classList.remove('cc-home');
    stopMic();saveDrafts();
    return enabled()&&card()&&view==='subject' ? 'home' : view;
  }
  document.addEventListener('input',e=>{
    const el=e.target;
    if(el.id && el.closest('#ccActivity') && ['TEXTAREA','INPUT'].includes(el.tagName) && !['file','password','checkbox','radio'].includes(el.type)){
      C.fields[el.id]=el.value.slice(0,1500);if(Object.keys(C.fields).length>30) delete C.fields[Object.keys(C.fields)[0]];saveDrafts();
    }
  });
  window.addEventListener('resize',syncViewport);
  window.visualViewport?.addEventListener('resize',syncViewport);
  window.addEventListener('pagehide',()=>{stopMic();saveDrafts();});
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden){stopMic();saveDrafts();return;}
    if(enabled() && C.session && !C.busy && !C.loading)load();
  });
  function clear(){stopMic();C.epoch++;C.actions=null;C.schedule=false;C.scrollTop=null;C.feedRevision=null;C.error='';C.busy=false;C.loading=false;C.session=null;C.context=null;C.draft='';C.fields={};C.pending=null;C.checkpoint=null;C.mounted=false;localStorage.removeItem('may_companion_draft');}
  return {enabled,render:renderHome,decorate,beforeGo,afterApi,request,clear,reload:load};
})();

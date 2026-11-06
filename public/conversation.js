/* Chat shell around the existing learning renderers. No grading or reward decisions here. */
const Companion = (() => {
  const C = {session:null, context:null, loading:false, busy:false, error:'', draft:'', pending:null,
    picker:false, schedule:false, activitySubject:null, mounted:false, mic:null, fields:{}, epoch:0, checkpoint:null};
  const activityViews = ['quiz','homework','write','read','listen','wp','speak','chat','check'];
  const enabled = () => !PARENT_MODE && me.chatFirst !== false;
  const uuid = () => globalThis.crypto?.randomUUID?.() || Date.now().toString(36)+'_'+Math.random().toString(36).slice(2)+'_request';
  const card = () => C.session?.state.active_card;
  const modeView = v => v === 'review' ? 'quiz' : v;
  const activeHere = () => enabled() && card() && activityViews.includes(state.view);
  function saveDrafts(){
    if(!C.session) return;
    try { localStorage.setItem('may_companion_draft', JSON.stringify({id:C.session.id, at:Date.now(),
      expires:Date.now()+C.context.retention_days*86400000, draft:C.draft.slice(0,1500), pending:C.pending, checkpoint:C.checkpoint, fields:C.fields, ref:card()?.ref||0, view:card()?.view||''})); } catch {}
  }
  function restoreDrafts(){
    try {
      const d=JSON.parse(localStorage.getItem('may_companion_draft')||'null');
      if(d?.id===C.session.id && Date.now()-d.at < C.context.retention_days*86400000){
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
  async function load(){
    if(C.loading || !ready()) return;
    C.loading=true; C.error=''; const epoch=C.epoch;
    try { const d=await api('/api/companion/session'); if(epoch!==C.epoch)return;accept(d); restoreDrafts(); }
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
    C.busy=true; C.error=''; stopMic(); stopVoice(); saveDrafts(); render();
    try {
      const d=await api('/api/companion/turn',request);
      if(epoch!==C.epoch)return false;accept(d);
      if(type==='text') C.draft='';
      C.pending=null; C.picker=false; saveDrafts();
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
  function history(){
    const messages=C.session?.messages||[];
    return `<div class="cc-history" role="log" aria-label="Trò chuyện với Mây" aria-live="polite" aria-relevant="additions text">${messages.map(m=>
      `<div class="msg ${m.role==='user'?'me':'buddy'}">${m.role==='user'?'':mascot()}<div class="bubble">${fmt(m.content)}${m.role==='assistant'?`<button class="speak" data-cc-say="${m.id}" aria-label="Đọc lời Mây">🔊</button>`:''}</div></div>`).join('')}
      ${C.busy?'<p role="status">Mây đang nghe và nghĩ cùng Kem…</p>':''}</div>`;
  }
  function subjectChoices(){
    const expected=C.context?.expected||[], catalog=C.context?.subjects||[];
    const keys=new Set(expected.map(x=>x.subject));
    return `<div class="chips">${expected.map(x=>`<button class="chip" data-cc-subject="${esc(x.key)}">${esc(x.label)}</button>`).join('')}
      ${catalog.filter(x=>!keys.has(x.id)).map(x=>`<button class="chip" data-cc-subject="${esc(x.id)}">${esc(x.name)}</button>`).join('')}</div>`;
  }
  function activities(){
    const subjects=C.context?.subjects||[];
    const selected=subjects.find(s=>s.id===C.activitySubject)||subjects.find(s=>s.id===C.session?.state.selected?.subject);
    const actual=C.session?.state.recaps.filter(r=>r.kind==='lesson_report' && r.subject===selected?.id).at(-1);
    return `<section class="cc-activities card" aria-label="Chọn hoạt động"><h3>Học cùng Mây từng chút một</h3>
      <p class="muted">Kem chọn bài cô giao, phần muốn ôn hoặc hoạt động mình thích nhé.</p>
      <div class="chips">${subjects.map(s=>`<button class="chip" data-cc-pick="${esc(s.id)}" aria-pressed="${selected?.id===s.id}">${esc(s.name)}</button>`).join('')}</div>
      ${selected?`<div class="cc-modes">${Object.entries(selected.modes).map(([view,label])=>`<button class="btn" data-cc-start="${view}" data-subject="${esc(selected.id)}">${esc(label)}</button>`).join('')}</div>
        ${actual?`<p class="muted">Theo điều Kem kể: ${esc(actual.text)}. Với bài dài, Kem có thể mở rộng vùng làm bài.</p>`:''}`:'<p>Chọn môn để xem hoạt động có sẵn.</p>'}
      <button class="btn" data-cc-start="review">📒 Ôn câu đã làm</button>
      <button class="btn" data-cc-turn="joy">Mình muốn trò chuyện tiếp</button></section>`;
  }
  function markup(workspace=false){
    const s=C.session?.state, has=C.session;
    const isRecap=s && ['SCHOOL_RECAP','KNOWLEDGE_RECALL'].includes(s.phase);
    return `<div class="cc-shell ${workspace?'cc-with-workspace':''}"><section class="cc-conversation" aria-label="Mây và Kem">
      <div class="cc-heading">${mascot()}<div><h1>Mây &amp; ${esc(nick()==='bạn'?'Kem':nick())}</h1><p>Mây đang ở đây với ${esc(nick()==='bạn'?'Kem':nick())}</p></div></div>
      ${noSampleNotice()}
      <div class="chips"><button class="chip" id="ccSchedule" aria-expanded="${C.schedule}">📅 Lịch học hôm nay</button><span class="chip">🌱 Học vui mỗi ngày</span></div>
      ${C.schedule?`<section class="card"><h3>Thời khóa biểu dự kiến</h3><p>${C.context?.day_off?'Hôm nay được đánh dấu là ngày nghỉ.':(C.context?.expected?.length?'Lịch có thể thay đổi; Kem cứ sửa lại cho Mây nhé.':'Chưa có môn dự kiến hôm nay. Kem có thể nghỉ, kể bài hoặc chọn hoạt động mình thích.')}</p>${subjectChoices()}</section>`:''}
      ${C.loading?'<p role="status">Mây đang mở cuộc trò chuyện…</p>':''}
      ${history()}
      ${C.error?`<div class="notice" role="alert">${esc(C.error)} ${!has?'<button class="btn" id="ccRetry">Thử kết nối lại</button>':''}</div>`:''}
      <div class="cc-quick" aria-label="Kem muốn làm gì?">${[['joy','😊 Có chuyện vui!'],['recap','📚 Kể chuyện học'],['direct','✏️ Làm bài luôn'],['rest','😴 Hơi mệt rồi']].map(([type,label])=>`<button class="btn" data-cc-turn="${type}" ${C.busy||!has?'disabled':''}>${label}</button>`).join('')}</div>
      ${isRecap?`<section class="card"><h3>Lịch dự kiến · Kể chuyện học</h3><p>Kem có thể kể bằng lời của mình, đổi môn hoặc bỏ qua.</p>${subjectChoices()}<button class="btn" id="ccRecapDone">Kể xong rồi</button> <button class="btn" data-cc-turn="offer">Cùng ôn bài này</button> <button class="btn" data-cc-turn="skip">Bỏ qua</button></section>`:''}
      ${s?.phase==='REST'?'<button class="btn" data-cc-turn="resume">Mình muốn trò chuyện tiếp</button>':''}
      ${card()&&!workspace?`<div class="card"><p>Bài đang làm vẫn ở đây.</p><button class="btn primary" id="ccResume">▶ Tiếp tục bài</button><button class="btn" data-cc-turn="reflection">Quay lại trò chuyện</button></div>`:''}
      ${C.picker||s?.phase==='MISSION_OFFER'?activities():''}
      <div class="cc-compose"><label class="sr-only" for="ccBox">Nhắn cho Mây</label><textarea id="ccBox" rows="2" maxlength="1500" placeholder="Kể Mây nghe nhé…" ${C.busy?'readonly':''}>${esc(C.draft)}</textarea>
        <button class="btn" id="ccMic" aria-label="Nói với Mây" aria-pressed="false" ${C.busy||!has?'disabled':''}>🎤</button><button class="btn primary" id="ccSend" ${C.busy||!has?'disabled':''}>Gửi</button>
        <p id="ccMicStatus" class="muted" role="status">${navigator.onLine?'':'Đang mất mạng. Nội dung đang nhập được giữ trên máy; kết nối lại để gửi nhé.'}</p></div>
      </section>${workspace?'<section id="ccWorkspace" class="cc-workspace" aria-label="Vùng làm bài"><div class="cc-workspace-tools"><button class="btn" id="ccExpand" aria-pressed="false">⛶ Mở rộng</button><button class="btn" id="ccPause">⏸ Tạm dừng</button><button class="btn" id="ccReflect">Quay lại trò chuyện</button></div><div id="ccActivity"></div></section>':''}</div>`;
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
    on('ccSchedule',()=>{ C.schedule=!C.schedule; render(); });
    on('ccMic',mic);
    on('ccPause',()=>go('home'));
    on('ccReflect',async()=>{ if(await turn('reflection')){ C.fields={}; go('home'); saveDrafts(); } });
    on('ccExpand',()=>{const e=document.querySelector('.cc-shell');const expanded=e.classList.toggle('cc-expanded');document.getElementById('ccExpand').setAttribute('aria-pressed',String(expanded));});
    const box=document.getElementById('ccBox');
    if(box){box.oninput=()=>{C.draft=box.value;saveDrafts();};box.onkeydown=e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();turn();}};}
  }
  function renderHome(){
    document.body.classList.add("chat-first");
    if(!C.session && !C.draft){
      try { const local=JSON.parse(localStorage.getItem('may_companion_draft')||'null');
        if(local?.expires>Date.now()) C.draft=String(local.draft||'');
        else localStorage.removeItem('may_companion_draft');
      } catch {}
    }
    stopMic();
    app.innerHTML=markup(); wire();
    const log=app.querySelector('.cc-history'); if(log) log.scrollTop=log.scrollHeight;
    if(!C.session && !C.loading && !C.error && ready()) load();
  }
  function decorate(){
    if(!activeHere()) return;
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
    const log=app.querySelector('.cc-history');if(log)log.scrollTop=log.scrollHeight;
  }
  async function start(view,subject){
    if(C.busy) return;
    if(card() && !confirm('Bài đang làm vẫn có thể tiếp tục. Kem muốn mở hoạt động khác không?')) return;
    const actual=C.session.state.recaps.filter(r=>r.kind==='lesson_report'&&r.subject===subject).at(-1);
    const selected={view,subject,topic:actual?.text.slice(0,80)||''};
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
      case 'speak': return openSpeak(null,a.topic);
      case 'chat': state.turns=[];state.convId=null;state.vidShown=null;return go('chat');
    }
  }
  async function resume(){
    if(C.busy||!card()) return;
    if(C.checkpoint && !await flushCheckpoint()){render();return;}
    const a=card();
    if(state.view==='home' && C.mounted && state[({quiz:'quiz',review:'quiz',write:'wr',read:'rd',listen:'ls',wp:'wp',speak:'sp',homework:'hw',chat:'turns'})[a.view]]){
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
        const r=d.activity, key={read:'rd',listen:'ls',wp:'wp'}[a.view];
        state[key]={r,level:r.level,phase:r.score!==null?'done':({read:'read',listen:'listen',wp:'work'})[a.view],
          err:'',sel:r.answers||[],typed:'',plays:0,dplays:0,first:{},val:{},wordsSeen:false};
      } else if(a.view==='homework'&&a.ref){openHomework(a.subject);await loadHomeworkList(a.ref);return;}
      else {launch(a);return;}
      go(modeView(a.view));C.mounted=true;
    } catch(e){C.error=e.code==='activity_gone'?'Bài cũ không còn được lưu. Kem có thể quay lại trò chuyện và chọn bài khác.':copyFor(e.code);}
    finally {C.busy=false;render();}
  }
  async function afterApi(path,body,data){
    if(!enabled()||!C.session||!card()||path.startsWith('/api/companion/')) return;
    const refs={quiz:data.quiz_id,write:data.writing?.id,read:data.reading?.id,wp:data.wp?.id,
      listen:data.listening?.id,speak:data.session_id,homework:data.id,chat:data.conv_id};
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
      C.draft=[base,final+interim].filter(Boolean).join(' ').slice(0,1500);box.value=C.draft;saveDrafts();};
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
    stopMic();saveDrafts();
    return enabled()&&card()&&view==='subject' ? 'home' : view;
  }
  document.addEventListener('input',e=>{
    const el=e.target;
    if(el.id && el.closest('#ccActivity') && ['TEXTAREA','INPUT'].includes(el.tagName) && !['file','password','checkbox','radio'].includes(el.type)){
      C.fields[el.id]=el.value.slice(0,1500);if(Object.keys(C.fields).length>30) delete C.fields[Object.keys(C.fields)[0]];saveDrafts();
    }
  });
  window.addEventListener('pagehide',()=>{stopMic();saveDrafts();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden){stopMic();saveDrafts();}});
  function clear(){stopMic();C.epoch++;C.error='';C.busy=false;C.loading=false;C.session=null;C.context=null;C.draft='';C.fields={};C.pending=null;C.checkpoint=null;C.mounted=false;localStorage.removeItem('may_companion_draft');}
  return {enabled,render:renderHome,decorate,beforeGo,afterApi,request,clear,reload:load};
})();

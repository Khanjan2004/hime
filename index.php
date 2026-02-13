<?php
session_start();
$config = require __DIR__ . '/config.php';
$adminCode = $config['ADMIN_PANEL_CODE'] ?? 'change_this_secret_code';

if (isset($_GET['api']) && $_GET['api'] === 'admin-login') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim((string)($input['code'] ?? ''));

    if ($code !== '' && hash_equals($adminCode, $code)) {
        $_SESSION['admin_ok'] = true;
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Invalid admin code']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="uz" class="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Vocabulary Quiz System (PHP)</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class' };</script>
  <style>
    .card{border:1px solid #334155;background:rgba(15,23,42,.85);border-radius:16px;padding:16px}
    .btn{padding:10px 14px;border-radius:10px;font-weight:700}
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 text-slate-100 p-4">
  <div class="max-w-6xl mx-auto">
    <h1 class="text-3xl md:text-4xl font-extrabold text-indigo-300 mb-2">Vocabulary Quiz Testing</h1>
    <p class="text-slate-300 mb-6">PHP hosting uchun 1 ta asosiy fayl + 1 ta kichik config fayl</p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <button data-open="uploadPanel" class="md:col-span-2 text-left rounded-2xl p-7 bg-gradient-to-r from-indigo-600 to-purple-600">
        <div class="text-2xl font-bold">📤 User Upload Test</div>
        <div class="text-indigo-100">Adminsiz test yuklab tez boshlash</div>
      </button>
      <button data-open="codePanel" class="text-left rounded-2xl p-6 bg-gradient-to-r from-pink-600 to-violet-700">
        <div class="text-xl font-semibold">🎓 Student Test by Code</div>
        <div class="text-pink-100 text-sm">Ism + kod majburiy</div>
      </button>
      <button data-open="contactPanel" class="text-left rounded-2xl p-6 bg-slate-800 border border-slate-700">📬 Contact</button>
      <button data-open="adminPanel" class="text-left rounded-2xl p-6 bg-slate-800 border border-red-700">🛡️ Admin Panel</button>
      <button id="themeBtn" class="text-left rounded-2xl p-6 bg-slate-800 border border-slate-700">🌙 / ☀️ Theme</button>
    </div>

    <section id="uploadPanel" class="panel hidden card mb-4">
      <h2 class="text-xl font-bold text-indigo-300 mb-2">Upload test</h2>
      <textarea id="uploadText" class="w-full h-40 p-3 rounded bg-slate-900 border border-slate-700" placeholder="hello - salom\nwater - suv"></textarea>
      <button id="startUploadBtn" class="btn bg-indigo-600 mt-2">Start uploaded test</button>
    </section>

    <section id="codePanel" class="panel hidden card mb-4">
      <h2 class="text-xl font-bold text-pink-300 mb-2">Code-based test</h2>
      <div class="grid md:grid-cols-3 gap-2">
        <input id="studentName" class="p-3 rounded bg-slate-900 border border-slate-700" placeholder="Student name" />
        <input id="studentCode" class="p-3 rounded bg-slate-900 border border-slate-700 uppercase" placeholder="Test code" />
        <button id="startCodeBtn" class="btn bg-pink-600">Start</button>
      </div>
    </section>

    <section id="contactPanel" class="panel hidden card mb-4">
      <h2 class="text-xl font-bold mb-2">Coder contact</h2>
      <div class="flex flex-wrap gap-2">
        <a class="btn bg-sky-600" href="https://t.me/XOSHIMJONOVUBAYDULLO" target="_blank">Telegram</a>
        <a class="btn bg-slate-700" href="https://github.com/Khanjan2004" target="_blank">GitHub</a>
        <a class="btn bg-emerald-700" href="mailto:ubaydulloxoshimjonov@gmail.com">Email</a>
      </div>
    </section>

    <section id="adminPanel" class="panel hidden card mb-4">
      <h2 class="text-xl font-bold text-red-300 mb-2">Admin panel</h2>
      <div id="adminLoginArea">
        <div class="grid md:grid-cols-2 gap-2">
          <input id="adminCodeInput" type="password" class="p-3 rounded bg-slate-900 border border-slate-700" placeholder="Admin secret code" />
          <button id="adminLoginBtn" class="btn bg-red-700">Login</button>
        </div>
        <p class="text-xs text-slate-400 mt-1">Secret `config.php` ichida saqlanadi, frontendda yo'q.</p>
      </div>
      <div id="adminBody" class="hidden mt-3">
        <div class="grid md:grid-cols-2 gap-2 mb-2">
          <input id="testName" class="p-2 rounded bg-slate-900 border border-slate-700" placeholder="Test name" />
          <input id="testCode" class="p-2 rounded bg-slate-900 border border-slate-700 uppercase" placeholder="Test code" />
          <input id="testTimer" type="number" class="p-2 rounded bg-slate-900 border border-slate-700" placeholder="Timer minute (optional)" />
          <select id="testMode" class="p-2 rounded bg-slate-900 border border-slate-700">
            <option value="eng-uzb">ENG → UZB</option>
            <option value="uzb-eng">UZB → ENG</option>
          </select>
        </div>
        <textarea id="testWords" class="w-full h-32 p-3 rounded bg-slate-900 border border-slate-700" placeholder="word - tarjima"></textarea>
        <button id="createTestBtn" class="btn bg-emerald-700 mt-2">Save test</button>
        <h3 class="mt-4 mb-2 font-bold">Statistics board</h3>
        <div id="testsBoard" class="space-y-2"></div>
      </div>
    </section>

    <section id="quizSection" class="hidden card">
      <div class="flex justify-between mb-2">
        <h2 id="quizTitle" class="text-xl font-bold"></h2>
        <div id="timerText" class="text-amber-300"></div>
      </div>
      <div id="quizProgress" class="text-slate-300 mb-2"></div>
      <div id="quizQuestion" class="text-3xl font-extrabold mb-3"></div>
      <input id="quizAnswer" class="w-full p-3 rounded bg-slate-900 border border-slate-700 mb-2" placeholder="Answer" />
      <div class="flex gap-2">
        <button id="submitAnswerBtn" class="btn bg-indigo-600">Check</button>
        <button id="nextQuestionBtn" class="btn bg-slate-700 hidden">Next</button>
        <button id="finishQuizBtn" class="btn bg-red-700">Finish</button>
      </div>
      <div id="quizFeedback" class="mt-2"></div>
    </section>
  </div>

<script>
const STORE_KEY='vocab-quiz-php-v1';
const state={tests:[],quiz:null,theme:localStorage.getItem('theme')||'dark'};
const el=id=>document.getElementById(id);

function parseWords(text){
  return text.split('\n').map(s=>s.trim()).filter(Boolean).map(line=>{
    const cleaned=line.replace(/^\d+[\).\-\s]*/,'');
    const [a,...rest]=cleaned.split(/\s*[-:;|=–—]\s*/);
    const b=rest.join(' - ');
    return a&&b?{eng:a.trim(),uzb:b.trim()}:null;
  }).filter(Boolean);
}
function save(){localStorage.setItem(STORE_KEY,JSON.stringify({tests:state.tests}));}
function load(){const d=JSON.parse(localStorage.getItem(STORE_KEY)||'{}');state.tests=d.tests||[];}
function level(p){if(p<50)return 'MIN';if(p<80)return 'NORMAL';return 'MAX';}
function showPanel(id){document.querySelectorAll('.panel').forEach(p=>p.classList.add('hidden'));el(id).classList.remove('hidden');}

function renderBoard(){
  el('testsBoard').innerHTML=state.tests.map(t=>{
    const at=(t.results||[]).flatMap(r=>r.attempts||[]);
    const avg=at.length?Math.round(at.reduce((s,a)=>s+a.percent,0)/at.length):0;
    return `<div class='p-3 rounded bg-slate-900 border border-slate-700'>
      <div class='flex justify-between flex-wrap gap-2'>
        <div><b>${t.name}</b><div class='text-xs text-slate-400'>Code: ${t.code} | ${t.mode==='eng-uzb'?'ENG→UZB':'UZB→ENG'} | ${t.open?'OPEN':'CLOSED'} | Timer: ${t.timerMin?t.timerMin+'m':'No limit'}</div></div>
        <div class='flex gap-1'>
          <button class='btn bg-slate-700' onclick="toggleOpen('${t.id}')">${t.open?'Close':'Open'}</button>
          <button class='btn bg-indigo-700' onclick="switchMode('${t.id}')">ENG/UZB</button>
        </div>
      </div>
      <div class='text-sm mt-1'>students ${t.results?.length||0}, attempts ${at.length}, avg ${avg}% (${level(avg)})</div>
    </div>`;
  }).join('')||"<div class='text-slate-400'>No tests yet</div>";
}
window.toggleOpen=id=>{const t=state.tests.find(x=>x.id===id);if(!t)return;t.open=!t.open;save();renderBoard();};
window.switchMode=id=>{const t=state.tests.find(x=>x.id===id);if(!t)return;t.mode=t.mode==='eng-uzb'?'uzb-eng':'eng-uzb';save();renderBoard();};

function startQuiz({name,words,mode,timerMin,code,studentName,testId}){
  state.quiz={name,words,mode,idx:0,correct:0,wrong:0,timerMin,startAt:Date.now(),code,studentName,testId,done:false};
  el('quizSection').classList.remove('hidden');
  renderQuiz();
}
function renderQuiz(){
  const q=state.quiz;if(!q)return;
  const w=q.words[q.idx];
  const question=q.mode==='eng-uzb'?w.eng:w.uzb;
  const right=q.mode==='eng-uzb'?w.uzb:w.eng;
  el('quizTitle').textContent=`${q.name} (${q.mode==='eng-uzb'?'ENG→UZB':'UZB→ENG'})`;
  el('quizProgress').textContent=`${q.idx+1}/${q.words.length} | ✅${q.correct} ❌${q.wrong}`;
  el('quizQuestion').textContent=question;
  const ms=q.timerMin?Math.max(0,q.timerMin*60000-(Date.now()-q.startAt)):null;
  el('timerText').textContent=ms===null?'No time limit':`Remaining ${Math.ceil(ms/1000)}s`;
  if(ms===0&&!q.done) finishQuiz();

  el('submitAnswerBtn').onclick=()=>{
    const ok=el('quizAnswer').value.trim().toLowerCase()===right.trim().toLowerCase();
    if(ok) q.correct++; else q.wrong++;
    el('quizFeedback').innerHTML=ok?"<div class='text-emerald-300'>Correct ✅</div>":`<div class='text-red-300'>Wrong ❌ | ${right}</div>`;
    el('nextQuestionBtn').classList.remove('hidden');
  };
  el('nextQuestionBtn').onclick=()=>{
    el('quizAnswer').value='';el('quizFeedback').innerHTML='';el('nextQuestionBtn').classList.add('hidden');
    if(q.idx<q.words.length-1){q.idx++;renderQuiz();}else finishQuiz();
  };
  el('finishQuizBtn').onclick=()=>finishQuiz();
}
function finishQuiz(){
  const q=state.quiz;if(!q||q.done)return;q.done=true;
  const pct=Math.round((q.correct/q.words.length)*100);
  el('quizFeedback').innerHTML=`<div class='p-3 rounded bg-slate-900 border border-slate-600'>Finished: ${q.correct}/${q.words.length} (${pct}%) - ${level(pct)}</div>`;
  if(q.testId&&q.code){
    const t=state.tests.find(x=>x.id===q.testId);
    if(t){
      t.results=t.results||[];
      let st=t.results.find(r=>r.name===q.studentName);
      if(!st){st={name:q.studentName,code:q.code,attempts:[]};t.results.push(st);}
      st.attempts.push({percent:pct,correct:q.correct,total:q.words.length,at:new Date().toLocaleString('uz-UZ')});
      save();renderBoard();
    }
  }
}
setInterval(()=>{if(state.quiz&&!state.quiz.done)renderQuiz();},1000);

document.querySelectorAll('[data-open]').forEach(b=>b.onclick=()=>showPanel(b.dataset.open));
el('startUploadBtn').onclick=()=>{
  const words=parseWords(el('uploadText').value);
  if(!words.length) return alert('Format: eng - uzb');
  startQuiz({name:'Uploaded test',words,mode:'eng-uzb'});
};
el('startCodeBtn').onclick=()=>{
  const studentName=el('studentName').value.trim();
  const code=el('studentCode').value.trim().toUpperCase();
  if(!studentName) return alert('Name required');
  if(!code) return alert('Code required');
  const t=state.tests.find(x=>x.code===code);
  if(!t) return alert('Test topilmadi');
  if(!t.open) return alert('Test yopiq');
  startQuiz({name:t.name,words:t.words,mode:t.mode,timerMin:t.timerMin,code:t.code,studentName,testId:t.id});
};
el('adminLoginBtn').onclick=async()=>{
  const code=el('adminCodeInput').value;
  const r=await fetch('?api=admin-login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})});
  if(!r.ok) return alert('Admin code xato');
  el('adminLoginArea').classList.add('hidden');
  el('adminBody').classList.remove('hidden');
};
el('createTestBtn').onclick=()=>{
  const name=el('testName').value.trim();
  const code=el('testCode').value.trim().toUpperCase();
  const timerMin=Number(el('testTimer').value||0);
  const mode=el('testMode').value;
  const words=parseWords(el('testWords').value);
  if(!name||!code||!words.length) return alert('Name, code, words kerak');
  if(state.tests.some(t=>t.code===code)) return alert('Code band');
  state.tests.push({id:Math.random().toString(36).slice(2),name,code,timerMin:timerMin>0?timerMin:null,mode,open:true,words,results:[]});
  save();renderBoard();['testName','testCode','testTimer','testWords'].forEach(i=>el(i).value='');
};
el('themeBtn').onclick=()=>{state.theme=state.theme==='dark'?'light':'dark';document.documentElement.classList.toggle('dark',state.theme==='dark');localStorage.setItem('theme',state.theme)};

load();document.documentElement.classList.toggle('dark',state.theme==='dark');renderBoard();
</script>
</body>
</html>

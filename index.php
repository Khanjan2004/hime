<?php
session_start();
$config = require __DIR__ . '/config.php';
$adminCode = (string)($config['ADMIN_PANEL_CODE'] ?? 'change_this_secret_code');

if (isset($_GET['api']) && $_GET['api'] === 'admin-login') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim((string)($input['code'] ?? ''));

    if ($code !== '' && hash_equals($adminCode, $code)) {
        $_SESSION['admin_ok'] = true;
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Vocabulary Platform</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config = { darkMode: 'class' }</script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 via-blue-50 to-indigo-50 dark:from-gray-900 dark:via-indigo-950 dark:to-purple-950 p-4 transition-all duration-500">
<div id="app"></div>
<script>
const STORE_KEY = 'vocab-platform-pro-v3';
const MESSAGES = ["Ajoyib! 🎉", "Zo'r! 💪", "A'lo! ⭐", "Davom eting! 🚀", "Mukammal! 🌟"];

let state = {
  theme: 'dark',
  modal: null,
  adminModal: false,
  adminLogged: false,
  adminCodeInput: '',

  adminTests: [],
  userUploadText: '',

  quiz: null,
  quizAnswer: '',
  quizFeedback: null,
  autoNextAt: null,

  codeName: '',
  codeValue: '',

  adminForm: { name: '', code: '', timer: '', mode: 'english-uzbek', words: '' },
  viewingStatsTestId: null
};

function saveState() {
  localStorage.setItem(STORE_KEY, JSON.stringify({
    theme: state.theme,
    adminTests: state.adminTests
  }));
}

function loadState() {
  try {
    const data = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
    state.theme = data.theme || 'dark';
    state.adminTests = data.adminTests || [];
  } catch (_) {}
  applyTheme();
  render();
}

function applyTheme() {
  document.documentElement.classList.toggle('dark', state.theme === 'dark');
}

function parseVocabulary(text) {
  const lines = text.trim().split('\n');
  const out = [];
  for (let line of lines) {
    line = line.replace(/^\d+[\.)\]\s•*-]*/, '').trim();
    if (!line) continue;
    const parts = line.split(/\s*[-–—:;|=]\s*/);
    if (parts.length >= 2) {
      const english = parts[0].trim();
      const uzbek = parts.slice(1).join(' - ').trim();
      if (english && uzbek) out.push({ english, uzbek });
    }
  }
  return out;
}

function levelByPercent(p) {
  if (p < 50) return 'MIN';
  if (p < 80) return 'NORMAL';
  return 'TOP';
}

function groupStats(results) {
  const latest = (results || []).map(r => r.scores && r.scores.length ? r.scores[r.scores.length - 1] : null).filter(Boolean);
  return {
    top: latest.filter(x => x.percentage >= 80).length,
    normal: latest.filter(x => x.percentage >= 50 && x.percentage < 80).length,
    min: latest.filter(x => x.percentage < 50).length
  };
}

function totals(results) {
  let attempts = 0, correct = 0, wrong = 0;
  for (const user of (results || [])) {
    for (const score of (user.scores || [])) {
      attempts++;
      correct += Number(score.correct || 0);
      wrong += Number((score.total || 0) - (score.correct || 0));
    }
  }
  return { attempts, correct, wrong };
}

function getQuestionAnswer(quiz) {
  const word = quiz.words[quiz.index];
  const question = quiz.mode === 'english-uzbek' ? word.english : word.uzbek;
  const answer = quiz.mode === 'english-uzbek' ? word.uzbek : word.english;
  return { question, answer };
}

function openModal(name) {
  state.modal = name;
  render();
}

function closeModal() {
  state.modal = null;
  render();
}

function closeAdminModal() {
  state.adminModal = false;
  state.viewingStatsTestId = null;
  render();
}

function startQuiz({ words, mode, timerMin = null, source = 'upload', testId = null, username = '' }) {
  if (!words || !words.length) return alert('So\'zlar topilmadi');
  const shuffled = [...words].sort(() => Math.random() - 0.5);
  state.quiz = {
    source,
    testId,
    username,
    words: shuffled,
    mode,
    timerMin,
    startedAt: Date.now(),
    index: 0,
    score: { correct: 0, wrong: 0 },
    done: false
  };
  state.quizAnswer = '';
  state.quizFeedback = null;
  state.autoNextAt = null;
  closeModal();
  render();
}

function submitAnswerAndAutoNext() {
  if (!state.quiz || state.quiz.done) return;
  const qa = getQuestionAnswer(state.quiz);
  const typed = state.quizAnswer.trim().toLowerCase();
  const ok = typed === qa.answer.trim().toLowerCase();

  if (ok) {
    state.quiz.score.correct++;
    confetti({ particleCount: 90, spread: 75, origin: { y: 0.62 }, colors: ['#4F46E5', '#7C3AED', '#EC4899'] });
  } else {
    state.quiz.score.wrong++;
  }

  state.quizFeedback = {
    ok,
    answer: qa.answer,
    msg: ok ? MESSAGES[Math.floor(Math.random() * MESSAGES.length)] : (typed ? 'Noto\'g\'ri ❌' : 'Javob kiritilmadi ❌')
  };

  const isLast = state.quiz.index >= state.quiz.words.length - 1;
  if (isLast) {
    finishQuiz();
    return;
  }

  state.autoNextAt = Date.now() + 700;
  render();
}

function tickAutoNext() {
  if (!state.quiz || state.quiz.done || !state.autoNextAt) return;
  if (Date.now() >= state.autoNextAt) {
    state.autoNextAt = null;
    state.quiz.index++;
    state.quizAnswer = '';
    state.quizFeedback = null;
    render();
  }
}

function finishQuiz() {
  if (!state.quiz || state.quiz.done) return;
  state.quiz.done = true;
  const total = state.quiz.words.length;
  const correct = state.quiz.score.correct;
  const percentage = Math.round((correct / total) * 100);

  if (state.quiz.source === 'coded' && state.quiz.testId) {
    const test = state.adminTests.find(t => t.id === state.quiz.testId);
    if (test) {
      test.results = test.results || [];
      let user = test.results.find(u => u.username === state.quiz.username);
      if (!user) {
        user = { username: state.quiz.username, attempts: 0, scores: [] };
        test.results.push(user);
      }
      user.attempts += 1;
      user.scores.push({
        correct,
        total,
        percentage,
        date: new Date().toLocaleString('uz-UZ')
      });
      saveState();
    }
  }

  render();
}

function exitQuizToHome() {
  state.quiz = null;
  state.quizAnswer = '';
  state.quizFeedback = null;
  state.autoNextAt = null;
  render();
}

function loginAdmin() {
  fetch('?api=admin-login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code: state.adminCodeInput })
  }).then(r => {
    if (!r.ok) throw new Error('bad');
    state.adminLogged = true;
    state.adminCodeInput = '';
    state.adminModal = true;
    state.modal = null;
    render();
  }).catch(() => alert('Admin kod noto\'g\'ri'));
}

function createAdminTest() {
  const name = state.adminForm.name.trim();
  const code = state.adminForm.code.trim().toUpperCase();
  const timerRaw = Number(state.adminForm.timer || 0);
  const timerMin = timerRaw > 0 ? timerRaw : null;
  const words = parseVocabulary(state.adminForm.words);

  if (!name || !code || !words.length) return alert('Nom, kod, so\'zlar kerak');
  if (state.adminTests.some(t => t.code === code)) return alert('Bu kod band');

  state.adminTests.push({
    id: String(Date.now()),
    name,
    code,
    words,
    mode: state.adminForm.mode,
    timerMin,
    isActive: true,
    results: [],
    createdAt: new Date().toLocaleString('uz-UZ')
  });

  state.adminForm = { name: '', code: '', timer: '', mode: 'english-uzbek', words: '' };
  saveState();
  render();
}

function toggleTestMode(id) {
  const t = state.adminTests.find(x => x.id === id);
  if (!t) return;
  t.mode = t.mode === 'english-uzbek' ? 'uzbek-english' : 'english-uzbek';
  saveState();
  render();
}

function toggleTestStatus(id) {
  const t = state.adminTests.find(x => x.id === id);
  if (!t) return;
  t.isActive = !t.isActive;
  saveState();
  render();
}

function deleteTest(id) {
  if (!confirm('Test o\'chirilsinmi?')) return;
  state.adminTests = state.adminTests.filter(x => x.id !== id);
  saveState();
  render();
}

function startUploadedQuiz() {
  const words = parseVocabulary(state.userUploadText);
  if (!words.length) return alert('Format: english - uzbek');
  startQuiz({ words, mode: 'english-uzbek', timerMin: null, source: 'upload' });
}

function startCodedQuiz() {
  const username = state.codeName.trim();
  const code = state.codeValue.trim().toUpperCase();
  if (!username) return alert('Ism kiriting');
  if (!code) return alert('Kod kiriting');

  const test = state.adminTests.find(t => t.code === code);
  if (!test) return alert('Kod topilmadi');
  if (!test.isActive) return alert('Test yopiq');

  startQuiz({ words: test.words, mode: test.mode, timerMin: test.timerMin, source: 'coded', testId: test.id, username });
}

function renderQuiz() {
  if (!state.quiz) {
    return `
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-12 text-center max-w-4xl mx-auto mt-8">
        <h1 class="text-5xl font-bold text-indigo-600 dark:text-indigo-400 mb-4">Vocabulary Platform</h1>
        <p class="text-xl text-gray-600 dark:text-gray-300">Kerakli bo'limni ochish uchun menyudagi katta tugmalardan foydalaning.</p>
      </div>
    `;
  }

  const q = state.quiz;
  const total = q.words.length;
  const timeLeft = q.timerMin ? Math.max(0, (q.timerMin * 60000) - (Date.now() - q.startedAt)) : null;
  if (timeLeft !== null && timeLeft <= 0 && !q.done) finishQuiz();

  if (q.done) {
    const correct = q.score.correct;
    const percent = Math.round((correct / total) * 100);
    const badge = levelByPercent(percent);
    return `
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-10 max-w-4xl mx-auto mt-6 text-center">
        <h2 class="text-4xl font-bold text-indigo-600 dark:text-indigo-300 mb-4">Test yakunlandi</h2>
        <p class="text-6xl font-extrabold mb-2 text-indigo-700 dark:text-indigo-200">${correct} / ${total}</p>
        <p class="text-2xl mb-2 text-gray-700 dark:text-gray-200">${percent}% · ${badge}</p>
        <p class="text-gray-600 dark:text-gray-400 mb-7">${badge === 'TOP' ? 'Juda yaxshi natija! 🔥' : badge === 'NORMAL' ? 'Yaxshi, davom eting! 💪' : 'Mashqni davom ettiring! 🚀'}</p>
        <div class="flex justify-center gap-3">
          <button onclick="exitQuizToHome()" class="px-8 py-3 rounded-xl bg-indigo-600 text-white font-bold">Yopish</button>
        </div>
      </div>
    `;
  }

  const qa = getQuestionAnswer(q);
  const nextTip = state.autoNextAt ? '<p class="text-sm text-indigo-500 dark:text-indigo-300 mt-3">Keyingi savolga o\'tilmoqda...</p>' : '';
  const timerText = q.timerMin ? `${Math.ceil(timeLeft / 1000)}s` : '';

  return `
    <div class="max-w-4xl mx-auto mt-6">
      <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow mb-4">
        <div class="flex justify-between items-center mb-3">
          <div class="font-bold text-gray-700 dark:text-gray-100">${q.index + 1} / ${total}</div>
          <div class="flex items-center gap-5 font-bold text-lg">
            <span class="text-green-600 dark:text-green-400">✅ ${q.score.correct}</span>
            <span class="text-red-600 dark:text-red-400">❌ ${q.score.wrong}</span>
            ${timerText ? `<span class="text-amber-600 dark:text-amber-300">⏱️ ${timerText}</span>` : ''}
          </div>
        </div>
        <div class="w-full bg-gray-200 dark:bg-gray-700 h-3 rounded-full overflow-hidden">
          <div class="bg-gradient-to-r from-indigo-500 to-purple-500 h-3" style="width:${((q.index + 1) / total) * 100}%"></div>
        </div>
      </div>

      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8">
        <div class="mb-7 p-8 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900 dark:to-purple-900 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 text-center">
          <p class="text-sm uppercase tracking-wider text-gray-500 dark:text-gray-300 mb-2">Tarjima qiling</p>
          <h3 class="text-4xl font-extrabold text-indigo-900 dark:text-indigo-100">${qa.question}</h3>
        </div>

        <textarea id="answerInput" rows="2" placeholder="Javobingiz... (Enter bilan tasdiqlanadi)" class="w-full p-5 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-xl text-gray-800 dark:text-gray-100 focus:outline-none focus:border-indigo-500">${state.quizAnswer}</textarea>

        ${state.quizFeedback ? (state.quizFeedback.ok
          ? `<div class="mt-4 p-4 rounded-xl border-2 border-green-500 bg-green-50 dark:bg-green-900/30"><p class="text-2xl font-bold text-green-700 dark:text-green-200">${state.quizFeedback.msg}</p><p class="text-green-700 dark:text-green-300">${state.quizFeedback.answer}</p></div>`
          : `<div class="mt-4 p-4 rounded-xl border-2 border-red-500 bg-red-50 dark:bg-red-900/30"><p class="text-2xl font-bold text-red-700 dark:text-red-200">${state.quizFeedback.msg}</p><p class="text-red-700 dark:text-red-300">To'g'ri javob: ${state.quizFeedback.answer}</p></div>`
        ) : ''}

        ${nextTip}
      </div>
    </div>
  `;
}

function renderMainButtons() {
  return `
    <div class="max-w-6xl mx-auto mb-5 grid grid-cols-1 md:grid-cols-4 gap-4">
      <button onclick="openModal('upload')" class="md:col-span-2 rounded-2xl p-8 text-left bg-gradient-to-r from-indigo-600 to-purple-600 text-white shadow-xl hover:-translate-y-1 transition">
        <div class="text-2xl font-bold">📤 So'z yuklash</div>
        <div class="text-indigo-100">Foydalanuvchi o'zi test yuklaydi</div>
      </button>
      <button onclick="openModal('coded')" class="rounded-2xl p-6 text-left bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-xl hover:-translate-y-1 transition">
        <div class="text-lg font-bold">🎓 Kodli test</div>
        <div class="text-sm opacity-90">Ism + kod bilan kirish</div>
      </button>
      <button onclick="openModal('contact')" class="rounded-2xl p-6 text-left bg-slate-800 text-white border border-slate-700 shadow-xl hover:-translate-y-1 transition">
        <div class="text-lg font-bold">📬 Bog'lanish</div>
      </button>
      <button onclick="openModal('adminLogin')" class="rounded-2xl p-6 text-left bg-slate-800 text-white border border-red-700 shadow-xl hover:-translate-y-1 transition">
        <div class="text-lg font-bold">🛡️ Admin</div>
      </button>
    </div>
  `;
}

function modalWrapper(inner, max='max-w-3xl') {
  return `
  <div class="fixed inset-0 bg-black/70 p-4 z-50 flex items-center justify-center" onclick="if(event.target===this)closeModal()">
    <div class="w-full ${max} bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6 max-h-[92vh] overflow-y-auto" onclick="event.stopPropagation()">
      <div class="flex justify-end mb-2"><button onclick="closeModal()" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"><i data-lucide="x"></i></button></div>
      ${inner}
    </div>
  </div>`;
}

function renderModal() {
  if (!state.modal) return '';

  if (state.modal === 'upload') {
    return modalWrapper(`
      <h2 class="text-3xl font-bold text-indigo-600 dark:text-indigo-300 mb-4">So'z yuklash</h2>
      <textarea id="uploadInput" class="w-full h-80 p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900" placeholder="hello - salom\nwater - suv" oninput="state.userUploadText=this.value">${state.userUploadText}</textarea>
      <div class="mt-4 flex gap-3">
        <button onclick="startUploadedQuiz()" class="flex-1 py-4 rounded-xl bg-indigo-600 text-white font-bold">Boshlash</button>
      </div>
    `, 'max-w-4xl');
  }

  if (state.modal === 'coded') {
    return modalWrapper(`
      <h2 class="text-3xl font-bold text-purple-600 dark:text-purple-300 mb-4">Kodli test</h2>
      <div class="grid md:grid-cols-2 gap-3">
        <input id="codedName" class="p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900" placeholder="Ism" value="${state.codeName}" oninput="state.codeName=this.value" onkeydown="if(event.key==='Enter')startCodedQuiz()"/>
        <input id="codedCode" class="p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 uppercase" placeholder="Test kodi" value="${state.codeValue}" oninput="state.codeValue=this.value.toUpperCase();this.value=this.value.toUpperCase()" onkeydown="if(event.key==='Enter')startCodedQuiz()"/>
      </div>
      <button onclick="startCodedQuiz()" class="mt-4 w-full py-4 rounded-xl bg-purple-600 text-white font-bold">Boshlash</button>
    `, 'max-w-xl');
  }

  if (state.modal === 'contact') {
    return modalWrapper(`
      <h2 class="text-3xl font-bold text-blue-600 dark:text-blue-300 mb-4">Bog'lanish</h2>
      <div class="flex flex-wrap gap-3">
        <a class="px-5 py-3 rounded-xl bg-sky-600 text-white font-bold" href="https://t.me/XOSHIMJONOVUBAYDULLO" target="_blank">Telegram</a>
        <a class="px-5 py-3 rounded-xl bg-slate-700 text-white font-bold" href="https://github.com/Khanjan2004" target="_blank">GitHub</a>
        <a class="px-5 py-3 rounded-xl bg-emerald-700 text-white font-bold" href="mailto:ubaydulloxoshimjonov@gmail.com">Email</a>
      </div>
    `, 'max-w-xl');
  }

  if (state.modal === 'adminLogin') {
    return modalWrapper(`
      <h2 class="text-3xl font-bold text-red-600 dark:text-red-300 mb-4">Admin kirish</h2>
      <input id="adminCode" type="password" class="w-full p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900" placeholder="Admin kod" value="${state.adminCodeInput}" oninput="state.adminCodeInput=this.value" onkeydown="if(event.key==='Enter')loginAdmin()"/>
      <button onclick="loginAdmin()" class="mt-4 w-full py-4 rounded-xl bg-red-600 text-white font-bold">Kirish</button>
    `, 'max-w-lg');
  }

  return '';
}

function renderAdminModal() {
  if (!state.adminModal || !state.adminLogged) return '';

  const selected = state.adminTests.find(t => t.id === state.viewingStatsTestId);

  return `
  <div class="fixed inset-0 bg-black/80 p-4 z-[60] flex items-center justify-center" onclick="if(event.target===this)closeAdminModal()">
    <div class="w-full max-w-6xl bg-gray-900 rounded-2xl shadow-2xl p-6 max-h-[94vh] overflow-y-auto border border-red-700" onclick="event.stopPropagation()">
      <div class="flex justify-between items-center mb-5">
        <h2 class="text-3xl font-extrabold text-red-300">Admin Interface</h2>
        <button onclick="closeAdminModal()" class="p-2 rounded-lg hover:bg-gray-800 text-white"><i data-lucide="x"></i></button>
      </div>

      <div class="rounded-xl bg-gray-800 p-4 border border-gray-700 mb-4">
        <div class="grid md:grid-cols-5 gap-2 mb-2">
          <input class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" placeholder="Nom" value="${state.adminForm.name}" oninput="state.adminForm.name=this.value" onkeydown="if(event.key==='Enter')createAdminTest()"/>
          <input class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white uppercase" placeholder="Kod" value="${state.adminForm.code}" oninput="state.adminForm.code=this.value.toUpperCase();this.value=this.value.toUpperCase()" onkeydown="if(event.key==='Enter')createAdminTest()"/>
          <input type="number" class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" placeholder="Timer(min)" value="${state.adminForm.timer}" oninput="state.adminForm.timer=this.value" onkeydown="if(event.key==='Enter')createAdminTest()"/>
          <select class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" onchange="state.adminForm.mode=this.value">
            <option value="english-uzbek" ${state.adminForm.mode==='english-uzbek'?'selected':''}>EN → UZ</option>
            <option value="uzbek-english" ${state.adminForm.mode==='uzbek-english'?'selected':''}>UZ → EN</option>
          </select>
          <button onclick="createAdminTest()" class="rounded-lg bg-emerald-600 text-white font-bold">Saqlash</button>
        </div>
        <textarea class="w-full h-28 p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" placeholder="word - tarjima" oninput="state.adminForm.words=this.value">${state.adminForm.words}</textarea>
      </div>

      <div class="space-y-3">
        ${state.adminTests.length ? state.adminTests.map(test => {
          const totalData = totals(test.results || []);
          const groups = groupStats(test.results || []);
          return `
            <div class="rounded-xl bg-gray-800 border border-gray-700 p-4">
              <div class="flex flex-wrap justify-between gap-2 mb-3">
                <div>
                  <h3 class="text-xl font-bold text-white">${test.name}</h3>
                  <p class="text-gray-400 text-sm">${test.createdAt} · ${test.code}</p>
                </div>
                <div class="flex gap-2">
                  <button onclick="toggleTestStatus('${test.id}')" class="px-3 py-2 rounded-lg text-sm font-bold ${test.isActive ? 'bg-green-700 text-green-100' : 'bg-red-700 text-red-100'}">${test.isActive ? 'Ochiq' : 'Yopiq'}</button>
                  <button onclick="toggleTestMode('${test.id}')" class="px-3 py-2 rounded-lg text-sm font-bold bg-indigo-700 text-white">${test.mode === 'english-uzbek' ? 'EN→UZ' : 'UZ→EN'}</button>
                  <button onclick="state.viewingStatsTestId='${test.id}';render()" class="px-3 py-2 rounded-lg text-sm font-bold bg-blue-700 text-white">Stat</button>
                  <button onclick="deleteTest('${test.id}')" class="px-3 py-2 rounded-lg text-sm font-bold bg-gray-700 text-white">Del</button>
                </div>
              </div>
              <div class="grid grid-cols-2 md:grid-cols-6 gap-2 text-sm">
                <div class="rounded-lg bg-gray-900 p-2 text-center text-gray-200">So'z<br><b>${test.words.length}</b></div>
                <div class="rounded-lg bg-gray-900 p-2 text-center text-gray-200">User<br><b>${(test.results || []).length}</b></div>
                <div class="rounded-lg bg-gray-900 p-2 text-center text-gray-200">Urinish<br><b>${totalData.attempts}</b></div>
                <div class="rounded-lg bg-gray-900 p-2 text-center text-green-300">To'g'ri<br><b>${totalData.correct}</b></div>
                <div class="rounded-lg bg-gray-900 p-2 text-center text-red-300">Xato<br><b>${totalData.wrong}</b></div>
                <div class="rounded-lg bg-gray-900 p-2 text-center text-amber-300">Timer<br><b>${test.timerMin ? test.timerMin + 'm' : '-'}</b></div>
              </div>
            </div>
          `;
        }).join('') : '<div class="text-gray-400">Testlar yo\'q</div>'}
      </div>

      ${selected ? `
      <div class="mt-5 rounded-xl bg-gray-800 border border-gray-700 p-4">
        <div class="flex justify-between items-center mb-3">
          <h3 class="text-xl font-bold text-white">${selected.name} · Statistikalar</h3>
          <button onclick="state.viewingStatsTestId=null;render()" class="px-3 py-1 rounded bg-gray-700 text-white">Yopish</button>
        </div>
        <div class="grid md:grid-cols-3 gap-3 mb-3">
          <div class="rounded-lg p-3 bg-green-900/40 border border-green-700 text-green-300">TOP (80-100): <b>${groupStats(selected.results || []).top}</b></div>
          <div class="rounded-lg p-3 bg-yellow-900/40 border border-yellow-700 text-yellow-300">NORMAL (50-79): <b>${groupStats(selected.results || []).normal}</b></div>
          <div class="rounded-lg p-3 bg-red-900/40 border border-red-700 text-red-300">MIN (0-49): <b>${groupStats(selected.results || []).min}</b></div>
        </div>
        <div class="space-y-2 max-h-64 overflow-y-auto">
          ${(selected.results || []).length ? selected.results.map(user => {
            const last = user.scores[user.scores.length - 1];
            return `<div class="rounded-lg bg-gray-900 p-3 border border-gray-700 text-gray-200">${user.username} · urinish ${user.attempts} · oxirgi ${last.correct}/${last.total} (${last.percentage}%)</div>`;
          }).join('') : '<div class="text-gray-400">Natija yo\'q</div>'}
        </div>
      </div>` : ''}
    </div>
  </div>`;
}

function render() {
  document.getElementById('app').innerHTML = `
    <div class="max-w-6xl mx-auto">
      <div class="fixed top-4 right-4 z-40 flex gap-3">
        <button onclick="state.theme = state.theme === 'dark' ? 'light' : 'dark'; applyTheme(); saveState(); render();" class="p-3 rounded-xl bg-white dark:bg-gray-800 shadow text-gray-700 dark:text-white">
          <i data-lucide="${state.theme === 'dark' ? 'sun' : 'moon'}" class="w-5 h-5"></i>
        </button>
      </div>

      ${renderMainButtons()}
      ${renderQuiz()}
    </div>
    ${renderModal()}
    ${renderAdminModal()}
  `;

  lucide.createIcons();

  const answerInput = document.getElementById('answerInput');
  if (answerInput && state.quiz && !state.quiz.done) {
    answerInput.focus();
    answerInput.addEventListener('input', (e) => { state.quizAnswer = e.target.value; });
    answerInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitAnswerAndAutoNext();
      }
    });
  }
}

setInterval(tickAutoNext, 120);
loadState();
</script>
</body>
</html>

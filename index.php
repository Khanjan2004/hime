<?php
session_start();
$config = require __DIR__ . '/config.php';
$adminCode = (string)($config['ADMIN_PANEL_CODE'] ?? 'change_this_secret_code');
$dbPath = (string)($config['DB_PATH'] ?? (__DIR__ . '/storage.sqlite'));

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('CREATE TABLE IF NOT EXISTS tests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  code TEXT NOT NULL UNIQUE,
  words_json TEXT NOT NULL,
  mode TEXT NOT NULL,
  timer_min INTEGER NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE IF NOT EXISTS results (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  test_id INTEGER NOT NULL,
  username TEXT NOT NULL,
  correct INTEGER NOT NULL,
  total INTEGER NOT NULL,
  percentage INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(test_id) REFERENCES tests(id)
)');

// Backward compatibility migration (old DB had no updated_at)
$testColumns = $pdo->query('PRAGMA table_info(tests)')->fetchAll(PDO::FETCH_ASSOC);
$hasUpdatedAt = false;
foreach ($testColumns as $column) {
    if (($column['name'] ?? '') === 'updated_at') { $hasUpdatedAt = true; break; }
}
if (!$hasUpdatedAt) {
    $pdo->exec("ALTER TABLE tests ADD COLUMN updated_at TEXT NOT NULL DEFAULT ''");
    $pdo->exec("UPDATE tests SET updated_at = created_at WHERE updated_at = ''");
}

function jsonOut($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function inputJson(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw ?: '{}', true);
    return is_array($decoded) ? $decoded : [];
}

function requireAdmin(): void {
    if (empty($_SESSION['admin_ok'])) {
        jsonOut(['ok' => false, 'message' => 'Unauthorized'], 403);
    }
}

function normalizeWords($words): array {
    if (!is_array($words)) return [];
    $clean = [];
    foreach ($words as $item) {
        if (!is_array($item)) continue;
        $english = trim((string)($item['english'] ?? ''));
        $uzbek = trim((string)($item['uzbek'] ?? ''));
        if ($english !== '' && $uzbek !== '') $clean[] = ['english' => $english, 'uzbek' => $uzbek];
    }
    return $clean;
}

function assembleTests(PDO $pdo, bool $adminView): array {
    $tests = [];
    $sql = $adminView
        ? 'SELECT * FROM tests ORDER BY id DESC'
        : 'SELECT * FROM tests WHERE is_active = 1 ORDER BY id DESC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $tests[] = [
            'id' => (string)$row['id'],
            'name' => $row['name'],
            'code' => $row['code'],
            'words' => json_decode($row['words_json'], true) ?: [],
            'mode' => $row['mode'],
            'timerMin' => $row['timer_min'] !== null ? (int)$row['timer_min'] : null,
            'isActive' => (bool)$row['is_active'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'results' => []
        ];
    }

    if (!$adminView || !$tests) return $tests;

    $map = [];
    foreach ($tests as $i => $t) {
        $map[$t['id']] = $i;
    }

    $resultRows = $pdo->query('SELECT * FROM results ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resultRows as $r) {
        $testId = (string)$r['test_id'];
        if (!isset($map[$testId])) continue;

        $idx = $map[$testId];
        $username = $r['username'];
        $userIndex = null;

        foreach ($tests[$idx]['results'] as $k => $u) {
            if ($u['username'] === $username) {
                $userIndex = $k;
                break;
            }
        }

        if ($userIndex === null) {
            $tests[$idx]['results'][] = ['username' => $username, 'attempts' => 0, 'scores' => []];
            $userIndex = count($tests[$idx]['results']) - 1;
        }

        $tests[$idx]['results'][$userIndex]['attempts']++;
        $tests[$idx]['results'][$userIndex]['scores'][] = [
            'correct' => (int)$r['correct'],
            'total' => (int)$r['total'],
            'percentage' => (int)$r['percentage'],
            'date' => $r['created_at']
        ];
    }

    return $tests;
}

if (isset($_GET['api'])) {
    $api = $_GET['api'];
    $body = inputJson();

    if ($api === 'admin-login') {
        $code = trim((string)($body['code'] ?? ''));
        if ($code !== '' && hash_equals($adminCode, $code)) {
            $_SESSION['admin_ok'] = true;
            jsonOut(['ok' => true]);
        }
        jsonOut(['ok' => false], 401);
    }

    if ($api === 'admin-logout') {
        $_SESSION['admin_ok'] = false;
        jsonOut(['ok' => true]);
    }

    if ($api === 'state-get') {
        $adminView = !empty($_SESSION['admin_ok']);
        jsonOut(['ok' => true, 'admin' => $adminView, 'tests' => assembleTests($pdo, $adminView)]);
    }

    if ($api === 'test-create') {
        requireAdmin();
        $name = trim((string)($body['name'] ?? ''));
        $code = strtoupper(trim((string)($body['code'] ?? '')));
        $mode = (string)($body['mode'] ?? 'english-uzbek');
        $timer = isset($body['timerMin']) && $body['timerMin'] !== null ? (int)$body['timerMin'] : null;
        $words = normalizeWords($body['words'] ?? []);

        if ($name === '' || $code === '' || !count($words)) {
            jsonOut(['ok' => false, 'message' => 'Invalid fields'], 400);
        }

        $stmt = $pdo->prepare('INSERT INTO tests(name, code, words_json, mode, timer_min, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)');
        try {
            $now = date('Y-m-d H:i:s');
            $stmt->execute([$name, $code, json_encode($words, JSON_UNESCAPED_UNICODE), $mode, $timer, 1, $now, $now]);
        } catch (Throwable $e) {
            jsonOut(['ok' => false, 'message' => 'Code exists'], 409);
        }
        jsonOut(['ok' => true]);
    }

    if ($api === 'test-update') {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        $name = trim((string)($body['name'] ?? ''));
        $mode = (string)($body['mode'] ?? 'english-uzbek');
        $timer = isset($body['timerMin']) && $body['timerMin'] !== null ? (int)$body['timerMin'] : null;
        $words = normalizeWords($body['words'] ?? []);

        if ($id < 1 || $name === '' || !count($words)) {
            jsonOut(['ok' => false, 'message' => 'Invalid fields'], 400);
        }

        $stmt = $pdo->prepare('UPDATE tests SET name=?, words_json=?, mode=?, timer_min=?, updated_at=? WHERE id=?');
        $stmt->execute([$name, json_encode($words, JSON_UNESCAPED_UNICODE), $mode, $timer, date('Y-m-d H:i:s'), $id]);
        jsonOut(['ok' => true]);
    }

    if ($api === 'test-toggle-status') {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        $pdo->prepare('UPDATE tests SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END, updated_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $id]);
        jsonOut(['ok' => true]);
    }

    if ($api === 'test-toggle-mode') {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        $pdo->prepare("UPDATE tests SET mode = CASE WHEN mode='english-uzbek' THEN 'uzbek-english' ELSE 'english-uzbek' END, updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        jsonOut(['ok' => true]);
    }

    if ($api === 'test-delete') {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        $pdo->prepare('DELETE FROM results WHERE test_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM tests WHERE id=?')->execute([$id]);
        jsonOut(['ok' => true]);
    }

    if ($api === 'result-save') {
        $testId = (int)($body['testId'] ?? 0);
        $username = trim((string)($body['username'] ?? ''));
        $correct = (int)($body['correct'] ?? 0);
        $total = (int)($body['total'] ?? 0);
        $percentage = (int)($body['percentage'] ?? 0);

        if ($testId < 1 || $username === '' || $total < 1) {
            jsonOut(['ok' => false], 400);
        }

        $exists = $pdo->prepare('SELECT id FROM tests WHERE id=? AND is_active=1');
        $exists->execute([$testId]);
        if (!$exists->fetchColumn()) jsonOut(['ok' => false], 404);

        $stmt = $pdo->prepare('INSERT INTO results(test_id, username, correct, total, percentage, created_at) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$testId, $username, $correct, $total, $percentage, date('Y-m-d H:i:s')]);
        jsonOut(['ok' => true]);
    }

    if ($api === 'test-export-csv') {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        $testStmt = $pdo->prepare('SELECT name, code FROM tests WHERE id=?');
        $testStmt->execute([$id]);
        $test = $testStmt->fetch(PDO::FETCH_ASSOC);
        if (!$test) jsonOut(['ok' => false], 404);

        $rows = $pdo->prepare('SELECT username, correct, total, percentage, created_at FROM results WHERE test_id=? ORDER BY id DESC');
        $rows->execute([$id]);
        $data = $rows->fetchAll(PDO::FETCH_ASSOC);

        $csv = "username,correct,total,percentage,date\n";
        foreach ($data as $r) {
            $line = [
                str_replace('"', '""', $r['username']),
                (string)$r['correct'],
                (string)$r['total'],
                (string)$r['percentage'],
                str_replace('"', '""', $r['created_at'])
            ];
            $csv .= '"' . implode('","', $line) . "\"\n";
        }

        jsonOut([
            'ok' => true,
            'filename' => 'test_' . $test['code'] . '_results.csv',
            'content' => base64_encode($csv)
        ]);
    }

    jsonOut(['ok' => false, 'message' => 'Unknown API'], 404);
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
<body class="min-h-screen bg-gradient-to-br from-gray-50 via-blue-50 to-indigo-50 dark:from-gray-900 dark:via-indigo-950 dark:to-purple-950 p-4 transition-all duration-500 text-gray-900 dark:text-white">
<div id="app"></div>
<script>
const MESSAGES = ["Ajoyib! 🎉", "Zo'r! 💪", "A'lo! ⭐", "Davom eting! 🚀", "Mukammal! 🌟"];
const CLIENT_STORE_KEY = 'vocab-platform-pro-v4';

let state = {
  theme: 'dark',
  syncing: false,

  modal: null,
  adminModal: false,
  adminLogged: false,
  adminCodeInput: '',
  searchTerm: '',

  adminTests: [],
  userTests: [],

  userUploadText: '',
  codeName: '',
  codeValue: '',

  quiz: null,
  quizAnswer: '',
  quizFeedback: null,
  autoNextAt: null,

  adminForm: { name: '', code: '', timer: '', mode: 'english-uzbek', words: '' },
  editingTestId: null,
  viewingStatsTestId: null
};

function loadClientState() {
  try {
    const saved = JSON.parse(localStorage.getItem(CLIENT_STORE_KEY) || '{}');
    state.theme = saved.theme || 'dark';
    state.codeName = saved.codeName || '';
  } catch (_) {}
}

function saveClientState() {
  localStorage.setItem(CLIENT_STORE_KEY, JSON.stringify({
    theme: state.theme,
    codeName: state.codeName
  }));
}

function applyTheme() {
  document.documentElement.classList.toggle('dark', state.theme === 'dark');
  saveClientState();
}

async function api(endpoint, payload = {}) {
  const res = await fetch(`?api=${endpoint}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || 'API error');
  return data;
}

async function syncState() {
  state.syncing = true;
  render();
  try {
    const data = await api('state-get');
    state.adminLogged = !!data.admin;
    state.adminTests = data.admin ? data.tests : [];
    state.userTests = data.admin ? data.tests.filter(t => t.isActive) : data.tests;
  } catch (_) {
    alert('Server bilan bog\'lanishda xatolik');
  } finally {
    state.syncing = false;
    render();
  }
}

function parseVocabulary(text) {
  const lines = text.trim().split('\n');
  const words = [];
  for (let line of lines) {
    line = line.replace(/^\d+[\.)\]\s•*-]*/, '').trim();
    if (!line) continue;
    const parts = line.split(/\s*[-–—:;|=]\s*/);
    if (parts.length < 2) continue;
    const english = parts[0].trim();
    const uzbek = parts.slice(1).join(' - ').trim();
    if (english && uzbek) words.push({ english, uzbek });
  }
  return words;
}

function levelByPercent(p) {
  if (p < 50) return 'MIN';
  if (p < 80) return 'NORMAL';
  return 'TOP';
}

function groupStats(results) {
  const latest = (results || []).map(r => r.scores?.[r.scores.length - 1]).filter(Boolean);
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
      attempts += 1;
      correct += Number(score.correct || 0);
      wrong += Number(score.total || 0) - Number(score.correct || 0);
    }
  }
  return { attempts, correct, wrong };
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
  state.editingTestId = null;
  render();
}

function getQuestionAnswer(quiz) {
  const word = quiz.words[quiz.index];
  const question = quiz.mode === 'english-uzbek' ? word.english : word.uzbek;
  const answer = quiz.mode === 'english-uzbek' ? word.uzbek : word.english;
  return { question, answer };
}

function startQuiz({ words, mode, timerMin = null, source = 'upload', testId = null, username = '' }) {
  if (!words?.length) return alert('So\'zlar topilmadi');

  state.quiz = {
    source,
    testId,
    username,
    words: [...words].sort(() => Math.random() - 0.5),
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

async function finishQuiz() {
  if (!state.quiz || state.quiz.done) return;
  state.quiz.done = true;

  if (state.quiz.source === 'coded' && state.quiz.testId) {
    const total = state.quiz.words.length;
    const correct = state.quiz.score.correct;
    const percentage = Math.round((correct / total) * 100);

    try {
      await api('result-save', {
        testId: Number(state.quiz.testId),
        username: state.quiz.username,
        correct,
        total,
        percentage
      });
      await syncState();
    } catch (_) {}
  }

  render();
}

function submitAnswerAndAutoNext() {
  if (!state.quiz || state.quiz.done) return;

  const qa = getQuestionAnswer(state.quiz);
  const input = state.quizAnswer.trim().toLowerCase();
  const ok = input === qa.answer.trim().toLowerCase();

  if (ok) {
    state.quiz.score.correct += 1;
    confetti({
      particleCount: 80,
      spread: 70,
      origin: { y: 0.6 },
      colors: ['#4F46E5', '#7C3AED', '#EC4899']
    });
  } else {
    state.quiz.score.wrong += 1;
  }

  state.quizFeedback = {
    ok,
    answer: qa.answer,
    msg: ok ? MESSAGES[Math.floor(Math.random() * MESSAGES.length)] : 'Noto\'g\'ri ❌'
  };

  if (state.quiz.index >= state.quiz.words.length - 1) {
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
    state.quiz.index += 1;
    state.quizAnswer = '';
    state.quizFeedback = null;
    render();
  }
}

function exitQuizToHome() {
  state.quiz = null;
  state.quizAnswer = '';
  state.quizFeedback = null;
  state.autoNextAt = null;
  render();
}

async function loginAdmin() {
  try {
    await api('admin-login', { code: state.adminCodeInput });
    state.adminCodeInput = '';
    await syncState();
    state.adminModal = true;
    state.modal = null;
    render();
  } catch (_) {
    alert('Admin kod noto\'g\'ri');
  }
}

async function logoutAdmin() {
  await api('admin-logout');
  state.adminModal = false;
  await syncState();
}

function fillAdminFormForEdit(test) {
  state.editingTestId = test.id;
  state.adminForm.name = test.name;
  state.adminForm.code = test.code;
  state.adminForm.mode = test.mode;
  state.adminForm.timer = test.timerMin === null ? '' : String(test.timerMin);
  state.adminForm.words = (test.words || []).map(w => `${w.english} - ${w.uzbek}`).join('\n');
  render();
}

function resetAdminForm() {
  state.editingTestId = null;
  state.adminForm = { name: '', code: '', timer: '', mode: 'english-uzbek', words: '' };
  render();
}

function generateCode() {
  const code = Math.random().toString(36).slice(2, 8).toUpperCase();
  state.adminForm.code = code;
  render();
}

async function createOrUpdateAdminTest() {
  const name = state.adminForm.name.trim();
  const code = state.adminForm.code.trim().toUpperCase();
  const words = parseVocabulary(state.adminForm.words);
  const timerRaw = Number(state.adminForm.timer || 0);
  const timerMin = timerRaw > 0 ? timerRaw : null;

  if (!name || !code || !words.length) return alert('Nom, kod, so\'zlar kerak');

  try {
    if (state.editingTestId) {
      await api('test-update', {
        id: Number(state.editingTestId),
        name,
        words,
        mode: state.adminForm.mode,
        timerMin
      });
    } else {
      await api('test-create', { name, code, words, mode: state.adminForm.mode, timerMin });
    }

    resetAdminForm();
    await syncState();
  } catch (e) {
    alert('Kod band yoki xatolik');
  }
}

async function toggleTestStatus(id) {
  await api('test-toggle-status', { id: Number(id) });
  await syncState();
}

async function toggleTestMode(id) {
  await api('test-toggle-mode', { id: Number(id) });
  await syncState();
}

async function deleteTest(id) {
  if (!confirm('Test o\'chirilsinmi?')) return;
  await api('test-delete', { id: Number(id) });
  await syncState();
}

async function exportTestCsv(id) {
  try {
    const res = await api('test-export-csv', { id: Number(id) });
    const bytes = atob(res.content);
    const blob = new Blob([bytes], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = res.filename || 'results.csv';
    a.click();
    URL.revokeObjectURL(url);
  } catch (_) {
    alert('CSV yuklashda xatolik');
  }
}

function startUploadedQuiz() {
  const words = parseVocabulary(state.userUploadText);
  if (!words.length) return alert('Format: english - uzbek');
  startQuiz({ words, mode: 'english-uzbek', source: 'upload' });
}

function startCodedQuiz() {
  const username = state.codeName.trim();
  const code = state.codeValue.trim().toUpperCase();

  if (!username) return alert('Ism kiriting');
  if (!code) return alert('Kod kiriting');

  const test = state.userTests.find(t => t.code === code);
  if (!test) return alert('Kod topilmadi');

  state.codeName = username;
  saveClientState();

  startQuiz({
    words: test.words,
    mode: test.mode,
    timerMin: test.timerMin,
    source: 'coded',
    testId: test.id,
    username
  });
}

function renderQuiz() {
  if (!state.quiz) {
    return `
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-12 text-center max-w-4xl mx-auto mt-8 text-gray-900 dark:text-white">
        <h1 class="text-5xl font-bold text-indigo-600 dark:text-indigo-300 mb-4">Vocabulary Platform</h1>
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
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-10 max-w-4xl mx-auto mt-6 text-center text-gray-900 dark:text-white">
        <h2 class="text-4xl font-bold text-indigo-600 dark:text-indigo-300 mb-4">Test yakunlandi</h2>
        <p class="text-6xl font-extrabold mb-2 text-indigo-700 dark:text-indigo-200">${correct}/${total}</p>
        <p class="text-2xl mb-5 text-gray-700 dark:text-white">${percent}% · ${badge}</p>
        <button onclick="exitQuizToHome()" class="px-8 py-3 rounded-xl bg-indigo-600 text-white font-bold">Yopish</button>
      </div>
    `;
  }

  const qa = getQuestionAnswer(q);
  const timerText = q.timerMin ? `<span class="text-amber-600 dark:text-amber-300">⏱️ ${Math.ceil(timeLeft / 1000)}s</span>` : '';
  const nextTip = state.autoNextAt ? '<p class="text-sm text-indigo-500 dark:text-indigo-300 mt-3">Keyingi savolga o\'tilmoqda...</p>' : '';

  return `
    <div class="max-w-4xl mx-auto mt-6">
      <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow mb-4 text-gray-900 dark:text-white">
        <div class="flex justify-between items-center mb-3">
          <div class="font-bold text-gray-700 dark:text-white">${q.index + 1}/${total}</div>
          <div class="flex items-center gap-5 font-bold text-lg">
            <span class="text-green-600 dark:text-green-400">✅ ${q.score.correct}</span>
            <span class="text-red-600 dark:text-red-400">❌ ${q.score.wrong}</span>
            ${timerText}
          </div>
        </div>
        <div class="w-full bg-gray-200 dark:bg-gray-700 h-3 rounded-full overflow-hidden">
          <div class="bg-gradient-to-r from-indigo-500 to-purple-500 h-3" style="width:${((q.index + 1) / total) * 100}%"></div>
        </div>
      </div>

      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 text-gray-900 dark:text-white">
        <div class="mb-7 p-8 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900 dark:to-purple-900 rounded-xl border-2 border-indigo-200 dark:border-indigo-700 text-center">
          <h3 class="text-4xl font-extrabold text-indigo-900 dark:text-indigo-100">${qa.question}</h3>
        </div>

        <textarea id="answerInput" rows="2" class="w-full p-5 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-xl text-gray-900 dark:text-white focus:outline-none focus:border-indigo-500">${state.quizAnswer}</textarea>

        ${state.quizFeedback ? (
          state.quizFeedback.ok
            ? `<div class="mt-4 p-4 rounded-xl border-2 border-green-500 bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200">${state.quizFeedback.msg}</div>`
            : `<div class="mt-4 p-4 rounded-xl border-2 border-red-500 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200">${state.quizFeedback.msg} · ${state.quizFeedback.answer}</div>`
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
      </button>
      <button onclick="openModal('coded')" class="rounded-2xl p-6 text-left bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-xl hover:-translate-y-1 transition">
        <div class="text-lg font-bold">🎓 Kodli test</div>
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

function modalWrapper(inner, max = 'max-w-3xl') {
  return `
    <div class="fixed inset-0 bg-black/70 p-4 z-50 flex items-center justify-center" onclick="if(event.target===this)closeModal()">
      <div class="w-full ${max} bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-6 max-h-[92vh] overflow-y-auto text-gray-900 dark:text-white" onclick="event.stopPropagation()">
        <div class="flex justify-end mb-2">
          <button onclick="closeModal()" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"><i data-lucide="x"></i></button>
        </div>
        ${inner}
      </div>
    </div>
  `;
}

function renderModal() {
  if (!state.modal) return '';

  if (state.modal === 'upload') {
    return modalWrapper(`
      <h2 class="text-3xl font-bold text-indigo-600 dark:text-indigo-300 mb-4">So'z yuklash</h2>
      <textarea class="w-full h-80 p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white" placeholder="hello - salom\nwater - suv" oninput="state.userUploadText=this.value">${state.userUploadText}</textarea>
      <button onclick="startUploadedQuiz()" class="mt-4 w-full py-4 rounded-xl bg-indigo-600 text-white font-bold">Boshlash</button>
    `, 'max-w-4xl');
  }

  if (state.modal === 'coded') {
    return modalWrapper(`
      <h2 class="text-3xl font-bold text-purple-600 dark:text-purple-300 mb-4">Kodli test</h2>
      <div class="grid md:grid-cols-2 gap-3">
        <input class="p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white" placeholder="Ism" value="${state.codeName}" oninput="state.codeName=this.value" onkeydown="if(event.key==='Enter')startCodedQuiz()"/>
        <input class="p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white uppercase" placeholder="Test kodi" value="${state.codeValue}" oninput="state.codeValue=this.value.toUpperCase();this.value=this.value.toUpperCase()" onkeydown="if(event.key==='Enter')startCodedQuiz()"/>
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
      <input type="password" class="w-full p-4 rounded-xl border-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white" placeholder="Admin kod" value="${state.adminCodeInput}" oninput="state.adminCodeInput=this.value" onkeydown="if(event.key==='Enter')loginAdmin()"/>
      <button onclick="loginAdmin()" class="mt-4 w-full py-4 rounded-xl bg-red-600 text-white font-bold">Kirish</button>
    `, 'max-w-lg');
  }

  return '';
}

function renderAdminModal() {
  if (!state.adminModal || !state.adminLogged) return '';

  const selected = state.adminTests.find(t => t.id === state.viewingStatsTestId);
  const search = state.searchTerm.trim().toLowerCase();
  const filteredTests = state.adminTests.filter(t => {
    if (!search) return true;
    return t.name.toLowerCase().includes(search) || t.code.toLowerCase().includes(search);
  });

  return `
    <div class="fixed inset-0 bg-black/80 p-4 z-[60] flex items-center justify-center" onclick="if(event.target===this)closeAdminModal()">
      <div class="w-full max-w-6xl bg-gray-900 rounded-2xl shadow-2xl p-6 max-h-[94vh] overflow-y-auto border border-red-700 text-white" onclick="event.stopPropagation()">
        <div class="flex flex-wrap justify-between items-center gap-2 mb-5">
          <h2 class="text-3xl font-extrabold text-red-300">Admin Interface</h2>
          <div class="flex gap-2">
            <button onclick="syncState()" class="px-3 py-2 rounded bg-indigo-700 text-white">Yangila</button>
            <button onclick="logoutAdmin()" class="px-3 py-2 rounded bg-yellow-700 text-white">Logout</button>
            <button onclick="closeAdminModal()" class="p-2 rounded-lg hover:bg-gray-800"><i data-lucide="x"></i></button>
          </div>
        </div>

        <div class="rounded-xl bg-gray-800 p-4 border border-gray-700 mb-4">
          <div class="grid md:grid-cols-6 gap-2 mb-2">
            <input class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" placeholder="Nom" value="${state.adminForm.name}" oninput="state.adminForm.name=this.value" onkeydown="if(event.key==='Enter')createOrUpdateAdminTest()"/>
            <input class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white uppercase" placeholder="Kod" value="${state.adminForm.code}" ${state.editingTestId ? 'disabled' : ''} oninput="state.adminForm.code=this.value.toUpperCase();this.value=this.value.toUpperCase()" onkeydown="if(event.key==='Enter')createOrUpdateAdminTest()"/>
            <input type="number" class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" placeholder="Timer(min)" value="${state.adminForm.timer}" oninput="state.adminForm.timer=this.value" onkeydown="if(event.key==='Enter')createOrUpdateAdminTest()"/>
            <select class="p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" onchange="state.adminForm.mode=this.value">
              <option value="english-uzbek" ${state.adminForm.mode === 'english-uzbek' ? 'selected' : ''}>EN → UZ</option>
              <option value="uzbek-english" ${state.adminForm.mode === 'uzbek-english' ? 'selected' : ''}>UZ → EN</option>
            </select>
            <button onclick="generateCode()" class="rounded-lg bg-blue-700 text-white font-bold">Kod</button>
            <button onclick="createOrUpdateAdminTest()" class="rounded-lg bg-emerald-600 text-white font-bold">${state.editingTestId ? 'Saqlash' : 'Yaratish'}</button>
          </div>
          <textarea class="w-full h-28 p-3 rounded-lg bg-gray-900 border border-gray-700 text-white" placeholder="word - tarjima" oninput="state.adminForm.words=this.value">${state.adminForm.words}</textarea>
          ${state.editingTestId ? '<button onclick="resetAdminForm()" class="mt-2 px-3 py-2 rounded bg-gray-700 text-white">Bekor</button>' : ''}
        </div>

        <div class="mb-3">
          <input class="w-full p-3 rounded-lg bg-gray-800 border border-gray-700 text-white" placeholder="Qidirish: nom yoki kod" value="${state.searchTerm}" oninput="state.searchTerm=this.value;render()" />
        </div>

        <div class="space-y-3">
          ${filteredTests.length ? filteredTests.map(test => {
            const totalData = totals(test.results || []);
            return `
              <div class="rounded-xl bg-gray-800 border border-gray-700 p-4">
                <div class="flex flex-wrap justify-between gap-2 mb-3">
                  <div>
                    <h3 class="text-xl font-bold text-white">${test.name}</h3>
                    <p class="text-gray-300 text-sm">${test.createdAt} · yangilandi: ${test.updatedAt} · kod: ${test.code}</p>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <button onclick="toggleTestStatus('${test.id}')" class="px-3 py-2 rounded-lg text-sm font-bold ${test.isActive ? 'bg-green-700 text-green-100' : 'bg-red-700 text-red-100'}">${test.isActive ? 'Ochiq' : 'Yopiq'}</button>
                    <button onclick="toggleTestMode('${test.id}')" class="px-3 py-2 rounded-lg text-sm font-bold bg-indigo-700 text-white">${test.mode === 'english-uzbek' ? 'EN→UZ' : 'UZ→EN'}</button>
                    <button onclick='fillAdminFormForEdit(${JSON.stringify(test).replace(/'/g, "\\'")})' class="px-3 py-2 rounded-lg text-sm font-bold bg-violet-700 text-white">Edit</button>
                    <button onclick="state.viewingStatsTestId='${test.id}';render()" class="px-3 py-2 rounded-lg text-sm font-bold bg-blue-700 text-white">Stat</button>
                    <button onclick="exportTestCsv('${test.id}')" class="px-3 py-2 rounded-lg text-sm font-bold bg-cyan-700 text-white">CSV</button>
                    <button onclick="deleteTest('${test.id}')" class="px-3 py-2 rounded-lg text-sm font-bold bg-gray-700 text-white">Del</button>
                  </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-2 text-sm">
                  <div class="rounded-lg bg-gray-900 p-2 text-center">So'z<br><b>${test.words.length}</b></div>
                  <div class="rounded-lg bg-gray-900 p-2 text-center">User<br><b>${(test.results || []).length}</b></div>
                  <div class="rounded-lg bg-gray-900 p-2 text-center">Urinish<br><b>${totalData.attempts}</b></div>
                  <div class="rounded-lg bg-gray-900 p-2 text-center text-green-300">To'g'ri<br><b>${totalData.correct}</b></div>
                  <div class="rounded-lg bg-gray-900 p-2 text-center text-red-300">Xato<br><b>${totalData.wrong}</b></div>
                  <div class="rounded-lg bg-gray-900 p-2 text-center text-amber-300">Timer<br><b>${test.timerMin ? test.timerMin + 'm' : '-'}</b></div>
                </div>
              </div>
            `;
          }).join('') : '<div class="text-gray-300">Test topilmadi</div>'}
        </div>

        ${selected ? `
          <div class="mt-5 rounded-xl bg-gray-800 border border-gray-700 p-4">
            <div class="flex justify-between items-center mb-3">
              <h3 class="text-xl font-bold text-white">${selected.name} · Statistikalar</h3>
              <button onclick="state.viewingStatsTestId=null;render()" class="px-3 py-1 rounded bg-gray-700 text-white">Yopish</button>
            </div>
            <div class="grid md:grid-cols-3 gap-3 mb-3">
              <div class="rounded-lg p-3 bg-green-900/40 border border-green-700 text-green-300">TOP: <b>${groupStats(selected.results || []).top}</b></div>
              <div class="rounded-lg p-3 bg-yellow-900/40 border border-yellow-700 text-yellow-300">NORMAL: <b>${groupStats(selected.results || []).normal}</b></div>
              <div class="rounded-lg p-3 bg-red-900/40 border border-red-700 text-red-300">MIN: <b>${groupStats(selected.results || []).min}</b></div>
            </div>
            <div class="space-y-2 max-h-64 overflow-y-auto">
              ${(selected.results || []).length ? selected.results.map(u => {
                const last = u.scores[u.scores.length - 1];
                const best = Math.max(...u.scores.map(s => s.percentage));
                return `<div class="rounded-lg bg-gray-900 p-3 border border-gray-700 text-white">${u.username} · urinish ${u.attempts} · oxirgi ${last.correct}/${last.total} (${last.percentage}%) · best ${best}%</div>`;
              }).join('') : '<div class="text-gray-300">Natija yo\'q</div>'}
            </div>
          </div>
        ` : ''}
      </div>
    </div>
  `;
}

function render() {
  document.getElementById('app').innerHTML = `
    <div class="max-w-6xl mx-auto">
      <div class="fixed top-4 right-4 z-40 flex gap-3">
        ${state.syncing ? '<span class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm">Sync...</span>' : ''}
        <button onclick="state.theme = state.theme === 'dark' ? 'light' : 'dark'; applyTheme(); render();" class="p-3 rounded-xl bg-white dark:bg-gray-800 shadow text-gray-700 dark:text-white">
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
    answerInput.addEventListener('input', e => { state.quizAnswer = e.target.value; });
    answerInput.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitAnswerAndAutoNext();
      }
    });
  }
}

setInterval(tickAutoNext, 120);
setInterval(() => {
  if (state.adminLogged && !state.quiz) syncState();
}, 12000);

loadClientState();
applyTheme();
syncState();
</script>
</body>
</html>

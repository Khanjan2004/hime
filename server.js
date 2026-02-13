import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const publicDir = path.join(__dirname, 'public');

const port = Number(process.env.PORT || 3000);
const ADMIN_PANEL_CODE = process.env.ADMIN_PANEL_CODE || 'change-me-in-env';

function sendJson(res, code, payload) {
  res.writeHead(code, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(payload));
}

function serveFile(res, filePath) {
  const ext = path.extname(filePath);
  const map = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json' };
  const type = map[ext] || 'text/plain';
  fs.readFile(filePath, (err, data) => {
    if (err) {
      res.writeHead(404);
      return res.end('Not found');
    }
    res.writeHead(200, { 'Content-Type': type });
    return res.end(data);
  });
}

const server = http.createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/api/admin/login') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      try {
        const parsed = JSON.parse(body || '{}');
        if (String(parsed.code || '') !== ADMIN_PANEL_CODE) {
          return sendJson(res, 401, { ok: false, message: 'Invalid admin code' });
        }
        return sendJson(res, 200, { ok: true });
      } catch {
        return sendJson(res, 400, { ok: false, message: 'Bad request' });
      }
    });
    return;
  }

  let reqPath = req.url || '/';
  if (reqPath === '/') reqPath = '/index.html';
  const safePath = path.normalize(reqPath).replace(/^\/+/, '');
  const filePath = path.join(publicDir, safePath);

  if (!filePath.startsWith(publicDir)) {
    res.writeHead(403);
    return res.end('Forbidden');
  }

  if (fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
    return serveFile(res, filePath);
  }

  return serveFile(res, path.join(publicDir, 'index.html'));
});

server.listen(port, '0.0.0.0', () => {
  console.log(`Vocabulary system running on http://0.0.0.0:${port}`);
});

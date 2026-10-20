// One command to run everything locally:  npm start
// Starts the PHP backend (SQLite, scans this computer's network), the dashboard, and a scan every 2 minutes.
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.PHP_BIN || 'php';
const API_PORT = process.env.API_PORT || '8080';

const check = spawnSync(php, ['-r', 'echo extension_loaded("pdo_sqlite") ? "ok" : "no-sqlite";'], { encoding: 'utf8' });
if (check.error) {
  console.error(`PHP was not found ("${php}"). Install PHP (https://www.php.net/downloads, or XAMPP), then either add it to your PATH or run:\n  PHP_BIN=/full/path/to/php npm start`);
  process.exit(1);
}
if (check.stdout.trim() !== 'ok') {
  console.error('Your PHP has no SQLite support. Enable "extension=pdo_sqlite" and "extension=sqlite3" in php.ini, then try again.');
  process.exit(1);
}

const children = [];
const run = (name, cmd, args, env = {}) => {
  const child = spawn(cmd, args, { cwd: root, env: { ...process.env, ...env }, stdio: 'inherit', shell: process.platform === 'win32' && cmd === 'npx' });
  child.on('exit', (code) => { console.log(`[${name}] stopped (${code})`); shutdown(); });
  children.push(child);
};
function shutdown() { children.forEach((c) => c.kill()); process.exit(0); }
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

run('backend', php, ['-S', `127.0.0.1:${API_PORT}`, '-t', 'backend'], { PHP_CLI_SERVER_WORKERS: '4' });
run('dashboard', 'npx', ['vite', '--host', '127.0.0.1', '--port', '5173'], { VITE_API_BASE_URL: `http://127.0.0.1:${API_PORT}` });

// Scheduled scan so alerts work even when the dashboard is closed.
setInterval(() => {
  const r = spawnSync(php, ['backend/cli_scan.php'], { cwd: root, encoding: 'utf8' });
  if (r.stdout.trim()) console.log(r.stdout.trim());
  if (r.stderr.trim()) console.error(r.stderr.trim());
}, 120000);

console.log(`\nDashboard: http://127.0.0.1:5173  (create an account, then press "Scan now")\nPress Ctrl+C to stop.\n`);

// The backend address is chosen at runtime (typed on the sign-in page and remembered in the browser), so the same
// deployed dashboard can talk to a local backend or to a home backend reached through a tunnel.
const DEFAULT_BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost/network-monitor-api').replace(/\/$/, '');
const TOKEN_KEY = 'netmon_token';
const BASE_KEY = 'netmon_api_base';

export function getApiBase() {
  try { return localStorage.getItem(BASE_KEY) || DEFAULT_BASE; } catch { return DEFAULT_BASE; }
}

// Cleans what the user typed: adds https:// (http:// for localhost), drops a trailing slash or /index.php.
export function cleanApiBase(input) {
  let v = input.trim().replace(/\/index\.php\/?$/i, '').replace(/\/+$/, '');
  if (v && !/^https?:\/\//i.test(v)) v = `${/^(localhost|127\.)/i.test(v) ? 'http' : 'https'}://${v}`;
  return v;
}

export function setApiBase(input) {
  const v = cleanApiBase(input);
  try { v && v !== DEFAULT_BASE ? localStorage.setItem(BASE_KEY, v) : localStorage.removeItem(BASE_KEY); } catch { /* storage unavailable */ }
  return v || DEFAULT_BASE;
}

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

export function getToken() {
  try { return sessionStorage.getItem(TOKEN_KEY) || ''; } catch { return ''; }
}
export function setToken(token) {
  try { token ? sessionStorage.setItem(TOKEN_KEY, token) : sessionStorage.removeItem(TOKEN_KEY); } catch { /* storage unavailable */ }
}

async function request(action, { method = 'GET', body, params = {} } = {}) {
  const query = new URLSearchParams({ action, ...params });
  const base = getApiBase();
  let res;
  try {
    res = await fetch(`${base}/index.php?${query}`, {
      method,
      // ngrok-skip-browser-warning stops ngrok's free plan from answering with an HTML warning page
      headers: { 'Content-Type': 'application/json', 'X-Auth-Token': getToken(), 'ngrok-skip-browser-warning': 'true' },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new Error(`Cannot reach the backend at ${base}. Is it running (and, for online use, is the tunnel started and the address correct)?`);
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && action !== 'login') onUnauthorized();
  if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
  return data;
}

export const api = {
  login: (password) => request('login', { method: 'POST', body: { password } }),
  status: () => request('status'),
  devices: async () => (await request('devices')).map((d) => ({ ...d, online: !!+d.online, suspicious: !!+d.suspicious })),
  alerts: () => request('alerts'),
  history: (mac) => request('history', { params: mac ? { mac } : {} }),
  scan: () => request('scan', { method: 'POST' }),
  ackAlert: (id) => request('alert_ack', { method: 'POST', body: { id } }),
  updateDevice: (mac, changes) => request('device_update', { method: 'POST', body: { mac, ...changes } }),
  deleteDevice: (mac) => request('device_delete', { method: 'POST', body: { mac } }),
  saveSettings: (alert_email) => request('settings', { method: 'POST', body: { alert_email } }),
};

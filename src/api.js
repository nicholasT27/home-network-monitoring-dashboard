const BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost/network-monitor-api').replace(/\/$/, '');
const TOKEN_KEY = 'netmon_token';

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
  let res;
  try {
    res = await fetch(`${BASE}/index.php?${query}`, {
      method,
      headers: { 'Content-Type': 'application/json', 'X-Auth-Token': getToken() },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new Error(`Cannot reach the monitoring API at ${BASE}. Is Apache running?`);
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

import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Activity,
  AlertTriangle,
  Check,
  CirclePlus,
  Clock3,
  Edit3,
  LoaderCircle,
  Network,
  Radar,
  RefreshCw,
  Router,
  ShieldCheck,
  Trash2,
  Wifi,
  WifiOff,
  X,
} from 'lucide-react';

const API_BASE = (import.meta.env.VITE_API_BASE_URL || 'http://localhost/network-monitor-api').replace(/\/$/, '');

const emptyForm = { device_name: '', ip_address: '', mac_address: '' };

async function apiRequest(path, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (options.body) {
    headers['Content-Type'] = 'application/json';
  }

  let response;
  try {
    response = await fetch(`${API_BASE}/${path}`, {
      ...options,
      headers,
    });
  } catch {
    throw new Error('Cannot reach the local API. Start Apache and MySQL in XAMPP, then refresh.');
  }

  const text = await response.text();
  let payload = {};
  try {
    payload = text ? JSON.parse(text) : {};
  } catch {
    throw new Error('The server returned an invalid response. Check that Apache and MySQL are running.');
  }

  if (!response.ok || payload.error) {
    throw new Error(payload.error || `Request failed (${response.status})`);
  }

  return payload;
}

function formatDate(value) {
  if (!value) return 'Never checked';
  const normalized = String(value).replace(' ', 'T');
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
}

function StatusBadge({ status }) {
  const normalized = String(status || 'unknown').toLowerCase();
  const icon = normalized === 'online' ? <Wifi size={14} /> : normalized === 'offline' ? <WifiOff size={14} /> : <Clock3 size={14} />;
  return <span className={`status-badge ${normalized}`}>{icon}{normalized}</span>;
}

function MetricCard({ label, value, hint, icon: Icon, tone = 'blue' }) {
  return (
    <article className={`metric-card ${tone}`}>
      <div className="metric-icon"><Icon size={20} /></div>
      <div>
        <p>{label}</p>
        <strong>{value}</strong>
        <span>{hint}</span>
      </div>
    </article>
  );
}

function DeviceModal({ device, onClose, onSave, saving }) {
  const [form, setForm] = useState(device ? {
    device_name: device.device_name || '',
    ip_address: device.ip_address || '',
    mac_address: device.mac_address || '',
  } : emptyForm);

  function submit(event) {
    event.preventDefault();
    onSave(form);
  }

  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section className="modal" role="dialog" aria-modal="true" aria-labelledby="device-modal-title" onMouseDown={(event) => event.stopPropagation()}>
        <header>
          <div>
            <span className="eyebrow">Device registry</span>
            <h2 id="device-modal-title">{device ? 'Edit device' : 'Add a device'}</h2>
          </div>
          <button className="icon-button" onClick={onClose} aria-label="Close"><X size={18} /></button>
        </header>
        <form onSubmit={submit}>
          <label>
            Device name
            <input autoFocus required value={form.device_name} onChange={(e) => setForm({ ...form, device_name: e.target.value })} placeholder="Living room router" />
          </label>
          <label>
            IP address
            <input required inputMode="decimal" value={form.ip_address} onChange={(e) => setForm({ ...form, ip_address: e.target.value })} placeholder="192.168.1.1" />
          </label>
          <label>
            MAC address <span className="optional">optional</span>
            <input value={form.mac_address} onChange={(e) => setForm({ ...form, mac_address: e.target.value })} placeholder="AA:BB:CC:DD:EE:FF" />
          </label>
          <footer>
            <button type="button" className="button ghost" onClick={onClose}>Cancel</button>
            <button className="button primary" disabled={saving}>
              {saving ? <LoaderCircle className="spin" size={17} /> : <Check size={17} />}
              {device ? 'Save changes' : 'Add device'}
            </button>
          </footer>
        </form>
      </section>
    </div>
  );
}

function ConfirmDelete({ device, onCancel, onConfirm, deleting }) {
  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={onCancel}>
      <section className="modal confirm-modal" role="alertdialog" aria-modal="true" onMouseDown={(event) => event.stopPropagation()}>
        <div className="danger-mark"><Trash2 size={22} /></div>
        <h2>Remove {device.device_name}?</h2>
        <p>This removes the device and its saved alerts. You cannot undo this action.</p>
        <footer>
          <button className="button ghost" onClick={onCancel}>Keep device</button>
          <button className="button danger" onClick={onConfirm} disabled={deleting}>
            {deleting ? <LoaderCircle className="spin" size={17} /> : <Trash2 size={17} />}
            Remove device
          </button>
        </footer>
      </section>
    </div>
  );
}

export default function App() {
  const [devices, setDevices] = useState([]);
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [checking, setChecking] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [modal, setModal] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [notice, setNotice] = useState(null);
  const [lastRefresh, setLastRefresh] = useState(null);
  const [apiConnected, setApiConnected] = useState(null);

  const loadDashboard = useCallback(async (quiet = false) => {
    if (!quiet) setLoading(true);
    try {
      const [deviceData, alertData] = await Promise.all([
        apiRequest('get_devices.php'),
        apiRequest('get_alerts.php'),
      ]);
      setDevices(Array.isArray(deviceData) ? deviceData : []);
      setAlerts(Array.isArray(alertData) ? alertData : []);
      setLastRefresh(new Date());
      setApiConnected(true);
      setNotice(null);
    } catch (error) {
      setApiConnected(false);
      setNotice({ type: 'error', message: error.message });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadDashboard();
  }, [loadDashboard]);

  const metrics = useMemo(() => {
    const online = devices.filter((device) => device.status === 'online').length;
    const offline = devices.filter((device) => device.status === 'offline').length;
    const activeAlerts = alerts.filter((alert) => Number(alert.is_resolved) === 0).length;
    return { total: devices.length, online, offline, activeAlerts };
  }, [devices, alerts]);

  async function saveDevice(form) {
    setSaving(true);
    try {
      const editing = modal?.type === 'edit';
      await apiRequest(editing ? 'update_device.php' : 'add_device.php', {
        method: 'POST',
        body: JSON.stringify(editing ? { id: modal.device.id, ...form } : form),
      });
      setModal(null);
      await loadDashboard(true);
      setNotice({ type: 'success', message: editing ? 'Device updated.' : 'Device added.' });
    } catch (error) {
      setNotice({ type: 'error', message: error.message });
    } finally {
      setSaving(false);
    }
  }

  async function removeDevice() {
    setDeleting(true);
    try {
      await apiRequest('delete_device.php', {
        method: 'POST',
        body: JSON.stringify({ id: deleteTarget.id }),
      });
      setDeleteTarget(null);
      await loadDashboard(true);
      setNotice({ type: 'success', message: 'Device removed.' });
    } catch (error) {
      setNotice({ type: 'error', message: error.message });
    } finally {
      setDeleting(false);
    }
  }

  async function checkNetwork() {
    setChecking(true);
    try {
      const result = await apiRequest('check_all_devices.php');
      await loadDashboard(true);
      setNotice({ type: 'success', message: `${result.checked || 0} device${result.checked === 1 ? '' : 's'} checked.` });
    } catch (error) {
      setNotice({ type: 'error', message: error.message });
    } finally {
      setChecking(false);
    }
  }

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="brand" href="#top" aria-label="Sentinel home">
          <span className="brand-mark"><Radar size={24} /></span>
          <span><strong>Sentinel</strong><small>Network monitor</small></span>
        </a>
        <nav aria-label="Main navigation">
          <a className="active" href="#overview"><Activity size={18} />Overview</a>
          <a href="#devices"><Router size={18} />Devices <span>{devices.length}</span></a>
          <a href="#alerts"><AlertTriangle size={18} />Alerts <span>{metrics.activeAlerts}</span></a>
        </nav>
        <div className="sidebar-status">
          <ShieldCheck size={18} />
          <div><strong>Local monitoring</strong><small>Data stays on this network</small></div>
        </div>
      </aside>

      <main id="top">
        <header className="topbar">
          <div>
            <span className="eyebrow">Home network</span>
            <h1>Monitoring dashboard</h1>
          </div>
          <div className="topbar-actions">
            <span className="refresh-time">{lastRefresh ? `Updated ${lastRefresh.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : 'Connecting…'}</span>
            <button className="button secondary" onClick={() => loadDashboard()} disabled={loading}><RefreshCw className={loading ? 'spin' : ''} size={17} />Refresh</button>
            <button className="button primary" onClick={() => setModal({ type: 'add' })}><CirclePlus size={17} />Add device</button>
          </div>
        </header>

        {notice && (
          <div className={`notice ${notice.type}`} role="status">
            {notice.type === 'error' ? <AlertTriangle size={18} /> : <Check size={18} />}
            <span>{notice.message}</span>
            <button onClick={() => setNotice(null)} aria-label="Dismiss"><X size={16} /></button>
          </div>
        )}

        <section id="overview" className="hero-card">
          <div>
            <span className={`live-chip ${apiConnected === false ? 'offline' : ''}`}>
              <i /> {apiConnected === null ? 'Connecting' : apiConnected ? 'System ready' : 'API unavailable'}
            </span>
            <h2>Your network at a glance.</h2>
            <p>Check every registered device, spot outages, and keep a clean history of important alerts.</p>
          </div>
          <button className="scan-button" onClick={checkNetwork} disabled={checking || loading}>
            <span>{checking ? <LoaderCircle className="spin" size={28} /> : <Radar size={28} />}</span>
            <div><strong>{checking ? 'Checking network…' : 'Run status check'}</strong><small>Ping all registered devices</small></div>
          </button>
        </section>

        <section className="metrics" aria-label="Network summary">
          <MetricCard label="Registered" value={metrics.total} hint="Total devices" icon={Network} />
          <MetricCard label="Online" value={metrics.online} hint="Reachable now" icon={Wifi} tone="green" />
          <MetricCard label="Offline" value={metrics.offline} hint="Needs attention" icon={WifiOff} tone="red" />
          <MetricCard label="Active alerts" value={metrics.activeAlerts} hint="Unresolved events" icon={AlertTriangle} tone="amber" />
        </section>

        <section id="devices" className="panel">
          <header className="panel-header">
            <div><span className="eyebrow">Inventory</span><h2>Registered devices</h2></div>
            <span className="record-count">{devices.length} record{devices.length === 1 ? '' : 's'}</span>
          </header>

          {loading ? (
            <div className="empty-state"><LoaderCircle className="spin" size={28} /><h3>Loading your network</h3></div>
          ) : devices.length === 0 ? (
            <div className="empty-state"><Router size={30} /><h3>No devices yet</h3><p>Add the first device you want to monitor.</p><button className="button primary" onClick={() => setModal({ type: 'add' })}><CirclePlus size={17} />Add device</button></div>
          ) : (
            <div className="table-scroll">
              <table>
                <thead><tr><th>Device</th><th>IP address</th><th>MAC address</th><th>Status</th><th>Last checked</th><th><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>
                  {devices.map((device) => (
                    <tr key={device.id}>
                      <td><div className="device-name"><span><Router size={18} /></span><div><strong>{device.device_name}</strong><small>ID {device.id}</small></div></div></td>
                      <td><code>{device.ip_address}</code></td>
                      <td className="muted">{device.mac_address || '—'}</td>
                      <td><StatusBadge status={device.status} /></td>
                      <td className="muted">{formatDate(device.last_seen)}</td>
                      <td><div className="row-actions"><button className="icon-button" onClick={() => setModal({ type: 'edit', device })} aria-label={`Edit ${device.device_name}`}><Edit3 size={16} /></button><button className="icon-button danger-icon" onClick={() => setDeleteTarget(device)} aria-label={`Delete ${device.device_name}`}><Trash2 size={16} /></button></div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section id="alerts" className="panel alerts-panel">
          <header className="panel-header">
            <div><span className="eyebrow">Event history</span><h2>Recent alerts</h2></div>
            <span className="record-count">Latest 50</span>
          </header>
          {alerts.length === 0 ? (
            <div className="empty-state compact"><ShieldCheck size={30} /><h3>No alerts recorded</h3><p>Your network history is clear.</p></div>
          ) : (
            <div className="alert-list">
              {alerts.map((alert) => {
                const resolved = Number(alert.is_resolved) === 1;
                return (
                  <article className="alert-item" key={alert.id}>
                    <span className={`alert-icon ${resolved ? 'resolved' : ''}`}>{resolved ? <Check size={18} /> : <AlertTriangle size={18} />}</span>
                    <div className="alert-copy"><strong>{alert.device_name || `Device ${alert.device_id}`}</strong><p>{alert.message}</p></div>
                    <div className="alert-meta"><span className={resolved ? 'resolved-label' : 'open-label'}>{resolved ? 'Resolved' : 'Open'}</span><time>{formatDate(alert.created_at)}</time></div>
                  </article>
                );
              })}
            </div>
          )}
        </section>

        <footer className="page-footer">Sentinel runs locally through XAMPP · API: {API_BASE}</footer>
      </main>

      {modal && <DeviceModal device={modal.device} onClose={() => setModal(null)} onSave={saveDevice} saving={saving} />}
      {deleteTarget && <ConfirmDelete device={deleteTarget} onCancel={() => setDeleteTarget(null)} onConfirm={removeDevice} deleting={deleting} />}
    </div>
  );
}

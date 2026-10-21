import { useCallback, useEffect, useState } from 'react';
import { LogOut, Radar, RefreshCw } from 'lucide-react';
import { api, getToken, setToken, setUnauthorizedHandler } from './api.js';
import { timeAgo, fullTime } from './format.js';
import Login from './components/Login.jsx';
import DeviceList from './components/DeviceList.jsx';
import { ActivityPanel, AgentPanel, AlertsPanel, SettingsPanel } from './components/SidePanels.jsx';

const POLL_MS = 15000;

function Tile({ label, value, tone, hint }) {
  return (
    <div className={`tile ${tone || ''}`}>
      <span className="tile-label">{label}</span>
      <strong className="tile-value">{value}</strong>
      {hint && <span className="tile-hint">{hint}</span>}
    </div>
  );
}

async function waitForAgentScan(previousId) {
  for (let i = 0; i < 60; i++) {
    await new Promise((resolve) => setTimeout(resolve, 2000));
    const s = await api.status();
    if (s.last_scan && s.last_scan.id !== previousId) return s.last_scan;
  }
  throw new Error('The home agent did not answer. Is agent.php running on your home computer?');
}

function Dashboard({ onSignedOut }) {
  const [devices, setDevices] = useState([]);
  const [alerts, setAlerts] = useState([]);
  const [events, setEvents] = useState([]);
  const [status, setStatus] = useState(null);
  const [error, setError] = useState('');
  const [scanning, setScanning] = useState(false);
  const [notice, setNotice] = useState('');

  const load = useCallback(async () => {
    try {
      const [d, a, e, s] = await Promise.all([api.devices(), api.alerts(), api.history(), api.status()]);
      setDevices(d); setAlerts(a); setEvents(e); setStatus(s); setError('');
    } catch (err) {
      setError(err.message);
    }
  }, []);

  useEffect(() => {
    load();
    const id = setInterval(load, POLL_MS);
    return () => clearInterval(id);
  }, [load]);

  async function scan() {
    setScanning(true);
    setNotice('');
    try {
      let r = await api.scan();
      if (r.queued) {
        // Cloud mode: the home agent picks the request up, scans, and uploads the result.
        setNotice('Scan requested. Waiting for the home agent…');
        r = await waitForAgentScan(status?.last_scan?.id ?? null);
      }
      const secs = (r.duration_ms / 1000).toFixed(1);
      setNotice(`Scan finished in ${secs}s: ${r.devices_found} devices found${r.new_unknown ? `, ${r.new_unknown} new unknown` : ''}.`);
      await load();
    } catch (err) {
      setError(err.message);
    } finally {
      setScanning(false);
    }
  }

  const online = devices.filter((d) => d.online).length;
  const trusted = devices.filter((d) => d.status === 'trusted').length;
  const unknown = devices.filter((d) => d.status === 'unknown').length;
  const suspicious = devices.filter((d) => d.suspicious).length;
  const last = status?.last_scan;
  const target = status?.scan_target_s ?? 120;
  const lastSecs = last ? last.duration_ms / 1000 : null;

  return (
    <div className="shell">
      <header className="top">
        <div>
          <h1>Home Network Monitor</h1>
          <p className="muted small">
            {status ? <>{status.email} · network <span className="mono">{status.subnet}</span>{status.mode === 'demo' && ' · demo data'}
              {status.mode === 'agent' && <> · home agent <strong className={status.agent_online ? 'agent-on' : 'agent-off'}>{status.agent_online ? 'online' : 'offline'}</strong></>}</> : 'Connecting…'}
            {last && <> · last scan <span title={fullTime(last.started_at)}>{timeAgo(last.started_at)}</span> ({lastSecs.toFixed(1)}s of {target}s target)</>}
          </p>
        </div>
        <div className="top-actions">
          <button className="btn primary" onClick={scan} disabled={scanning}>
            {scanning ? <RefreshCw size={16} className="spin" /> : <Radar size={16} />} {scanning ? 'Scanning…' : 'Scan now'}
          </button>
          <button className="btn" onClick={() => { setToken(''); onSignedOut(); }}><LogOut size={16} /> Sign out</button>
        </div>
      </header>

      {error && <p className="banner error" role="alert">{error}</p>}
      {notice && <p className="banner ok" role="status">{notice}</p>}

      <section className="tiles" aria-label="Network summary">
        <Tile label="Devices" value={devices.length} />
        <Tile label="Online" value={online} tone="ok" />
        <Tile label="Trusted" value={trusted} tone="ok" />
        <Tile label="Unknown" value={unknown} tone={unknown ? 'warn' : ''} hint={unknown ? 'Review and trust or remove' : null} />
        <Tile label="Suspicious" value={suspicious} tone={suspicious ? 'bad' : ''} />
      </section>

      <div className="grid">
        <DeviceList devices={devices} onChange={load} />
        <aside className="side">
          {status?.mode === 'agent' && <AgentPanel status={status} />}
          <AlertsPanel alerts={alerts} onChange={load} />
          <ActivityPanel events={events} />
          {status && <SettingsPanel status={status} onSaved={load} />}
        </aside>
      </div>
    </div>
  );
}

export default function App() {
  const [signedIn, setSignedIn] = useState(() => Boolean(getToken()));
  useEffect(() => { setUnauthorizedHandler(() => { setToken(''); setSignedIn(false); }); }, []);
  return signedIn ? <Dashboard onSignedOut={() => setSignedIn(false)} /> : <Login onSignedIn={() => setSignedIn(true)} />;
}

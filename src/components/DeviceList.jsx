import { useEffect, useState } from 'react';
import { Check, History, Pencil, ShieldOff, ShieldCheck, Trash2, X, Eye } from 'lucide-react';
import { api } from '../api.js';
import { EVENT_TEXT, deviceName, fullTime, isPrivateMac, timeAgo } from '../format.js';

function StatusBadge({ d }) {
  if (d.suspicious) return <span className="badge bad">Suspicious</span>;
  return d.status === 'trusted' ? <span className="badge ok">Trusted</span> : <span className="badge warn">Unknown</span>;
}

function HistoryPanel({ mac }) {
  const [rows, setRows] = useState(null);
  const [error, setError] = useState('');
  useEffect(() => {
    api.history(mac).then(setRows).catch((e) => setError(e.message));
  }, [mac]);
  if (error) return <p className="error">{error}</p>;
  if (!rows) return <p className="muted">Loading history…</p>;
  if (!rows.length) return <p className="muted">No history yet.</p>;
  return (
    <ul className="timeline">
      {rows.map((r) => (
        <li key={r.id}>
          <span>{EVENT_TEXT[r.event] || r.event}{r.ip ? ` · ${r.ip}` : ''}</span>
          <time title={fullTime(r.event_time)}>{fullTime(r.event_time)}</time>
        </li>
      ))}
    </ul>
  );
}

function Row({ d, onChange }) {
  const [editing, setEditing] = useState(false);
  const [label, setLabel] = useState(d.label || '');
  const [showHistory, setShowHistory] = useState(false);
  const [busy, setBusy] = useState(false);

  async function act(fn) {
    setBusy(true);
    try { await fn(); await onChange(); } catch (e) { alert(e.message); } finally { setBusy(false); }
  }
  const saveLabel = () => act(async () => { await api.updateDevice(d.mac, { label }); setEditing(false); });

  return (
    <li className={`device ${d.online ? 'is-online' : 'is-offline'} ${d.suspicious ? 'is-suspicious' : ''}`}>
      <div className="device-main">
        <span className={`dot ${d.online ? 'on' : 'off'}`} title={d.online ? 'Online' : 'Offline'} role="img" aria-label={d.online ? 'Online' : 'Offline'} />
        <div className="device-id">
          {editing ? (
            <form className="rename" onSubmit={(e) => { e.preventDefault(); saveLabel(); }}>
              <input autoFocus value={label} onChange={(e) => setLabel(e.target.value)} placeholder={d.hostname || 'Device name'} aria-label="Device name" maxLength={100} />
              <button className="icon-btn" title="Save name" aria-label="Save name" disabled={busy}><Check size={16} /></button>
              <button type="button" className="icon-btn" title="Cancel" aria-label="Cancel" onClick={() => setEditing(false)}><X size={16} /></button>
            </form>
          ) : (
            <strong className="device-name">{deviceName(d)}</strong>
          )}
          <span className="muted mono">{d.ip} · {d.mac}</span>
          <span className="muted small">
            {d.vendor || 'Unknown manufacturer'}{d.hostname && d.label ? ` · ${d.hostname}` : ''}
            {isPrivateMac(d.mac) && !d.vendor?.startsWith('Private') ? ' · Private MAC' : ''}
          </span>
        </div>
        <div className="device-state">
          <StatusBadge d={d} />
          <span className="muted small" title={fullTime(d.last_seen)}>{d.online ? 'Online now' : `Seen ${timeAgo(d.last_seen)}`}</span>
        </div>
        <div className="device-actions">
          {d.suspicious && (
            <button className="btn small" disabled={busy} onClick={() => act(() => api.updateDevice(d.mac, { clear_suspicious: true }))}><Eye size={14} /> Dismiss</button>
          )}
          {d.status === 'trusted'
            ? <button className="btn small" disabled={busy} onClick={() => act(() => api.updateDevice(d.mac, { status: 'unknown' }))}><ShieldOff size={14} /> Untrust</button>
            : <button className="btn small primary" disabled={busy} onClick={() => act(() => api.updateDevice(d.mac, { status: 'trusted' }))}><ShieldCheck size={14} /> Trust</button>}
          <button className="icon-btn" title="Rename" aria-label={`Rename ${deviceName(d)}`} onClick={() => setEditing(true)}><Pencil size={16} /></button>
          <button className={`icon-btn ${showHistory ? 'active' : ''}`} title="History" aria-label={`History of ${deviceName(d)}`} aria-expanded={showHistory} onClick={() => setShowHistory(!showHistory)}><History size={16} /></button>
          <button className="icon-btn danger" title="Remove" aria-label={`Remove ${deviceName(d)}`} disabled={busy}
            onClick={() => confirm(`Remove ${deviceName(d)} from the list? It will be treated as new if it appears again.`) && act(() => api.deleteDevice(d.mac))}><Trash2 size={16} /></button>
        </div>
      </div>
      {d.suspicious && <p className="reason">⚠ {d.suspicious_reason}</p>}
      {showHistory && <div className="device-history"><HistoryPanel mac={d.mac} /></div>}
    </li>
  );
}

const FILTERS = [
  ['all', 'All'], ['online', 'Online'], ['unknown', 'Unknown'], ['trusted', 'Trusted'], ['suspicious', 'Suspicious'],
];

export default function DeviceList({ devices, onChange }) {
  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');

  const match = {
    all: () => true, online: (d) => d.online, unknown: (d) => d.status === 'unknown',
    trusted: (d) => d.status === 'trusted', suspicious: (d) => d.suspicious,
  }[filter];
  const needle = q.trim().toLowerCase();
  const shown = devices.filter(match).filter((d) =>
    !needle || [d.label, d.hostname, d.ip, d.mac, d.vendor].some((v) => (v || '').toLowerCase().includes(needle)));

  return (
    <section className="card" aria-labelledby="devices-h">
      <div className="card-head">
        <h2 id="devices-h">Devices</h2>
        <input className="search" type="search" placeholder="Search name, IP or MAC" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search devices" />
      </div>
      <div className="chips" role="group" aria-label="Filter devices">
        {FILTERS.map(([key, text]) => (
          <button key={key} className={`chip ${filter === key ? 'active' : ''}`} aria-pressed={filter === key} onClick={() => setFilter(key)}>{text}</button>
        ))}
      </div>
      {shown.length === 0
        ? <p className="empty">{devices.length === 0 ? 'No devices yet. Run a scan to discover what is on your network.' : 'No devices match this filter.'}</p>
        : <ul className="devices">{shown.map((d) => <Row key={d.mac} d={d} onChange={onChange} />)}</ul>}
    </section>
  );
}

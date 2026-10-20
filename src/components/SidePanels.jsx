import { useState } from 'react';
import { BellRing, CheckCheck, Mail } from 'lucide-react';
import { api } from '../api.js';
import { EVENT_TEXT, fullTime, timeAgo } from '../format.js';

const TITLES = { unknown_device: 'Unknown device', suspicious_device: 'Suspicious device', baseline: 'Initial scan complete' };

export function AlertsPanel({ alerts, onChange }) {
  const ack = async (id) => { try { await api.ackAlert(id); await onChange(); } catch (e) { alert(e.message); } };
  return (
    <section className="card" aria-labelledby="alerts-h">
      <div className="card-head">
        <h2 id="alerts-h"><BellRing size={16} aria-hidden="true" /> Active alerts {alerts.length > 0 && <span className="count">{alerts.length}</span>}</h2>
        {alerts.length > 1 && <button className="btn small" onClick={() => ack('all')}><CheckCheck size={14} /> Dismiss all</button>}
      </div>
      {alerts.length === 0 ? <p className="empty">All clear. No unrecognised devices.</p> : (
        <ul className="alerts">
          {alerts.map((a) => (
            <li key={a.id} className={a.type === 'suspicious_device' ? 'bad' : a.type === 'baseline' ? 'info' : 'warn'}>
              <div>
                <strong>{TITLES[a.type] || 'Alert'}</strong>
                <p>{a.message}</p>
                {a.type !== 'baseline' && <p className="muted mono small">{a.ip || '-'} · {a.mac}{a.hostname ? ` · ${a.hostname}` : ''}</p>}
                <p className="muted small" title={fullTime(a.created_at)}>
                  {timeAgo(a.created_at)}{a.type !== 'baseline' && (a.email_sent ? ' · email sent' : ' · email not sent')}
                </p>
              </div>
              <button className="btn small" onClick={() => ack(a.id)}>Dismiss</button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export function ActivityPanel({ events }) {
  return (
    <section className="card" aria-labelledby="activity-h">
      <h2 id="activity-h">Recent activity</h2>
      {events.length === 0 ? <p className="empty">Nothing recorded yet.</p> : (
        <ul className="timeline">
          {events.slice(0, 12).map((e) => (
            <li key={e.id}>
              <span>{EVENT_TEXT[e.event] || e.event}<br /><span className="muted small mono">{e.hostname || e.mac} · {e.ip}</span></span>
              <time title={fullTime(e.event_time)}>{timeAgo(e.event_time)}</time>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export function EmailSettings({ email, onSaved }) {
  const [value, setValue] = useState(email || '');
  const [msg, setMsg] = useState('');
  const [testing, setTesting] = useState(false);
  async function sendTest() {
    setTesting(true);
    setMsg('');
    try {
      const r = await api.testEmail();
      setMsg(r.sent ? `Test email sent to ${r.to}. Check your inbox (and spam).` : `Could not send the test email (via ${r.via}). The server's email settings are missing or wrong.`);
    } catch (err) {
      setMsg(err.message);
    } finally {
      setTesting(false);
    }
  }
  async function save(e) {
    e.preventDefault();
    try { await api.saveSettings(value.trim()); setMsg('Saved'); onSaved(); } catch (err) { setMsg(err.message); }
  }
  return (
    <section className="card" aria-labelledby="mail-h">
      <h2 id="mail-h"><Mail size={16} aria-hidden="true" /> Email alerts</h2>
      <p className="muted small">An email is sent when an unknown or suspicious device is detected, even if the dashboard is closed.</p>
      <form className="mail-form" onSubmit={save}>
        <input type="email" placeholder="you@example.com" value={value} onChange={(e) => { setValue(e.target.value); setMsg(''); }} aria-label="Alert email address" />
        <button className="btn primary">Save</button>
      </form>
      <button className="btn small" onClick={sendTest} disabled={testing || !email}>{testing ? 'Sending…' : 'Send test email'}</button>
      {msg && <p className="small" role="status">{msg}</p>}
    </section>
  );
}

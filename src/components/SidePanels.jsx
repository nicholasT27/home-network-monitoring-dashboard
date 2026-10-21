import { useEffect, useState } from 'react';
import { BellRing, CheckCheck, Cpu, Mail, Settings } from 'lucide-react';
import { API_URL, api } from '../api.js';
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

// Mirrors what the server saves: "192.168.0.124/24" -> "192.168.0.0/24".
function networkAddress(cidr) {
  const m = cidr.trim().match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/);
  if (!m) return cidr.trim();
  const prefix = Number(m[5]);
  const ip = m.slice(1, 5).reduce((acc, o) => acc * 256 + Number(o), 0);
  const mask = prefix === 0 ? 0 : (0xffffffff << (32 - prefix)) >>> 0;
  const net = (ip & mask) >>> 0;
  return `${net >>> 24}.${(net >>> 16) & 255}.${(net >>> 8) & 255}.${net & 255}/${prefix}`;
}

function Note({ note }) {
  return note ? <p className={`small ${note.error ? 'error' : 'ok-text'}`} role="status">{note.text}</p> : null;
}

export function SettingsPanel({ status, onSaved }) {
  const [subnet, setSubnet] = useState(status?.subnet || '');
  const [email, setEmail] = useState(status?.alert_email || '');
  const [netNote, setNetNote] = useState(null);
  const [mailNote, setMailNote] = useState(null);
  const [testing, setTesting] = useState(false);
  // Follow the server's values (e.g. after a save or a reload) without resetting the messages.
  useEffect(() => { setSubnet(status.subnet || ''); }, [status.subnet]);
  useEffect(() => { setEmail(status.alert_email || ''); }, [status.alert_email]);

  async function saveNetwork(e) {
    e.preventDefault();
    try {
      await api.saveSettings({ subnet });
      setNetNote({ text: subnet.trim() ? `Network range saved. Scans will now look at ${networkAddress(subnet)}.` : 'Network range cleared. The default range will be used.' });
      onSaved();
    } catch (err) { setNetNote({ text: err.message, error: true }); }
  }
  async function saveEmail(e) {
    e.preventDefault();
    try {
      await api.saveSettings({ alert_email: email });
      setMailNote({ text: email.trim()
        ? `Email saved. We will email ${email.trim()} when a new unknown or suspicious device is detected.`
        : 'Email alerts turned off. No emails will be sent.' });
      onSaved();
    } catch (err) { setMailNote({ text: err.message, error: true }); }
  }
  async function sendTest() {
    setTesting(true);
    setMailNote(null);
    try {
      const r = await api.testEmail();
      setMailNote(r.sent
        ? { text: `Test email sent to ${r.to}. Check your inbox (and spam).` }
        : { text: `Could not send the test email (via ${r.via}). The server's email settings are missing or wrong.`, error: true });
    } catch (err) {
      setMailNote({ text: err.message, error: true });
    } finally {
      setTesting(false);
    }
  }
  const emailChanged = email.trim() !== (status.alert_email || '');

  return (
    <section className="card" aria-labelledby="settings-h">
      <h2 id="settings-h"><Settings size={16} aria-hidden="true" /> Network &amp; alerts</h2>

      <form className="settings-form" onSubmit={saveNetwork}>
        <label htmlFor="subnet">Your network range</label>
        <input id="subnet" value={subnet} onChange={(e) => { setSubnet(e.target.value); setNetNote(null); }} placeholder="192.168.0.0/24" className="mono" />
        <p className="muted small">Take your computer's IP (for example 192.168.0.124), keep the first three numbers and end with .0/24.</p>
        <div className="row"><button className="btn primary">Save network</button></div>
        <Note note={netNote} />
      </form>

      <hr className="divider" />

      <form className="settings-form" onSubmit={saveEmail}>
        <label htmlFor="alert-email"><Mail size={14} aria-hidden="true" /> Email for alerts</label>
        <input id="alert-email" type="email" value={email} onChange={(e) => { setEmail(e.target.value); setMailNote(null); }} placeholder="you@example.com" />
        <p className="muted small">An email is sent when a new unknown or suspicious device is detected, even if the dashboard is closed.</p>
        <div className="row">
          <button className="btn primary">Save email</button>
          <button type="button" className="btn" onClick={sendTest} disabled={testing || !status.alert_email || emailChanged}
            title={emailChanged ? 'Save the email first' : undefined}>{testing ? 'Sending…' : 'Send test email'}</button>
        </div>
        <Note note={mailNote} />
      </form>
    </section>
  );
}

export function AgentPanel({ status }) {
  const [copied, setCopied] = useState('');
  const settings = `AGENT_API_URL=${new URL(API_URL, window.location.href).href}\nAGENT_KEY=${status.agent_key}`;
  async function copy(text, what) {
    try { await navigator.clipboard.writeText(text); setCopied(what); setTimeout(() => setCopied(''), 2000); } catch { /* clipboard unavailable */ }
  }
  return (
    <section className="card" aria-labelledby="agent-h">
      <h2 id="agent-h"><Cpu size={16} aria-hidden="true" /> Home agent <span className={`badge ${status.agent_online ? 'ok' : 'bad'}`}>{status.agent_online ? 'Online' : 'Offline'}</span></h2>
      <p className="muted small">A website cannot see inside your home network, so a small agent must run on a computer connected to your router. It scans your network and sends the results here. It only makes outgoing connections.</p>
      <ol className="steps small">
        <li>On that computer install <strong>PHP</strong> and download this project's <code>backend</code> folder from GitHub.</li>
        <li>In that folder, create a file named <code>.env</code> with these two lines:
          <pre className="code">{settings}</pre>
          <button className="btn small" onClick={() => copy(settings, 'settings')}>{copied === 'settings' ? 'Copied' : 'Copy'}</button>
        </li>
        <li>Run <code>php agent.php</code> and leave it open. This panel turns <strong>Online</strong>.</li>
      </ol>
      <p className="muted small">Keep your agent key private: it lets a program send data to your account.</p>
    </section>
  );
}

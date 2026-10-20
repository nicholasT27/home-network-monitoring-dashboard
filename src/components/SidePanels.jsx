import { useState } from 'react';
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

export function SettingsPanel({ status, onSaved }) {
  const [subnet, setSubnet] = useState(status?.subnet || '');
  const [email, setEmail] = useState(status?.alert_email || '');
  const [smtpUser, setSmtpUser] = useState(status?.smtp_user || '');
  const [smtpPass, setSmtpPass] = useState('');
  const [msg, setMsg] = useState('');
  const [testing, setTesting] = useState(false);

  async function save(e) {
    e.preventDefault();
    try {
      const changes = { subnet, alert_email: email };
      if (status?.can_configure_email) { changes.smtp_user = smtpUser; changes.smtp_pass = smtpPass; }
      await api.saveSettings(changes);
      setSmtpPass('');
      setMsg('Saved');
      onSaved();
    } catch (err) { setMsg(err.message); }
  }
  async function sendTest() {
    setTesting(true);
    setMsg('');
    try {
      const r = await api.testEmail();
      setMsg(r.sent ? `Test email sent to ${r.to}. Check your inbox (and spam).` : `Could not send the test email${r.error ? `: ${r.error}` : ''}. Check the sender account below.`);
    } catch (err) {
      setMsg(err.message);
    } finally {
      setTesting(false);
    }
  }

  return (
    <section className="card" aria-labelledby="settings-h">
      <h2 id="settings-h"><Settings size={16} aria-hidden="true" /> Network &amp; alerts</h2>
      <form className="settings-form" onSubmit={save}>
        <label htmlFor="subnet">Your network range</label>
        <input id="subnet" value={subnet} onChange={(e) => { setSubnet(e.target.value); setMsg(''); }} placeholder="192.168.0.0/24" className="mono" />
        <p className="muted small">{status?.can_configure_email !== undefined
          ? 'Detected automatically from this computer. Change it only if the scan finds the wrong network.'
          : "Take your computer's IP (for example 192.168.0.124), keep the first three numbers and end with .0/24."}</p>
        <label htmlFor="alert-email"><Mail size={14} aria-hidden="true" /> Email for alerts</label>
        <input id="alert-email" type="email" value={email} onChange={(e) => { setEmail(e.target.value); setMsg(''); }} placeholder="you@example.com" />
        <p className="muted small">An email is sent when an unknown or suspicious device is detected, even if the dashboard is closed.</p>
        {status?.can_configure_email && (
          <>
            <label htmlFor="smtp-user">Send alerts from (Gmail address)</label>
            <input id="smtp-user" type="email" value={smtpUser} onChange={(e) => { setSmtpUser(e.target.value); setMsg(''); }} placeholder="project.account@gmail.com" />
            <label htmlFor="smtp-pass">Gmail app password</label>
            <input id="smtp-pass" type="password" value={smtpPass} onChange={(e) => { setSmtpPass(e.target.value); setMsg(''); }}
              placeholder={status.smtp_configured ? 'Saved (leave blank to keep)' : '16-character app password'} autoComplete="new-password" />
            <p className="muted small">Google Account → Security → 2-Step Verification → App passwords. Use a project account, not your main one.</p>
          </>
        )}
        <div className="row">
          <button className="btn primary">Save</button>
          <button type="button" className="btn" onClick={sendTest} disabled={testing || !status?.alert_email}>{testing ? 'Sending…' : 'Send test email'}</button>
        </div>
      </form>
      {msg && <p className="small" role="status">{msg}</p>}
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

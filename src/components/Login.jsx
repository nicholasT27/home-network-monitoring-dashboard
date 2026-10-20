import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { api, cleanApiBase, getApiBase, setApiBase, setToken } from '../api.js';

const isLocalPage = ['localhost', '127.0.0.1'].includes(window.location.hostname);

export default function Login({ onSignedIn }) {
  const [password, setPassword] = useState('');
  const [backend, setBackend] = useState(getApiBase());
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setError('');
    const address = cleanApiBase(backend);
    if (window.location.protocol === 'https:' && address.startsWith('http://') && !/^http:\/\/(localhost|127\.)/i.test(address)) {
      setError('This page is secure (https), so the backend address must also start with https:// (use your tunnel address).');
      return;
    }
    setBusy(true);
    try {
      setApiBase(address);
      const { token } = await api.login(password);
      setToken(token);
      onSignedIn();
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="login">
      <form className="card login-card" onSubmit={submit}>
        <ShieldCheck size={32} className="login-icon" aria-hidden="true" />
        <h1>Home Network Monitor</h1>
        <p className="muted">Sign in to see which devices are on your network.</p>
        <label htmlFor="pw">Dashboard password</label>
        <input id="pw" type="password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" />
        <details open={!isLocalPage}>
          <summary className="link">Backend address {isLocalPage ? '(advanced)' : ''}</summary>
          <input id="backend" className="mono" value={backend} onChange={(e) => setBackend(e.target.value)} aria-label="Backend address"
            placeholder="https://your-tunnel.trycloudflare.com" spellCheck="false" />
          <p className="muted small">Where your home backend is. For online use, paste the address your tunnel printed (for example https://something.trycloudflare.com).</p>
        </details>
        {error && <p className="error" role="alert">{error}</p>}
        <button className="btn primary" disabled={busy || !password || !backend.trim()}>{busy ? 'Signing in…' : 'Sign in'}</button>
      </form>
    </main>
  );
}

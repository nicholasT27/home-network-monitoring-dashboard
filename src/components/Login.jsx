import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { api, setToken } from '../api.js';

export default function Login({ onSignedIn }) {
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
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
        {error && <p className="error" role="alert">{error}</p>}
        <button className="btn primary" disabled={busy || !password}>{busy ? 'Signing in…' : 'Sign in'}</button>
      </form>
    </main>
  );
}

import { useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { api, setToken } from '../api.js';

export default function Login({ onSignedIn }) {
  const [mode, setMode] = useState('login');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const registering = mode === 'register';

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      const { token } = await (registering ? api.register : api.login)(email, password);
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
        <p className="muted">{registering ? 'Create an account to monitor your own home network.' : 'Sign in to see which devices are on your network.'}</p>
        <label htmlFor="email">Email</label>
        <input id="email" type="email" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
        <label htmlFor="pw">Password {registering && <span className="muted">(at least 8 characters)</span>}</label>
        <input id="pw" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete={registering ? 'new-password' : 'current-password'} />
        {error && <p className="error" role="alert">{error}</p>}
        <button className="btn primary" disabled={busy || !email || !password}>{busy ? 'Please wait…' : registering ? 'Create account' : 'Sign in'}</button>
        <button type="button" className="link" onClick={() => { setMode(registering ? 'login' : 'register'); setError(''); }}>
          {registering ? 'Already have an account? Sign in' : 'New here? Create an account'}
        </button>
      </form>
    </main>
  );
}

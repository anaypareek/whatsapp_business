import { useState } from 'react'
import { Mail, Lock, Eye, EyeOff, MessageCircle, CheckCheck, ShieldCheck, Zap, BarChart3 } from 'lucide-react'
import { api, setToken } from './api'
import { Button } from './ui'

function PhoneArt() {
  return (<div className="art">
    <div className="art-phone">
      <div className="art-top"><span className="art-av" /><div><b>Fineoutput</b><small>online</small></div></div>
      <div className="art-chat">
        <div className="art-b in">Hi! Is my order shipped? 📦</div>
        <div className="art-b out">Yes! Order #OS-1001 is on the way 🚚<span><CheckCheck size={13} /></span></div>
        <div className="art-b in">Great, thanks 🙌</div>
        <div className="art-b out tpl"><b>Order confirmed</b>Hi Ravi, your order of ₹1,440 is confirmed.<em>Track order ↗</em></div>
        <div className="art-typing"><i /><i /><i /></div>
      </div>
    </div>
    <div className="art-float f1"><BarChart3 size={16} /> 98% delivered</div>
    <div className="art-float f2"><Zap size={16} /> Auto-reply sent</div>
  </div>)
}

export default function Login({ onLogin, endpoint = '/auth/login', brand = 'WA Business Fineoutput Portal', sub = 'Admin panel', heading = 'Welcome back 👋', blurb = 'Sign in to manage your WhatsApp Business account', footer = 'Forgot? Ask your admin' }) {
  const [email, setEmail] = useState(''); const [password, setPassword] = useState('')
  const [show, setShow] = useState(false); const [remember, setRemember] = useState(true)
  const [err, setErr] = useState(''); const [busy, setBusy] = useState(false)
  const submit = async (e) => {
    e.preventDefault(); setErr(''); setBusy(true)
    try { const r = await api(endpoint, { method: 'POST', body: { email, password } }); setToken(r.token, remember); onLogin(r.user || r.client) }
    catch (e) { setErr(e.message) } finally { setBusy(false) }
  }
  return (
    <div className="login">
      <div className="login-brand">
        <div className="logo light"><span className="logo-mark"><MessageCircle size={22} /></span><div><b>{brand}</b><small>{sub}</small></div></div>
        <div className="login-copy">
          <h2>All your WhatsApp conversations, <span>one portal.</span></h2>
          <p>Send order alerts, reply to customers, manage templates and automate replies on the official WhatsApp Cloud API.</p>
          <ul><li><CheckCheck size={18} /> Multi-number shared inbox</li><li><ShieldCheck size={18} /> Approved templates & signed webhooks</li><li><Zap size={18} /> Keyword auto-replies & REST API</li></ul>
        </div>
        <PhoneArt />
        <div className="blob b1" /><div className="blob b2" />
      </div>
      <div className="login-side">
        <form className="login-card" onSubmit={submit}>
          <div className="login-head"><span className="logo-mark big"><MessageCircle size={26} /></span><h1>{heading}</h1><p>{blurb}</p></div>
          <label className="field"><span className="field-label">Email address</span>
            <div className="input-ico"><Mail size={16} className="ico" /><input type="email" autoComplete="username" placeholder="admin@company.com" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus /></div></label>
          <label className="field"><span className="field-label">Password</span>
            <div className="input-ico"><Lock size={16} className="ico" /><input type={show ? 'text' : 'password'} autoComplete="current-password" placeholder="••••••••" value={password} onChange={(e) => setPassword(e.target.value)} required />
              <button type="button" className="eye" onClick={() => setShow(!show)} aria-label={show ? 'Hide password' : 'Show password'}>{show ? <EyeOff size={16} /> : <Eye size={16} />}</button></div></label>
          <div className="row between"><label className="check"><input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} /> Remember me</label>
            <span className="muted small">{footer}</span></div>
          {err && <div className="alert error">{err}</div>}
          <Button type="submit" variant="primary" size="lg" loading={busy} className="w100">{busy ? 'Signing in…' : 'Sign in'}</Button>
          <p className="login-foot">Secured with Laravel Sanctum · © {new Date().getFullYear()} Fineoutput</p>
        </form>
      </div>
    </div>
  )
}

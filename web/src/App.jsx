import { useEffect, useState } from 'react'
import { api, getToken, setToken } from './api'
import { Templates } from './templates'
import { Login, Users, Numbers, SendMessage, Chat, AutoReplies, Settings, ApiKeys } from './pages'

const TABS = [
  ['chat', 'Chat Master'], ['send', 'Send Message'], ['templates', 'Template Master'], ['numbers', 'Number Master'], ['rules', 'Auto-replies'],
  ['users', 'Users', true], ['keys', 'API Keys', true], ['settings', 'Settings', true],
]

export default function App() {
  const [user, setUser] = useState(null)
  const [tab, setTab] = useState('chat')
  const [booting, setBooting] = useState(!!getToken())

  useEffect(() => {
    if (getToken()) api('/auth/me').then(setUser).catch(() => setToken(null)).finally(() => setBooting(false))
  }, [])

  if (booting) return <div className="center">Loading…</div>
  if (!user) return <Login onLogin={(u) => setUser(u)} />

  const logout = async () => { try { await api('/auth/logout', { method: 'POST' }) } catch { /* ignore */ } setToken(null); setUser(null) }
  const Page = { chat: Chat, send: SendMessage, templates: Templates, numbers: Numbers, rules: AutoReplies, users: Users, keys: ApiKeys, settings: Settings }[tab]

  return (
    <div className="shell">
      <aside className="nav">
        <div className="brand">WhatsApp Business</div>
        {TABS.filter(([, , admin]) => !admin || user.is_admin).map(([k, label]) => (
          <button key={k} className={tab === k ? 'active' : ''} onClick={() => setTab(k)}>{label}</button>
        ))}
        <div className="me">{user.name}<br /><small>{user.email}</small><button className="link" onClick={logout}>Log out</button></div>
      </aside>
      <main className={tab === 'chat' ? 'main chatmain' : 'main'}><Page user={user} /></main>
    </div>
  )
}

import { useEffect, useRef, useState } from 'react'
import { LayoutDashboard, MessagesSquare, Send, FileText, Phone, Bot, Users as UsersIcon, KeyRound, Settings as SettingsIcon, LogOut, Menu, ChevronDown, Bell, MessageCircle, Briefcase, PhoneCall, ClipboardList, Workflow, Bug, Camera } from 'lucide-react'
import { api, getToken, setToken } from './api'
import { Spinner } from './ui'
import { ADMIN_BASE } from './config'
import Login from './Login'
import Dashboard from './Dashboard'
import { Templates } from './templates'
import { Clients } from './clients'
import { NumberRequests, TemplateRequests } from './requests'
import { Flows } from './flows'
import { ErrorLogs } from './errorlogs'
import { Instagram } from './instagram'
import { Users, Numbers, SendMessage, Chat, AutoReplies, Settings, ApiKeys } from './pages'

const NAV = [
  { group: 'Overview', items: [['dashboard', 'Dashboard', LayoutDashboard]] },
  { group: 'Messaging', items: [['chat', 'Chat Master', MessagesSquare], ['send', 'Send Message', Send], ['templates', 'Template Master', FileText], ['flows', 'Automation Flows', Workflow], ['instagram', 'Instagram', Camera], ['rules', 'Auto-replies', Bot]] },
  { group: 'Requests', items: [['number-requests', 'Number Requests', PhoneCall, true], ['template-requests', 'Template Requests', ClipboardList, true]] },
  { group: 'Setup', items: [['clients', 'Clients', Briefcase, true], ['numbers', 'Number Master', Phone], ['users', 'Users', UsersIcon, true], ['keys', 'API Keys', KeyRound, true], ['settings', 'Settings', SettingsIcon, true], ['error-logs', 'Error Logs', Bug, true]] },
]
const PAGES = { 'error-logs': ErrorLogs, flows: () => <Flows scope="admin" />, instagram: () => <Instagram scope="admin" />, 'number-requests': NumberRequests, 'template-requests': TemplateRequests, clients: Clients, dashboard: Dashboard, chat: Chat, send: SendMessage, templates: Templates, numbers: Numbers, rules: AutoReplies, users: Users, keys: ApiKeys, settings: Settings }
const TITLES = Object.fromEntries(NAV.flatMap((g) => g.items.map(([k, l]) => [k, l])))

export default function App() {
  const [user, setUser] = useState(null)
  const fromPath = () => { const seg = location.pathname.slice(ADMIN_BASE.length).replace(/^\/+|\/+$/g, ''); return seg in PAGES ? seg : 'dashboard' }
  const [tab, setTab] = useState(fromPath)
  const [booting, setBooting] = useState(!!getToken())
  const [navOpen, setNavOpen] = useState(false)
  const [menu, setMenu] = useState(false)
  const [unread, setUnread] = useState(0)
  const [pending, setPending] = useState({ numbers: 0, templates: 0 })
  const [errCount, setErrCount] = useState(0)
  const loadPending = () => { if (!user?.is_admin) return; api('/requests/pending-counts').then(setPending).catch(() => {}); api('/error-logs/count').then((c) => setErrCount(c.open)).catch(() => {}) }
  const menuRef = useRef()

  useEffect(() => { if (getToken()) api('/auth/me').then(setUser).catch(() => setToken(null)).finally(() => setBooting(false)) }, [])
  // path-based routing under ADMIN_BASE: /<base>/login, /<base>/<page>
  useEffect(() => {
    const want = user ? `${ADMIN_BASE}/${tab}` : `${ADMIN_BASE}/login`
    if (!booting && location.pathname !== want) history.replaceState(null, '', want)
    setNavOpen(false)
  }, [tab, user, booting])
  useEffect(() => { const f = () => setTab(fromPath()); window.addEventListener('popstate', f); return () => window.removeEventListener('popstate', f) }, []) // eslint-disable-line
  const go = (k) => { if (k !== tab) history.pushState(null, '', `${ADMIN_BASE}/${k}`); setTab(k) }
  useEffect(() => { const c = (e) => menuRef.current && !menuRef.current.contains(e.target) && setMenu(false); document.addEventListener('mousedown', c); return () => document.removeEventListener('mousedown', c) }, [])
  useEffect(() => {
    if (!user) return
    const f = () => api('/messages/poll').then((p) => setUnread(p.unread)).catch(() => {})
    f(); const t = setInterval(f, 10000); return () => clearInterval(t)
  }, [user])
  useEffect(() => { if (!user?.is_admin) return; loadPending(); const t = setInterval(loadPending, 30000); return () => clearInterval(t) }, [user]) // eslint-disable-line

  if (booting) return <div className="boot"><Spinner size={28} /></div>
  if (!user) return <Login onLogin={(u) => { setUser(u); history.replaceState(null, '', `${ADMIN_BASE}/dashboard`); setTab('dashboard') }} />

  const logout = async () => { try { await api('/auth/logout', { method: 'POST' }) } catch { /* ignore */ } setToken(null); setUser(null) }
  const Page = PAGES[tab]

  return (
    <div className={`layout ${navOpen ? 'nav-open' : ''}`}>
      <aside className="sidebar">
        <div className="logo"><span className="logo-mark"><MessageCircle size={20} /></span><div><b>WA Business Fineoutput Portal</b><small>Admin panel</small></div></div>
        <nav>{NAV.map((g) => (<div key={g.group} className="nav-group"><div className="nav-label">{g.group}</div>
          {g.items.filter(([, , , admin]) => !admin || user.is_admin).map(([k, label, Icon]) => (
            <button key={k} className={`nav-item ${tab === k ? 'active' : ''}`} onClick={() => go(k)}><Icon size={18} /><span>{label}</span>
              {k === 'chat' && unread > 0 && <em className="nav-badge">{unread}</em>}
              {k === 'number-requests' && pending.numbers > 0 && <em className="nav-badge" title="Pending">{pending.numbers}</em>}
              {k === 'template-requests' && pending.templates > 0 && <em className="nav-badge" title="Pending">{pending.templates}</em>}
              {k === 'error-logs' && errCount > 0 && <em className="nav-badge" title="Unresolved errors">{errCount > 99 ? '99+' : errCount}</em>}</button>))}
        </div>))}</nav>
        <div className="sidebar-foot"><div className="help-card"><b>Need the API?</b><small>Use API Keys to send order alerts from your store.</small>
          {user.is_admin && <button onClick={() => go('keys')}>Manage keys</button>}</div></div>
      </aside>
      <div className="scrim" onClick={() => setNavOpen(false)} />
      <div className="content">
        <header className="topbar">
          <button className="icon-btn burger" onClick={() => setNavOpen(true)}><Menu size={20} /></button>
          <div className="crumb"><span>Portal</span> / <b>{TITLES[tab]}</b></div>
          <div className="topbar-right">
            <button className="icon-btn bell" title="Unread messages" onClick={() => go('chat')}><Bell size={19} />{unread > 0 && <i>{unread > 99 ? '99+' : unread}</i>}</button>
            <div className="user-menu" ref={menuRef}>
              <button className="user-btn" onClick={() => setMenu(!menu)}>
                <span className="avatar sm">{user.name.split(' ').map((w) => w[0]).slice(0, 2).join('')}</span>
                <span className="user-meta"><b>{user.name}</b><small>{user.is_admin ? 'Administrator' : 'User'}</small></span><ChevronDown size={16} />
              </button>
              {menu && <div className="dropdown">
                <div className="dd-head"><b>{user.name}</b><small>{user.email}</small></div>
                {user.is_admin && <button onClick={() => { go('settings'); setMenu(false) }}><SettingsIcon size={16} /> Settings</button>}
                <button onClick={logout} className="danger"><LogOut size={16} /> Log out</button>
              </div>}
            </div>
          </div>
        </header>
        <main className={`main ${tab === 'chat' ? 'main-chat' : ''}`}><Page user={user} go={go} onChange={loadPending} /></main>
      </div>
    </div>
  )
}

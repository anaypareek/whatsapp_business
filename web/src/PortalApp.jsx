// Client portal (served at /). Must not import or mention the admin path.
import { useEffect, useRef, useState } from 'react'
import { LayoutDashboard, MessagesSquare, Phone, FileText, LogOut, Menu, ChevronDown, Bell, MessageCircle, Send, Inbox, CheckCheck, PhoneCall, ClipboardList, Workflow } from 'lucide-react'
import { api, getToken, setToken, fmtTime } from './api'
import { Spinner, Card, PageHeader, Skeleton, Empty, Badge, useLoad, TableSkeleton } from './ui'
import Login from './Login'
import { Chat } from './pages'
import { Flows } from './flows'
import { NumberRequestsPage, TemplateRequestsPage, StatsSection, TemplateDetail } from './portal-extra'

const EP = { numbers: '/client/numbers', conversations: '/client/conversations', thread: '/client/messages/thread', poll: '/client/messages/poll', send: '/client/messages/send' }

function Home({ me, go }) {
  const [d, setD] = useState(null)
  useEffect(() => { api('/client/dashboard').then(setD).catch(() => {}) }, [])
  const card = (Icon, tone, label, v) => <div className={`stat tone-${tone}`}><div className="stat-top"><span className="stat-ico"><Icon size={20} /></span></div>
    {d ? <div className="stat-val">{v}</div> : <Skeleton h={28} w="40%" style={{ margin: '10px 0 6px' }} />}<div className="stat-label">{label}</div></div>
  return (<div className="page">
    <PageHeader title={`Welcome, ${me.name.split(' ')[0]} 👋`} subtitle={me.company || 'Your WhatsApp Business overview'} />
    <StatsSection go={go} />
    <div className="mini-stats">
      <div className="mini"><Inbox size={18} /><b>{d ? d.received_today : '–'}</b><span>Received today</span></div>
      <div className="mini"><Bell size={18} /><b>{d ? d.unread : '–'}</b><span>Unread messages</span></div>
      <div className="mini"><MessagesSquare size={18} /><b>{d ? d.conversations : '–'}</b><span>Conversations</span></div>
      <div className="mini"><CheckCheck size={18} /><b>{d ? d.templates.approved : '–'}</b><span>Approved templates</span></div>
    </div>
    <Card title="Get started" icon={MessageCircle}><div className="quick">
      <button onClick={() => go('chats')}><MessagesSquare size={18} /><span>Open chats</span></button>
      <button onClick={() => go('numbers')}><Phone size={18} /><span>My numbers</span></button>
      <button onClick={() => go('templates')}><FileText size={18} /><span>My templates</span></button>
      <button onClick={() => go('number-requests')}><PhoneCall size={18} /><span>Request a number</span></button>
      <button onClick={() => go('template-requests')}><ClipboardList size={18} /><span>Request a template</span></button>
    </div></Card>
  </div>)
}

function MyNumbers() {
  const [rows, , loading] = useLoad('/client/numbers')
  return (<div className="page"><PageHeader icon={Phone} title="My numbers" subtitle="WhatsApp numbers assigned to your account" />
    <Card pad={false}><div className="table-wrap"><table className="table"><thead><tr><th>Number</th><th>Name</th><th>Quality</th><th>Status</th></tr></thead>
      {loading ? <TableSkeleton cols={4} /> : rows.length ? <tbody>{rows.map((n) => <tr key={n.id}><td><div className="cell-user"><span className="ico-tile"><Phone size={16} /></span><b>{n.display_number}</b></div></td>
        <td>{n.name || '—'}</td><td>{n.quality_rating ? <Badge tone={{ GREEN: 'green', YELLOW: 'amber', RED: 'red' }[n.quality_rating] || 'gray'}>{n.quality_rating}</Badge> : '—'}</td><td className="muted">{n.status || '—'}</td></tr>)}</tbody>
        : <tbody><tr><td colSpan={4}><Empty icon={Phone} title="No numbers assigned yet">Your administrator will assign WhatsApp numbers to your account.</Empty></td></tr></tbody>}
    </table></div></Card></div>)
}

function MyTemplates() {
  const [rows, , loading] = useLoad('/client/templates')
  return (<div className="page"><PageHeader icon={FileText} title="My templates" subtitle="Approved message templates assigned to your account" />
    <Card pad={false}><div className="table-wrap"><table className="table"><thead><tr><th>Template</th><th>Category</th><th>Status</th><th>Updated</th></tr></thead>
      {loading ? <TableSkeleton cols={4} /> : rows.length ? <tbody>{rows.map((t) => <tr key={t.id}><td><b>{t.name}</b><small className="block muted">{t.language}</small>{t.rejected_reason && <small className="block err-text">Rejected: {t.rejected_reason}</small>}</td>
        <td>{t.category}</td><td><Badge s={t.status} /></td><td className="muted">{fmtTime(t.updated_at)}</td></tr>)}</tbody>
        : <tbody><tr><td colSpan={4}><Empty icon={FileText} title="No templates yet" /></td></tr></tbody>}
    </table></div></Card></div>)
}

const NAV = [['dashboard', 'Dashboard', LayoutDashboard], ['chats', 'Chats', MessagesSquare], ['numbers', 'My numbers', Phone], ['templates', 'My templates', FileText], ['flows', 'Automation flows', Workflow],
  ['number-requests', 'Number requests', PhoneCall], ['template-requests', 'Template requests', ClipboardList]]
const DETAIL = /^template-stats\/(\d+)$/

export default function PortalApp() {
  const [me, setMe] = useState(null); const [booting, setBooting] = useState(!!getToken())
  const fromPath = () => { const s = location.pathname.replace(/^\/+|\/+$/g, ''); return NAV.some(([k]) => k === s) || DETAIL.test(s) ? s : 'dashboard' }
  const [tab, setTab] = useState(fromPath); const [navOpen, setNavOpen] = useState(false); const [menu, setMenu] = useState(false); const menuRef = useRef()
  useEffect(() => { if (getToken()) api('/client/auth/me').then(setMe).catch(() => setToken(null)).finally(() => setBooting(false)) }, [])
  useEffect(() => { const want = me ? `/${tab}` : '/login'; if (!booting && location.pathname !== want) history.replaceState(null, '', want); setNavOpen(false) }, [tab, me, booting])
  useEffect(() => { const f = () => setTab(fromPath()); window.addEventListener('popstate', f); return () => window.removeEventListener('popstate', f) }, []) // eslint-disable-line
  useEffect(() => { const c = (e) => menuRef.current && !menuRef.current.contains(e.target) && setMenu(false); document.addEventListener('mousedown', c); return () => document.removeEventListener('mousedown', c) }, [])
  const go = (k) => { if (k !== tab) history.pushState(null, '', `/${k}`); setTab(k) }

  if (booting) return <div className="boot"><Spinner size={28} /></div>
  if (!me) return <Login endpoint="/client/auth/login" brand="WA Business" sub="Client portal" heading="Client login" blurb="Sign in to view your WhatsApp numbers and chats" footer="Forgot? Contact your account manager"
    onLogin={(c) => { setMe(c); history.replaceState(null, '', '/dashboard'); setTab('dashboard') }} />
  const logout = async () => { try { await api('/client/auth/logout', { method: 'POST' }) } catch { /* ignore */ } setToken(null); setMe(null) }
  const label = DETAIL.test(tab) ? 'Template details' : NAV.find(([k]) => k === tab)[1]
  return (<div className={`layout ${navOpen ? 'nav-open' : ''}`}>
    <aside className="sidebar">
      <div className="logo"><span className="logo-mark"><MessageCircle size={20} /></span><div><b>WA Business</b><small>Client portal</small></div></div>
      <nav><div className="nav-group"><div className="nav-label">Menu</div>{NAV.map(([k, l, Icon]) => <button key={k} className={`nav-item ${tab === k || (k === 'dashboard' && DETAIL.test(tab)) ? 'active' : ''}`} onClick={() => go(k)}><Icon size={18} /><span>{l}</span></button>)}</div></nav>
    </aside>
    <div className="scrim" onClick={() => setNavOpen(false)} />
    <div className="content">
      <header className="topbar"><button className="icon-btn burger" onClick={() => setNavOpen(true)}><Menu size={20} /></button>
        <div className="crumb"><span>{me.company || 'Portal'}</span> / <b>{label}</b></div>
        <div className="topbar-right"><div className="user-menu" ref={menuRef}>
          <button className="user-btn" onClick={() => setMenu(!menu)}><span className="avatar sm">{me.name.split(' ').map((w) => w[0]).slice(0, 2).join('')}</span>
            <span className="user-meta"><b>{me.name}</b><small>{me.company || 'Client'}</small></span><ChevronDown size={16} /></button>
          {menu && <div className="dropdown"><div className="dd-head"><b>{me.name}</b><small>{me.email}</small></div><button onClick={logout} className="danger"><LogOut size={16} /> Log out</button></div>}
        </div></div>
      </header>
      <main className={`main ${tab === 'chats' ? 'main-chat' : ''}`}>
        {tab === 'dashboard' && <Home me={me} go={go} />}{tab === 'chats' && <Chat ep={EP} />}{tab === 'numbers' && <MyNumbers />}{tab === 'templates' && <MyTemplates />}{tab === 'flows' && <Flows scope="client" />}
        {tab === 'number-requests' && <NumberRequestsPage />}{tab === 'template-requests' && <TemplateRequestsPage />}{DETAIL.test(tab) && <TemplateDetail key={tab} id={tab.match(DETAIL)[1]} go={go} />}
      </main>
    </div>
  </div>)
}

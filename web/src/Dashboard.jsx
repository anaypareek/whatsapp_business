import { useEffect, useState } from 'react'
import { Send, Inbox, AlertTriangle, Phone, FileText, Bot, Users, KeyRound, MessagesSquare, Plus, RefreshCw, ArrowUpRight, ArrowDownRight, TrendingUp, PhoneCall, ClipboardList } from 'lucide-react'
import { api, fmtShort } from './api'
import { Card, PageHeader, Skeleton, Button, Empty, Avatar, useToast } from './ui'
import { WabaHealthBanner } from './apikeys'

function Stat({ icon: Icon, label, value, sub, tone = 'orange', trend, loading }) {
  return (<div className={`stat tone-${tone}`}>
    <div className="stat-top"><span className="stat-ico"><Icon size={20} /></span>
      {trend != null && <span className={`trend ${trend >= 0 ? 'up' : 'down'}`}>{trend >= 0 ? <ArrowUpRight size={14} /> : <ArrowDownRight size={14} />}{Math.abs(trend)}%</span>}</div>
    {loading ? <Skeleton h={28} w="50%" style={{ margin: '10px 0 6px' }} /> : <div className="stat-val">{value?.toLocaleString('en-IN')}</div>}
    <div className="stat-label">{label}</div>{sub && <div className="stat-sub">{sub}</div>}
  </div>)
}

/** Lightweight SVG area/line chart for sent vs received. */
function Chart({ series }) {
  const [hover, setHover] = useState(null)
  const W = 640, H = 240, P = { l: 36, r: 12, t: 16, b: 28 }
  const max = Math.max(4, ...series.flatMap((d) => [d.sent, d.received]))
  const nice = Math.ceil(max / 4) * 4
  const x = (i) => P.l + (i * (W - P.l - P.r)) / Math.max(1, series.length - 1)
  const y = (v) => H - P.b - (v / nice) * (H - P.t - P.b)
  const path = (k) => series.map((d, i) => `${i ? 'L' : 'M'}${x(i)},${y(d[k])}`).join(' ')
  const area = (k) => `${path(k)} L${x(series.length - 1)},${y(0)} L${x(0)},${y(0)} Z`
  return (<div className="chart">
    <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" onMouseLeave={() => setHover(null)}>
      <defs>
        <linearGradient id="gs" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor="#F97316" stopOpacity=".35" /><stop offset="1" stopColor="#F97316" stopOpacity="0" /></linearGradient>
        <linearGradient id="gr" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stopColor="#0EA5E9" stopOpacity=".22" /><stop offset="1" stopColor="#0EA5E9" stopOpacity="0" /></linearGradient>
      </defs>
      {[0, 1, 2, 3, 4].map((i) => { const v = (nice / 4) * i; return <g key={i}><line x1={P.l} x2={W - P.r} y1={y(v)} y2={y(v)} className="grid" /><text x={P.l - 8} y={y(v) + 4} textAnchor="end" className="axis">{v}</text></g> })}
      <path d={area('received')} fill="url(#gr)" /><path d={path('received')} className="ln rcv" />
      <path d={area('sent')} fill="url(#gs)" /><path d={path('sent')} className="ln snt" />
      {series.map((d, i) => <g key={d.date}>
        <text x={x(i)} y={H - 8} textAnchor="middle" className="axis">{d.label}</text>
        <rect x={x(i) - 20} y={P.t} width={40} height={H - P.t - P.b} fill="transparent" onMouseEnter={() => setHover(i)} />
        {hover === i && <line x1={x(i)} x2={x(i)} y1={P.t} y2={H - P.b} className="hover-ln" />}
        <circle cx={x(i)} cy={y(d.sent)} r={hover === i ? 5 : 3.5} className="dot snt" /><circle cx={x(i)} cy={y(d.received)} r={hover === i ? 5 : 3.5} className="dot rcv" />
      </g>)}
    </svg>
    {hover != null && <div className="tip" style={{ left: `${(x(hover) / W) * 100}%` }}><b>{series[hover].date}</b><span><i className="snt" />Sent {series[hover].sent}</span><span><i className="rcv" />Received {series[hover].received}</span></div>}
  </div>)
}

function Donut({ t }) {
  const parts = [['Approved', t.approved, '#22C55E'], ['Pending', t.pending, '#F59E0B'], ['Rejected', t.rejected, '#EF4444'], ['Other', t.other, '#CBD5E1']]
  const total = parts.reduce((a, p) => a + p[1], 0)
  let acc = 0; const R = 54, C = 2 * Math.PI * R
  return (<div className="donut-wrap">
    <svg viewBox="0 0 140 140" className="donut"><circle cx="70" cy="70" r={R} className="donut-bg" />
      {total > 0 && parts.map(([l, v, c]) => { const len = (v / total) * C; const el = <circle key={l} cx="70" cy="70" r={R} stroke={c} strokeDasharray={`${len} ${C - len}`} strokeDashoffset={-acc} className="donut-seg" />; acc += len; return el })}
      <text x="70" y="66" textAnchor="middle" className="donut-n">{total}</text><text x="70" y="86" textAnchor="middle" className="donut-l">templates</text></svg>
    <ul className="legend">{parts.map(([l, v, c]) => <li key={l}><i style={{ background: c }} />{l}<b>{v}</b><span className="bar"><span style={{ width: `${total ? (v / total) * 100 : 0}%`, background: c }} /></span></li>)}</ul>
  </div>)
}

export default function Dashboard({ go, user }) {
  const [d, setD] = useState(null); const toast = useToast()
  const load = () => api('/dashboard').then(setD).catch((e) => toast.error(e.message))
  useEffect(() => { load(); const t = setInterval(load, 30000); return () => clearInterval(t) }, []) // eslint-disable-line
  const L = !d
  const s = d?.series || []
  const pct = (a, b) => (b ? Math.round(((a - b) / b) * 100) : a ? 100 : 0)
  const wk = s.reduce((a, x) => a + x.sent + x.received, 0)
  const hour = Number(new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', hour: 'numeric', hour12: false }))
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'

  return (<div className="page">
    <PageHeader title={`${greet}, ${user.name.split(' ')[0]} 👋`} subtitle="Here's what's happening on your WhatsApp numbers today.">
      <Button icon={RefreshCw} onClick={load}>Refresh</Button>
      <Button variant="primary" icon={Send} onClick={() => go('send')}>New message</Button>
    </PageHeader>
    <WabaHealthBanner />

    <div className="stats">
      <Stat icon={Send} label="Sent today" value={d?.messages.sent_today} loading={L} trend={s.length ? pct(s[6]?.sent, s[5]?.sent) : null} sub={`${(d?.messages.total_sent ?? 0).toLocaleString('en-IN')} total sent`} />
      <Stat icon={Inbox} tone="blue" label="Received today" value={d?.messages.received_today} loading={L} trend={s.length ? pct(s[6]?.received, s[5]?.received) : null} sub={`${(d?.messages.total_received ?? 0).toLocaleString('en-IN')} total received`} />
      <Stat icon={AlertTriangle} tone="red" label="Failed today" value={d?.messages.failed_today} loading={L} sub="Check the error tooltip in chats" />
      <Stat icon={MessagesSquare} tone="green" label="Conversations" value={d?.conversations} loading={L} sub={`${d?.contacts ?? 0} unique contacts`} />
    </div>

    {user.is_admin && d && (d.pending_requests.numbers + d.pending_requests.templates > 0) && <div className="pending-strip">
      <button onClick={() => go('number-requests')}><PhoneCall size={18} /><b>{d.pending_requests.numbers}</b><span>pending number request{d.pending_requests.numbers === 1 ? '' : 's'}</span></button>
      <button onClick={() => go('template-requests')}><ClipboardList size={18} /><b>{d.pending_requests.templates}</b><span>pending template request{d.pending_requests.templates === 1 ? '' : 's'}</span></button>
    </div>}
    <div className="mini-stats">
      <div className="mini"><Phone size={18} /><b>{L ? '–' : d.phone_numbers}</b><span>Numbers</span></div>
      <div className="mini"><Bot size={18} /><b>{L ? '–' : d.auto_replies_active}</b><span>Active rules</span></div>
      <div className="mini"><KeyRound size={18} /><b>{L ? '–' : d.api_keys}</b><span>API keys</span></div>
      <div className="mini"><Users size={18} /><b>{L ? '–' : d.contacts}</b><span>Contacts</span></div>
    </div>
    <div className="grid-2-1 stretch">
      <Card className="fill" title="Message activity" icon={TrendingUp} actions={<div className="legend-inline"><span><i className="snt" />Sent</span><span><i className="rcv" />Received</span><span className="muted">{wk} in 7 days</span></div>}>
        {L ? <Skeleton h={240} /> : <Chart series={s} />}
      </Card>
      <Card title="Templates" icon={FileText} actions={<button className="link" onClick={() => go('templates')}>View all</button>}>
        {L ? <Skeleton h={200} /> : <Donut t={d.templates} />}
      </Card>
    </div>

    <div className="grid-2-1 stretch">
      <Card className="fill" title="Recent conversations" icon={MessagesSquare} pad={false} actions={<button className="link" onClick={() => go('chat')}>Open inbox</button>}>
        {L ? <div className="card-body">{[1, 2, 3].map((i) => <Skeleton key={i} h={44} style={{ marginBottom: 10 }} />)}</div>
          : d.recent_conversations.length ? <ul className="recent">{d.recent_conversations.map((c) => (
            <li key={`${c.phone_number_id}-${c.contact_wa_id}`} onClick={() => go('chat')}>
              <Avatar name={c.contact_name || c.contact_wa_id} />
              <div className="grow"><div className="row between"><b>{c.contact_name || `+${c.contact_wa_id}`}</b><small className="muted">{fmtShort(c.last_message?.created_at)}</small></div>
                <div className="row between"><span className="muted ellipsis">{c.last_message?.direction === 'out' ? 'You: ' : ''}{c.last_message?.body || `[${c.last_message?.type}]`}</span>
                  {c.unread > 0 ? <em className="pill">{c.unread}</em> : <small className="via">{c.number}</small>}</div></div>
            </li>))}</ul> : <Empty title="No conversations yet">Messages appear here as soon as the webhook receives them.</Empty>}
      </Card>
      <Card title="Quick actions" icon={Plus}>
          <div className="quick">
            <button onClick={() => go('send')}><Send size={18} /><span>Send message</span></button>
            <button onClick={() => go('templates')}><FileText size={18} /><span>New template</span></button>
            <button onClick={() => go('rules')}><Bot size={18} /><span>Auto-reply</span></button>
            <button onClick={() => go('numbers')}><Phone size={18} /><span>Add number</span></button>
          </div>
        </Card>
    </div>
  </div>)
}

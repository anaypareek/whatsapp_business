import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Camera, Plus, Plug, RefreshCw, Trash2, Pencil, Ban, RotateCw, Send, Clock, Calendar as CalIcon, List as ListIcon, LayoutGrid, ChevronLeft, ChevronRight, Image as ImageIcon, Clapperboard, GalleryHorizontal,
  CircleDot, Upload, X, ArrowLeft, ArrowRight, ExternalLink, Gauge, Heart, MessageCircle, Bookmark, Hash, Copy, Check, Save, TriangleAlert, ShieldCheck, Lock, KeyRound, Play, Link2, Search } from 'lucide-react'
import { api, fmtTime } from './api'
import { Button, Card, PageHeader, Field, Badge, Empty, Skeleton, Modal, Avatar, useToast, Spinner } from './ui'
import { ClientSelect } from './clients'

const FORMATS = [['image', 'Photo', ImageIcon], ['reel', 'Reel', Clapperboard], ['carousel', 'Carousel', GalleryHorizontal], ['story', 'Story', CircleDot]]
const FMT = Object.fromEntries(FORMATS.map(([k, l, I]) => [k, { label: l, Icon: I }]))
const TONE = { draft: 'gray', scheduled: 'blue', publishing: 'amber', posted: 'green', failed: 'red', active: 'green', needs_reconnect: 'red', disconnected: 'gray' }
const LABEL = { needs_reconnect: 'Reconnect needed' }
const StatusBadge = ({ s }) => <Badge tone={TONE[s] || 'gray'}>{LABEL[s] || s}</Badge>
const IST = 'Asia/Kolkata'

// ---- time helpers: everything the user sees/types is IST; the API stores UTC ----
export const istKey = (v) => new Date(v).toLocaleDateString('sv-SE', { timeZone: IST })
const istTime = (v) => new Date(v).toLocaleTimeString('en-IN', { timeZone: IST, hour: 'numeric', minute: '2-digit', hour12: true }).toLowerCase()
const istInput = (v) => new Date(v).toLocaleString('sv-SE', { timeZone: IST }).slice(0, 16).replace(' ', 'T')   // value for <input type=datetime-local>
const defaultSlot = () => { const d = new Date(Date.now() + 3600e3 * 2); const s = istInput(d); return `${s.slice(0, 13)}:00` }
const addDays = (key, n) => { const d = new Date(`${key}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10) }
const dow = (key) => new Date(`${key}T00:00:00Z`).getUTCDay()
const fmtFull = (v) => v ? new Date(v).toLocaleString('en-IN', { timeZone: IST, weekday: 'short', day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true }) + ' IST' : ''
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

export const useIg = (scope) => { const p = scope === 'admin' ? '/instagram' : '/client/instagram'; return { p, admin: scope === 'admin' } }

export const captionStats = (c = '') => ({ chars: [...c].length, tags: (c.match(/(^|[^\p{L}\p{N}_&])#[\p{L}\p{N}_]+/gu) || []).length })
const dur = (s) => s == null ? '' : `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`
const mb = (b) => b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.round(b / 1024)} KB`

function Pic({ a, size = 40 }) {
  const [bad, setBad] = useState(false)
  return a?.profile_picture_url && !bad ? <img className="ig-pic" style={{ width: size, height: size }} src={a.profile_picture_url} alt="" onError={() => setBad(true)} /> : <Avatar name={a?.username} size={size} />
}

// =====================================================================
// Container with tabs
// =====================================================================
export function Instagram({ scope }) {
  const { admin } = useIg(scope)
  const q = new URLSearchParams(location.search)
  const [tab, setTab] = useState(q.get('connect') || q.get('connect_error') ? 'accounts' : (q.get('tab') || 'posts'))
  const [edit, setEdit] = useState(null)          // post being edited / duplicated into the composer
  const [slot, setSlot] = useState(null)          // prefilled schedule time (calendar click)
  const go = (t) => { setTab(t); if (t !== 'compose') { setEdit(null); setSlot(null) } const u = new URL(location.href); u.search = t === 'posts' ? '' : `?tab=${t}`; history.replaceState(null, '', u) }
  const TABS = [['posts', 'Posts & calendar', CalIcon], ['compose', edit ? 'Edit post' : 'Compose', Pencil], ['accounts', 'Accounts', Plug], ['usage', 'Usage & limits', Gauge]]
  return (<div className="page">
    <PageHeader icon={Camera} title="Instagram" subtitle={admin ? 'Connected accounts, scheduled & published posts and publishing limits for all clients' : 'Connect your Instagram business account, then schedule photos, reels, carousels and stories'}>
      <Button variant="primary" icon={Plus} onClick={() => { setEdit(null); setSlot(null); go('compose') }}>New post</Button>
    </PageHeader>
    <div className="seg ig-tabs">{TABS.map(([k, l, I]) => <button key={k} className={tab === k ? 'on' : ''} onClick={() => go(k)}><I size={16} />{l}</button>)}</div>
    {tab === 'posts' && <Posts scope={scope} onEdit={(p) => { setEdit(p); go2(setTab, 'compose') }} onNew={(s) => { setEdit(null); setSlot(s); go2(setTab, 'compose') }} />}
    {tab === 'compose' && <Composer key={edit?.id || slot || 'new'} scope={scope} post={edit} slot={slot} onDone={() => go('posts')} />}
    {tab === 'accounts' && <Accounts scope={scope} />}
    {tab === 'usage' && <Usage scope={scope} />}
  </div>)
}
const go2 = (setTab, t) => { setTab(t); const u = new URL(location.href); u.search = `?tab=${t}`; history.replaceState(null, '', u) }

// =====================================================================
// Accounts
// =====================================================================
function Quota({ q, compact }) {
  if (!q || q.usage == null) return <span className="muted small">not checked yet</span>
  const pct = Math.min(100, Math.round((q.usage / (q.total || 100)) * 100))
  return (<div className={`ig-quota ${pct >= 90 ? 'hot' : pct >= 70 ? 'warm' : ''}`}><div className="ig-bar"><span style={{ width: `${pct}%` }} /></div>
    {!compact && <small>{q.usage} / {q.total} posts in the last 24 h</small>}</div>)
}

function Accounts({ scope }) {
  const { p, admin } = useIg(scope); const toast = useToast()
  const [rows, setRows] = useState(null); const [clients, setClients] = useState([]); const [cfg, setCfg] = useState(null); const [pick, setPick] = useState(null); const [err, setErr] = useState(null)
  const load = useCallback(() => api(`${p}/accounts`).then(setRows).catch((e) => toast.error(e.message)), [p]) // eslint-disable-line
  useEffect(() => { load(); api(`${p}/config`).then(setCfg).catch(() => {}); if (admin) api('/instagram/clients').then(setClients).catch(() => {}) }, [load, admin, p])
  useEffect(() => {   // back from Facebook: ?connect=<session> or ?connect_error=…
    const q = new URLSearchParams(location.search), sid = q.get('connect'), e = q.get('connect_error')
    if (!sid && !e) return
    history.replaceState(null, '', location.pathname + '?tab=accounts')
    if (e) { setErr(e); return }
    api(`${p}/connect/${sid}`).then((r) => setPick({ sid, ...r })).catch((x) => setErr(x.message))
  }, []) // eslint-disable-line
  const connect = async () => { try { const r = await api(`${p}/connect/start`, { method: 'POST', body: {} }); location.href = r.url } catch (e) { toast.error(e.message) } }
  const check = async (a) => { try { const r = await api(`${p}/accounts/${a.id}/check`, { method: 'POST' }); setRows((x) => x.map((y) => y.id === a.id ? r : y)); r.status === 'active' ? toast.success(`@${r.username} is connected`) : toast.error(r.status_reason || 'Needs reconnect') } catch (e) { toast.error(e.message) } }
  const remove = async (a, force) => {
    try { await api(`${p}/accounts/${a.id}${force ? '?force=1' : ''}`, { method: 'DELETE' }); toast.success(`@${a.username} removed`); load() }
    catch (e) { if (e.status === 409 && confirm(`${e.message}\n\nRemove anyway?`)) return remove(a, true); if (e.status !== 409) toast.error(e.message) }
  }
  const assign = async (a, client_id) => { try { const r = await api(`/instagram/accounts/${a.id}`, { method: 'PUT', body: { client_id: client_id || null } }); setRows((x) => x.map((y) => y.id === a.id ? r : y)); toast.success('Client updated') } catch (e) { toast.error(e.message) } }
  return (<>
    {err && <div className="alert error"><TriangleAlert size={18} /><span>{err}</span><button className="icon-btn" onClick={() => setErr(null)}><X size={16} /></button></div>}
    {cfg && !cfg.configured && <div className="alert error"><TriangleAlert size={18} /><span>{admin ? 'Instagram app credentials are missing. Add them under Settings → Instagram (or reuse the Meta App ID / secret).' : 'Instagram is not set up on this portal yet. Please ask your administrator.'}</span></div>}
    <Card title={`Connected accounts${rows ? ` (${rows.length})` : ''}`} icon={Plug} actions={<><Button icon={RefreshCw} onClick={load}>Refresh</Button><Button variant="primary" icon={Plus} onClick={connect} disabled={cfg && !cfg.configured}>Connect Instagram</Button></>} pad={false}>
      {!rows ? <div className="card-body"><Skeleton h={90} /></div> : !rows.length ? <Empty icon={Camera} title="No Instagram account connected">Click “Connect Instagram”, log in with Facebook and choose the Page(s) whose Instagram business account you want to publish to.</Empty> :
        <div className="ig-accts">{rows.map((a) => (
          <div key={a.id} className={`ig-acct ${a.status !== 'active' ? 'bad' : ''}`}>
            <div className="ig-acct-top"><Pic a={a} size={48} /><div className="grow"><b>@{a.username}</b><small className="block muted ellipsis">{a.page_name ? `Page: ${a.page_name}` : a.ig_user_id}</small></div><StatusBadge s={a.status} /></div>
            {a.status_reason && a.status !== 'active' && <p className="err-text small">{a.status_reason}</p>}
            <dl className="ig-kv">
              <div><dt>Token</dt><dd className={a.token_expiring ? 'err-text' : ''}>{a.token_expires_at ? `${a.token_days_left}d left · ${new Date(a.token_expires_at).toLocaleDateString('en-IN', { timeZone: IST, day: '2-digit', month: 'short', year: 'numeric' })}` : 'does not expire'}</dd></div>
              <div><dt>Last check</dt><dd>{a.last_checked_at ? fmtTime(a.last_checked_at) : '—'}</dd></div>
              <div><dt>Posts</dt><dd>{a.counts ? `${a.counts.scheduled} scheduled · ${a.counts.posted} posted${a.counts.failed ? ` · ${a.counts.failed} failed` : ''}` : '—'}</dd></div>
              <div><dt>24 h limit</dt><dd><Quota q={a.quota} compact /><small>{a.quota?.usage != null ? `${a.quota.usage}/${a.quota.total}` : ''}</small></dd></div>
            </dl>
            {admin && <Field label="Client"><ClientSelect value={a.client_id || ''} onChange={(v) => assign(a, v)} clients={clients} placeholder="Unassigned" /></Field>}
            <div className="row wrap gap8 ig-acct-actions">
              <Button size="sm" icon={ShieldCheck} onClick={() => check(a)}>Check</Button>
              {a.status !== 'active' && <Button size="sm" variant="primary" icon={Link2} onClick={connect}>Reconnect</Button>}
              <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={() => remove(a)}>Remove</Button>
            </div>
          </div>))}</div>}
    </Card>
    {pick && <PickModal pick={pick} scope={scope} clients={clients} onClose={() => setPick(null)} onSaved={() => { setPick(null); load() }} />}
  </>)
}

function PickModal({ pick, scope, clients, onClose, onSaved }) {
  const { p, admin } = useIg(scope); const toast = useToast()
  const free = pick.candidates.filter((c) => !c.connected_to_other_client)
  const [sel, setSel] = useState(() => new Set(free.filter((c) => !c.already_connected).map((c) => c.ig_user_id).slice(0, 1)))
  const [clientId, setClientId] = useState('')
  const toggle = (id) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  const save = async () => {
    try {
      const r = await api(`${p}/connect/${pick.sid}`, { method: 'POST', body: { ig_user_ids: [...sel], client_id: admin && clientId ? Number(clientId) : null } })
      r.skipped?.length && toast.error(r.skipped.map((s) => `@${s.username}: ${s.reason}`).join(' '))
      r.saved.length && toast.success(`${r.saved.length} account${r.saved.length > 1 ? 's' : ''} connected`); onSaved()
    } catch (e) { toast.error(e.message) }
  }
  return (<Modal title="Choose Instagram accounts" wide onClose={onClose} footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" icon={Check} onClick={save} disabled={!sel.size}>Connect {sel.size || ''} account{sel.size === 1 ? '' : 's'}</Button></>}>
    <p className="muted">Facebook Pages you manage that have a linked Instagram professional account. Select one or more.</p>
    {!pick.candidates.length ? <Empty icon={Camera} title="No Instagram business account found">Link the Instagram account to a Facebook Page (Instagram → Settings → Account type → Professional → Connect Page), then try again.</Empty> :
      <div className="ig-pick">{pick.candidates.map((c) => { const off = c.connected_to_other_client; return (
        <label key={c.ig_user_id} className={`ig-pick-row ${off ? 'off' : ''}`}><input type="checkbox" disabled={off} checked={sel.has(c.ig_user_id)} onChange={() => toggle(c.ig_user_id)} />
          <Pic a={c} size={40} /><div className="grow"><b>@{c.username}</b><small className="block muted">Page: {c.page_name}</small></div>
          {off ? <Badge tone="red">Used by another client</Badge> : c.already_connected ? <Badge tone="amber">Already connected · refresh token</Badge> : null}</label>) })}</div>}
    {admin && <Field label="Assign to client" hint="Optional: you can change this later"><ClientSelect value={clientId} onChange={setClientId} clients={clients} placeholder="Unassigned" /></Field>}
  </Modal>)
}

// =====================================================================
// Composer
// =====================================================================
function Hint({ children, tone }) { return <div className={`ig-note ${tone || ''}`}>{children}</div> }

function Composer({ scope, post, slot, onDone }) {
  const { p, admin } = useIg(scope); const toast = useToast()
  const [cfg, setCfg] = useState(null); const [accounts, setAccounts] = useState(null)
  const [format, setFormat] = useState(post?.format || 'image')
  const [accIds, setAccIds] = useState(post ? [post.account.id] : [])
  const [media, setMedia] = useState(() => post ? post.items.map((i) => ({ ...i.media })) : [])
  const [busyFiles, setBusyFiles] = useState([]); const [fileErrs, setFileErrs] = useState([])
  const [caption, setCaption] = useState(post?.caption || ''); const [feed, setFeed] = useState(post ? !!post.share_to_feed : true)
  const [mode, setMode] = useState(post ? (post.status === 'draft' ? 'draft' : 'schedule') : (slot ? 'schedule' : 'now'))
  const [when, setWhen] = useState(post?.scheduled_at ? istInput(post.scheduled_at) : slot ? `${slot}T10:00` : defaultSlot())
  const [saving, setSaving] = useState(false); const [errs, setErrs] = useState([]); const fileRef = useRef()
  useEffect(() => { api(`${p}/config`).then(setCfg).catch(() => {}); api(`${p}/accounts`).then(setAccounts).catch((e) => toast.error(e.message)) }, [p]) // eslint-disable-line
  const usable = (accounts || []).filter((a) => a.status === 'active')
  useEffect(() => { if (!post && usable.length === 1 && !accIds.length) setAccIds([usable[0].id]) }, [accounts]) // eslint-disable-line

  const changeFormat = (f) => { if (f === format) return; if (media.length && !confirm('Changing the format removes the uploaded media. Continue?')) return; setFormat(f); setMedia([]); setFileErrs([]) }
  const accept = format === 'reel' ? 'video/mp4,video/quicktime' : format === 'image' ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime'
  const multi = format === 'carousel'; const max = multi ? 10 : 1
  const upload = async (files) => {
    const list = [...files].slice(0, Math.max(0, max - media.length)); if (!list.length) return
    for (const f of list) {
      const key = Math.random().toString(36).slice(2); setBusyFiles((b) => [...b, { key, name: f.name }])
      const fd = new FormData(); fd.append('format', format); fd.append('file', f)
      try { const m = await api(`${p}/media`, { method: 'POST', form: fd }); setMedia((x) => multi ? [...x, m] : [m]); setFileErrs((e) => e.filter((y) => y.name !== f.name)) }
      catch (e) { setFileErrs((x) => [...x.filter((y) => y.name !== f.name), { name: f.name, msg: e.message }]) }
      finally { setBusyFiles((b) => b.filter((y) => y.key !== key)) }
    }
  }
  const move = (i, d) => setMedia((x) => { const n = [...x]; const j = i + d; if (j < 0 || j >= n.length) return n;[n[i], n[j]] = [n[j], n[i]]; return n })
  const st = captionStats(caption); const lim = cfg?.limits || { caption_max: 2200, hashtag_max: 30 }
  const capErr = format !== 'story' && (st.chars > lim.caption_max || st.tags > lim.hashtag_max)
  const problems = []
  if (!accIds.length && !post) problems.push('Choose at least one Instagram account.')
  if (!media.length) problems.push(multi ? 'Add 2 to 10 photos/videos.' : 'Upload a file.'); else if (multi && media.length < 2) problems.push('A carousel needs at least 2 items.')
  if (capErr) problems.push(`Caption: max ${lim.caption_max} characters and ${lim.hashtag_max} hashtags.`)
  if (mode === 'schedule' && !when) problems.push('Pick a date and time.')
  const submit = async () => {
    setSaving(true); setErrs([])
    const body = { format, media_ids: media.map((m) => m.id), caption: format === 'story' ? null : caption, share_to_feed: feed, mode, scheduled_at: mode === 'schedule' ? when : null }
    try {
      if (post) await api(`${p}/posts/${post.id}`, { method: 'PUT', body })
      else await api(`${p}/posts`, { method: 'POST', body: { ...body, account_ids: accIds } })
      toast.success(mode === 'draft' ? 'Draft saved' : mode === 'now' ? 'Queued — it will publish within a minute' : `Scheduled for ${fmtFull(new Date(when + '+05:30'))}`); onDone()
    } catch (e) { setErrs([e.message]); toast.error(e.message) } finally { setSaving(false) }
  }
  const chosen = accounts?.find((a) => accIds.includes(a.id)) || post?.account
  return (<div className="ig-compose">
    <Card title={post ? `Edit post #${post.id}` : 'Create post'} icon={Pencil}>
      <div className="form">
        <Field label="Format"><div className="seg ig-fmt">{FORMATS.map(([k, l, I]) => <button key={k} type="button" className={format === k ? 'on' : ''} disabled={!!post} onClick={() => changeFormat(k)}><I size={16} />{l}</button>)}</div></Field>
        <Field label={post ? 'Account' : 'Post to'} hint={accounts && !usable.length ? 'No active account — connect one under Accounts.' : post ? 'The account cannot be changed when editing.' : 'Pick one or more accounts; one post is created per account.'}>
          {!accounts ? <Skeleton h={44} /> : <div className="ig-accpick">{(post ? [post.account] : accounts).map((a) => { const off = a.status !== 'active'; const on = accIds.includes(a.id); return (
            <button key={a.id} type="button" disabled={off || !!post} className={`ig-chip ${on ? 'on' : ''}`} onClick={() => setAccIds((x) => on ? x.filter((i) => i !== a.id) : [...x, a.id])} title={off ? 'Reconnect needed' : ''}>
              <Pic a={a} size={26} /><span>@{a.username}</span>{admin && (a.client?.name || a.client_name) && <small>{a.client?.name || a.client_name}</small>}{off && <Badge tone="red">reconnect</Badge>}{on && !post && <Check size={14} />}</button>) })}</div>}
        </Field>

        <Field label={multi ? `Media (${media.length}/10)` : 'Media'} hint={cfg ? `${specHint(cfg, format)} Server upload limit: ${Math.round((cfg.limits?.server_upload_limit_bytes || 0) / 1048576) > 100000 ? 'none' : Math.round((cfg.limits?.server_upload_limit_bytes || 0) / 1048576) + ' MB'}.` : ''}>
          <div className="ig-media">
            {media.map((m, i) => (<div key={m.id} className="ig-thumb">{m.kind === 'video' ? <video src={m.url} muted preload="metadata" /> : <img src={m.url} alt="" />}
              {m.kind === 'video' && <span className="ig-vid"><Play size={12} />{dur(m.duration)}</span>}
              <div className="ig-thumb-actions">{multi && <><button type="button" disabled={i === 0} onClick={() => move(i, -1)}><ArrowLeft size={13} /></button><button type="button" disabled={i === media.length - 1} onClick={() => move(i, 1)}><ArrowRight size={13} /></button></>}
                <button type="button" onClick={() => setMedia((x) => x.filter((y) => y.id !== m.id))}><X size={13} /></button></div>
              <small>{m.width}×{m.height}{m.size ? ` · ${mb(m.size)}` : ''}</small>{m.warnings?.map((w, k) => <em key={k} className="ig-warn">{w}</em>)}</div>))}
            {busyFiles.map((b) => <div key={b.key} className="ig-thumb loading"><Spinner size={22} /><small className="ellipsis">{b.name}</small></div>)}
            {media.length + busyFiles.length < max && <button type="button" className="ig-drop" onClick={() => fileRef.current.click()} onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); upload(e.dataTransfer.files) }}><Upload size={22} /><b>{multi ? 'Add photos / videos' : format === 'reel' ? 'Upload video' : 'Upload'}</b><small>click or drop</small></button>}
            <input ref={fileRef} type="file" hidden accept={accept} multiple={multi} onChange={(e) => { upload(e.target.files); e.target.value = '' }} />
          </div>
          {fileErrs.map((e) => <Hint key={e.name} tone="err"><TriangleAlert size={14} /> <b>{e.name}</b>: {e.msg}</Hint>)}
          {media.flatMap((m) => m.warnings || []).length > 0 && !media.some((m) => m.warnings?.length) ? null : null}
        </Field>

        {format !== 'story' ? <Field label="Caption">
          <textarea rows={6} value={caption} onChange={(e) => setCaption(e.target.value)} placeholder="Write a caption… use #hashtags and @mentions" />
          <div className="ig-count"><span className={st.chars > lim.caption_max ? 'err-text' : ''}>{st.chars}/{lim.caption_max} characters</span><span className={st.tags > lim.hashtag_max ? 'err-text' : ''}><Hash size={12} />{st.tags}/{lim.hashtag_max} hashtags</span></div>
        </Field> : <Hint>Stories are published without a caption (the Instagram API does not support one). Add text to the image/video itself.</Hint>}
        {format === 'reel' && <label className="ig-check"><input type="checkbox" checked={feed} onChange={(e) => setFeed(e.target.checked)} /> Also show on the profile feed</label>}

        <Field label="When" hint={mode === 'schedule' ? 'Indian Standard Time (IST, UTC+5:30). Posts publish within a minute of this time.' : mode === 'now' ? 'Published by the next scheduler run (about a minute).' : ''}>
          <div className="seg">{[['now', 'Post now', Send], ['schedule', 'Schedule', Clock], ['draft', 'Save draft', Save]].map(([k, l, I]) => <button key={k} type="button" className={mode === k ? 'on' : ''} onClick={() => setMode(k)}><I size={15} />{l}</button>)}</div>
          {mode === 'schedule' && <input type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} style={{ marginTop: 10, maxWidth: 260 }} />}
        </Field>
        {problems.length > 0 && <Hint>{problems.map((x) => <div key={x}>• {x}</div>)}</Hint>}
        {errs.map((e) => <Hint key={e} tone="err"><TriangleAlert size={14} /> {e}</Hint>)}
        <div className="row gap8"><Button variant="primary" icon={mode === 'now' ? Send : mode === 'draft' ? Save : Clock} loading={saving} disabled={problems.length > 0 || busyFiles.length > 0} onClick={submit}>{post ? 'Save changes' : mode === 'now' ? 'Publish now' : mode === 'draft' ? 'Save draft' : 'Schedule post'}</Button><Button onClick={onDone}>Cancel</Button></div>
      </div>
    </Card>
    <div className="ig-side"><Card title="Preview" icon={Camera}><Preview format={format} media={media} caption={caption} account={chosen} when={mode === 'schedule' ? when : null} /></Card></div>
  </div>)
}

function specHint(cfg, f) {
  const s = cfg.limits?.specs || {}; const img = s['image:image'], vid = f === 'story' ? s['story:video'] : f === 'carousel' ? s['carousel:video'] : s['reel:video']
  if (f === 'image') return `JPEG/PNG/WebP up to ${img?.max_mb} MB · aspect ratio between 4:5 and 1.91:1 (PNG/WebP are converted to JPEG).`
  if (f === 'reel') return `MP4/MOV (H.264 + AAC) · ${vid?.min_s}–${vid?.max_s / 60} min · up to ${vid?.max_mb} MB · 9:16 recommended.`
  if (f === 'story') return `Image up to ${img?.max_mb} MB or video ${vid?.min_s}–${vid?.max_s} s up to ${vid?.max_mb} MB · 9:16 (1080×1920) recommended.`
  return `2–10 items, photos (≤ ${img?.max_mb} MB, 4:5 – 1.91:1) and/or videos (${vid?.min_s}–${vid?.max_s} s, ≤ ${vid?.max_mb} MB). Drag order with the arrows.`
}

/** Phone mockup of the feed / reel / story. */
export function Preview({ format, media, caption, account, when }) {
  const [i, setI] = useState(0); const idx = Math.min(i, Math.max(0, media.length - 1)); const m = media[idx]
  const tall = format === 'story' || format === 'reel'
  const body = (caption || '').split(/(\s+)/).map((t, k) => /^[#@]/.test(t) ? <span key={k} className="ig-tag">{t}</span> : t)
  const el = m ? (m.kind === 'video' ? <video key={m.url} src={m.url} muted loop playsInline autoPlay /> : <img src={m.url} alt="" />) : <div className="ig-empty-media"><ImageIcon size={34} /><small>Your {FMT[format].label.toLowerCase()} appears here</small></div>
  const ratio = tall ? '9 / 16' : m?.width && m?.height ? `${Math.min(1.91, Math.max(0.8, m.width / m.height))}` : '1'
  return (<div className="ig-phone"><div className="ig-phone-in">
    <div className={`ig-ph-head ${tall ? 'over' : ''}`}>{format === 'story' && <div className="ig-prog"><span /></div>}
      <div className="row gap8"><Pic a={account} size={30} /><b>{account ? account.username : 'your_account'}</b>{format === 'reel' && <small>Reel</small>}{format === 'story' && <small>now</small>}</div></div>
    <div className="ig-ph-media" style={{ aspectRatio: ratio }}>{el}
      {format === 'carousel' && media.length > 1 && <><span className="ig-count-pill">{idx + 1}/{media.length}</span>
        {idx > 0 && <button className="ig-nav l" onClick={() => setI(idx - 1)}><ChevronLeft size={16} /></button>}{idx < media.length - 1 && <button className="ig-nav r" onClick={() => setI(idx + 1)}><ChevronRight size={16} /></button>}</>}
      {format === 'reel' && <div className="ig-reel-side"><Heart size={22} /><MessageCircle size={22} /><Send size={22} /></div>}
      {format === 'reel' && caption && <div className="ig-reel-cap"><b>{account?.username || 'your_account'}</b> {caption.slice(0, 90)}</div>}</div>
    {format === 'carousel' && media.length > 1 && <div className="ig-dots">{media.map((_, k) => <i key={k} className={k === idx ? 'on' : ''} />)}</div>}
    {!tall && <><div className="ig-ph-actions"><Heart size={22} /><MessageCircle size={22} /><Send size={22} /><span className="grow" /><Bookmark size={22} /></div>
      <div className="ig-ph-cap"><b>{account ? account.username : 'your_account'}</b> {caption ? body : <span className="muted">Caption…</span>}</div></>}
    {when && <div className="ig-ph-when"><Clock size={13} /> Scheduled {fmtFull(new Date(when + '+05:30'))}</div>}
  </div></div>)
}

// =====================================================================
// Posts: list + month / week calendar
// =====================================================================
function Thumb({ post, size = 44 }) {
  const m = post.items?.[0]?.media
  return <span className="ig-mini" style={{ width: size, height: size }}>{m?.url && !m.purged ? (m.kind === 'video' ? <video src={m.url} muted preload="metadata" /> : <img src={m.url} alt="" />) : <ImageIcon size={16} />}
    {post.items?.length > 1 && <i>{post.items.length}</i>}</span>
}

function usePostActions(scope, reload) {
  const { p } = useIg(scope); const toast = useToast()
  const run = (verb, path, ok, method = 'POST') => async (post) => {
    try { await api(`${p}/posts/${post.id}${path}`, { method }); toast.success(ok); reload() } catch (e) { toast.error(e.message) }
  }
  return { cancel: run('cancel', '/cancel', 'Cancelled — moved to drafts'), retry: run('retry', '/retry', 'Queued again'), publishNow: run('now', '/publish-now', 'Queued — publishing within a minute'),
    remove: async (post) => { if (!confirm('Delete this post from the portal? (A post already on Instagram stays there.)')) return; try { await api(`${p}/posts/${post.id}`, { method: 'DELETE' }); toast.success('Deleted'); reload() } catch (e) { toast.error(e.message) } } }
}

function PostActions({ post, act, onEdit, onClose }) {
  const w = (f) => async () => { await f(post); onClose?.() }
  return (<div className="row wrap gap8">
    {post.permalink && <a className="btn btn-sm" href={post.permalink} target="_blank" rel="noreferrer"><ExternalLink size={14} /><span>View on Instagram</span></a>}
    {post.editable && <Button size="sm" icon={Pencil} onClick={() => { onEdit(post); onClose?.() }}>Edit</Button>}
    {post.can_publish_now && <Button size="sm" variant="primary" icon={Send} onClick={w(act.publishNow)}>Post now</Button>}
    {post.can_retry && <Button size="sm" variant="primary" icon={RotateCw} onClick={w(act.retry)}>Retry</Button>}
    {post.can_cancel && <Button size="sm" icon={Ban} onClick={w(act.cancel)}>Cancel</Button>}
    {post.can_delete && <Button size="sm" variant="ghost-danger" icon={Trash2} onClick={w(act.remove)}>Delete</Button>}</div>)
}

function Reason({ post }) {
  if (post.status === 'failed' && post.error) return <small className="block err-text">{post.error}{post.error_log_id ? ` (log #${post.error_log_id})` : ''}</small>
  if (post.deferred_reason && ['scheduled', 'publishing'].includes(post.status)) return <small className="block ig-defer">{post.deferred_reason}</small>
  return null
}

function PostModal({ post, scope, act, onEdit, onClose }) {
  return (<Modal title={`${FMT[post.format].label} · @${post.account?.username}`} onClose={onClose} wide>
    <div className="ig-detail"><Preview format={post.format} media={post.items.map((i) => i.media).filter(Boolean)} caption={post.caption} account={post.account} />
      <div className="stack-cards"><div className="row gap8"><StatusBadge s={post.status} />{post.account?.client_name && <Badge tone="gray">{post.account.client_name}</Badge>}</div>
        <dl className="ig-kv big">
          <div><dt>{post.status === 'posted' ? 'Published' : 'Scheduled'}</dt><dd>{fmtFull(post.status === 'posted' ? post.posted_at : post.scheduled_at) || '—'}</dd></div>
          {post.stage && post.status === 'publishing' && <div><dt>Progress</dt><dd>{post.stage} · container {post.container_status || 'creating'}</dd></div>}
          {post.attempts > 0 && <div><dt>Attempts</dt><dd>{post.attempts}</dd></div>}</dl>
        <Reason post={post} />
        <PostActions post={post} act={act} onEdit={onEdit} onClose={onClose} /></div></div></Modal>)
}

function Posts({ scope, onEdit, onNew }) {
  const { p, admin } = useIg(scope)
  const [view, setView] = useState('month'); const [cur, setCur] = useState(() => istKey(new Date()))
  const [f, setF] = useState({ status: '', format: '', account_id: '', client_id: '', q: '' }); const [page, setPage] = useState(1)
  const [data, setData] = useState(null); const [accounts, setAccounts] = useState([]); const [clients, setClients] = useState([]); const [open, setOpen] = useState(null)
  const range = useMemo(() => {
    if (view === 'list') return {}
    if (view === 'week') { const s = addDays(cur, -dow(cur)); return { from: `${s}T00:00`, to: `${addDays(s, 7)}T00:00`, per_page: 500, sort: 'asc' } }
    const first = `${cur.slice(0, 7)}-01`; const s = addDays(first, -dow(first)); return { from: `${s}T00:00`, to: `${addDays(s, 42)}T00:00`, per_page: 500, sort: 'asc' }
  }, [view, cur])
  const qs = useMemo(() => { const x = new URLSearchParams(); Object.entries({ ...f, ...range, page: view === 'list' ? page : '' }).forEach(([k, v]) => v && x.set(k, v)); if (view === 'list') x.set('per_page', 15); return x.toString() }, [f, range, page, view])
  const load = useCallback(() => api(`${p}/posts?${qs}`).then(setData).catch(() => {}), [p, qs])
  useEffect(() => { load(); const t = setInterval(load, 15000); return () => clearInterval(t) }, [load])
  useEffect(() => { api(`${p}/accounts`).then(setAccounts).catch(() => {}); if (admin) api('/instagram/clients').then(setClients).catch(() => {}) }, [p, admin])
  const act = usePostActions(scope, load)
  const rows = data?.data || []
  const setFilter = (k, v) => { setF((x) => ({ ...x, [k]: v })); setPage(1) }
  const step = (n) => view === 'week' ? setCur(addDays(cur, 7 * n)) : (() => { const d = new Date(`${cur.slice(0, 7)}-01T00:00:00Z`); d.setUTCMonth(d.getUTCMonth() + n); setCur(d.toISOString().slice(0, 10)) })()
  const title = view === 'week' ? (() => { const s = addDays(cur, -dow(cur)), e = addDays(s, 6); const fm = (k) => `${Number(k.slice(8))} ${MONTHS[Number(k.slice(5, 7)) - 1].slice(0, 3)}`; return `${fm(s)} – ${fm(e)} ${e.slice(0, 4)}` })() : `${MONTHS[Number(cur.slice(5, 7)) - 1]} ${cur.slice(0, 4)}`
  return (<>
    <Card pad={false}>
      <div className="ig-toolbar">
        <div className="seg">{[['month', 'Month', LayoutGrid], ['week', 'Week', CalIcon], ['list', 'List', ListIcon]].map(([k, l, I]) => <button key={k} className={view === k ? 'on' : ''} onClick={() => { setView(k); setPage(1) }}><I size={15} />{l}</button>)}</div>
        {view !== 'list' && <div className="row gap8"><button className="icon-btn" onClick={() => step(-1)}><ChevronLeft size={18} /></button><b className="ig-cal-title">{title}</b><button className="icon-btn" onClick={() => step(1)}><ChevronRight size={18} /></button><Button size="sm" onClick={() => setCur(istKey(new Date()))}>Today</Button></div>}
        <div className="filters ig-filters">
          <select value={f.status} onChange={(e) => setFilter('status', e.target.value)}><option value="">All statuses</option>{['draft', 'scheduled', 'publishing', 'posted', 'failed'].map((s) => <option key={s}>{s}</option>)}</select>
          <select value={f.format} onChange={(e) => setFilter('format', e.target.value)}><option value="">All formats</option>{FORMATS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          <select value={f.account_id} onChange={(e) => setFilter('account_id', e.target.value)}><option value="">All accounts</option>{accounts.map((a) => <option key={a.id} value={a.id}>@{a.username}</option>)}</select>
          {admin && <select value={f.client_id} onChange={(e) => setFilter('client_id', e.target.value)}><option value="">All clients</option>{clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>}
          <div className="input-ico"><Search size={16} className="ico" /><input placeholder="Search captions" value={f.q} onChange={(e) => setFilter('q', e.target.value)} /></div>
        </div>
      </div>
      {!data ? <div className="card-body"><Skeleton h={260} /></div> : view === 'list' ? <>
        <div className="table-wrap"><table className="table"><thead><tr><th>Post</th>{admin && <th>Client</th>}<th>Account</th><th>When (IST)</th><th>Status</th><th /></tr></thead>
          <tbody>{rows.map((r) => <tr key={r.id}><td><div className="row gap8 clickable" onClick={() => setOpen(r)}><Thumb post={r} /><div className="grow"><span className="badge tone-gray ig-fmtb">{FMT[r.format].label}</span><small className="block ellipsis ig-cap">{r.caption || (r.format === 'story' ? 'Story' : 'No caption')}</small></div></div></td>
            {admin && <td>{r.account?.client_name || <span className="muted">—</span>}</td>}
            <td><div className="row gap8"><Pic a={r.account} size={24} />@{r.account?.username}</div></td>
            <td className="nowrap">{fmtTime(r.status === 'posted' ? r.posted_at : r.scheduled_at) || <span className="muted">—</span>}</td>
            <td><StatusBadge s={r.status} /><Reason post={r} /></td><td className="right"><PostActions post={r} act={act} onEdit={onEdit} /></td></tr>)}
            {!rows.length && <tr><td colSpan={admin ? 6 : 5}><Empty icon={CalIcon} title="No posts found">Create your first post with “New post”.</Empty></td></tr>}</tbody></table></div>
        <div className="pager"><span className="muted">{data.total} post{data.total === 1 ? '' : 's'}</span><div className="row gap8"><Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button><span>{page} / {data.last_page || 1}</span><Button size="sm" disabled={page >= (data.last_page || 1)} onClick={() => setPage(page + 1)}>Next</Button></div></div></>
        : <Calendar view={view} cur={cur} rows={rows} onOpen={setOpen} onDay={(k) => onNew(k)} />}
    </Card>
    {open && <PostModal post={open} scope={scope} act={act} onEdit={onEdit} onClose={() => setOpen(null)} />}
  </>)
}

function Calendar({ view, cur, rows, onOpen, onDay }) {
  const today = istKey(new Date()); const by = {}
  rows.forEach((r) => { const t = r.scheduled_at || r.posted_at; if (!t) return; (by[istKey(t)] ||= []).push(r) })
  const first = `${cur.slice(0, 7)}-01`; const start = view === 'week' ? addDays(cur, -dow(cur)) : addDays(first, -dow(first)); const n = view === 'week' ? 7 : 42
  const days = Array.from({ length: n }, (_, i) => addDays(start, i)); const month = cur.slice(0, 7)
  const chip = (r) => <button key={r.id} className={`ig-ev st-${r.status}`} onClick={(e) => { e.stopPropagation(); onOpen(r) }} title={r.caption || FMT[r.format].label}><i />{view === 'week' && <Thumb post={r} size={22} />}<span>{istTime(r.scheduled_at || r.posted_at)}</span><em className="ellipsis">{view === 'week' ? `@${r.account?.username}` : r.account?.username}</em></button>
  return (<div className={`ig-cal ${view}`}>
    <div className="ig-cal-head">{['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((d) => <div key={d}>{d}</div>)}</div>
    <div className="ig-cal-grid" style={{ gridTemplateRows: view === 'month' ? 'repeat(6,1fr)' : '1fr' }}>
      {days.map((k) => { const ev = by[k] || []; const max = view === 'month' ? 3 : 99
        return (<div key={k} className={`ig-day ${k === today ? 'today' : ''} ${view === 'month' && k.slice(0, 7) !== month ? 'other' : ''}`} onClick={() => k >= today && onDay(k)}>
          <span className="ig-dnum">{Number(k.slice(8))}{view === 'week' ? ` ${MONTHS[Number(k.slice(5, 7)) - 1].slice(0, 3)}` : ''}</span>
          {ev.slice(0, max).map(chip)}{ev.length > max && <small className="muted">+{ev.length - max} more</small>}
          {k >= today && <span className="ig-add"><Plus size={13} /></span>}</div>) })}</div>
    <div className="ig-legend">{['scheduled', 'publishing', 'posted', 'failed', 'draft'].map((s) => <span key={s} className={`st-${s}`}><i />{s}</span>)}<small className="muted">Times in IST · click a day to schedule a post</small></div></div>)
}

// =====================================================================
// Usage & limits
// =====================================================================
function Usage({ scope }) {
  const { p, admin } = useIg(scope); const toast = useToast()
  const [d, setD] = useState(null); const [busy, setBusy] = useState(false)
  const load = useCallback(async (refresh) => { setBusy(true); try { setD(await api(`${p}/usage${refresh ? '?refresh=1' : ''}`)) } catch (e) { toast.error(e.message) } finally { setBusy(false) } }, [p]) // eslint-disable-line
  useEffect(() => { load(false) }, [load])
  const t = d?.totals
  const mini = [['Accounts', t?.accounts, Camera], ['Scheduled', t?.scheduled, Clock], ['Publishing', t?.publishing, Send], ['Posted', t?.posted, Check], ['Failed', t?.failed, TriangleAlert]]
  return (<>
    <div className="mini-stats ig-mini5">{mini.map(([l, v, I]) => <div key={l} className="mini"><I size={18} /><b>{d ? v : '–'}</b><span>{l}</span></div>)}</div>
    <Card title="Publishing limit per account" icon={Gauge} actions={<Button icon={RefreshCw} loading={busy} onClick={() => load(true)}>Refresh from Instagram</Button>} pad={false}>
      <div className="table-wrap"><table className="table"><thead><tr><th>Account</th>{admin && <th>Client</th>}<th>Status</th><th style={{ minWidth: 220 }}>Usage (rolling 24 h)</th><th>Posted 24 h</th><th>Deferred</th><th>Checked</th></tr></thead>
        <tbody>{!d ? <tr><td colSpan={7}><Skeleton h={40} /></td></tr> : d.accounts.map((r) => <tr key={r.account.id}><td><div className="row gap8"><Pic a={r.account} size={30} /><b>@{r.account.username}</b></div></td>
          {admin && <td>{r.account.client_name || <span className="muted">Unassigned</span>}</td>}<td><StatusBadge s={r.account.status} /></td>
          <td><Quota q={r.usage == null ? null : { usage: r.usage, total: r.total }} /></td><td>{r.posted_24h}</td><td>{r.deferred ? <Badge tone="amber">{r.deferred} waiting</Badge> : '—'}</td><td className="muted">{r.checked_at ? fmtTime(r.checked_at) : '—'}</td></tr>)}
          {d && !d.accounts.length && <tr><td colSpan={7}><Empty icon={Gauge} title="No accounts connected" /></td></tr>}</tbody></table></div>
      <p className="muted small ig-foot">{d?.note} Instagram allows up to {d?.daily_limit ?? 100} API-published posts per account per 24 hours (carousels count once). Posts that would exceed it are held and retried automatically.</p>
    </Card></>)
}


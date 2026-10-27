import { useEffect, useState } from 'react'
import { Camera, Check, Copy, Hash, KeyRound, Link2, Lock, ShieldCheck } from 'lucide-react'
import { api } from './api'
import { Button, Card, Field, Skeleton, useToast } from './ui'

// =====================================================================
// Admin → Settings card
// =====================================================================
export function InstagramSettings() {
  const toast = useToast(); const [s, setS] = useState(null); const [f, setF] = useState({ app_id: '', app_secret: '', config_id: '', redirect_uri: '' }); const [busy, setBusy] = useState(false)
  const apply = (r) => { setS(r); setF({ app_id: r.app_id, app_secret: '', config_id: r.config_id, redirect_uri: r.redirect_uri_custom }) }
  useEffect(() => { api('/instagram/settings').then(apply).catch((e) => toast.error(e.message)) }, []) // eslint-disable-line
  const save = async (e) => { e.preventDefault(); setBusy(true); try { apply(await api('/instagram/settings', { method: 'PUT', body: f })); toast.success('Instagram settings saved (secret stored encrypted)') } catch (x) { toast.error(x.message) } finally { setBusy(false) } }
  const copy = (t) => navigator.clipboard?.writeText(t).then(() => toast.success('Copied'))
  const src = (x) => x === 'instagram' ? 'own Instagram value' : x === 'meta' ? 'reusing the Meta app above' : 'not set'
  return (<div className="grid-2-1">
    <Card title="Instagram (Meta Facebook Login for Business)" icon={Camera}>{!s ? <Skeleton h={260} /> :
      <form className="form" onSubmit={save}>
        <p className="muted small">Leave App ID / secret blank to reuse the Meta app above. Publishing needs the Instagram permissions on that app — see docs/INSTAGRAM.md.</p>
        <div className="form-2"><Field label="Instagram App ID" icon={Hash} hint={`Using: ${s.effective_app_id || '—'} (${src(s.app_id_source)})`}><input value={f.app_id} onChange={(e) => setF({ ...f, app_id: e.target.value })} placeholder={s.effective_app_id || ''} /></Field>
          <Field label="Login configuration ID" icon={KeyRound} hint="Optional: Facebook Login for Business config_id (replaces scope)"><input value={f.config_id} onChange={(e) => setF({ ...f, config_id: e.target.value })} /></Field></div>
        <Field label="Instagram App secret" icon={Lock} hint={s.app_secret_set ? 'Saved — leave blank to keep' : `Using: ${src(s.secret_source)}`}><input type="password" autoComplete="new-password" value={f.app_secret} onChange={(e) => setF({ ...f, app_secret: e.target.value })} /></Field>
        <Field label="Redirect URI override" hint="Leave blank to use the default shown on the right"><input value={f.redirect_uri} onChange={(e) => setF({ ...f, redirect_uri: e.target.value })} placeholder={s.default_redirect_uri} /></Field>
        <div><Button type="submit" variant="primary" icon={Check} loading={busy}>Save Instagram settings</Button></div>
      </form>}</Card>
    <div className="stack-cards">
      <Card title="Redirect URI" icon={Link2}>{s && <><Field label="Add this under Facebook Login → Valid OAuth Redirect URIs"><div className="copy-row"><input readOnly value={s.redirect_uri} /><Button icon={Copy} onClick={() => copy(s.redirect_uri)} /></div></Field>
        <p className="muted small">Must match exactly (https, no trailing slash). Separate from the WhatsApp webhook URL.</p></>}</Card>
      <Card title="Permissions requested" icon={ShieldCheck}>{s && <><div className="ig-scopes">{s.scopes.map((x) => <code key={x}>{x}</code>)}</div>
        <ul className="checklist"><li className={s.configured ? 'ok' : ''}><Check size={14} /> App ID &amp; secret available</li><li className={s.config_id ? 'ok' : ''}><Check size={14} /> Login configuration (optional)</li></ul>
        <p className="muted small">Graph API {s.graph_version} · media URLs are served from {s.media_base_url}</p></>}</Card>
    </div></div>)
}

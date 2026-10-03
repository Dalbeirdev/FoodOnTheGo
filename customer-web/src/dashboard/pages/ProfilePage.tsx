import { useEffect, useState } from 'react'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { AcceptingSwitch } from '../DashboardLayout'
import ImageUpload, { GalleryUpload } from '../components/ImageUpload'
import { Card, Field, Icon, PageHeader, Pill, Tabs, ToastLine, useToastMessage } from '../components/ui'
import { dashboardErrorMessage } from '../api/apiDashboard'
import type { ProfileTaxonomy } from '../types'

/** Restaurant profile (Module 17): General information · Images · Cuisine & features; location block; operational status. */
export default function ProfilePage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const r = loc.restaurant; const p = loc.profile; const rid = r.id; const canEdit = d.can('restaurant.profile.edit')
  const [tab, setTab] = useState<'general' | 'images' | 'cuisine'>('general')
  const [form, setForm] = useState({ name: r.name, description: r.description, phone: p.contact.phone ?? '', website: p.contact.website ?? '', email: p.contact.publicEmail ?? '', prepTimeMin: r.prepTimeMin })
  const [cuisines, setCuisines] = useState<string[]>(r.cuisines); const [features, setFeatures] = useState<string[]>(r.features); const [newCuisine, setNewCuisine] = useState(''); const [newFeature, setNewFeature] = useState('')
  const [logo, setLogo] = useState(p.logo ?? ''); const [cover, setCover] = useState(p.coverImage ?? ''); const [gallery, setGallery] = useState<string[]>(p.gallery)
  const [busy, setBusy] = useState(false); const [errors, setErrors] = useState<Record<string, string>>({}); const { msg, toast } = useToastMessage()
  // With the backend, cuisines and features are chosen from its taxonomy (never free text).
  const [taxonomy, setTaxonomy] = useState<ProfileTaxonomy | null>(null)
  useEffect(() => { let on = true; d.repos.management.getTaxonomy?.().then((v) => { if (on) setTaxonomy(v) }).catch(() => undefined); return () => { on = false } }, [d.repos])
  // Reset the form for another location and whenever the saved version changes (after a save, or when someone else saved).
  useEffect(() => { setForm({ name: r.name, description: r.description, phone: p.contact.phone ?? '', website: p.contact.website ?? '', email: p.contact.publicEmail ?? '', prepTimeMin: r.prepTimeMin }); setCuisines(r.cuisines); setFeatures(r.features); setLogo(p.logo ?? ''); setCover(p.coverImage ?? ''); setGallery(p.gallery) }, [rid, loc.live?.version]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { document.title = `${t('dash.nav.profile', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const validate = () => { const e: Record<string, string> = {}; if (!form.name.trim()) e.name = t('dash.validation.required', undefined, locale); if (form.description.length > 500) e.description = t('dash.validation.maxLength', { max: 500 }, locale); if (form.website && !/^https?:\/\/\S+$/i.test(form.website.trim())) e.website = t('dash.validation.url', undefined, locale); if (form.email && !/^\S+@\S+\.\S+$/.test(form.email.trim())) e.email = t('dash.validation.email', undefined, locale); if (!Number.isInteger(form.prepTimeMin) || form.prepTimeMin < 1 || form.prepTimeMin > 180) e.prepTimeMin = t('dash.validation.range', undefined, locale); setErrors(e); return Object.keys(e).length === 0 }
  const save = async () => {
    if (!validate()) return; setBusy(true)
    try { await d.repos.management.updateProfile(rid, { name: form.name.trim(), description: form.description.trim(), prepTimeMin: form.prepTimeMin, cuisines, features, contact: { phone: form.phone.trim() || null, website: form.website.trim() || null, publicEmail: form.email.trim() || null }, ...(d.live ? {} : { logo: logo || null, coverImage: cover || null, gallery }) }); await d.refreshLocation(); toast(t('dash.profile.saved', undefined, locale)) }
    catch (e) { toast(d.live ? dashboardErrorMessage(e) : t('dash.profile.saveFailed', undefined, locale)); if (d.live) await d.refreshLocation() } finally { setBusy(false) }
  }
  const onb = p.onboardingStatus
  return (
    <div className="db-page" data-testid="db-profile">
      <PageHeader title={t('dash.profile.title', undefined, locale)} lead={t('dash.profile.lead', undefined, locale)} actions={<Pill tone={onb === 'APPROVED' ? 'green' : onb === 'REJECTED' || onb === 'SUSPENDED' ? 'red' : 'amber'}>{t(`dash.onboarding.${onb}`, undefined, locale)}</Pill>} />
      <div className="db-grid db-grid--2">
        <Card>
          <Tabs tabs={[{ id: 'general' as const, label: t('dash.profile.tab.general', undefined, locale) }, { id: 'images' as const, label: t('dash.profile.tab.images', undefined, locale) }, { id: 'cuisine' as const, label: t('dash.profile.tab.cuisine', undefined, locale) }]} value={tab} onChange={setTab} label={t('dash.profile.tabs', undefined, locale)} />
          <form className="db-form-grid" style={{ marginTop: 16 }} onSubmit={(e) => { e.preventDefault(); void save() }} data-testid="profile-form">
            {tab === 'general' && (
              <>
                <Field label={t('dash.profile.name', undefined, locale)} required error={errors.name} id="pf-name"><input id="pf-name" className="db-input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} dir="auto" disabled={!canEdit} data-testid="profile-name" /></Field>
                <Field label={t('dash.profile.description', undefined, locale)} hint={`${form.description.length}/500`} error={errors.description} id="pf-desc"><textarea id="pf-desc" className="db-textarea" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} dir="auto" disabled={!canEdit} /></Field>
                <div className="db-form-row">
                  <Field label={t('dash.profile.phone', undefined, locale)} hint={t('dash.profile.phoneHint', undefined, locale)} id="pf-phone"><input id="pf-phone" className="db-input" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} inputMode="tel" disabled={!canEdit} /></Field>
                  <Field label={t('dash.profile.website', undefined, locale)} error={errors.website} id="pf-web"><input id="pf-web" className="db-input" value={form.website} onChange={(e) => setForm({ ...form, website: e.target.value })} inputMode="url" placeholder="https://" disabled={!canEdit} /></Field>
                </div>
                <div className="db-form-row">
                  <Field label={t('dash.profile.email', undefined, locale)} error={errors.email} id="pf-email"><input id="pf-email" className="db-input" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} inputMode="email" disabled={!canEdit} /></Field>
                  <Field label={t('dash.profile.prep', undefined, locale)} hint={t('dash.profile.prepHint', undefined, locale)} error={errors.prepTimeMin} id="pf-prep"><input id="pf-prep" type="number" min={1} max={180} className="db-input" value={form.prepTimeMin} onChange={(e) => setForm({ ...form, prepTimeMin: Number(e.target.value) })} disabled={!canEdit} /></Field>
                </div>
              </>
            )}
            {tab === 'images' && d.live && (
              <>
                <p className="db-muted" style={{ margin: 0 }} data-testid="profile-images-live">{t('dash.profile.imagesLive', undefined, locale)}</p>
                {[p.logo, p.coverImage, ...p.gallery].filter((u): u is string => !!u).length === 0 ? <p className="db-muted">{t('dash.profile.noImages', undefined, locale)}</p>
                  : <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>{[p.logo, p.coverImage, ...p.gallery].filter((u): u is string => !!u).map((u, i) => <img key={`${u}-${i}`} src={u} alt="" style={{ width: 132, height: 92, objectFit: 'cover', borderRadius: 10 }} />)}</div>}
              </>
            )}
            {tab === 'images' && !d.live && (
              <>
                <p className="db-muted" style={{ margin: 0 }}>{t('dash.profile.imagesNote', undefined, locale)}</p>
                <div className="db-form-row">
                  <ImageUpload kind="logo" value={logo || null} onChange={(u) => setLogo(u ?? '')} label={t('dash.profile.logo', undefined, locale)} hint={t('dash.profile.logoHint', undefined, locale)} disabled={!canEdit} shape="square" testId="upload-logo" />
                  <ImageUpload kind="cover" value={cover || null} onChange={(u) => setCover(u ?? '')} label={t('dash.profile.cover', undefined, locale)} hint={t('dash.profile.coverHint', undefined, locale)} disabled={!canEdit} shape="wide" testId="upload-cover" />
                </div>
                <GalleryUpload value={gallery} onChange={setGallery} label={t('dash.profile.gallery', undefined, locale)} disabled={!canEdit} testId="upload-gallery" />
                <p className="db-field__hint">{t('dash.profile.uploadPending', undefined, locale)}</p>
              </>
            )}
            {tab === 'cuisine' && (
              <>
                <div><p className="db-field__label" style={{ margin: '0 0 6px' }}>{t('dash.profile.cuisines', undefined, locale)}</p><div className="db-tags" data-testid="profile-cuisines">{cuisines.map((c) => <span key={c} className="db-badge db-badge--muted" dir="auto">{c}{canEdit && <button type="button" className="db-link" aria-label={`${t('dash.action.remove', undefined, locale)} ${c}`} onClick={() => setCuisines(cuisines.filter((x) => x !== c))}><Icon name="x" size={12} /></button>}</span>)}</div>{canEdit && <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>{taxonomy
                  ? <select className="db-select" aria-label={t('dash.profile.addCuisine', undefined, locale)} value={newCuisine} onChange={(e) => setNewCuisine(e.target.value)} data-testid="profile-cuisine-select"><option value="">{t('dash.profile.chooseCuisine', undefined, locale)}</option>{taxonomy.cuisines.filter((c) => !cuisines.includes(c.name)).map((c) => <option key={c.code} value={c.name}>{c.name}</option>)}</select>
                  : <input className="db-input db-input--sm" aria-label={t('dash.profile.addCuisine', undefined, locale)} value={newCuisine} onChange={(e) => setNewCuisine(e.target.value)} dir="auto" data-testid="profile-cuisine-input" />}<button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={!!taxonomy && cuisines.length >= taxonomy.limits.cuisines} onClick={() => { const v = newCuisine.trim(); if (v && !cuisines.includes(v)) setCuisines([...cuisines, v]); setNewCuisine('') }} data-testid="profile-cuisine-add"><Icon name="plus" size={14} /> {t('dash.action.add', undefined, locale)}</button></div>}<p className="db-field__hint">{taxonomy ? t('dash.profile.taxonomyHint', { max: taxonomy.limits.cuisines }, locale) : t('dash.profile.cuisineHint', undefined, locale)}</p></div>
                <div><p className="db-field__label" style={{ margin: '0 0 6px' }}>{t('dash.profile.features', undefined, locale)}</p><div className="db-tags">{features.map((c) => <span key={c} className="db-badge db-badge--blue" dir="auto">{c}{canEdit && <button type="button" className="db-link" aria-label={`${t('dash.action.remove', undefined, locale)} ${c}`} onClick={() => setFeatures(features.filter((x) => x !== c))}><Icon name="x" size={12} /></button>}</span>)}</div>{canEdit && <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>{taxonomy
                  ? <select className="db-select" aria-label={t('dash.profile.addFeature', undefined, locale)} value={newFeature} onChange={(e) => setNewFeature(e.target.value)} data-testid="profile-feature-select"><option value="">{t('dash.profile.chooseFeature', undefined, locale)}</option>{taxonomy.features.filter((f) => !features.includes(f.name)).map((f) => <option key={f.code} value={f.name}>{f.name}</option>)}</select>
                  : <input className="db-input db-input--sm" aria-label={t('dash.profile.addFeature', undefined, locale)} value={newFeature} onChange={(e) => setNewFeature(e.target.value)} dir="auto" />}<button type="button" className="db-btn db-btn--outline db-btn--sm" disabled={!!taxonomy && features.length >= taxonomy.limits.features} onClick={() => { const v = newFeature.trim(); if (v && !features.includes(v)) setFeatures([...features, v]); setNewFeature('') }} data-testid="profile-feature-add"><Icon name="plus" size={14} /> {t('dash.action.add', undefined, locale)}</button></div>}{taxonomy && <p className="db-field__hint">{t('dash.profile.taxonomyHint', { max: taxonomy.limits.features }, locale)}</p>}</div>
              </>
            )}
            {canEdit && <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}><button type="button" className="db-btn db-btn--ghost" onClick={() => { setForm({ name: r.name, description: r.description, phone: p.contact.phone ?? '', website: p.contact.website ?? '', email: p.contact.publicEmail ?? '', prepTimeMin: r.prepTimeMin }); setCuisines(r.cuisines); setFeatures(r.features); setErrors({}) }}>{t('dash.action.cancel', undefined, locale)}</button><button type="submit" className="db-btn db-btn--primary" disabled={busy} data-testid="profile-save">{busy ? t('dash.action.saving', undefined, locale) : t('dash.action.saveChanges', undefined, locale)}</button></div>}
            {!canEdit && <p className="db-muted" style={{ margin: 0 }}>{t('dash.profile.readOnly', undefined, locale)}</p>}
          </form>
        </Card>
        <div className="db-grid" style={{ gap: 16 }}>
          <Card title={t('dash.profile.status', undefined, locale)}>
            <div className="db-settings-row"><p>{t('dash.profile.active', undefined, locale)}<small>{t('dash.profile.activeHint', undefined, locale)}</small></p><Pill tone={p.active && r.status === 'active' ? 'green' : 'red'}>{p.active && r.status === 'active' ? t('dash.profile.activeYes', undefined, locale) : t('dash.profile.activeNo', undefined, locale)}</Pill></div>
            {loc.live && <div className="db-settings-row" data-testid="profile-visible"><p>{t('dash.profile.visible', undefined, locale)}<small>{t('dash.profile.visibleHint', undefined, locale)}</small></p><Pill tone={loc.live.availability.visibleToCustomers ? 'green' : 'red'}>{loc.live.availability.visibleToCustomers ? t('dash.profile.visibleYes', undefined, locale) : t('dash.profile.visibleNo', undefined, locale)}</Pill></div>}
            <div className="db-settings-row"><p>{t('dash.profile.openNow', undefined, locale)}<small>{t('dash.profile.openHint', undefined, locale)}</small></p><OpenBadge /></div>
            <div className="db-settings-row"><p>{t('dash.profile.accepting', undefined, locale)}<small>{t('dash.profile.acceptingHint', undefined, locale)}</small></p><AcceptingSwitch /></div>
          </Card>
          <Card title={t('dash.profile.location', undefined, locale)} subtitle={p.locationName}>
            <div className="db-settings-row"><p>{t('dash.profile.address', undefined, locale)}</p><b dir="auto" style={{ textAlign: 'end' }}>{r.address.formatted}</b></div>
            <div className="db-settings-row"><p>{t('dash.profile.country', undefined, locale)}</p><b>{r.countryCode}</b></div>
            <div className="db-settings-row"><p>{t('dash.profile.coordinates', undefined, locale)}</p><b className="db-money">{r.lat.toFixed(4)}, {r.lng.toFixed(4)}</b></div>
            <div className="db-settings-row"><p>{t('dash.profile.timezone', undefined, locale)}</p><b>{r.timezone}</b></div>
            <div className="db-settings-row"><p>{t('dash.profile.currency', undefined, locale)}</p><b>{r.currency}</b></div>
            <p className="db-muted" style={{ fontSize: '0.8rem', marginBottom: 0 }}>{t('dash.profile.locationNote', undefined, locale)}</p>
          </Card>
        </div>
      </div>
      <ToastLine msg={msg} />
    </div>
  )
}

function OpenBadge() {
  const d = useDashboard(); const r = d.location!.restaurant; const locale = d.locale
  // With the backend the answer is the backend's (location time zone, special dates, temporarily closed).
  const live = d.location!.live
  if (live) return <Pill tone={live.availability.openNow ? 'green' : 'muted'}>{live.availability.openNow ? t('dash.profile.openYes', undefined, locale) : t('dash.profile.openNo', undefined, locale)}</Pill>
  const now = new Date(); const wd = Number(new Intl.DateTimeFormat('en-US', { timeZone: r.timezone, weekday: 'short' }).format(now).slice(0, 3) === 'Sun' ? 0 : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(new Intl.DateTimeFormat('en-US', { timeZone: r.timezone, weekday: 'short' }).format(now)) + 1)
  const [h, m] = new Intl.DateTimeFormat('en-GB', { timeZone: r.timezone, hour: '2-digit', minute: '2-digit', hour12: false }).format(now).split(':').map(Number); const mins = h * 60 + m
  const hm = (s: string) => { const [a, b] = s.split(':').map(Number); return a * 60 + b }
  const open = r.status === 'active' && r.openingHours.periods.some((p) => { const o = hm(p.open), c = hm(p.close); if (c > o) return p.day === wd && mins >= o && mins < c; return (p.day === wd && mins >= o) || (p.day === (wd + 6) % 7 && mins < c) })
  return <Pill tone={open ? 'green' : 'muted'}>{open ? t('dash.profile.openYes', undefined, locale) : t('dash.profile.openNo', undefined, locale)}</Pill>
}

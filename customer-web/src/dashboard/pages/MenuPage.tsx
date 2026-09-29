import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { formatMoney, minorDigits } from '../../i18n/format'
import { t } from '../../i18n/strings'
import type { ItemAvailability, MenuCategory, MenuItem, ModifierGroup, OptionChoice, OptionGroup, VariantGroup } from '../../menu/repositories'
import { useDashboard } from '../DashboardContext'
import { AvailabilityBadge, Card, ConfirmDialog, Drawer, EmptyState, ErrorState, Field, Icon, PageHeader, Pill, Skeleton, Tabs, Thumb, ToastLine, useToastMessage } from '../components/ui'
import type { ManagedMenu, MenuItemInput, MenuValidationIssue } from '../types'

/**
 * Menu management (Module 17): Categories · Items · Modifiers tabs, search + filters, item editor drawer (customer-compatible
 * MenuItem + generic option groups), availability badges, duplicate / archive, customer preview link.
 * Prices are edited as decimal strings and stored as integer minor units (ISO 4217 digits) — never floats.
 */
const AVAIL: ItemAvailability[] = ['available', 'sold_out', 'temporarily_unavailable', 'unavailable']
export const toMinor = (text: string, currency: string): number | null => { const s = text.trim().replace(',', '.'); if (!/^\d+(\.\d+)?$/.test(s)) return null; const digits = minorDigits(currency); const [i, f = ''] = s.split('.'); if (f.length > digits) return null; return Number(i) * 10 ** digits + Number((f + '0'.repeat(digits)).slice(0, digits)) }
export const fromMinor = (minor: number, currency: string) => { const digits = minorDigits(currency); return digits === 0 ? String(minor) : `${Math.trunc(minor / 10 ** digits)}.${String(Math.abs(minor % 10 ** digits)).padStart(digits, '0')}` }
export function validateItem(input: { name: string; categoryId: string; price: string; prepTimeMin: number }, currency: string, groups: OptionGroup[]): MenuValidationIssue[] {
  const issues: MenuValidationIssue[] = []
  if (!input.name.trim()) issues.push({ field: 'name', code: 'required' })
  if (!input.categoryId) issues.push({ field: 'categoryId', code: 'required' })
  if (toMinor(input.price, currency) == null) issues.push({ field: 'price', code: 'invalid_price' })
  if (!Number.isInteger(input.prepTimeMin) || input.prepTimeMin < 0 || input.prepTimeMin > 240) issues.push({ field: 'prepTimeMin', code: 'invalid_range' })
  groups.forEach((g, gi) => {
    if (!g.name.trim()) issues.push({ field: `group.${gi}.name`, code: 'required' })
    if (g.options.length === 0) issues.push({ field: `group.${gi}.options`, code: 'no_options' })
    if (g.minSelections < 0 || g.maxSelections < 1 || g.minSelections > g.maxSelections || g.maxSelections > Math.max(1, g.options.length)) issues.push({ field: `group.${gi}.range`, code: 'min_max' })
    if (g.required && g.minSelections < 1) issues.push({ field: `group.${gi}.range`, code: 'min_max' })
    if (g.options.some((o) => !o.name.trim())) issues.push({ field: `group.${gi}.optionName`, code: 'required' })
  })
  return issues
}

const newGroup = (kind: 'variant' | 'modifier', order: number): OptionGroup => ({ id: `grp_${Date.now().toString(36)}${order}`, kind, name: '', required: kind === 'variant', minSelections: kind === 'variant' ? 1 : 0, maxSelections: 1, displayOrder: order, options: [] })
const newOption = (order: number): OptionChoice => ({ id: `opt_${Date.now().toString(36)}${order}`, name: '', priceAdjustmentMinor: 0, available: true, defaultSelected: false, displayOrder: order })

function GroupEditor({ group, currency, locale, onChange, onRemove, issues, index }: { group: OptionGroup; currency: string; locale: string; onChange: (g: OptionGroup) => void; onRemove: () => void; issues: MenuValidationIssue[]; index: number }) {
  const err = (f: string) => issues.find((i) => i.field === `group.${index}.${f}`)
  return (
    <div className="db-card" style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 10 }} data-testid={`group-${group.kind}`}>
      <div className="db-form-row">
        <Field label={t('dash.menu.groupName', undefined, locale)} required error={err('name') && t('dash.validation.required', undefined, locale)}><input className="db-input db-input--sm" value={group.name} onChange={(e) => onChange({ ...group, name: e.target.value })} placeholder={group.kind === 'variant' ? t('dash.menu.groupNameVariantHint', undefined, locale) : t('dash.menu.groupNameModifierHint', undefined, locale)} /></Field>
        <div className="db-form-row">
          <Field label={t('dash.menu.minSelections', undefined, locale)} error={err('range') && t('dash.validation.minMax', undefined, locale)}><input type="number" min={0} className="db-input db-input--sm" value={group.minSelections} onChange={(e) => onChange({ ...group, minSelections: Number(e.target.value), required: Number(e.target.value) >= 1 })} /></Field>
          <Field label={t('dash.menu.maxSelections', undefined, locale)}><input type="number" min={1} className="db-input db-input--sm" value={group.maxSelections} onChange={(e) => onChange({ ...group, maxSelections: Number(e.target.value) })} /></Field>
        </div>
      </div>
      <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: '0.88rem' }}><input type="checkbox" checked={group.required} onChange={(e) => onChange({ ...group, required: e.target.checked, minSelections: e.target.checked ? Math.max(1, group.minSelections) : group.minSelections })} /> {t('dash.menu.groupRequired', undefined, locale)}</label>
      {err('options') && <p className="db-field__error" role="alert">{t('dash.validation.noOptions', undefined, locale)}</p>}
      <div className="db-table-wrap"><table className="db-table"><thead><tr><th scope="col">{t('dash.menu.optionName', undefined, locale)}</th><th scope="col">{t('dash.menu.priceAdj', { currency }, locale)}</th><th scope="col">{t('dash.menu.optionAvailable', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.orders.col.actions', undefined, locale)}</span></th></tr></thead>
        <tbody>{group.options.map((o, oi) => <tr key={o.id}><td><input className="db-input db-input--sm" aria-label={t('dash.menu.optionName', undefined, locale)} value={o.name} onChange={(e) => onChange({ ...group, options: group.options.map((x, k) => (k === oi ? { ...x, name: e.target.value } : x)) })} /></td><td><input className="db-input db-input--sm" style={{ width: 110 }} inputMode="decimal" aria-label={t('dash.menu.priceAdj', { currency }, locale)} value={fromMinor(o.priceAdjustmentMinor, currency)} onChange={(e) => { const m = toMinor(e.target.value || '0', currency); onChange({ ...group, options: group.options.map((x, k) => (k === oi ? { ...x, priceAdjustmentMinor: m ?? x.priceAdjustmentMinor } : x)) }) }} /></td><td><input type="checkbox" aria-label={t('dash.menu.optionAvailable', undefined, locale)} checked={o.available} onChange={(e) => onChange({ ...group, options: group.options.map((x, k) => (k === oi ? { ...x, available: e.target.checked } : x)) })} /></td><td><button type="button" className="db-iconbtn" style={{ width: 34, height: 34 }} aria-label={t('dash.action.remove', undefined, locale)} onClick={() => onChange({ ...group, options: group.options.filter((_, k) => k !== oi) })}><Icon name="trash" size={16} /></button></td></tr>)}</tbody></table></div>
      <div style={{ display: 'flex', gap: 8 }}><button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => onChange({ ...group, options: [...group.options, newOption(group.options.length)] })}><Icon name="plus" size={14} /> {t('dash.menu.addOption', undefined, locale)}</button><button type="button" className="db-btn db-btn--danger db-btn--sm" onClick={onRemove}>{t('dash.menu.removeGroup', undefined, locale)}</button></div>
    </div>
  )
}

export function ItemEditor({ open, onClose, item, groups, categories, currency, onSaved }: { open: boolean; onClose: () => void; item: MenuItem | null; groups: ManagedMenu['groups'][string] | null; categories: MenuCategory[]; currency: string; onSaved: (i: MenuItem) => void }) {
  const d = useDashboard(); const locale = d.locale; const rid = d.location!.restaurant.id
  const [form, setForm] = useState({ name: '', description: '', categoryId: categories[0]?.id ?? '', price: '', prepTimeMin: 10, availability: 'available' as ItemAvailability, dietary: '', image: '', featured: false })
  const [vgroups, setVgroups] = useState<OptionGroup[]>([]); const [mgroups, setMgroups] = useState<OptionGroup[]>([]); const [issues, setIssues] = useState<MenuValidationIssue[]>([]); const [busy, setBusy] = useState(false); const [err, setErr] = useState<string | null>(null)
  useEffect(() => { if (!open) return; setIssues([]); setErr(null); if (item) { setForm({ name: item.name, description: item.description, categoryId: item.categoryId, price: fromMinor(item.basePriceMinor, currency), prepTimeMin: item.prepTimeMin, availability: item.availability, dietary: item.dietaryTags.join(', '), image: item.images[0] ?? '', featured: item.featured }); setVgroups(groups?.variantGroups ?? []); setMgroups(groups?.modifierGroups ?? []) } else { setForm({ name: '', description: '', categoryId: categories[0]?.id ?? '', price: '', prepTimeMin: 10, availability: 'available', dietary: '', image: '', featured: false }); setVgroups([]); setMgroups([]) } }, [open, item, groups, categories, currency])
  const all = [...vgroups, ...mgroups]
  const save = async () => {
    const iss = validateItem(form, currency, all); setIssues(iss); if (iss.length) return
    setBusy(true); setErr(null)
    try {
      const input: MenuItemInput = { id: item?.id, name: form.name.trim(), description: form.description.trim(), categoryId: form.categoryId, basePriceMinor: toMinor(form.price, currency)!, availability: form.availability, dietaryTags: form.dietary.split(',').map((s) => s.trim()).filter(Boolean), customizable: all.length > 0, prepTimeMin: form.prepTimeMin, displayOrder: item?.displayOrder ?? 999, featured: form.featured, status: 'active', images: form.image ? [form.image] : [] }
      const saved = await d.repos.menu.saveItem(rid, input, { variantGroups: vgroups.map((g, i) => ({ ...g, kind: 'variant', displayOrder: i })) as VariantGroup[], modifierGroups: mgroups.map((g, i) => ({ ...g, kind: 'modifier', displayOrder: i })) as ModifierGroup[] })
      onSaved(saved)
    } catch { setErr(t('dash.menu.saveFailed', undefined, locale)) } finally { setBusy(false) }
  }
  const e = (f: string) => issues.find((i) => i.field === f)
  return (
    <Drawer open={open} onClose={onClose} title={item ? t('dash.menu.editItem', undefined, locale) : t('dash.menu.addItem', undefined, locale)} wide footer={<><button type="button" className="db-btn db-btn--ghost" onClick={onClose}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void save() }} disabled={busy} data-testid="item-save">{busy ? t('dash.action.saving', undefined, locale) : t('dash.action.save', undefined, locale)}</button></>}>
      {err && <p className="db-field__error" role="alert">{err}</p>}
      {issues.length > 0 && <p className="db-field__error" role="alert" data-testid="item-errors">{t('dash.validation.summary', { n: issues.length }, locale)}</p>}
      <div className="db-form-grid" data-testid="item-form">
        <Field label={t('dash.menu.itemName', undefined, locale)} required error={e('name') && t('dash.validation.required', undefined, locale)} id="it-name"><input id="it-name" className="db-input" value={form.name} onChange={(ev) => setForm({ ...form, name: ev.target.value })} dir="auto" data-testid="item-name" /></Field>
        <Field label={t('dash.menu.itemDescription', undefined, locale)} id="it-desc"><textarea id="it-desc" className="db-textarea" value={form.description} onChange={(ev) => setForm({ ...form, description: ev.target.value })} dir="auto" /></Field>
        <div className="db-form-row">
          <Field label={t('dash.menu.category', undefined, locale)} required error={e('categoryId') && t('dash.validation.required', undefined, locale)} id="it-cat"><select id="it-cat" className="db-select" value={form.categoryId} onChange={(ev) => setForm({ ...form, categoryId: ev.target.value })} data-testid="item-category">{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
          <Field label={t('dash.menu.basePrice', { currency }, locale)} required hint={t('dash.menu.priceHint', { digits: minorDigits(currency) }, locale)} error={e('price') && t('dash.validation.price', undefined, locale)} id="it-price"><input id="it-price" className="db-input" inputMode="decimal" value={form.price} onChange={(ev) => setForm({ ...form, price: ev.target.value })} data-testid="item-price" /></Field>
        </div>
        <div className="db-form-row">
          <Field label={t('dash.menu.prepTime', undefined, locale)} error={e('prepTimeMin') && t('dash.validation.range', undefined, locale)} id="it-prep"><input id="it-prep" type="number" min={0} max={240} className="db-input" value={form.prepTimeMin} onChange={(ev) => setForm({ ...form, prepTimeMin: Number(ev.target.value) })} /></Field>
          <Field label={t('dash.menu.availability', undefined, locale)} id="it-av"><select id="it-av" className="db-select" value={form.availability} onChange={(ev) => setForm({ ...form, availability: ev.target.value as ItemAvailability })} data-testid="item-availability">{AVAIL.map((a) => <option key={a} value={a}>{t(`dash.availability.${a}`, undefined, locale)}</option>)}</select></Field>
        </div>
        <div className="db-form-row">
          <Field label={t('dash.menu.dietary', undefined, locale)} hint={t('dash.menu.dietaryHint', undefined, locale)} id="it-diet"><input id="it-diet" className="db-input" value={form.dietary} onChange={(ev) => setForm({ ...form, dietary: ev.target.value })} /></Field>
          <Field label={t('dash.menu.image', undefined, locale)} hint={t('dash.menu.imageHint', undefined, locale)} id="it-img"><input id="it-img" className="db-input" value={form.image} onChange={(ev) => setForm({ ...form, image: ev.target.value })} placeholder="/images/food-burger.jpg" /></Field>
        </div>
        <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="checkbox" checked={form.featured} onChange={(ev) => setForm({ ...form, featured: ev.target.checked })} /> {t('dash.menu.featured', undefined, locale)}</label>
        <Card title={t('dash.menu.variants', undefined, locale)} subtitle={t('dash.menu.variantsSub', undefined, locale)} actions={<button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setVgroups([...vgroups, newGroup('variant', vgroups.length)])} data-testid="add-variant"><Icon name="plus" size={14} /> {t('dash.menu.addGroup', undefined, locale)}</button>}>
          {vgroups.length === 0 && <p className="db-muted" style={{ margin: 0 }}>{t('dash.menu.noGroups', undefined, locale)}</p>}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>{vgroups.map((g, i) => <GroupEditor key={g.id} group={g} index={i} currency={currency} locale={locale} issues={issues} onChange={(ng) => setVgroups(vgroups.map((x, k) => (k === i ? ng : x)))} onRemove={() => setVgroups(vgroups.filter((_, k) => k !== i))} />)}</div>
        </Card>
        <Card title={t('dash.menu.modifiers', undefined, locale)} subtitle={t('dash.menu.modifiersSub', undefined, locale)} actions={<button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setMgroups([...mgroups, newGroup('modifier', mgroups.length)])} data-testid="add-modifier"><Icon name="plus" size={14} /> {t('dash.menu.addGroup', undefined, locale)}</button>}>
          {mgroups.length === 0 && <p className="db-muted" style={{ margin: 0 }}>{t('dash.menu.noGroups', undefined, locale)}</p>}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>{mgroups.map((g, i) => <GroupEditor key={g.id} group={g} index={vgroups.length + i} currency={currency} locale={locale} issues={issues} onChange={(ng) => setMgroups(mgroups.map((x, k) => (k === i ? ng : x)))} onRemove={() => setMgroups(mgroups.filter((_, k) => k !== i))} />)}</div>
        </Card>
      </div>
    </Drawer>
  )
}

export default function MenuPage() {
  const d = useDashboard(); const locale = d.locale; const loc = d.location!; const rid = loc.restaurant.id; const cur = loc.restaurant.currency; const canEdit = d.can('menu.edit')
  const [params, setParams] = useSearchParams(); const tab = (['categories', 'items', 'modifiers'].includes(params.get('tab') ?? '') ? params.get('tab') : 'items') as 'categories' | 'items' | 'modifiers'
  const [menu, setMenu] = useState<ManagedMenu | null>(null); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading')
  const [query, setQuery] = useState(''); const [cat, setCat] = useState('all'); const [status, setStatus] = useState(params.get('status') ?? 'all')
  const [editor, setEditor] = useState<{ open: boolean; item: MenuItem | null }>({ open: false, item: null }); const [catEditor, setCatEditor] = useState<{ open: boolean; cat: MenuCategory | null }>({ open: false, cat: null }); const [catName, setCatName] = useState(''); const [catDesc, setCatDesc] = useState(''); const [catErr, setCatErr] = useState<string | null>(null)
  const [archive, setArchive] = useState<MenuItem | null>(null); const { msg, toast } = useToastMessage(); const [menuOpen, setMenuOpen] = useState<string | null>(null)
  const load = useCallback(async () => { setState('loading'); try { setMenu(await d.repos.menu.getMenu(rid)); setState('ready') } catch { setState('error') } }, [d.repos, rid])
  useEffect(() => { void load() }, [load])
  useEffect(() => { document.title = `${t('dash.nav.menu', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const cats = useMemo(() => [...(menu?.categories ?? [])].sort((a, b) => a.displayOrder - b.displayOrder), [menu])
  const items = useMemo(() => { const q = query.trim().toLowerCase(); return (menu?.items ?? []).filter((i) => i.status === 'active' || status === 'archived').filter((i) => status === 'all' ? i.status === 'active' : status === 'archived' ? i.status === 'inactive' : status === 'unavailable' ? i.availability !== 'available' && i.status === 'active' : i.availability === status && i.status === 'active').filter((i) => cat === 'all' || i.categoryId === cat).filter((i) => !q || i.name.toLowerCase().includes(q) || (i.alternateNames ?? []).some((a) => a.toLowerCase().includes(q)) || i.description.toLowerCase().includes(q)).sort((a, b) => (cats.findIndex((c) => c.id === a.categoryId) - cats.findIndex((c) => c.id === b.categoryId)) || a.displayOrder - b.displayOrder) }, [menu, query, cat, status, cats])
  const setAvail = async (it: MenuItem, a: ItemAvailability) => { await d.repos.menu.setAvailability(rid, it.id, a); toast(t('dash.menu.availabilityUpdated', { name: it.name, status: t(`dash.availability.${a}`, undefined, locale) }, locale)); await load() }
  const saveCat = async () => { if (!catName.trim()) { setCatErr(t('dash.validation.required', undefined, locale)); return } if (cats.some((c) => c.name.toLowerCase() === catName.trim().toLowerCase() && c.id !== catEditor.cat?.id)) { setCatErr(t('dash.validation.duplicate', undefined, locale)); return } await d.repos.menu.saveCategory(rid, { id: catEditor.cat?.id, name: catName.trim(), description: catDesc.trim() || undefined }); setCatEditor({ open: false, cat: null }); toast(t('dash.menu.categorySaved', undefined, locale)); await load() }
  const move = async (c: MenuCategory, dir: -1 | 1) => { const ids = cats.map((x) => x.id); const i = ids.indexOf(c.id); const j = i + dir; if (j < 0 || j >= ids.length) return; [ids[i], ids[j]] = [ids[j], ids[i]]; await d.repos.menu.reorderCategories(rid, ids); await load() }
  const modifierRows = useMemo(() => (menu ? menu.items.filter((i) => i.status === 'active').flatMap((i) => [...(menu.groups[i.id]?.variantGroups ?? []), ...(menu.groups[i.id]?.modifierGroups ?? [])].map((g) => ({ item: i, group: g }))) : []), [menu])
  return (
    <div className="db-page" data-testid="db-menu">
      <PageHeader title={t('dash.menu.title', undefined, locale)} lead={t('dash.menu.lead', { currency: cur }, locale)} actions={<><Link to={`/restaurants/${loc.restaurant.slug}`} target="_blank" rel="noreferrer" className="db-btn db-btn--outline"><Icon name="eye" size={18} /> {t('dash.menu.preview', undefined, locale)}</Link>{canEdit && <button type="button" className="db-btn db-btn--primary" onClick={() => setEditor({ open: true, item: null })} data-testid="menu-add"><Icon name="plus" size={18} /> {t('dash.menu.addItem', undefined, locale)}</button>}</>} />
      <Card>
        <Tabs tabs={[{ id: 'categories' as const, label: t('dash.menu.tab.categories', undefined, locale), count: cats.length }, { id: 'items' as const, label: t('dash.menu.tab.items', undefined, locale), count: menu?.items.filter((i) => i.status === 'active').length }, { id: 'modifiers' as const, label: t('dash.menu.tab.modifiers', undefined, locale), count: modifierRows.length }]} value={tab} onChange={(v) => setParams({ tab: v })} label={t('dash.menu.tabs', undefined, locale)} />
        {state === 'loading' && <Skeleton rows={6} />}
        {state === 'error' && <ErrorState title={t('dash.menu.errorTitle', undefined, locale)} text={t('dash.error.loadText', undefined, locale)} onRetry={() => { void load() }} locale={locale} />}
        {state === 'ready' && menu && tab === 'items' && (
          <>
            <div className="db-toolbar" style={{ marginTop: 14 }}>
              <label className="db-search"><Icon name="search" size={18} /><span className="db-sr-only">{t('dash.menu.search', undefined, locale)}</span><input type="search" className="db-input" placeholder={t('dash.menu.searchPlaceholder', undefined, locale)} value={query} onChange={(e) => setQuery(e.target.value)} data-testid="menu-search" /></label>
              <select className="db-select db-input--sm" style={{ width: 'auto' }} aria-label={t('dash.menu.category', undefined, locale)} value={cat} onChange={(e) => setCat(e.target.value)} data-testid="menu-filter-cat"><option value="all">{t('dash.menu.allCategories', undefined, locale)}</option>{cats.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
              <select className="db-select db-input--sm" style={{ width: 'auto' }} aria-label={t('dash.menu.status', undefined, locale)} value={status} onChange={(e) => setStatus(e.target.value)} data-testid="menu-filter-status"><option value="all">{t('dash.menu.allStatus', undefined, locale)}</option>{AVAIL.map((a) => <option key={a} value={a}>{t(`dash.availability.${a}`, undefined, locale)}</option>)}<option value="unavailable">{t('dash.menu.anyUnavailable', undefined, locale)}</option><option value="archived">{t('dash.availability.inactive', undefined, locale)}</option></select>
              <span className="db-muted" style={{ marginInlineStart: 'auto', fontSize: '0.85rem' }} data-testid="menu-count">{t('dash.menu.count', { n: items.length }, locale)}</span>
            </div>
            {items.length === 0 ? <EmptyState icon="menu" title={t('dash.menu.emptyTitle', undefined, locale)} text={t('dash.menu.emptyText', undefined, locale)} action={canEdit ? <button type="button" className="db-btn db-btn--primary" onClick={() => setEditor({ open: true, item: null })}>{t('dash.menu.addItem', undefined, locale)}</button> : undefined} /> : (
              <div className="db-table-wrap" style={{ marginTop: 12 }}><table className="db-table" data-testid="menu-table">
                <thead><tr><th scope="col">#</th><th scope="col">{t('dash.menu.itemName', undefined, locale)}</th><th scope="col">{t('dash.menu.category', undefined, locale)}</th><th scope="col">{t('dash.menu.price', undefined, locale)}</th><th scope="col">{t('dash.menu.status', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.orders.col.actions', undefined, locale)}</span></th></tr></thead>
                <tbody>{items.map((it, i) => <tr key={it.id} data-testid="menu-row" data-item={it.slug}><td className="db-muted">{i + 1}</td><td><div style={{ display: 'flex', gap: 10, alignItems: 'center' }}><Thumb src={it.image} name={it.name} /><span><b dir="auto">{it.name}</b>{it.featured && <Pill tone="orange">★</Pill>}<br /><small className="db-muted" dir="auto">{it.description.slice(0, 60)}{it.description.length > 60 ? '…' : ''}</small></span></div></td><td dir="auto">{cats.find((c) => c.id === it.categoryId)?.name ?? '—'}</td><td className="db-money">{formatMoney(it.basePriceMinor, cur, locale)}</td><td><AvailabilityBadge availability={it.availability} inactive={it.status === 'inactive'} locale={locale} /></td><td><div className="db-table__actions" style={{ position: 'relative' }}>{canEdit && it.status === 'active' && <><button type="button" className="db-iconbtn" style={{ width: 36, height: 36 }} aria-label={`${t('dash.action.edit', undefined, locale)} ${it.name}`} onClick={() => setEditor({ open: true, item: it })} data-testid="item-edit"><Icon name="edit" size={16} /></button><button type="button" className="db-iconbtn" style={{ width: 36, height: 36 }} aria-haspopup="menu" aria-expanded={menuOpen === it.id} aria-label={`${t('dash.action.more', undefined, locale)} ${it.name}`} onClick={() => setMenuOpen(menuOpen === it.id ? null : it.id)} data-testid="item-more"><Icon name="more" size={16} /></button>{menuOpen === it.id && <div className="db-profile__menu" role="menu" style={{ top: 40 }}>{AVAIL.filter((a) => a !== it.availability).map((a) => <button key={a} type="button" role="menuitem" className="db-loc__opt" onClick={() => { setMenuOpen(null); void setAvail(it, a) }} data-testid={`set-${a}`}>{t('dash.menu.markAs', { status: t(`dash.availability.${a}`, undefined, locale) }, locale)}</button>)}<button type="button" role="menuitem" className="db-loc__opt" onClick={async () => { setMenuOpen(null); await d.repos.menu.duplicateItem(rid, it.id); toast(t('dash.menu.duplicated', undefined, locale)); await load() }}><Icon name="copy" size={14} /> {t('dash.action.duplicate', undefined, locale)}</button><button type="button" role="menuitem" className="db-loc__opt" style={{ color: 'var(--db-red)' }} onClick={() => { setMenuOpen(null); setArchive(it) }}><Icon name="trash" size={14} /> {t('dash.action.archive', undefined, locale)}</button></div>}</>}</div></td></tr>)}</tbody>
              </table></div>
            )}
          </>
        )}
        {state === 'ready' && menu && tab === 'categories' && (
          <div style={{ marginTop: 14 }}>
            {canEdit && <button type="button" className="db-btn db-btn--primary db-btn--sm" onClick={() => { setCatEditor({ open: true, cat: null }); setCatName(''); setCatDesc(''); setCatErr(null) }} data-testid="cat-add"><Icon name="plus" size={14} /> {t('dash.menu.addCategory', undefined, locale)}</button>}
            {cats.length === 0 ? <EmptyState icon="menu" title={t('dash.menu.noCategories', undefined, locale)} /> : (
              <div className="db-table-wrap" style={{ marginTop: 12 }}><table className="db-table" data-testid="cat-table"><thead><tr><th scope="col">{t('dash.menu.displayOrder', undefined, locale)}</th><th scope="col">{t('dash.menu.categoryName', undefined, locale)}</th><th scope="col">{t('dash.menu.itemDescription', undefined, locale)}</th><th scope="col">{t('dash.menu.tab.items', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.orders.col.actions', undefined, locale)}</span></th></tr></thead>
                <tbody>{cats.map((c, i) => <tr key={c.id} data-testid="cat-row"><td>{i + 1}</td><td dir="auto"><b>{c.name}</b></td><td className="db-muted" dir="auto">{c.description ?? ''}</td><td>{menu.items.filter((it) => it.categoryId === c.id && it.status === 'active').length}</td><td><div className="db-table__actions">{canEdit && <><button type="button" className="db-iconbtn" style={{ width: 34, height: 34 }} aria-label={t('dash.action.moveUp', undefined, locale)} disabled={i === 0} onClick={() => { void move(c, -1) }}><Icon name="up" size={14} /></button><button type="button" className="db-iconbtn" style={{ width: 34, height: 34 }} aria-label={t('dash.action.moveDown', undefined, locale)} disabled={i === cats.length - 1} onClick={() => { void move(c, 1) }}><Icon name="down" size={14} /></button><button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { setCatEditor({ open: true, cat: c }); setCatName(c.name); setCatDesc(c.description ?? ''); setCatErr(null) }}>{t('dash.action.edit', undefined, locale)}</button></>}</div></td></tr>)}</tbody></table></div>
            )}
          </div>
        )}
        {state === 'ready' && menu && tab === 'modifiers' && (
          <div style={{ marginTop: 14 }}>
            <p className="db-muted" style={{ marginTop: 0 }}>{t('dash.menu.modifiersLead', undefined, locale)}</p>
            {modifierRows.length === 0 ? <EmptyState icon="menu" title={t('dash.menu.noModifiers', undefined, locale)} /> : (
              <div className="db-table-wrap"><table className="db-table" data-testid="mod-table"><thead><tr><th scope="col">{t('dash.menu.groupName', undefined, locale)}</th><th scope="col">{t('dash.menu.kind', undefined, locale)}</th><th scope="col">{t('dash.menu.itemName', undefined, locale)}</th><th scope="col">{t('dash.menu.rules', undefined, locale)}</th><th scope="col">{t('dash.menu.options', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.orders.col.actions', undefined, locale)}</span></th></tr></thead>
                <tbody>{modifierRows.map(({ item, group }) => <tr key={item.id + group.id}><td dir="auto"><b>{group.name}</b></td><td><Pill tone={group.kind === 'variant' ? 'blue' : 'purple'}>{t(`dash.menu.kind.${group.kind}`, undefined, locale)}</Pill></td><td dir="auto">{item.name}</td><td className="db-muted">{group.required ? t('dash.menu.required', undefined, locale) : t('dash.menu.optional', undefined, locale)} · {group.minSelections}–{group.maxSelections}</td><td dir="auto">{group.options.map((o) => o.name + (o.available ? '' : ` (${t('dash.availability.sold_out', undefined, locale)})`)).join(', ')}</td><td><div className="db-table__actions">{canEdit && <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => setEditor({ open: true, item })}>{t('dash.action.edit', undefined, locale)}</button>}</div></td></tr>)}</tbody></table></div>
            )}
          </div>
        )}
      </Card>
      <ItemEditor open={editor.open} onClose={() => setEditor({ open: false, item: null })} item={editor.item} groups={editor.item ? (menu?.groups[editor.item.id] ?? null) : null} categories={cats} currency={cur} onSaved={(it) => { setEditor({ open: false, item: null }); toast(t('dash.menu.itemSaved', { name: it.name }, locale)); void load() }} />
      <Drawer open={catEditor.open} onClose={() => setCatEditor({ open: false, cat: null })} title={catEditor.cat ? t('dash.menu.editCategory', undefined, locale) : t('dash.menu.addCategory', undefined, locale)} footer={<><button type="button" className="db-btn db-btn--ghost" onClick={() => setCatEditor({ open: false, cat: null })}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void saveCat() }} data-testid="cat-save">{t('dash.action.save', undefined, locale)}</button></>}>
        <Field label={t('dash.menu.categoryName', undefined, locale)} required error={catErr ?? undefined} id="cat-name"><input id="cat-name" className="db-input" value={catName} onChange={(e) => setCatName(e.target.value)} dir="auto" data-testid="cat-name" /></Field>
        <Field label={t('dash.menu.itemDescription', undefined, locale)} id="cat-desc"><input id="cat-desc" className="db-input" value={catDesc} onChange={(e) => setCatDesc(e.target.value)} dir="auto" /></Field>
      </Drawer>
      <ConfirmDialog open={!!archive} title={t('dash.menu.archiveTitle', { name: archive?.name ?? '' }, locale)} text={t('dash.menu.archiveText', undefined, locale)} confirmLabel={t('dash.action.archive', undefined, locale)} cancelLabel={t('dash.action.cancel', undefined, locale)} danger onCancel={() => setArchive(null)} onConfirm={async () => { if (archive) { await d.repos.menu.archiveItem(rid, archive.id); setArchive(null); toast(t('dash.menu.archived', undefined, locale)); await load() } }} />
      <ToastLine msg={msg} />
    </div>
  )
}

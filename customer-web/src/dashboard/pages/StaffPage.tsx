import { useCallback, useEffect, useState } from 'react'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { ROLES } from '../mock/fixtures'
import ImageUpload from '../components/ImageUpload'
import { Avatar, Card, ConfirmDialog, Drawer, EmptyState, ErrorState, Field, Icon, PageHeader, Pill, Skeleton, ToastLine, useToastMessage } from '../components/ui'
import type { Role, RoleId, StaffMember } from '../types'
import { dashboardErrorMessage } from '../api/apiDashboard'

/** Staff management (Module 17): list, invite, role + location access, suspend / remove; permission matrix per role. */
export default function StaffPage() {
  const d = useDashboard(); const locale = d.locale; const canManage = d.can('staff.manage'); const here = d.location?.restaurant.id
  const [list, setList] = useState<StaffMember[]>([]); const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading'); const [drawer, setDrawer] = useState<{ open: boolean; member: StaffMember | null }>({ open: false, member: null })
  const [form, setForm] = useState<{ name: string; email: string; role: RoleId; access: 'all' | string[]; avatar: string | null }>({ name: '', email: '', role: 'order_staff', access: 'all', avatar: null }); const [errors, setErrors] = useState<Record<string, string>>({}); const [remove, setRemove] = useState<StaffMember | null>(null); const { msg, toast } = useToastMessage()
  // With the backend the role bundles are the backend's own (they can change without a release); the fixtures are the fallback.
  const [roles, setRoles] = useState<Role[]>(ROLES)
  useEffect(() => { let on = true; d.repos.staff.roles?.().then((r) => { if (on && r.length) setRoles(r) }).catch(() => undefined); return () => { on = false } }, [d.repos])
  const load = useCallback(async () => { setState('loading'); try { setList(await d.repos.staff.list(here)); setState('ready') } catch { setState('error') } }, [d.repos, here])
  // A refused change (not your own membership, the last owner, a role above your own …) is shown; the list is loaded again.
  const act = async (fn: () => Promise<unknown>, done?: string) => { try { await fn(); if (done) toast(done) } catch (e) { toast(d.live ? dashboardErrorMessage(e) : t('dash.staff.saveFailed', undefined, locale)) } await load() }
  useEffect(() => { void load() }, [load])
  useEffect(() => { document.title = `${t('dash.nav.staff', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const openInvite = () => { setForm({ name: '', email: '', role: 'order_staff', access: 'all', avatar: null }); setErrors({}); setDrawer({ open: true, member: null }) }
  const openEdit = (m: StaffMember) => { setForm({ name: m.name, email: m.email, role: m.role, access: m.locationAccess, avatar: m.avatar ?? null }); setErrors({}); setDrawer({ open: true, member: m }) }
  const save = async () => {
    const e: Record<string, string> = {}; if (!form.name.trim()) e.name = t('dash.validation.required', undefined, locale); if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) e.email = t('dash.validation.email', undefined, locale); if (form.access !== 'all' && form.access.length === 0) e.access = t('dash.staff.accessRequired', undefined, locale); setErrors(e); if (Object.keys(e).length) return
    try { if (drawer.member) { await d.repos.staff.update(drawer.member.id, { role: form.role, locationAccess: form.access, avatar: form.avatar }, here); toast(t('dash.staff.updated', undefined, locale)) } else { await d.repos.staff.invite({ name: form.name.trim(), email: form.email.trim(), role: form.role, locationAccess: form.access, avatar: form.avatar }, here); toast(t('dash.staff.invited', { name: form.name.trim() }, locale)) } setDrawer({ open: false, member: null }); await load() }
    catch (err) { setErrors({ form: (err as Error).message === 'staff_duplicate_email' ? t('dash.staff.duplicate', undefined, locale) : (err as Error).message === 'last_owner' ? t('dash.staff.lastOwner', undefined, locale) : d.live ? dashboardErrorMessage(err) : t('dash.staff.saveFailed', undefined, locale) }) }
  }
  const locName = (id: string) => d.locations.find((l) => l.restaurant.id === id)?.restaurant.name ?? id
  const perms = roles.find((r) => r.id === form.role)?.permissions ?? []
  const allPerms = ROLES[0].permissions.filter((p) => roles.some((r) => r.permissions.includes(p)))
  return (
    <div className="db-page" data-testid="db-staff">
      <PageHeader title={t('dash.staff.title', undefined, locale)} lead={t('dash.staff.lead', undefined, locale)} actions={canManage ? <button type="button" className="db-btn db-btn--primary" onClick={openInvite} data-testid="staff-invite"><Icon name="plus" size={18} /> {t('dash.staff.invite', undefined, locale)}</button> : undefined} />
      <Card>
        {state === 'loading' && <Skeleton rows={5} />}
        {state === 'error' && <ErrorState title={t('dash.error.loadTitle', undefined, locale)} onRetry={() => { void load() }} locale={locale} />}
        {state === 'ready' && list.length === 0 && <EmptyState icon="staff" title={t('dash.staff.emptyTitle', undefined, locale)} text={t('dash.staff.emptyText', undefined, locale)} />}
        {state === 'ready' && list.length > 0 && (
          <div className="db-table-wrap"><table className="db-table" data-testid="staff-table">
            <thead><tr><th scope="col">{t('dash.staff.col.name', undefined, locale)}</th><th scope="col">{t('dash.staff.col.role', undefined, locale)}</th><th scope="col">{t('dash.staff.col.access', undefined, locale)}</th><th scope="col">{t('dash.staff.col.status', undefined, locale)}</th><th scope="col"><span className="db-sr-only">{t('dash.orders.col.actions', undefined, locale)}</span></th></tr></thead>
            <tbody>{list.map((m) => <tr key={m.id} data-testid="staff-row"><td><div style={{ display: 'flex', gap: 10, alignItems: 'center' }}><Avatar name={m.name} size={34} src={m.avatar} /><span><b dir="auto">{m.name}</b>{m.isSelf && <> <Pill tone="blue">{t('dash.staff.you', undefined, locale)}</Pill></>}<br /><small className="db-muted">{m.email}</small></span></div></td><td><Pill tone={m.role === 'owner' ? 'orange' : m.role === 'manager' ? 'blue' : 'muted'}>{t(`dash.role.${m.role}`, undefined, locale)}</Pill></td><td dir="auto">{m.locationAccess === 'all' ? t('dash.staff.allLocations', undefined, locale) : m.locationAccess.map(locName).join(', ')}</td><td><Pill tone={m.status === 'active' ? 'green' : m.status === 'invited' ? 'amber' : 'red'}>{t(`dash.staff.status.${m.status}`, undefined, locale)}</Pill></td><td><div className="db-table__actions">{canManage && !m.isSelf && <><button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => openEdit(m)} data-testid="staff-edit">{t('dash.action.edit', undefined, locale)}</button>{m.status === 'invited' && d.repos.staff.resendInvitation && <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void act(() => d.repos.staff.resendInvitation!(m.id, here), t('dash.staff.resent', undefined, locale)) }} data-testid="staff-resend">{t('dash.staff.resend', undefined, locale)}</button>}{(d.live || m.role !== 'owner') && m.status !== 'invited' && (m.status === 'suspended' ? <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void act(() => d.repos.staff.update(m.id, { status: 'active' }, here)) }} data-testid="staff-reactivate">{t('dash.staff.reactivate', undefined, locale)}</button> : <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => { void act(() => d.repos.staff.update(m.id, { status: 'suspended' }, here)) }} data-testid="staff-suspend">{t('dash.staff.suspend', undefined, locale)}</button>)}{(d.live || m.role !== 'owner') && <button type="button" className="db-btn db-btn--danger db-btn--sm" onClick={() => setRemove(m)} data-testid="staff-remove">{t('dash.action.remove', undefined, locale)}</button>}</>}</div></td></tr>)}</tbody>
          </table></div>
        )}
      </Card>
      <Card title={t('dash.staff.permissions', undefined, locale)} subtitle={t('dash.staff.permissionsSub', undefined, locale)}>
        <div className="db-table-wrap"><table className="db-table" data-testid="perm-table"><thead><tr><th scope="col">{t('dash.staff.permission', undefined, locale)}</th>{roles.map((r) => <th key={r.id} scope="col">{t(`dash.role.${r.id}`, undefined, locale)}</th>)}</tr></thead>
          <tbody>{allPerms.map((p) => <tr key={p}><td className="db-table__num" style={{ fontSize: '0.8rem' }}>{p}</td>{roles.map((r) => <td key={r.id}>{r.permissions.includes(p) ? <span className="db-badge db-badge--green"><Icon name="check" size={12} /><span className="db-sr-only">{t('dash.staff.granted', undefined, locale)}</span></span> : <span className="db-muted" aria-label={t('dash.staff.notGranted', undefined, locale)}>—</span>}</td>)}</tr>)}</tbody></table></div>
        <p className="db-muted" style={{ fontSize: '0.8rem', marginBottom: 0 }}>{t('dash.staff.rbacNote', undefined, locale)}</p>
      </Card>
      <Drawer open={drawer.open} onClose={() => setDrawer({ open: false, member: null })} title={drawer.member ? t('dash.staff.editTitle', undefined, locale) : t('dash.staff.invite', undefined, locale)} footer={<><button type="button" className="db-btn db-btn--ghost" onClick={() => setDrawer({ open: false, member: null })}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" onClick={() => { void save() }} data-testid="staff-save">{drawer.member ? t('dash.action.save', undefined, locale) : t('dash.staff.sendInvite', undefined, locale)}</button></>}>
        {errors.form && <p className="db-field__error" role="alert">{errors.form}</p>}
        {!d.live && <ImageUpload kind="avatar" value={form.avatar} onChange={(u) => setForm({ ...form, avatar: u })} label={t('dash.staff.photo', undefined, locale)} hint={t('dash.staff.photoHint', undefined, locale)} shape="round" compact testId="upload-avatar" />}
        <Field label={t('dash.staff.col.name', undefined, locale)} required error={errors.name} id="st-name"><input id="st-name" className="db-input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} disabled={!!drawer.member} dir="auto" data-testid="staff-name" /></Field>
        <Field label={t('dash.staff.email', undefined, locale)} required error={errors.email} id="st-email"><input id="st-email" className="db-input" inputMode="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} disabled={!!drawer.member} data-testid="staff-email" /></Field>
        <Field label={t('dash.staff.col.role', undefined, locale)} required id="st-role"><select id="st-role" className="db-select" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as RoleId })} data-testid="staff-role">{roles.map((r) => <option key={r.id} value={r.id}>{t(`dash.role.${r.id}`, undefined, locale)}</option>)}</select></Field>
        <div className="db-tags" aria-label={t('dash.staff.permissionsFor', undefined, locale)}>{perms.map((p) => <Pill key={p}>{p}</Pill>)}</div>
        <Field label={t('dash.staff.col.access', undefined, locale)} error={errors.access} id="st-access">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }} id="st-access">
            <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="radio" name="access" checked={form.access === 'all'} onChange={() => setForm({ ...form, access: 'all' })} /> {t('dash.staff.allLocations', undefined, locale)}</label>
            <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="radio" name="access" checked={form.access !== 'all'} onChange={() => setForm({ ...form, access: [] })} /> {t('dash.staff.someLocations', undefined, locale)}</label>
            {form.access !== 'all' && <div style={{ paddingInlineStart: 24, display: 'flex', flexDirection: 'column', gap: 4 }}>{d.locations.map((l) => <label key={l.restaurant.id} style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="checkbox" checked={form.access.includes(l.restaurant.id)} onChange={(e) => setForm({ ...form, access: e.target.checked ? [...(form.access as string[]), l.restaurant.id] : (form.access as string[]).filter((x) => x !== l.restaurant.id) })} /> <span dir="auto">{l.restaurant.name} · {l.profile.locationName}</span></label>)}</div>}
          </div>
        </Field>
        <p className="db-muted" style={{ margin: 0, fontSize: '0.8rem' }}>{t(d.live ? 'dash.staff.inviteNoteLive' : 'dash.staff.inviteNote', undefined, locale)}</p>
      </Drawer>
      <ConfirmDialog open={!!remove} title={t('dash.staff.removeTitle', { name: remove?.name ?? '' }, locale)} text={t('dash.staff.removeText', undefined, locale)} confirmLabel={t('dash.action.remove', undefined, locale)} cancelLabel={t('dash.action.cancel', undefined, locale)} danger onCancel={() => setRemove(null)} onConfirm={async () => { if (remove) { const id = remove.id; setRemove(null); await act(() => d.repos.staff.remove(id, here)) } }} />
      <ToastLine msg={msg} />
    </div>
  )
}

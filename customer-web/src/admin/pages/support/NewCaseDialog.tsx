import { useMemo, useRef, useState } from 'react'
import { t } from '../../../i18n/strings'
import { describeSupportError, newSupportClientId, SUPPORT_LINK_TYPES, type ApiAdminSupportRepository, type SupportCaseDetail, type SupportCatalog } from '../../api/ApiAdminSupportRepository'
import { DeskDialog, DeskField, deskLabel } from './desk'

/**
 * "New internal case" (Module 37): an administrator opens a case for internal work or for something reported another
 * way. It has no requester — nobody outside the service desk reads it — and the form has no field for one, nor for a
 * status, a priority, a team or an author: the backend derives them. The categories are the catalog's `admin_categories`.
 */
export function NewCaseDialog({ repo, catalog, markets, locale, onCreated, onCancel }: {
  repo: ApiAdminSupportRepository; catalog: SupportCatalog | null; markets: Array<{ slug: string; name: string }>; locale: string
  onCreated: (c: SupportCaseDetail) => void; onCancel: () => void
}) {
  const categories = useMemo(() => catalog?.admin_categories ?? [], [catalog])
  const [category, setCategory] = useState(''); const [subcategory, setSubcategory] = useState('')
  const [subject, setSubject] = useState(''); const [note, setNote] = useState(''); const [market, setMarket] = useState('')
  const [linkType, setLinkType] = useState(''); const [reference, setReference] = useState('')
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null)
  // One key per opened dialog: a double click, or a retry after a lost answer, is one case.
  const key = useRef(`support-case-${newSupportClientId()}`)
  const chosen = category || categories[0]?.code || ''
  const subcategories = categories.find((c) => c.code === chosen)?.subcategories ?? []
  const linkTypes = catalog?.link_types ?? [...SUPPORT_LINK_TYPES]
  const limits = catalog?.limits ?? { subject_max: 140, message_max: 4000, note_max: 4000 }

  const submit = async () => {
    if (!chosen) { setError(t('adm.desk.new.needCategory', undefined, locale)); return }
    if (subject.trim().length < 3 || note.trim().length < 3) { setError(t('adm.desk.new.needText', undefined, locale)); return }
    if ((linkType === '') !== (reference.trim() === '')) { setError(t('adm.desk.new.needLink', undefined, locale)); return }
    setBusy(true); setError(null)
    try {
      onCreated(await repo.create({ category: chosen, subcategory: subcategory || null, subject, note, market: market || null, links: linkType ? [{ type: linkType, reference }] : [] }, key.current))
    } catch (e) { setError(describeSupportError(e).message) } finally { setBusy(false) }
  }

  return (
    <DeskDialog action="new" title={t('adm.desk.new.title', undefined, locale)} text={t('adm.desk.new.text', undefined, locale)} submitLabel={t('adm.desk.new.submit', undefined, locale)} onSubmit={submit} onCancel={onCancel} busy={busy} error={error} locale={locale}>
      <DeskField label={t('adm.desk.field.category', undefined, locale)}>{(id) => (
        <select id={id} className="db-select" value={chosen} onChange={(e) => { setCategory(e.target.value); setSubcategory('') }} data-testid="desk-field-category">
          {categories.map((c) => <option key={c.code} value={c.code}>{deskLabel('category', c.code, locale)}</option>)}
        </select>
      )}</DeskField>
      {subcategories.length > 0 && <DeskField label={t('adm.desk.field.subcategory', undefined, locale)}>{(id) => (
        <select id={id} className="db-select" value={subcategory} onChange={(e) => setSubcategory(e.target.value)} data-testid="desk-field-subcategory">
          <option value="">{t('adm.desk.none', undefined, locale)}</option>
          {subcategories.map((x) => <option key={x} value={x}>{deskLabel('subcategory', x, locale)}</option>)}
        </select>
      )}</DeskField>}
      <DeskField label={t('adm.desk.field.subject', undefined, locale)}>{(id) => <input id={id} className="db-input" value={subject} maxLength={limits.subject_max} onChange={(e) => setSubject(e.target.value)} data-testid="desk-field-subject" />}</DeskField>
      <DeskField label={t('adm.desk.field.firstNote', undefined, locale)} hint={t('adm.desk.new.noteHint', undefined, locale)}>{(id) => <textarea id={id} className="db-textarea" rows={4} value={note} maxLength={limits.note_max} onChange={(e) => setNote(e.target.value)} data-testid="desk-field-note" />}</DeskField>
      {markets.length > 0 && <DeskField label={t('adm.desk.field.market', undefined, locale)}>{(id) => (
        <select id={id} className="db-select" value={market} onChange={(e) => setMarket(e.target.value)} data-testid="desk-field-market">
          <option value="">{t('adm.desk.new.noMarket', undefined, locale)}</option>
          {markets.map((m) => <option key={m.slug} value={m.slug}>{m.name}</option>)}
        </select>
      )}</DeskField>}
      <div className="db-form-row">
        <DeskField label={t('adm.desk.field.linkType', undefined, locale)}>{(id) => (
          <select id={id} className="db-select" value={linkType} onChange={(e) => setLinkType(e.target.value)} data-testid="desk-field-link-type">
            <option value="">{t('adm.desk.new.noLink', undefined, locale)}</option>
            {linkTypes.map((x) => <option key={x} value={x}>{deskLabel('link', x, locale)}</option>)}
          </select>
        )}</DeskField>
        <DeskField label={t('adm.desk.field.linkReference', undefined, locale)}>{(id) => <input id={id} className="db-input" value={reference} maxLength={80} onChange={(e) => setReference(e.target.value)} data-testid="desk-field-link-reference" />}</DeskField>
      </div>
    </DeskDialog>
  )
}

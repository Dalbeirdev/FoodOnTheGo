import { useRef, useState } from 'react'
import { t } from '../../../i18n/strings'
import { describeSupportError, newSupportClientId, type ApiAdminSupportRepository, type SupportCaseDetail, type SupportMessage, type SupportNote, type SupportProblem } from '../../api/ApiAdminSupportRepository'

/**
 * The composer of a case (Module 37). It writes to ONE of two places and always says which:
 *
 *   INTERNAL NOTE (the default — the safe one): administrators only, another endpoint and another table.
 *   REPLY TO REQUESTER (EXTERNAL): the customer or the restaurant reads it.
 *
 * Nothing goes to the requester without an explicit switch: the mode starts as INTERNAL, returns to INTERNAL after
 * every message that was sent, and the EXTERNAL mode shows "The requester will read this." above the box, changes the
 * button's label and colour — and does not exist at all for a case that has no requester (an administrator's or the
 * system's case) or that the backend says cannot be answered. The request names its visibility; the backend refuses a
 * mismatch.
 */
export type ComposerMode = 'INTERNAL' | 'EXTERNAL'

export function Composer({ c, repo, limits, locale, onReplied, onNoted, onStale }: {
  c: SupportCaseDetail; repo: ApiAdminSupportRepository; limits: { message_max: number; note_max: number }; locale: string
  onReplied: (r: { message: SupportMessage; case: SupportCaseDetail }) => void; onNoted: (n: SupportNote) => void; onStale: (p: SupportProblem) => void
}) {
  const hasRequester = c.requester.type === 'CUSTOMER' || c.requester.type === 'RESTAURANT'
  const external = c.can.reply && hasRequester
  const internal = c.can.notes
  const [chosen, setChosen] = useState<ComposerMode>('INTERNAL')
  const [text, setText] = useState(''); const [thenWait, setThenWait] = useState(false)
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null)
  // The same text sent again (a retry after a lost answer) is the same message for the backend.
  const clientId = useRef(newSupportClientId())
  // A case that stopped accepting replies while the composer was open falls back to the internal mode.
  const mode: ComposerMode = chosen === 'EXTERNAL' && external ? 'EXTERNAL' : 'INTERNAL'
  const writable = mode === 'EXTERNAL' || internal

  if (!external && !internal) return <p className="db-muted" data-testid="desk-composer-none">{t(hasRequester ? 'adm.desk.composer.none' : 'adm.desk.composer.noneNoRequester', undefined, locale)}</p>

  const send = async () => {
    const body = text.trim()
    if (!body || busy || !writable) return
    setBusy(true); setError(null)
    try {
      if (mode === 'EXTERNAL') {
        const then = thenWait ? (c.requester.type === 'CUSTOMER' ? 'WAITING_FOR_CUSTOMER' as const : 'WAITING_FOR_RESTAURANT' as const) : null
        onReplied(await repo.reply(c.id, { message: body, then, clientMessageId: clientId.current, expectedVersion: c.version }))
      } else {
        onNoted(await repo.note(c.id, { note: body, clientNoteId: clientId.current }))
      }
      setText(''); setThenWait(false); setChosen('INTERNAL'); clientId.current = newSupportClientId()
    } catch (e) {
      const p = describeSupportError(e)
      setError(p.message)
      if (p.reload) onStale(p)
    } finally { setBusy(false) }
  }

  return (
    <form className={`adm-desk-composer adm-desk-composer--${mode.toLowerCase()}`} onSubmit={(e) => { e.preventDefault(); void send() }} data-testid="desk-composer" data-mode={mode}>
      <div className="adm-desk-modes" role="radiogroup" aria-label={t('adm.desk.composer.mode', undefined, locale)}>
        <button type="button" role="radio" aria-checked={mode === 'INTERNAL'} className={`adm-desk-mode adm-desk-mode--internal ${mode === 'INTERNAL' ? 'is-on' : ''}`} onClick={() => setChosen('INTERNAL')} data-testid="desk-mode-internal">{t('adm.desk.composer.internal', undefined, locale)}</button>
        {external && <button type="button" role="radio" aria-checked={mode === 'EXTERNAL'} className={`adm-desk-mode adm-desk-mode--external ${mode === 'EXTERNAL' ? 'is-on' : ''}`} onClick={() => setChosen('EXTERNAL')} data-testid="desk-mode-external">{t('adm.desk.composer.external', undefined, locale)}</button>}
      </div>
      {!external && <p className="db-muted adm-small" data-testid="desk-external-unavailable">{t(hasRequester ? 'adm.desk.composer.noReply' : 'adm.desk.composer.noRequester', undefined, locale)}</p>}
      {mode === 'EXTERNAL'
        ? <p className="adm-desk-warning" role="note" data-testid="desk-external-warning">{t('adm.desk.composer.externalWarning', undefined, locale)}</p>
        : <p className="adm-desk-internal-label" data-testid="desk-internal-hint">{t('adm.desk.composer.internalHint', undefined, locale)}</p>}
      {mode === 'INTERNAL' && !internal && <p className="db-field__error" role="alert" data-testid="desk-composer-notes-forbidden">{t('adm.desk.composer.notesForbidden', undefined, locale)}</p>}
      <label className="db-sr-only" htmlFor="desk-composer-input">{t(mode === 'EXTERNAL' ? 'adm.desk.composer.external' : 'adm.desk.composer.internal', undefined, locale)}</label>
      <textarea id="desk-composer-input" className="db-textarea" rows={4} value={text} maxLength={mode === 'EXTERNAL' ? limits.message_max : limits.note_max} onChange={(e) => setText(e.target.value)} disabled={!writable || busy} dir="auto"
        placeholder={t(mode === 'EXTERNAL' ? 'adm.desk.composer.externalPlaceholder' : 'adm.desk.composer.internalPlaceholder', undefined, locale)} data-testid="desk-composer-input" />
      {mode === 'EXTERNAL' && <label className="db-check"><input type="checkbox" checked={thenWait} onChange={(e) => setThenWait(e.target.checked)} disabled={busy} data-testid="desk-then-wait" /> <span>{t('adm.desk.composer.thenWait', undefined, locale)}</span></label>}
      {error && <p className="db-field__error" role="alert" data-testid="desk-composer-error">{error}</p>}
      <div className="adm-desk-composer__foot">
        <button type="submit" className={`db-btn adm-desk-send adm-desk-send--${mode.toLowerCase()}`} disabled={busy || !writable || text.trim() === ''} data-testid="desk-composer-send" data-mode={mode}>
          {busy ? t('dash.action.saving', undefined, locale) : t(mode === 'EXTERNAL' ? 'adm.desk.composer.sendExternal' : 'adm.desk.composer.saveInternal', undefined, locale)}
        </button>
      </div>
    </form>
  )
}

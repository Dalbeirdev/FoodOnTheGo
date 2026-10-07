/**
 * Tax rules and fee rules of one market (Module 29) — shown on the market's Configuration › Tax tab when the admin
 * runs against the backend.
 *
 * Everything here is the backend's: GET /admin/markets/{id}/financial-rules, and the changes an administrator with
 * market_configuration.manage may make. Nothing is configured until somebody configures it — an empty list means
 * customers of the market are charged no tax and no fee. A rule is a TEST configuration until it is marked APPROVED
 * (TEST rules never apply in production), is a DRAFT until it is activated, and is frozen once active: to change a
 * rate the rule is ended and a new one created. Every change needs a reason and is audited by the backend.
 *
 * Rates are entered in basis points and amounts in minor units — integers, exactly as they are stored. This screen
 * calculates nothing and knows no official rate of any country.
 */
import { useState } from 'react'
import { api, ApiError } from '../../api/client'
import { Card, Drawer, ErrorState, Field, Skeleton, ToastLine, useToastMessage } from '../../dashboard/components/ui'
import { formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { Badge, ReasonDialog } from '../components/DataTable'
import { fmtDate, useLoad } from './shared'

type RuleCommon = { id: string; status: 'DRAFT' | 'ACTIVE' | 'DISABLED'; approval: 'TEST' | 'APPROVED'; in_force: boolean; effective_from: string; effective_until: string | null; location: { id: string; name: string } | null; version: number }
export type TaxRuleDto = RuleCommon & { name: string; applies_to: 'MERCHANDISE' | 'FEES'; price_treatment: 'EXCLUSIVE' | 'INCLUSIVE'; discount_treatment: 'AFTER_DISCOUNT' | 'BEFORE_DISCOUNT'; components: Array<{ code: string; label: string; rate_basis_points: number }> }
export type FeeRuleDto = RuleCommon & { code: string; label: string; type: 'FIXED' | 'PERCENTAGE'; currency: string | null; amount_minor: number | null; percent_basis_points: number | null; percent_of: 'SUBTOTAL' | 'DISCOUNTED_SUBTOTAL'; minimum_minor: number | null; maximum_minor: number | null; pickup_method: string | null; taxable: boolean }
export type FinancialRulesDto = { market: { id: string; name: string; default_currency: string }; tax_rules: TaxRuleDto[]; fee_rules: FeeRuleDto[]; test_rules_applied: boolean; configured: { tax: boolean; fees: boolean } }

const A = { context: 'admin' as const }
/** Basis points as text (250 → "2.5%"), by integer arithmetic. */
export const rate = (bps: number) => { const whole = Math.floor(bps / 100); const rest = bps % 100; return `${rest === 0 ? whole : `${whole}.${String(rest).padStart(2, '0').replace(/0$/, '')}`}%` }
const integer = (s: string) => (/^\d+$/.test(s.trim()) ? Number(s.trim()) : null)
type TaxForm = { name: string; appliesTo: TaxRuleDto['applies_to']; price: TaxRuleDto['price_treatment']; discount: TaxRuleDto['discount_treatment']; approval: RuleCommon['approval']; from: string; components: Array<{ code: string; label: string; bps: string }>; reason: string }
type FeeForm = { code: string; label: string; type: FeeRuleDto['type']; amount: string; bps: string; percentOf: FeeRuleDto['percent_of']; taxable: boolean; approval: RuleCommon['approval']; from: string; reason: string }
type Target = { kind: 'tax-rules' | 'fee-rules'; rule: RuleCommon; label: string; action: 'ACTIVE' | 'DISABLED' | 'END' }
const today = () => new Date().toISOString().slice(0, 10)
const blankTax = (): TaxForm => ({ name: 'TEST TAX CONFIGURATION', appliesTo: 'MERCHANDISE', price: 'EXCLUSIVE', discount: 'AFTER_DISCOUNT', approval: 'TEST', from: today(), components: [{ code: '', label: '', bps: '' }], reason: '' })
const blankFee = (): FeeForm => ({ code: '', label: '', type: 'FIXED', amount: '', bps: '', percentOf: 'SUBTOTAL', taxable: false, approval: 'TEST', from: today(), reason: '' })

export function FinancialRulesPanel({ marketId, canManage, locale, load }: { marketId: string; canManage: boolean; locale: string; load?: () => Promise<FinancialRulesDto> }) {
  const fetchRules = load ?? (() => api<FinancialRulesDto>(`/admin/markets/${marketId}/financial-rules`, A))
  const { data, state, reload, refresh } = useLoad(fetchRules, [marketId])
  const [tax, setTax] = useState<TaxForm | null>(null)
  const [fee, setFee] = useState<FeeForm | null>(null)
  const [target, setTarget] = useState<Target | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const { msg, toast } = useToastMessage()
  const words = (key: string, vars?: Record<string, string | number>) => t(`adm.fin.${key}`, vars, locale)
  const say = (e: unknown) => setError(e instanceof ApiError ? e.field(Object.keys(e.errors)[0] ?? '') ?? e.message : e instanceof Error ? e.message : String(e))
  const done = async () => { setTax(null); setFee(null); setTarget(null); setError(null); await refresh(); toast(t('adm.updated', undefined, locale)) }
  const run = async (work: () => Promise<unknown>) => { setBusy(true); setError(null); try { await work(); await done() } catch (e) { say(e) } finally { setBusy(false) } }

  const saveTax = () => {
    if (!tax) return
    const components = tax.components.filter((c) => c.code.trim() !== '' || c.label.trim() !== '' || c.bps.trim() !== '')
    if (tax.reason.trim().length < 3 || tax.name.trim().length < 2 || components.length === 0 || components.some((c) => integer(c.bps) === null || c.code.trim() === '' || c.label.trim() === '')) { setError(words('err.incomplete')); return }
    void run(() => api(`/admin/markets/${marketId}/tax-rules`, { ...A, method: 'POST', body: {
      name: tax.name.trim(), applies_to: tax.appliesTo, price_treatment: tax.price, discount_treatment: tax.discount, approval: tax.approval, effective_from: `${tax.from}T00:00:00Z`,
      components: components.map((c) => ({ code: c.code.trim().toUpperCase(), label: c.label.trim(), rate_basis_points: integer(c.bps) })), reason: tax.reason.trim(),
    } }))
  }
  const saveFee = () => {
    if (!fee || !data) return
    const fixed = fee.type === 'FIXED'
    if (fee.reason.trim().length < 3 || fee.label.trim() === '' || integer(fixed ? fee.amount : fee.bps) === null) { setError(words('err.incomplete')); return }
    void run(() => api(`/admin/markets/${marketId}/fee-rules`, { ...A, method: 'POST', body: {
      code: fee.code.trim().toUpperCase(), label: fee.label.trim(), type: fee.type, approval: fee.approval, taxable: fee.taxable, effective_from: `${fee.from}T00:00:00Z`, reason: fee.reason.trim(),
      ...(fixed ? { currency: data.market.default_currency, amount_minor: integer(fee.amount) } : { percent_basis_points: integer(fee.bps), percent_of: fee.percentOf }),
    } }))
  }
  const apply = (reason: string) => {
    if (!target) return Promise.resolve()
    const body = target.action === 'END' ? { effective_until: new Date(Date.now() + 60_000).toISOString(), version: target.rule.version, reason } : { status: target.action, version: target.rule.version, reason }
    return run(() => api(`/admin/${target.kind}/${target.rule.id}${target.action === 'END' ? '' : '/status'}`, { ...A, method: target.action === 'END' ? 'PATCH' : 'POST', body }))
  }
  const actions = (kind: Target['kind'], rule: RuleCommon, label: string) => canManage && rule.status !== 'DISABLED' && (
    <span className="db-row-actions">
      {rule.status === 'DRAFT' && <button type="button" className="db-btn db-btn--sm db-btn--outline" data-testid={`fin-activate-${kind}`} onClick={() => { setError(null); setTarget({ kind, rule, label, action: 'ACTIVE' }) }}>{words('activate')}</button>}
      {rule.status === 'ACTIVE' && rule.effective_until === null && <button type="button" className="db-btn db-btn--sm db-btn--outline" data-testid={`fin-end-${kind}`} onClick={() => { setError(null); setTarget({ kind, rule, label, action: 'END' }) }}>{words('end')}</button>}
      <button type="button" className="db-btn db-btn--sm db-btn--danger" data-testid={`fin-disable-${kind}`} onClick={() => { setError(null); setTarget({ kind, rule, label, action: 'DISABLED' }) }}>{words('disable')}</button>
    </span>
  )
  const state2 = (rule: RuleCommon) => (
    <span className="db-tags">
      <Badge tone={rule.status === 'ACTIVE' ? (rule.in_force ? 'green' : 'muted') : rule.status === 'DRAFT' ? 'amber' : 'muted'}>{words(`status.${rule.status}`)}{rule.status === 'ACTIVE' && !rule.in_force ? ` · ${words('notInForce')}` : ''}</Badge>
      <Badge tone={rule.approval === 'APPROVED' ? 'green' : 'amber'}>{words(`approval.${rule.approval}`)}</Badge>
    </span>
  )
  const window = (rule: RuleCommon) => `${fmtDate(rule.effective_from, locale)} – ${rule.effective_until ? fmtDate(rule.effective_until, locale) : words('open')}`
  const preview = (minor: string, currency: string) => { const n = integer(minor); return n === null ? '' : formatMoney(n, currency, locale) }

  return (
    <div className="db-grid" style={{ gap: 16 }} data-testid="financial-rules">
      {state === 'loading' && <Card><Skeleton rows={3} /></Card>}
      {state === 'error' && <Card><ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></Card>}
      {state === 'ready' && data && <>
        <Card title={words('tax.title')} subtitle={words('tax.subtitle')} actions={<>
          <Badge tone={data.configured.tax ? 'green' : 'amber'}>{words(data.configured.tax ? 'configured' : 'notConfigured')}</Badge>
          {canManage && <button type="button" className="db-btn db-btn--primary db-btn--sm" data-testid="fin-tax-new" onClick={() => { setError(null); setTax(blankTax()) }}>{words('tax.new')}</button>}
        </>}>
          {data.tax_rules.length === 0 ? <p className="db-muted" data-testid="fin-tax-empty">{words('tax.empty')}</p> : (
            <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="fin-tax-table"><caption className="db-sr-only">{words('tax.title')}</caption>
              <thead><tr><th scope="col">{words('col.rule')}</th><th scope="col">{words('col.rates')}</th><th scope="col">{words('col.applies')}</th><th scope="col">{words('col.window')}</th><th scope="col">{words('col.state')}</th><th scope="col"><span className="db-sr-only">{t('adm.col.actions', undefined, locale)}</span></th></tr></thead>
              <tbody>{data.tax_rules.map((r) => (
                <tr key={r.id} data-testid="fin-tax-row" data-status={r.status} data-approval={r.approval} data-in-force={r.in_force ? 'true' : 'false'}>
                  <td className="adm-td--primary">{r.name}{r.location && <small className="db-muted"> · {r.location.name}</small>}</td>
                  <td>{r.components.map((c) => `${c.label} ${rate(c.rate_basis_points)}`).join(' + ')}</td>
                  <td>{words(`applies.${r.applies_to}`)} · {words(`price.${r.price_treatment}`)} · {words(`discount.${r.discount_treatment}`)}</td>
                  <td>{window(r)}</td><td>{state2(r)}</td><td>{actions('tax-rules', r, r.name)}</td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
        </Card>
        <Card title={words('fee.title')} subtitle={words('fee.subtitle')} actions={<>
          <Badge tone={data.configured.fees ? 'green' : 'muted'}>{words(data.configured.fees ? 'configured' : 'notConfigured')}</Badge>
          {canManage && <button type="button" className="db-btn db-btn--primary db-btn--sm" data-testid="fin-fee-new" onClick={() => { setError(null); setFee(blankFee()) }}>{words('fee.new')}</button>}
        </>}>
          {data.fee_rules.length === 0 ? <p className="db-muted" data-testid="fin-fee-empty">{words('fee.empty')}</p> : (
            <div className="db-table-wrap" tabIndex={0}><table className="db-table" data-testid="fin-fee-table"><caption className="db-sr-only">{words('fee.title')}</caption>
              <thead><tr><th scope="col">{words('col.rule')}</th><th scope="col">{words('col.value')}</th><th scope="col">{words('col.window')}</th><th scope="col">{words('col.state')}</th><th scope="col"><span className="db-sr-only">{t('adm.col.actions', undefined, locale)}</span></th></tr></thead>
              <tbody>{data.fee_rules.map((r) => (
                <tr key={r.id} data-testid="fin-fee-row" data-status={r.status} data-approval={r.approval} data-code={r.code}>
                  <td className="adm-td--primary"><span className="adm-code">{r.code}</span> {r.label}{r.location && <small className="db-muted"> · {r.location.name}</small>}</td>
                  <td>{r.type === 'FIXED' ? formatMoney(r.amount_minor ?? 0, r.currency ?? data.market.default_currency, locale) : `${rate(r.percent_basis_points ?? 0)} ${words(`of.${r.percent_of}`)}`}{r.taxable ? ` · ${words('taxable')}` : ''}</td>
                  <td>{window(r)}</td><td>{state2(r)}</td><td>{actions('fee-rules', r, r.code)}</td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
        </Card>
        <p className="db-muted" data-testid="fin-note">{words(data.test_rules_applied ? 'note.testApplied' : 'note.testNotApplied')} {words('note.noInvention')}</p>
      </>}

      <Drawer open={!!tax} onClose={() => setTax(null)} title={words('tax.new')} wide footer={<><button type="button" className="db-btn db-btn--ghost" onClick={() => setTax(null)}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" disabled={busy} data-testid="fin-tax-save" onClick={saveTax}>{busy ? t('dash.action.saving', undefined, locale) : t('adm.action.save', undefined, locale)}</button></>}>
        {tax && <form className="db-grid" style={{ gap: 12 }} data-testid="fin-tax-form" onSubmit={(e) => { e.preventDefault(); saveTax() }}>
          <Field label={words('f.name')} required id="ft-name"><input id="ft-name" className="db-input" value={tax.name} maxLength={120} onChange={(e) => setTax({ ...tax, name: e.target.value })} /></Field>
          <div className="db-form-row">
            <Field label={words('f.applies')} id="ft-applies"><select id="ft-applies" className="db-select" value={tax.appliesTo} onChange={(e) => setTax({ ...tax, appliesTo: e.target.value as TaxForm['appliesTo'] })}>{(['MERCHANDISE', 'FEES'] as const).map((v) => <option key={v} value={v}>{words(`applies.${v}`)}</option>)}</select></Field>
            <Field label={words('f.price')} id="ft-price"><select id="ft-price" className="db-select" value={tax.price} onChange={(e) => setTax({ ...tax, price: e.target.value as TaxForm['price'] })}>{(['EXCLUSIVE', 'INCLUSIVE'] as const).map((v) => <option key={v} value={v}>{words(`price.${v}`)}</option>)}</select></Field>
          </div>
          <div className="db-form-row">
            <Field label={words('f.discount')} id="ft-disc"><select id="ft-disc" className="db-select" value={tax.discount} onChange={(e) => setTax({ ...tax, discount: e.target.value as TaxForm['discount'] })}>{(['AFTER_DISCOUNT', 'BEFORE_DISCOUNT'] as const).map((v) => <option key={v} value={v}>{words(`discount.${v}`)}</option>)}</select></Field>
            <Field label={words('f.approval')} hint={words('f.approvalHint')} id="ft-appr"><select id="ft-appr" className="db-select" value={tax.approval} onChange={(e) => setTax({ ...tax, approval: e.target.value as TaxForm['approval'] })}>{(['TEST', 'APPROVED'] as const).map((v) => <option key={v} value={v}>{words(`approval.${v}`)}</option>)}</select></Field>
          </div>
          <Field label={words('f.from')} required id="ft-from"><input id="ft-from" type="date" className="db-input" value={tax.from} onChange={(e) => setTax({ ...tax, from: e.target.value })} /></Field>
          {tax.components.map((c, i) => (
            <div className="db-form-row" key={i}>
              <Field label={words('f.componentCode')} required id={`ft-c-code-${i}`}><input id={`ft-c-code-${i}`} className="db-input" value={c.code} maxLength={24} onChange={(e) => setTax({ ...tax, components: tax.components.map((x, j) => (j === i ? { ...x, code: e.target.value.toUpperCase() } : x)) })} /></Field>
              <Field label={words('f.componentLabel')} required id={`ft-c-label-${i}`}><input id={`ft-c-label-${i}`} className="db-input" value={c.label} maxLength={60} onChange={(e) => setTax({ ...tax, components: tax.components.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)) })} /></Field>
              <Field label={words('f.bps')} hint={integer(c.bps) === null ? words('f.bpsHint') : rate(integer(c.bps) ?? 0)} required id={`ft-c-bps-${i}`}><input id={`ft-c-bps-${i}`} className="db-input" inputMode="numeric" value={c.bps} onChange={(e) => setTax({ ...tax, components: tax.components.map((x, j) => (j === i ? { ...x, bps: e.target.value } : x)) })} /></Field>
            </div>
          ))}
          {tax.components.length < 4 && <button type="button" className="db-btn db-btn--ghost db-btn--sm" data-testid="fin-tax-add-component" onClick={() => setTax({ ...tax, components: [...tax.components, { code: '', label: '', bps: '' }] })}>{words('f.addComponent')}</button>}
          <Field label={words('f.reason')} required id="ft-reason"><input id="ft-reason" className="db-input" value={tax.reason} maxLength={500} onChange={(e) => setTax({ ...tax, reason: e.target.value })} /></Field>
          {error && <p className="db-field__error" role="alert" data-testid="fin-error">{error}</p>}
        </form>}
      </Drawer>

      <Drawer open={!!fee} onClose={() => setFee(null)} title={words('fee.new')} wide footer={<><button type="button" className="db-btn db-btn--ghost" onClick={() => setFee(null)}>{t('dash.action.cancel', undefined, locale)}</button><button type="button" className="db-btn db-btn--primary" disabled={busy} data-testid="fin-fee-save" onClick={saveFee}>{busy ? t('dash.action.saving', undefined, locale) : t('adm.action.save', undefined, locale)}</button></>}>
        {fee && data && <form className="db-grid" style={{ gap: 12 }} data-testid="fin-fee-form" onSubmit={(e) => { e.preventDefault(); saveFee() }}>
          <div className="db-form-row">
            <Field label={words('f.feeCode')} hint={words('f.feeCodeHint')} required id="ff-code"><input id="ff-code" className="db-input" value={fee.code} maxLength={24} onChange={(e) => setFee({ ...fee, code: e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_') })} /></Field>
            <Field label={words('f.feeLabel')} required id="ff-label"><input id="ff-label" className="db-input" value={fee.label} maxLength={60} onChange={(e) => setFee({ ...fee, label: e.target.value })} /></Field>
          </div>
          <div className="db-form-row">
            <Field label={words('f.type')} id="ff-type"><select id="ff-type" className="db-select" value={fee.type} onChange={(e) => setFee({ ...fee, type: e.target.value as FeeForm['type'] })}>{(['FIXED', 'PERCENTAGE'] as const).map((v) => <option key={v} value={v}>{words(`type.${v}`)}</option>)}</select></Field>
            {fee.type === 'FIXED'
              ? <Field label={words('f.amountMinor', { currency: data.market.default_currency })} hint={preview(fee.amount, data.market.default_currency) || words('f.amountHint')} required id="ff-amount"><input id="ff-amount" className="db-input" inputMode="numeric" value={fee.amount} onChange={(e) => setFee({ ...fee, amount: e.target.value })} /></Field>
              : <Field label={words('f.bps')} hint={integer(fee.bps) === null ? words('f.bpsHint') : rate(integer(fee.bps) ?? 0)} required id="ff-bps"><input id="ff-bps" className="db-input" inputMode="numeric" value={fee.bps} onChange={(e) => setFee({ ...fee, bps: e.target.value })} /></Field>}
          </div>
          {fee.type === 'PERCENTAGE' && <Field label={words('f.percentOf')} id="ff-of"><select id="ff-of" className="db-select" value={fee.percentOf} onChange={(e) => setFee({ ...fee, percentOf: e.target.value as FeeForm['percentOf'] })}>{(['SUBTOTAL', 'DISCOUNTED_SUBTOTAL'] as const).map((v) => <option key={v} value={v}>{words(`of.${v}`)}</option>)}</select></Field>}
          <div className="db-form-row">
            <Field label={words('f.approval')} hint={words('f.approvalHint')} id="ff-appr"><select id="ff-appr" className="db-select" value={fee.approval} onChange={(e) => setFee({ ...fee, approval: e.target.value as FeeForm['approval'] })}>{(['TEST', 'APPROVED'] as const).map((v) => <option key={v} value={v}>{words(`approval.${v}`)}</option>)}</select></Field>
            <Field label={words('f.from')} required id="ff-from"><input id="ff-from" type="date" className="db-input" value={fee.from} onChange={(e) => setFee({ ...fee, from: e.target.value })} /></Field>
          </div>
          <label className="db-check"><input type="checkbox" checked={fee.taxable} onChange={(e) => setFee({ ...fee, taxable: e.target.checked })} /> <span>{words('f.taxable')}</span></label>
          <Field label={words('f.reason')} required id="ff-reason"><input id="ff-reason" className="db-input" value={fee.reason} maxLength={500} onChange={(e) => setFee({ ...fee, reason: e.target.value })} /></Field>
          {error && <p className="db-field__error" role="alert" data-testid="fin-error">{error}</p>}
        </form>}
      </Drawer>

      <ReasonDialog open={!!target} title={target ? words(`confirm.${target.action}`, { name: target.label }) : ''} confirmLabel={t('adm.action.apply', undefined, locale)} danger={target?.action === 'DISABLED'} requireReason busy={busy} locale={locale}
        onCancel={() => { setTarget(null); setError(null) }} onConfirm={apply} testId="fin-status-dialog" />
      {error && !tax && !fee && <p className="db-field__error" role="alert" data-testid="fin-error">{error}</p>}
      <ToastLine msg={msg} />
    </div>
  )
}

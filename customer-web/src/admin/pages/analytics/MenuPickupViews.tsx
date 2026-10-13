import { Icon } from '../../../dashboard/components/ui'
import { Badge, Stat } from '../../components/DataTable'
import type { Metric } from '../../api/ApiAdminAnalyticsRepository'
import { count, decimal, duration, moneyText, percent, say } from './format'
import { Breakdown, Empty, KpiGrid, Note, ReportFrame, Section, StatList, metricsOf, useViewReport, type ViewProps } from './parts'

/**
 * The Menu and Pickup views of Platform Analytics (Module 38) — two more reports of the backend, shown as they
 * arrive for the market, the period and (when one was chosen in the Restaurants view) the restaurant of the page.
 */

/* ------------------------------------------------------------------ Menu */
/**
 * Menu items: what was LOOKED AT and what was SOLD come from two different sources — behavioural events (recorded from
 * Module 38 on) and the items of completed orders. The table keeps them apart under two headings, each with the
 * backend's own sentence about its source, so that nobody reads "0 views, 6 sold" as a contradiction.
 */
export function MenuView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.menu(q))
  const orDash = (v: number | null | undefined) => (typeof v === 'number' && Number.isFinite(v) ? count(v, locale) : '—')
  return (
    <ReportFrame report={report} locale={locale}>{(d) => (
      <>
        <KpiGrid metrics={d.metrics} definitions={definitions} locale={locale} testId="an-menu-kpis" />
        <Section title={say('menu.items', undefined, locale)} testId="an-menu-items">
          <dl className="adm-an-sources">
            <div data-testid="an-menu-source-viewed"><dt>{say('menu.behaviour', undefined, locale)}</dt><dd>{say('menu.source', { source: d.viewed_source }, locale)}</dd></div>
            <div data-testid="an-menu-source-sold"><dt>{say('menu.sold', undefined, locale)}</dt><dd>{say('menu.source', { source: d.sold_source }, locale)}</dd></div>
          </dl>
          {d.items.length === 0 ? <Empty locale={locale} testFor="an-menu-items" /> : (
            <div className="db-table-wrap" tabIndex={0}>
              <table className="db-table adm-an-table" data-testid="an-menu-items-table">
                <caption className="db-sr-only">{say('menu.items', undefined, locale)}. {say('menu.behaviour', undefined, locale)}: {d.viewed_source}. {say('menu.sold', undefined, locale)}: {d.sold_source}.</caption>
                <thead>
                  <tr>
                    <td />
                    <th scope="colgroup" colSpan={3} className="adm-an-colgroup" data-source="viewed">{say('menu.behaviour', undefined, locale)}</th>
                    <th scope="colgroup" colSpan={4} className="adm-an-colgroup" data-source="sold">{say('menu.sold', undefined, locale)}</th>
                  </tr>
                  <tr>
                    <th scope="col">{say('menu.col.item', undefined, locale)}</th>
                    <th scope="col" className="adm-td--end">{say('menu.col.views', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('menu.col.cartAdds', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('menu.col.addsPerView', undefined, locale)}</th>
                    <th scope="col" className="adm-td--end">{say('menu.col.units', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('menu.col.orders', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('menu.col.sales', undefined, locale)}</th><th scope="col" className="adm-td--end">{say('menu.col.share', undefined, locale)}</th>
                  </tr>
                </thead>
                <tbody>{d.items.map((r) => (
                  <tr key={`${r.item.id}-${r.sales?.currency ?? ''}`} data-testid="an-menu-item-row" data-item={r.item.id} data-currency={r.sales?.currency}>
                    <th scope="row" className="adm-an-rowhead"><span dir="auto">{r.item.name}</span></th>
                    <td className="adm-td--end" data-col="views">{orDash(r.views)}</td>
                    <td className="adm-td--end" data-col="cart_adds">{orDash(r.cart_adds)}</td>
                    {/* Under the minimum number of views the backend sends no quotient: said in words, not as a zero. */}
                    <td className="adm-td--end" data-col="cart_adds_per_view">{typeof r.cart_adds_per_view === 'number' ? decimal(r.cart_adds_per_view, locale) : r.views > 0 ? say('insufficient', undefined, locale) : '—'}</td>
                    <td className="adm-td--end" data-col="units_sold">{orDash(r.units_sold)}</td>
                    <td className="adm-td--end" data-col="orders">{orDash(r.orders)}</td>
                    <td className="adm-td--end" data-col="sales">{moneyText(r.sales, locale)}{r.sales && <> <Badge tone="muted">{r.sales.currency}</Badge></>}</td>
                    <td className="adm-td--end" data-col="share_of_sales">{typeof r.share_of_sales_percent === 'number' ? percent(r.share_of_sales_percent, locale) : '—'}</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          <Note>{say('menu.shareNote', undefined, locale)}</Note>
        </Section>
        <p className="adm-an-insight" data-testid="an-menu-advice"><Icon name="info" size={16} /> {d.advice_only}</p>
      </>
    )}</ReportFrame>
  )
}

/* ------------------------------------------------------------------ Pickup */
const UTILIZATION = 'pickup_capacity_utilization'
/**
 * Pickup: how orders were to be collected (as soon as possible or at a chosen time, with the lead time), by which
 * method, and why a pickup time was refused. Capacity is shown only where a capacity per slot is CONFIGURED: without
 * one there is no utilization to speak of, and the page shows the backend's sentence instead of a figure.
 */
export function PickupView(p: ViewProps) {
  const { locale, definitions } = p
  const report = useViewReport(p, (q) => p.repo.pickup(q))
  return (
    <ReportFrame report={report} locale={locale}>{(d) => {
      const configured = d.capacity?.configured === true
      const all = metricsOf(d.metrics)
      const utilization: Metric | undefined = all.find((m) => m.key === UTILIZATION)
      const n = (v: number | null | undefined) => (typeof v === 'number' && Number.isFinite(v) ? count(v, locale) : '—')
      return (
        <>
          {/* Utilization belongs to the capacity block below — and to nowhere when no capacity is configured. */}
          <KpiGrid metrics={all.filter((m) => m.key !== UTILIZATION)} definitions={definitions} locale={locale} testId="an-pickup-kpis" />
          <div className="adm-an-grid3">
            <Section title={say('pickup.modes', undefined, locale)} testId="an-pickup-modes">
              <Breakdown rows={d.modes} caption={say('pickup.modes', undefined, locale)} head={say('pickup.col.mode', undefined, locale)} locale={locale} testId="an-pickup-modes-table"
                extra={[{ id: 'lead', head: say('pickup.col.leadTime', undefined, locale), cell: (r) => (typeof r.lead_time_average === 'number' ? duration(r.lead_time_average, locale) : '—') }]} />
            </Section>
            <Section title={say('pickup.methods', undefined, locale)} testId="an-pickup-methods">
              <Breakdown rows={d.methods} caption={say('pickup.methods', undefined, locale)} head={say('pickup.col.method', undefined, locale)} locale={locale} testId="an-pickup-methods-table" />
            </Section>
            <Section title={say('pickup.refusals', undefined, locale)} subtitle={say('pickup.refusalsNote', undefined, locale)} testId="an-pickup-refusals">
              <Breakdown rows={d.refusal_reasons} caption={say('pickup.refusals', undefined, locale)} head={say('col.reason', undefined, locale)} locale={locale} testId="an-pickup-refusals-table" />
            </Section>
          </div>
          <section className="db-card adm-an-section" aria-label={say('pickup.capacity', undefined, locale)} data-testid="an-pickup-capacity" data-configured={configured ? 'true' : 'false'}>
            <div className="db-card__head"><div><h2 className="db-card__title">{say('pickup.capacity', undefined, locale)}</h2></div></div>
            {configured && (
              <>
                {utilization && <StatList metrics={[utilization]} block="capacity" definitions={definitions} locale={locale} />}
                <div className="adm-stats">
                  <Stat label={say('pickup.capacity.slotsWithOrders', undefined, locale)} value={<span data-testid="an-pickup-slots-with-orders">{n(d.capacity.slots_with_orders)}</span>} />
                  <Stat label={say('pickup.capacity.slotsFull', undefined, locale)} value={<span data-testid="an-pickup-slots-full">{n(d.capacity.slots_full)}</span>} />
                  <Stat label={say('pickup.capacity.unitsUsed', undefined, locale)} value={<span data-testid="an-pickup-units-used">{n(d.capacity.units_used)}</span>} />
                  <Stat label={say('pickup.capacity.unitsCapacity', undefined, locale)} value={<span data-testid="an-pickup-units-capacity">{n(d.capacity.units_capacity)}</span>} />
                </div>
              </>
            )}
            <p className={configured ? 'adm-an-note' : 'adm-an-restricted'} data-testid="an-pickup-capacity-note">{d.capacity?.note}</p>
          </section>
          <Note testId="an-pickup-delivery">{d.delivery}</Note>
        </>
      )
    }}</ReportFrame>
  )
}

import { Card, ErrorState, Pill, Skeleton } from '../../dashboard/components/ui'
import { formatMoney } from '../../i18n/format'
import { t } from '../../i18n/strings'
import { useAdmin } from '../AdminContext'
import { fmtDateTime, useLoad } from './shared'

/**
 * Menu oversight (Module 24): the menu of a location exactly as customers see it, with prices, each item's state and
 * the counts behind it. Read-only — menus are edited by the restaurant; the backend has no admin write route.
 */
export default function RestaurantMenuTab({ id }: { id: string }) {
  const a = useAdmin(); const locale = a.locale
  const { data, state, reload } = useLoad(() => (a.repos.restaurants.menu ? a.repos.restaurants.menu(id) : Promise.reject(new Error('not_available'))), [a.repos, id])
  if (state === 'loading') return <Card title={t('adm.restaurant.tab.menu', undefined, locale)}><Skeleton rows={5} /></Card>
  if (state === 'error' || !data) return <Card title={t('adm.restaurant.tab.menu', undefined, locale)}><ErrorState title={t('adm.error.loadTitle', undefined, locale)} onRetry={() => { void reload() }} locale={locale} /></Card>
  const s = data.summary
  const counts: Array<[string, number]> = [['categories', s.categories], ['items', s.items], ['active', s.activeItems], ['soldOut', s.soldOutItems], ['unavailable', s.unavailableItems], ['disabled', s.disabledItems], ['archived', s.archivedItems], ['customizable', s.customizableItems]]
  return (
    <Card title={t('adm.restaurant.tab.menu', undefined, locale)} subtitle={t('adm.menu.lead', undefined, locale)}>
      <div className="adm-menu-counts" data-testid="admin-menu-summary">{counts.map(([key, n]) => <div key={key} className="adm-menu-count" data-count={key}><b>{n}</b><small className="db-muted">{t(`adm.menu.count.${key}`, undefined, locale)}</small></div>)}</div>
      {data.menu
        ? <p className="db-muted" data-testid="admin-menu-status">{t('adm.menu.status', undefined, locale)}: {data.menu.status} · {t('adm.menu.version', undefined, locale)} {data.menu.catalogVersion}{s.lastChangedAt ? ` · ${t('adm.menu.updated', undefined, locale)} ${fmtDateTime(s.lastChangedAt, locale)}` : ''}</p>
        : <p className="db-muted" data-testid="admin-menu-none">{t('adm.menu.none', undefined, locale)}</p>}
      {data.inactiveCategories.length > 0 && <p className="db-muted" data-testid="admin-menu-hidden">{t('adm.menu.hiddenCategories', undefined, locale)}: {data.inactiveCategories.map((c) => c.name).join(', ')}</p>}
      {data.categories.map((c) => (
        <section key={c.id} className="adm-menu-category" data-testid="admin-menu-category">
          <h3 dir="auto">{c.name} <small className="db-muted">({c.items.length})</small></h3>
          <div className="db-table-wrap" tabIndex={0}>
            <table className="db-table" data-testid="admin-menu-table"><caption className="db-sr-only">{c.name}</caption>
              <thead><tr><th scope="col">{t('adm.menu.col.item', undefined, locale)}</th><th scope="col">{t('adm.menu.col.price', undefined, locale)}</th><th scope="col">{t('adm.menu.col.state', undefined, locale)}</th><th scope="col">{t('adm.menu.col.labels', undefined, locale)}</th></tr></thead>
              <tbody>{c.items.map((i) => (
                <tr key={i.id} data-item={i.slug}>
                  <td dir="auto"><b>{i.name}</b>{i.customizable && <small className="db-muted"> · {t('adm.menu.options', undefined, locale)}</small>}</td>
                  <td className="db-money">{formatMoney(i.priceMinor, i.currency, locale)}</td>
                  <td><Pill tone={i.status === 'ACTIVE' ? 'green' : 'orange'}>{t(`adm.menu.state.${i.status}`, undefined, locale)}</Pill>{i.status === 'ACTIVE' && !i.orderable && i.reason ? <small className="db-muted"> {i.reason}</small> : null}</td>
                  <td dir="auto">{i.dietaryTags.join(', ')}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        </section>
      ))}
    </Card>
  )
}

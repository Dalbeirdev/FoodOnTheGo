import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { t } from '../../i18n/strings'
import { useDashboard } from '../DashboardContext'
import { Card, Icon, PageHeader, Pill } from '../components/ui'

/** Restaurant partner help (Module 17): topic cards + support contacts (delivery channels pending backend). */
const TOPICS: Array<{ id: string; icon: string; to: string }> = [
  { id: 'orders', icon: 'orders', to: '/restaurant-dashboard/orders' }, { id: 'menu', icon: 'menu', to: '/restaurant-dashboard/menu' }, { id: 'pickup', icon: 'qr', to: '/restaurant-dashboard/pickup-verification' },
  { id: 'reviews', icon: 'reviews', to: '/restaurant-dashboard/reviews' }, { id: 'payments', icon: 'money', to: '/restaurant-dashboard/settings' }, { id: 'account', icon: 'staff', to: '/restaurant-dashboard/staff' }, { id: 'technical', icon: 'help', to: '/restaurant-dashboard/help' },
]
export default function HelpPage() {
  const d = useDashboard(); const locale = d.locale; const [q, setQ] = useState('')
  useEffect(() => { document.title = `${t('dash.nav.help', undefined, locale)} · ${t('dash.brand', undefined, locale)}` }, [locale])
  const list = TOPICS.filter((tp) => !q.trim() || `${t(`dash.help.topic.${tp.id}`, undefined, locale)} ${t(`dash.help.topicText.${tp.id}`, undefined, locale)}`.toLowerCase().includes(q.trim().toLowerCase()))
  return (
    <div className="db-page" data-testid="db-help">
      <PageHeader title={t('dash.help.title', undefined, locale)} lead={t('dash.help.lead', undefined, locale)} />
      <label className="db-search" style={{ maxWidth: 520 }}><Icon name="search" size={18} /><span className="db-sr-only">{t('dash.help.search', undefined, locale)}</span><input type="search" className="db-input" placeholder={t('dash.help.searchPlaceholder', undefined, locale)} value={q} onChange={(e) => setQ(e.target.value)} /></label>
      <div className="db-help-grid" data-testid="help-topics">{list.map((tp) => <Link key={tp.id} to={tp.to} className="db-help-card" style={{ textDecoration: 'none', color: 'inherit' }}><span className="db-kpi__icon db-kpi__icon--orange"><Icon name={tp.icon} /></span><h3>{t(`dash.help.topic.${tp.id}`, undefined, locale)}</h3><p>{t(`dash.help.topicText.${tp.id}`, undefined, locale)}</p></Link>)}</div>
      <Card title={t('dash.help.contact', undefined, locale)} subtitle={t('dash.help.contactSub', undefined, locale)}>
        <div className="db-settings-row"><p>{t('dash.help.partnerSupport', undefined, locale)}<small>{t('dash.help.partnerSupportHint', undefined, locale)}</small></p><Pill tone="amber">{t('dash.pending', undefined, locale)}</Pill></div>
        <div className="db-settings-row"><p>{t('dash.help.status', undefined, locale)}<small>{t('dash.help.statusHint', undefined, locale)}</small></p><Pill tone="green">{t('dash.help.operational', undefined, locale)}</Pill></div>
        <div className="db-settings-row"><p>{t('dash.help.customerHelp', undefined, locale)}<small>{t('dash.help.customerHelpHint', undefined, locale)}</small></p><Link to="/help" className="db-link">{t('dash.help.openCustomerHelp', undefined, locale)} →</Link></div>
      </Card>
    </div>
  )
}

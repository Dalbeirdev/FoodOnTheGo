import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import Header from '../components/Header'
import AccountSidebar from '../components/AccountSidebar'
import { t } from '../i18n/strings'
import { statusLabel, statusTone } from './labels'
import '../pages/account/AccountPage.css'
import './Support.css'

/** The account frame the support pages share: header, account sidebar, the page head and its content. */
export function SupportShell({ title, lead, actions, children }: { title: string; lead?: string; actions?: ReactNode; children: ReactNode }) {
  return (
    <>
      <Header />
      <main id="main" className="ac">
        <div className="ac__grid">
          <AccountSidebar />
          <div className="ac__main">
            <div className="ac-head"><div><h1>{title}</h1>{lead && <p>{lead}</p>}</div>{actions}</div>
            {children}
          </div>
        </div>
      </main>
    </>
  )
}

/** The status of a case as a chip: the backend's word in plain language, the code on the element for tests. */
export function SupportStatusChip({ status, locale, testId }: { status: string; locale: string; testId?: string }) {
  return <span className={`sup-chip sup-chip--${statusTone(status)}`} data-testid={testId} data-status={status}>{statusLabel(status, locale)}</span>
}

/** No backend, no cases: said plainly, with the way back to the contact details that do work. */
export function SupportOffline({ locale, testId }: { locale: string; testId: string }) {
  return (
    <div className="ac-card" data-testid={testId} data-source="none">
      <h2>{t('sup.offline.title', undefined, locale)}</h2>
      <p className="sup-muted">{t('sup.offline.text', undefined, locale)}</p>
      <div className="sup-actions"><Link to="/help" className="btn btn--outline">{t('sup.offline.action', undefined, locale)}</Link></div>
    </div>
  )
}

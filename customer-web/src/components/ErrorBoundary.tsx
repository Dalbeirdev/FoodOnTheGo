import { Component, type ErrorInfo, type ReactNode } from 'react'
import '../pages/NotFoundPage.css'

/**
 * Module 41: the last line of defence of every surface (Customer Web, Restaurant Dashboard, Platform Admin). A render
 * error or a lazy chunk that cannot be loaded any more (a new release replaced it) shows a calm page with a reload
 * button instead of a blank screen. The error text itself is never shown — it is developer language.
 */
export default class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true }
  }

  componentDidCatch(error: unknown, info: ErrorInfo): void {
    // Development only: the console of a production build stays quiet (nothing personal is in the error, but the
    // stack is of no use to a customer).
    if (import.meta.env.DEV) console.error('Unhandled render error', error, info.componentStack)
  }

  render(): ReactNode {
    if (!this.state.failed) return this.props.children
    return (
      <main id="main" className="nf" data-page="app-error" role="alert">
        <div className="nf__card">
          <h1>Something went wrong</h1>
          <p className="nf__text">This page could not be shown. Reloading usually fixes it — nothing you entered on another page is lost.</p>
          <div className="nf__actions">
            <button type="button" className="btn btn--lg btn--primary" onClick={() => window.location.reload()}>Reload the page</button>
            <a href="/" className="btn btn--lg btn--outline">Go to Home</a>
          </div>
        </div>
      </main>
    )
  }
}

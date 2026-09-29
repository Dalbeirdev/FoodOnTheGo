import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { t } from '../i18n/strings'
import type { PickupVerification } from './repositories'

/**
 * Pickup code + QR card (Modules 13 / 14 share it — one pickup-verification system). The QR encodes only the opaque
 * token; the text code is the fallback. Showing either never proves pickup: the restaurant / server verifies.
 */
const QrIcon = ({ size = 18 }: { size?: number }) => (<svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /><path d="M14 14h3v3h-3zM19 14h2M14 19h2M19 19h2" /></svg>)

export function PickupCode({ verification: pv, locale, emphasis = false }: { verification: PickupVerification | null; locale: string; emphasis?: boolean }) {
  const [qr, setQr] = useState<string | null>(null)
  useEffect(() => {
    let on = true
    if (!pv) { setQr(null); return }
    QRCode.toDataURL(pv.qrToken, { errorCorrectionLevel: 'M', margin: 1, width: 220, color: { dark: '#101827', light: '#ffffff' } }).then((u) => { if (on) setQr(u) }).catch(() => { if (on) setQr(null) })
    return () => { on = false }
  }, [pv])
  if (!pv) return <section className="cart-card ocp-code" aria-labelledby="ocp-code-title"><h2 id="ocp-code-title"><QrIcon /> {t('oc.code', undefined, locale)}</h2><p className="cart-notice cart-notice--warn" role="status">{t('oc.code.unavailable', undefined, locale)}</p></section>
  const spaced = pv.code.split('').join(' ')
  return (
    <section className={`cart-card ocp-code ${emphasis ? 'ocp-code--emphasis' : ''}`} aria-labelledby="ocp-code-title">
      <h2 id="ocp-code-title"><QrIcon /> {t('oc.code', undefined, locale)}</h2>
      <p className="cart-muted">{t(emphasis ? 'track.code.textReady' : 'oc.code.text', undefined, locale)}</p>
      <div className="ocp-code__grid">
        <div className="ocp-code__qr">
          {qr ? <img src={qr} width={220} height={220} alt={t('oc.code.qrAlt', { code: spaced }, locale)} data-testid="oc-qr" /> : <div className="ocp-skel ocp-skel--qr" aria-hidden="true" />}
        </div>
        <div className="ocp-code__text">
          <p className="ocp-code__label">{t('oc.code.label', undefined, locale)}</p>
          <p className="ocp-code__value" data-testid="oc-code" aria-label={t('oc.code.aria', { code: spaced }, locale)}>{pv.code}</p>
          <p className="cart-muted">{t(`oc.code.state.${pv.status}`, undefined, locale)}</p>
        </div>
      </div>
      <p className="cart-muted ocp-code__note">{t('oc.code.note', undefined, locale)}</p>
    </section>
  )
}

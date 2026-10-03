import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import Header from '../components/Header'
import CartBar from '../components/CartBar'
import { ChevronRightIcon } from '../components/Icons'
import { useCart, type AddItemInput } from '../cart/CartContext'
import { menuRepository } from '../menu/menuRepository'
import type { MenuItem, MenuItemDetail, OptionGroup } from '../menu/repositories'
import { restaurantRepository } from '../repositories'
import type { Restaurant } from '../repositories/types'
import { computeAvailability } from '../repositories/mock/restaurants'
import { formatMinutes, formatMoney } from '../i18n/format'
import { t, useLocale } from '../i18n/strings'
import { clampQuantity, defaultSelections, lineTotalMinor, normalizeInstructions, orderability, selectOption, selectedOptions, unitPriceMinor, validateSelections, type GroupIssue, type Selections } from '../pricing/pricing'
import './ItemDetailPage.css'

/**
 * Food Item Details & Customization (Module 08) — canonical /restaurants/:restaurantSlug/item/:itemSlug.
 * Everything on the page is driven by MenuItemDetail (variant + modifier groups, min/max, availability);
 * prices come from LocalPricingService in minor units. Add to Cart validates, builds a structured
 * CartItem and hands it to the centralized cart (one restaurant per cart, identical configurations merge).
 */

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const })
const CheckIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="m5 12 4 4L19 7" /></svg>)
const CartIcon = ({ size = 22 }: P) => (<svg {...stroke(size)}><path d="M3 4h2l2.5 11h11L21 7H7" /><circle cx="9" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" /></svg>)
const PlusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M12 5v14M5 12h14" /></svg>)
const MinusIcon = ({ size = 16 }: P) => (<svg {...stroke(size)}><path d="M5 12h14" /></svg>)
const LeafIcon = ({ size = 14 }: P) => (<svg {...stroke(size)}><path d="M5 19c0-8 4-13 14-14 0 10-5 14-13 14M5 19l6-6" /></svg>)

function Img({ src, fallback, alt = '', className = '' }: { src: string; fallback: string; alt?: string; className?: string }) {
  return (
    <span className={`it-img ${className}`}>
      {src && <img src={src} alt={alt} loading="lazy" onError={(e) => { e.currentTarget.style.display = 'none' }} />}
      <span className="it-img__fallback" aria-hidden="true">{fallback}</span>
    </span>
  )
}

const signed = (minor: number, currency: string, locale: string) => (minor === 0 ? '' : `${minor > 0 ? '+' : '−'}${formatMoney(Math.abs(minor), currency, locale)}`)

function ruleLabel(g: OptionGroup, locale: string): string {
  const { minSelections: min, maxSelections: max, required } = g
  if (max === 1) return t('item.chooseOne', undefined, locale)
  if (min === max && min > 1) return t('item.chooseExactly', { count: min }, locale)
  if (min > 0 && max > min) return t('item.chooseRange', { min, max }, locale)
  if (min > 0) return t('item.chooseAtLeast', { count: min }, locale)
  return required ? t('item.chooseAtLeast', { count: 1 }, locale) : t('item.chooseUpTo', { count: max }, locale)
}

function issueText(issue: GroupIssue, locale: string): string {
  if (issue.code === 'required') return t('item.issue.required', undefined, locale)
  if (issue.code === 'min') return t('item.issue.min', { count: issue.min ?? 1 }, locale)
  if (issue.code === 'max') return t('item.issue.max', { count: issue.max ?? 1 }, locale)
  return t('item.issue.unavailable', undefined, locale)
}

type LoadState = { status: 'loading' | 'ready' | 'notfound' | 'error'; restaurant: Restaurant | null; item: MenuItemDetail | null; error?: string }

export default function ItemDetailPage() {
  const { rid = '', itemId = '' } = useParams()
  const [search] = useSearchParams()
  const editId = search.get('edit')
  const navigate = useNavigate()
  const { locale } = useLocale()
  const cart = useCart()
  const editing = editId ? cart.cart?.items.find((i) => i.id === editId) ?? null : null
  const restoredFor = useRef<string | null>(null)
  const [state, setState] = useState<LoadState>({ status: 'loading', restaurant: null, item: null })
  const [selections, setSelections] = useState<Selections>({})
  const [quantity, setQuantity] = useState(1)
  const [instructions, setInstructions] = useState('')
  const [attempted, setAttempted] = useState(false)
  const [touched, setTouched] = useState<Record<string, boolean>>({})
  const [groupNotice, setGroupNotice] = useState<Record<string, string>>({})
  const [slide, setSlide] = useState(0)
  const [toast, setToast] = useState<{ merged: boolean } | null>(null)
  const [more, setMore] = useState<MenuItem[]>([])
  const groupRefs = useRef<Record<string, HTMLFieldSetElement | null>>({})
  const [reloadTick, setReloadTick] = useState(0)

  useEffect(() => {
    let alive = true
    ;(async () => {
      await Promise.resolve()
      if (!alive) return
      setState({ status: 'loading', restaurant: null, item: null })
      try {
        const restaurant = await restaurantRepository.getRestaurantBySlug(rid)
        const item = restaurant ? await menuRepository.getItemDetail(restaurant.id, itemId) : null
        if (!alive) return
        if (!restaurant || !item) { setState({ status: 'notfound', restaurant, item: null }); return }
        setState({ status: 'ready', restaurant, item })
        setSelections(defaultSelections(item)); setQuantity(clampQuantity(item, item.minimumQuantity)); setInstructions(''); restoredFor.current = null
        setAttempted(false); setTouched({}); setGroupNotice({}); setSlide(0); setToast(null)
        document.title = `${item.name} · ${restaurant.name} · FoodOnTheGo`
        menuRepository.getItems(restaurant.id, { limit: 12 }).then((p) => { if (alive) setMore(p.items.filter((i) => i.id !== item.id && i.availability === 'available').slice(0, 4)) }).catch(() => {})
      } catch (e) {
        if (alive) setState({ status: 'error', restaurant: null, item: null, error: e instanceof Error ? e.message : String(e) })
      }
    })()
    return () => { alive = false }
  }, [rid, itemId, editId, reloadTick])
  // Edit mode (Module 09): once the item and the cart line are both available, restore the previous configuration.
  useEffect(() => {
    const item = state.item
    if (!item || !editing || restoredFor.current === editing.id) return
    restoredFor.current = editing.id
    const restored: Selections = {}
    for (const o of [...editing.selectedVariants, ...editing.selectedModifiers]) (restored[o.groupId] ??= []).push(o.optionId)
    const id = setTimeout(() => { setSelections(restored); setQuantity(clampQuantity(item, editing.quantity)); setInstructions(editing.specialInstructions) }, 0)
    return () => clearTimeout(id)
  }, [state.item, editing])

  const item = state.item; const restaurant = state.restaurant
  const groups = useMemo(() => (item ? [...item.variantGroups, ...item.modifierGroups].sort((a, b) => a.displayOrder - b.displayOrder) : []), [item])
  const issues = useMemo(() => (item ? validateSelections(item, selections) : []), [item, selections])
  const unit = item ? unitPriceMinor(item, selections) : 0
  const total = lineTotalMinor(unit, quantity)
  const why = item ? orderability(item, restaurant) : 'ok'
  const availability = useMemo(() => (restaurant ? computeAvailability(restaurant, new Date().toISOString()) : null), [restaurant])
  const canAdd = why === 'ok' && cart.status !== 'updating'

  const pick = useCallback((groupId: string, optionId: string) => {
    if (!item) return
    const res = selectOption(item, selections, groupId, optionId)
    setTouched((x) => ({ ...x, [groupId]: true }))
    if (res.applied) { setSelections(res.selections); setGroupNotice((n) => ({ ...n, [groupId]: '' })) }
    else if (res.reason === 'max') { const g = groups.find((x) => x.id === groupId)!; setGroupNotice((n) => ({ ...n, [groupId]: t('item.maxReached', { count: g.maxSelections }, locale) })) }
  }, [item, selections, groups, locale])

  const addToCart = () => {
    if (!item || !restaurant || !canAdd) return
    setAttempted(true)
    if (issues.length) { const first = groupRefs.current[issues[0].groupId]; first?.scrollIntoView({ block: 'center', behavior: 'smooth' }); first?.focus(); return }
    const chosen = selectedOptions(item, selections)
    const input: AddItemInput = {
      menuItemId: item.id, itemSlug: item.slug, itemName: item.name, image: item.image, fallback: item.fallback, basePriceMinor: item.basePriceMinor, currency: item.currency,
      restaurant: { id: restaurant.id, slug: restaurant.slug, name: restaurant.name, currency: restaurant.currency },
      selectedVariants: chosen.filter((c) => c.group.kind === 'variant').map((c) => ({ groupId: c.group.id, groupName: c.group.name, optionId: c.option.id, optionName: c.option.name, priceAdjustmentMinor: c.option.priceAdjustmentMinor })),
      selectedModifiers: chosen.filter((c) => c.group.kind === 'modifier').map((c) => ({ groupId: c.group.id, groupName: c.group.name, optionId: c.option.id, optionName: c.option.name, priceAdjustmentMinor: c.option.priceAdjustmentMinor })),
      specialInstructions: normalizeInstructions(instructions, item.instructionsMaxLength), quantity: clampQuantity(item, quantity), unitPriceMinor: unit, minimumQuantity: item.minimumQuantity, maximumQuantity: item.maximumQuantity,
    }
    if (editing) {
      const res = cart.editItem(editing.id, input)
      if (res.ok) { setAttempted(false); navigate('/cart', { state: { updated: true } }) }
      return
    }
    const res = cart.addItem(input)
    if (res.ok) setAttempted(false)
  }
  // Feedback for every successful add for this item — including one completed through the cart-replacement dialog.
  const lastAdded = cart.lastAdded
  useEffect(() => { if (!(lastAdded && item && lastAdded.menuItemId === item.id)) return; const id = setTimeout(() => setToast({ merged: lastAdded.merged }), 0); return () => clearTimeout(id) }, [lastAdded, item])
  useEffect(() => { if (!toast) return; const id = setTimeout(() => setToast(null), 5000); return () => clearTimeout(id) }, [toast])

  if (state.status === 'loading') return (<><Header /><main id="main" className="it"><div className="it__inner"><p className="it-state" role="status" aria-busy="true">{t('item.loading', undefined, locale)}</p><div className="it-skel" aria-hidden="true"><span /><span /><span /></div></div></main></>)
  if (state.status === 'notfound') return (
    <><Header /><main id="main" className="it"><div className="it__inner"><div className="rd-state it-state" role="status"><h1>{t('item.notFound.title', undefined, locale)}</h1><p>{t('item.notFound.text', undefined, locale)}</p><Link to={state.restaurant ? `/restaurants/${state.restaurant.slug}` : '/restaurants'} className="btn btn--primary">{t('item.notFound.back', undefined, locale)}</Link></div></div></main></>
  )
  if (state.status === 'error' || !item || !restaurant) return (
    <><Header /><main id="main" className="it"><div className="it__inner"><div className="rd-state rd-state--error it-state" role="alert"><h1>{t('item.error.title', undefined, locale)}</h1><p>{state.error}</p><button type="button" className="btn btn--primary" onClick={() => setReloadTick((x) => x + 1)}>{t('item.error.retry', undefined, locale)}</button></div></div></main></>
  )

  const photos = item.images.length ? item.images : [item.image]
  const showIssues = (g: OptionGroup) => (attempted || touched[g.id]) ? issues.find((i) => i.groupId === g.id) : undefined
  const blocked = why !== 'ok'

  return (
    <>
      <Header />
      <main id="main" className={`it ${cart.count ? 'has-cart' : ''}`}>
        <div className="it__inner">
          <div className="it-crumbs">
            <nav aria-label="Breadcrumb">
              <Link to="/">Home</Link><ChevronRightIcon size={14} />
              <Link to="/restaurants">Restaurants</Link><ChevronRightIcon size={14} />
              <Link to={`/restaurants/${restaurant.slug}`} dir="auto">{restaurant.name}</Link><ChevronRightIcon size={14} />
              <span aria-current="page" dir="auto">{item.name}</span>
            </nav>
          </div>

          <div className="it__grid">
            <section className="it-gallery" aria-label="Photos">
              <div className="it-gallery__main">
                <Img src={photos[slide] ?? item.image} fallback={item.fallback} alt={item.name} />
                {item.featured && <span className="it-gallery__badge">{t('rd.item.featured', undefined, locale)}</span>}
                {item.availability !== 'available' && <span className="it-gallery__badge it-gallery__badge--off">{t(`rd.item.${item.availability}`, undefined, locale)}</span>}
              </div>
              {photos.length > 1 && (
                <div className="it-gallery__thumbs">
                  {photos.map((src, i) => <button key={i} type="button" className={i === slide ? 'is-on' : ''} onClick={() => setSlide(i)} aria-label={`Photo ${i + 1}`} aria-pressed={i === slide}><Img src={src} fallback={item.fallback} /></button>)}
                </div>
              )}
            </section>

            <section className="it-summary">
              <Link to={`/restaurants/${restaurant.slug}`} className="it-summary__rest" dir="auto">{restaurant.name}</Link>
              <h1 dir="auto">{item.name}</h1>
              {item.alternateNames && item.alternateNames.length > 0 && <p className="it-summary__alt" dir="auto">{item.alternateNames.join(' · ')}</p>}
              <p className="it-summary__price"><span className="it-muted">{t('item.base', undefined, locale)}</span> <strong>{formatMoney(item.basePriceMinor, item.currency, locale)}</strong></p>
              {item.description && <p className="it-summary__desc" dir="auto">{item.description}</p>}
              <ul className="it-meta" aria-label="Item details">
                {item.prepTimeMin > 0 && <li>{t('item.prep', { minutes: formatMinutes(item.prepTimeMin) }, locale)}</li>}
                {item.dietaryTags.map((d) => <li key={d} className="it-diet"><LeafIcon /> {d}</li>)}
              </ul>
              {blocked && <p className="rd-note rd-note--warn it-blocked" role="status">{t(`item.unavailable.${why === 'item_unavailable' ? item.availability : why}`, undefined, locale)}</p>}
              {!blocked && availability && !['open', 'closing_soon'].includes(availability.status) && <p className="rd-note it-closed" role="status">{t('item.closedNote', undefined, locale)}</p>}
            </section>

            <aside className="it-custom" aria-labelledby="customize-title">
              <h2 id="customize-title">{editing ? t('item.edit.title', undefined, locale) : groups.length ? 'Customize' : 'Your order'}</h2>
              {editing && <p className="it-group__rule" style={{ margin: '0 0 8px' }}><Link to="/cart">{t('item.edit.cancel', undefined, locale)}</Link></p>}
              {groups.map((g, gi) => {
                const issue = showIssues(g)
                const chosen = selections[g.id] ?? []
                const single = g.maxSelections === 1
                return (
                  <fieldset key={g.id} ref={(el) => { groupRefs.current[g.id] = el }} tabIndex={-1} className={`it-group ${issue ? 'is-invalid' : ''}`} aria-describedby={`${g.id}-rule`}>
                    <legend className="it-group__head"><span className="it-step">{gi + 1}</span><span dir="auto">{g.name}</span><em className={g.required ? 'it-req' : ''}>{g.required ? t('item.required', undefined, locale) : t('item.optional', undefined, locale)}</em></legend>
                    <p id={`${g.id}-rule`} className="it-group__rule">{ruleLabel(g, locale)}{g.description && g.description !== ruleLabel(g, locale) ? ` · ${g.description}` : ''}{!single && g.maxSelections > 1 ? ` · ${t('item.selectedCount', { count: chosen.length, max: g.maxSelections }, locale)}` : ''}</p>
                    <ul className="it-opts">
                      {[...g.options].sort((a, b) => a.displayOrder - b.displayOrder).map((o) => {
                        const on = chosen.includes(o.id)
                        return (
                          <li key={o.id} className={`it-opt ${on ? 'is-on' : ''} ${o.available ? '' : 'it-opt--off'}`}>
                            <label>
                              <input type={single ? 'radio' : 'checkbox'} name={g.id} value={o.id} checked={on} disabled={!o.available || blocked} onChange={() => pick(g.id, o.id)} />
                              <span className="it-opt__name" dir="auto">{o.name}</span>
                              {!o.available && <span className="it-opt__off">{t('item.optionSoldOut', undefined, locale)}</span>}
                              <span className="it-opt__price">{signed(o.priceAdjustmentMinor, item.currency, locale)}</span>
                            </label>
                          </li>
                        )
                      })}
                    </ul>
                    {issue && <p className="it-group__error" role="alert">{issueText(issue, locale)}</p>}
                    {!issue && groupNotice[g.id] && <p className="it-group__error" role="status">{groupNotice[g.id]}</p>}
                  </fieldset>
                )
              })}

              <div className="it-group">
                <h3 className="it-group__head"><span className="it-step">{groups.length + 1}</span><label htmlFor="it-instructions">{t('item.instructions', undefined, locale)}</label><em>{t('item.optional', undefined, locale)}</em></h3>
                <label className="it-note">
                  <textarea id="it-instructions" value={instructions} maxLength={item.instructionsMaxLength} placeholder={t('item.instructions.placeholder', undefined, locale)} onChange={(e) => setInstructions(e.target.value)} disabled={blocked} rows={3} />
                  <small>{instructions.length}/{item.instructionsMaxLength}</small>
                </label>
                <p className="it-group__rule">{t('item.instructions.note', undefined, locale)}</p>
              </div>

              <div className="it-quantity">
                <h3 className="it-group__head"><span className="it-step">{groups.length + 2}</span>{t('item.quantity', undefined, locale)}</h3>
                <span className="it-qty" role="group" aria-label={t('item.quantity', undefined, locale)}>
                  <button type="button" aria-label={t('item.quantity.decrease', undefined, locale)} disabled={blocked || quantity <= item.minimumQuantity} onClick={() => setQuantity((q) => clampQuantity(item, q - 1))}><MinusIcon /></button>
                  <b aria-live="polite">{quantity}</b>
                  <button type="button" className="is-plus" aria-label={t('item.quantity.increase', undefined, locale)} disabled={blocked || quantity >= item.maximumQuantity} onClick={() => setQuantity((q) => clampQuantity(item, q + 1))}><PlusIcon /></button>
                </span>
                {quantity >= item.maximumQuantity && <small className="it-muted">{t('item.quantity.max', { count: item.maximumQuantity }, locale)}</small>}
              </div>

              <div className="it-total">
                <div>
                  <span className="it-muted">{t('item.total', undefined, locale)}</span>
                  <strong aria-live="polite">{formatMoney(total, item.currency, locale)}</strong>
                  <small className="it-muted">{t('item.unit', { price: formatMoney(unit, item.currency, locale) }, locale)}</small>
                </div>
                <button type="button" className="btn btn--primary it-total__btn" onClick={addToCart} disabled={!canAdd} aria-disabled={!canAdd}><CartIcon /> {editing ? t('item.edit.save', undefined, locale) : t('item.addToCart', undefined, locale)}</button>
              </div>
              {attempted && issues.length > 0 && <p className="it-group__error" role="alert">{t('item.addToCart.fix', undefined, locale)}</p>}
              {toast && (
                <p className="it-toast" role="status">
                  <CheckIcon /> {t('item.added', undefined, locale)} <Link to="/cart">{t('item.added.view', undefined, locale)}</Link>
                </p>
              )}
            </aside>

            <section className="it-info">
              <div className="it-info__body">
                <div>
                  <h2>About this item</h2>
                  <p dir="auto">{item.description || '—'}</p>
                  {item.dietaryTags.length > 0 && (<><h2>{t('item.dietary', undefined, locale)}</h2><p>{item.dietaryTags.join(' · ')} — {t('rd.item.dietaryNote', undefined, locale)}</p></>)}
                </div>
                <div>
                  <h2>{t('item.allergens', undefined, locale)}</h2>
                  <p dir="auto">{item.allergenInformation ?? t('item.allergens.none', undefined, locale)}</p>
                  <p><small>{t('item.allergens.note', undefined, locale)}</small></p>
                </div>
              </div>
              {more.length > 0 && (
                <>
                  <h2 className="it-related__title">{t('item.more', { name: restaurant.name }, locale)}</h2>
                  <ul className="it-related">
                    {more.map((r) => (
                      <li key={r.id}>
                        <Link to={`/restaurants/${restaurant.slug}/item/${r.slug}`} aria-hidden="true" tabIndex={-1}><Img src={r.image} fallback={r.fallback} alt="" /></Link>
                        <div>
                          <Link to={`/restaurants/${restaurant.slug}/item/${r.slug}`} dir="auto"><b>{r.name}</b></Link>
                          <span>{formatMoney(r.basePriceMinor, r.currency, locale)}</span>
                        </div>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </section>
          </div>
        </div>
        <CartBar />
      </main>
    </>
  )
}

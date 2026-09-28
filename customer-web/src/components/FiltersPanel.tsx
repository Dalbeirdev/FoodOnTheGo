import { useState } from 'react'
import { StarIcon } from './Icons'
import { formatDistance, formatMinutes, priceLevelLabel } from '../i18n/format'
import type { UnitSystem } from '../i18n/markets'
import { t, useLocale } from '../i18n/strings'
import type { FilterDefinition, FilterValue } from '../repositories/types'

type Props = {
  definitions: FilterDefinition[]
  values: Record<string, FilterValue>
  onToggleOption: (id: string, option: string) => void
  onSet: (id: string, value: FilterValue | null) => void
  onClear: () => void
  units: UnitSystem
  /** Market currency for price-level symbols; null in multi-market (general) discovery → neutral labels. */
  currency: string | null
}

/** Renders whatever filter definitions the repository declares — nothing about cuisines or units is hardcoded here. */
export default function FiltersPanel({ definitions, values, onToggleOption, onSet, onClear, units, currency }: Props) {
  const { locale } = useLocale()
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})
  const fmtValue = (d: FilterDefinition, v: number) => d.unit === 'distance' ? formatDistance(v, units, locale) : d.unit === 'minutes' ? formatMinutes(v, locale) : d.unit === 'rating' ? t('filter.ratingAbove', { rating: v.toLocaleString(locale, { minimumFractionDigits: 1 }) }, locale) : String(v)
  return (
    <aside className="filters" aria-label={t('discovery.filters', undefined, locale)}>
      <div className="filters__head">
        <h2>{t('discovery.filters', undefined, locale)}</h2>
        <button type="button" className="filters__clear" onClick={onClear}>{t('discovery.filters.clear', undefined, locale)}</button>
      </div>
      {definitions.map((d) => {
        const label = t(d.labelKey, undefined, locale)
        if (d.kind === 'toggle') {
          return (
            <div key={d.id} className="filters__group">
              <label className="check"><input type="checkbox" checked={values[d.id] === true} onChange={(e) => onSet(d.id, e.target.checked ? true : null)} /><span>{label}</span></label>
            </div>
          )
        }
        if (d.kind === 'multi') {
          const opts = d.options ?? []
          const limit = 6
          const show = expanded[d.id] ? opts : opts.slice(0, limit)
          const cur = Array.isArray(values[d.id]) ? (values[d.id] as string[]) : []
          return (
            <fieldset key={d.id} className="filters__group">
              <legend><h3>{label}</h3></legend>
              {show.map((o) => (
                <label key={o.value} className="check"><input type="checkbox" checked={cur.includes(o.value)} onChange={() => onToggleOption(d.id, o.value)} /><span dir="auto">{d.unit === 'price' ? (currency ? priceLevelLabel(Number(o.value), currency, locale) : t('filter.priceLevel', { n: o.value }, locale)) : o.label}</span>{o.count !== undefined && <small className="check__count">{o.count}</small>}</label>
              ))}
              {opts.length > limit && <button type="button" className="filters__more" aria-expanded={!!expanded[d.id]} onClick={() => setExpanded((e) => ({ ...e, [d.id]: !e[d.id] }))}>{expanded[d.id] ? t('filter.less', undefined, locale) : `${t('filter.more', undefined, locale)} (${opts.length - limit})`}</button>}
            </fieldset>
          )
        }
        // min / max sliders
        const min = d.min ?? 0, max = d.max ?? 100, step = d.step ?? 1
        const raw = typeof values[d.id] === 'number' ? (values[d.id] as number) : null
        const value = raw ?? (d.kind === 'max' ? max : min)
        const isDefault = raw === null
        const pct = ((value - min) / (max - min)) * 100
        if (d.unit === 'rating') {
          return (
            <fieldset key={d.id} className="filters__group">
              <legend><h3>{label}</h3></legend>
              {[4.5, 4, 3.5, 3].map((rv) => (
                <label key={rv} className="check"><input type="radio" name={`f-${d.id}`} checked={raw === rv} onChange={() => onSet(d.id, rv)} /><span className="stars" aria-hidden="true">{[0, 1, 2, 3, 4].map((i) => <StarIcon key={i} size={13} className={i < Math.round(rv) ? 'is-on' : ''} />)}</span><span>{t('filter.ratingAbove', { rating: rv.toLocaleString(locale, { minimumFractionDigits: 1 }) }, locale)}</span></label>
              ))}
              {raw !== null && <button type="button" className="filters__more" onClick={() => onSet(d.id, null)}>{t('discovery.filters.clear', undefined, locale)}</button>}
            </fieldset>
          )
        }
        return (
          <div key={d.id} className="filters__group">
            <h3><label htmlFor={`f-${d.id}`}>{label}</label></h3>
            <input id={`f-${d.id}`} type="range" min={min} max={max} step={step} value={value} onChange={(e) => onSet(d.id, Number(e.target.value))} className="range" style={{ ['--pct' as string]: `${pct}%` }} aria-valuetext={fmtValue(d, value)} />
            <div className="range__labels"><span>{fmtValue(d, min)}</span><span>{isDefault ? '—' : fmtValue(d, value)}</span><span>{fmtValue(d, max)}</span></div>
          </div>
        )
      })}
    </aside>
  )
}

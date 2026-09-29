import { useId, useRef, useState, type DragEvent } from 'react'
import { t } from '../../i18n/strings'
import { IMAGE_SPECS, formatBytes, imageAssets, validateImageFile, type ImageAssetRepository, type ImageIssue, type ImageKind } from '../imageAssets'
import { Icon } from './ui'

/**
 * Professional image uploader (Module 17): drop zone + browse, accepted types / size / recommended dimensions, client-side
 * resize and centre-crop to the kind's spec, preview with dimensions, replace / remove, progress and error states.
 * `value` is the stored image URL (data URL in development); the backend returns CDN URLs later.
 */
type Props = {
  kind: ImageKind
  value: string | null
  onChange: (url: string | null) => void
  label: string
  hint?: string
  disabled?: boolean
  shape?: 'square' | 'wide' | 'round'
  repository?: ImageAssetRepository
  testId?: string
  compact?: boolean
}
export default function ImageUpload({ kind, value, onChange, label, hint, disabled, shape = 'square', repository = imageAssets, testId, compact }: Props) {
  const spec = IMAGE_SPECS[kind]; const id = useId(); const inputRef = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false); const [drag, setDrag] = useState(false); const [error, setError] = useState<ImageIssue | null>(null); const [warning, setWarning] = useState<ImageIssue | null>(null); const [meta, setMeta] = useState<{ width: number; height: number; bytes: number; name: string } | null>(null)
  const locale = 'en'
  const accept = spec.accept.join(',')
  const typeLabel = spec.accept.map((a) => a.split('/')[1].replace('+xml', '').toUpperCase()).join(', ')
  const handle = async (file: File | null) => {
    if (disabled) return
    setError(null); setWarning(null)
    const v = validateImageFile(file, spec)
    if (!v.ok) { setError(v.issues[0]); return }
    setBusy(true)
    try { const asset = await repository.upload(file!, kind); setMeta({ width: asset.width, height: asset.height, bytes: asset.bytes, name: asset.name }); onChange(asset.url); if (file && (file.type !== 'image/svg+xml')) { /* dimension warnings are surfaced by processImage via meta below */ } }
    catch (e) { const code = (e as { code?: ImageIssue['code'] }).code ?? 'decode'; setError({ code, detail: (e as { detail?: string }).detail }) }
    finally { setBusy(false); if (inputRef.current) inputRef.current.value = '' }
  }
  const onDrop = (e: DragEvent) => { e.preventDefault(); setDrag(false); void handle(e.dataTransfer.files?.[0] ?? null) }
  const issueText = (i: ImageIssue) => t(`dash.upload.error.${i.code}`, { types: typeLabel, max: formatBytes(spec.maxBytes, locale), min: `${spec.minWidth} × ${spec.minHeight}`, detail: i.detail ?? '' }, locale)
  return (
    <div className={`db-upload db-upload--${shape} ${compact ? 'db-upload--compact' : ''}`} data-testid={testId}>
      <p className="db-field__label" id={`${id}-label`}>{label}</p>
      <div className={`db-upload__zone ${drag ? 'is-drag' : ''} ${value ? 'has-image' : ''} ${disabled ? 'is-disabled' : ''}`} onDragOver={(e) => { if (!disabled) { e.preventDefault(); setDrag(true) } }} onDragLeave={() => setDrag(false)} onDrop={onDrop} aria-busy={busy}>
        {value ? <img src={value} alt={label} className="db-upload__preview" /> : (
          <button type="button" className="db-upload__empty" onClick={() => inputRef.current?.click()} disabled={disabled || busy} aria-describedby={`${id}-hint`}>
            <span className="db-upload__icon"><Icon name="image" size={26} /></span>
            <b>{busy ? t('dash.upload.uploading', undefined, locale) : t('dash.upload.drop', undefined, locale)}</b>
            <small>{t('dash.upload.browse', undefined, locale)}</small>
          </button>
        )}
        {busy && <span className="db-upload__progress" role="status">{t('dash.upload.uploading', undefined, locale)}</span>}
        {value && !busy && !disabled && (
          <div className="db-upload__actions">
            <button type="button" className="db-btn db-btn--outline db-btn--sm" onClick={() => inputRef.current?.click()} data-testid="upload-replace"><Icon name="edit" size={14} /> {t('dash.upload.replace', undefined, locale)}</button>
            <button type="button" className="db-btn db-btn--danger db-btn--sm" onClick={() => { onChange(null); setMeta(null); setError(null); setWarning(null) }} data-testid="upload-remove"><Icon name="trash" size={14} /> {t('dash.upload.remove', undefined, locale)}</button>
          </div>
        )}
      </div>
      <input ref={inputRef} type="file" accept={accept} className="db-sr-only" aria-labelledby={`${id}-label`} onChange={(e) => { void handle(e.target.files?.[0] ?? null) }} disabled={disabled} data-testid={testId ? `${testId}-input` : undefined} />
      <p id={`${id}-hint`} className="db-field__hint">{hint ?? t('dash.upload.hint', { types: typeLabel, max: formatBytes(spec.maxBytes, locale), size: `${spec.width} × ${spec.height}` }, locale)}</p>
      {meta && <p className="db-upload__meta" data-testid="upload-meta">{meta.name} · {meta.width} × {meta.height} · {formatBytes(meta.bytes, locale)} · {t('dash.upload.optimized', undefined, locale)}</p>}
      {warning && <p className="db-field__hint db-upload__warn" role="status">{issueText(warning)}</p>}
      {error && <p className="db-field__error" role="alert" data-testid="upload-error">{issueText(error)}</p>}
    </div>
  )
}

/** Multi-image gallery uploader: add, preview, reorder (move first / left / right), remove; cap enforced. */
export function GalleryUpload({ value, onChange, label, max = 8, disabled, repository = imageAssets, testId }: { value: string[]; onChange: (v: string[]) => void; label: string; max?: number; disabled?: boolean; repository?: ImageAssetRepository; testId?: string }) {
  const spec = IMAGE_SPECS.gallery; const inputRef = useRef<HTMLInputElement>(null); const id = useId()
  const [busy, setBusy] = useState(false); const [error, setError] = useState<ImageIssue | null>(null); const [drag, setDrag] = useState(false)
  const locale = 'en'; const typeLabel = spec.accept.map((a) => a.split('/')[1].toUpperCase()).join(', ')
  const add = async (files: FileList | null) => {
    if (!files || disabled) return; setError(null)
    const room = max - value.length; if (room <= 0) { setError({ code: 'size', detail: 'max' }); return }
    setBusy(true); const next = [...value]
    try { for (const f of Array.from(files).slice(0, room)) { const v = validateImageFile(f, spec); if (!v.ok) { setError(v.issues[0]); continue } const a = await repository.upload(f, 'gallery'); next.push(a.url) } onChange(next) }
    catch (e) { setError({ code: (e as { code?: ImageIssue['code'] }).code ?? 'decode' }) }
    finally { setBusy(false); if (inputRef.current) inputRef.current.value = '' }
  }
  const move = (i: number, dir: -1 | 1) => { const j = i + dir; if (j < 0 || j >= value.length) return; const n = [...value]; [n[i], n[j]] = [n[j], n[i]]; onChange(n) }
  return (
    <div className="db-upload db-upload--gallery" data-testid={testId}>
      <p className="db-field__label" id={`${id}-label`}>{label} <span className="db-muted" style={{ fontWeight: 500 }}>({value.length}/{max})</span></p>
      <div className="db-gallery">
        {value.map((src, i) => <figure key={src.slice(0, 40) + i} className="db-gallery__item"><img src={src} alt={t('dash.upload.galleryAlt', { n: i + 1 }, locale)} />{!disabled && <figcaption><button type="button" className="db-iconbtn" style={{ width: 30, height: 30 }} aria-label={t('dash.action.moveUp', undefined, locale)} disabled={i === 0} onClick={() => move(i, -1)}><Icon name="back" size={12} /></button><button type="button" className="db-iconbtn" style={{ width: 30, height: 30 }} aria-label={t('dash.action.moveDown', undefined, locale)} disabled={i === value.length - 1} onClick={() => move(i, 1)}><Icon name="chevron" size={12} /></button><button type="button" className="db-iconbtn" style={{ width: 30, height: 30 }} aria-label={t('dash.upload.remove', undefined, locale)} onClick={() => onChange(value.filter((_, k) => k !== i))}><Icon name="trash" size={12} /></button></figcaption>}</figure>)}
        {!disabled && value.length < max && (
          <button type="button" className={`db-upload__zone db-upload__zone--tile ${drag ? 'is-drag' : ''}`} onClick={() => inputRef.current?.click()} onDragOver={(e) => { e.preventDefault(); setDrag(true) }} onDragLeave={() => setDrag(false)} onDrop={(e) => { e.preventDefault(); setDrag(false); void add(e.dataTransfer.files) }} disabled={busy} aria-describedby={`${id}-hint`} data-testid="gallery-add">
            <Icon name="plus" size={20} /><small>{busy ? t('dash.upload.uploading', undefined, locale) : t('dash.upload.addPhotos', undefined, locale)}</small>
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept={spec.accept.join(',')} multiple className="db-sr-only" aria-labelledby={`${id}-label`} onChange={(e) => { void add(e.target.files) }} disabled={disabled} data-testid={testId ? `${testId}-input` : undefined} />
      <p id={`${id}-hint`} className="db-field__hint">{t('dash.upload.hint', { types: typeLabel, max: formatBytes(spec.maxBytes, locale), size: `${spec.width} × ${spec.height}` }, locale)}</p>
      {error && <p className="db-field__error" role="alert" data-testid="upload-error">{error.detail === 'max' ? t('dash.upload.error.max', { max }, locale) : t(`dash.upload.error.${error.code}`, { types: typeLabel, max: formatBytes(spec.maxBytes, locale), min: `${spec.minWidth} × ${spec.minHeight}`, detail: error.detail ?? '' }, locale)}</p>}
    </div>
  )
}

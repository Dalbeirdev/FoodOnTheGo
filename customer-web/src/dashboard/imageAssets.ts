/**
 * Image assets for the Restaurant Dashboard (Module 17). Frontend-first: files are validated, resized and cropped in the
 * browser, then stored as data URLs through ImageAssetRepository (MockImageAssetRepository = localStorage). The backend
 * (ApiImageAssetRepository) will own upload, virus / content validation, storage, compression and CDN delivery later.
 */
export type ImageKind = 'logo' | 'cover' | 'gallery' | 'item' | 'icon' | 'avatar'
export type ImageSpec = {
  kind: ImageKind
  /** Output box; `mode: 'cover'` crops to the exact aspect, `'contain'` keeps the whole image inside the box. */
  width: number; height: number; mode: 'cover' | 'contain'
  /** Minimum source dimensions (soft guidance surfaced as a warning, never a hard block below the absolute floor). */
  minWidth: number; minHeight: number
  maxBytes: number
  accept: string[]
  /** JPEG / WebP quality for raster output; SVG passes through when allowed. */
  quality: number
  allowSvg: boolean
}
export const IMAGE_SPECS: Record<ImageKind, ImageSpec> = {
  logo: { kind: 'logo', width: 512, height: 512, mode: 'contain', minWidth: 256, minHeight: 256, maxBytes: 5 * 1024 * 1024, accept: ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'], quality: 0.9, allowSvg: true },
  cover: { kind: 'cover', width: 1200, height: 400, mode: 'cover', minWidth: 800, minHeight: 267, maxBytes: 8 * 1024 * 1024, accept: ['image/png', 'image/jpeg', 'image/webp'], quality: 0.85, allowSvg: false },
  gallery: { kind: 'gallery', width: 1200, height: 800, mode: 'contain', minWidth: 600, minHeight: 400, maxBytes: 8 * 1024 * 1024, accept: ['image/png', 'image/jpeg', 'image/webp'], quality: 0.85, allowSvg: false },
  item: { kind: 'item', width: 800, height: 800, mode: 'cover', minWidth: 400, minHeight: 400, maxBytes: 8 * 1024 * 1024, accept: ['image/png', 'image/jpeg', 'image/webp'], quality: 0.85, allowSvg: false },
  icon: { kind: 'icon', width: 128, height: 128, mode: 'contain', minWidth: 64, minHeight: 64, maxBytes: 2 * 1024 * 1024, accept: ['image/png', 'image/svg+xml', 'image/webp'], quality: 0.9, allowSvg: true },
  avatar: { kind: 'avatar', width: 256, height: 256, mode: 'cover', minWidth: 128, minHeight: 128, maxBytes: 5 * 1024 * 1024, accept: ['image/png', 'image/jpeg', 'image/webp'], quality: 0.85, allowSvg: false },
}
export type ImageAsset = { id: string; kind: ImageKind; url: string; width: number; height: number; bytes: number; name: string; createdAt: string }
export type ImageIssue = { code: 'type' | 'size' | 'dimensions' | 'decode' | 'storage' | 'empty'; detail?: string }
export type ImageValidation = { ok: boolean; issues: ImageIssue[]; warnings: ImageIssue[] }

/** File-level checks before decoding (type, size). Dimension checks happen after decoding. */
export function validateImageFile(file: File | null, spec: ImageSpec): ImageValidation {
  const issues: ImageIssue[] = []
  if (!file || file.size === 0) return { ok: false, issues: [{ code: 'empty' }], warnings: [] }
  if (!spec.accept.includes(file.type)) issues.push({ code: 'type', detail: file.type || 'unknown' })
  if (file.size > spec.maxBytes) issues.push({ code: 'size', detail: String(file.size) })
  return { ok: issues.length === 0, issues, warnings: [] }
}
export const formatBytes = (n: number, locale = 'en') => (n < 1024 * 1024 ? `${Math.round(n / 1024).toLocaleString(locale)} KB` : `${(n / 1024 / 1024).toLocaleString(locale, { maximumFractionDigits: 1 })} MB`)

const safeContext = (c: HTMLCanvasElement) => { try { return c.getContext('2d') } catch { return null } }
const readAsDataUrl = (file: File) => new Promise<string>((resolve, reject) => { const r = new FileReader(); r.onload = () => resolve(String(r.result)); r.onerror = () => reject(new Error('decode')); r.readAsDataURL(file) })
const loadImage = (src: string) => new Promise<HTMLImageElement>((resolve, reject) => { const img = new Image(); img.onload = () => resolve(img); img.onerror = () => reject(new Error('decode')); img.src = src })

/**
 * Decodes, validates dimensions, then resizes / crops on a canvas to the spec box. SVG passes through untouched when the
 * spec allows it. Environments without canvas (tests) keep the original data URL.
 */
export async function processImage(file: File, spec: ImageSpec): Promise<{ url: string; width: number; height: number; bytes: number; warnings: ImageIssue[] }> {
  const dataUrl = await readAsDataUrl(file)
  if (file.type === 'image/svg+xml') return { url: dataUrl, width: spec.width, height: spec.height, bytes: file.size, warnings: [] }
  // No canvas (jsdom / restricted runtimes): keep the original image unchanged.
  const probe = typeof document !== 'undefined' ? document.createElement('canvas') : null
  if (!probe || typeof probe.getContext !== 'function' || !safeContext(probe)) return { url: dataUrl, width: 0, height: 0, bytes: file.size, warnings: [] }
  let img: HTMLImageElement
  try { img = await loadImage(dataUrl) } catch { throw Object.assign(new Error('decode'), { code: 'decode' }) }
  const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height
  const warnings: ImageIssue[] = []
  if (w && h && (w < spec.minWidth || h < spec.minHeight)) warnings.push({ code: 'dimensions', detail: `${w}×${h}` })
  const canvas = typeof document !== 'undefined' ? document.createElement('canvas') : null
  const ctx = canvas?.getContext?.('2d')
  if (!canvas || !ctx || !w || !h) return { url: dataUrl, width: w || spec.width, height: h || spec.height, bytes: file.size, warnings }
  let tw = spec.width, th = spec.height
  if (spec.mode === 'contain') { const s = Math.min(spec.width / w, spec.height / h, 1); tw = Math.max(1, Math.round(w * s)); th = Math.max(1, Math.round(h * s)) }
  canvas.width = tw; canvas.height = th
  if (spec.mode === 'cover') {
    const s = Math.max(tw / w, th / h); const sw = tw / s, sh = th / s; const sx = (w - sw) / 2, sy = (h - sh) / 2 // centre crop
    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, tw, th)
  } else ctx.drawImage(img, 0, 0, tw, th)
  const type = file.type === 'image/png' && spec.mode === 'contain' ? 'image/png' : 'image/jpeg'
  const out = canvas.toDataURL(type, spec.quality)
  const bytes = Math.round((out.length - out.indexOf(',') - 1) * 0.75)
  return { url: out, width: tw, height: th, bytes, warnings }
}

export interface ImageAssetRepository {
  upload(file: File, kind: ImageKind): Promise<ImageAsset>
  remove(id: string): Promise<void>
}
const KEY = 'fotg.rd.assets.v1'
export class MockImageAssetRepository implements ImageAssetRepository {
  private load(): ImageAsset[] { try { const raw = localStorage.getItem(KEY); return raw ? (JSON.parse(raw) as ImageAsset[]) : [] } catch { return [] } }
  private save(list: ImageAsset[]) { try { localStorage.setItem(KEY, JSON.stringify(list)) } catch { throw Object.assign(new Error('storage'), { code: 'storage' }) } }
  async upload(file: File, kind: ImageKind): Promise<ImageAsset> {
    const spec = IMAGE_SPECS[kind]; const v = validateImageFile(file, spec)
    if (!v.ok) throw Object.assign(new Error(v.issues[0].code), { code: v.issues[0].code, detail: v.issues[0].detail })
    const p = await processImage(file, spec)
    const asset: ImageAsset = { id: `img_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`, kind, url: p.url, width: p.width, height: p.height, bytes: p.bytes, name: file.name, createdAt: new Date().toISOString() }
    const list = this.load(); list.push(asset)
    // Development storage is small: keep the newest 40 assets.
    this.save(list.slice(-40))
    return asset
  }
  async remove(id: string) { this.save(this.load().filter((a) => a.id !== id)) }
}
export const imageAssets: ImageAssetRepository = new MockImageAssetRepository()

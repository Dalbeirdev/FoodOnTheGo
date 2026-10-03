/** A data URL from a file picker becomes a file for a multipart upload (no network, no canvas). */
export function dataUrlToBlob(dataUrl: string): { blob: Blob; name: string } {
  const comma = dataUrl.indexOf(',')
  const meta = dataUrl.slice(5, comma); const payload = dataUrl.slice(comma + 1)
  const mime = meta.split(';')[0] || 'image/jpeg'
  const binary = meta.includes('base64') ? atob(payload) : decodeURIComponent(payload)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  const ext = mime === 'image/png' ? 'png' : mime === 'image/webp' ? 'webp' : 'jpg'
  return { blob: new Blob([bytes], { type: mime }), name: `photo.${ext}` }
}

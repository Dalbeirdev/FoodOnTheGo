import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
/** Share build (VITE_SHARE_BUILD=1): public assets referenced by absolute path become relative so the bundle runs from any base path. */
const shareAssets = { name: 'share-relative-assets', enforce: 'post' as const, transform(code: string, id: string) { if (!/\.(tsx?|jsx?)$/.test(id) || id.includes('node_modules')) return null; const out = code.replace(/(["'`])\/(brand|images)\//g, '$1./$2/'); return out === code ? null : { code: out, map: null } } }

export default defineConfig({
  plugins: [react(), ...(process.env.VITE_SHARE_BUILD === '1' ? [shareAssets] : [])],
  server: {
    host: true,
    port: 5173,
    strictPort: true,
  },
})

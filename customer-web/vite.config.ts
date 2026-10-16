import react from '@vitejs/plugin-react'
import { defineConfig, type Plugin } from 'vite'
import { contentSecurityPolicy, type CspEnv } from './src/security/contentSecurityPolicy.ts'
import { releaseConfigurationProblems } from './src/security/releaseBuild.ts'

// https://vite.dev/config/
/** Share build (VITE_SHARE_BUILD=1): public assets referenced by absolute path become relative so the bundle runs from any base path. */
const shareAssets = { name: 'share-relative-assets', enforce: 'post' as const, transform(code: string, id: string) { if (!/\.(tsx?|jsx?)$/.test(id) || id.includes('node_modules')) return null; const out = code.replace(/(["'`])\/(brand|images)\//g, '$1./$2/'); return out === code ? null : { code: out, map: null } } }

/**
 * Module 39 (C-01): a Content-Security-Policy for PRODUCTION BUILDS only (`vite build`; never the dev server, whose
 * HMR client needs inline code and a WebSocket of its own). The policy is built from the build's own VITE_* settings
 * by src/security/contentSecurityPolicy.ts (which explains every source) and written into index.html as a <meta>
 * right after the charset, before any stylesheet or script is requested.
 *
 * A <meta> policy cannot carry frame-ancestors, reporting, HSTS or the other security headers: the web server that
 * serves the app must send those (Module 42, deployment).
 *
 * The static share build (VITE_SHARE_BUILD=1) is left without it: it is a mock-only preview with no backend that is
 * re-wrapped into a page of its own host (docs/local-review/share-preview.md), where that host's policy applies.
 */
function contentSecurityPolicyPlugin(): Plugin {
  let env: CspEnv = {}
  return {
    name: 'fotg-csp',
    apply: 'build',
    configResolved(config) { env = config.env as CspEnv },
    transformIndexHtml: {
      order: 'post',
      handler(html) {
        if (process.env.VITE_SHARE_BUILD === '1') return html
        const charset = /<meta charset="UTF-8" \/>/i
        // Fail the build rather than ship a page without its policy.
        if (!charset.test(html)) throw new Error('fotg-csp: index.html has no <meta charset="UTF-8" /> to place the Content-Security-Policy after')
        const policy = contentSecurityPolicy(env).replace(/"/g, '&quot;')
        return html.replace(charset, (m) => `${m}\n    <meta http-equiv="Content-Security-Policy" content="${policy}" />`)
      },
    },
  }
}

/**
 * Module 39 (C-03): a production build never carries the backend's local test code. The app already ignores
 * VITE_DEV_OTP in a production build (src/auth/devOtp.ts); this also keeps the value itself out of the bundle when a
 * build is made with a developer's .env.local. (A define of `import.meta.env.X` also replaces X inside the whole
 * `import.meta.env` object that Vite writes into the bundle.)
 */
const withoutDevOtp: Plugin = { name: 'fotg-no-dev-otp', apply: 'build', config: () => ({ define: { 'import.meta.env.VITE_DEV_OTP': JSON.stringify('') } }) }

/**
 * Module 41: a build that would run on development data never comes out (src/security/releaseBuild.ts explains the
 * rules). VITE_ENVIRONMENT must say local, staging or production; staging and production need every domain on the
 * backend, https / wss and no local test code. The share preview (VITE_SHARE_BUILD=1) is mock-only by design.
 */
const releaseGate: Plugin = {
  name: 'fotg-release-gate',
  apply: 'build',
  configResolved(config) {
    const problems = releaseConfigurationProblems({ ...(config.env as Record<string, string>), VITE_SHARE_BUILD: process.env.VITE_SHARE_BUILD })
    if (problems.length) throw new Error(`fotg-release-gate: this build is not configured for its environment:\n  - ${problems.join('\n  - ')}`)
  },
}

export default defineConfig({
  plugins: [react(), releaseGate, contentSecurityPolicyPlugin(), withoutDevOtp,...(process.env.VITE_SHARE_BUILD === '1' ? [shareAssets] : [])],
  server: {
    host: true,
    port: 5173,
    strictPort: true,
  },
})

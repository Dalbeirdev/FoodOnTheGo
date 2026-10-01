// LOCAL DEVELOPMENT ONLY — never deployed.
//
// PHP's built-in server (`php artisan serve`) handles one connection at a time and waits on it until the
// request is complete. A browser's idle "speculative" connection, or a tab closed in the middle of a request,
// therefore makes every other request queue behind it for many seconds. Once every page calls the API
// (Module 22) that shows up as random stalls.
//
// This proxy listens on the public development port, holds the browser connections itself, and hands PHP
// only complete requests, each over its own short-lived connection. Staging / production use a real web server.
//
//   php artisan serve --host=127.0.0.1 --port=8002      (the application)
//   node scripts/local/api-proxy.mjs                    (0.0.0.0:8001 → 127.0.0.1:8002)
import http from 'node:http'

const LISTEN = Number(process.env.API_PROXY_PORT ?? 8001)
const TARGET = Number(process.env.API_UPSTREAM_PORT ?? 8002)
const MAX_BODY = 2 * 1024 * 1024
const unavailable = (res, message) => { if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ error: { code: 'service_unavailable', message } })) }

http.createServer((req, res) => {
  const chunks = []; let size = 0
  req.on('data', (c) => { size += c.length; if (size > MAX_BODY) req.destroy(); else chunks.push(c) })
  req.on('error', () => {})
  // Forward only once the whole request has arrived: PHP never waits on a slow or vanished client.
  req.on('end', () => {
    const body = Buffer.concat(chunks)
    const headers = { ...req.headers, connection: 'close', 'content-length': String(body.length) }
    delete headers['transfer-encoding']
    const upstream = http.request({ host: '127.0.0.1', port: TARGET, method: req.method, path: req.url, headers, agent: false, timeout: 60000 }, (r) => {
      res.writeHead(r.statusCode ?? 502, r.headers)
      r.pipe(res)
    })
    upstream.on('timeout', () => upstream.destroy(new Error('timeout')))
    upstream.on('error', () => unavailable(res, `The local API did not answer (php artisan serve --port=${TARGET}).`))
    res.on('close', () => { if (!res.writableEnded) upstream.destroy() })
    upstream.end(body)
  })
}).listen(LISTEN, '0.0.0.0', () => console.log(`API proxy: http://0.0.0.0:${LISTEN} → http://127.0.0.1:${TARGET}`))

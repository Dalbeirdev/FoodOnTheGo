// Module 40 — the load generator's HTTP client. One connection per request (`Connection: close`, no agent): PHP's built-in
// server handles HTTP keep-alive badly, and a real web server in front of PHP-FPM would also hand PHP one request at a
// time. Requests are spread round-robin over the pool's base URLs. Every request is recorded with its name (the route
// PATTERN, never a URL with values), status, duration and the server's own Server-Timing (app / db time, query count).
import http from 'node:http'

let bases = ['http://127.0.0.1:8002']
let next = 0
export const samples = []
let recording = true

export function setBases(list) { bases = list; next = 0 }
export function record(on) { recording = on }
export function resetSamples() { samples.length = 0 }

function parseServerTiming(h) {
  if (!h) return null
  const app = /app;dur=([\d.]+)/.exec(h)
  const db = /db;dur=([\d.]+);desc="(\d+) queries"/.exec(h)
  return { app_ms: app ? Number(app[1]) : null, db_ms: db ? Number(db[1]) : null, queries: db ? Number(db[2]) : null }
}

/**
 * @param {string} name   a label for statistics, e.g. 'GET /restaurants/{slug}'
 * @returns {Promise<{status:number, json:any, ms:number, timing:any}>}
 */
export function request(name, method, path, { body, headers = {}, timeout = 60000, base } = {}) {
  const target = new URL((base || bases[next++ % bases.length]) + '/api/v1' + path)
  const payload = body === undefined ? null : Buffer.from(JSON.stringify(body))
  const h = { Accept: 'application/json', Connection: 'close', ...headers }
  if (payload) { h['Content-Type'] = 'application/json'; h['Content-Length'] = String(payload.length) }
  const started = process.hrtime.bigint()
  return new Promise((resolve) => {
    const done = (status, text, timingHeader, error) => {
      const ms = Number(process.hrtime.bigint() - started) / 1e6
      let json = null
      try { json = text ? JSON.parse(text) : null } catch { json = null }
      const timing = parseServerTiming(timingHeader)
      if (recording) samples.push({ name, status, ms, t: Date.now(), timing, error: error || null, code: json && json.error ? json.error.code : null })
      resolve({ status, json, ms, timing })
    }
    const req = http.request({ host: target.hostname, port: target.port, path: target.pathname + target.search, method, headers: h, agent: false, timeout }, (res) => {
      const chunks = []
      res.on('data', (c) => chunks.push(c))
      res.on('end', () => done(res.statusCode || 0, Buffer.concat(chunks).toString('utf8'), res.headers['server-timing']))
      res.on('error', (e) => done(0, '', null, 'response: ' + e.code))
    })
    req.on('timeout', () => { req.destroy(new Error('timeout')) })
    req.on('error', (e) => done(0, '', null, e.message === 'timeout' ? 'timeout' : (e.code || e.message)))
    if (payload) req.write(payload)
    req.end()
  })
}

export const get = (name, path, headers) => request(name, 'GET', path, { headers })
export const post = (name, path, body, headers) => request(name, 'POST', path, { body: body ?? {}, headers })

// Module 40 — a debugging aid: starts ONE pool process and prints the raw answer (status, headers, first 600 bytes) of
// one request. LOCAL ONLY.   node tests/performance/debug-probe.mjs METHOD /path ['{"json":"body"}']
import fs from 'node:fs'
import http from 'node:http'
import { startPool, stopPool } from './lib/servers.mjs'

const [method = 'GET', path = '/health', body] = process.argv.slice(2)
const [base] = await startPool({ size: 1, workers: 0 })
const u = new URL(base + '/api/v1' + path)
await new Promise((resolve) => {
  const req = http.request({ host: u.hostname, port: u.port, path: u.pathname + u.search, method, agent: false, headers: { Accept: 'application/json', Connection: 'close', ...(body ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) } : {}) } }, (res) => {
    const chunks = []
    res.on('data', (c) => chunks.push(c))
    res.on('end', () => { const text = Buffer.concat(chunks).toString('utf8'); if (process.env.PROBE_OUT) fs.writeFileSync(process.env.PROBE_OUT, text); console.log(res.statusCode, JSON.stringify(res.headers)); console.log(text.slice(0, 600)); resolve() })
  })
  req.on('error', (e) => { console.log('error', e.message); resolve() })
  if (body) req.write(body)
  req.end()
})
stopPool()

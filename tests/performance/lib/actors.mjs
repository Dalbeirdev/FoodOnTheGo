// Module 40 — the deterministic test accounts of the load tests (perf database only).
//   customers: +91 90000 0xxxx (index 1…n) — created through the real OTP sign-in (development code), named "Perf
//              Customer n"; tokens cached in tests/performance/.state (git-ignored) so a rerun does not spend codes.
//   staff:     the local fixture accounts (restaurant owner / order staff of Riverside, administrators) — their password
//              is LOCAL_FIXTURE_PASSWORD from the git-ignored backend/.env (read, never printed).
import fs from 'node:fs'
import path from 'node:path'
import { post, request } from './http.mjs'
import { PERF_DB } from './servers.mjs'

const ENV_TEXT = fs.readFileSync('E:/TechPio-Data/FoodOnTheGo/backend/.env', 'utf8')
const env = (k) => ((ENV_TEXT.match(new RegExp('^' + k + '=(.*)$', 'm')) || [])[1] || '').trim().replace(/^"|"$/g, '')
const PASSWORD = env('LOCAL_FIXTURE_PASSWORD')
const OTP = env('OTP_DEV_CODE') || '123456'
const STATE = path.resolve('E:/TechPio-Data/FoodOnTheGo/tests/performance/.state')
const STORE = path.join(STATE, `tokens-${PERF_DB}.json`)

const load = () => { try { return JSON.parse(fs.readFileSync(STORE, 'utf8')) } catch { return { customers: {}, staff: {} } } }
const save = (s) => { fs.mkdirSync(STATE, { recursive: true }); fs.writeFileSync(STORE, JSON.stringify(s)) }

export const phoneOf = (i) => '+919000' + String(i).padStart(6, '0')

async function signInCustomer(i) {
  const phone = phoneOf(i)
  const c = await post('auth: POST /auth/customer/otp/request', '/auth/customer/otp/request', { phone, country: 'IN' })
  if (c.status !== 200) throw new Error(`otp request ${phone.slice(-4)} → ${c.status} ${c.json && c.json.error && c.json.error.code}`)
  const v = await post('auth: POST /auth/customer/otp/verify', '/auth/customer/otp/verify', { phone, country: 'IN', challenge_id: c.json.challenge_id, code: OTP, device_name: 'perf' })
  if (v.status !== 200 && v.status !== 201) throw new Error(`otp verify → ${v.status}`)
  const H = { Authorization: 'Bearer ' + v.json.token }
  await request('auth: PATCH /auth/customer/profile', 'PATCH', '/auth/customer/profile', { body: { name: 'Perf Customer ' + i }, headers: H })
  return H
}

async function valid(H) {
  const r = await request('auth: GET /auth/me', 'GET', '/auth/me', { headers: H })
  return r.status === 200
}

/** Headers of customers 1…n (signed in once, then reused while their tokens are valid). */
export async function customers(n, { from = 1, concurrency = 8 } = {}) {
  const s = load()
  const out = new Array(n)
  let next = 0
  const worker = async () => {
    for (;;) {
      const k = next++
      if (k >= n) return
      const i = from + k
      let H = s.customers[i]
      if (!H || !(await valid(H))) { H = await signInCustomer(i); s.customers[i] = H }
      out[k] = H
    }
  }
  await Promise.all(Array.from({ length: concurrency }, worker))
  save(s)
  return out
}

/** Headers of a fixture staff account ('restaurant' or 'admin'). */
export async function staff(kind, email) {
  const s = load()
  const key = kind + ':' + email
  if (s.staff[key] && (await valid(s.staff[key]))) return s.staff[key]
  if (!PASSWORD) throw new Error('LOCAL_FIXTURE_PASSWORD is not set in backend/.env')
  const r = await post(`auth: POST /auth/${kind}/login`, `/auth/${kind}/login`, { email, password: PASSWORD, device_name: 'perf' })
  if (r.status !== 200 || !r.json.token) throw new Error(`${kind} sign-in ${email.split('@')[0]} → ${r.status} ${r.json && r.json.error && r.json.error.code}`)
  s.staff[key] = { Authorization: 'Bearer ' + r.json.token }
  save(s)
  return s.staff[key]
}

export const STAFF = {
  owner: ['restaurant', 'john@riverside.example'],
  orderStaff: ['restaurant', 'mike@riverside.example'],
  superAdmin: ['admin', 'alex.morgan@foodonthego.example'],
  finance: ['admin', 'kenji.watanabe@foodonthego.example'],
  support: ['admin', 'lea.dubois@foodonthego.example'],
  analyst: ['admin', 'nina.patel@foodonthego.example'],
}

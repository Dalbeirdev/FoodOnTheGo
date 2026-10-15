// Module 40 — the synthetic data set of the load tests (perf database ONLY; refuses any database not named *_perf).
//
//   node tests/performance/dataset.mjs templates            real orders already exist (smoke / baseline runs); this adds a
//                                                           few reviews and support cases THROUGH THE API as templates
//   node tests/performance/dataset.mjs grow --tier medium   multiplies the real rows with INSERT … SELECT (sizes below)
//   node tests/performance/dataset.mjs counts               row counts
//
// How the copies stay consistent: before a table is copied, a temporary map (source id, copy number g, new id) takes its
// new ids from the table's own sequence; a child is copied through its parent's map, so every foreign key points at the
// right copy. Unique columns get fresh values (public ids, order numbers in the order-number alphabet, provider
// references, posting keys, dedupe keys). Orders keep their restaurant (Burger Hub — the busiest restaurant of the test)
// and are spread over the synthetic customers and the last 365 days; every copy is a completed order (a rejected copy
// would need its own refund rows - no inconsistent state is invented). Ledger transactions are copied
// with the same copy numbers as the orders, so every allocation points at its own balanced transaction.
// No payment, refund or ledger row is ever CHANGED — only new synthetic rows are added. NO MONEY IS INVOLVED.
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { customers } from './lib/actors.mjs'
import { get, post, setBases } from './lib/http.mjs'
import { psql } from './lib/monitor.mjs'
import { PERF_DB, startPool, stopPool } from './lib/servers.mjs'

if (!/_perf$/.test(PERF_DB)) throw new Error('dataset.mjs only runs on a *_perf database')
const cmd = process.argv[2]
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d }

const TIERS = {
  // copies: per source order (orders = sources × copies); customers; location copies per source location; notifications
  // copies per source notification; analytics events.
  medium: { copies: 1250, customers: 5000, locationCopies: 20, notificationCopies: 200, events: 200000 },
  large: { copies: 12500, customers: 50000, locationCopies: 100, notificationCopies: 2000, events: 2000000 },
}

function runSql(sql, label) {
  const file = path.join(os.tmpdir(), `fotg-perf-${process.pid}.sql`)
  fs.writeFileSync(file, '\\set ON_ERROR_STOP on\nset client_min_messages = warning;\n' + sql)
  const t = Date.now()
  try { const out = psql(`\\i ${file.replace(/\\/g, '/')}`); for (const l of out.split(/\r?\n/).filter((x) => x.startsWith('STEP'))) console.log('   ', l) } finally { fs.rmSync(file, { force: true }) }
  console.log(`  ${label}: ${((Date.now() - t) / 1000).toFixed(1)} s`)
}

/** Column names of a table except `id`, with their max length (text) and type. */
function columns(table) {
  return psql(`select column_name, data_type, coalesce(character_maximum_length, 0) from information_schema.columns where table_schema = 'public' and table_name = '${table}' order by ordinal_position`)
    .split('\n').filter(Boolean).map((l) => { const [name, type, len] = l.split('|'); return { name, type, len: Number(len) } }).filter((c) => c.name !== 'id')
}

/**
 * INSERT INTO table (cols) SELECT <override or s.col> FROM <from> — `from` must expose the source row as `s` and the
 * map columns (`m.dst`, `m.g`) when `withId`.
 */
function copySql(table, from, overrides, withId = true) {
  const cols = columns(table)
  const list = cols.map((c) => `"${c.name}"`)
  const sel = cols.map((c) => (overrides[c.name] !== undefined ? overrides[c.name] : `s."${c.name}"`) + ` as "${c.name}"`)
  return `insert into ${table} (${withId ? 'id, ' : ''}${list.join(', ')}) select ${withId ? 'm.dst, ' : ''}${sel.join(', ')} from ${from};\n`
}

const step = (label) => `select 'STEP', ${"'"}${label}${"'"}, to_char(clock_timestamp(), 'HH24:MI:SS.MS');\n`
const mapSql = (name, table, select) => `drop table if exists ${name}; create unlogged table ${name} as ${select.replace('NEXTVAL', `nextval(pg_get_serial_sequence('${table}', 'id'))`)}; create index on ${name} (src, g); create index on ${name} (dst); analyze ${name};\n`
// A unique text value: the source value shortened, then the new id in hex (fits the column's length).
const uniq = (col, len, idExpr = 'm.dst') => len ? `left(coalesce(s."${col}", ''), greatest(1, ${len} - 14)) || '_' || to_hex(${idExpr})` : `coalesce(s."${col}", '') || '_' || to_hex(${idExpr})`
// An order number FOTG-YYYYMMDD-XXXXXX in the order-number alphabet (no I, O, 0, 1), unique per new id.
const ALPHA = "'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'"
const code6 = (idExpr) => Array.from({ length: 6 }, (_, k) => `substr(${ALPHA}, 1 + ((${idExpr} / ${32 ** (5 - k)}) % 32)::int, 1)`).join(' || ')
const code8 = (idExpr) => Array.from({ length: 8 }, (_, k) => `substr(${ALPHA}, 1 + ((${idExpr} / ${32 ** (7 - k)}::bigint) % 32)::int, 1)`).join(' || ')

async function templates() {
  const bases = await startPool({ size: 1, workers: 1 })
  setBases(bases)
  try {
    const C = await customers(4)
    let reviews = 0; let cases = 0
    for (const H of C) {
      const list = await get('setup', '/customer/orders', H)
      for (const o of (list.json && list.json.data) || []) {
        if (o.status === 'COMPLETED' && reviews < 6) {
          const r = await post('setup', `/customer/orders/${o.order_number}/review`, { overall_rating: 3 + (reviews % 3), comment: 'Load-test review: quick pickup on the highway, food as described.' }, H)
          if (r.status === 201) reviews++
        }
      }
      const c = await post('setup', '/customer/support/cases', { category: 'ORDER', subject: 'Load-test case', message: 'A synthetic support case created for the Module 40 data set.' }, H)
      if (c.status === 201) cases++
    }
    console.log(`templates through the API: ${reviews} reviews, ${cases} support cases`)
  } finally { stopPool() }
}

function grow(tierName) {
  const T = TIERS[tierName]
  if (!T) throw new Error('tier: medium | large')
  const C = T.copies
  if (Number(psql("select count(*) from customers where phone_e164 like '+9196%'"))) throw new Error('synthetic rows exist already: grow a FRESH small-tier copy (restore the perf-small snapshot first)')
  console.log(`growing ${PERF_DB} by tier ${tierName}: ${JSON.stringify(T)}`)
  const custTemplate = psql("select min(id) from customers where phone_e164 like '+919000%'")
  const market = psql("select market_id from restaurant_locations where slug = 'burger-hub'")

  // ---- customers
  let all = step('customers') + mapSql('perf_gen.m_cust', 'customers', `select ${custTemplate}::bigint as src, g, NEXTVAL as dst from generate_series(1, ${T.customers}) g`)
    + copySql('customers', 'perf_gen.m_cust m join customers s on s.id = m.src', { public_id: 'gen_random_uuid()', phone_e164: "'+9196' || lpad(m.dst::text, 8, '0')", name: "'Synthetic Customer ' || m.g", email: 'null', created_at: "now() - (m.g % 365) * interval '1 day'", updated_at: 'now()' })
    + 'create unlogged table perf_gen.t_cust as select row_number() over (order by dst) as n, dst as id from perf_gen.m_cust; create index on perf_gen.t_cust (n);'

  // ---- restaurant locations along the highway corridors, with hours, pickup settings and their menus
  const corridors = "(array[[28.61,77.21,30.73,76.78],[28.61,77.21,26.91,75.79],[28.54,77.39,27.18,78.01],[23.02,72.57,19.08,72.88],[30.73,76.78,32.26,75.64]])"
  const lat = `(${corridors})[1 + (m.dst % 5)][1] + ((${corridors})[1 + (m.dst % 5)][3] - (${corridors})[1 + (m.dst % 5)][1]) * ((m.dst * 7919) % 1000) / 1000.0 + ((m.dst % 7) - 3) * 0.004`
  const lng = `(${corridors})[1 + (m.dst % 5)][2] + ((${corridors})[1 + (m.dst % 5)][4] - (${corridors})[1 + (m.dst % 5)][2]) * ((m.dst * 7919) % 1000) / 1000.0 + ((m.dst % 5) - 2) * 0.004`
  let sql = step('restaurant locations + menus') + mapSql('perf_gen.m_loc', 'restaurant_locations', `select s.id as src, g, NEXTVAL as dst from restaurant_locations s cross join generate_series(1, ${T.locationCopies}) g where s.status = 'APPROVED' and s.slug !~ '-x[0-9]+$'`)
  sql += copySql('restaurant_locations', 'perf_gen.m_loc m join restaurant_locations s on s.id = m.src', { public_id: 'gen_random_uuid()', slug: "left(s.slug, 60) || '-x' || m.dst", name: "left(s.name, 70) || ' ' || m.g", location: `ST_SetSRID(ST_MakePoint(${lng}, ${lat}), 4326)::geography` })
  for (const child of ['restaurant_location_hours', 'restaurant_pickup_settings', 'restaurant_location_pickup_methods', 'restaurant_location_rating_summaries', 'restaurant_location_cuisines', 'restaurant_location_features']) {
    sql += copySql(child, `perf_gen.m_loc m join ${child} s on s.location_id = m.src`, { location_id: 'm.dst', ...(columns(child).some((c) => c.name === 'public_id') ? { public_id: 'gen_random_uuid()' } : {}) }, false)
  }
  sql += mapSql('perf_gen.m_menu', 'menus', 'select s.id as src, m.g, NEXTVAL as dst from menus s join perf_gen.m_loc m on m.src = s.location_id')
  sql += copySql('menus', 'perf_gen.m_menu m join menus s on s.id = m.src join perf_gen.m_loc l on l.src = s.location_id and l.g = m.g', { public_id: 'gen_random_uuid()', location_id: 'l.dst' })
  sql += mapSql('perf_gen.m_cat', 'menu_categories', 'select s.id as src, m.g, NEXTVAL as dst from menu_categories s join perf_gen.m_menu m on m.src = s.menu_id')
  sql += copySql('menu_categories', 'perf_gen.m_cat m join menu_categories s on s.id = m.src join perf_gen.m_menu p on p.src = s.menu_id and p.g = m.g', { public_id: 'gen_random_uuid()', menu_id: 'p.dst' })
  sql += mapSql('perf_gen.m_item', 'menu_items', 'select s.id as src, m.g, NEXTVAL as dst from menu_items s join perf_gen.m_menu m on m.src = s.menu_id')
  sql += copySql('menu_items', 'perf_gen.m_item m join menu_items s on s.id = m.src join perf_gen.m_menu p on p.src = s.menu_id and p.g = m.g left join perf_gen.m_cat c on c.src = s.category_id and c.g = m.g', { public_id: 'gen_random_uuid()', menu_id: 'p.dst', category_id: 'c.dst' })
  sql += copySql('menu_item_dietary_tags', 'perf_gen.m_item m join menu_item_dietary_tags s on s.item_id = m.src', { item_id: 'm.dst' }, false)
  sql += mapSql('perf_gen.m_grp', 'menu_option_groups', 'select s.id as src, m.g, NEXTVAL as dst from menu_option_groups s join perf_gen.m_item m on m.src = s.item_id')
  sql += copySql('menu_option_groups', 'perf_gen.m_grp m join menu_option_groups s on s.id = m.src join perf_gen.m_item p on p.src = s.item_id and p.g = m.g', { public_id: 'gen_random_uuid()', item_id: 'p.dst' })
  sql += mapSql('perf_gen.m_opt', 'menu_options', 'select s.id as src, m.g, NEXTVAL as dst from menu_options s join perf_gen.m_grp m on m.src = s.group_id')
  sql += copySql('menu_options', 'perf_gen.m_opt m join menu_options s on s.id = m.src join perf_gen.m_grp p on p.src = s.group_id and p.g = m.g', { public_id: 'gen_random_uuid()', group_id: 'p.dst' })
  all += sql

  // ---- orders, their items / events / fulfilment / pickup credential / payment attempt / allocation / ledger
  const nCust = `(select count(*) from perf_gen.t_cust)`
  sql = step('orders, payments, items, events, credentials, ledger, allocations') + mapSql('perf_gen.m_ord', 'orders', `select s.id as src, g, NEXTVAL as dst, gen_random_uuid() as checkout_uuid, (now() - ((g * 7 + s.id) % 365) * interval '1 day' - ((g * 13) % 600) * interval '1 minute') as placed from orders s cross join generate_series(1, ${C}) g where s.status = 'COMPLETED'`)
  sql += `drop table if exists perf_gen.m_ordc; create unlogged table perf_gen.m_ordc as select m.*, c.id as customer_id from perf_gen.m_ord m join perf_gen.t_cust c on c.n = 1 + ((m.dst * 31) % ${nCust}); create index on perf_gen.m_ordc (src, g); analyze perf_gen.m_ordc;\n`
  sql += mapSql('perf_gen.m_pay', 'payment_attempts', 'select s.id as src, m.g, NEXTVAL as dst from payment_attempts s join perf_gen.m_ord m on m.src = (select o.id from orders o where o.payment_attempt_id = s.id)')
  const pay = columns('payment_attempts')
  const lenOf = (cols, n) => (cols.find((c) => c.name === n) || {}).len || 0
  sql += copySql('payment_attempts', 'perf_gen.m_pay m join payment_attempts s on s.id = m.src join orders so on so.payment_attempt_id = s.id join perf_gen.m_ordc o on o.src = so.id and o.g = m.g', {
    public_id: 'gen_random_uuid()', checkout_public_id: 'o.checkout_uuid', checkout_session_id: 'null', customer_id: 'o.customer_id',
    provider_order_reference: uniq('provider_order_reference', lenOf(pay, 'provider_order_reference')), provider_payment_reference: uniq('provider_payment_reference', lenOf(pay, 'provider_payment_reference')),
    provider_receipt: uniq('provider_receipt', lenOf(pay, 'provider_receipt')), created_at: 'o.placed', updated_at: 'o.placed',
    ...(pay.some((c) => c.name === 'verified_at') ? { verified_at: "o.placed + interval '1 minute'" } : {}),
  })
  sql += copySql('payment_attempt_events', 'perf_gen.m_pay m join payment_attempt_events s on s.payment_attempt_id = m.src', { payment_attempt_id: 'm.dst', ...(columns('payment_attempt_events').some((c) => c.name === 'public_id') ? { public_id: 'gen_random_uuid()' } : {}) }, false)
  sql += copySql('orders', 'perf_gen.m_ordc m join orders s on s.id = m.src left join perf_gen.m_pay p on p.src = s.payment_attempt_id and p.g = m.g', {
    public_id: 'gen_random_uuid()', order_number: `'FOTG-' || to_char(m.placed, 'YYYYMMDD') || '-' || ${code6('m.dst')}`, checkout_public_id: 'm.checkout_uuid', checkout_session_id: 'null', payment_attempt_id: 'p.dst',
    payment_public_id: '(select pa.public_id from payment_attempts pa where pa.id = p.dst)', pickup_reservation_id: 'null', customer_id: 'm.customer_id', customer_note: 'null',
    placed_at: 'm.placed', accepted_at: "m.placed + interval '2 minutes'", preparing_at: "m.placed + interval '3 minutes'", ready_at: "m.placed + interval '14 minutes'",
    picked_up_at: "m.placed + interval '20 minutes'", completed_at: "m.placed + interval '20 minutes'", pickup_at: "m.placed + interval '20 minutes'", created_at: 'm.placed', updated_at: "m.placed + interval '20 minutes'",
  })
  sql += copySql('order_items', 'perf_gen.m_ord m join order_items s on s.order_id = m.src', { public_id: 'gen_random_uuid()', order_id: 'm.dst' }, false)
  sql += copySql('order_events', 'perf_gen.m_ord m join order_events s on s.order_id = m.src', { order_id: 'm.dst', ...(columns('order_events').some((c) => c.name === 'public_id') ? { public_id: 'gen_random_uuid()' } : {}), created_at: 'm.placed' }, false)
  sql += copySql('order_fulfillments', 'perf_gen.m_ordc m join order_fulfillments s on s.order_id = m.src left join perf_gen.m_pay p on p.src = s.payment_attempt_id and p.g = m.g', { public_id: 'gen_random_uuid()', order_id: 'm.dst', checkout_public_id: 'm.checkout_uuid', customer_id: 'm.customer_id', payment_attempt_id: 'p.dst' }, false)
  sql += copySql('order_pickup_credentials', 'perf_gen.m_ord m join order_pickup_credentials s on s.order_id = m.src', { public_id: 'gen_random_uuid()', order_id: 'm.dst', token_hash: "encode(sha256(convert_to(s.token_hash || ':' || m.dst, 'UTF8')), 'hex')" }, false)
  // ledger: each transaction is copied once per copy of ITS order (an order transaction from the order, a payment
  // transaction from the order the payment paid for), pointing at that copy — source id, public id and reference — and
  // dated like it (the original offset from the order's placement is kept), so the reconciliations see a coherent ledger
  sql += mapSql('perf_gen.m_ltx', 'ledger_transactions', `select s.id as src, m.g, NEXTVAL as dst, m.dst as order_dst, p.dst as pay_dst, m.placed + (s.occurred_at - so.placed_at) as at
    from ledger_transactions s join orders so on (s.source_type = 'order' and so.id = s.source_id) or (s.source_type = 'payment' and so.payment_attempt_id = s.source_id)
    join perf_gen.m_ord m on m.src = so.id left join perf_gen.m_pay p on p.src = so.payment_attempt_id and p.g = m.g`)
  const ltx = columns('ledger_transactions')
  sql += copySql('ledger_transactions', 'perf_gen.m_ltx m join ledger_transactions s on s.id = m.src', { public_id: 'gen_random_uuid()', posting_key: uniq('posting_key', lenOf(ltx, 'posting_key')), reverses_transaction_id: 'null',
    source_id: "case s.source_type when 'order' then m.order_dst else m.pay_dst end",
    source_public_id: "case s.source_type when 'order' then (select o.public_id from orders o where o.id = m.order_dst) else (select pa.public_id from payment_attempts pa where pa.id = m.pay_dst) end",
    source_reference: "case s.source_type when 'order' then (select o.order_number from orders o where o.id = m.order_dst) else (select pa.provider_payment_reference from payment_attempts pa where pa.id = m.pay_dst) end",
    occurred_at: 'm.at', posted_at: 'm.at' })
  sql += copySql('ledger_entries', 'perf_gen.m_ltx m join ledger_entries s on s.ledger_transaction_id = m.src', { ledger_transaction_id: 'm.dst', occurred_at: 'm.at', ...(columns('ledger_entries').some((c) => c.name === 'public_id') ? { public_id: 'gen_random_uuid()' } : {}) }, false)
  sql += copySql('order_financial_allocations', 'perf_gen.m_ord m join order_financial_allocations s on s.order_id = m.src join orders so on so.id = s.order_id left join perf_gen.m_ltx t on t.src = s.ledger_transaction_id and t.g = m.g', { public_id: 'gen_random_uuid()', order_id: 'm.dst', ledger_transaction_id: 't.dst',
    placed_at: 'm.placed', recognized_at: 'm.placed + (s.recognized_at - so.placed_at)', created_at: 'm.placed', updated_at: 'm.placed' }, false)
  // the restaurant's payable line of each recognized order copy (what the ledger verification pairs with the allocation)
  sql += copySql('restaurant_payable_items', `perf_gen.m_ord m join restaurant_payable_items s on s.order_id = m.src and s.type = 'ORDER_EARNING' join orders so on so.id = s.order_id
    left join perf_gen.m_ltx t on t.src = s.ledger_transaction_id and t.g = m.g`, { public_id: 'gen_random_uuid()', source_key: uniq('source_key', lenOf(columns('restaurant_payable_items'), 'source_key')),
    order_id: 'm.dst', order_number: '(select o.order_number from orders o where o.id = m.dst)', allocation_id: '(select a.id from order_financial_allocations a where a.order_id = m.dst)',
    ledger_transaction_id: 't.dst', settlement_id: 'null', settled_at: 'null', status: "case when s.status = 'SETTLED' then 'AVAILABLE' else s.status end",
    available_at: 'm.placed + (s.available_at - so.placed_at)', created_at: 'm.placed', updated_at: 'm.placed' }, false)
  all += sql

  // ---- reviews (one per copied order of a reviewed order), support cases (+ messages, events), notifications
  sql = step('reviews, support cases, notifications, behavioural events') + copySql('restaurant_reviews', 'perf_gen.m_ordc m join restaurant_reviews s on s.order_id = m.src', { public_id: 'gen_random_uuid()', order_id: 'm.dst', customer_id: 'm.customer_id', created_at: "m.placed + interval '1 day'", updated_at: "m.placed + interval '1 day'" }, false)
  sql += mapSql('perf_gen.m_case', 'support_cases', `select s.id as src, g, NEXTVAL as dst from support_cases s cross join generate_series(1, ${Math.round(C / 2)}) g`)
  sql += `drop table if exists perf_gen.m_casec; create unlogged table perf_gen.m_casec as select m.*, c.id as customer_id from perf_gen.m_case m join perf_gen.t_cust c on c.n = 1 + ((m.dst * 17) % ${nCust}); analyze perf_gen.m_casec;\n`
  sql += copySql('support_cases', 'perf_gen.m_casec m join support_cases s on s.id = m.src', { public_id: 'gen_random_uuid()', case_number: `'CS-' || ${code8('m.dst')}`, customer_id: 'm.customer_id', system_key: 'null', duplicate_of_case_id: 'null',
    created_at: "now() - (m.g % 200) * interval '1 day'", updated_at: "now() - (m.g % 200) * interval '1 day'" })
  sql += copySql('support_case_messages', 'perf_gen.m_case m join support_case_messages s on s.case_id = m.src', { public_id: 'gen_random_uuid()', case_id: 'm.dst', client_message_id: 'null' }, false)
  sql += copySql('support_case_events', 'perf_gen.m_case m join support_case_events s on s.case_id = m.src', { case_id: 'm.dst', ...(columns('support_case_events').some((c) => c.name === 'public_id') ? { public_id: 'gen_random_uuid()' } : {}) }, false)
  sql += mapSql('perf_gen.m_not', 'notifications', `select s.id as src, g, NEXTVAL as dst from notifications s cross join generate_series(1, ${T.notificationCopies}) g`)
  sql += copySql('notifications', `perf_gen.m_not m join notifications s on s.id = m.src join perf_gen.t_cust c on c.n = 1 + ((m.dst * 13) % ${nCust})`, { public_id: 'gen_random_uuid()', customer_id: 'c.id', dedupe_key: uniq('dedupe_key', lenOf(columns('notifications'), 'dedupe_key')), created_at: "now() - (m.g % 365) * interval '1 day'" })
  sql += copySql('notification_deliveries', 'perf_gen.m_not m join notification_deliveries s on s.notification_id = m.src', { public_id: 'gen_random_uuid()', notification_id: 'm.dst', customer_device_id: 'null' }, false)
  // behavioural events (Module 38 registry names), spread over the last 180 days and the copied locations
  sql += `insert into analytics_events (event_id, event_name, schema_version, source, platform, occurred_at, received_at, session_id, market_id, location_id, properties)
    select gen_random_uuid(), (array['journey_search_started','journey_results_viewed','restaurant_viewed','menu_viewed','item_added_to_cart','pickup_selected','checkout_started','payment_started'])[1 + (g % 8)], 1, 'CLIENT',
      case when (g / 8) % 3 = 0 then 'ANDROID' else 'WEB' end, now() - ((g / 8) % 180) * interval '1 day' - (g % 600) * interval '1 minute', now(), md5((g / 8)::text)::uuid, ${market},
      loc.a[1 + (g / 8) % 20], '{}' from generate_series(1, ${T.events}) g, (select array_agg(id order by id) as a from (select id from restaurant_locations order by id limit 20) x) loc;\n`
  all += sql
  all += step('analyze') + 'drop schema perf_gen cascade; analyze;'
  runSql('begin; create schema perf_gen;\n' + all + step('end') + 'commit;', `tier ${tierName} (one transaction)`)
}

function counts() {
  const t = ['customers', 'restaurant_locations', 'menus', 'menu_items', 'orders', 'order_items', 'order_events', 'payment_attempts', 'ledger_transactions', 'ledger_entries', 'order_financial_allocations', 'notifications', 'notification_deliveries', 'restaurant_reviews', 'support_cases', 'support_case_messages', 'analytics_events']
  const out = Object.fromEntries(t.map((x) => [x, Number(psql(`select count(*) from ${x}`))]))
  out.database_size_mb = Math.round(Number(psql('select pg_database_size(current_database())')) / 1048576)
  console.log(JSON.stringify(out))
  return out
}

if (cmd === 'templates') await templates()
else if (cmd === 'grow') { grow(arg('tier', 'medium')); counts() } else if (cmd === 'counts') counts()
else console.error('usage: templates | grow --tier medium|large | counts')

"""Generates docs/project-progress.html from docs/tracker/data.json.

Run after every module:  python docs/tracker/build.py
All counts and percentages are computed from the tracked rows — never typed by hand.
"""
import json, os, subprocess, html
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DATA = os.path.join(ROOT, 'docs', 'tracker', 'data.json')
OUT = os.path.join(ROOT, 'docs', 'project-progress.html')
d = json.load(open(DATA, encoding='utf-8'))
e = html.escape

DONE = {'VERIFIED', 'COMPLETE', 'PASSED'}
PROG = {'IN PROGRESS', 'IMPLEMENTED', 'TESTING', 'PARTIAL'}
BADGE = {
    'VERIFIED': 'ok', 'COMPLETE': 'ok', 'PASSED': 'ok', 'PASS': 'ok', 'YES': 'ok', 'APPROVED': 'ok', 'DESIGN APPROVED': 'ok', 'DESIGN APPROVED (SHEET)': 'ok',
    'IMPLEMENTED': 'info', 'IN PROGRESS': 'info', 'TESTING': 'info',
    'PARTIAL': 'warn', 'PENDING': 'warn', 'PENDING USER DEVICE VERIFICATION': 'warn', 'PARTIAL (LAYOUT)': 'warn', 'DRAFT TEXT': 'warn', 'NOT RUN': 'warn',
    'FAILED': 'bad', 'BLOCKED': 'bad', 'NO': 'bad',
    'DEFERRED': 'muted', 'NOT STARTED': 'muted', 'NOT APPLICABLE': 'muted', 'N/A': 'muted', '-': 'muted',
}
def badge(v):
    v = str(v)
    key = v.upper()
    cls = BADGE.get(key, 'ok' if key.startswith('FIXED') or key.startswith('VERIFIED') else 'muted')
    return '<span class="b b-%s">%s</span>' % (cls, e(v))

def git_commit():
    try:
        return subprocess.check_output(['git', 'rev-parse', '--short', 'HEAD'], cwd=ROOT, stderr=subprocess.DEVNULL).decode().strip()
    except Exception:
        return 'No git repository yet (CF-013)'

def counts(rows, key='status'):
    c = {'total': len(rows), 'completed': 0, 'in_progress': 0, 'pending': 0, 'failed': 0, 'blocked': 0, 'deferred': 0}
    for r in rows:
        s = (r[key] if isinstance(r, dict) else r).upper()
        if s in DONE: c['completed'] += 1
        elif s in PROG: c['in_progress'] += 1
        elif s == 'FAILED': c['failed'] += 1
        elif s == 'BLOCKED': c['blocked'] += 1
        elif s == 'DEFERRED': c['deferred'] += 1
        else: c['pending'] += 1
    c['pct'] = round(100 * c['completed'] / c['total']) if c['total'] else 0
    return c

reqs = d['requirements']
by_cat = lambda cats, plats=None: [r for r in reqs if r['cat'] in cats and (plats is None or r['platform'] in plats)]
web_pages = [p for p in d['pages'] if p['design'] != 'PENDING']
web_page_done = [p for p in web_pages if p['manual'] == 'VERIFIED' and p['design'] not in ('PARTIAL (layout)',)]

cards = [
    ('Requirements', counts(reqs)),
    ('UI/UX Design', counts([{'status': 'VERIFIED' if p['design'].startswith(('APPROVED', 'DESIGN APPROVED')) else ('PARTIAL' if p['design'].startswith('PARTIAL') else 'PENDING')} for p in d['pages']])),
    ('Web Frontend', counts([{'status': 'VERIFIED' if p['manual'] == 'VERIFIED' and not p['design'].startswith('PARTIAL') else ('PARTIAL' if p['manual'] == 'VERIFIED' else 'NOT STARTED')} for p in d['pages']])),
    ('Android Frontend', counts(d['android'])),
    ('Backend', counts([{'status': st} for _, st in d['backendAreas']])),
    ('Database', counts([{'status': st} for _, st in d['databaseItems']])),
    ('API Integration', counts([{'status': p['backend']} for p in d['pages'] if p['backend'] != 'NOT APPLICABLE'])),
    ('External Integrations', counts([{'status': 'NOT STARTED'} for _ in ('Maps/Places', 'Routing provider', 'Razorpay TEST', 'OTP/SMS', 'Firebase FCM')])),
    ('Testing', counts([{'status': 'VERIFIED' if t[5] == 'PASS' else 'PENDING'} for t in d['tests']])),
    ('Security', counts([{'status': st} for _, st in d['securityItems']])),
    ('Production Readiness', counts([{'status': 'BLOCKED'} for _ in ('Web', 'Android', 'Backend', 'Overall')])),
]
web_pct = cards[2][1]['pct']; android_pct = cards[3][1]['pct']

def table(headers, rows, badge_cols=()):
    h = ''.join('<th>%s</th>' % e(x) for x in headers)
    body = ''
    for r in rows:
        body += '<tr>' + ''.join('<td>%s</td>' % (badge(c) if i in badge_cols else e(str(c))) for i, c in enumerate(r)) + '</tr>'
    return '<div class="tw"><table><thead><tr>%s</tr></thead><tbody>%s</tbody></table></div>' % (h, body)

def module_summary(m):
    rows = [r for r in reqs if r['module'] == m['id']]
    c = counts(rows)
    plat = lambda p: counts([r for r in rows if r['platform'] in p])
    status = 'NOT STARTED' if not rows and not m['started'] else ('COMPLETE' if c['total'] and c['completed'] == c['total'] else ('IN PROGRESS' if m['started'] else 'NOT STARTED'))
    return status, c, plat

now = datetime.now().strftime('%d %b %Y, %H:%M')
parts = []
parts.append('''<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FoodOnTheGo — Master Project Status</title>
<style>
:root{--ink:#101827;--muted:#667085;--line:#e6e8ee;--bg:#f6f7fa;--o:#FF6A00;--r:#FF2538;--g:#1d8f3f;--a:#b45309;--bl:#2f7cf6;--card:#fff}
*{box-sizing:border-box}html,body{overflow-x:hidden}body{margin:0;font-family:Outfit,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:var(--bg)}
a{color:var(--o)}
.top{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:10}
.top-in{max-width:1400px;margin:0 auto;padding:12px 24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.top img{height:44px}.top h1{margin:0;font-size:18px;letter-spacing:.5px}.top h1 span{background:linear-gradient(90deg,var(--o),var(--r));-webkit-background-clip:text;background-clip:text;color:transparent}
.nav{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap}.nav a{font-size:12px;font-weight:600;color:var(--muted);padding:6px 10px;border-radius:8px;text-decoration:none}.nav a:hover{background:var(--bg);color:var(--ink)}
main{max-width:1400px;margin:0 auto;padding:24px}
h2{font-size:20px;margin:36px 0 12px;display:flex;align-items:center;gap:10px}h2 i{width:8px;height:22px;border-radius:4px;background:linear-gradient(180deg,var(--o),var(--r));display:inline-block}
.card{min-width:0;overflow-wrap:anywhere;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 20px;box-shadow:0 6px 24px rgba(16,24,39,.05)}
.grid{display:grid;gap:14px}.grid>*{min-width:0}.g2{grid-template-columns:repeat(2,1fr)}.g3{grid-template-columns:repeat(3,1fr)}.g4{grid-template-columns:repeat(4,1fr)}.g6{grid-template-columns:repeat(6,1fr)}
.kv{display:flex;flex-direction:column;gap:2px;min-width:0}.kv small{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}.kv b{font-size:15px;overflow-wrap:anywhere}
.pc{display:flex;flex-direction:column;gap:8px}.pc .t{font-weight:700;font-size:14px}.pc .n{font-size:26px;font-weight:800}
.bar{height:8px;border-radius:8px;background:#eceef3;overflow:hidden}.bar i{display:block;height:100%;background:linear-gradient(90deg,var(--o),var(--r))}
.mini{display:flex;flex-wrap:wrap;gap:6px;font-size:11px;color:var(--muted)}.mini span b{color:var(--ink)}
.b{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;letter-spacing:.2px}
.b-ok{background:#e6f7ea;color:var(--g)}.b-info{background:#e9f1ff;color:var(--bl)}.b-warn{background:#fff3e0;color:var(--a)}.b-bad{background:#ffe9e9;color:#e5261f}.b-muted{background:#eef0f4;color:var(--muted)}
.tw{overflow:auto;border:1px solid var(--line);border-radius:12px;background:#fff}table{width:100%;border-collapse:collapse;font-size:12.5px}th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{background:#f9fafc;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);white-space:nowrap;position:sticky;top:0}tr:last-child td{border-bottom:0}
.banner{padding:14px 18px;border-radius:12px;font-weight:800;font-size:15px}.ban-ok{background:#e6f7ea;color:var(--g)}.ban-bad{background:#ffe9e9;color:#e5261f}.ban-warn{background:#fff3e0;color:var(--a)}
.small{font-size:12px;color:var(--muted)}ul{margin:6px 0 0;padding-left:18px}li{margin:3px 0}
.shots{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.shots a{display:block;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff;text-decoration:none;color:var(--ink)}.shots img{width:100%;height:120px;object-fit:cover;object-position:top;display:block}.shots span{display:block;padding:6px 8px;font-size:11px}
@media(max-width:1000px){.g4,.g6{grid-template-columns:repeat(2,1fr)}.g3{grid-template-columns:1fr}}@media(max-width:600px){.g2,.g4,.g6{grid-template-columns:1fr}main{padding:14px}.top h1{font-size:14px;white-space:normal}.top-in{gap:10px;padding:10px 14px}.top img{height:34px}.mini{font-size:10px}}
</style></head><body>
<div class="top"><div class="top-in"><img src="../customer-web/public/brand/foodonthego-logo-no-tagline.svg" alt="FoodOnTheGo"><h1>FOODONTHEGO — <span>MASTER PROJECT STATUS</span></h1>
<nav class="nav"><a href="#summary">Summary</a><a href="#progress">Progress</a><a href="#modules">Modules</a><a href="#detail">Module detail</a><a href="#web">Web</a><a href="#android">Android</a><a href="#backend">Backend</a><a href="#decisions">Decisions</a><a href="#carry">Carry-forward</a><a href="#bugs">Bugs</a><a href="#tests">Tests</a><a href="#artifacts">Artifacts</a><a href="#apk">APK</a><a href="#assets">Assets</a><a href="#prod">Production</a><a href="#closing">Closing</a></nav></div></div>
<main>''')

# ---- summary
parts.append('<h2 id="summary"><i></i>Top summary</h2><div class="card grid g4">')
for k, v in [('Project', d['project']), ('Current phase', d['phase']), ('Current module', d['currentModule']), ('Environment', d['environment']),
             ('Production deployment', badge(d['production'])), ('Web', '%s · %d%%' % (badge('IN PROGRESS' if web_pct < 100 else 'COMPLETE'), web_pct)), ('Android', '%s · %d%%' % (badge('NOT STARTED' if android_pct == 0 else 'IN PROGRESS'), android_pct)), ('iOS', badge('DEFERRED') + ' — ' + e(d['ios'])),
             ('Backend', e(d['backendPhase'])), ('Database', e(d['database'])), ('Cache', e(d['cache'])), ('Last updated', e(now)), ('Git commit', e(git_commit()))]:
    parts.append('<div class="kv"><small>%s</small><b>%s</b></div>' % (e(k), v if v.startswith('<') or '<span' in v else v))
parts.append('</div>')

# ---- progress cards
parts.append('<h2 id="progress"><i></i>Overall progress</h2><div class="grid g4">')
for name, c in cards:
    parts.append('<div class="card pc"><div class="t">%s</div><div class="n">%d%%</div><div class="bar"><i style="width:%d%%"></i></div><div class="mini"><span>Total <b>%d</b></span><span>Done <b>%d</b></span><span>In progress <b>%d</b></span><span>Pending <b>%d</b></span><span>Failed <b>%d</b></span><span>Blocked <b>%d</b></span><span>Deferred <b>%d</b></span></div></div>' % (e(name), c['pct'], c['pct'], c['total'], c['completed'], c['in_progress'], c['pending'], c['failed'], c['blocked'], c['deferred']))
parts.append('</div><p class="small">Percentages = completed ÷ total tracked items per area. "Done" = VERIFIED/COMPLETE only. Production Readiness stays 0% while any blocker exists.</p>')

# ---- module summary
parts.append('<h2 id="modules"><i></i>Module progress</h2><div class="grid g2">')
for m in d['modules']:
    status, c, plat = module_summary(m)
    parts.append('<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><b>%s</b>%s</div><div class="bar" style="margin:10px 0"><i style="width:%d%%"></i></div><div class="grid g3" style="gap:8px">' % (e(m['name']), badge(status), c['pct']))
    for k, v in [('Completion', '%d%% (%d/%d)' % (c['pct'], c['completed'], c['total'])), ('Started', m['started'] or '—'), ('Completed', m['completed'] or '—'),
                 ('Web', '%d/%d' % (plat({'WEB'})['completed'], plat({'WEB'})['total'])), ('Android', '%d/%d' % (plat({'ANDROID'})['completed'], plat({'ANDROID'})['total'])), ('Backend', '%d/%d' % (plat({'BACKEND', 'DATABASE'})['completed'], plat({'BACKEND', 'DATABASE'})['total']))]:
        parts.append('<div class="kv"><small>%s</small><b>%s</b></div>' % (k, e(v)))
    parts.append('</div>%s</div>' % ('<p class="small">%s</p>' % e(m['scope']) if m['scope'] else ''))
parts.append('</div>')

# ---- module detail
parts.append('<h2 id="detail"><i></i>Module detail</h2>')
for m in d['modules']:
    rows = [r for r in reqs if r['module'] == m['id']]
    if not rows: continue
    parts.append('<h3 style="margin:18px 0 8px">%s</h3>' % e(m['name']))
    parts.append(table(['ID', 'Requirement', 'Platform', 'Category', 'Priority', 'Status', 'Implementation', 'Testing', 'Manual verification', 'Evidence', 'Issue / blocker', 'Carry forward to', 'Notes'],
                       [[r['id'], r['req'], r['platform'], r['cat'], r['pri'], r['status'], r['impl'], r['test'], r['manual'], r['evidence'], r['issue'], r['carry'], r['notes']] for r in rows], badge_cols=(5, 6, 7, 8)))

# ---- web pages
parts.append('<h2 id="web"><i></i>Web application — page tracking</h2>')
parts.append(table(['Page', 'URL', 'Design approved', 'Frontend implemented', 'Responsive', 'Interactions', 'Mock data', 'Testing', 'Manual review', 'Backend connected', 'Production ready', 'Notes'],
                   [[p['page'], p['url'], p['design'], p['impl'], p['resp'], p['inter'], p['mock'], p['test'], p['manual'], p['backend'], p['prod'], p['notes']] for p in d['pages']], badge_cols=(2, 3, 4, 5, 6, 7, 8, 9, 10)))
shots = [p for p in d['pages'] if p.get('shot')]
seen = set(); parts.append('<h3 style="margin:18px 0 8px">Live local view evidence (LOCAL environment)</h3><div class="shots">')
for p in shots:
    if p['shot'] in seen: continue
    seen.add(p['shot'])
    for v in ('desktop', 'mobile'):
        rel = 'local-review/screenshots/web/%s-%s.png' % (p['shot'], v)
        if os.path.exists(os.path.join(ROOT, 'docs', rel)):
            parts.append('<a href="%s" target="_blank"><img src="%s" alt="" loading="lazy"><span>%s · %s</span></a>' % (rel, rel, e(p['page']), v))
parts.append('</div>')

# ---- android
parts.append('<h2 id="android"><i></i>Android application</h2>')
parts.append(table(['Screen', 'Design', 'Flutter UI', 'Navigation', 'Mobile behaviour', 'Mock data', 'Testing', 'APK included', 'Manual mobile verification', 'Backend integration', 'Status'],
                   [[a['screen'], a['design'], a['ui'], a['nav'], a['resp'], a['mock'], a['test'], a['apk'], a['manual'], a['backend'], a['status']] for a in d['android']], badge_cols=(2, 7, 8, 9, 10)))
parts.append('<div class="card" style="margin-top:12px"><b>iOS application:</b> %s. No iOS tasks affect completion percentages.</div>' % badge('DEFERRED UNTIL WEB + ANDROID ARE COMPLETE'))

# ---- backend
parts.append('<h2 id="backend"><i></i>Backend</h2><div class="card"><p class="banner ban-warn" style="margin:0 0 12px">BACKEND PHASE = NOT STARTED — WAITING FOR FRONTEND APPROVAL</p><p class="small">Module 00 bootstrapped the local environment only (Laravel skeleton, /api/health, PostgreSQL/PostGIS, Redis). Feature areas are tracked below and will move to IN PROGRESS from the module in which they start.</p>')
parts.append(table(['Backend area', 'Status'], d['backendAreas'], badge_cols=(1,)) + '<h3 style="margin:14px 0 6px">Database readiness</h3>' + table(['Item', 'Status'], d['databaseItems'], badge_cols=(1,)) + '<h3 style="margin:14px 0 6px">Security</h3>' + table(['Item', 'Status'], d['securityItems'], badge_cols=(1,)))
parts.append('<h3 style="margin:14px 0 6px">Database architecture decision (permanent)</h3><ul><li><b>Primary database:</b> PostgreSQL + PostGIS</li><li><b>Reason:</b> FoodOnTheGo is highly geospatial and needs efficient restaurant-to-route proximity/corridor queries at large scale.</li><li><b>Redis:</b> caching, queues, rate limits, locks and temporary state only.</li><li>Do not switch to MySQL/MariaDB unless explicitly approved later.</li></ul></div>')

# ---- decisions
parts.append('<h2 id="decisions"><i></i>Project decisions (permanent)</h2>' + table(['Date', 'Decision'], d['decisions']))
# ---- carry forward
parts.append('<h2 id="carry"><i></i>Pending / carry-forward register</h2>' + table(['ID', 'Task', 'Original module', 'Reason pending', 'Priority', 'Owner', 'Target module', 'Current status', 'Last updated', 'Resolution'], d['carry'], badge_cols=(7,)))
# ---- bugs
parts.append('<h2 id="bugs"><i></i>Bug register</h2>' + table(['Bug ID', 'Module', 'Platform', 'Screen', 'Severity', 'Description', 'Expected', 'Actual', 'Root cause', 'Fix', 'Retest', 'Status'], d['bugs'], badge_cols=(10, 11)))
# ---- tests
parts.append('<h2 id="tests"><i></i>Test evidence</h2>' + table(['Test ID', 'Module', 'Platform', 'Feature', 'Test type', 'Result', 'Evidence', 'Date'], d['tests'], badge_cols=(5,)))
# ---- artifacts
parts.append('<h2 id="artifacts"><i></i>Screenshot / artifact index</h2>' + table(['Artifact', 'Module', 'Platform', 'Path', 'Purpose', 'Verified'], d['artifacts'], badge_cols=(5,)))
# ---- apk
parts.append('<h2 id="apk"><i></i>APK tracking</h2>')
parts.append(table(['APK name', 'Version', 'Build', 'Environment', 'API environment', 'Git commit', 'Build date', 'SHA-256', 'Install tested', 'Manual user tested', 'Status'], d['apks'], badge_cols=(8, 9, 10)) if d['apks'] else '<div class="card">%s No APK has been produced yet (CF-006 → CF-009).</div>' % badge('NOT STARTED'))
# ---- assets
pa = d['pendingAssets']
parts.append('<h2 id="assets"><i></i>Pending assets — placeholder images (production blocker)</h2><div class="card"><p class="small">Owner decision 25 Sep 2026: temporary DineFlow/generic photos are development placeholders only; emoji fallbacks are development-only and never final. An image-dependent page is not production-ready until its final asset replaces the placeholder. Paths under <code>customer-web/public/images/</code>.</p>')
parts.append(table(['Asset', 'Used on', 'Current state', 'Final asset'], [[a, b, c, 'PENDING'] for a, b, c in pa['rows']], badge_cols=(3,)))
parts.append('<p class="small">Totals: %d slots · %d temporary photos · %d emoji/gradient fallbacks · <b>%d final assets</b>.</p><p class="banner ban-warn" style="margin-top:10px">LEGAL CONTENT = DRAFT / PENDING FINAL BUSINESS &amp; LEGAL APPROVAL — /terms, /privacy, /refund-policy, /cookie-policy show a draft banner; placeholder wording must never be published as final policy.</p></div>' % (pa['total'], pa['temp'], pa['fallback'], pa['final']))
# ---- production
parts.append('<h2 id="prod"><i></i>Production readiness</h2><div class="grid g4">' + ''.join('<div class="card kv"><small>%s</small><b>%s</b></div>' % (k, badge('NO')) for k in ('WEB PRODUCTION READY', 'ANDROID PRODUCTION READY', 'BACKEND PRODUCTION READY', 'OVERALL PRODUCTION READY')) + '</div><p class="small">Blocked until: all critical requirements VERIFIED, final images (CF-001), final legal text (CF-002), Android APK manually verified (CF-009), backend modules complete, security review passed, explicit owner deployment approval.</p>')

# ---- closing summary for current module
cur = next(m for m in d['modules'] if m['id'] == d['currentModule'].replace('Module ', 'M'))
status, c, plat = module_summary(cur)
cf_count = sum(1 for r in d['carry'] if r[2] == cur['id'] and r[7].upper() not in ('RESOLVED', 'DONE'))
complete = c['completed'] == c['total'] and cf_count == 0
blockers = [r['id'] + ' ' + r['req'] for r in reqs if r['module'] == cur['id'] and r['status'].upper() not in DONE]
parts.append('<h2 id="closing"><i></i>Module completion summary — %s</h2><div class="card"><div class="grid g4">' % e(cur['name']))
for k, v in [('Total requirements', c['total']), ('Complete / verified', c['completed']), ('Pending', c['pending']), ('Failed', c['failed']), ('Blocked', c['blocked']), ('Deferred', c['deferred']), ('In progress / partial', c['in_progress']), ('Carry-forward count', cf_count)]:
    parts.append('<div class="kv"><small>%s</small><b>%s</b></div>' % (k, v))
parts.append('</div><p class="banner %s" style="margin-top:14px">MODULE STATUS: %s</p><p class="banner %s">NEXT MODULE MAY START: %s</p>' % ('ban-ok' if complete else 'ban-bad', 'COMPLETE' if complete else 'NOT COMPLETE', 'ban-ok' if complete else 'ban-bad', 'YES' if complete else 'NO'))
if not complete:
    parts.append('<p><b>Blockers:</b></p><ul>' + ''.join('<li>%s</li>' % e(b) for b in blockers) + '</ul>')
parts.append('</div><p class="small" style="margin-top:24px">Generated %s from docs/tracker/data.json by docs/tracker/build.py. History is never deleted: FAILED, BLOCKED, PENDING and DEFERRED items persist until resolved with evidence.</p></main></body></html>' % e(now))

open(OUT, 'w', encoding='utf-8').write(''.join(parts))
print('wrote', OUT, '| web %d%% android %d%% | %s' % (web_pct, android_pct, 'COMPLETE' if complete else 'NOT COMPLETE'))

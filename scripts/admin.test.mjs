import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { execFileSync, spawn } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { createServer } from 'node:net'
import { mkdtempSync, readFileSync, writeFileSync, readdirSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

// All writes target an isolated temp directory. No Docker or database required.
const cwd = fileURLToPath(new URL('../', import.meta.url))
const storage = mkdtempSync(join(tmpdir(), 'aura-csv-test-'))
const env = { ...process.env, AURA_STORAGE_DIR: storage, APP_ENV: 'local', SESSION_COOKIE_SECURE: 'false', DB_HOST: 'database-must-not-be-used.invalid' }
const password = randomBytes(16).toString('hex')
const email = 'test@example.invalid'
const php = (args, input) => execFileSync('php', args, { cwd, env, input, encoding: 'utf8' })
const training = id => ({ id, title: 'Formation ' + id, sectorId: 'bureau', description: '', occupationDescription: '', topics: [], prerequisites: [], duration: '', schedule: '', extraInfo: '', locations: [], foremUrl: '', photo: '', photoAlt: '', photoCredit: '', sourceUrl: 'https://www.leforem.be/catalogue-des-formations', verifiedAt: null, contentStatus: 'pending', shortage: { indicated: false, source: 'fixture', verifiedAt: null } })
const catalog = { kind: 'forem-training-catalog', schemaVersion: 2, sectors: [{ id: 'bureau', label: 'Bureau', icon: '💼', color: '#436d68' }], trainings: [training('simple'), { ...training('groupe'), variants: [training('variante-a'), training('variante-b')] }] }
const csvFor = value => php(['-r', 'require "src/bootstrap.php"; echo Aura\\Api\\Catalog\\Csv::export(json_decode(stream_get_contents(STDIN),true));'], JSON.stringify(value))
const records = csv => JSON.parse(php(['-r', 'require "src/bootstrap.php"; echo json_encode(Aura\\Api\\Catalog\\Csv::parse(stream_get_contents(STDIN))["records"]);'], csv)).map(row => row.cells)
const csvText = rows => '\uFEFF' + rows.map(row => row.map(value => '"' + value.replaceAll('"', '""') + '"').join(';')).join('\r\n') + '\r\n'
const state = () => JSON.parse(readFileSync(join(storage, 'catalogue.json'), 'utf8'))
let server, base, session, second
function client() {
  let cookie = '', csrf = ''
  return {
    async call(path, method = 'GET', body, headers = {}) {
      const multipart = body instanceof FormData
      if (multipart && !body.has('_csrf')) body.set('_csrf', csrf)
      const form = body && !multipart && typeof body !== 'string' ? new URLSearchParams({ _csrf: csrf, ...body }) : body
      const response = await fetch(base + path, { method, redirect: 'manual', headers: { ...(cookie ? { Cookie: cookie } : {}), ...headers }, ...(form === undefined ? {} : { body: form }) })
      if (response.headers.get('set-cookie')) cookie = response.headers.get('set-cookie').split(';')[0]
      const text = Buffer.from(await response.arrayBuffer()).toString('utf8')
      const token = text.match(/name="_csrf" value="([a-f0-9]+)"/)
      if (token) csrf = token[1]
      const payload = response.headers.get('content-type')?.includes('application/json') ? JSON.parse(text) : null
      return { status: response.status, headers: response.headers, payload, text, cookie, csrf }
    },
    async login() { await this.call('/admin/login'); const result = await this.call('/admin/login', 'POST', { email, password }); if (result.status === 303) await this.call('/admin'); return result },
  }
}
async function upload(csv, { revision, name = 'formations.csv', confirmation = 'REMPLACER', actor = session } = {}) {
  revision ??= current().revision
  const form = new FormData()
  form.set('catalogue', new Blob([csv], { type: 'text/csv' }), name)
  form.set('revision', String(revision))
  form.set('confirmation', confirmation)
  return actor.call('/admin/import', 'POST', form)
}
const current = () => JSON.parse(php(['scripts/catalog-admin.php', 'status']))
const publicCatalog = async () => (await client().call('/api/v1/catalog')).payload

before(async () => {
  php(['scripts/catalog-admin.php', 'initialize'], JSON.stringify(catalog))
  php(['scripts/catalog-admin.php', 'create-user', email], password)
  const port = await new Promise(resolve => { const socket = createServer(); socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)) }) })
  base = 'http://127.0.0.1:' + port
  server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'public', 'public/index.php'], { cwd, env, stdio: 'ignore' })
  for (let i = 0; i < 50; i++) { try { await fetch(base + '/admin/login'); break } catch { if (i === 49) throw Error('PHP did not start'); await new Promise(resolve => setTimeout(resolve, 50)) } }
  session = client(); second = client()
})
after(async () => {
  if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)) }
  assert.match(storage, /aura-csv-test-[a-zA-Z0-9]+$/)
  rmSync(storage, { recursive: true, force: true })
})

test('public catalogue and health work without any database connection', async () => {
  const result = await client().call('/api/v1/catalog')
  assert.equal(result.status, 200)
  assert.equal(result.payload.trainings.length, 2)
  assert.equal(result.payload.trainings[0].orientationStatus, undefined)
  assert.equal(result.headers.get('cache-control'), 'no-store')
  assert.equal((await client().call('/api/v1/health')).payload.data.storage, 'csv')
  assert.equal(result.headers.get('set-cookie'), null)
  assert.equal(result.headers.get('access-control-allow-credentials'), null)
  assert.equal(result.payload.active, undefined)
  assert.equal(result.payload.users, undefined)
})
test('anonymous access cannot read private metadata, export, upload or restore', async () => {
  const anonymous = client()
  for (const [path, method, body] of [['/admin', 'GET'], ['/admin/export', 'GET'], ['/admin/import', 'POST', {}], ['/admin/restore', 'POST', {}]]) {
    const result = await anonymous.call(path, method, body)
    assert.equal(result.status, 303)
    assert.equal(result.headers.get('location'), '/admin/login')
  }
  for (const path of ['/api/v1/admin/session', '/api/v1/admin/catalog', '/api/v1/admin/login']) assert.equal((await anonymous.call(path)).status, 410)
})
test('session bootstrap and login use secure session conventions and CSRF', async () => {
  const bootstrap = await session.call('/admin/login')
  assert.match(bootstrap.headers.get('set-cookie'), /HttpOnly/i)
  assert.match(bootstrap.headers.get('set-cookie'), /SameSite=Lax/i)
  assert.match(bootstrap.headers.get('set-cookie'), /path=\/admin;/i)
  assert.ok(bootstrap.csrf.length >= 32)
  assert.match(bootstrap.text, /<form action="\/admin\/login"/)
  assert.doesNotMatch(bootstrap.text, /<script|localStorage|sessionStorage/i)
  assert.equal((await session.call('/admin/login', 'POST', { email, password, _csrf: '' }, { 'X-CSRF-Token': bootstrap.csrf })).status, 403)
  assert.equal((await session.call('/admin/login', 'POST', { email, password: 'incorrect' })).status, 401)
  const logged = await session.call('/admin/login', 'POST', { email, password })
  assert.equal(logged.status, 303)
  assert.equal(logged.headers.get('location'), '/admin')
  assert.notEqual(logged.cookie, bootstrap.cookie)
  const dashboard = await session.call('/admin')
  assert.notEqual(dashboard.csrf, bootstrap.csrf)
  assert.ok(dashboard.text.includes(email))
  assert.match(dashboard.headers.get('content-security-policy'), /default-src 'none'/)
  assert.equal(dashboard.headers.get('x-frame-options'), 'DENY')
  assert.match(dashboard.headers.get('cache-control'), /no-store/)
})
test('initial dashboard has one active CSV and no previous version or full editor', async () => {
  const value = await current()
  assert.equal(value.revision, 1)
  assert.equal(value.active.entries, 2)
  assert.equal(value.active.formations, 3)
  assert.equal(value.previous, null)
  assert.equal(value.catalog, undefined)
  assert.equal(value.notes, undefined)
  assert.equal((await session.call('/admin/restore', 'POST', { revision: 1, confirmation: 'REVENIR' })).status, 409)
  assert.equal((await session.call('/admin/trainings/simple')).status, 404)
  assert.equal((await session.call('/admin', 'PATCH', {})).status, 405)
})
test('reinitialization refuses to overwrite the current CSV', () => {
  const old = state()
  assert.throws(() => php(['scripts/catalog-admin.php', 'initialize'], JSON.stringify(catalog)), /CSV existe déjà/)
  assert.deepEqual(state(), old)
})
test('private account hashes, metadata and CSV files are never exposed as static routes', async () => {
  const accounts = readFileSync(join(storage, 'accounts.json'), 'utf8')
  assert.ok(!accounts.includes(password))
  for (const path of ['/../storage/accounts.json', '/storage/accounts.json', '/storage/catalogue.json', '/storage/' + state().active.file]) assert.equal((await client().call(path)).status, 404)
  for (const path of ['/api/v1/jobs', '/api/v1/sectors', '/api/v1/jobs/old']) assert.equal((await client().call(path)).status, 410)
})
test('export contains the complete CSV including group parents, variants, and no account data', async () => {
  const result = await session.call('/admin/export')
  assert.equal(result.status, 200)
  assert.match(result.headers.get('content-type'), /text\/csv/)
  assert.match(result.headers.get('content-disposition'), /attachment; filename="aura-catalogue-r1.csv"/)
  assert.equal(result.text.charCodeAt(0), 0xfeff)
  assert.equal(records(result.text).length, 5)
  assert.equal(records(result.text)[0].length, 33)
  assert.ok(!result.text.includes(password) && !result.text.includes(email))
})
test('identical upload is a no-op and does not replace the backup', async () => {
  const before = state()
  const csv = (await session.call('/admin/export')).text
  const result = await upload(csv)
  assert.equal(result.status, 303)
  assert.equal(result.headers.get('location'), '/admin')
  assert.match((await session.call('/admin')).text, /correspond déjà au catalogue actif/)
  assert.doesNotMatch((await session.call('/admin')).text, /correspond déjà au catalogue actif/)
  assert.deepEqual(state(), before)
  assert.equal(readdirSync(storage).filter(name => name.endsWith('.csv')).length, 1)
})
test('a full replacement removes omitted rows, persists to CSV, and keeps the exact previous file', async () => {
  const before = state()
  const item = { ...training('simple'), title: 'Électricité ; "atelier"', description: 'Une description\nsur deux lignes', topics: ['Un objectif\nmultiligne'], photo: 'https://example.com/photo.jpg', photoAlt: '', photoCredit: '' }
  const result = await upload(csvFor({ ...catalog, trainings: [item] }))
  assert.equal(result.status, 303, result.text)
  assert.equal(current().active.entries, 1)
  assert.equal(current().previous.sha256, before.active.sha256)
  assert.equal(current().revision, 2)
  assert.equal((await publicCatalog()).trainings[0].title, item.title)
  assert.deepEqual((await publicCatalog()).trainings[0].topics, item.topics)
  const persisted = JSON.parse(php(['-r', 'require "src/bootstrap.php"; echo json_encode((new Aura\\Api\\Repository\\FileCatalogRepository())->current());']))
  assert.equal(persisted.trainings.length, 1)
  assert.equal(readdirSync(storage).filter(name => name.endsWith('.csv')).length, 2)
})
test('a second replacement retains only the immediately previous CSV', async () => {
  const before = state()
  const result = await upload(csvFor({ ...catalog, trainings: [{ ...training('simple'), description: 'Version suivante' }] }))
  assert.equal(result.status, 303)
  assert.equal(current().previous.sha256, before.active.sha256)
  assert.equal(readdirSync(storage).filter(name => name.endsWith('.csv')).length, 2)
  assert.ok(!readdirSync(storage).includes(before.previous.file))
})
test('stale revisions, missing confirmation, foreign origins and absent CSRF never overwrite files', async () => {
  const before = state()
  const csv = csvFor(catalog)
  assert.equal((await upload(csv, { revision: 1 })).status, 409)
  assert.equal((await upload(csv, { confirmation: '' })).status, 422)
  assert.equal((await session.call('/admin/restore', 'POST', { revision: before.revision, confirmation: 'REVENIR' }, { Origin: 'https://attacker.invalid' })).status, 403)
  assert.equal((await session.call('/admin/restore', 'POST', { revision: before.revision, confirmation: 'REVENIR', _csrf: '' })).status, 403)
  assert.equal((await session.call('/admin/restore', 'POST', { revision: before.revision, confirmation: 'REVENIR' }, { 'Sec-Fetch-Site': 'cross-site' })).status, 403)
  assert.deepEqual(state(), before)
})
test('empty, malformed, unrelated, oversized and wrong-type files preserve both current and previous CSV', async () => {
  const before = state()
  const exported = (await session.call('/admin/export')).text
  for (const csv of ['', '"unclosed', 'a;b\n1;2', csvText([records(exported)[0]]), 'x'.repeat(1048577)]) assert.equal((await upload(csv)).status, 422)
  assert.equal((await upload(exported, { name: 'formations.xlsx' })).status, 422)
  assert.equal((await session.call('/admin/import', 'POST', {})).status, 415)
  assert.deepEqual(state(), before)
})
test('technical CSV errors are refused but incomplete formation details are allowed', async () => {
  const before = state()
  const good = records(csvFor(catalog))
  for (const [header, value] of [['secteur_id', 'inconnu'], ['formation_id', '../evil'], ['lien_formation_forem', 'javascript:alert(1)']]) {
    const copy = structuredClone(good); copy[1][copy[0].indexOf(header)] = value
    assert.equal((await upload(csvText(copy))).status, 422)
  }
  const duplicate = structuredClone(good); duplicate[2] = [...duplicate[1]]; duplicate[2][duplicate[0].indexOf('description')] = 'Conflicting repeated row'
  assert.equal((await upload(csvText(duplicate))).status, 422)
  assert.deepEqual(state(), before)
  const incomplete = { ...training('nouvelle'), sourceUrl: '', contentStatus: 'ready' }
  const result = await upload(csvFor({ ...catalog, trainings: [incomplete] }))
  assert.equal(result.status, 303, result.text)
  const active = (await publicCatalog()).trainings[0]
  assert.equal(active.description, '')
  assert.equal(active.contentStatus, 'partial')
  assert.equal(active.sourceUrl, '')
  assert.equal(active.orientationStatus, 'pending')
})
test('restore swaps complete versions, requires confirmation, and increments the revision', async () => {
  const before = state()
  assert.equal((await session.call('/admin/restore', 'POST', { revision: before.revision, confirmation: '' })).status, 422)
  const restored = await session.call('/admin/restore', 'POST', { revision: before.revision, confirmation: 'REVENIR' })
  assert.equal(restored.status, 303)
  assert.equal(current().active.sha256, before.previous.sha256)
  assert.equal(current().previous.sha256, before.active.sha256)
  assert.equal(current().revision, before.revision + 1)
  assert.equal((await publicCatalog()).trainings[0].description, 'Version suivante')
  assert.equal((await session.call('/admin/restore', 'POST', { revision: before.revision, confirmation: 'REVENIR' })).status, 409)
  assert.equal(readdirSync(storage).filter(name => name.endsWith('.csv')).length, 2)
})
test('corrupted backups cannot replace a valid active CSV', async () => {
  const before = state()
  const path = join(storage, before.previous.file)
  const original = readFileSync(path)
  writeFileSync(path, 'corrupted')
  const result = await session.call('/admin/restore', 'POST', { revision: before.revision, confirmation: 'REVENIR' })
  assert.equal(result.status, 500)
  assert.deepEqual(state(), before)
  assert.equal((await client().call('/api/v1/catalog')).status, 200)
  writeFileSync(path, original)
})
test('legacy 22-column files preserve known photos and reconstruct grouped fiches', async () => {
  await upload(csvFor(catalog))
  const rows = records(csvFor(catalog))
  const legacy = rows.map(row => row.slice(0, 22)).filter((row, index) => index === 0 || row[0] !== 'groupe' || row[18] !== '')
  const result = await upload(csvText(legacy))
  assert.equal(result.status, 303, result.text)
  assert.equal((await publicCatalog()).trainings.find(item => item.id === 'groupe').variants.length, 2)
})
test('encodings, comma separators, plain text lists and formula-like titles round-trip', async () => {
  const value = { ...catalog, trainings: [{ ...training('simple'), title: '=1+1', topics: ['A', 'B'] }] }
  const csv = csvFor(value)
  assert.ok(csv.includes("'=1+1"))
  const rows = records(csv)
  rows[1][rows[0].indexOf('contenu')] = 'Théorie\nAtelier'
  const comma = rows.map(row => row.map(cell => '"' + cell.replaceAll('"', '""') + '"').join(',')).join('\r\n')
  const utf16 = Buffer.concat([Buffer.from([0xff, 0xfe]), Buffer.from(comma, 'utf16le')])
  assert.equal((await upload(utf16)).status, 303)
  assert.equal((await publicCatalog()).trainings[0].title, '=1+1')
  assert.deepEqual((await publicCatalog()).trainings[0].topics, ['Théorie', 'Atelier'])
})
test('changed group composition is excluded from orientation until calibration', async () => {
  await upload(csvFor(catalog))
  const modified = structuredClone(catalog); modified.trainings[1].variants.push(training('variante-c'))
  assert.equal((await upload(csvFor(modified))).status, 303)
  const group = (await publicCatalog()).trainings.find(item => item.id === 'groupe')
  assert.equal(group.orientationStatus, 'pending')
  assert.equal(group.variants[2].orientationStatus, 'pending')
})
test('independent sessions detect concurrent changes rather than silently overwriting', async () => {
  assert.equal((await second.login()).status, 303)
  const previous = current()
  await upload(csvFor(catalog))
  const response = await upload(csvFor(catalog), { revision: previous.revision, actor: second })
  assert.equal(response.status, 409)
})
test('dashboard and restore are plain HTML forms; GET and refresh never change the catalogue', async () => {
  const before = state()
  const dashboard = await session.call('/admin')
  assert.match(dashboard.text, /action="\/admin\/import" method="post" enctype="multipart\/form-data"/)
  assert.match(dashboard.text, /name="revision" value="\d+"/)
  assert.match(dashboard.text, /href="\/admin" aria-label="Accueil de l’administration AURA"/)
  assert.doesNotMatch(dashboard.text, /<script|localhost:5173|127\.0\.0\.1:5173|localStorage/)
  const confirmation = await session.call('/admin/restore')
  assert.equal(confirmation.status, 200)
  assert.match(confirmation.text, /value="REVENIR" required/)
  for (const path of ['/admin/logout', '/admin/import']) assert.equal((await session.call(path)).status, 405)
  await session.call('/admin')
  assert.deepEqual(state(), before)
  const css = await client().call('/admin/assets/style.css')
  assert.equal(css.status, 200)
  assert.match(css.headers.get('content-type'), /text\/css/)
  assert.match(css.text, /@media/)
  assert.match(css.text, /overflow-wrap/)
})
test('uploaded filenames and failed login values are escaped, passwords are never redisplayed', async () => {
  const filename = '<img src=x onerror=alert(1)>.csv'
  const value = { ...catalog, trainings: [{ ...training('simple'), description: 'Affichage sûr' }] }
  assert.equal((await upload(csvFor(value), { name: filename })).status, 303)
  const dashboard = await session.call('/admin')
  assert.ok(dashboard.text.includes('&lt;img src=x onerror=alert(1)&gt;.csv'))
  assert.ok(!dashboard.text.includes(filename))
  const actor = client(); await actor.call('/admin/login')
  const result = await actor.call('/admin/login', 'POST', { email: '\"><img src=x>', password: 'never-show-this-password' })
  assert.equal(result.status, 401)
  assert.doesNotMatch(result.text, /never-show-this-password|<img src=x>/)
})
test('account disable revokes existing sessions and logout revokes the active one', async () => {
  await session.call('/admin/logout', 'POST', {})
  assert.equal((await session.call('/admin')).status, 303)
  php(['scripts/catalog-admin.php', 'disable-user', email])
  assert.equal((await second.call('/admin')).status, 303)
  await second.call('/admin/login')
  assert.equal((await second.call('/admin/login', 'POST', { email, password })).status, 401)
})
test('login rate limiting persists across different browser sessions without SQL', async () => {
  let limited = false
  for (let i = 0; i < 12; i++) {
    const actor = client(); await actor.call('/admin/login')
    const result = await actor.call('/admin/login', 'POST', { email: 'missing@example.invalid', password: 'incorrect' })
    if (result.status === 429) { limited = true; break }
    assert.equal(result.status, 401)
  }
  assert.equal(limited, true)
})
test('the storage directory cannot be placed under the public document root', () => {
  assert.throws(() => execFileSync('php', ['-r', 'require "src/bootstrap.php"; new Aura\\Api\\Storage\\PrivateFiles();'], { cwd, env: { ...env, AURA_STORAGE_DIR: join(cwd, 'public') }, stdio: 'pipe' }), error => (String(error.stdout) + String(error.stderr)).includes('hors du dossier public'))
})

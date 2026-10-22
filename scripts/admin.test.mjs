import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { execFileSync, spawn } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { createServer } from 'node:net'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

// Real HTTP/PHP/MariaDB tests in an isolated, disposable database, never `aura`.
const cwd = fileURLToPath(new URL('../', import.meta.url))
const database = `aura_admin_test_${randomBytes(6).toString('hex')}`
const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', DB_DATABASE: database, SESSION_COOKIE_SECURE: 'false' }
const password = randomBytes(20).toString('hex')
const email = 'test@example.invalid'
const php = (args, input) => execFileSync('php', args, { cwd, env, input, encoding: 'utf8' })
const sql = query => execFileSync('docker', ['compose', 'exec', '-T', 'database', 'sh', '-c', 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'], { cwd, input: query, encoding: 'utf8' })
const training = id => ({ id, sectorId: 'bureau', title: `Formation ${id}`, occupationDescription: '', description: '', topics: [], prerequisites: [], duration: '', schedule: '', extraInfo: '', locations: [], foremUrl: '', videoUrl: '', photo: '', photoAlt: '', photoCredit: '', sourceUrl: 'https://www.leforem.be/catalogue-des-formations', contentStatus: 'pending', verifiedAt: null, shortage: { indicated: false, source: 'Test', verifiedAt: null } })
const catalog = { kind: 'forem-training-catalog', schemaVersion: 2, sectors: [{ id: 'bureau', label: 'Bureau', icon: '💼', color: '#436d68' }, { id: 'technique', label: 'Technique', icon: '🔧', color: '#8bbbd0' }], trainings: [training('simple'), { ...training('groupe'), variants: [training('variante-a'), training('variante-b')] }] }
let server
let base
let session
let second
let databaseCreated = false
const current = async () => (await session.call('/admin/catalog')).payload
const csvFor = catalog => php(['-r', 'require "src/bootstrap.php"; echo Aura\\Api\\Catalog\\Csv::export(json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR));'], JSON.stringify(catalog))
const csvRecords = csv => JSON.parse(php(['-r', 'require "src/bootstrap.php"; echo json_encode(Aura\\Api\\Catalog\\Csv::parse(stream_get_contents(STDIN))["records"], JSON_THROW_ON_ERROR);'], csv)).map(record => record.cells)
const csvText = records => '\uFEFF' + records.map(row => row.map(value => '"' + String(value).replaceAll('"', '""') + '"').join(';')).join('\r\n') + '\r\n'
async function previewCsv(csv, mode = 'merge', revision) {
  return session.call('/admin/catalog/import/preview', 'POST', { csv, mode, revision: revision ?? (await current()).revision })
}
async function publishCsv(csv, mode = 'replace') {
  const preview = await previewCsv(csv, mode)
  assert.equal(preview.status, 200, JSON.stringify(preview.payload))
  const { token, revision, diff } = preview.payload
  const result = await session.call('/admin/catalog/import/commit', 'POST', { token, revision, confirmation: diff.removed.length ? `SUPPRIMER ${diff.removed.length}` : '' })
  assert.equal(result.status, 200, JSON.stringify(result.payload))
  return result.payload
}

function client() {
  let cookie = ''
  let csrf = ''
  return {
    async call(path, method = 'GET', body, headers = {}) {
      const response = await fetch(base + path, { method, headers: { ...(cookie ? { Cookie: cookie } : {}), ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }), ...headers }, ...(body === undefined ? {} : { body: typeof body === 'string' ? body : JSON.stringify(body) }) })
      const setCookie = response.headers.get('set-cookie')
      if (setCookie) cookie = setCookie.split(';')[0]
      const payload = await response.json()
      if (payload.csrfToken) csrf = payload.csrfToken
      return { status: response.status, headers: response.headers, payload, cookie }
    },
  }
}

before(async () => {
  sql(`CREATE DATABASE \`${database}\` CHARACTER SET utf8mb4; GRANT ALL ON \`${database}\`.* TO 'aura'@'%';`)
  databaseCreated = true
  php(['scripts/catalog-admin.php', 'migrate'])
  php(['scripts/catalog-admin.php', 'initialize'], JSON.stringify(catalog))
  php(['scripts/catalog-admin.php', 'create-user', email], password)
  const port = await new Promise(resolve => { const listener = createServer(); listener.listen(0, '127.0.0.1', () => { const port = listener.address().port; listener.close(() => resolve(port)) }) })
  base = `http://127.0.0.1:${port}/api/v1`
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd, env, stdio: 'ignore' })
  // No blind sleep: wait for the process to actually announce a reachable socket.
  for (let attempt = 0; attempt < 50; attempt++) {
    try { await fetch(base + '/admin/session'); break } catch { if (attempt === 49) throw new Error('PHP server did not start'); await new Promise(resolve => setTimeout(resolve, 50)) }
  }
  session = client()
  second = client()
})

after(async () => {
  if (server && server.exitCode === null) { server.kill('SIGTERM'); await new Promise(resolve => server.once('exit', resolve)) }
  // Only this generated test database is eligible for cleanup.
  assert.match(database, /^aura_admin_test_[a-f0-9]{12}$/)
  if (databaseCreated) sql(`REVOKE ALL PRIVILEGES ON \`${database}\`.* FROM 'aura'@'%'; DROP DATABASE IF EXISTS \`${database}\`;`)
})

test('public catalogue serves the v2 contract without credentials or internal notes', async () => {
  const result = await client().call('/catalog')
  assert.equal(result.status, 200)
  assert.deepEqual(result.payload, catalog)
  assert.equal(result.headers.get('cache-control'), 'no-store')
})

test('anonymous reads and writes are denied on the server', async () => {
  assert.equal((await client().call('/admin/catalog')).status, 401)
  assert.equal((await client().call('/admin/trainings/simple', 'PATCH', { revision: 1, changes: { title: 'X' }, note: '' })).status, 401)
})

test('login bootstrap sets HttpOnly/SameSite and requires a CSRF token', async () => {
  const result = await session.call('/admin/session')
  assert.equal(result.payload.user, null)
  assert.match(result.headers.get('set-cookie'), /HttpOnly/i)
  assert.match(result.headers.get('set-cookie'), /SameSite=Lax/i)
  assert.match(result.headers.get('set-cookie'), /path=\/api\/v1\/admin/i)
  assert.equal((await session.call('/admin/login', 'POST', { email, password }, { 'X-CSRF-Token': '' })).status, 403)
})

test('login errors do not distinguish a missing, disabled or wrong-password account', async () => {
  const missing = await session.call('/admin/login', 'POST', { email: 'missing@example.invalid', password })
  const wrong = await session.call('/admin/login', 'POST', { email, password: 'wrong' })
  assert.equal(missing.status, 401)
  assert.deepEqual(missing.payload, wrong.payload)
})

test('valid login rotates the session ID and grants the authorized catalogue', async () => {
  const previous = await session.call('/admin/session')
  const logged = await session.call('/admin/login', 'POST', { email, password })
  assert.equal(logged.status, 200)
  assert.notEqual(previous.cookie, logged.cookie)
  assert.notEqual(previous.payload.csrfToken, logged.payload.csrfToken)
  assert.equal(logged.payload.user.email, email)
  const current = await session.call('/admin/catalog')
  assert.equal(current.payload.revision, 1)
  assert.deepEqual(current.payload.catalog, catalog)
  await second.call('/admin/session')
  assert.equal((await second.call('/admin/login', 'POST', { email, password })).status, 200)
})

test('write protection rejects foreign origins, absent CSRF and non-JSON bodies', async () => {
  const update = { revision: 1, changes: { title: 'Essai' }, note: '' }
  assert.equal((await session.call('/admin/trainings/simple', 'PATCH', update, { Origin: 'https://attacker.example' })).status, 403)
  assert.equal((await session.call('/admin/trainings/simple', 'PATCH', update, { 'X-CSRF-Token': 'forged' })).status, 403)
  assert.equal((await session.call('/admin/trainings/simple', 'PATCH', update, { 'Content-Type': 'text/plain' })).status, 415)
  assert.equal((await session.call('/admin/trainings/simple', 'PATCH', '{bad json')).status, 400)
})

test('the server rejects unsafe URLs, read-only fields, incomplete ready status and bad dates', async () => {
  for (const changes of [{ foremUrl: 'javascript:alert(1)' }, { foremUrl: 'https://leforem.be.attacker.example/' }, { videoUrl: 'https://example.com/not-youtube' }, { photo: 'http://example.com/image.jpg' }, { id: 'changed-id' }, { sectorId: 'changed-sector' }, { variants: [] }, { contentStatus: 'ready' }, { verifiedAt: '2026-02-30' }, { topics: 'not-an-array' }]) {
    const result = await session.call('/admin/trainings/simple', 'PATCH', { revision: 1, changes, note: '' })
    assert.equal(result.status, 422, JSON.stringify(changes))
  }
  assert.equal((await session.call('/admin/catalog')).payload.revision, 1)
})

test('saving persists across sessions, versions the catalogue and keeps notes private', async () => {
  const changes = { title: 'Formation complétée', description: 'Programme documenté', topics: ['Objectif 1'], contentStatus: 'partial', locations: [{ city: 'Verviers', address: 'Adresse de test', accessUrl: '' }] }
  const result = await session.call('/admin/trainings/simple', 'PATCH', { revision: 1, changes, note: 'Information interne à confirmer' })
  assert.equal(result.status, 200)
  assert.equal(result.payload.revision, 2)
  assert.equal(result.payload.catalog.trainings[0].title, changes.title)
  assert.equal(result.payload.notes.simple, 'Information interne à confirmer')
  assert.equal((await second.call('/admin/catalog')).payload.revision, 2)
  const publicResult = await client().call('/catalog')
  assert.equal(publicResult.payload.trainings[0].title, changes.title)
  assert.ok(!JSON.stringify(publicResult.payload).includes('Information interne'))
  assert.equal(publicResult.payload.updatedBy, undefined)
})

test('stale edits return 409 and cannot overwrite another employee’s changes', async () => {
  const result = await second.call('/admin/trainings/simple', 'PATCH', { revision: 1, changes: { title: 'Obsolete' }, note: '' })
  assert.equal(result.status, 409)
  assert.equal((await session.call('/admin/catalog')).payload.catalog.trainings[0].title, 'Formation complétée')
})

test('variants update independently; validated status requires real place details', async () => {
  const invalid = { description: 'Programme', foremUrl: 'https://www.leforem.be/catalogue-des-formations', verifiedAt: '2026-10-22', contentStatus: 'ready', locations: [{ city: '', address: '', accessUrl: '' }] }
  assert.equal((await session.call('/admin/trainings/variante-a', 'PATCH', { revision: 2, changes: invalid, note: '' })).status, 422)
  const result = await session.call('/admin/trainings/variante-a', 'PATCH', { revision: 2, changes: { ...invalid, locations: [{ city: 'Verviers', address: 'Adresse de test', accessUrl: '' }] }, note: 'Note variante' })
  assert.equal(result.status, 200)
  assert.equal(result.payload.revision, 3)
  assert.equal(result.payload.catalog.trainings[1].variants[0].contentStatus, 'ready')
  assert.equal(result.payload.catalog.trainings[1].variants[1].contentStatus, 'pending')
  assert.equal(result.payload.catalog.trainings[1].title, catalog.trainings[1].title)
})

test('unknown training IDs and repeated initialization never replace existing data', async () => {
  assert.equal((await session.call('/admin/trainings/missing', 'PATCH', { revision: 3, changes: { title: 'X' }, note: '' })).status, 404)
  assert.throws(() => php(['scripts/catalog-admin.php', 'initialize'], JSON.stringify(catalog)))
  assert.equal((await session.call('/admin/catalog')).payload.revision, 3)
  const count = sql(`SELECT COUNT(*) FROM \`${database}\`.training_catalog_versions;`).trim().split('\n').at(-1)
  assert.equal(count, '3')
})

test('CRUD and CSV endpoints reject anonymous access and missing CSRF', async () => {
  for (const [path, method] of [['/admin/trainings', 'POST'], ['/admin/trainings/simple', 'DELETE'], ['/admin/catalog/export', 'GET'], ['/admin/catalog/import/preview', 'POST'], ['/admin/catalog/import/commit', 'POST']]) assert.equal((await client().call(path, method, method === 'GET' ? undefined : {})).status, 401)
  assert.equal((await session.call('/admin/trainings', 'POST', {}, { 'X-CSRF-Token': '' })).status, 403)
  assert.equal((await session.call('/admin/catalog/import/commit', 'POST', {}, { 'X-CSRF-Token': '' })).status, 403)
})

test('new formations are persistent, uniquely identified and excluded from uncalibrated recommendations', async () => {
  const revision = (await current()).revision
  const result = await session.call('/admin/trainings', 'POST', { revision, id: 'nouvelle-formation', parentId: null, changes: { title: 'Nouvelle formation', sectorId: 'bureau', sourceUrl: 'https://www.leforem.be/catalogue-des-formations' }, note: 'Note privée nouvelle' })
  assert.equal(result.status, 201)
  const added = result.payload.catalog.trainings.find(item => item.id === 'nouvelle-formation')
  assert.equal(added.orientationStatus, 'pending')
  assert.equal(added.contentStatus, 'pending')
  assert.equal(added.verifiedAt, null)
  assert.equal(result.payload.notes['nouvelle-formation'], 'Note privée nouvelle')
  assert.equal((await session.call('/admin/trainings', 'POST', { revision: result.payload.revision, id: 'nouvelle-formation', parentId: null, changes: { title: 'Doublon' }, note: '' })).status, 409)
  for (const id of ['', '../escape', 'UPPERCASE']) assert.equal((await session.call('/admin/trainings', 'POST', { revision: result.payload.revision, id, parentId: null, changes: { title: 'X' }, note: '' })).status, 422)
  assert.equal((await session.call('/admin/trainings', 'POST', { revision, id: 'stale', parentId: null, changes: { title: 'Périmée' }, note: '' })).status, 409)
})

test('variants can be added only to an existing compatible group', async () => {
  const revision = (await current()).revision
  assert.equal((await session.call('/admin/trainings', 'POST', { revision, id: 'invalid-parent', parentId: 'simple', changes: { title: 'X', sectorId: 'bureau', sourceUrl: 'https://www.leforem.be/' }, note: '' })).status, 422)
  const result = await session.call('/admin/trainings', 'POST', { revision, id: 'variante-c', parentId: 'groupe', changes: { title: 'Variante C', sectorId: 'bureau', sourceUrl: 'https://www.leforem.be/' }, note: '' })
  assert.equal(result.status, 201)
  assert.equal(result.payload.catalog.trainings.find(item => item.id === 'groupe').variants.length, 3)
  assert.equal(result.payload.catalog.trainings.find(item => item.id === 'groupe').orientationStatus, 'pending')
  assert.equal(result.payload.catalog.trainings.find(item => item.id === 'groupe').variants[2].orientationStatus, 'pending')
})

test('deletion previews do not mutate and deletion requires the exact confirmed impact', async () => {
  const fixture = structuredClone(catalog)
  fixture.trainings[1].variants.push(training('variante-c'))
  const proposal = JSON.parse(php(['-r', 'require "src/bootstrap.php"; echo json_encode(Aura\\Api\\Catalog\\Training::removal(json_decode(stream_get_contents(STDIN), true), "variante-c"), JSON_THROW_ON_ERROR);'], JSON.stringify(fixture)))
  assert.ok(proposal.diff.updated.find(item => item.id === 'groupe').changes.some(change => change.field === 'orientationStatus' && change.after === 'pending'))
  const before = await current()
  const preview = await session.call('/admin/trainings/variante-c/delete-preview', 'POST', { revision: before.revision })
  assert.equal(preview.status, 200)
  assert.equal(preview.payload.diff.removed.length, 1)
  assert.equal((await current()).revision, before.revision)
  assert.equal((await session.call('/admin/trainings/variante-c', 'DELETE', { revision: before.revision, confirmation: 'wrong', removedCount: 1 })).status, 422)
  assert.equal((await session.call('/admin/trainings/variante-c', 'DELETE', { revision: before.revision, confirmation: 'variante-c', removedCount: 2 })).status, 409)
  const removed = await session.call('/admin/trainings/variante-c', 'DELETE', { revision: before.revision, confirmation: 'variante-c', removedCount: 1 })
  assert.equal(removed.status, 200)
  assert.equal(removed.payload.catalog.trainings.find(item => item.id === 'groupe').variants.length, 2)
  assert.equal(removed.payload.notes['variante-c'], undefined)
})

test('removing one of two variants promotes, rather than deletes, the survivor', async () => {
  const before = await current()
  const preview = await session.call('/admin/trainings/variante-a/delete-preview', 'POST', { revision: before.revision })
  assert.equal(preview.status, 200)
  assert.deepEqual(preview.payload.diff.removed.map(item => item.id).sort(), ['groupe', 'variante-a'])
  assert.equal(preview.payload.promotedId, 'variante-b')
  const removed = await session.call('/admin/trainings/variante-a', 'DELETE', { revision: before.revision, confirmation: 'variante-a', removedCount: 2 })
  assert.equal(removed.status, 200)
  assert.equal(removed.payload.catalog.trainings.find(item => item.id === 'variante-b').orientationStatus, 'pending')
  assert.ok(!removed.payload.catalog.trainings.some(item => item.id === 'groupe'))
  assert.equal(removed.payload.notes['variante-a'], undefined)
})

test('CSV export excludes private notes and safely round-trips public content without mutations', async () => {
  const before = await current()
  const exported = await session.call('/admin/catalog/export')
  assert.equal(exported.status, 200)
  assert.equal(exported.payload.csv.charCodeAt(0), 0xFEFF)
  assert.ok(!exported.payload.csv.includes('Note privée nouvelle'))
  assert.match(exported.payload.filename, /^aura-catalogue-\d{4}-\d{2}-\d{2}-r\d+\.csv$/)
  const preview = await previewCsv(exported.payload.csv)
  assert.equal(preview.status, 200, JSON.stringify(preview.payload))
  for (const key of ['added', 'updated', 'removed']) assert.deepEqual(preview.payload.diff[key], [])
  assert.equal(preview.payload.diff.orderChanged, false)
  assert.deepEqual(await current(), before)
})

test('merge imports add or update without silently removing unmentioned formations', async () => {
  const before = await current()
  const item = { ...before.catalog.trainings[0], description: 'Programme mis à jour par CSV\nDeuxième ligne ; avec "guillemets".', topics: ['Un élément\navec retour', 'Un autre, élément'] }
  const subset = { ...before.catalog, trainings: [item] }
  const preview = await previewCsv(csvFor(subset), 'merge')
  assert.equal(preview.status, 200)
  assert.equal(preview.payload.diff.updated.length, 1)
  assert.equal(preview.payload.diff.removed.length, 0)
  assert.ok(preview.payload.diff.updated[0].changes.some(change => change.field === 'description'))
  const manualRows = csvRecords(csvFor(subset))
  manualRows[1][manualRows[0].indexOf('contenu')] = 'Théorie\nAtelier'
  manualRows[1][manualRows[0].indexOf('statut_contenu')] = 'en cours'
  const friendly = await previewCsv(csvText(manualRows), 'merge')
  assert.equal(friendly.status, 200)
  assert.deepEqual(friendly.payload.diff.updated[0].changes.find(change => change.field === 'topics').after, ['Théorie', 'Atelier'])
  // A newer preview deliberately invalidates the old one, so rebuild it.
  const publishable = await previewCsv(csvFor(subset), 'merge')
  const saved = await session.call('/admin/catalog/import/commit', 'POST', { token: publishable.payload.token, revision: before.revision, confirmation: '' })
  assert.equal(saved.status, 200)
  assert.equal(saved.payload.catalog.trainings.length, before.catalog.trainings.length)
  assert.deepEqual(saved.payload.catalog.trainings[0].topics, item.topics)
  assert.equal(saved.payload.notes['nouvelle-formation'], 'Note privée nouvelle')
})

test('replacement imports show omissions and require the exact deletion count', async () => {
  const before = await current()
  const csv = csvFor({ ...before.catalog, trainings: [before.catalog.trainings[0]] })
  const preview = await previewCsv(csv, 'replace')
  assert.equal(preview.status, 200)
  const count = preview.payload.diff.removed.length
  assert.ok(count > 0)
  assert.equal((await session.call('/admin/catalog/import/commit', 'POST', { token: preview.payload.token, revision: before.revision, confirmation: '' })).status, 422)
  assert.equal((await current()).revision, before.revision)
  const saved = await session.call('/admin/catalog/import/commit', 'POST', { token: preview.payload.token, revision: before.revision, confirmation: `SUPPRIMER ${count}` })
  assert.equal(saved.status, 200)
  assert.equal(saved.payload.catalog.trainings.length, 1)
  assert.equal(saved.payload.notes['nouvelle-formation'], undefined)
})

test('the last entry cannot be removed or replaced with an empty import', async () => {
  const before = await current()
  const id = before.catalog.trainings[0].id
  assert.equal((await session.call(`/admin/trainings/${id}/delete-preview`, 'POST', { revision: before.revision })).status, 422)
  assert.equal((await session.call(`/admin/trainings/${id}`, 'DELETE', { revision: before.revision, confirmation: id, removedCount: 1 })).status, 422)
  const exported = await session.call('/admin/catalog/export')
  const rows = csvRecords(exported.payload.csv)
  for (const csv of ['', csvText([rows[0]])]) assert.equal((await previewCsv(csv, 'replace')).status, 422)
  assert.deepEqual(await current(), before)
})

test('CSV parsing rejects malformed, unrelated and contradictory files atomically', async () => {
  const before = await current()
  const exported = await session.call('/admin/catalog/export')
  const rows = csvRecords(exported.payload.csv)
  const conflict = [...rows[1]]; conflict[rows[0].indexOf('duree')] = 'Durée contradictoire'
  for (const csv of ['task_id,title\nx,y', '"unterminated', csvText([[...rows[0], rows[0][0]], [...rows[1], rows[1][0]]]), csvText([rows[0], rows[1], conflict]), csvText([rows[0], rows[1].slice(1)])]) {
    assert.equal((await previewCsv(csv)).status, 422, csv.slice(0, 80))
  }
  const unsafe = [...rows[1]]; unsafe[rows[0].indexOf('lien_formation_forem')] = 'javascript:alert(1)'
  assert.equal((await previewCsv(csvText([rows[0], unsafe]))).status, 422)
  assert.deepEqual(await current(), before)
})

test('raw file bytes support UTF-16, Windows-1252, commas, tabs and CRLF', async () => {
  const before = await current()
  const csv = csvFor(before.catalog)
  const utf16 = Buffer.concat([Buffer.from([0xff, 0xfe]), Buffer.from(csv.replace(/^\uFEFF/, ''), 'utf16le')]).toString('base64')
  const preview = await session.call('/admin/catalog/import/preview', 'POST', { revision: before.revision, mode: 'merge', csvBase64: utf16 })
  assert.equal(preview.status, 200)
  assert.ok(preview.payload.warnings.some(warning => warning.includes('UTF-16')))
  const rows = csvRecords(csv)
  for (const separator of [',', '\t']) {
    const converted = rows.map(row => row.map(value => '"' + value.replaceAll('"', '""') + '"').join(separator)).join('\r\n')
    assert.equal((await previewCsv(converted)).status, 200)
  }
  const win = php(['-r', 'echo base64_encode(mb_convert_encoding(stream_get_contents(STDIN), "Windows-1252", "UTF-8"));'], csv.replace(/^\uFEFF/, ''))
  const legacyBytes = await session.call('/admin/catalog/import/preview', 'POST', { revision: before.revision, mode: 'merge', csvBase64: win })
  assert.equal(legacyBytes.status, 200)
  assert.ok(legacyBytes.payload.warnings.some(warning => warning.includes('Windows-1252')))
  assert.deepEqual(legacyBytes.payload.diff.updated, [])
  assert.equal((await session.call('/admin/catalog/import/preview', 'POST', { revision: before.revision, mode: 'merge', csvBase64: 'not base64!' })).status, 422)
})

test('stale, replaced, cross-session and replayed preview tokens never publish silently', async () => {
  const before = await current()
  const csv = csvFor(before.catalog)
  const first = await previewCsv(csv)
  const next = await previewCsv(csv)
  assert.equal((await session.call('/admin/catalog/import/commit', 'POST', { token: first.payload.token, revision: before.revision, confirmation: '' })).status, 409)
  assert.equal((await second.call('/admin/catalog/import/commit', 'POST', { token: next.payload.token, revision: before.revision, confirmation: '' })).status, 409)
  const id = before.catalog.trainings[0].id
  assert.equal((await second.call(`/admin/trainings/${id}`, 'PATCH', { revision: before.revision, changes: { description: 'Modification concurrente' }, note: '' })).status, 200)
  assert.equal((await session.call('/admin/catalog/import/commit', 'POST', { token: next.payload.token, revision: before.revision, confirmation: '' })).status, 409)
  const fresh = await previewCsv(csv)
  const payload = { token: fresh.payload.token, revision: fresh.payload.revision, confirmation: '' }
  assert.equal((await session.call('/admin/catalog/import/commit', 'POST', payload)).status, 200)
  assert.equal((await session.call('/admin/catalog/import/commit', 'POST', payload)).status, 409)
})

test('spreadsheet formula-like titles are exported as text and reimported losslessly', async () => {
  const before = await current()
  const title = '=HYPERLINK("https://attacker.example";"Texte")'
  const id = before.catalog.trainings[0].id
  assert.equal((await session.call(`/admin/trainings/${id}`, 'PATCH', { revision: before.revision, changes: { title }, note: '' })).status, 200)
  const exported = await session.call('/admin/catalog/export')
  const rows = csvRecords(exported.payload.csv)
  assert.equal(rows[1][rows[0].indexOf('intitule')], "'" + title)
  const preview = await previewCsv(exported.payload.csv)
  assert.equal(preview.status, 200)
  assert.deepEqual(preview.payload.diff.updated, [])
})

test('full CSV imports preserve parents, variants, multiple places and illustration metadata', async () => {
  const full = JSON.parse(JSON.stringify(catalog))
  full.trainings[0].locations = [{ city: 'Verviers', address: 'Premier lieu', accessUrl: '' }, { city: 'Liège', address: 'Second lieu', accessUrl: 'https://example.org/plan' }]
  full.trainings[0].photo = 'https://images.pexels.com/photos/1234/photo.jpg?width=300'
  full.trainings[0].photoAlt = 'Une illustration'
  full.trainings[0].photoCredit = 'Auteur de test'
  const saved = await publishCsv(csvFor(full))
  assert.equal(saved.catalog.trainings.length, 2)
  assert.equal(saved.catalog.trainings[1].variants.length, 2)
  assert.deepEqual(saved.catalog.trainings[0].locations, full.trainings[0].locations)
  assert.equal(saved.catalog.trainings[0].photoCredit, 'Auteur de test')
  const preview = await previewCsv(csvFor(saved.catalog))
  assert.equal(preview.status, 200)
  assert.deepEqual(preview.payload.diff.updated, [])
})

test('legacy 22-column CSV keeps known photos and reconstructs known grouped entries', async () => {
  const before = await current()
  const rows = csvRecords(csvFor(before.catalog))
  const columns = rows[0].slice(0, 22)
  const legacy = [columns]
  for (const row of rows.slice(1)) {
    const type = row[rows[0].indexOf('type_ligne')]
    if (type === 'regroupement') continue
    const values = row.slice(0, 22)
    for (const column of ['contenu', 'prerequis']) values[columns.indexOf(column)] = JSON.parse(values[columns.indexOf(column)]).join('\n')
    legacy.push(values)
  }
  const saved = await publishCsv(csvText(legacy), 'merge')
  assert.equal(saved.catalog.trainings[0].photo, before.catalog.trainings[0].photo)
  assert.equal(saved.catalog.trainings.find(item => item.id === 'groupe').variants.length, 2)
})

test('moving a group moves its variants consistently and requires recalibration', async () => {
  const before = await current()
  const moved = await session.call('/admin/trainings/groupe', 'PATCH', { revision: before.revision, changes: { sectorId: 'technique' }, note: '' })
  assert.equal(moved.status, 200)
  const group = moved.payload.catalog.trainings.find(item => item.id === 'groupe')
  assert.equal(group.sectorId, 'technique')
  assert.equal(group.orientationStatus, 'pending')
  assert.ok(group.variants.every(item => item.sectorId === 'technique' && item.orientationStatus === 'pending'))
  const invalid = await session.call('/admin/trainings/variante-a', 'PATCH', { revision: moved.payload.revision, changes: { sectorId: 'bureau' }, note: '' })
  assert.equal(invalid.status, 422)
})

test('numeric variant identifiers stay strings in preview responses', async () => {
  const fixture = { ...catalog, trainings: [{ ...training('12'), variants: [training('0'), training('1')] }] }
  const preview = await previewCsv(csvFor(fixture), 'replace')
  assert.equal(preview.status, 200, JSON.stringify(preview.payload))
  assert.deepEqual(preview.payload.diff.added.map(item => item.id).sort(), ['0', '1', '12'])
  assert.ok(preview.payload.diff.added.every(item => typeof item.id === 'string'))
})

test('expired preview tokens are rejected before any database write', () => {
  const result = php(['-r', 'require "src/bootstrap.php"; $_SESSION["catalog_import"]=["token"=>str_repeat("a",64),"revision"=>1,"expires"=>time()-1,"removedCount"=>0]; $repository=new Aura\\Api\\Repository\\TrainingCatalogRepository(new PDO("sqlite::memory:")); try{$repository->importCommit(["token"=>str_repeat("a",64),"revision"=>1,"confirmation"=>""],"test@example.invalid");}catch(Aura\\Api\\Http\\ApiException $error){echo $error->errorCode;}'])
  assert.equal(result, 'import_preview_expired')
})

test('whole-group deletion explicitly previews and removes its variants, never leaves a dangling record', async () => {
  const before = await current()
  const preview = await session.call('/admin/trainings/groupe/delete-preview', 'POST', { revision: before.revision })
  assert.equal(preview.status, 200)
  assert.equal(preview.payload.diff.removed.length, 3)
  const saved = await session.call('/admin/trainings/groupe', 'DELETE', { revision: before.revision, confirmation: 'groupe', removedCount: 3 })
  assert.equal(saved.status, 200)
  assert.equal(saved.payload.catalog.trainings.length, 1)
  assert.equal(saved.payload.catalog.trainings[0].id, 'simple')
})

test('deactivated accounts lose access even with a previously valid session', async () => {
  php(['scripts/catalog-admin.php', 'disable-user', email])
  assert.equal((await second.call('/admin/catalog')).status, 401)
  assert.equal((await second.call('/admin/session')).payload.user, null)
})

test('logout invalidates authenticated access and rotates CSRF', async () => {
  const previous = await session.call('/admin/session')
  const loggedOut = await session.call('/admin/logout', 'POST', {})
  assert.equal(loggedOut.status, 200)
  assert.equal(loggedOut.payload.user, null)
  assert.notEqual(loggedOut.payload.csrfToken, previous.payload.csrfToken)
  assert.equal((await session.call('/admin/catalog')).status, 401)
})

test('repeated invalid logins are limited by a shared server-side IP budget', async () => {
  const attacker = client()
  await attacker.call('/admin/session')
  const statuses = []
  for (let i = 0; i < 12; i++) statuses.push((await attacker.call('/admin/login', 'POST', { email: `unknown-${i}@example.invalid`, password: 'wrong' })).status)
  assert.ok(statuses.includes(429))
  assert.equal(statuses.at(-1), 429)
})

test('migration is additive and the public endpoint does not expose the admin envelope', () => {
  const migration = readFileSync(new URL('../database/migrations/003_training_admin.sql', import.meta.url), 'utf8')
  assert.doesNotMatch(migration, /DROP\s|TRUNCATE\s|ALTER TABLE\s+(?:jobs|sectors)/i)
  const routes = readFileSync(new URL('../public/index.php', import.meta.url), 'utf8')
  assert.match(routes, /current\(\)\['catalog'\]/)
})

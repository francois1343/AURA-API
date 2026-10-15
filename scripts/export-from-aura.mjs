import { writeFile } from 'node:fs/promises'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

const apiRoot = path.resolve(import.meta.dirname, '..')
const defaultSource = path.resolve(apiRoot, '../AURA/frontend/src/data/jobs.js')
const sourcePath = path.resolve(process.argv[2] || defaultSource)
const outputPath = path.resolve(apiRoot, 'database/seeds/catalog.json')
const { jobs, sectors } = await import(pathToFileURL(sourcePath))

const catalog = {
  sectors: sectors.map(({ id, label, icon, description, color, textColor }) => ({
    id,
    label,
    icon,
    description,
    color,
    textColor,
  })),
  jobs: jobs.map((job) => ({
    id: job.id,
    sectorId: job.sectorId,
    title: job.title,
    searchQuery: job.searchQuery,
    badge: job.badge,
    summary: job.summary,
    description: job.description,
    tasks: job.tasks,
    environment: job.environment,
    schedule: job.schedule,
    access: job.access,
    qualities: job.qualities,
    photo: job.photo,
    photoAlt: job.photoAlt,
    foremUrl: job.foremUrl,
    verifiedAt: null,
  })),
}

await writeFile(outputPath, `${JSON.stringify(catalog, null, 2)}\n`, 'utf8')
console.log(`Catalogue exporté : ${catalog.sectors.length} secteurs et ${catalog.jobs.length} métiers.`)


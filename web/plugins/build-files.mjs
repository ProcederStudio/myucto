/**
 * Vite plugin: po buildu zapíše do `dist/build-files.json` seznam souborů v `dist/assets/`.
 *
 * Why: aktualizace instance rozbalí nový bundle přes starý strom, takže assety
 * předchozích vydání v `assets/` zůstávají (jména mají otisk obsahu) a disk roste
 * s každým vydáním. Podle seznamu je po nasazení smaže `FrontendAssetPruner`.
 */
import { readdirSync, writeFileSync } from 'node:fs'
import { join, relative, resolve, sep } from 'node:path'

export default function buildFiles() {
  let outDir = ''
  return {
    name: 'myucto-build-files',
    apply: 'build',
    configResolved(config) {
      outDir = resolve(config.root, config.build.outDir)
    },
    closeBundle() {
      const files = readdirSync(join(outDir, 'assets'), { recursive: true, withFileTypes: true })
        .filter((entry) => entry.isFile())
        .map((entry) => relative(outDir, join(entry.parentPath, entry.name)).split(sep).join('/'))
        .sort()
      writeFileSync(join(outDir, 'build-files.json'),JSON.stringify({ files }) + '\n')
    },
  }
}

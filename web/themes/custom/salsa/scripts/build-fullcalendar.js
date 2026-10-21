#!/usr/bin/env node
/// <reference types="node" />
/**
 * @file
 * Builds the vendored FullCalendar assets served from
 * `libraries/fullcalendar/`.
 *
 * The files are generated, not committed. `pnpm install` runs this script as
 * the theme's `postinstall`; `pnpm build:fullcalendar` runs it on demand.
 */

import { copyFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

const themeDir = dirname(dirname(fileURLToPath(import.meta.url)));
const outputDir = join(themeDir, 'libraries', 'fullcalendar');

// Resolve from the theme, so the copy installed as the theme's devDependency
// is the one that gets vendored.
const require = createRequire(import.meta.url);
const packageDir = dirname(require.resolve('@fullcalendar/web-component'));

/** @type {Array<{ source: string, target: string }>} */
const bundles = [
  // The skeleton styles must be applied before the element is defined; the
  // theme styles chain onto them.
  { source: 'skeleton.styles.js', target: 'skeleton.styles.min.js' },
  { source: 'themes/classic/theme.styles.js', target: 'theme-classic.styles.min.js' },
  { source: 'all/global.js', target: 'fullcalendar.global.min.js' },
  { source: 'themes/classic/global.js', target: 'theme-classic.global.min.js' },
  { source: 'locales/de/global.js', target: 'locale-de.global.min.js' },
];

/** @type {Array<{ source: string, target: string }>} */
const copies = [
  { source: 'LICENSE.md', target: 'LICENSE.md' },
  { source: 'themes/classic/palette.css', target: 'theme-classic.palette.css' },
];

try {
  await mkdir(outputDir, { recursive: true });

  await Promise.all(
    bundles.map(({ source, target }) =>
      build({
        entryPoints: [join(packageDir, source)],
        outfile: join(outputDir, target),
        minify: true,
        legalComments: 'inline',
        logLevel: 'warning',
      }),
    ),
  );

  await Promise.all(
    copies.map(({ source, target }) =>
      copyFile(join(packageDir, source), join(outputDir, target)),
    ),
  );

  console.log(
    `FullCalendar: built ${bundles.length} bundles and copied ${copies.length} files to ${relative(process.cwd(), outputDir) || outputDir}`,
  );
} catch (error) {
  console.error('FullCalendar: build failed');
  console.error(error instanceof Error ? error.message : String(error));
  process.exitCode = 1;
}

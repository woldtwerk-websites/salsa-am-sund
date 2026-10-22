import { defineConfig } from 'taze'

export default defineConfig({
  recursive: true,
  ignorePaths: [
    '**/node_modules/**',
    '**/vendor/**',
    '**/test/**',
    'web/core/**',
    'web/modules/contrib/**',
    'web/themes/contrib/**',
    'recipes/**',
    'web/libraries/**',
    // CKEditor 5 plugin builds are pinned to the DLL-based versions (< 48) that
    // Drupal core's bundled CKEditor 5 still uses. Do not let taze bump them.
    'web/modules/custom/ck5_todo',
    'web/modules/custom/ck5_file',
  ],
})

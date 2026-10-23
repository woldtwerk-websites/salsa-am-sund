import { defineConfig } from 'vite-plus';

export default defineConfig({
  staged: {
    '*': 'vp check --fix',
  },
  fmt: {
    ignorePatterns: [
      '.ddev/**',
      'composer.json',
      '**/*.css',
      'misc/**',
      'web/sites/default/default.*',
      'web/sites/development.services.yml',
      'web/modules/custom/*/config/**',
    ],
    overrides: [
      {
        files: ['**/*.yml', '**/*.yaml', '**/*.js', '**/*.ts', '**/*.tsx'],
        options: { singleQuote: true },
      },
    ],
  },
  lint: {
    ignorePatterns: ['.ddev/**', 'misc/**'],
    jsPlugins: [{ name: 'vite-plus', specifier: 'vite-plus/oxlint-plugin' }],
    rules: { 'vite-plus/prefer-vite-plus-imports': 'error' },
    options: { typeAware: true, typeCheck: true },
  },
});

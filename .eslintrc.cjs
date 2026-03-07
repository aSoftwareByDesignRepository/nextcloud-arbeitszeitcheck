/**
 * ESLint config for ArbeitszeitCheck
 * Plain JS – Nextcloud globals, pragmatic rules for CI
 */
module.exports = {
  root: true,
  env: {
    browser: true,
    es2021: true,
  },
  globals: {
    OC: 'readonly',
  },
  extends: ['eslint:recommended'],
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
  },
  ignorePatterns: ['node_modules/', 'vendor/'],
  rules: {
    'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    'no-undef': ['error', { typeof: true }],
    'no-inner-declarations': 'off',
    'no-dupe-keys': 'warn',
    'no-useless-escape': 'warn',
  },
}

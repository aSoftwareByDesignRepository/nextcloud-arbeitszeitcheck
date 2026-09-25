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
    t: 'readonly',
  },
  plugins: ['arbeitszeitcheck'],
  extends: ['eslint:recommended'],
  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module',
  },
  ignorePatterns: ['node_modules/', 'vendor/'],
  overrides: [
    {
      files: ['js/common/desklet-actions.js', 'js/common/keep-focused-visible.js'],
      env: {
        node: true,
      },
    },
    {
      files: ['js/**/*.test.js', 'tests/e2e/**/*.js'],
      rules: {
        'no-restricted-syntax': 'off',
        'arbeitszeitcheck/no-raw-app-url': 'off',
        'arbeitszeitcheck/no-raw-app-navigation': 'off',
      },
    },
  ],
  rules: {
    'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    'no-undef': ['error', { typeof: true }],
    'no-inner-declarations': 'off',
    'no-dupe-keys': 'warn',
    'no-useless-escape': 'warn',
    'arbeitszeitcheck/no-raw-app-url': 'error',
    'arbeitszeitcheck/no-raw-app-navigation': 'error',
    'no-restricted-syntax': [
      'error',
      {
        selector: "CallExpression[callee.name='fetch'] > Literal:first-child[value=/^\\/apps\\/arbeitszeitcheck\\//]",
        message: 'Do not call fetch() with raw /apps/arbeitszeitcheck paths. Use ArbeitszeitCheckUtils.resolveUrl(...) or Utils.ajax(...).',
      },
      {
        selector: "CallExpression[callee.name='fetch'] > TemplateLiteral:first-child > TemplateElement[value.raw=/^\\/apps\\/arbeitszeitcheck\\//]",
        message: 'Do not call fetch() with raw /apps/arbeitszeitcheck paths. Use ArbeitszeitCheckUtils.resolveUrl(...) or Utils.ajax(...).',
      },
      {
        selector: "CallExpression[callee.name='fetch'] > Literal:first-child[value=/^(https?:)?\\/\\//]",
        message: 'External fetch() URLs are disallowed by default. Route through ArbeitszeitCheckUtils.ajax(..., { allowExternal: true }) with justification.',
      },
      {
        selector: "CallExpression[callee.name='fetch'] > TemplateLiteral:first-child > TemplateElement[value.raw=/^(https?:)?\\/\\//]",
        message: 'External fetch() URLs are disallowed by default. Route through ArbeitszeitCheckUtils.ajax(..., { allowExternal: true }) with justification.',
      },
    ],
  },
}

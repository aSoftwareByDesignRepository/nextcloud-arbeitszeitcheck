import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['js/**/*.test.js'],
    setupFiles: ['tests/js/vitest.setup.js'],
    restoreMocks: true,
    clearMocks: true,
  },
})


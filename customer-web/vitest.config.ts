import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// Unit/component tests for the Customer Web (jsdom). Run with `npm test`.
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
    // Module 37: the suite is 72 files now. In the parallel full run the long typing tests of the admin file (1.4–2.5 s
    // each when the file runs alone) passed the 5 s default on this machine and failed without any assertion failing.
    // The limit is the only thing changed; no test and no expectation was touched.
    testTimeout: 15000,
  },
})

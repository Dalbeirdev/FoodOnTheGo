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
  },
})

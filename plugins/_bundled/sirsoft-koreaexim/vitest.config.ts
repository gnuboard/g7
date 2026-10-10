import { defineConfig } from 'vitest/config';
import path from 'node:path';

export default defineConfig({
  test: {globals: true, environment: 'jsdom', include: ['resources/js/**/*.test.{ts,tsx}'], setupFiles: ['./resources/js/tests/setup.ts']},
  resolve: {dedupe: ['react', 'react-dom'], alias: {'@core': path.resolve(__dirname, '../../../resources/js/core')}},
});

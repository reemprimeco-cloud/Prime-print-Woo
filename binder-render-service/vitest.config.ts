import { defineConfig } from 'vitest/config';
import { resolve } from 'node:path';

export default defineConfig({
  resolve: { alias: { '@binder/shared': resolve(__dirname, '../binder-shared/src/index.ts') } },
  test: { testTimeout: 60_000, hookTimeout: 60_000, fileParallelism: false },
});

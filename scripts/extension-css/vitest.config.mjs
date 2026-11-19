/**
 * 확장 CSS 도구(scripts/extension-css) 전용 Vitest 설정
 *
 * 루트 `vitest.config.ts` 는 브라우저 코드(jsdom·setup 파일)용이고 `scripts/**` 를 포함하지 않는다.
 * 이 도구 테스트는 Node 환경에서 파일 시스템과 Tailwind 컴파일러를 직접 쓰므로 설정을 따로 둔다.
 *
 * 실행: npx vitest run --config scripts/extension-css/vitest.config.mjs scripts/extension-css
 */
import { defineConfig } from 'vitest/config';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  root: path.resolve(here, '..', '..'),
  test: {
    environment: 'node',
    include: ['scripts/extension-css/__tests__/**/*.test.mjs'],
    exclude: ['**/node_modules/**', 'scripts/extension-css/__tests__/fixtures/**'],
    testTimeout: 60000,
  },
});

import { defineConfig } from 'vitest/config';
import { resolve } from 'path';

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['resources/js/__tests__/**/*.test.{ts,tsx}'],
    setupFiles: [],
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'resources/js'),
      // 코어 테스트 유틸(`layoutTestUtils`·`layoutShapeUtils`)을 확장 스위트가 공유한다.
      // 다른 번들 플러그인(marketing·ckeditor5)은 처음부터 이 alias 를 갖고 있었고 이 플러그인만
      // 빠져 있었다 — 코어 유틸을 import 하는 순간 `Failed to resolve import` 로 파일 전체가 죽는다.
      '@core': resolve(__dirname, '../../../resources/js/core'),
    },
  },
});
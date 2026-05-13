import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./resources/js/tests/setup.ts'],
    include: [
      'resources/js/**/*.{test,spec}.{ts,tsx}',
      'templates/**/src/**/*.{test,spec}.{ts,tsx}',
      // 학습용 샘플 템플릿: __tests__ 디렉토리도 루트에서 회귀 커버리지 확보
      'templates/_bundled/gnuboard7-hello_admin_template/__tests__/**/*.{test,spec}.{ts,tsx}',
      'templates/_bundled/gnuboard7-hello_user_template/__tests__/**/*.{test,spec}.{ts,tsx}',
    ],
    exclude: [
      '**/node_modules/**',
      'templates/sirsoft-admin_sample/**',
      // 업데이트/백업 임시 스테이징 디렉토리는 실제 코드가 아니므로 제외
      '**/_pending/**',
      // 활성 디렉토리 (sirsoft-basic, sirsoft-admin_basic) 는 _bundled 사본이며
      // npm 의존성이 설치되지 않은 상태로 import 실패 발생. _bundled 원본만 테스트.
      'templates/sirsoft-basic/**',
      'templates/sirsoft-admin_basic/**',
    ],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      exclude: [
        'node_modules/',
        'resources/js/tests/',
        '**/*.d.ts',
        '**/*.config.*',
        '**/mockData',
      ],
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './resources/js'),
      '@core': path.resolve(__dirname, './resources/js/core'),
    },
  },
});

import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./resources/js/tests/setup.ts'],
    // 루트 스위트는 코어(resources/js)와 학습용 샘플 템플릿의 __tests__ 만 모은다.
    // 번들 템플릿(sirsoft-admin_basic·sirsoft-basic)의 src 시험은 각 템플릿의 자체 vitest.config 로 도는 확장 스위트와
    // 중복이었고(407파일), `templates/**/src/**` 는 저장소 밖 로컬 템플릿까지 쓸어 담았다. 자체 설정을 가진 확장
    // (예: 학습용 샘플 모듈)은 그 설정으로 돈다.
    include: [
      'resources/js/**/*.{test,spec}.{ts,tsx}',
      // 학습용 샘플 템플릿: __tests__ 디렉토리도 루트에서 회귀 커버리지 확보
      'templates/_bundled/gnuboard7-hello_admin_template/__tests__/**/*.{test,spec}.{ts,tsx}',
      'templates/_bundled/gnuboard7-hello_user_template/__tests__/**/*.{test,spec}.{ts,tsx}',
    ],
    exclude: [
      '**/node_modules/**',
      // 업데이트/백업 임시 스테이징 디렉토리는 실제 코드가 아니므로 제외
      '**/_pending/**',
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

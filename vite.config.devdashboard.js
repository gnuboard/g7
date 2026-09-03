import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

/**
 * 개발 대시보드 CSS 빌드 설정
 *
 * `/dev` 대시보드가 쓰던 Tailwind Play CDN 을 자체 빌드 CSS 로 대체한다.
 * 산출물은 `public/build/core/dev-dashboard.css` 하나이며, 코어 3번들과 같은 디렉토리를
 * 쓰므로 `emptyOutDir: false` 로 서로를 지우지 않게 한다.
 */
export default defineConfig({
  // outDir 이 publicDir 안에 있어 public 복사가 재귀한다 — 코어 3번들과 동일하게 끈다
  publicDir: false,
  plugins: [tailwindcss()],
  build: {
    outDir: 'public/build/core',
    emptyOutDir: false,
    rollupOptions: {
      input: path.resolve(__dirname, 'resources/css/dev-dashboard.css'),
      output: {
        assetFileNames: 'dev-dashboard.css',
      },
    },
  },
});

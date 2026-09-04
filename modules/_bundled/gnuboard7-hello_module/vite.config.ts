import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

/**
 * Hello Module 빌드 설정 — 학습용 최소 샘플
 *
 * 모듈이 제공하는 컴포넌트를 담은 경량 IIFE 번들. 모듈 번들은 코어가 페이지에 로드하며,
 * 엔트리가 즉시 실행되어 컴포넌트를 코어 레지스트리에 자가등록한다.
 *
 * React 는 external 이다 — 코어가 이미 페이지에 실어 둔 인스턴스를 빌려 쓴다. 번들이 자기
 * React 사본을 품으면 훅 호출이 다른 인스턴스에 걸려 "Invalid hook call" 로 죽는다.
 */
export default defineConfig({
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },

  plugins: [react()],

  build: {
    lib: {
      entry: path.resolve(__dirname, 'resources/js/index.ts'),
      name: 'Gnuboard7HelloModule',
      fileName: 'hello-module',
      formats: ['iife'],
    },

    outDir: 'dist',
    // 기본값 true 는 서빙 중인 산출물 디렉토리를 통째로 비운다 — 산출물 정리는 빌드 커맨드가 한다
    emptyOutDir: false,
    // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
    sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),

    rollupOptions: {
      external: ['react', 'react-dom', 'react/jsx-runtime'],

      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          'react/jsx-runtime': 'ReactJSXRuntime',
        },

        entryFileNames: 'js/hello-module.iife.js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: 'assets/[name][extname]',
      },
    },

    minify: 'esbuild',
    target: 'es2020',
  },

  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'resources/js'),
    },
  },
});

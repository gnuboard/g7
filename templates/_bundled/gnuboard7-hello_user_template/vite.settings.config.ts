import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

/**
 * 템플릿 설정 화면 묶음 빌드 (#434 D28·D30)
 *
 * 코어가 관리자 화면을 열 때 설치된 템플릿의 `dist/settings/settings.iife.js`·`settings.css` 를 플러그인 묶음처럼 싣는다
 * (이 템플릿이 꺼져 있거나 다른 관리자 템플릿이 켜져 있어도). 규약 경로라 이름을 바꾸지 않는다. Vite 라이브러리 모드는
 * IIFE 진입을 하나만 받으므로 본 묶음(vite.config.ts)과 따로 빌드한다 — `npm run build` 가 둘을 잇는다.
 */
export default defineConfig({
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },

  plugins: [react()],

  build: {
    lib: {
      entry: path.resolve(__dirname, 'src/settings/index.ts'),
      name: 'HelloUserTemplateSettings',
      fileName: 'settings',
      formats: ['iife'],
    },

    outDir: 'dist',
    emptyOutDir: false,

    sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),

    rollupOptions: {
      external: ['react', 'react-dom', 'react/jsx-runtime'],

      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          'react/jsx-runtime': 'ReactJSXRuntime',
        },

        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'settings/settings.css';
          }

          return 'settings/assets/[name][extname]';
        },

        entryFileNames: 'settings/settings.iife.js',
      },
    },

    minify: 'esbuild',
    target: 'es2020',
    chunkSizeWarningLimit: 1500,
  },

  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
});

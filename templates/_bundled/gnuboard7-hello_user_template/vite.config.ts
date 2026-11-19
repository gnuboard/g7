import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import dts from 'vite-plugin-dts';
import fs from 'fs';
import path from 'path';

/**
 * 확장 CSS 덮기 파일을 dist/css/overrides/ 로 옮긴다.
 *
 * 원본은 src/styles/overrides/<확장식별자>.css 이고, template.json `extension_css_overrides[].path` 가 그 산출 경로를
 * 가리킨다. 순수 CSS 라 변환 없이 그대로 옮긴다(엔트리에 묶이면 템플릿 CSS 와 섞여 따로 실을 수 없다).
 */
function extensionCssOverrides(): Plugin {
  const sourceDir = path.resolve(__dirname, 'src/styles/overrides');

  return {
    name: 'hello-extension-css-overrides',
    generateBundle() {
      if (!fs.existsSync(sourceDir)) return;
      for (const file of fs.readdirSync(sourceDir).filter((name) => name.endsWith('.css'))) {
        this.emitFile({
          type: 'asset',
          fileName: `css/overrides/${file}`,
          source: fs.readFileSync(path.join(sourceDir, file), 'utf8'),
        });
      }
    },
  };
}

/**
 * Hello User Template 빌드 설정 — 학습용 최소 샘플
 *
 * 8개 Basic 컴포넌트만 포함하는 경량 IIFE 번들
 *
 * 스타일은 순수 CSS 다 — src/index.ts 가 불러오는 src/styles/main.css 는 dist/css/components.css 로,
 * 확장 CSS 덮기 파일(src/styles/overrides/*.css)은 dist/css/overrides/ 로 낸다.
 */
export default defineConfig({
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },

  plugins: [
    react(),
    dts({
      insertTypesEntry: true,
      include: ['src/**/*.ts', 'src/**/*.tsx'],
      exclude: ['src/**/*.test.ts', 'src/**/*.test.tsx', 'node_modules'],
    }),
    extensionCssOverrides(),
  ],

  build: {
    lib: {
      entry: path.resolve(__dirname, 'src/index.ts'),
      name: 'Gnuboard7HelloUserTemplate',
      fileName: 'components',
      formats: ['iife'],
    },

    outDir: 'dist',
    emptyOutDir: false, // 동봉 vendor 보존 — 산출물 정리는 빌드 커맨드가 한다
    // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
    // 미설정(로컬 npm run build)이면 생성 — 개발 디버깅 경험을 유지한다.
    sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),

    rollupOptions: {
      external: ['react', 'react-dom', 'react/jsx-runtime'],

      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          'react/jsx-runtime': 'ReactJSXRuntime',
        },

        entryFileNames: 'js/components.iife.js',
        chunkFileNames: 'js/[name]-[hash].js',
        // src/index.ts 가 불러오는 CSS 는 template.json assets.css 와 같은 경로로 낸다
        assetFileNames: (info) => (info.name?.endsWith('.css') ? 'css/components.css' : 'assets/[name][extname]'),
      },
    },

    minify: 'esbuild',
    target: 'es2020',
  },

  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      '@components': path.resolve(__dirname, 'src/components'),
    },
  },
});

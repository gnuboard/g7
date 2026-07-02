import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

/**
 * 레이아웃 편집기 lazy 번들 빌드 설정 (engine-v1.51.0)
 *
 * 메인 코어 번들(`vite.config.core.js` → template-engine.min.js)에서 편집기 코드를 제거해
 * 초기 접속 payload 를 줄이고, 편집기 셸을 별도 `layout-editor.min.js` 로 빌드한다.
 * `/admin/layout-editor/*` 진입 시에만 런타임 `<script>` 주입으로 로드된다.
 *
 * 핵심:
 * - React/ReactDOM/jsx-runtime = external → window.React 등 (메인 번들이 노출한 단일 인스턴스
 *   재사용). react/jsx-runtime 을 external 하지 않으면 @vitejs/plugin-react 의 automatic-runtime
 *   JSX import 로 React 가 transitive 재포함돼 "Invalid hook call" 발생.
 * - 코어 런타임(DynamicRenderer/엔진/컨텍스트) = 커스텀 resolveId 플러그인으로 __runtime-shims
 *   치환 → window.G7Core.__runtime 에서 빌려 씀(재번들 0바이트, 싱글톤/컨텍스트 동일성 보장).
 */

const coreDir = path.resolve(__dirname, 'resources/js/core');
const shimDir = path.resolve(coreDir, 'template-engine/layout-editor/__runtime-shims');

// 편집기가 layout-editor/ 밖으로 import 하는 코어 런타임 모듈(확장자 제외 절대경로) → shim 절대경로.
// resolveId 훅이 "해석된 절대경로" 기준으로 매칭하므로, 편집기가 어느 상대 깊이(../ ../../ ../../../)로
// import 하든 단일 규칙으로 전부 shim 으로 치환된다.
const RUNTIME_TARGETS = [
  ['template-engine/DynamicRenderer', 'DynamicRenderer'],
  ['template-engine/TranslationEngine', 'TranslationEngine'],
  ['template-engine/TranslationContext', 'TranslationContext'],
  ['template-engine/DataSourceManager', 'DataSourceManager'],
  ['template-engine/DataBindingEngine', 'DataBindingEngine'],
  ['template-engine/ActionDispatcher', 'ActionDispatcher'],
  ['template-engine/ResponsiveManager', 'ResponsiveManager'],
  ['template-engine/ResponsiveContext', 'ResponsiveContext'],
  ['template-engine/ComponentRegistry', 'ComponentRegistry'],
  ['utils/Logger', 'Logger'],
  ['auth/AuthManager', 'AuthManager'],
];

/** 절대경로(확장자 제거) → shim 절대경로 맵 */
const targetToShim = new Map(
  RUNTIME_TARGETS.map(([target, shim]) => [
    path.resolve(coreDir, target).split(path.sep).join('/'),
    path.resolve(shimDir, shim + '.ts').split(path.sep).join('/'),
  ])
);

/** 경로에서 .ts/.tsx/.js/.jsx 확장자 제거 후 정규화(/구분자) */
function normalizeNoExt(id) {
  return id.replace(/\.(tsx?|jsx?)$/, '').split(path.sep).join('/');
}

/**
 * 코어 런타임 import 를 __runtime-shims 로 리디렉트하는 Vite 플러그인.
 *
 * Vite 의 문자열 `resolve.alias` 는 import specifier(상대경로 원문) 기준 매칭이라 상대 깊이가
 * 제각각인 편집기 import 를 잡지 못한다. 따라서 기본 해석기가 계산한 "절대경로"를 기준으로
 * 매칭하는 resolveId 훅을 쓴다.
 *
 * shim 파일 자신이 원본 경로를 import(값은 없고 `export type` 만 — 컴파일 시 소거)할 수 있으므로,
 * importer 가 shim 디렉토리 내부면 리디렉트하지 않아 무한 루프를 막는다.
 */
function coreRuntimeShimPlugin() {
  return {
    name: 'g7-core-runtime-shim',
    enforce: 'pre', // 기본 해석기보다 먼저 실행되어 코어 런타임 import 를 가로챈다
    async resolveId(source, importer, options) {
      if (!importer) return null;
      // shim 자신이 원본을 import(type-only)하는 경우는 리디렉트 제외 (루프 방지)
      if (normalizeNoExt(importer).includes('/__runtime-shims/')) {
        return null;
      }
      // 상대경로 import 만 대상 (코어 런타임은 편집기에서 상대경로로 참조됨)
      if (!source.startsWith('.')) return null;

      // importer 기준으로 절대경로 계산 (this.resolve 미사용 — enforce:pre 재진입 회피)
      const abs = normalizeNoExt(path.resolve(path.dirname(importer), source));
      const shim = targetToShim.get(abs);
      return shim || null;
    },
  };
}

export default defineConfig({
  plugins: [coreRuntimeShimPlugin(), react()],
  publicDir: false,
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: 'public/build/core',
    emptyOutDir: false, // template-engine.min.js 와 같은 디렉토리 공유
    lib: {
      entry: path.resolve(coreDir, 'layout-editor-entry.ts'),
      name: 'G7LayoutEditor', // G7Core 와 구분 (실제 연동은 window.G7Core.__LayoutEditorChrome)
      formats: ['iife'],
      fileName: () => 'layout-editor.min.js',
    },
    rollupOptions: {
      // React 3종 + jsx-runtime = 메인 번들의 window 전역 재사용 (단일 인스턴스)
      external: ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime'],
      output: {
        globals: {
          react: 'React',
          'react-dom': 'ReactDOM',
          'react-dom/client': 'ReactDOM',
          'react/jsx-runtime': 'ReactJSXRuntime',
        },
        exports: 'named',
        extend: true,
      },
    },
    minify: 'esbuild',
    sourcemap: true,
    target: 'es2020',
  },
  resolve: {
    alias: [{ find: '@', replacement: path.resolve(__dirname, 'resources/js') }],
  },
});

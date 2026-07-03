import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

/**
 * DevTools lazy 번들 빌드 설정 (engine-v1.51.0)
 *
 * 개발자 진단도구(패널 UI/진단엔진/서버커넥터/스타일추적기)는 디버그 모드에서만 쓰이므로
 * 메인 코어 번들에서 분리해 별도 `devtools.min.js` 로 빌드한다. `initDevToolsAPI()` 가
 * `isEnabled()` 참일 때만 런타임 <script> 주입으로 로드된다.
 *
 * 핵심:
 * - React/ReactDOM/jsx-runtime = external → window.React 등 단일 인스턴스 재사용.
 * - G7DevToolsCore(추적 코어, 항상 메인 번들에 상주) = resolveId 플러그인으로 shim 치환 →
 *   window.G7Core.__runtime.G7DevToolsCore 공유(싱글톤 동일성 보장).
 */

const coreDir = path.resolve(__dirname, 'resources/js/core');
const devtoolsDir = path.resolve(coreDir, 'devtools');
const shimDir = path.resolve(devtoolsDir, '__runtime-shims');

// 원본 절대경로(확장자 제외) → shim 절대경로
const targetToShim = new Map([
  [
    path.resolve(devtoolsDir, 'G7DevToolsCore').split(path.sep).join('/'),
    path.resolve(shimDir, 'G7DevToolsCore.ts').split(path.sep).join('/'),
  ],
]);

function normalizeNoExt(id) {
  return id.replace(/\.(tsx?|jsx?)$/, '').split(path.sep).join('/');
}

/**
 * G7DevToolsCore import 를 __runtime-shims 로 리디렉트하는 Vite 플러그인.
 * 편집기 번들과 동일 방식(enforce:'pre' + importer 기준 절대경로 매칭).
 */
function devtoolsRuntimeShimPlugin() {
  return {
    name: 'g7-devtools-runtime-shim',
    enforce: 'pre',
    resolveId(source, importer) {
      if (!importer) return null;
      if (normalizeNoExt(importer).includes('/__runtime-shims/')) return null;
      if (!source.startsWith('.')) return null;

      const abs = normalizeNoExt(path.resolve(path.dirname(importer), source));
      return targetToShim.get(abs) || null;
    },
  };
}

export default defineConfig({
  plugins: [devtoolsRuntimeShimPlugin(), react()],
  publicDir: false,
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: 'public/build/core',
    emptyOutDir: false,
    lib: {
      entry: path.resolve(coreDir, 'devtools-entry.ts'),
      name: 'G7DevToolsBundle',
      formats: ['iife'],
      fileName: () => 'devtools.min.js',
    },
    rollupOptions: {
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

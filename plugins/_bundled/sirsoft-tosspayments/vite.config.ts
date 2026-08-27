import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
    build: {
        lib: {
            entry: resolve(__dirname, 'resources/js/index.ts'),
            name: 'SirsoftTosspayments',
            formats: ['iife'],
        },
        outDir: 'dist',
        emptyOutDir: false, // 동봉 vendor 보존 — 산출물 정리는 빌드 커맨드가 한다
        rollupOptions: {
            output: {
                entryFileNames: 'js/plugin.iife.js',
            },
        },
        // 소스맵 생성
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        // 미설정(로컬 npm run build)이면 생성 — 개발 디버깅 경험을 유지한다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),
        minify: 'esbuild',
        target: 'es2020',
        chunkSizeWarningLimit: 500,
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
});

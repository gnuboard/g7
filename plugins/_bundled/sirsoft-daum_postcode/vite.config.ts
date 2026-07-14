import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },

    build: {
        // 라이브러리 모드 설정
        lib: {
            entry: path.resolve(__dirname, 'resources/js/index.ts'),
            name: 'SirsoftDaumPostcode', // 전역 변수명 (IIFE 모드용)
            fileName: 'plugin',
            formats: ['iife'], // IIFE 포맷만 빌드
        },

        // 빌드 출력 설정
        outDir: 'dist',
        emptyOutDir: true,

        // 소스맵 생성
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        // 미설정(로컬 npm run build)이면 생성 — 개발 디버깅 경험을 유지한다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),

        rollupOptions: {
            output: {
                // JS 파일명 패턴
                entryFileNames: 'js/plugin.iife.js',
                chunkFileNames: 'js/[name]-[hash].js',
            },
        },

        // 빌드 최적화
        minify: 'esbuild',
        target: 'es2020',

        // Chunk 크기 경고 임계값
        chunkSizeWarningLimit: 500,
    },

    // 타입스크립트 설정
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});

import { defineConfig } from 'vite';
import path from 'path';

/**
 * 비즈뿌리오 메시징 플러그인 프론트엔드 에셋 빌드 설정.
 *
 * 플러그인 엔트리(resources/js/index.ts)를 IIFE 번들로 빌드해 활성화 시 로드한다.
 * 출력물은 dist/js/plugin.iife.js 이며 plugin.json assets.js.output 과 일치한다.
 */
export default defineConfig({
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },

    build: {
        lib: {
            entry: path.resolve(__dirname, 'resources/js/index.ts'),
            name: 'SirsoftMessageBizppurio',
            fileName: 'plugin',
            formats: ['iife'],
        },
        outDir: 'dist',
        emptyOutDir: false, // 동봉 vendor 보존 — 산출물 정리는 빌드 커맨드가 한다
        // 소스맵 생성
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        // 미설정(로컬 npm run build)이면 생성 — 개발 디버깅 경험을 유지한다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),
        rollupOptions: {
            output: {
                entryFileNames: 'js/plugin.iife.js',
                chunkFileNames: 'js/[name]-[hash].js',
            },
        },
        minify: 'esbuild',
        target: 'es2020',
        chunkSizeWarningLimit: 500,
    },

    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});

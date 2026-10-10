import { defineConfig } from 'vite';
import path from 'path';

/**
 * sirsoft-board 모듈 빌드 설정
 *
 * 액션 핸들러와 관리자 화면 전용 접두 클래스 CSS(dist/css/module.css)를 담은 경량 IIFE 번들이다(컴포넌트 없음).
 * 코어가 활성 모듈 번들을 페이지에 실으면 엔트리가 즉시 실행되어 핸들러를 ActionDispatcher 에 등록한다.
 *
 * 빌드: php artisan module:build sirsoft-board --production
 */
export default defineConfig({
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },

    build: {
        lib: {
            entry: path.resolve(__dirname, 'resources/js/index.ts'),
            name: 'SirsoftBoard',
            fileName: 'module',
            formats: ['iife'],
        },

        outDir: 'dist',
        // 기본값 true 는 서빙 중인 산출물 디렉토리를 통째로 비운다 — 산출물 정리는 빌드 커맨드가 한다
        emptyOutDir: false,
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),

        rollupOptions: {
            output: {
                entryFileNames: 'js/module.iife.js',
                chunkFileNames: 'js/[name]-[hash].js',
                // index.ts 가 불러오는 CSS 는 module.json assets.css.output 과 같은 경로로 낸다
                assetFileNames: (info) => (info.name?.endsWith('.css') ? 'css/module.css' : 'assets/[name][extname]'),
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

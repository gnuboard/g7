import { defineConfig } from 'vite';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';

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

    // 화면 스타일은 접두(bizppurio:) Tailwind 빌드다 — resources/css/main.css 진입 블록 참조
    plugins: [tailwindcss()],

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
                // index.ts 가 불러오는 CSS 는 plugin.json assets.css.output 과 같은 경로로 낸다
                assetFileNames: (info) => {
                    if (info.name && info.name.endsWith('.css')) return 'css/plugin.css';
                    return 'assets/[name][extname]';
                },
                chunkFileNames: 'js/[name]-[hash].js',
            },
        },
        minify: 'esbuild',
        target: 'es2020',
        // CSS 는 lightningcss 로 줄인다 — 기본(esbuild) 압축은 oklch() 색을 근삿값 16진수로 바꿔 템플릿 CSS 와 같은 색이
        // 계산값에서 달라진다(Tailwind 산출과 같은 도구로 압축).
        cssMinify: 'lightningcss',
        // CSS 대상 브라우저는 Tailwind 4 기준(Safari 16.4·Chrome 111·Firefox 128) — 색·중첩을 낮추지 않는다.
        cssTarget: ['chrome111', 'edge111', 'firefox128', 'safari16.4'],
        chunkSizeWarningLimit: 500,
    },

    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});

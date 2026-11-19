import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';

/**
 * 히어로섹션 플러그인 빌드 설정.
 *
 * 사용자 슬라이더와 관리자 미리보기 컴포넌트를 담은 IIFE 번들. React 는 external 이다 — 코어가
 * 페이지에 실어 둔 인스턴스를 빌려 쓴다(번들이 자기 사본을 품으면 훅 호출이 다른 인스턴스에 걸린다).
 */
export default defineConfig({
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    // 관리자 화면 스타일은 접두(hs:) Tailwind 빌드다 — resources/css/main.css 진입 블록 참조
    plugins: [react(), tailwindcss()],
    build: {
        lib: {
            entry: resolve(__dirname, 'resources/js/index.ts'),
            name: 'SirsoftHeroSection',
            formats: ['iife'],
            cssFileName: 'plugin',
        },
        outDir: 'dist',
        emptyOutDir: false, // 산출물 정리는 빌드 커맨드가 한다
        // CSS 는 lightningcss 로 줄인다 — 기본(esbuild) 압축은 oklch() 색을 근삿값 16진수로 바꿔 템플릿 CSS 와 같은 색이
        // 계산값에서 달라진다(Tailwind 산출과 같은 도구로 압축).
        cssMinify: 'lightningcss',
        // CSS 대상 브라우저는 Tailwind 4 기준(Safari 16.4·Chrome 111·Firefox 128) — 색·중첩을 낮추지 않는다.
        cssTarget: ['chrome111', 'edge111', 'firefox128', 'safari16.4'],
        rollupOptions: {
            external: ['react', 'react-dom', 'react/jsx-runtime'],
            output: {
                globals: {
                    react: 'React',
                    'react-dom': 'ReactDOM',
                    'react/jsx-runtime': 'ReactJSXRuntime',
                },
                // 번들에 함께 담는 Swiper 의 MIT 저작권 고지 — 원본 머리 주석은 `/**` 라 압축 때 지워지므로 `/*!` 로 남긴다
                banner: '/*! Swiper 14.3.0 | https://swiperjs.com | Copyright 2014-2026 Vladimir Kharlampidi | Released under the MIT License */',
                entryFileNames: 'js/plugin.iife.js',
                assetFileNames: (info) => {
                    if (info.name && info.name.endsWith('.css')) return 'css/plugin.css';
                    return 'assets/[name][extname]';
                },
            },
        },
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
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

import { defineConfig } from 'vite';
import { resolve } from 'path';
import tailwindcss from '@tailwindcss/vite';

/**
 * 마케팅 동의 플러그인 화면 스타일 빌드 설정.
 *
 * 이 플러그인은 화면 스크립트가 없다 — 화면 스타일 CSS 하나(resources/css/main.css)만 빌드해
 * plugin.json assets.css.output(dist/css/plugin.css)으로 낸다. 스타일은 접두(marketing:) Tailwind 빌드다 —
 * resources/css/main.css 진입 블록 참조.
 */
export default defineConfig({
    plugins: [tailwindcss()],
    build: {
        outDir: 'dist',
        emptyOutDir: false, // 산출물 정리는 빌드 커맨드가 한다
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),
        // CSS 는 lightningcss 로 줄인다 — 기본(esbuild) 압축은 oklch() 색을 근삿값 16진수로 바꿔 템플릿 CSS 와 같은 색이
        // 계산값에서 달라진다(Tailwind 산출과 같은 도구로 압축).
        cssMinify: 'lightningcss',
        // CSS 대상 브라우저는 Tailwind 4 기준(Safari 16.4·Chrome 111·Firefox 128) — 색·중첩을 낮추지 않는다.
        cssTarget: ['chrome111', 'edge111', 'firefox128', 'safari16.4'],
        rollupOptions: {
            input: { plugin: resolve(__dirname, 'resources/css/main.css') },
            output: {
                // assets.css.output(dist/css/plugin.css)과 같은 경로
                assetFileNames: (info) => (info.name?.endsWith('.css') ? 'css/plugin.css' : 'assets/[name][extname]'),
            },
        },
    },
});

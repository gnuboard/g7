import { defineConfig } from 'vite';
import { resolve } from 'path';

/**
 * CloudFront CDN 플러그인 빌드 설정
 *
 * 이 플러그인은 화면 스크립트가 없다. 손으로 쓴 순수 CSS(resources/css/main.css) 하나를 plugin.json
 * `assets.css.output`(dist/css/plugin.css)으로 옮긴다. CSS 전처리기·Tailwind 플러그인은 쓰지 않는다.
 *
 * 압축하지 않는다 — 기본(esbuild) CSS 압축은 oklch() 색을 근삿값 16진수로 바꿔 템플릿과 같은 색이 계산값에서
 * 달라진다.
 */
export default defineConfig({
    build: {
        outDir: 'dist',
        // 산출물 정리는 빌드 커맨드가 한다
        emptyOutDir: false,
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),
        cssMinify: false,
        rollupOptions: {
            input: { plugin: resolve(__dirname, 'resources/css/main.css') },
            output: {
                // assets.css.output(dist/css/plugin.css)과 같은 경로
                assetFileNames: (info) => (info.name?.endsWith('.css') ? 'css/plugin.css' : 'assets/[name][extname]'),
            },
        },
    },
});

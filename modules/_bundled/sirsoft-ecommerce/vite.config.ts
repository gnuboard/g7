import { createLogger, defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import path from 'path';

/**
 * 동봉 국기 url() 은 빌드가 일부러 풀지 않는다 — 그 안내 경고(국기 수만큼)만 걸러 빌드 출력에서 다른 경고가 묻히지 않게 한다.
 */
const logger = createLogger();
const isVendorFlagNotice = (msg: string): boolean =>
    msg.includes('/vendor/flag-icons/') && msg.includes("didn't resolve at build time");
const baseWarn = logger.warn.bind(logger);
const baseWarnOnce = logger.warnOnce.bind(logger);
logger.warn = (msg, options) => {
    if (!isVendorFlagNotice(msg)) baseWarn(msg, options);
};
logger.warnOnce = (msg, options) => {
    if (!isVendorFlagNotice(msg)) baseWarnOnce(msg, options);
};

/**
 * 화면 스타일은 이 모듈 자기 CSS(resources/css/main.css)다. Tailwind 는 이 모듈의 작성 도구로만 쓰며
 * `ecommerce:` 접두 유틸리티만 만든다(preflight 없음). 엔트리가 CSS 를 import 하면 아래 assetFileNames 가
 * module.json `assets.css.output` 과 같은 dist/css/module.css 로 낸다.
 *
 * 국기 SVG 는 dist/vendor/flag-icons/ 에 동봉돼 있고 CSS 의 url() 은 산출 파일 기준 상대 경로다 — 빌드가
 * 풀지 않고 그대로 두며(라이브러리 모드의 인라인 회피), 서버가 내보낼 때 자산 주소로 바꾼다.
 */
export default defineConfig({
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },

    customLogger: logger,

    plugins: [tailwindcss()],

    build: {
        // 라이브러리 모드 설정
        lib: {
            entry: path.resolve(__dirname, 'resources/js/index.ts'),
            name: 'SirsoftEcommerce', // 전역 변수명 (IIFE 모드용)
            fileName: 'module',
            formats: ['iife'], // IIFE 포맷만 빌드
        },

        // 빌드 출력 설정
        outDir: 'dist',
        emptyOutDir: false, // 동봉 vendor 보존 — 산출물 정리는 빌드 커맨드가 한다

        // 소스맵 생성
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        // 미설정(로컬 npm run build)이면 생성 — 개발 디버깅 경험을 유지한다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),

        rollupOptions: {
            output: {
                // 에셋 파일명 패턴
                assetFileNames: (assetInfo) => {
                    // CSS 파일
                    if (assetInfo.name?.endsWith('.css')) {
                        return 'css/module[extname]';
                    }
                    // 폰트 파일
                    if (assetInfo.name?.match(/\.(woff|woff2|eot|ttf|otf)$/)) {
                        return 'assets/fonts/[name][extname]';
                    }
                    // 이미지 파일
                    if (assetInfo.name?.match(/\.(png|jpg|jpeg|gif|svg|webp)$/)) {
                        return 'assets/images/[name][extname]';
                    }
                    return 'assets/[name][extname]';
                },

                // JS 파일명 패턴
                entryFileNames: 'js/module.iife.js',
                chunkFileNames: 'js/[name]-[hash].js',
            },
        },

        // 빌드 최적화
        minify: 'esbuild',
        target: 'es2020',
        // CSS 는 lightningcss 로 줄인다 — 기본(esbuild) 압축은 oklch() 색을 근삿값 16진수로 바꿔 템플릿 CSS 와 같은 색이
        // 계산값에서 달라진다(Tailwind 산출과 같은 도구로 압축).
        cssMinify: 'lightningcss',
        // CSS 대상 브라우저는 Tailwind 4 기준(Safari 16.4·Chrome 111·Firefox 128) — 색·중첩을 낮추지 않는다.
        cssTarget: ['chrome111', 'edge111', 'firefox128', 'safari16.4'],

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

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
            name: 'SirsoftEcommerce', // 전역 변수명 (IIFE 모드용)
            fileName: 'module',
            formats: ['iife'], // IIFE 포맷만 빌드
        },

        // 빌드 출력 설정
        outDir: 'dist',
        emptyOutDir: true,

        // 소스맵 생성
        sourcemap: true,

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

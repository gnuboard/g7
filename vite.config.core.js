import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
    plugins: [react()],
    publicDir: false,
    // 환경 변수 정의 (React 빌드용)
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    build: {
        outDir: 'public/build/core',
        emptyOutDir: false,
        lib: {
            entry: path.resolve(__dirname, 'resources/js/core/template-engine.ts'),
            name: 'G7Core',
            formats: ['iife'],
            fileName: () => 'template-engine.min.js',
        },
        rollupOptions: {
            // React/ReactDOM을 external에서 제거하여 번들에 포함
            // external: ['react', 'react-dom'],
            output: {
                // React/ReactDOM이 번들에 포함되므로 globals 불필요
                // globals: {
                //     react: 'React',
                //     'react-dom': 'ReactDOM',
                // },
                exports: 'named',
                // IIFE 번들에서 전역 변수 자동 할당
                extend: true,
            },
        },
        minify: 'esbuild',
        // 배포용 빌드(--production)는 G7_BUILD_SOURCEMAP=0 을 주입해 소스맵을 생성하지 않는다.
        // 미설정(로컬 npm run build)이면 생성 — 개발 디버깅 경험을 유지한다.
        sourcemap: !['0', 'false'].includes(process.env.G7_BUILD_SOURCEMAP ?? ''),
        target: 'es2020',
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});

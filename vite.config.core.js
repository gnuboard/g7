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
        sourcemap: true,
        target: 'es2020',
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});

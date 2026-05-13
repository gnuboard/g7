import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { copyFileSync, mkdirSync, readdirSync } from 'fs';

// 다국어 파일 복사 플러그인
function copyLangFiles() {
    return {
        name: 'copy-lang-files',
        closeBundle() {
            const srcLangDir = path.resolve(__dirname, 'resources/js/core/lang');
            const destLangDir = path.resolve(__dirname, 'public/build/core/lang');

            mkdirSync(destLangDir, { recursive: true });

            // lang 디렉토리의 모든 .json 파일 복사
            const files = readdirSync(srcLangDir).filter(file => file.endsWith('.json'));

            files.forEach(file => {
                const src = path.join(srcLangDir, file);
                const dest = path.join(destLangDir, file);
                copyFileSync(src, dest);
                console.log(`✓ Copied ${file} to public/build/core/lang/`);
            });
        }
    };
}

export default defineConfig({
    plugins: [react(), copyLangFiles()],
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

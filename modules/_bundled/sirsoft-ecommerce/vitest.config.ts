import { defineConfig } from 'vitest/config';
import path from 'path';
import fs from 'fs';

// 프로젝트 루트를 동적으로 탐색 (artisan 파일 기준)
function findProjectRoot(startDir: string): string {
    let dir = startDir;
    while (dir !== path.dirname(dir)) {
        if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
        dir = path.dirname(dir);
    }
    return path.resolve(startDir, '../../'); // fallback
}

const projectRoot = findProjectRoot(__dirname);

export default defineConfig({
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        exclude: ['node_modules/', 'dist/'],
        setupFiles: ['./resources/js/tests/setup.ts'],
    },
    resolve: {
        // 관리자 템플릿 실물 부품(Select 등)을 레이아웃 렌더 시험에 들일 때 템플릿 폴더의 react 사본이 아니라
        // 렌더러와 같은 react 하나를 쓰게 한다 — 둘이 갈리면 부품의 훅이 「Invalid hook call」 로 그려지지 않는다
        dedupe: ['react', 'react-dom'],
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@core': path.resolve(projectRoot, 'resources/js/core'),
        },
    },
});

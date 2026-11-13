import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
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
    plugins: [react()],
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        exclude: ['node_modules/', 'dist/'],
    },
    resolve: {
        alias: {
            // 레이아웃을 엔진 렌더러로 그려 보는 시험이 렌더러와 같은 react 하나를 쓰게 한다 — 이 플러그인 폴더에 react 사본이 있어
            // 그대로 두면 렌더러는 이 사본을, 시험 도구(코어 쪽 @testing-library/react)는 코어의 react 를 읽어 훅 호출이 깨진다
            react: path.resolve(projectRoot, 'node_modules/react'),
            'react-dom': path.resolve(projectRoot, 'node_modules/react-dom'),
            '@': path.resolve(__dirname, 'resources/js'),
            '@core': path.resolve(projectRoot, 'resources/js/core'),
        },
    },
});

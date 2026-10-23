import { defineConfig } from 'vitest/config';
import path from 'path';
import fs from 'fs';

// 프로젝트 루트를 동적으로 탐색 (artisan 파일 기준)
// 이 설정이 없으면 레이아웃 렌더 시험이 `@core/...` 를 풀지 못해 시험 0건으로 실패했다(7.1.3 레이아웃 시험 도구 전수 실행에서 발견)
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
        environment: 'node',
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        exclude: ['node_modules/', 'dist/'],
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@core': path.resolve(projectRoot, 'resources/js/core'),
        },
    },
});

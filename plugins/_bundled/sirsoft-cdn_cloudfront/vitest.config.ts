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

// 이 플러그인은 npm 의존성을 따로 두지 않는다 — vitest·vite(CSS 옮기기 빌드)·렌더 도구는 저장소 루트에 설치된 것을 쓴다.
// 레이아웃 정적 시험은 DOM 이 필요 없어 기본 환경이 node 이고, 렌더 시험만 파일 머리에 jsdom 을 선언한다.
export default defineConfig({
    test: {
        globals: true,
        environment: 'node',
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        exclude: ['node_modules/', 'dist/'],
    },
    resolve: {
        dedupe: ['react', 'react-dom'],
        alias: {
            '@core': path.resolve(projectRoot, 'resources/js/core'),
        },
    },
});

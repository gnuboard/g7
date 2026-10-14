import { defineConfig } from 'vitest/config';

// 예제 플러그인은 npm 의존성을 따로 두지 않는다 — vitest 는 저장소 루트에 설치된 것을 쓴다.
// 레이아웃 정적 시험(굽기 산출물 JSON 판정)만 있어 DOM 환경이 필요 없다.
export default defineConfig({
    test: {
        globals: true,
        environment: 'node',
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        exclude: ['node_modules/', 'dist/'],
    },
});

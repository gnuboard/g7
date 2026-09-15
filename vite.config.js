import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    // 산출물 디렉토리를 비우지 않는다 — `public/build/` 에는 vite 가 만들지 않는
    // 서빙 자산이 함께 산다: 폴백이 없는 코어 3번들(`build/core/`, 동기 classic
    // 스크립트라 소실 = 사이트 부팅 불가)과 부트스트랩 정적 게시본(`build/ext/{v}/`,
    // 이미 배달된 HTML 의 immutable URL 이 참조한다). 기본값 true 는 빌드 때마다
    // 이들을 통째로 지워 404 를 만든다 (공개 #122). 잔존 구 해시 파일은
    // laravel-vite-plugin 이 `manifest.json` 으로 산출물을 선택하므로 참조되지 않는다.
    build: {
        emptyOutDir: false,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});

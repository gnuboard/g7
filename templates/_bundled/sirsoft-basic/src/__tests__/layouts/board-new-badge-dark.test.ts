// e2e:allow Vitest 레이아웃 계약 — 새글 배지 다크 색은 7.1.0 실측 K4(K4-D6)에서 Playwright 로 라이트·다크 computed 배경을 재측정했다.
/**
 * 게시판 새글(N) 배지 — 다크 모드에서 밝은 화면과 다른 전용 색을 쓴다.
 *
 * 7.1.0 은 게시글 보기의 배지를 `dark:bg-red-600` 으로 바로잡으며 「목록·갤러리 화면과 마찬가지로」라고 적었지만,
 * 목록(기본형·카드형·갤러리형)·인기글·홈 위젯의 배지는 `dark:bg-red-500`(밝은 화면과 같은 색)이거나 다크 클래스가
 * 없었다(실측: 목록 다크 모드 배지 14개 배경 oklch(0.637 0.237 25.331) = 라이트와 같음, 상세는 oklch(0.577 0.245 27.325)).
 * 모든 새글 배지가 상세와 같은 다크 전용 색을 쓰는지 소스 단위로 잠근다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const templateRoot = path.resolve(__dirname, '../../..');

/** 새글 배지를 그리는 레이아웃 조각 */
const BADGE_FILES = [
    'layouts/partials/board/types/basic/index.g7.html',
    'layouts/partials/board/types/basic/show.g7.html',
    'layouts/partials/board/types/card/index.g7.html',
    'layouts/partials/board/types/gallery/index.g7.html',
    'layouts/partials/board/popular/_popular_item.g7.html',
    'layouts/partials/home/_board_summary.g7.html',
    'layouts/partials/home/_recent_posts.g7.html',
];

/**
 * 레이아웃 조각에서 새글 배지 요소(`is_new` 조건이 붙은 span)의 class 를 모두 읽는다.
 *
 * @param file 템플릿 루트 기준 경로
 * @returns 배지 class 문자열 목록
 */
function badgeClasses(file: string): string[] {
    const src = fs.readFileSync(path.join(templateRoot, file), 'utf8');
    const out: string[] = [];
    const re = /<span[^>]*data-if="\{\{post\??\.(?:data\??\.)?is_new\}\}"[^>]*class="([^"]*)"/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(src)) !== null) {
        out.push(m[1]);
    }
    return out;
}

describe('게시판 새글 배지 다크 모드 색', () => {
    // @scenario surface=list_and_widgets
    // @effects new_badge_uses_dark_specific_color
    it('목록·상세·인기글·홈 위젯의 새글 배지가 모두 다크 전용 색(red-600)을 쓴다', () => {
        for (const file of BADGE_FILES) {
            const classes = badgeClasses(file);
            expect(classes.length, file).toBeGreaterThan(0);
            for (const cls of classes) {
                expect(cls, file).toContain('bg-red-500');
                expect(cls, file).toContain('dark:bg-red-600');
                expect(cls, file).not.toContain('dark:bg-red-500');
            }
        }
    });

    // @scenario surface=list_and_widgets
    // @effects new_badge_uses_dark_specific_color
    it('짝 JSON 레이아웃에도 같은 다크 색이 실려 있다', () => {
        for (const file of BADGE_FILES) {
            const json = fs.readFileSync(path.join(templateRoot, file.replace(/\.g7\.html$/, '.json')), 'utf8');
            expect(json, file).not.toContain('bg-red-500 dark:bg-red-500');
        }
    });
});

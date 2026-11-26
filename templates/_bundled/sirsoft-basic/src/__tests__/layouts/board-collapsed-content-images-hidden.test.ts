// e2e:allow Vitest 레이아웃 계약 — 브라우저 실측(2026-11-05)에서 Playwright 로 접힌 답글·원글 미리보기의 img computed display 와 이미지 요청 수를 재측정했다.
/**
 * 게시글 보기의 「접힌」 본문 미리보기 — 답글 목록의 답글 본문과 답글 보기 위에 붙는 원글 인용 — 는
 * 펼치기 전까지 본문의 이미지·표를 숨긴다(`[&_img]:hidden [&_table]:hidden`).
 *
 * 그런데 이 본문은 CKEditor5 플러그인의 `html_content` 확장 조각이 `.ck-content` 로 감싸 그리고, 플러그인이
 * 문서에 넣는 덮어쓰기 스타일 `.ck-content img { display: inline }`·`.ck-content figure.table { display: table }` 은
 * **레이어 밖**(unlayered) 규칙이다. Tailwind v4 유틸리티는 `@layer utilities` 안에 있어, 명시도와 무관하게
 * 레이어 밖 규칙이 이긴다 — 실측: 접힌 답글의 img computed display `inline`, 768×768 로 보이고 `?size=lg` 요청 1건.
 * 숨긴 본문 이미지를 내려받지 않는다는 전제(본문 img `loading=lazy` + 숨김이면 요청 0)가 깨진다.
 *
 * 레이어 밖 일반 규칙을 이기는 것은 레이어 안의 `!important` 다. 그래서 접힘 상태의 숨김은 `!hidden` 으로 쓴다.
 * 소스(`.g7.html`)와 굽힌 짝 `.json` 양쪽을 잠근다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const templateRoot = path.resolve(__dirname, '../../..');

/** 접힌 본문 미리보기를 그리는 레이아웃 조각 (소스·짝 JSON) */
const FILES = [
    'layouts/partials/board/show/_reply_section.g7.html',
    'layouts/partials/board/show/_reply_section.json',
    'layouts/partials/board/types/basic/show.g7.html',
    'layouts/partials/board/types/basic/show.json',
];

/**
 * 파일 원문에서 이미지 숨김 유틸리티 표기를 센다 (`.g7.html` 의 `&amp;` 는 `&` 로 되돌려 센다).
 *
 * @param file 템플릿 루트 기준 경로
 * @returns 표기별 개수
 */
function countHiding(file: string): { plainImg: number; importantImg: number; plainTable: number; importantTable: number } {
    const src = fs.readFileSync(path.join(templateRoot, file), 'utf8').replace(/&amp;/g, '&');

    return {
        plainImg: (src.match(/\[&_img\]:hidden/g) ?? []).length,
        importantImg: (src.match(/\[&_img\]:!hidden/g) ?? []).length,
        plainTable: (src.match(/\[&_table\]:hidden/g) ?? []).length,
        importantTable: (src.match(/\[&_table\]:!hidden/g) ?? []).length,
    };
}

describe('접힌 본문 미리보기의 이미지·표 숨김은 CKEditor 의 레이어 밖 규칙을 이긴다', () => {
    // @scenario screen=board_collapsed_preview
    // @effects collapsed_preview_hides_images_over_unlayered_rules
    it.each(FILES)('%s — 접힘 상태 숨김은 !hidden 만 쓴다', (file) => {
        const c = countHiding(file);

        // 확장 조각 props 와 기본(default) HtmlContent 두 자리
        expect(c.importantImg).toBe(2);
        expect(c.importantTable).toBe(2);
        expect(c.plainImg).toBe(0);
        expect(c.plainTable).toBe(0);
    });
});

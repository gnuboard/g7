// e2e:allow Vitest 언어 파일 계약 — 검색칸 안내는 7.1.0 실측 K3 후속(KF3-7)에서 Playwright 로 ko/en/ja 게시판 목록을 열어 재측정했다.
/**
 * 사용자 게시판 목록 검색칸 안내 문구 — 실제로 찾는 곳과 어긋나지 않는다.
 *
 * 게시판 목록(일반·카드·갤러리)의 검색칸은 옆의 「찾을 곳」 선택(제목+내용·제목·내용·작성자, 기본 제목+내용)과
 * 함께 제출된다. 그런데 안내 문구가 「제목, 내용, 작성자로 검색...」이라 기본 상태에서 작성자 이름을 넣으면
 * 0건이 나왔다(7.1.0 실측 K3 관측 O-K3-9 재측정: 작성자 이름으로 search_field=title_content → total 0,
 * search_field=author → total 22). 찾을 곳은 선택이 정하므로 안내는 특정 대상을 약속하지 않는다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const templateRoot = path.resolve(__dirname, '../../..');
const jaPack = path.resolve(templateRoot, '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/board.json');

/**
 * 언어 파일의 검색칸 안내 문구를 읽는다.
 *
 * @param file board.json 경로
 * @returns search_placeholder 값
 */
function placeholder(file: string): string {
    return JSON.parse(fs.readFileSync(file, 'utf8')).search_placeholder;
}

describe('게시판 목록 검색칸 안내 문구', () => {
    // @scenario locale=ko
    // @effects board_search_placeholder_matches_selected_field
    it('한국어 안내는 작성자를 약속하지 않는다', () => {
        expect(placeholder(path.join(templateRoot, 'lang/partial/ko/board.json'))).toBe('검색어를 입력하세요');
    });

    // @scenario locale=en
    // @effects board_search_placeholder_matches_selected_field
    it('영어 안내는 작성자를 약속하지 않는다', () => {
        expect(placeholder(path.join(templateRoot, 'lang/partial/en/board.json'))).toBe('Enter a search term');
    });

    // @scenario locale=ja
    // @effects board_search_placeholder_matches_selected_field
    it('일본어 언어팩 안내는 작성자를 약속하지 않는다', () => {
        expect(placeholder(jaPack)).toBe('検索キーワードを入力してください');
    });

    // @scenario locale=ko
    // @effects board_search_placeholder_matches_selected_field
    it('세 목록 유형이 같은 안내 키와 찾을 곳 선택을 함께 쓴다', () => {
        for (const type of ['basic', 'card', 'gallery']) {
            const src = fs.readFileSync(path.join(templateRoot, `layouts/partials/board/types/${type}/index.g7.html`), 'utf8');
            expect(src, type).toContain('placeholder="$t:board.search_placeholder"');
            expect(src, type).toContain('board_search_field_select');
        }
    });
});

// e2e:allow 레이아웃 소스 단언 — 화면 검증은 이력 문서의 en 검색 결과 「1 result found」 실측으로 한다.
/**
 * 숫자 + 단위 문구로 갈라 그리는 자리도 단위 키에 `count` 를 넘긴다 (7.1.0 P20)
 *
 * 검색 요약(「"검색어" 결과 1 results found」)과 마이페이지 게시판 활동 목록의 「(1 items)」은 수를 바인딩으로
 * 따로 찍고 단위만 번역 키로 붙인다. 단위 키가 `count` 를 받지 않으면 번역 엔진이 단수형(`_one`)을 고를 수 없다.
 */
import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const base = path.resolve(__dirname, '../../layouts/partials');
const read = (rel: string): string => fs.readFileSync(path.resolve(base, rel), 'utf-8');

const CASES: Array<[string, string, string]> = [
  ['search/_search_states.json', 'search.result_count_suffix', 'searchResults.data.total'],
  ['mypage/board/_my_comments.json', 'mypage.board_activities.search_result_unit', 'myComments?.data?.total'],
  ['mypage/board/_my_posts.json', 'mypage.board_activities.search_result_unit', 'myPosts?.data?.total'],
  ['mypage/board/_my_reactions.json', 'mypage.board_activities.search_result_unit', 'myReactions?.data?.pagination?.total'],
];

describe('숫자 + 단위 문구의 단위 키가 count 를 받는다', () => {
  for (const [file, key, total] of CASES) {
    // @scenario count_form=numeric
    // @effects one_category_selects_one_variant
    it(`${file} — $t:${key}|count={{${total} …}}`, () => {
      const src = read(file);
      expect(src).toContain(`$t:${key}|count={{${total}`);
    });
  }

  // @scenario count_form=numeric
  // @effects one_category_selects_one_variant
  it('en 사전에 단수 변형이 있다 (ko·ja 는 범주가 other 뿐이라 두지 않는다)', () => {
    const search = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../lang/partial/en/search.json'), 'utf-8'));
    const mypage = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../lang/partial/en/mypage.json'), 'utf-8'));
    expect(search.result_count_suffix_one).toBe(' result found');
    expect(mypage.board_activities.search_result_unit_one).toBe(' item');
  });
});

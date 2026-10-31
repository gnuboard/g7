/**
 * @file admin-board-posts-search-fields.test.ts
 * @description 관리자 게시글 목록 검색 구분 (#524 WP-B) — 제목·내용을 따로 고를 수 있어야 한다.
 *
 * 고치기 전에는 검색 대상이 「전체 / 작성자」뿐이었다. 전체는 제목과 내용을 함께 훑으므로,
 * 「제목에 그 말이 든 글만」을 찾을 방법이 없었다 — 본문에 흔한 낱말이 들어간 글이 전부 걸려
 * 운영자가 눈으로 다시 걸러야 했다. 그래서 `title_content` · `title` · `content` 세 갈래를 연다.
 *
 * 셀렉트 값은 서버 화이트리스트(`PostIndexRequest`)와 같은 문자열이어야 한다 — 다르면
 * 화면에는 보이지만 서버가 무시하는 «장식 셀렉트» 가 된다(계획서 부록 B D7).
 */

import { describe, it, expect } from 'vitest';
import { asArray, flattenNodes } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../../layouts/admin/admin_board_posts_index.json');
const koLang = require('../../../lang/partial/ko/admin/posts.json');
const enLang = require('../../../lang/partial/en/admin/posts.json');

const searchSelects = flattenNodes(layout)
    .filter((n: any) => asArray(n?.props?.options).some((o: any) => o.value === 'title_content'));

describe('관리자 게시글 목록 — 검색 구분 (#524 WP-B)', () => {
    // @scenario surface=admin_post_list, search_field=title_content
    // @effects admin_post_search_field_offers_five_targets
    it('검색 대상이 전체·제목+내용·제목·내용·작성자 다섯이다', () => {
        expect(searchSelects.length).toBeGreaterThanOrEqual(1);

        for (const select of searchSelects) {
            expect(asArray(select.props.options).map((o: any) => o.value)).toEqual([
                'all', 'title_content', 'title', 'content', 'author',
            ]);
        }
    });

    // @scenario surface=admin_post_list, search_field=title
    // @effects admin_post_search_field_values_match_server_whitelist
    it('셀렉트 값이 서버 화이트리스트와 같은 문자열이다 — 장식 셀렉트 금지', () => {
        const values = asArray(searchSelects[0].props.options).map((o: any) => o.value);

        // PostIndexRequest 의 in: 목록과 같아야 한다. 하나라도 다르면 서버가 조용히 무시한다.
        for (const value of ['all', 'title_content', 'title', 'content', 'author']) {
            expect(values).toContain(value);
        }
    });

    // @scenario surface=admin_post_list, search_field=content
    // @effects admin_post_search_field_labels_have_ko_en
    it('새로 연 세 갈래의 라벨이 ko·en 양쪽에 있다', () => {
        for (const key of ['title_content', 'title', 'content']) {
            expect(koLang.search_fields?.[key]).toBeTruthy();
            expect(enLang.search_fields?.[key]).toBeTruthy();
        }
    });

    // @scenario surface=admin_post_list, search_field=title
    // @effects admin_post_search_field_travels_in_filters_query
    it('고른 값이 filters[0][field] 로 서버에 간다', () => {
        const ds = layout.data_sources.find((d: any) => d.id === 'posts');

        expect(ds.params['filters[0][field]']).toMatch(/filters\[0\]\[field\]/);
        expect(ds.params['filters[0][operator]']).toMatch(/filters\[0\]\[operator\]/);
    });
});

/**
 * @file mypage-search-applied-empty-state.test.tsx
 * @description 마이페이지 목록 5곳(내 글, 내 댓글, 내 반응, 내 신고, 스크랩)의 검색어는 «친 값» 과 «적용된 값» 을 나눠 든다.
 *
 * 입력칸은 타이핑마다 검색어 상태를 바꾸고 조회는 검색 버튼(스크랩은 Enter 도)이 한다. 그런데 0건 안내가
 * 친 값으로 판정해, 글이 0건인 회원이 검색어를 치기만 해도 「검색 결과가 없습니다」가 떴고 검색으로 0건이 된 뒤
 * 입력칸만 비우면 「아직 없음」으로 바뀌었다. 조회 주소도 친 값을 읽어 게시판·정렬을 바꿀 때 검색 버튼을 누르지
 * 않은 검색어가 함께 실렸다. 이제 검색 실행이 친 값을 «적용된 값» 으로 옮기고, 조회 주소와 0건 안내가 모두 그
 * 값을 본다.
 */

import { describe, it, expect } from 'vitest';

const boardPage = require('../../layouts/mypage/board.json');
const scrapPage = require('../../layouts/mypage/scrap.json');

interface ListCase {
    name: string;
    page: any;
    dataSourceId: string;
    partial: any;
    scope: '_global' | '_local';
    typed: string;
    applied: string;
    /** 검색 실행 지점 수(버튼, 스크랩은 Enter 포함) */
    searchTriggers: number;
}

const cases: ListCase[] = [
    {
        name: '내 글', page: boardPage, dataSourceId: 'myPosts',
        partial: require('../../layouts/partials/mypage/board/_my_posts.json'),
        scope: '_global', typed: 'myPostsSearch', applied: 'myPostsSearchApplied', searchTriggers: 1,
    },
    {
        name: '내 댓글', page: boardPage, dataSourceId: 'myComments',
        partial: require('../../layouts/partials/mypage/board/_my_comments.json'),
        scope: '_global', typed: 'myCommentsSearch', applied: 'myCommentsSearchApplied', searchTriggers: 1,
    },
    {
        name: '내 반응', page: boardPage, dataSourceId: 'myReactions',
        partial: require('../../layouts/partials/mypage/board/_my_reactions.json'),
        scope: '_global', typed: 'myReactionsSearch', applied: 'myReactionsSearchApplied', searchTriggers: 1,
    },
    {
        name: '내 신고', page: boardPage, dataSourceId: 'myReports',
        partial: require('../../layouts/partials/mypage/board/_my_reports.json'),
        scope: '_global', typed: 'myReportsSearch', applied: 'myReportsSearchApplied', searchTriggers: 1,
    },
    {
        name: '스크랩', page: scrapPage, dataSourceId: 'scrap',
        partial: require('../../layouts/partials/mypage/scrap/_list.json'),
        scope: '_local', typed: 'scrapSearch', applied: 'scrapSearchApplied', searchTriggers: 2,
    },
];

function endpointOf(page: any, id: string): string {
    return page.data_sources.find((d: any) => d.id === id).endpoint;
}

function countOf(haystack: string, needle: string): number {
    return haystack.split(needle).length - 1;
}

describe('마이페이지 목록 검색어 — 친 값과 적용된 값', () => {
    for (const c of cases) {
        // @scenario list=all, trigger=search_button
        // @effects mypage_list_query_reads_applied_search
        it(`${c.name}: 조회 주소는 적용된 검색어를 싣는다`, () => {
            const endpoint = endpointOf(c.page, c.dataSourceId);
            expect(endpoint).toContain(`search={{${c.scope}.${c.applied} ?? ''}}`);
            expect(endpoint).not.toContain(`search={{${c.scope}.${c.typed} ??`);
        });

        // @scenario list=all, trigger=search_button
        // @effects mypage_search_trigger_applies_typed_search
        it(`${c.name}: 검색 실행이 친 값을 적용된 값으로 옮긴다`, () => {
            const json = JSON.stringify(c.partial);
            expect(countOf(json, `"${c.applied}":"{{${c.scope}.${c.typed} ?? ''}}"`)).toBe(c.searchTriggers);
        });

        // @scenario list=all, trigger=reset
        // @effects mypage_reset_clears_applied_search
        it(`${c.name}: 초기화는 적용된 검색어도 비운다`, () => {
            const json = JSON.stringify(c.partial);
            const resets = countOf(json, `"${c.typed}":""`);
            expect(resets).toBeGreaterThan(0);
            expect(countOf(json, `"${c.applied}":""`)).toBe(resets);
        });

        // @scenario list=all, trigger=typing
        // @effects mypage_empty_state_follows_applied_search
        it(`${c.name}: 0건 안내는 적용된 검색어로 판정한다(치기만 한 값은 보지 않는다)`, () => {
            const json = JSON.stringify(c.partial);
            expect(json).not.toContain(`!!${c.scope}.${c.typed} `);
            expect(json).not.toContain(`!!${c.scope}.${c.typed})`);
            expect(json).not.toContain(`!!${c.scope}.${c.typed}}`);
            expect(json).toContain(`!!${c.scope}.${c.applied}`);
        });

        // @scenario list=all, trigger=reset
        // @effects mypage_reset_clears_applied_search
        it(`${c.name}: 화면 진입 초기값에 적용된 검색어도 비운다`, () => {
            const json = JSON.stringify(c.page);
            expect(json).toMatch(new RegExp(`"${c.applied}"`));
        });
    }
});

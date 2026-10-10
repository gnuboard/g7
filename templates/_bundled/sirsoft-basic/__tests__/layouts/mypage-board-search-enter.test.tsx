/**
 * @file mypage-board-search-enter.test.tsx
 * @description 마이페이지 게시판 활동 검색칸 Enter 검색 (7.1.0 실측 O-K3-10)
 *
 * 「내 글」「내 댓글」「내 반응」「내 신고」 검색칸에서 Enter 를 쳐도 아무 일이 없고 옆 「검색」 버튼만
 * 동작했다(스크랩 목록은 이미 Enter 로 검색한다). 네 탭의 검색칸이 Enter 에서 검색 버튼과 같은 순서
 * (쪽수 1·적용 검색어 갱신 → 같은 데이터소스 재조회)를 타는지 잠근다. 적용 검색어는 Enter 를 친
 * 입력칸의 값에서 바로 읽는다 — 상태가 아직 따라오지 않은 마지막 글자(한글 조합 끝 글자 등)도 빠지지 않게.
 * 한글 조합 중 Enter 는 엔진 키 필터가 매칭에서 뺀다(조합 확정 Enter 로 이중 조회하지 않는다).
 *
 * @scenario tab=posts
 * @effects mypage_activity_enter_runs_search_button_sequence
 */

import { describe, it, expect } from 'vitest';
import { actionsOf, callbacksOf, flattenNodes } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const tabs = [
    { name: '내 글', layout: require('../../layouts/partials/mypage/board/_my_posts.json'), prefix: 'myPosts' },
    { name: '내 댓글', layout: require('../../layouts/partials/mypage/board/_my_comments.json'), prefix: 'myComments' },
    { name: '내 반응', layout: require('../../layouts/partials/mypage/board/_my_reactions.json'), prefix: 'myReactions' },
    { name: '내 신고', layout: require('../../layouts/partials/mypage/board/_my_reports.json'), prefix: 'myReports' },
];

/**
 * 검색어를 담는 입력칸(change 에서 `<prefix>Search` 를 담는 Input)을 찾는다.
 */
function searchInputOf(layout: unknown, prefix: string): any {
    return flattenNodes(layout).find((n: any) =>
        n.name === 'Input'
        && actionsOf(n).some((a: any) => a.type === 'change' && (a.params ?? {})[`${prefix}Search`] !== undefined));
}

/**
 * 「검색」 버튼의 click 순서를 찾는다(`<prefix>SearchApplied` 를 갱신하는 sequence).
 */
function searchButtonSequenceOf(layout: unknown, prefix: string): any {
    return flattenNodes(layout)
        .filter((n: any) => n.name === 'Button')
        .flatMap((n: any) => actionsOf(n))
        .find((a: any) => a.type === 'click' && a.handler === 'sequence'
            && callbacksOf(a, 'actions').some((s: any) => (s.params ?? {})[`${prefix}SearchApplied`] !== undefined));
}

describe('마이페이지 게시판 활동 검색칸 Enter 검색 (O-K3-10)', () => {
    for (const tab of tabs) {
        // @scenario tab=posts
        // @effects mypage_activity_enter_runs_search_button_sequence
        it(`${tab.name}: 검색칸 Enter 가 검색 버튼과 같은 순서를 탄다`, () => {
            const input = searchInputOf(tab.layout, tab.prefix);
            expect(input, `${tab.name} 검색칸`).toBeTruthy();

            const enter = actionsOf(input).find((a: any) => a.key === 'Enter');
            expect(enter, `${tab.name} 검색칸 Enter 액션`).toBeTruthy();
            expect(enter.type).toBe('keypress');
            expect(enter.handler).toBe('sequence');

            const button = searchButtonSequenceOf(tab.layout, tab.prefix);
            expect(button, `${tab.name} 검색 버튼`).toBeTruthy();

            const steps = callbacksOf(enter, 'actions');
            const buttonSteps = callbacksOf(button, 'actions');

            // 같은 단계 순서(상태 갱신들 → 재조회)
            expect(steps.map((s: any) => s.handler)).toEqual(buttonSteps.map((s: any) => s.handler));

            // 같은 데이터소스를 다시 받는다
            const refetch = steps.find((s: any) => s.handler === 'refetchDataSource');
            const buttonRefetch = buttonSteps.find((s: any) => s.handler === 'refetchDataSource');
            expect(refetch.params.dataSourceId).toBe(buttonRefetch.params.dataSourceId);

            // 쪽수 1, 적용 검색어는 입력칸 값에서
            const first = steps[0].params;
            expect(first[`${tab.prefix}Page`]).toBe(1);
            expect(String(first[`${tab.prefix}SearchApplied`])).toContain('$event.target.value');
            expect(String(first[`${tab.prefix}Search`])).toContain('$event.target.value');

            // 버튼이 함께 비우는 상태(선택 등)도 같게 비운다
            for (const [index, step] of buttonSteps.entries()) {
                for (const [key, value] of Object.entries(step.params ?? {})) {
                    if (key.endsWith('SearchApplied') || key.endsWith('Search')) {
                        continue;
                    }
                    expect(steps[index].params?.[key], `${tab.name} ${key}`).toEqual(value);
                }
            }
        });
    }
});

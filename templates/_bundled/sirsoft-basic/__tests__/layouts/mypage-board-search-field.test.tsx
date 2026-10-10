/**
 * @file mypage-board-search-field.test.tsx
 * @description 마이페이지 게시판 활동 검색 구분 (#524 WP-B) — 내 글과 내 댓글은 축이 다르다.
 *
 * 내 글은 제목·내용을 가르는 것이 자연스럽고(제목+내용 / 제목 / 내용),
 * 내 댓글은 «댓글 내용» 과 «원글 제목» 중에서 고르는 것이 자연스럽다 — 「어떤 글에 단 댓글인지」로
 * 찾는 경우가 많기 때문이다. 그래서 두 하위 탭의 갈래 목록이 서로 다르다.
 *
 * 두 탭은 각자의 검색·정렬·쪽수를 전역에 따로 들고 있다(`myPosts*` / `myComments*`) —
 * 탭을 오갈 때 상대의 조건이 끌려오면 "왜 결과가 달라졌나" 를 설명할 수 없다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../layouts/mypage/board.json');
const myPosts = require('../../layouts/partials/mypage/board/_my_posts.json');
const myComments = require('../../layouts/partials/mypage/board/_my_comments.json');
const koLang = require('../../lang/partial/ko/mypage.json');
const enLang = require('../../lang/partial/en/mypage.json');

function endpointOf(id: string): string {
    return layout.data_sources.find((d: any) => d.id === id).endpoint;
}

describe('마이페이지 게시판 활동 검색 구분 (#524 WP-B)', () => {
    // @scenario tab=posts
    // @effects mypage_posts_search_field_offers_three_targets
    it('내 글 검색 대상이 제목+내용·제목·내용 셋이다', () => {
        const select = findById(myPosts, 'my_posts_search_field_select');

        expect(asArray(select.props.options).map((o: any) => o.value)).toEqual([
            'title_content', 'title', 'content',
        ]);
    });

    // @scenario tab=comments
    // @effects mypage_comments_search_field_offers_content_and_post_title
    it('내 댓글 검색 대상은 댓글 내용과 원글 제목 둘이다 — 내 글과 축이 다르다', () => {
        const select = findById(myComments, 'my_comments_search_field_select');

        expect(asArray(select.props.options).map((o: any) => o.value)).toEqual([
            'content', 'post_title',
        ]);
    });

    // @scenario tab=posts
    // @effects mypage_posts_search_field_defaults_to_title_content
    it('내 글 기본값은 제목+내용이다', () => {
        const select = findById(myPosts, 'my_posts_search_field_select');

        expect(select.props.value).toMatch(/myPostsSearchField \?\? 'title_content'/);
        expect(endpointOf('myPosts')).toMatch(/search_field=\{\{_global\.myPostsSearchField \?\? 'title_content'\}\}/);
    });

    // @scenario tab=comments
    // @effects mypage_comments_search_field_defaults_to_content
    it('내 댓글 기본값은 댓글 내용이다', () => {
        const select = findById(myComments, 'my_comments_search_field_select');

        expect(select.props.value).toMatch(/myCommentsSearchField \?\? 'content'/);
        expect(endpointOf('myComments')).toMatch(/search_field=\{\{_global\.myCommentsSearchField \?\? 'content'\}\}/);
    });

    // @scenario tab=posts
    // @effects mypage_activity_endpoints_are_separate
    it('두 탭이 서로 다른 엔드포인트를 쓴다 — 댓글은 전용 목록이 있다', () => {
        expect(endpointOf('myPosts')).toContain('/me/board-activities');
        expect(endpointOf('myPosts')).toContain('activity_type=authored');
        expect(endpointOf('myComments')).toContain('/me/my-comments');
    });

    // @scenario tab=comments
    // @effects mypage_activity_state_is_namespaced_per_tab
    it('검색·정렬·쪽수를 탭마다 따로 들고 있다 — 탭을 오가도 섞이지 않는다', () => {
        const postsEndpoint = endpointOf('myPosts');
        const commentsEndpoint = endpointOf('myComments');

        for (const key of ['myPostsPage', 'myPostsSearch', 'myPostsSearchField', 'myPostsSort']) {
            expect(postsEndpoint).toContain(key);
            expect(commentsEndpoint).not.toContain(key);
        }
        for (const key of ['myCommentsPage', 'myCommentsSearch', 'myCommentsSearchField', 'myCommentsSort']) {
            expect(commentsEndpoint).toContain(key);
            expect(postsEndpoint).not.toContain(key);
        }
    });

    // @scenario tab=posts
    // @effects mypage_activity_select_only_stores_value
    it('셀렉트는 담아만 두고 조회는 검색이 한다 — 고르는 즉시 목록이 튀지 않는다', () => {
        for (const [partial, id, key] of [
            [myPosts, 'my_posts_search_field_select', 'myPostsSearchField'],
            [myComments, 'my_comments_search_field_select', 'myCommentsSearchField'],
        ] as const) {
            const action = actionsOf(findById(partial, id))[0];

            expect(action.handler).toBe('setState');
            expect(action.params[key]).toMatch(/\$event\.target\.value/);
        }
    });

    // @scenario tab=posts
    // @effects mypage_activity_every_reset_clears_search_field
    it('검색어를 비우는 자리는 «전부» 검색 대상도 함께 되돌린다', () => {
        // 모집단을 손으로 적지 않는다 — 레이아웃에서 「검색어를 빈 문자열로 되돌리는 setState」를
        // 전부 긁어 온다. 초기화 버튼이 하나 더 늘어도 이 검사가 따라간다.
        // (검색창 입력은 $event.target.value 를 넣으므로 여기 걸리지 않는다)
        for (const [partial, searchKey, fieldKey, fallback] of [
            [myPosts, 'myPostsSearch', 'myPostsSearchField', 'title_content'],
            [myComments, 'myCommentsSearch', 'myCommentsSearchField', 'content'],
        ] as const) {
            const resets = flattenNodes(partial)
                .flatMap((node: any) => actionsOf(node))
                .filter((a: any) => a?.handler === 'setState' && a?.params?.[searchKey] === '');

            // 검색 바 초기화 + 검색 결과 배너 초기화 = 최소 두 자리
            expect(resets.length).toBeGreaterThanOrEqual(2);

            for (const action of resets) {
                expect(action.params[fieldKey]).toBe(fallback);
            }
        }
    });

    // @scenario tab=posts
    // @effects mypage_activity_search_field_labels_have_ko_en
    it('두 축의 라벨이 ko·en 양쪽에 있다', () => {
        for (const lang of [koLang, enLang]) {
            for (const key of ['title_content', 'title', 'content']) {
                expect(lang.board_activities?.search_fields?.[key]).toBeTruthy();
            }
            for (const key of ['content', 'post_title']) {
                expect(lang.board_activities?.comment_search_fields?.[key]).toBeTruthy();
            }
        }
    });
});

describe('마이페이지 게시판 활동 — 검색 0건 안내', () => {
    // @scenario tab=posts
    // @effects mypage_filtered_empty_says_no_results
    it('내 글·내 댓글은 검색어나 게시판을 건 채 0건이면 검색 결과 없음을 낸다', () => {
        const posts = JSON.stringify(myPosts);
        const comments = JSON.stringify(myComments);
        expect(posts.includes('{{!!_global.myPostsSearchApplied || !!_global.myPostsBoard}}')).toBe(true);
        expect(comments.includes('{{!!_global.myCommentsSearchApplied || !!_global.myCommentsBoard}}')).toBe(true);
        expect(posts.includes('$t:board.no_search_results')).toBe(true);
        expect(comments.includes('$t:board.no_search_results')).toBe(true);
    });
});

describe('내 글·내 댓글 — 좁은 화면(390px) 검색 줄 (K2 실측 2026-10-06)', () => {
    // @scenario tab=posts
    // @effects mypage_activity_search_input_shrinks_on_narrow_screen
    it('내 글 검색 입력칸이 줄어들 수 있다', () => {
        // 입력칸의 고유 최소 폭 때문에 390px 에서 「검색」·「초기화」가 화면 밖(오른쪽 449px)으로 밀렸다.
        const inputs = flattenNodes(myPosts).filter((n: any) => n?.name === 'Input' && n?.props?.type === 'text');

        expect(inputs.length).toBe(1);
        expect(inputs[0].props.className).toContain('min-w-0');
    });

    // @scenario tab=comments
    // @effects mypage_activity_search_input_shrinks_on_narrow_screen
    it('내 댓글 검색 입력칸이 줄어들 수 있다', () => {
        // 같은 이유로 「초기화」가 오른쪽 444px 까지 밀렸다.
        const inputs = flattenNodes(myComments).filter((n: any) => n?.name === 'Input' && n?.props?.type === 'text');

        expect(inputs.length).toBe(1);
        expect(inputs[0].props.className).toContain('min-w-0');
    });
});

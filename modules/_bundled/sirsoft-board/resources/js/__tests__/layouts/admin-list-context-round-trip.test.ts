/**
 * @file admin-list-context-round-trip.test.ts
 * @description 관리자 게시판 목록 컨텍스트 왕복 시 URL 목록 상태 보존 회귀 (#75)
 *
 * 결함: 목록(`/admin/board/{slug}`) 에서 상세로 들어간 뒤 이전글/다음글/폼취소/삭제후복귀를
 *   거치면 navigate 가 `mergeQuery` 없이 실행되어 page/search/category/filters 가 URL 에서
 *   사라졌다. 그 뒤 '목록' 버튼은 복원할 상태가 없어 1페이지로 되돌아간다.
 *
 * 검증 방식 2단:
 *   1. 실 레이아웃 JSON 전수 분석 — 클러스터 내 navigate 가 모두 `mergeQuery: true` 인지
 *   2. 실 액션을 엔진에 태워 실행(`triggerAction`) — 선언이 실제 목적지 URL 로 이어지는지
 *   손으로 쓴 조각이 아니라 실 레이아웃 JSON 을 읽는다. 실파일이 규약을 어기면 red 여야 한다.
 *   선언만 검사하면 "JSON 에 리터럴이 적혀 있다" 까지만 고정되고 병합 결과는 미검증으로 남는다.
 *
 * @scenario leg=row_click|prev|next|form_cancel|delete_return
 * @effects navigation_preserves_list_query
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import fs from 'fs';
import path from 'path';

import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts/admin');

/**
 * 레이아웃 파일 → 그 화면이 속한 목록 클러스터.
 *
 * 클러스터는 파일 단위로 다르다. 예를 들어 게시판 목록(`/admin/boards`)에서 특정 게시판의
 * 글 목록(`/admin/board/{slug}`)으로 가는 것은 왕복이 아니라 **다른 목록으로의 진입**이므로
 * 병합 대상이 아니다. 확장 전체에 하나의 목록 목록을 적용하면 이런 이동까지 위반으로 잡힌다.
 */
const CLUSTER_FILES: Array<[string, string]> = [
    ['admin_board_posts_index.json', '/admin/board/*'],
    ['admin_board_post_detail.json', '/admin/board/*'],
    ['admin_board_post_form.json', '/admin/board/*'],
    ['partials/admin_board_post_detail/_post_card_content.json', '/admin/board/*'],
    ['partials/admin_board_post_detail/_reply_card_content.json', '/admin/board/*'],
    ['admin_board_index.json', '/admin/boards'],
    ['admin_board_form.json', '/admin/boards'],
    ['admin_board_reports_index.json', '/admin/boards/reports'],
    ['admin_board_reports_detail.json', '/admin/boards/reports'],
    ['partials/admin_board_reports_detail/_card_report_info.json', '/admin/boards/reports'],
];

type NavAction = {
    handler: string;
    comment?: string;
    params: { path?: string; mergeQuery?: unknown; query?: unknown };
};

/**
 * JSON 트리에서 navigate/replaceUrl 액션을 모두 수집합니다.
 *
 * @param node 순회 시작 노드
 * @returns 액션 목록
 */
function collectNavActions(node: unknown): NavAction[] {
    const out: NavAction[] = [];
    const walk = (n: unknown): void => {
        if (Array.isArray(n)) {
            n.forEach(walk);
            return;
        }
        if (!n || typeof n !== 'object') return;
        const obj = n as Record<string, unknown>;
        if (
            (obj.handler === 'navigate' || obj.handler === 'replaceUrl') &&
            obj.params &&
            typeof obj.params === 'object'
        ) {
            out.push(obj as unknown as NavAction);
        }
        Object.values(obj).forEach(walk);
    };
    walk(node);
    return out;
}

/**
 * 목적지 경로를 비교용 표준형(`{{expr}}` → `*`)으로 바꿉니다.
 *
 * @param raw 원본 경로
 * @returns 표준형 경로 (판정 불가 시 null)
 */
function normalizePath(raw: unknown): string | null {
    if (typeof raw !== 'string' || raw === '') return null;
    const p = raw.replace(/\{\{[^}]*\}\}/g, '*').split('?')[0].replace(/\/+$/, '');
    return p.startsWith('/') ? p : null;
}

/**
 * 경로가 목록 라우트와 같은 클러스터인지 판정합니다.
 *
 * @param dest 표준형 목적지
 * @param list 표준형 목록 라우트
 * @returns 같은 클러스터 여부
 */
function inFamily(dest: string, list: string): boolean {
    return dest === list || dest.startsWith(`${list}/`);
}

/**
 * 레이아웃 파일을 읽어 파싱합니다.
 *
 * @param rel 레이아웃 루트 기준 상대 경로
 * @returns 파싱된 JSON
 */
function readLayout(rel: string): unknown {
    return JSON.parse(fs.readFileSync(path.join(LAYOUTS_ROOT, rel), 'utf8'));
}

describe('#75 관리자 게시판 목록 컨텍스트 왕복 — mergeQuery 전수', () => {
    it.each(CLUSTER_FILES)('%s (클러스터 %s) 내 navigate 는 모두 mergeQuery: true', (rel, list) => {
        const offenders = collectNavActions(readLayout(rel))
            .filter((a) => {
                if (typeof a.comment === 'string' && a.comment.includes('audit:allow')) return false;
                const dest = normalizePath(a.params.path);
                if (!dest) return a.params.mergeQuery !== undefined && a.params.mergeQuery !== true;
                if (!inFamily(dest, list)) return false;
                return a.params.mergeQuery !== true;
            })
            .map((a) => `${a.params.path} (mergeQuery=${JSON.stringify(a.params.mergeQuery)})`);

        expect(offenders, `목록 상태를 떨구는 navigate: \n  ${offenders.join('\n  ')}`).toEqual([]);
    });
});

describe('#75 개별 leg 고정', () => {
    /**
     * 특정 목적지로 가는 첫 navigate 액션을 찾습니다.
     *
     * @param rel 레이아웃 상대 경로
     * @param destPath 목적지 경로(원문 그대로)
     * @returns 액션
     */
    const leg = (rel: string, destPath: string): NavAction => {
        const hit = collectNavActions(readLayout(rel)).find((a) => a.params.path === destPath);
        if (!hit) throw new Error(`${rel} 에서 "${destPath}" navigate 를 찾지 못했습니다.`);
        return hit;
    };

    it('상세 이전글 leg 이 목록 상태를 승계한다', () => {
        const a = leg(
            'partials/admin_board_post_detail/_post_card_content.json',
            '/admin/board/{{route.slug}}/post/{{post?.data?.navigation?.prev?.id}}'
        );
        expect(a.params.mergeQuery).toBe(true);
    });

    it('상세 다음글 leg 이 목록 상태를 승계한다', () => {
        const a = leg(
            'partials/admin_board_post_detail/_post_card_content.json',
            '/admin/board/{{route.slug}}/post/{{post?.data?.navigation?.next?.id}}'
        );
        expect(a.params.mergeQuery).toBe(true);
    });

    it("상세 '목록' 버튼이 목록 상태를 복원한다", () => {
        const a = leg('admin_board_post_detail.json', '/admin/board/{{route.slug}}');
        expect(a.params.mergeQuery).toBe(true);
    });

    it('목록 행 클릭이 목록 상태를 상세로 나른다', () => {
        const a = leg('admin_board_posts_index.json', '/admin/board/{{row.slug}}/post/{{row.id}}');
        expect(a.params.mergeQuery).toBe(true);
    });

    it('삭제 확인 후 복귀 navigate 의 mergeQuery 가 분기 표현식이 아니다', () => {
        const dynamic = collectNavActions(readLayout('admin_board_post_detail.json')).filter(
            (a) => 'mergeQuery' in a.params && typeof a.params.mergeQuery !== 'boolean'
        );
        expect(dynamic.map((a) => a.params.path)).toEqual([]);
    });
});

// ---------------------------------------------------------------------------
// 실행 검증 — 실 액션을 엔진에 태워 목적지 URL 을 확인한다
// ---------------------------------------------------------------------------

/**
 * 실 JSON 액션을 현재 URL 상태에서 실행하고 목적지를 돌려줍니다.
 *
 * @param action 실 레이아웃 JSON 에서 뽑은 navigate 액션
 * @returns navigate 목적지 경로
 */
async function runNav(action: NavAction): Promise<string> {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'nav_action_probe',
            data_sources: [],
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
        } as never,
        { componentRegistry: createMockComponentRegistryWithBasics() as never, locale: 'ko' }
    );
    await utils.render();
    await utils.triggerAction({ type: 'click', ...action } as never);
    const dest = utils.getNavigationHistory()[0];
    utils.cleanup();
    return dest;
}

/**
 * 목적지 URL 의 쿼리스트링을 파싱합니다.
 *
 * @param dest navigate 목적지
 * @returns URLSearchParams
 */
function queryOf(dest: string): URLSearchParams {
    return new URLSearchParams(dest.includes('?') ? dest.slice(dest.indexOf('?')) : '');
}

/**
 * 특정 목적지로 가는 첫 navigate 액션을 찾습니다.
 *
 * @param rel 레이아웃 상대 경로
 * @param destPath 목적지 경로(원문 그대로)
 * @returns 액션
 */
function navLeg(rel: string, destPath: string): NavAction {
    const hit = collectNavActions(readLayout(rel)).find((a) => a.params.path === destPath);
    if (!hit) throw new Error(`${rel} 에서 "${destPath}" navigate 를 찾지 못했습니다.`);
    return hit;
}

describe('#75 관리자 게시판 목록 컨텍스트 왕복 — 실행 결과 URL', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    const legs: Array<[string, string]> = [
        ['이전글', '/admin/board/{{route.slug}}/post/{{post?.data?.navigation?.prev?.id}}'],
        ['다음글', '/admin/board/{{route.slug}}/post/{{post?.data?.navigation?.next?.id}}'],
    ];

    it.each(legs)('%s leg 실행 시 page/search/category 가 모두 살아남는다', async (_label, destPath) => {
        window.history.replaceState({}, '', '/admin/board/free/post/45?page=2&search=테스트&category=공지');

        const q = queryOf(await runNav(navLeg('partials/admin_board_post_detail/_post_card_content.json', destPath)));

        expect(q.get('page')).toBe('2');
        expect(q.get('search')).toBe('테스트');
        expect(q.get('category')).toBe('공지');
    });

    it("상세 '목록' 버튼 실행 시 목록 상태가 그대로 복귀된다", async () => {
        window.history.replaceState({}, '', '/admin/board/free/post/45?page=2&search=테스트');

        const q = queryOf(await runNav(navLeg('admin_board_post_detail.json', '/admin/board/{{route.slug}}')));

        expect(q.get('page')).toBe('2');
        expect(q.get('search')).toBe('테스트');
    });

    it('검색 실행(Enter) 은 page 를 떨구고 표시 개수는 유지한다', async () => {
        window.history.replaceState({}, '', '/admin/board/free?page=5&per_page=30&sort_by=created_at');

        const action = collectNavActions(readLayout('admin_board_posts_index.json')).find(
            (a) => (a as unknown as { type?: string }).type === 'keypress'
        );
        expect(action, 'admin_board_posts_index.json 에 검색 Enter navigate 가 없습니다.').toBeTruthy();

        const q = queryOf(await runNav(action as NavAction));

        // page 는 새 검색이므로 1페이지로 되돌린다(파라미터 제거). 되돌리지 않으면 결과의 5페이지로 가 빈 화면이 열린다.
        expect(q.get('page')).toBeNull();
        expect(q.get('per_page')).toBe('30');
        expect(q.get('sort_by')).toBe('created_at');
    });

    it('이전글 → 목록 순서로 이어가도 목록 페이지가 복원된다', async () => {
        window.history.replaceState({}, '', '/admin/board/free/post/45?page=2&search=테스트');

        // ① 이전글로 이동 → 이동 결과를 실제 URL 로 반영 (다음 leg 의 mergeQuery 입력)
        const afterPrev = await runNav(
            navLeg(
                'partials/admin_board_post_detail/_post_card_content.json',
                '/admin/board/{{route.slug}}/post/{{post?.data?.navigation?.prev?.id}}'
            )
        );
        window.history.replaceState({}, '', afterPrev);

        // ② 그 상태에서 '목록' 복귀
        const q = queryOf(await runNav(navLeg('admin_board_post_detail.json', '/admin/board/{{route.slug}}')));

        expect(q.get('page')).toBe('2');
        expect(q.get('search')).toBe('테스트');
    });
});

// ---------------------------------------------------------------------------
// 검색·필터 변경 시 page 되돌림 (#75)
//
// mergeQuery 는 현재 페이지까지 함께 나른다. 그래서 목록을 좁히는 조건(검색어·카테고리·상태)을
// 바꾸는 액션은 page 를 명시적으로 되돌려야 한다. 되돌리지 않으면 5페이지를 보던 중 검색했을 때
// 결과의 5페이지로 이동해 빈 화면이 열린다. 한 화면 안에서 진입점마다 갈리면(버튼은 되돌리고
// Enter 는 안 되돌림) 같은 기능이 다르게 동작한다.
// ---------------------------------------------------------------------------

/** 목록을 좁히는 조건 키 — 바뀌면 결과 건수가 달라지므로 page 를 되돌려야 한다. */
const NARROWING_KEY = /^(filters\[\d+\]\[value\]|search|search_keyword|category|status|target_status|type)$/;

/** page 되돌림으로 인정하는 값 — `""`(파라미터 제거) 또는 1. */
function isPageReset(value: unknown): boolean {
    return value === '' || value === 1 || value === '1';
}

describe('#75 검색·필터 액션은 page 를 되돌린다 — 전수', () => {
    it.each([['admin_board_posts_index.json'], ['admin_board_reports_index.json']])(
        '%s 의 조건 변경 navigate 는 모두 page 를 되돌린다',
        (rel) => {
            const offenders = collectNavActions(readLayout(rel))
                .filter((a) => {
                    const q = a.params.query;
                    if (!q || typeof q !== 'object' || Array.isArray(q)) return false;
                    const keys = Object.keys(q as Record<string, unknown>);
                    if (!keys.some((k) => NARROWING_KEY.test(k))) return false;
                    return !isPageReset((q as Record<string, unknown>).page);
                })
                .map((a) => `${a.params.path} → ${JSON.stringify(a.params.query)}`);

            expect(offenders, `조건을 바꾸면서 page 를 그대로 두는 navigate: \n  ${offenders.join('\n  ')}`).toEqual(
                []
            );
        }
    );

    // mergeQuery 가 이미 현재 정렬을 나르므로, 검색 액션이 정렬을 다시 적어 보낼 필요가 없다.
    // 열거가 남아 있으면 다음 편집자가 "여기에 적힌 값이 정렬의 근거" 로 오해한다 (기본값은 데이터소스가 정한다).
    it('신고 목록 검색 액션은 정렬 키를 다시 적어 보내지 않는다', () => {
        const search = (readLayout('admin_board_reports_index.json') as {
            named_actions: { searchBoardReports: NavAction };
        }).named_actions.searchBoardReports;

        const keys = Object.keys(search.params.query as Record<string, unknown>);

        expect(keys).not.toContain('sort_by');
        expect(keys).not.toContain('sort_order');
    });
});

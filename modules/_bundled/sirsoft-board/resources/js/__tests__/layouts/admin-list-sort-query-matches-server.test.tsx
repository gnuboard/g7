// e2e:allow 엔진 렌더(표 정렬 머리 → 이동 쿼리 값) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(게시판 신고·반응·스크랩 목록 머리 정렬 뒤 목록 요청·순서)이 덮는다.
/**
 * @file admin-list-sort-query-matches-server.test.tsx
 * @description 게시판 신고·반응·스크랩 관리 목록의 표 머리 정렬이 서버가 정렬하는 기준과 맞는다 (7.1.1)
 *
 * 세 목록의 서버는 정렬 기준 열을 받지 않는다 — 신고(`IndexReportRequest:46-47`)는 `sort_by` 를 받지 않고 저장소가 `last_reported_at`
 * 고정(`ReportRepository:345-351`), 반응(`IndexReactionRequest:59`)·스크랩(`IndexScrapRequest:56`)은 `sort_order` 만 받고 `created_at`
 * 고정(`ReactionRepository:301-306`·`PostScrapRepository:194-198`). 그런데 표는 신고 8열·반응 6열·스크랩 6열 모두를 정렬 가능하게 그려
 * (DataGrid 는 `:sortable="false"` 가 아니면 정렬 가능), 「상태」·「게시판」 등 어느 머리를 눌러도 날짜 방향만 뒤집혔다 — 화살표만 그 열로
 * 옮겨 가 그 열로 정렬된 것처럼 보였다.
 *
 * 고침(서버 무변경): 날짜 열(신고일·반응일·스크랩일) 하나만 정렬 가능하게 남기고 나머지 열은 `:sortable="false"`, 신고 목록은 서버가 받지
 * 않는 `sort_by` 를 보내지 않고 `sort_order` 만 보낸다(반응·스크랩과 같은 꼴).
 *
 * 판정: 정렬 가능한 열이 곧 서버가 정렬하는 열이고, 그 머리의 두 방향이 서버 허용 형식의 쿼리로 이동한다. 정렬 불가 열은 머리에 정렬 단추가
 * 없다(DataGrid 는 정렬 가능한 열에만 머리 단추를 그린다 — 7.1.1 아래 대역도 같은 규칙으로 그린다).
 *
 * @scenario-manifest tests/scenarios/admin-list-sort-query-matches-server.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import React from 'react';
import userEvent from '@testing-library/user-event';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    waitFor,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import reportsIndex from '../../../layouts/admin/admin_board_reports_index.json';
import reactionsIndex from '../../../layouts/admin/admin_board_reactions_index.json';
import scrapsIndex from '../../../layouts/admin/admin_board_scraps_index.json';

type Node = Record<string, any>;

/**
 * DataGrid 노드를 찾는다.
 *
 * @param x 탐색 대상
 * @returns DataGrid 노드
 */
function findGrid(x: any): Node | null {
    if (Array.isArray(x)) {
        for (const y of x) {
            const r = findGrid(y);
            if (r) return r;
        }

        return null;
    }
    if (!x || typeof x !== 'object') return null;
    if (x.name === 'DataGrid') return x;
    for (const v of Object.values(x)) {
        const r = findGrid(v);
        if (r) return r;
    }

    return null;
}

/**
 * 표의 정렬 가능한 열 — DataGrid 는 열의 sortable 이 false 일 때만 정렬하지 않는다.
 *
 * @param grid DataGrid 노드
 * @returns 열 필드 목록
 */
const sortableFieldsOf = (grid: Node): string[] =>
    ((grid.props?.columns ?? []) as any[]).filter((c) => c.sortable !== false).map((c) => String(c.field)).sort();

/** 화면 → [표 노드, 서버가 정렬하는 열] — 서버는 sort_by 를 받지 않고 sort_order(asc·desc)만 받는다(위 머리 주석의 file:line) */
const SCREENS: Array<[string, Node, string]> = [
    ['신고', findGrid(reportsIndex)!, 'created_at'],
    ['반응', findGrid(reactionsIndex)!, 'created_at'],
    ['스크랩', findGrid(scrapsIndex)!, 'created_at'],
];

describe('게시판 관리 목록 표 머리 정렬 → 서버가 받는 쿼리 (7.1.1)', () => {
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        // DataGrid 대역 — 정렬 가능한 열에만 「오름·내림」 머리 단추를 그려 실제 DataGrid 처럼 onSortChange(field, direction) 을 낸다
        registry.register('composite', 'DataGrid', ({ columns, onSortChange }: any) =>
            React.createElement('div', null, (columns ?? []).filter((c: any) => c.sortable !== false).flatMap((c: any) =>
                ['asc', 'desc'].map((d) => React.createElement('button', { key: `${c.field}-${d}`, type: 'button', onClick: () => onSortChange?.(c.field, d) }, `${c.field}:${d}`)))));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    /**
     * 신고·반응·스크랩 세 표 각각에서 정렬 가능한 열이 서버가 정렬하는 날짜 열 하나뿐이다(방향과 무관한 구조 단언).
     *
     * @effects board_admin_list_only_server_sort_column_sortable
     */
    it.each(SCREENS)('%s 목록 — 정렬 가능한 열은 서버가 정렬하는 날짜 열 하나다(나머지 머리엔 정렬 단추 없음)', (_name, grid, serverField) => {
        expect(grid, '표를 찾지 못했다').toBeTruthy();
        expect(sortableFieldsOf(grid)).toEqual([serverField]);
    });

    // 이 it.each 가 실제로 도는 (화면, 방향) 6행 — docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 적는다.
    // @scenario screen=reports, direction=asc
    // @scenario screen=reports, direction=desc
    // @scenario screen=reactions, direction=asc
    // @scenario screen=reactions, direction=desc
    // @scenario screen=scraps, direction=asc
    // @scenario screen=scraps, direction=desc
    // @effects admin_list_sort_query_matches_server
    it.each(SCREENS.flatMap(([name, grid, field]) => ['asc', 'desc'].map((d) => [name, grid, field, d] as const)))(
        '%s 목록 — 날짜 머리 → sort_order 만 보내고 sort_by 는 보내지 않는다',
        async (_name, grid, field, dir) => {
            const utils = createLayoutTest({ version: '1.0.0', layout_name: 'test/board-sort-query', components: [grid] } as any, { componentRegistry: registry as any, locale: 'ko' });
            await utils.render();
            await userEvent.setup().click(screen.getByText(`${field}:${dir}`));
            await waitFor(() => expect(utils.mockNavigate).toHaveBeenCalled());
            const target = String(utils.mockNavigate.mock.calls.at(-1)?.[0] ?? '');
            const q = new URL(target, 'http://x').searchParams;
            expect(q.get('sort_order'), target).toBe(dir);
            expect(q.has('sort_by'), target).toBe(false);
            expect(q.get('page'), target).toBe('1');
            utils.cleanup();
        },
    );

    /**
     * 신고 목록 날짜 열 — 큰 글자가 서버 정렬 기준(마지막 신고 시각)을 보인다. 서버는 재신고된 건을 `last_reported_at` 으로 위로 올리는데
     * 칸은 처음 신고 시각을 크게 그려, 재신고된 건이 큰 날짜 순서와 어긋난 자리에 섞여 보였다(7.1.0 부터). 서버 순서(내림차순)대로 놓은
     * 두 행의 큰 글자가 내림차순이고, 재신고된 건은 처음 신고 시각을 「최초」 보조 줄로 함께 보인다.
     *
     * @scenario screen=reports, direction=desc
     * @effects board_report_date_column_shows_server_sort_key
     */
    it('신고 목록 — 날짜 열 큰 글자는 서버 정렬 기준(마지막 신고 시각)이고 처음 신고 시각은 보조 줄로 보인다', () => {
        const grid = findGrid(reportsIndex)!;
        const col = ((grid.props?.columns ?? []) as any[]).find((c) => c.sortable !== false)!;
        const wrapper = col.cellChildren?.[0];
        const [primary, sub] = (wrapper?.children ?? []) as Node[];
        const evaluate = (expr: string, row: Record<string, unknown>): unknown =>
            // eslint-disable-next-line no-new-func
            new Function('row', `return (${String(expr).replace(/^\{\{|\}\}$/g, '')});`)(row);
        // 서버 내림차순(last_reported_at) 그대로 — 재신고로 올라온 A 가 먼저, 한 번만 신고된 B 가 다음
        const rows = [
            { created_at: '2026-01-01 09:00', last_reported_at: '2026-03-01 09:00', report_count: 3, is_reactivated: false },
            { created_at: '2026-02-01 09:00', last_reported_at: '2026-02-01 09:00', report_count: 1, is_reactivated: false },
        ];
        const shown = rows.map((r) => String(evaluate(primary.text, r)));
        expect(shown).toEqual(['2026-03-01 09:00', '2026-02-01 09:00']);
        // 재신고된 A 는 처음 신고 시각을 「최초」 라벨과 함께 보조 줄로, 한 번만 신고된 B 는 보조 줄 없음
        expect(Boolean(evaluate(sub.if, rows[0]))).toBe(true);
        expect(Boolean(evaluate(sub.if, rows[1]))).toBe(false);
        const [label, value] = (sub.children ?? []) as Node[];
        expect(label.text).toBe('$t:sirsoft-board.admin.reports.columns.first_reported_at_label');
        expect(String(evaluate(value.text, rows[0]))).toBe('2026-01-01 09:00');
    });
});

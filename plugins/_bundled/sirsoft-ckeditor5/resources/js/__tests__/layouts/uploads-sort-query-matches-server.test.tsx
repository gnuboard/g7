// e2e:allow 엔진 렌더(표 정렬 머리 → 이동 쿼리 값) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(CKEditor5 업로드 목록 머리 정렬 뒤 목록 요청 200)이 덮는다.
/**
 * @file uploads-sort-query-matches-server.test.tsx
 * @description CKEditor5 업로드 목록 표 머리 정렬이 서버가 받는 열로만 나간다 (7.1.1)
 *
 * 서버(`src/Http/Requests/IndexUploadsRequest.php:28` `SORTABLE = ['created_at', 'file_size', 'original_name']`, `:80` `sort_by` 의
 * `Rule::in(self::SORTABLE)`)는 세 열만 받는데, 표는 썸네일·업로더·참조 열도 정렬 가능하게 그려(DataGrid 기본 정렬 가능) 그 머리를 누르면
 * `sort_by=thumbnail` 등으로 422 가 났다.
 *
 * 고침(서버 무변경): 썸네일·업로더·참조 열에 `:sortable="false"`. 받는 세 열은 종전대로 `sort_by={열}`·`sort_order={방향}`.
 *
 * 판정: 정렬 가능한 열이 곧 서버 허용 열이고, 그 머리의 두 방향이 허용값의 `sort_by` 와 방향 `sort_order` 로 이동한다. 정렬 불가 열은 머리에
 * 정렬 단추가 없다(DataGrid 는 정렬 가능한 열에만 머리 단추를 그린다 — 7.1.1 아래 대역도 같은 규칙으로 그린다).
 *
 * @scenario-manifest tests/scenarios/uploads-sort-query-matches-server.yaml
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

import uploadsLayout from '../../../layouts/admin/ckeditor5_uploads.json';

type Node = Record<string, any>;

/** 서버 허용 정렬 열 — `src/Http/Requests/IndexUploadsRequest.php:28` SORTABLE */
const SERVER_SORTABLE = ['created_at', 'file_size', 'original_name'];

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

const grid = findGrid(uploadsLayout)!;
const sortableFields = ((grid?.props?.columns ?? []) as any[]).filter((c) => c.sortable !== false).map((c) => String(c.field)).sort();

describe('CKEditor5 업로드 목록 표 머리 정렬 → 서버 허용 sort_by (7.1.1)', () => {
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
     * 표의 정렬 가능한 열 집합이 서버 SORTABLE 세 열과 같다 — 아래 조합 표의 전제(방향과 무관한 구조 단언).
     *
     * @effects uploads_sortable_columns_match_server
     */
    it('정렬 가능한 열은 서버가 정렬할 수 있는 세 열이다(썸네일·업로더·참조 머리엔 정렬 단추 없음)', () => {
        expect(grid, '표를 찾지 못했다').toBeTruthy();
        expect(sortableFields).toEqual([...SERVER_SORTABLE].sort());
    });

    // 이 it.each 가 실제로 도는 (열, 방향) 6행 — docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 적는다.
    // @scenario column=created_at, direction=asc
    // @scenario column=created_at, direction=desc
    // @scenario column=file_size, direction=asc
    // @scenario column=file_size, direction=desc
    // @scenario column=original_name, direction=asc
    // @scenario column=original_name, direction=desc
    // @effects uploads_sort_query_matches_server
    it.each(sortableFields.flatMap((f) => ['asc', 'desc'].map((d) => [f, d])))('%s %s 머리 → sort_by 가 서버 허용값, sort_order 가 방향', async (field, dir) => {
        const utils = createLayoutTest({ version: '1.0.0', layout_name: 'test/uploads-sort-query', components: [grid] } as any, { componentRegistry: registry as any, locale: 'ko' });
        await utils.render();
        await userEvent.setup().click(screen.getByText(`${field}:${dir}`));
        await waitFor(() => expect(utils.mockNavigate).toHaveBeenCalled());
        const target = String(utils.mockNavigate.mock.calls.at(-1)?.[0] ?? '');
        const q = new URL(target, 'http://x').searchParams;
        expect(SERVER_SORTABLE, target).toContain(q.get('sort_by'));
        expect(q.get('sort_by'), target).toBe(field);
        expect(q.get('sort_order'), target).toBe(dir);
        utils.cleanup();
    });
});

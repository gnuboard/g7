// e2e:allow 엔진 렌더(표 정렬 머리 → 이동 쿼리 값) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(마일리지 내역 「일시」·「금액」 머리 정렬 뒤 목록 요청 200·순서 바뀜)이 덮는다.
/**
 * @file adminMileageSortQuery.test.tsx
 * @description 마일리지 내역 표 머리 정렬이 서버 허용값(`{필드}_{방향}`)으로 나간다 (7.1.1 13e)
 *
 * 「일시」·「금액」 머리를 누르면 DataGrid 가 `onSortChange(field, direction)` 을 내는데, 레이아웃이 방향(`$args[1]`)을 버리고
 * `sort=created_at`·`sort=amount` 로 주소를 바꿨다. 서버(`MileageTransactionListRequest`)는 `created_at_desc`·`created_at_asc`·
 * `amount_desc`·`amount_asc` 4값만 받아 422 를 돌려줘, 화면은 화살표·주소만 바뀌고 목록은 그대로였다(HEAD 부터).
 *
 * 판정: 정렬 가능한 열(DataGrid 기본 정렬 가능 — `:sortable="false"` 만 제외) × 두 방향 모두가 허용값 안의 `sort` 로 이동한다.
 *
 * 화살표·선택칸(7.1.1, 2026-10-19): 표가 지금 정렬을 받지 않아(`sortField`·`sortDirection` 미전달) 새로 그리면 머리 화살표가 사라지고
 * 선택칸으로 고른 정렬도 머리에 나타나지 않았다. 또 「일시」 머리 오름차순(`created_at_asc`)은 서버가 받는데 선택칸 항목에 없어
 * 빈칸으로 보였다. 판정: `query.sort`(`{필드}_{방향}`, 없으면 created_at_desc)를 풀어 표에 넘기고, 선택칸 항목 = 서버 허용 4값.
 *
 * 축 요약(마커 아님 — 평문): column, direction. 매핑은 테스트마다 단다.
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

import table from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_transactions_table.json';
import page from '../../../layouts/admin/admin_ecommerce_mileage_transaction_index.json';
import koLang from '../../../lang/partial/ko/admin/mileage_transactions.json';
import enLang from '../../../lang/partial/en/admin/mileage_transactions.json';
import jaLang from '../../../../../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/mileage_transactions.json';

/** 서버 허용값 — `src/Http/Requests/Admin/MileageTransactionListRequest.php` 의 `sort` 규칙 */
const SERVER_SORTS = ['created_at_desc', 'created_at_asc', 'amount_desc', 'amount_asc'];

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

const grid = findGrid(table)!;

/**
 * id 로 노드를 찾는다.
 *
 * @param x 탐색 대상
 * @param id 노드 id
 * @returns 노드
 */
function findById(x: any, id: string): Node | null {
    if (Array.isArray(x)) {
        for (const y of x) {
            const r = findById(y, id);
            if (r) return r;
        }

        return null;
    }
    if (!x || typeof x !== 'object') return null;
    if (x.id === id) return x;
    for (const v of Object.values(x)) {
        const r = findById(v, id);
        if (r) return r;
    }

    return null;
}
const sortableFields = (grid.props.columns as any[]).filter((c) => c.sortable !== false).map((c) => c.field as string);

describe('마일리지 내역 표 머리 정렬 → 서버 허용 sort 값', () => {
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        // DataGrid 대역 — 정렬 가능한 열마다 「오름·내림」 단추를 그려 실제 DataGrid 처럼 onSortChange(field, direction) 을 낸다
        registry.register('composite', 'DataGrid', ({ columns, onSortChange }: any) =>
            React.createElement('div', null, (columns ?? []).filter((c: any) => c.sortable !== false).flatMap((c: any) =>
                ['asc', 'desc'].map((d) => React.createElement('button', { key: `${c.field}-${d}`, type: 'button', onClick: () => onSortChange?.(c.field, d) }, `${c.field}:${d}`)))));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    /**
     * 표의 정렬 가능한 열 집합이 서버 허용 필드(created_at·amount)와 같다 — 아래 조합 표의 전제.
     *
     * @effects mileage_sort_query_matches_server
     */
    it('정렬 가능한 열은 서버가 정렬할 수 있는 열이다', () => {
        expect(sortableFields.sort()).toEqual(['amount', 'created_at']);
    });

    // 이 it.each 가 실제로 도는 (열, 방향) 4행 — docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 적는다.
    // @scenario column=created_at, direction=asc
    // @scenario column=created_at, direction=desc
    // @scenario column=amount, direction=asc
    // @scenario column=amount, direction=desc
    // @effects mileage_sort_query_matches_server
    it.each(sortableFields.flatMap((f) => ['asc', 'desc'].map((d) => [f, d])))('%s %s 머리 → 이동 쿼리 sort 가 허용값 `{필드}_{방향}`', async (field, dir) => {
        const utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/admin-mileage-sort', components: [grid] } as any,
            { componentRegistry: registry as any, locale: 'ko' },
        );
        await utils.render();
        await userEvent.setup().click(screen.getByText(`${field}:${dir}`));
        await waitFor(() => expect(utils.mockNavigate).toHaveBeenCalled());
        const target = String(utils.mockNavigate.mock.calls.at(-1)?.[0] ?? '');
        const sort = new URL(target, 'http://x').searchParams.get('sort');
        expect(sort, target).toBe(`${field}_${dir}`);
        expect(SERVER_SORTS).toContain(sort);
        utils.cleanup();
    });
});

describe('마일리지 내역 표 머리 화살표·정렬 선택칸이 지금 정렬(query.sort)을 따른다 (7.1.1)', () => {
    let registry: MockComponentRegistry;
    let received: { sortField?: unknown; sortDirection?: unknown } = {};

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        received = {};
        // DataGrid 대역 — 머리 화살표를 정하는 두 값(sortField·sortDirection)을 받아 둔다
        registry.register('composite', 'DataGrid', ({ sortField, sortDirection }: any) => {
            received = { sortField, sortDirection };

            return React.createElement('div', { 'data-testid': 'grid' });
        });
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // 주소의 sort 가 머리 화살표가 된다 — 종전에는 표가 정렬 값을 받지 않아 새로 그리면 화살표가 사라지고,
    // 선택칸으로 「금액 낮은순」을 골라도 머리는 아무 열도 가리키지 않았다. sort 가 없으면 서버 기본값(created_at_desc).
    // @scenario column=created_at, direction=asc
    // @scenario column=created_at, direction=desc
    // @scenario column=amount, direction=asc
    // @scenario column=amount, direction=desc
    // @effects mileage_sort_header_reflects_query
    it.each([
        [undefined, 'created_at', 'desc'],
        ...SERVER_SORTS.map((v) => [v, v.split('_').slice(0, -1).join('_'), v.split('_').pop()]),
    ])('query.sort=%s → 머리 화살표 %s %s', async (sort, field, dir) => {
        const utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/admin-mileage-sort-arrow', components: [grid] } as any,
            { componentRegistry: registry as any, locale: 'ko', queryParams: sort ? { sort } : {} },
        );
        await utils.render();
        expect(screen.getByTestId('grid')).toBeTruthy();
        expect(received).toEqual({ sortField: field, sortDirection: dir });
        utils.cleanup();
    });

    // 머리로 고를 수 있는 정렬(열 × 방향)은 위쪽 정렬 선택칸에도 모두 있어야 한다 — 「일시」 머리로 오름차순을 고르면
    // 선택칸 값(created_at_asc)에 맞는 항목이 없어 빈칸으로 보였다. 선택칸은 서버 허용값만 갖는다.
    // 아래 단언은 (열, 방향) 4조합 모두가 선택칸 항목인지 본다.
    // @scenario column=created_at, direction=asc
    // @scenario column=created_at, direction=desc
    // @scenario column=amount, direction=asc
    // @scenario column=amount, direction=desc
    // @effects mileage_sort_header_reflects_query
    it('정렬 선택칸 항목 = 서버 허용 sort 4값, 각 항목 문구가 ko·en·ja 에 있다', () => {
        const select = findById(page, 'sort_select')!;
        const options = select.props.options as Array<{ value: string; label: string }>;
        expect(options.map((o) => o.value).sort()).toEqual([...SERVER_SORTS].sort());
        for (const sortValue of sortableFields.flatMap((f) => ['asc', 'desc'].map((d) => `${f}_${d}`))) {
            expect(options.map((o) => o.value)).toContain(sortValue);
        }
        for (const o of options) {
            expect(o.label).toBe(`$t:sirsoft-ecommerce.admin.mileage_transactions.sort.${o.value}`);
            for (const lang of [koLang, enLang, jaLang] as any[]) {
                expect(typeof lang.sort?.[o.value], o.value).toBe('string');
            }
        }
    });
});

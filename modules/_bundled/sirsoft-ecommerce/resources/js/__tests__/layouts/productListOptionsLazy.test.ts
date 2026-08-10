/**
 * @file productListOptionsLazy.test.ts
 * @description 관리자 상품목록 확장 행 옵션 지연 로딩 레이아웃 회귀 (#518 / 공개 #76)
 *
 * 목록 응답이 옵션을 더 이상 싣지 않으므로, 행을 펼치는 순간 옵션을 가져오는 경로가 레이아웃에
 * 선언돼 있어야 한다. 이 테스트는 실 레이아웃 JSON 을 읽어 그 선언을 고정한다.
 *
 * 특히 조용히 깨지는 두 가지를 검사한다.
 *   1. `expandContext` 값이 `{{...}}` **단일 바인딩** 형태여야 한다. 엔진의 판정 정규식은
 *      `}` 를 허용하지 않아, 맵 조회 같은 표현식은 미매칭 → 문자열 보간으로 떨어져 배열/객체가
 *      JSON 문자열로 굳는다 (예외 없이 값만 망가진다)
 *   2. "옵션 없음" 조건이 `Array.isArray` 로 로드 완료를 확인해야 한다. `!row.options` 로 두면
 *      아직 안 불러온 상태(=undefined)에서도 참이 되어 로딩 중 "옵션 없음" 이 깜빡인다
 *
 * @scenario row_state=expanding,option_profile=mixed
 * @effects list_response_omits_options, no_fetch_when_total_count_zero
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const PARTIALS_ROOT = path.resolve(
    __dirname,
    '../../../layouts/admin/partials/admin_ecommerce_product_list'
);

const DATAGRID_PATH = path.join(PARTIALS_ROOT, '_partial_product_datagrid.json');
const CONFIRM_MODAL_PATH = path.join(PARTIALS_ROOT, '_modal_bulk_confirm.json');

/** 엔진이 "식으로 평가"할 수 있는 단일 바인딩 형태 (G7CoreGlobals 의 판정과 동일) */
const SINGLE_BINDING = /^\{\{([^}]+)\}\}$/;

/**
 * 레이아웃 JSON 을 읽습니다.
 *
 * @param file 파일 경로
 * @return 파싱된 JSON
 */
function readLayout(file: string): any {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/**
 * 트리를 순회하며 조건에 맞는 노드를 모읍니다.
 *
 * @param node 시작 노드
 * @param predicate 수집 조건
 * @return 일치 노드 목록
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];

    const walk = (current: any): void => {
        if (Array.isArray(current)) {
            current.forEach(walk);

            return;
        }

        if (!current || typeof current !== 'object') {
            return;
        }

        if (predicate(current)) {
            found.push(current);
        }

        Object.values(current).forEach(walk);
    };

    walk(node);

    return found;
}

/** DataGrid 노드를 찾습니다. */
function findDataGrid(layout: any): any {
    const grids = collectNodes(layout, (n) => n?.name === 'DataGrid' && !!n?.props?.expandChildren);

    expect(grids.length).toBe(1);

    return grids[0];
}

describe('상품목록 확장 행 옵션 지연 로딩 — 레이아웃 선언', () => {
    const datagrid = readLayout(DATAGRID_PATH);
    const grid = findDataGrid(datagrid);

    it('onExpandChange 가 옵션 로드 핸들러로 연결된다', () => {
        const actions = collectNodes(datagrid, (n) => n?.event === 'onExpandChange');

        expect(actions).toHaveLength(1);
        expect(actions[0].handler).toBe('sirsoft-ecommerce.loadExpandedOptions');
        expect(actions[0].params).toEqual({ expandedRows: '{{$args[0]}}' });
    });

    it('확장 상태 갱신과 옵션 로드가 한 액션으로 묶여 있다', () => {
        // setState 로 expandedRows 를 **갱신**하는 액션이 남아 있으면 순서에 따라 갓 펼친 행이
        // 누락된다. 목록 구성이 바뀔 때의 **초기화**(빈 배열 대입)는 별개 목적이라 제외한다.
        const staleSetState = collectNodes(
            datagrid,
            (n) =>
                n?.handler === 'setState'
                && n?.params?.expandedRows !== undefined
                && !(Array.isArray(n.params.expandedRows) && n.params.expandedRows.length === 0)
        );

        expect(staleSetState).toHaveLength(0);
    });

    it('expandContext 값이 모두 단일 바인딩 형태다', () => {
        const context = grid.props.expandContext;

        expect(context).toBeDefined();

        Object.entries(context).forEach(([key, value]) => {
            expect(typeof value).toBe('string');
            expect(
                SINGLE_BINDING.test(value as string),
                `expandContext.${key} 가 단일 바인딩이 아니다: ${value}`
            ).toBe(true);
        });
    });

    it('expandContext 에 로딩/에러 표시용 키가 노출된다', () => {
        const context = grid.props.expandContext;

        expect(context.optionsLoadingIds).toBe('{{_local.optionsLoadingIds ?? []}}');
        expect(context.optionsErrorIds).toBe('{{_local.optionsErrorIds ?? []}}');
    });

    it('"옵션 없음" 은 로드 완료(Array.isArray)를 확인한 뒤에만 표시된다', () => {
        const noOptions = collectNodes(
            datagrid,
            (n) => typeof n?.id === 'string' && n.id.startsWith('no_options_message_')
        );

        expect(noOptions).toHaveLength(1);
        expect(noOptions[0].if).toContain('Array.isArray(row.options)');
        expect(noOptions[0].if).toContain('row.options.length === 0');
    });

    it('로딩/에러 블록이 존재하고 조건이 단일 바인딩이다', () => {
        const loading = collectNodes(
            datagrid,
            (n) => typeof n?.id === 'string' && n.id.startsWith('options_loading_')
        );
        const errored = collectNodes(
            datagrid,
            (n) => typeof n?.id === 'string' && n.id.startsWith('options_error_')
        );

        expect(loading).toHaveLength(1);
        expect(errored).toHaveLength(1);

        [loading[0], errored[0]].forEach((node) => {
            expect(SINGLE_BINDING.test(node.if), `if 가 단일 바인딩이 아니다: ${node.if}`).toBe(true);
        });

        expect(loading[0].if).toContain('optionsLoadingIds');
        expect(errored[0].if).toContain('optionsErrorIds');
    });

    it('옵션 테이블은 로딩/에러 상태가 아닐 때만 렌더된다', () => {
        const table = collectNodes(
            datagrid,
            (n) => n?.props?.className === 'overflow-x-auto' && typeof n?.if === 'string'
        );

        expect(table.length).toBeGreaterThanOrEqual(1);

        const condition = table[0].if;

        expect(SINGLE_BINDING.test(condition)).toBe(true);
        expect(condition).toContain('optionsLoadingIds');
        expect(condition).toContain('optionsErrorIds');
    });

    it('재시도 버튼이 재로드 핸들러로 연결된다', () => {
        const retry = collectNodes(
            datagrid,
            (n) => n?.handler === 'sirsoft-ecommerce.retryExpandedOptions'
        );

        expect(retry).toHaveLength(1);
        expect(retry[0].params).toEqual({ productId: '{{row.id}}' });
    });
});

describe('일괄 변경 확인 모달 — 집계 표기', () => {
    const modal = readLayout(CONFIRM_MODAL_PATH);

    it('옵션 개수는 배열 길이가 아니라 summary.optionCount 를 쓴다', () => {
        const raw = JSON.stringify(modal);

        expect(raw).toContain('_global.bulkConfirmData.summary.optionCount');
        expect(raw).not.toContain('options_section|count={{_global.bulkConfirmData.options.length}}');
    });

    it('집계 엔트리와 개별 엔트리가 서로 배타적으로 표시된다', () => {
        const aggregate = collectNodes(
            modal,
            (n) => typeof n?.text === 'string' && n.text.includes('bulk_confirm.options_aggregate')
        );

        expect(aggregate).toHaveLength(1);
        expect(aggregate[0].if).toBe('{{option.aggregate === true}}');

        const individual = collectNodes(
            modal,
            (n) => n?.text === '{{option.productName}} > {{option.optionName}}'
        );

        expect(individual).toHaveLength(1);
        expect(individual[0].if).toBe('{{option.aggregate !== true}}');
    });
});

/**
 * 목록 구성이 바뀌는 이동에서 펼침 상태가 초기화되는지 고정한다.
 *
 * 옵션은 목록 응답에 없고 펼칠 때만 채워지므로, 정렬·페이지·검색·프리셋으로 행 구성이 바뀐 뒤에도
 * 이전 펼침 상태가 남아 있으면 그 행은 옵션이 없는 채로 펼쳐진다 — 로딩도 "옵션 없음" 도 아닌
 * 빈 영역이 남는다.
 *
 * @scenario row_state=expanded,option_profile=active_only
 * @effects expanded_state_reset_on_list_change
 */
describe('목록 구성 변경 시 펼침 상태 초기화', () => {
    const LIST_PATH = path.resolve(
        __dirname,
        '../../../layouts/admin/admin_ecommerce_product_list.json'
    );
    const FILTER_PATH = path.join(PARTIALS_ROOT, '_partial_filter_section.json');

    /** 초기화 대상 키 — 하나라도 빠지면 그 흔적이 다음 목록으로 새어 나간다 */
    const RESET_KEYS = ['expandedRows', 'optionsLoadingIds', 'optionsErrorIds', 'selectedOptionIds'];

    /**
     * setState 로 펼침 상태를 비우는 액션인지 판정합니다.
     *
     * @param node 검사 노드
     * @return 초기화 액션 여부
     */
    const isResetAction = (node: any): boolean =>
        node?.handler === 'setState'
        && node?.params?.target === 'local'
        && RESET_KEYS.every((key) => Array.isArray(node.params[key]) && node.params[key].length === 0);

    it('초기화 액션이 named_actions 에 단일 정의로 존재한다', () => {
        const layout = readLayout(LIST_PATH);
        const reset = layout.named_actions?.resetExpandedOptionState;

        expect(reset).toBeDefined();
        expect(isResetAction(reset)).toBe(true);
    });

    it('검색 실행이 초기화를 거친 뒤 이동한다', () => {
        const layout = readLayout(LIST_PATH);
        const search = layout.named_actions?.searchProducts;

        expect(search?.handler).toBe('sequence');
        expect(search.actions[0].actionRef).toBe('resetExpandedOptionState');
        expect(search.actions[1].actionRef).toBe('searchProductsNavigate');
    });

    it('정렬 변경이 초기화를 거친 뒤 이동한다', () => {
        const layout = readLayout(LIST_PATH);

        const sortSequences = collectNodes(
            layout,
            (n) =>
                n?.handler === 'sequence'
                && Array.isArray(n.actions)
                && n.actions.some(
                    (a: any) => typeof a?.params?.query?.sort_by === 'string'
                )
        );

        expect(sortSequences).toHaveLength(1);
        expect(isResetAction(sortSequences[0].actions[0])).toBe(true);
    });

    it('페이지 이동이 초기화를 거친 뒤 이동한다', () => {
        const grid = findDataGrid(readLayout(DATAGRID_PATH));

        const onPageChange = (grid.actions ?? []).find((a: any) => a.event === 'onPageChange');

        expect(onPageChange?.handler).toBe('sequence');
        expect(isResetAction(onPageChange.actions[0])).toBe(true);
        expect(onPageChange.actions[1].handler).toBe('navigate');
    });

    it('프리셋 적용과 필터 초기화도 초기화를 거친다', () => {
        const filter = readLayout(FILTER_PATH);

        const navigates = collectNodes(
            filter,
            (n) => n?.handler === 'navigate' && n?.params?.path === '/admin/ecommerce/products'
        );

        expect(navigates.length).toBeGreaterThan(0);

        const refs = collectNodes(filter, (n) => n?.actionRef === 'resetExpandedOptionState');

        expect(refs).toHaveLength(navigates.length);
    });
});

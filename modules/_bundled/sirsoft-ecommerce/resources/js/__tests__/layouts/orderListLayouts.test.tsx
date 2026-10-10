/**
 * 주문 목록 레이아웃 구조 검증 테스트
 *
 * @description
 * - 페이지네이션 경로가 올바른 API 응답 구조를 참조하는지 확인
 * - 필터 파라미터 키가 백엔드 FormRequest/Repository와 일치하는지 확인
 * - DataGrid 컬럼 구성 검증 (device, first_order 등)
 * - 동적 필터 (국가, 결제수단) iteration 검증
 * - 배송국가 국기 경로 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 레이아웃 JSON 임포트
import orderList from '../../../layouts/admin/admin_ecommerce_order_list.json';
import filterSection from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json';
import datagrid from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json';
import { asArray, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

/** 재귀적으로 JSON 트리에서 특정 조건의 노드를 찾는 유틸리티 */
function findNodes(node: any, predicate: (n: any) => boolean, results: any[] = []): any[] {
    if (!node) return results;
    if (predicate(node)) results.push(node);
    if (node.children) {
        for (const child of node.children) {
            findNodes(child, predicate, results);
        }
    }
    if (node.cellChildren) {
        for (const child of node.cellChildren) {
            findNodes(child, predicate, results);
        }
    }
    return results;
}

/** JSON 전체에서 특정 문자열을 포함하는 속성을 찾는 유틸리티 */
function findInObject(obj: any, pattern: string, results: string[] = [], path = ''): string[] {
    if (!obj) return results;
    if (typeof obj === 'string' && obj.includes(pattern)) {
        results.push(path);
    }
    if (typeof obj === 'object') {
        for (const key of Object.keys(obj)) {
            findInObject(obj[key], pattern, results, `${path}.${key}`);
        }
    }
    return results;
}

// ========================================
// 페이지네이션 경로 검증
// ========================================

describe('주문 목록 검색 Input 디바운스/Enter 검증', () => {
    // 검색 키워드 Input: change 액션이 filter.searchKeyword 를 쓰는 Input
    const getSearchInput = () =>
        findNodes(
            filterSection,
            (n: any) =>
                n.name === 'Input' &&
                asArray(n?.actions).length > 0 &&
                asArray(n?.actions).some(
                    (a: any) =>
                        a.type === 'change' && a.params?.['filter.searchKeyword'],
                ),
        )[0];

    /**
     * @scenario surface=order_list
     * @effects order_list_search_input_debounce_and_enter
     */
    // @scenario area=search_input
    // @effects search_input_submits_on_enter_with_debounce
    it('검색 키워드 Input 의 change 액션에 debounce 가 설정되어야 함', () => {
        const searchInput = getSearchInput();
        expect(searchInput).toBeDefined();

        const changeAction = asArray(searchInput?.actions).find(
            (a: any) =>
                a.type === 'change' &&
                a.handler === 'setState' &&
                a.params?.['filter.searchKeyword'],
        );
        expect(changeAction).toBeDefined();
        // 타이핑마다 무거운 리렌더가 발생하지 않도록 디바운스 필수
        expect(changeAction.debounce).toBeGreaterThan(0);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_search_input_debounce_and_enter
     */
    // @scenario area=search_input
    // @effects search_input_submits_on_enter_with_debounce
    it('검색 키워드 Input의 Enter 는 sequence[setState 선행 → searchOrders] 여야 함', () => {
        const searchInput = getSearchInput();
        expect(searchInput).toBeDefined();

        const enterAction = asArray(searchInput?.actions).find(
            (a: any) => a.type === 'keypress' && a.key === 'Enter'
        );
        expect(enterAction).toBeDefined();
        // race 회피: debounce 대기 중 Enter 시 최신 입력값을 즉시 반영 후 navigate
        expect(enterAction.handler).toBe('sequence');
        expect(asArray(enterAction?.actions).length > 0).toBe(true);

        const firstSetState = asArray(enterAction?.actions)[0];
        expect(firstSetState.handler).toBe('setState');
        expect(firstSetState.params?.['filter.searchKeyword']).toContain('$event.target.value');
        expect(firstSetState.debounce).toBeUndefined();

        const navStep = asArray(enterAction?.actions).find((a: any) => a.actionRef === 'searchOrders');
        expect(navStep).toBeDefined();
    });
});

describe('주문 목록 페이지네이션 경로 검증', () => {
    /**
     * @scenario surface=order_list
     * @effects order_list_pagination_reads_response_meta
     */
    // @scenario area=pagination
    // @effects pagination_reads_the_api_pagination_path
    it('DataGrid serverCurrentPage가 orders?.data?.pagination?.current_page 경로를 사용해야 함', () => {
        const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
        expect(datagridNode).toBeDefined();
        expect(datagridNode.props.serverCurrentPage).toContain('pagination?.current_page');
        expect(datagridNode.props.serverCurrentPage).not.toContain('orders?.data?.current_page');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_pagination_reads_response_meta
     */
    // @scenario area=pagination
    // @effects pagination_reads_the_api_pagination_path
    it('DataGrid serverTotalPages가 orders?.data?.pagination?.last_page 경로를 사용해야 함', () => {
        const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
        expect(datagridNode.props.serverTotalPages).toContain('pagination?.last_page');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_pagination_reads_response_meta
     */
    // @scenario area=pagination
    // @effects pagination_reads_the_api_pagination_path
    it('총건수 표시가 orders?.data?.pagination?.total 경로를 사용해야 함', () => {
        // total_count 텍스트 노드 찾기
        const totalPaths = findInObject(datagrid, 'pagination?.total');
        expect(totalPaths.length).toBeGreaterThanOrEqual(1);
    });
});

// ========================================
// 필터 파라미터 키 검증
// ========================================

describe('주문 목록 필터 파라미터 키 검증', () => {
    const dataSources = (orderList as any).data_sources;
    const ordersDS = dataSources.find((ds: any) => ds.id === 'orders');

    /**
     * @scenario surface=order_list
     * @effects order_list_filter_param_keys_match_request
     */
    // @scenario area=filter_params
    // @effects filter_query_keys_match_the_backend_request
    it('data_source orders가 존재해야 함', () => {
        expect(ordersDS).toBeDefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_filter_param_keys_match_request
     */
    // @scenario area=filter_params
    // @effects filter_query_keys_match_the_backend_request
    it('shipping_type 파라미터 키를 사용해야 함 (delivery_method 아닌)', () => {
        expect(ordersDS.params.shipping_type).toBeDefined();
        expect(ordersDS.params.delivery_method).toBeUndefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_filter_param_keys_match_request
     */
    // @scenario area=filter_params
    // @effects filter_query_keys_match_the_backend_request
    it('min_amount 파라미터 키를 사용해야 함 (min_price 아닌)', () => {
        expect(ordersDS.params.min_amount).toBeDefined();
        expect(ordersDS.params.min_price).toBeUndefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_filter_param_keys_match_request
     */
    // @scenario area=filter_params
    // @effects filter_query_keys_match_the_backend_request
    it('max_amount 파라미터 키를 사용해야 함 (max_price 아닌)', () => {
        expect(ordersDS.params.max_amount).toBeDefined();
        expect(ordersDS.params.max_price).toBeUndefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_filter_param_keys_match_request
     */
    // @scenario area=filter_params
    // @effects filter_query_keys_match_the_backend_request
    it('country_codes 파라미터 키를 사용해야 함 (country 아닌)', () => {
        expect(ordersDS.params.country_codes).toBeDefined();
        expect(ordersDS.params.country).toBeUndefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_filter_param_keys_match_request
     */
    // @scenario area=filter_params
    // @effects filter_query_keys_match_the_backend_request
    it('order_device 파라미터 키를 사용해야 함 (device 아닌)', () => {
        expect(ordersDS.params.order_device).toBeDefined();
        expect(ordersDS.params.device).toBeUndefined();
    });
});

// ========================================
// DataGrid 컬럼 검증
// ========================================

describe('주문 목록 DataGrid 컬럼 검증', () => {
    const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
    const columns = datagridNode?.props?.columns || [];

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_columns_bind_server_fields
     */
    // @scenario area=grid_columns
    // @effects datagrid_declares_the_expected_columns
    it('device 컬럼이 존재해야 함', () => {
        const deviceColumn = columns.find((c: any) => c.field === 'device');
        expect(deviceColumn).toBeDefined();
        expect(deviceColumn.header).toContain('device');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_columns_bind_server_fields
     */
    // @scenario area=grid_columns
    // @effects datagrid_declares_the_expected_columns
    it('device 컬럼이 order_device_label 바인딩을 사용해야 함', () => {
        const deviceColumn = columns.find((c: any) => c.field === 'device');
        const textNodes = findNodes({ children: deviceColumn.cellChildren }, (n: any) => n.text);
        const hasDeviceLabel = textNodes.some((n: any) => n.text.includes('order_device_label'));
        expect(hasDeviceLabel).toBe(true);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_columns_bind_server_fields
     */
    // @scenario area=grid_columns
    // @effects datagrid_declares_the_expected_columns
    it('orderer 컬럼에 첫구매 표시가 있어야 함', () => {
        const ordererColumn = columns.find((c: any) => c.field === 'orderer');
        // ActionMenu 내부에 중첩된 is_first_order 조건 확인
        const ordererJson = JSON.stringify(ordererColumn);
        expect(ordererJson).toContain('is_first_order');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_columns_bind_server_fields
     */
    // @scenario area=grid_columns
    // @effects datagrid_declares_the_expected_columns
    it('orderer 컬럼에 ActionMenu가 적용되어야 함', () => {
        const ordererColumn = columns.find((c: any) => c.field === 'orderer');
        const actionMenuNodes = findNodes({ children: ordererColumn.cellChildren }, (n: any) =>
            n.name === 'ActionMenu'
        );
        expect(actionMenuNodes.length).toBe(1);
        // view_member, search_by_orderer_{member,guest} 메뉴 항목 확인
        // (search_by_orderer 는 회원/비회원 두 분기로 분리됨)
        const items = actionMenuNodes[0].props?.items || [];
        expect(items.find((i: any) => i.id === 'view_member')).toBeDefined();
        const ordererSearchItems = items.filter((i: any) =>
            typeof i.id === 'string' && i.id.startsWith('search_by_orderer')
        );
        expect(ordererSearchItems.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_columns_bind_server_fields
     */
    // @scenario area=grid_columns
    // @effects datagrid_declares_the_expected_columns
    it('ordered_at 컬럼이 formatted 날짜를 사용해야 함', () => {
        const orderedAtColumn = columns.find((c: any) => c.field === 'ordered_at');
        const orderedAtJson = JSON.stringify(orderedAtColumn);
        expect(orderedAtJson).toContain('ordered_at_formatted');
        expect(orderedAtJson).not.toContain('{{row.ordered_at}}');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_columns_bind_server_fields
     */
    // @scenario area=grid_columns
    // @effects datagrid_declares_the_expected_columns
    it('country 컬럼 국기가 address?.recipient_country_code 경로를 사용해야 함', () => {
        const countryColumn = columns.find((c: any) => c.field === 'country');
        const flagNodes = findNodes({ children: countryColumn.cellChildren }, (n: any) =>
            n.props?.className?.includes('fi fi-')
        );
        expect(flagNodes.length).toBeGreaterThanOrEqual(1);
        // 올바른 경로 사용
        expect(flagNodes[0].props.className).toContain('address?.recipient_country_code');
        // 잘못된 경로 사용하면 안됨
        expect(flagNodes[0].props.className).not.toContain('row.country_code');
    });
});

// ========================================
// DataGrid 설정 검증
// ========================================

describe('주문 목록 DataGrid 설정 검증', () => {
    const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_props_are_defined
     */
    // @scenario area=grid_config
    // @effects datagrid_props_match_the_component_contract
    it('showColumnSelector가 false여야 함 (외부 ColumnSelector 사용)', () => {
        expect(datagridNode.props.showColumnSelector).toBe(false);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_props_are_defined
     */
    // @scenario area=grid_config
    // @effects datagrid_props_match_the_component_contract
    it('responsiveBreakpoint가 768이어야 함 (모바일 대응)', () => {
        expect(datagridNode.props.responsiveBreakpoint).toBe(768);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_props_are_defined
     */
    // @scenario area=grid_config
    // @effects datagrid_props_match_the_component_contract
    it('DataGrid 에 정의 없는 columnSelectorId·loading 을 넘기지 않는다 (열 연결은 외부 ColumnSelector 의 id 가 맡고, 컬럼 선택은 visibleColumns 상태로 잇는다)', () => {
        expect(datagridNode.props).not.toHaveProperty('columnSelectorId');
        expect(datagridNode.props).not.toHaveProperty('loading');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_props_are_defined
     */
    // @scenario area=grid_config
    // @effects datagrid_props_match_the_component_contract
    it('selectedIds prop을 사용해야 함 (selectedItems 아닌)', () => {
        expect(datagridNode.props.selectedIds).toBeDefined();
        expect(datagridNode.props.selectedItems).toBeUndefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_props_are_defined
     */
    // @scenario area=grid_config
    // @effects datagrid_props_match_the_component_contract
    it('외부 ColumnSelector 컴포넌트가 필터 섹션에 존재해야 함', () => {
        const columnSelectors = findNodes(filterSection, (n: any) =>
            n.name === 'ColumnSelector'
        );
        expect(columnSelectors.length).toBe(1);
        expect(columnSelectors[0].props.id).toBe('admin_order_list_columns');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_datagrid_props_are_defined
     */
    // @scenario area=grid_config
    // @effects datagrid_props_match_the_component_contract
    it('ColumnSelector에 device 컬럼이 포함되어야 함', () => {
        const columnSelector = findNodes(filterSection, (n: any) =>
            n.name === 'ColumnSelector'
        )[0];
        const deviceCol = columnSelector.props.columns.find((c: any) => c.field === 'device');
        expect(deviceCol).toBeDefined();
    });
});

// ========================================
// visibleColumns 검증
// ========================================

describe('주문 목록 visibleColumns 검증', () => {
    const state = initStateOf(orderList as any);

    /**
     * @scenario surface=order_list
     * @effects order_list_visible_columns_share_one_default
     */
    // @scenario area=visible_columns
    // @effects visible_columns_follow_the_column_selector
    it('visibleColumns에 device가 포함되어야 함', () => {
        expect(state.visibleColumns).toContain('device');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_visible_columns_share_one_default
     */
    // @scenario area=visible_columns
    // @effects visible_columns_follow_the_column_selector
    it('visibleColumns에 no가 포함되지 않아야 함 (기본 숨김)', () => {
        expect(state.visibleColumns).not.toContain('no');
    });

    /**
     * 기본 컬럼 묶음의 출처가 셋이다 — 레이아웃 `state`, 표시 컬럼 복원 핸들러
     * (`sirsoft-ecommerce.loadVisibleColumns`, #639 ⑩ 회원 uuid 키)의 `defaultColumns`, ColumnSelector 의
     * `defaultColumns`. 저장값이 없는 브라우저에서는 복원 핸들러가 state 의 씨앗을 덮어쓰므로, 그 목록이 어긋나면 신규 운영자
     * 화면에서만 컬럼이 빠진다(오류·로그 없음). DataGrid 는 `required` 를 보지 않고
     * `visibleColumns.includes(col.field)` 로만 거르므로 필드명이 하나만 틀려도 그 컬럼이
     * 사라진다 — 실제로 `order_date`(존재하지 않는 field, 실제 키는 `ordered_at`)가
     * 들어 있어 주문일·디바이스 두 컬럼이 신규 운영자에게 보이지 않았다.
     * @effects order_list_visible_columns_share_one_default
     */
    it('기본 컬럼 묶음 3출처(state / localStorage 기본값 / ColumnSelector)가 같아야 함', () => {
        const restore = ((orderList as any).init_actions ?? [])
            .find((a: any) => a.handler === 'sirsoft-ecommerce.loadVisibleColumns' && a.resultTo?.key === 'visibleColumns');
        expect(restore).toBeDefined();
        // 공용 키를 직접 읽는 옛 경로가 되살아나면 다른 관리자의 설정이 실린다(#639 ⑩).
        expect(findNodes(orderList as any, (n: any) => n.handler === 'loadFromLocalStorage' && n.params?.key === 'g7_order_visible_columns')).toEqual([]);

        const selector = findNodes(filterSection, (n: any) => n.props?.defaultColumns !== undefined)[0];
        expect(selector).toBeDefined();

        expect([...restore.params.defaultColumns].sort()).toEqual([...selector.props.defaultColumns].sort());
        expect([...state.visibleColumns].sort()).toEqual([...selector.props.defaultColumns].sort());
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_visible_columns_share_one_default
     */
    it('기본 컬럼 묶음의 모든 키가 실제 DataGrid 컬럼 field 여야 함', () => {
        const grid = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
        const fields = (grid?.props?.columns ?? []).map((c: any) => c.field);
        expect(fields.length).toBeGreaterThan(0);
        for (const key of state.visibleColumns) {
            expect(fields).toContain(key);
        }
    });

    /**
     * 계약 변경 (확정, 6차 검증) — 종전 단언은 "클레임 컬럼은 기본 묶음 어디에도 없다
     * (opt-in)" 였다. 그 opt-in 이 만든 것이 이번 라운드의 차단 결함이다: 컬럼 표시는
     * 브라우저 저장값이, 값 수신은 주소(`with_claim_summary`)가 정해 서로 어긋났고,
     * 컬럼을 한 번 켠 운영자의 다음 진입에서 컬럼은 보이는데 전 행이 "-" 가 됐다.
     * 서버가 요약을 항상 싣도록 바꿔 근거를 하나로 되돌렸으므로, 클레임 컬럼은 이제
     * 다른 10개와 같은 평범한 기본 컬럼이다.
     * @effects order_list_visible_columns_share_one_default
     */
    it('클레임 컬럼은 기본 묶음 3출처에 모두 있어야 함', () => {
        const restore = ((orderList as any).init_actions ?? [])
            .find((a: any) => a.handler === 'sirsoft-ecommerce.loadVisibleColumns' && a.resultTo?.key === 'visibleColumns');
        const selector = findNodes(filterSection, (n: any) => n.props?.defaultColumns !== undefined)[0];

        expect(state.visibleColumns).toContain('claim');
        expect(restore.params.defaultColumns).toContain('claim');
        expect(selector.props.defaultColumns).toContain('claim');
    });

    /**
     * 클레임 요약 요청을 주소로 나르던 축이 사라졌는지 고정한다. 남아 있으면 표시와
     * 값 수신이 다시 두 근거를 갖게 되어 같은 결함이 되살아난다.
     * @effects order_list_visible_columns_share_one_default
     */
    it('클레임 요약 opt-in 축(with_claim_summary)이 어디에도 남아 있지 않아야 함', () => {
        const serialized = JSON.stringify([orderList, filterSection]);

        expect(serialized.includes('"with_claim_summary"')).toBe(false);
    });
});

// ========================================
// 동적 필터 검증
// ========================================

describe('주문 목록 동적 필터 검증', () => {
    /**
     * @scenario surface=order_list
     * @effects order_list_dynamic_filters_follow_settings
     */
    // @scenario area=dynamic_filters
    // @effects dynamic_filters_iterate_server_options
    it('배송국가 필터가 iteration으로 동적 렌더링되어야 함', () => {
        const countryRow = findNodes(filterSection, (n: any) => n.id === 'country_filter_row')[0];
        expect(countryRow).toBeDefined();

        // iteration을 사용하는 Label 컴포넌트 찾기
        const iterationLabels = findNodes(countryRow, (n: any) =>
            n.name === 'Label' && n.iteration?.source?.includes('availableCountries')
        );
        expect(iterationLabels.length).toBe(1);
        expect(iterationLabels[0].iteration.item_var).toBe('country');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_dynamic_filters_follow_settings
     */
    // @scenario area=dynamic_filters
    // @effects dynamic_filters_iterate_server_options
    it('결제수단 필터가 iteration으로 동적 렌더링되어야 함', () => {
        const paymentRow = findNodes(filterSection, (n: any) => n.id === 'paymentMethod_filter_row')[0];
        expect(paymentRow).toBeDefined();

        const iterationLabels = findNodes(paymentRow, (n: any) =>
            n.name === 'Label' && n.iteration?.source?.includes('availablePaymentMethods')
        );
        expect(iterationLabels.length).toBe(1);
        expect(iterationLabels[0].iteration.item_var).toBe('method');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_dynamic_filters_follow_settings
     */
    // @scenario area=dynamic_filters
    // @effects dynamic_filters_iterate_server_options
    it('ecommerce_settings 데이터소스가 존재해야 함', () => {
        const dataSources = (orderList as any).data_sources;
        const settingsDS = dataSources.find((ds: any) => ds.id === 'ecommerce_settings');
        expect(settingsDS).toBeDefined();
        expect(settingsDS.endpoint).toContain('settings');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_dynamic_filters_follow_settings
     */
    // @scenario area=dynamic_filters
    // @effects dynamic_filters_iterate_server_options
    it('computed에 availableCountries가 정의되어야 함', () => {
        const computed = (orderList as any).computed;
        expect(computed.availableCountries).toBeDefined();
        expect(computed.availableCountries).toContain('is_active');
        expect(computed.availableCountries).toContain('available_countries');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_dynamic_filters_follow_settings
     */
    // @scenario area=dynamic_filters
    // @effects dynamic_filters_iterate_server_options
    it('computed에 availablePaymentMethods가 정의되어야 함', () => {
        const computed = (orderList as any).computed;
        expect(computed.availablePaymentMethods).toBeDefined();
        expect(computed.availablePaymentMethods).toContain('is_active');
        expect(computed.availablePaymentMethods).toContain('payment_methods');
    });
});

// ========================================
// 클레임 기준 필터 배선 검증 (D22)
// ========================================

/**
 * 이 블록은 원래 "클레임 필터 제거 검증" 이었다.
 *
 * 2026-03 당시(#140) 클레임 기능 자체가 없어서 필터를 **임시로** 뺐고(그 지시 문언도
 * "추후 재사용 가능하도록" 이었다) 그 사실을 테스트로 고정한 것이었다. 지금은 기능이
 * 있고 서버(`OrderListRequest`)와 API 문서가 이미 클레임 필터 파라미터를 정본 계약으로
 * 보유하므로, 부재를 단언하면 화면 테스트만 백엔드 계약과 반대 방향을 가리킨다.
 *
 * 축별 상세 계약(칩 어휘 == Enum 집합, 왕복 4지점, opt-in 요약)은
 * `adminOrderListClaimFilter.test.ts` 가 담당한다. 여기서는 이 파일이 보던
 * 세 지점(필터 섹션 / 데이터소스 params / state)에 배선이 **살아 있는지**만 지킨다.
 */
describe('주문 목록 클레임 기준 필터 배선 (D22)', () => {
    /**
     * @scenario surface=order_list
     * @effects order_list_claim_filter_wired
     */
    // @scenario area=claim_filter_wired
    // @effects claim_filters_are_wired
    it('필터 섹션에 클레임 노드가 있어야 함', () => {
        const claimNodes = findNodes(filterSection, (n: any) =>
            n.id?.includes('claim') || n.id?.includes('Claim')
        );
        // 하한 0 은 노드가 하나만 남아도 통과한다. 이 블록의 실체는 칩 40개(유무 3 + 교환 14
        // + 반품 11 + 환불 7 + 취소 2 + 귀책 3)와 신청일 2칸이므로, 그 규모가 무너지면
        // 여기서 드러나야 한다 — 축별 어휘 대조는 adminOrderListClaimFilter.test.ts 소유다.
        //
        // 하한을 40 으로 두면 실측(47)과 7 만큼 벌어져 **칩 7개 또는 신청일 입력 2칸 전부가
        // 사라져도 통과**한다. 실측값으로 고정한다 — 노드가 늘어 red 가 나면 그때 실측을 다시
        // 세는 것이 맞다(무엇이 늘었는지 보게 된다).
        expect(claimNodes.length).toBe(47);

        // 필터 블록 컨테이너 자체도 살아 있어야 한다.
        expect(claimNodes.some((n: any) => n.id === 'claim_filter_row')).toBe(true);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_claim_filter_wired
     */
    // @scenario area=claim_filter_wired
    // @effects claim_filters_are_wired
    it('data_sources params 에 클레임 축이 실려야 함', () => {
        const ordersDS = (orderList as any).data_sources.find((ds: any) => ds.id === 'orders');
        const claimKeys = Object.keys(ordersDS.params).filter((k: string) => k.includes('claim'));
        expect(claimKeys).toContain('claim_presence');
        // 요약 포함 여부는 더 이상 파라미터가 아니다 — 서버가 항상 싣는다.
        expect(claimKeys).not.toContain('with_claim_summary');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_claim_filter_wired
     */
    // @scenario area=claim_filter_wired
    // @effects claim_filters_are_wired
    it('state 에 클레임 필터 필드가 있어야 함', () => {
        const state = initStateOf(orderList as any);
        const filterState = state.filter || {};
        const claimKeys = Object.keys(filterState).filter((k: string) =>
            k.toLowerCase().includes('claim')
        );
        // 하한만 두면 8축 중 7축이 사라져도 통과한다 — 축별 상세는
        // adminOrderListClaimFilter.test.ts 가 갖지만 여기서도 개수는 고정한다.
        expect(claimKeys).toHaveLength(8);
    });
});

// ========================================
// 배송정책/배송비 필터 검증
// ========================================

describe('주문 목록 배송정책/배송비 필터 검증', () => {
    const dataSources = (orderList as any).data_sources;
    const ordersDS = dataSources.find((ds: any) => ds.id === 'orders');
    const computed = (orderList as any).computed;
    const state = initStateOf(orderList as any);

    // --- 데이터소스 파라미터 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('orders 데이터소스에 shipping_policy_id 파라미터가 있어야 함', () => {
        expect(ordersDS.params.shipping_policy_id).toBeDefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('orders 데이터소스에 min_shipping_amount 파라미터가 있어야 함', () => {
        expect(ordersDS.params.min_shipping_amount).toBeDefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('orders 데이터소스에 max_shipping_amount 파라미터가 있어야 함', () => {
        expect(ordersDS.params.max_shipping_amount).toBeDefined();
    });

    // --- shipping_policies 데이터소스 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('shipping_policies 데이터소스가 존재해야 함', () => {
        const shippingPoliciesDS = dataSources.find((ds: any) => ds.id === 'shipping_policies');
        expect(shippingPoliciesDS).toBeDefined();
        expect(shippingPoliciesDS.endpoint).toContain('shipping-policies/active');
    });

    // --- state 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('state.filter에 shippingPolicy 필드가 있어야 함', () => {
        expect(state.filter).toHaveProperty('shippingPolicy');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('state.filter에 minShippingFee 필드가 있어야 함', () => {
        expect(state.filter).toHaveProperty('minShippingFee');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('state.filter에 maxShippingFee 필드가 있어야 함', () => {
        expect(state.filter).toHaveProperty('maxShippingFee');
    });

    // --- computed 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('computed에 filterShippingPolicy가 정의되어야 함', () => {
        expect(computed.filterShippingPolicy).toBeDefined();
        expect(computed.filterShippingPolicy).toContain('shippingPolicy');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('computed에 shippingPolicyOptions가 정의되어야 함', () => {
        expect(computed.shippingPolicyOptions).toBeDefined();
        expect(computed.shippingPolicyOptions).toContain('shipping_policies');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('computed에 filterMinShippingFee가 정의되어야 함', () => {
        expect(computed.filterMinShippingFee).toBeDefined();
        expect(computed.filterMinShippingFee).toContain('minShippingFee');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('computed에 filterMaxShippingFee가 정의되어야 함', () => {
        expect(computed.filterMaxShippingFee).toBeDefined();
        expect(computed.filterMaxShippingFee).toContain('maxShippingFee');
    });

    // --- 필터 UI 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('필터 섹션에 shippingPolicy_filter_row가 존재해야 함', () => {
        const shippingPolicyRow = findNodes(filterSection, (n: any) => n.id === 'shippingPolicy_filter_row');
        expect(shippingPolicyRow.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('필터 섹션에 shippingFee_filter_row가 존재해야 함', () => {
        const shippingFeeRow = findNodes(filterSection, (n: any) => n.id === 'shippingFee_filter_row');
        expect(shippingFeeRow.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('배송정책 필터에 Select 컴포넌트가 있어야 함', () => {
        const shippingPolicyRow = findNodes(filterSection, (n: any) => n.id === 'shippingPolicy_filter_row')[0];
        const selects = findNodes(shippingPolicyRow, (n: any) => n.name === 'Select');
        expect(selects.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_shipping_filters_wired
     */
    // @scenario area=shipping_filters
    // @effects shipping_filters_send_the_expected_keys
    it('배송비 필터에 number 타입 Input이 2개 (min/max) 있어야 함', () => {
        const shippingFeeRow = findNodes(filterSection, (n: any) => n.id === 'shippingFee_filter_row')[0];
        const numberInputs = findNodes(shippingFeeRow, (n: any) =>
            n.name === 'Input' && n.props?.type === 'number'
        );
        expect(numberInputs.length).toBe(2);
    });
});

// ========================================
// 주문상품 컬럼 "외 X건" 표시 검증
// ========================================

describe('주문 목록 주문상품 컬럼 "외 X건" 표시 검증', () => {
    const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
    const columns = datagridNode?.props?.columns || [];
    const productColumn = columns.find((c: any) => c.field === 'product');

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('product 컬럼이 존재해야 함', () => {
        expect(productColumn).toBeDefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('상품명이 first_option?.product_name 바인딩을 사용해야 함', () => {
        const cellJson = JSON.stringify(productColumn.cellChildren);
        expect(cellJson).toContain('first_option?.product_name');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('options_count > 1일 때 "외 X건" 텍스트가 표시되어야 함', () => {
        const moreNodes = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.text?.includes('product.more') && n.if?.includes('options_count')
        );
        expect(moreNodes.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('"외 X건" 텍스트가 $t:defer: + 파이프 문법으로 count 파라미터를 전달해야 함', () => {
        const moreNode = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.text?.includes('product.more')
        )[0];
        expect(moreNode).toBeDefined();
        // cellChildren 내 row 참조 → $t:defer: 필수
        expect(moreNode.text).toContain('$t:defer:');
        expect(moreNode.text).toContain('|count=');
        expect(moreNode.text).toContain('options_count');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('"외 X건"은 options_count > 1 조건에서만 표시되어야 함', () => {
        const moreNode = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.text?.includes('product.more')
        )[0];
        expect(moreNode.if).toContain('options_count > 1');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('옵션명이 first_option?.product_option_name 바인딩을 사용해야 함', () => {
        const optionNodes = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.text?.includes('first_option?.product_option_name')
        );
        expect(optionNodes.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('대표상품 썸네일이 표시되어야 함', () => {
        const imgNodes = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.name === 'Img' && n.props?.src?.includes('thumbnail_url')
        );
        expect(imgNodes.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_product_cell_shows_more_count
     */
    // @scenario area=item_count_cell
    // @effects order_item_cell_shows_extra_count
    it('상품명이 상품수정 페이지 링크(A 태그, target=_blank)여야 함', () => {
        const linkNodes = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.name === 'A' && n.props?.target === '_blank' && n.props?.href?.includes('products')
        );
        expect(linkNodes.length).toBe(1);
        expect(linkNodes[0].props.href).toContain('product_code');
        expect(linkNodes[0].props.href).toContain('/edit');
    });
});

// ========================================
// 주문금액 셀 하위 표시 검증
// ========================================

describe('주문 목록 주문금액 셀 하위 표시 검증', () => {
    const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
    const columns = datagridNode?.props?.columns || [];
    const orderAmountColumn = columns.find((c: any) => c.field === 'order_amount');

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('order_amount 컬럼이 존재해야 함', () => {
        expect(orderAmountColumn).toBeDefined();
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('주문금액 셀에 배송비 표시가 $t:defer: 패턴이어야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('$t:defer:');
        expect(cellJson).toContain('total_shipping_amount_formatted');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('주문금액 셀에 결제금액 표시가 $t:defer: 패턴이어야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('total_paid_amount_formatted');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('주문금액 셀에 미결제금액 표시가 $t:defer: 패턴이어야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('total_unpaid_amount_formatted');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('결제금액에 초록색 스타일이 적용되어야 함', () => {
        const paidNodes = findNodes({ children: orderAmountColumn.cellChildren }, (n: any) => {
            const json = JSON.stringify(n);
            return json?.includes('total_paid_amount_formatted') && json?.includes('green');
        });
        expect(paidNodes.length).toBeGreaterThanOrEqual(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('미결제금액에 적색 스타일이 적용되어야 함', () => {
        const unpaidNodes = findNodes({ children: orderAmountColumn.cellChildren }, (n: any) => {
            const json = JSON.stringify(n);
            return json?.includes('total_unpaid_amount_formatted') && json?.includes('red');
        });
        expect(unpaidNodes.length).toBeGreaterThanOrEqual(1);
    });

    // 마일리지 시스템 도입 전 완성된 주문목록 그리드에 마일리지 사용/적립 표시가 누락된 회귀 차단.
    // OrderListResource 가 total_points_used_amount / total_earned_points_amount(_formatted) 를 노출하므로 셀에서 바인딩 가능.
    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('주문금액 셀에 마일리지 사용 표시가 $t:defer: 패턴으로 존재해야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('mileage_used_short');
        expect(cellJson).toContain('total_points_used_amount_formatted');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('주문금액 셀에 적립 예정 표시가 $t:defer: 패턴으로 존재해야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('points_earned_short');
        expect(cellJson).toContain('total_earned_points_amount_formatted');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_amount_cell_sub_lines
     */
    // @scenario area=amount_cell
    // @effects order_amount_cell_shows_sub_lines
    it('마일리지 사용 표시는 total_points_used_amount > 0 조건으로 노출되어야 함', () => {
        const usedNodes = findNodes({ children: orderAmountColumn.cellChildren }, (n: any) =>
            typeof n.if === 'string' && n.if.includes('total_points_used_amount') && n.if.includes('> 0')
        );
        expect(usedNodes.length).toBeGreaterThanOrEqual(1);
    });
});

// ========================================
// 주문자 필터 검증
// ========================================

describe('주문 목록 주문자 필터 검증', () => {
    const dataSources = (orderList as any).data_sources;
    const ordersDS = dataSources.find((ds: any) => ds.id === 'orders');
    const state = initStateOf(orderList as any);
    const initActions = (orderList as any).init_actions;
    const namedActions = (orderList as any).named_actions;

    // --- 데이터소스 파라미터 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('orders 데이터소스에 orderer_uuid 파라미터가 있어야 함', () => {
        expect(ordersDS.params.orderer_uuid).toBeDefined();
        expect(ordersDS.params.orderer_uuid).toContain('query.orderer_uuid');
    });

    // --- state 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('state에 ordererSearchResults 필드가 있어야 함', () => {
        expect(state).toHaveProperty('ordererSearchResults');
        expect(state.ordererSearchResults).toEqual([]);
    });

    // --- init_actions 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('init_actions에 orderer_uuid 기반 사용자 검색 apiCall이 있어야 함', () => {
        const ordererInitAction = initActions.find((a: any) =>
            a.handler === 'apiCall' &&
            a.target?.includes('users/search') &&
            a.if?.includes('orderer_uuid')
        );
        expect(ordererInitAction).toBeDefined();
        expect(ordererInitAction.params.query.uuid).toContain('query.orderer_uuid');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('init_actions setState에 ordererUuid 필터 초기화가 있어야 함', () => {
        const setStateAction = initActions.find((a: any) =>
            a.handler === 'setState' && a.params?._local?.filter?.ordererUuid !== undefined
        );
        expect(setStateAction).toBeDefined();
    });

    // --- named_actions 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('searchOrders named_action에 orderer_uuid 쿼리 파라미터가 있어야 함', () => {
        const searchQuery = namedActions.searchOrders.params.query;
        expect(searchQuery.orderer_uuid).toBeDefined();
        expect(searchQuery.orderer_uuid).toContain('ordererUuid');
    });

    // --- 필터 UI 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('필터 섹션에 orderer_filter_row가 존재해야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row');
        expect(ordererRow.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('주문자 필터에 SearchableDropdown 컴포넌트가 있어야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row')[0];
        const dropdowns = findNodes(ordererRow, (n: any) => n.name === 'SearchableDropdown');
        expect(dropdowns.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('SearchableDropdown에 ordererSearchResults 옵션이 바인딩되어야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row')[0];
        const dropdown = findNodes(ordererRow, (n: any) => n.name === 'SearchableDropdown')[0];
        expect(dropdown.props.options).toContain('ordererSearchResults');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('SearchableDropdown의 onSearch 이벤트가 /api/admin/users/search를 호출해야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row')[0];
        const dropdown = findNodes(ordererRow, (n: any) => n.name === 'SearchableDropdown')[0];
        const searchAction = dropdown.actions?.find((a: any) => a.event === 'onSearch');
        expect(searchAction).toBeDefined();
        expect(searchAction.target).toContain('users/search');
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_orderer_filter_wired
     */
    // @scenario area=orderer_filter
    // @effects orderer_filter_sends_the_expected_keys
    it('주문자 필터 클리어 버튼이 존재해야 함', () => {
        const clearButton = findNodes(filterSection, (n: any) => n.id === 'f_162_orderer_clear_button');
        expect(clearButton.length).toBe(1);
    });
});

// ========================================
// 회원/비회원 구분 필터 (member_type) 검증
// ========================================

describe('주문 목록 회원 구분 필터 검증', () => {
    const dataSources = (orderList as any).data_sources;
    const ordersDS = dataSources.find((ds: any) => ds.id === 'orders');
    const initActions = (orderList as any).init_actions;
    const namedActions = (orderList as any).named_actions;

    // --- 데이터소스 파라미터 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_member_type_filter_wired
     */
    // @scenario area=member_type_filter
    // @effects member_type_filter_sends_the_expected_keys
    it('orders 데이터소스에 member_type 파라미터가 있어야 함', () => {
        expect(ordersDS.params.member_type).toBeDefined();
        expect(ordersDS.params.member_type).toContain('query.member_type');
    });

    // --- init_actions 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_member_type_filter_wired
     */
    // @scenario area=member_type_filter
    // @effects member_type_filter_sends_the_expected_keys
    it('init_actions setState에 memberType 필터 초기화가 있어야 함', () => {
        const setStateAction = initActions.find((a: any) =>
            a.handler === 'setState' && a.params?._local?.filter?.memberType !== undefined
        );
        expect(setStateAction).toBeDefined();
    });

    // --- named_actions 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_member_type_filter_wired
     */
    // @scenario area=member_type_filter
    // @effects member_type_filter_sends_the_expected_keys
    it('searchOrders named_action에 member_type 쿼리 파라미터가 있어야 함', () => {
        const searchQuery = namedActions.searchOrders.params.query;
        expect(searchQuery.member_type).toBeDefined();
        expect(searchQuery.member_type).toContain('memberType');
    });

    // --- 필터 UI 검증 ---

    /**
     * @scenario surface=order_list
     * @effects order_list_member_type_filter_wired
     */
    // @scenario area=member_type_filter
    // @effects member_type_filter_sends_the_expected_keys
    it('필터 섹션에 member_type_filter_row가 존재해야 함', () => {
        const row = findNodes(filterSection, (n: any) => n.id === 'member_type_filter_row');
        expect(row.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_member_type_filter_wired
     */
    // @scenario area=member_type_filter
    // @effects member_type_filter_sends_the_expected_keys
    it('회원 구분 필터에 Select 컴포넌트가 있어야 함', () => {
        const row = findNodes(filterSection, (n: any) => n.id === 'member_type_filter_row')[0];
        const selects = findNodes(row, (n: any) => n.name === 'Select');
        expect(selects.length).toBe(1);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_member_type_filter_wired
     */
    // @scenario area=member_type_filter
    // @effects member_type_filter_sends_the_expected_keys
    it('Select 옵션이 전체/회원/비회원 3개 값(빈값/member/guest)을 가져야 함', () => {
        const row = findNodes(filterSection, (n: any) => n.id === 'member_type_filter_row')[0];
        const select = findNodes(row, (n: any) => n.name === 'Select')[0];
        const values = select.props.options.map((o: any) => o.value);
        expect(values).toEqual(['', 'member', 'guest']);
    });

    /**
     * @scenario surface=order_list
     * @effects order_list_member_type_filter_wired
     */
    // @scenario area=member_type_filter
    // @effects member_type_filter_sends_the_expected_keys
    it('Select change 핸들러가 filter.memberType 를 setState 해야 함', () => {
        const row = findNodes(filterSection, (n: any) => n.id === 'member_type_filter_row')[0];
        const select = findNodes(row, (n: any) => n.name === 'Select')[0];
        const changeAction = select.actions?.find((a: any) => a.type === 'change');
        expect(changeAction).toBeDefined();
        expect(changeAction.params['filter.memberType']).toBeDefined();
    });
});

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

describe('주문 목록 페이지네이션 경로 검증', () => {
    it('DataGrid serverCurrentPage가 orders?.data?.pagination?.current_page 경로를 사용해야 함', () => {
        const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
        expect(datagridNode).toBeDefined();
        expect(datagridNode.props.serverCurrentPage).toContain('pagination?.current_page');
        expect(datagridNode.props.serverCurrentPage).not.toContain('orders?.data?.current_page');
    });

    it('DataGrid serverTotalPages가 orders?.data?.pagination?.last_page 경로를 사용해야 함', () => {
        const datagridNode = findNodes(datagrid, (n: any) => n.name === 'DataGrid')[0];
        expect(datagridNode.props.serverTotalPages).toContain('pagination?.last_page');
    });

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

    it('data_source orders가 존재해야 함', () => {
        expect(ordersDS).toBeDefined();
    });

    it('shipping_type 파라미터 키를 사용해야 함 (delivery_method 아닌)', () => {
        expect(ordersDS.params.shipping_type).toBeDefined();
        expect(ordersDS.params.delivery_method).toBeUndefined();
    });

    it('min_amount 파라미터 키를 사용해야 함 (min_price 아닌)', () => {
        expect(ordersDS.params.min_amount).toBeDefined();
        expect(ordersDS.params.min_price).toBeUndefined();
    });

    it('max_amount 파라미터 키를 사용해야 함 (max_price 아닌)', () => {
        expect(ordersDS.params.max_amount).toBeDefined();
        expect(ordersDS.params.max_price).toBeUndefined();
    });

    it('country_codes 파라미터 키를 사용해야 함 (country 아닌)', () => {
        expect(ordersDS.params.country_codes).toBeDefined();
        expect(ordersDS.params.country).toBeUndefined();
    });

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

    it('device 컬럼이 존재해야 함', () => {
        const deviceColumn = columns.find((c: any) => c.field === 'device');
        expect(deviceColumn).toBeDefined();
        expect(deviceColumn.header).toContain('device');
    });

    it('device 컬럼이 order_device_label 바인딩을 사용해야 함', () => {
        const deviceColumn = columns.find((c: any) => c.field === 'device');
        const textNodes = findNodes({ children: deviceColumn.cellChildren }, (n: any) => n.text);
        const hasDeviceLabel = textNodes.some((n: any) => n.text.includes('order_device_label'));
        expect(hasDeviceLabel).toBe(true);
    });

    it('orderer 컬럼에 첫구매 표시가 있어야 함', () => {
        const ordererColumn = columns.find((c: any) => c.field === 'orderer');
        // ActionMenu 내부에 중첩된 is_first_order 조건 확인
        const ordererJson = JSON.stringify(ordererColumn);
        expect(ordererJson).toContain('is_first_order');
    });

    it('orderer 컬럼에 ActionMenu가 적용되어야 함', () => {
        const ordererColumn = columns.find((c: any) => c.field === 'orderer');
        const actionMenuNodes = findNodes({ children: ordererColumn.cellChildren }, (n: any) =>
            n.name === 'ActionMenu'
        );
        expect(actionMenuNodes.length).toBe(1);
        // view_member, search_by_orderer 메뉴 항목 확인
        const items = actionMenuNodes[0].props?.items || [];
        expect(items.find((i: any) => i.id === 'view_member')).toBeDefined();
        expect(items.find((i: any) => i.id === 'search_by_orderer')).toBeDefined();
    });

    it('ordered_at 컬럼이 formatted 날짜를 사용해야 함', () => {
        const orderedAtColumn = columns.find((c: any) => c.field === 'ordered_at');
        const orderedAtJson = JSON.stringify(orderedAtColumn);
        expect(orderedAtJson).toContain('ordered_at_formatted');
        expect(orderedAtJson).not.toContain('{{row.ordered_at}}');
    });

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

    it('showColumnSelector가 false여야 함 (외부 ColumnSelector 사용)', () => {
        expect(datagridNode.props.showColumnSelector).toBe(false);
    });

    it('responsiveBreakpoint가 768이어야 함 (모바일 대응)', () => {
        expect(datagridNode.props.responsiveBreakpoint).toBe(768);
    });

    it('columnSelectorId가 설정되어야 함', () => {
        expect(datagridNode.props.columnSelectorId).toBe('admin_order_list_columns');
    });

    it('selectedIds prop을 사용해야 함 (selectedItems 아닌)', () => {
        expect(datagridNode.props.selectedIds).toBeDefined();
        expect(datagridNode.props.selectedItems).toBeUndefined();
    });

    it('외부 ColumnSelector 컴포넌트가 필터 섹션에 존재해야 함', () => {
        const columnSelectors = findNodes(filterSection, (n: any) =>
            n.name === 'ColumnSelector'
        );
        expect(columnSelectors.length).toBe(1);
        expect(columnSelectors[0].props.id).toBe('admin_order_list_columns');
    });

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
    const state = (orderList as any).state;

    it('visibleColumns에 device가 포함되어야 함', () => {
        expect(state.visibleColumns).toContain('device');
    });

    it('visibleColumns에 no가 포함되지 않아야 함 (기본 숨김)', () => {
        expect(state.visibleColumns).not.toContain('no');
    });
});

// ========================================
// 동적 필터 검증
// ========================================

describe('주문 목록 동적 필터 검증', () => {
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

    it('결제수단 필터가 iteration으로 동적 렌더링되어야 함', () => {
        const paymentRow = findNodes(filterSection, (n: any) => n.id === 'paymentMethod_filter_row')[0];
        expect(paymentRow).toBeDefined();

        const iterationLabels = findNodes(paymentRow, (n: any) =>
            n.name === 'Label' && n.iteration?.source?.includes('availablePaymentMethods')
        );
        expect(iterationLabels.length).toBe(1);
        expect(iterationLabels[0].iteration.item_var).toBe('method');
    });

    it('ecommerce_settings 데이터소스가 존재해야 함', () => {
        const dataSources = (orderList as any).data_sources;
        const settingsDS = dataSources.find((ds: any) => ds.id === 'ecommerce_settings');
        expect(settingsDS).toBeDefined();
        expect(settingsDS.endpoint).toContain('settings');
    });

    it('computed에 availableCountries가 정의되어야 함', () => {
        const computed = (orderList as any).computed;
        expect(computed.availableCountries).toBeDefined();
        expect(computed.availableCountries).toContain('is_active');
        expect(computed.availableCountries).toContain('available_countries');
    });

    it('computed에 availablePaymentMethods가 정의되어야 함', () => {
        const computed = (orderList as any).computed;
        expect(computed.availablePaymentMethods).toBeDefined();
        expect(computed.availablePaymentMethods).toContain('is_active');
        expect(computed.availablePaymentMethods).toContain('payment_methods');
    });
});

// ========================================
// 클레임 필터 제거 검증
// ========================================

describe('주문 목록 클레임 필터 제거 검증', () => {
    it('필터 섹션에 claimStatus 관련 노드가 없어야 함', () => {
        const claimNodes = findNodes(filterSection, (n: any) =>
            n.id?.includes('claim') || n.id?.includes('Claim')
        );
        expect(claimNodes.length).toBe(0);
    });

    it('data_sources params에 claim 관련 키가 없어야 함', () => {
        const ordersDS = (orderList as any).data_sources.find((ds: any) => ds.id === 'orders');
        const claimKeys = Object.keys(ordersDS.params).filter((k: string) => k.includes('claim'));
        expect(claimKeys.length).toBe(0);
    });

    it('state에 claim 관련 필드가 없어야 함', () => {
        const state = (orderList as any).state;
        const filterState = state.filter || {};
        const claimKeys = Object.keys(filterState).filter((k: string) =>
            k.toLowerCase().includes('claim')
        );
        expect(claimKeys.length).toBe(0);
    });
});

// ========================================
// 배송정책/배송비 필터 검증
// ========================================

describe('주문 목록 배송정책/배송비 필터 검증', () => {
    const dataSources = (orderList as any).data_sources;
    const ordersDS = dataSources.find((ds: any) => ds.id === 'orders');
    const computed = (orderList as any).computed;
    const state = (orderList as any).state;

    // --- 데이터소스 파라미터 검증 ---

    it('orders 데이터소스에 shipping_policy_id 파라미터가 있어야 함', () => {
        expect(ordersDS.params.shipping_policy_id).toBeDefined();
    });

    it('orders 데이터소스에 min_shipping_amount 파라미터가 있어야 함', () => {
        expect(ordersDS.params.min_shipping_amount).toBeDefined();
    });

    it('orders 데이터소스에 max_shipping_amount 파라미터가 있어야 함', () => {
        expect(ordersDS.params.max_shipping_amount).toBeDefined();
    });

    // --- shipping_policies 데이터소스 검증 ---

    it('shipping_policies 데이터소스가 존재해야 함', () => {
        const shippingPoliciesDS = dataSources.find((ds: any) => ds.id === 'shipping_policies');
        expect(shippingPoliciesDS).toBeDefined();
        expect(shippingPoliciesDS.endpoint).toContain('shipping-policies/active');
    });

    // --- state 검증 ---

    it('state.filter에 shippingPolicy 필드가 있어야 함', () => {
        expect(state.filter).toHaveProperty('shippingPolicy');
    });

    it('state.filter에 minShippingFee 필드가 있어야 함', () => {
        expect(state.filter).toHaveProperty('minShippingFee');
    });

    it('state.filter에 maxShippingFee 필드가 있어야 함', () => {
        expect(state.filter).toHaveProperty('maxShippingFee');
    });

    // --- computed 검증 ---

    it('computed에 filterShippingPolicy가 정의되어야 함', () => {
        expect(computed.filterShippingPolicy).toBeDefined();
        expect(computed.filterShippingPolicy).toContain('shippingPolicy');
    });

    it('computed에 shippingPolicyOptions가 정의되어야 함', () => {
        expect(computed.shippingPolicyOptions).toBeDefined();
        expect(computed.shippingPolicyOptions).toContain('shipping_policies');
    });

    it('computed에 filterMinShippingFee가 정의되어야 함', () => {
        expect(computed.filterMinShippingFee).toBeDefined();
        expect(computed.filterMinShippingFee).toContain('minShippingFee');
    });

    it('computed에 filterMaxShippingFee가 정의되어야 함', () => {
        expect(computed.filterMaxShippingFee).toBeDefined();
        expect(computed.filterMaxShippingFee).toContain('maxShippingFee');
    });

    // --- 필터 UI 검증 ---

    it('필터 섹션에 shippingPolicy_filter_row가 존재해야 함', () => {
        const shippingPolicyRow = findNodes(filterSection, (n: any) => n.id === 'shippingPolicy_filter_row');
        expect(shippingPolicyRow.length).toBe(1);
    });

    it('필터 섹션에 shippingFee_filter_row가 존재해야 함', () => {
        const shippingFeeRow = findNodes(filterSection, (n: any) => n.id === 'shippingFee_filter_row');
        expect(shippingFeeRow.length).toBe(1);
    });

    it('배송정책 필터에 Select 컴포넌트가 있어야 함', () => {
        const shippingPolicyRow = findNodes(filterSection, (n: any) => n.id === 'shippingPolicy_filter_row')[0];
        const selects = findNodes(shippingPolicyRow, (n: any) => n.name === 'Select');
        expect(selects.length).toBeGreaterThanOrEqual(1);
    });

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

    it('product 컬럼이 존재해야 함', () => {
        expect(productColumn).toBeDefined();
    });

    it('상품명이 first_option?.product_name 바인딩을 사용해야 함', () => {
        const cellJson = JSON.stringify(productColumn.cellChildren);
        expect(cellJson).toContain('first_option?.product_name');
    });

    it('options_count > 1일 때 "외 X건" 텍스트가 표시되어야 함', () => {
        const moreNodes = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.text?.includes('product.more') && n.if?.includes('options_count')
        );
        expect(moreNodes.length).toBe(1);
    });

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

    it('"외 X건"은 options_count > 1 조건에서만 표시되어야 함', () => {
        const moreNode = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.text?.includes('product.more')
        )[0];
        expect(moreNode.if).toContain('options_count > 1');
    });

    it('옵션명이 first_option?.product_option_name 바인딩을 사용해야 함', () => {
        const optionNodes = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.text?.includes('first_option?.product_option_name')
        );
        expect(optionNodes.length).toBeGreaterThanOrEqual(1);
    });

    it('대표상품 썸네일이 표시되어야 함', () => {
        const imgNodes = findNodes({ children: productColumn.cellChildren }, (n: any) =>
            n.name === 'Img' && n.props?.src?.includes('thumbnail_url')
        );
        expect(imgNodes.length).toBe(1);
    });

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

    it('order_amount 컬럼이 존재해야 함', () => {
        expect(orderAmountColumn).toBeDefined();
    });

    it('주문금액 셀에 배송비 표시가 $t:defer: 패턴이어야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('$t:defer:');
        expect(cellJson).toContain('total_shipping_amount_formatted');
    });

    it('주문금액 셀에 결제금액 표시가 $t:defer: 패턴이어야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('total_paid_amount_formatted');
    });

    it('주문금액 셀에 미결제금액 표시가 $t:defer: 패턴이어야 함', () => {
        const cellJson = JSON.stringify(orderAmountColumn.cellChildren);
        expect(cellJson).toContain('total_unpaid_amount_formatted');
    });

    it('결제금액에 초록색 스타일이 적용되어야 함', () => {
        const paidNodes = findNodes({ children: orderAmountColumn.cellChildren }, (n: any) => {
            const json = JSON.stringify(n);
            return json?.includes('total_paid_amount_formatted') && json?.includes('green');
        });
        expect(paidNodes.length).toBeGreaterThanOrEqual(1);
    });

    it('미결제금액에 적색 스타일이 적용되어야 함', () => {
        const unpaidNodes = findNodes({ children: orderAmountColumn.cellChildren }, (n: any) => {
            const json = JSON.stringify(n);
            return json?.includes('total_unpaid_amount_formatted') && json?.includes('red');
        });
        expect(unpaidNodes.length).toBeGreaterThanOrEqual(1);
    });
});

// ========================================
// 주문자 필터 검증
// ========================================

describe('주문 목록 주문자 필터 검증', () => {
    const dataSources = (orderList as any).data_sources;
    const ordersDS = dataSources.find((ds: any) => ds.id === 'orders');
    const state = (orderList as any).state;
    const initActions = (orderList as any).init_actions;
    const namedActions = (orderList as any).named_actions;

    // --- 데이터소스 파라미터 검증 ---

    it('orders 데이터소스에 orderer_uuid 파라미터가 있어야 함', () => {
        expect(ordersDS.params.orderer_uuid).toBeDefined();
        expect(ordersDS.params.orderer_uuid).toContain('query.orderer_uuid');
    });

    // --- state 검증 ---

    it('state에 ordererSearchResults 필드가 있어야 함', () => {
        expect(state).toHaveProperty('ordererSearchResults');
        expect(state.ordererSearchResults).toEqual([]);
    });

    // --- init_actions 검증 ---

    it('init_actions에 orderer_uuid 기반 사용자 검색 apiCall이 있어야 함', () => {
        const ordererInitAction = initActions.find((a: any) =>
            a.handler === 'apiCall' &&
            a.target?.includes('users/search') &&
            a.if?.includes('orderer_uuid')
        );
        expect(ordererInitAction).toBeDefined();
        expect(ordererInitAction.params.query.uuid).toContain('query.orderer_uuid');
    });

    it('init_actions setState에 ordererUuid 필터 초기화가 있어야 함', () => {
        const setStateAction = initActions.find((a: any) =>
            a.handler === 'setState' && a.params?._local?.filter?.ordererUuid !== undefined
        );
        expect(setStateAction).toBeDefined();
    });

    // --- named_actions 검증 ---

    it('searchOrders named_action에 orderer_uuid 쿼리 파라미터가 있어야 함', () => {
        const searchQuery = namedActions.searchOrders.params.query;
        expect(searchQuery.orderer_uuid).toBeDefined();
        expect(searchQuery.orderer_uuid).toContain('ordererUuid');
    });

    // --- 필터 UI 검증 ---

    it('필터 섹션에 orderer_filter_row가 존재해야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row');
        expect(ordererRow.length).toBe(1);
    });

    it('주문자 필터에 SearchableDropdown 컴포넌트가 있어야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row')[0];
        const dropdowns = findNodes(ordererRow, (n: any) => n.name === 'SearchableDropdown');
        expect(dropdowns.length).toBe(1);
    });

    it('SearchableDropdown에 ordererSearchResults 옵션이 바인딩되어야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row')[0];
        const dropdown = findNodes(ordererRow, (n: any) => n.name === 'SearchableDropdown')[0];
        expect(dropdown.props.options).toContain('ordererSearchResults');
    });

    it('SearchableDropdown의 onSearch 이벤트가 /api/admin/users/search를 호출해야 함', () => {
        const ordererRow = findNodes(filterSection, (n: any) => n.id === 'orderer_filter_row')[0];
        const dropdown = findNodes(ordererRow, (n: any) => n.name === 'SearchableDropdown')[0];
        const searchAction = dropdown.actions?.find((a: any) => a.event === 'onSearch');
        expect(searchAction).toBeDefined();
        expect(searchAction.target).toContain('users/search');
    });

    it('주문자 필터 클리어 버튼이 존재해야 함', () => {
        const clearButton = findNodes(filterSection, (n: any) => n.id === 'f_162_orderer_clear_button');
        expect(clearButton.length).toBe(1);
    });
});

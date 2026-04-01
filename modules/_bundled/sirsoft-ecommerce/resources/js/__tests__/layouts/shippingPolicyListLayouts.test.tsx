/**
 * shippingPolicyListLayouts.test.tsx
 *
 * 배송정책 리스트 레이아웃 (admin_ecommerce_shipping_policy_list.json) 구조 검증
 * - 국가별 설정(country_settings) 요약 표시 기반으로 재설계됨
 * - DataGrid: countries_display, fee_summary 요약 컬럼 + subRow 국가별 상세
 * - 필터: Settings available_countries 기반 동적 Checkbox
 */
import { describe, it, expect } from 'vitest';

// Main layout
import listLayout from '../../../layouts/admin/admin_ecommerce_shipping_policy_list.json';

// Partials
import datagridPartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_datagrid.json';
import filterPartial from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_list/_partial_filter.json';

// ──────────────────────────────────────────────
// Helper
// ──────────────────────────────────────────────
function flattenAll(node: any, acc: any[] = []): any[] {
    if (!node) return acc;
    acc.push(node);
    if (node.children) node.children.forEach((c: any) => flattenAll(c, acc));
    if (node.slots) {
        Object.values(node.slots).forEach((slotArr: any) => {
            if (Array.isArray(slotArr)) slotArr.forEach((c: any) => flattenAll(c, acc));
        });
    }
    return acc;
}

function findById(root: any, id: string): any {
    return flattenAll(root).find((n: any) => n.id === id);
}

function findAllByType(root: any, type: string, name?: string): any[] {
    return flattenAll(root).filter(
        (n: any) => n.type === type && (!name || n.name === name)
    );
}

function collectI18nKeys(obj: any, keys: Set<string> = new Set()): Set<string> {
    if (!obj) return keys;
    if (typeof obj === 'string') {
        const matches = obj.match(/\$t:[a-zA-Z0-9_.\-]+/g);
        if (matches) matches.forEach(m => keys.add(m));
    } else if (Array.isArray(obj)) {
        obj.forEach(item => collectI18nKeys(item, keys));
    } else if (typeof obj === 'object') {
        Object.values(obj).forEach(v => collectI18nKeys(v, keys));
    }
    return keys;
}

// ──────────────────────────────────────────────
// 1. 메인 레이아웃 메타/구조
// ──────────────────────────────────────────────
describe('메인 레이아웃 (admin_ecommerce_shipping_policy_list)', () => {
    it('기본 메타 속성이 올바르다', () => {
        expect(listLayout.version).toBe('1.0.0');
        expect(listLayout.layout_name).toBe('admin_ecommerce_shipping_policy_list');
        expect(listLayout.extends).toBe('_admin_base');
        expect(listLayout.permissions).toContain('sirsoft-ecommerce.shipping-policies.read');
    });

    it('meta.title에 다국어 키를 사용한다', () => {
        expect(listLayout.meta.title).toBe('$t:sirsoft-ecommerce.admin.shipping_policy.title');
    });
});

// ──────────────────────────────────────────────
// 2. State 구조
// ──────────────────────────────────────────────
describe('State 구조', () => {
    const { state, global_state } = listLayout;

    it('local state에 필터 기본값이 정의되어 있다', () => {
        expect(state.filter).toBeDefined();
        expect(state.filter.search).toBe('');
        expect(state.filter.shipping_methods).toEqual([]);
        expect(state.filter.charge_policies).toEqual([]);
        expect(state.filter.countries).toEqual([]);
        expect(state.filter.is_active).toBe('');
    });

    it('local state에 selectAll, sortBy, perPage가 있다', () => {
        expect(state.selectedItems).toEqual([]);
        expect(state.selectAll).toBe(false);
        expect(state.sortBy).toBe('created_at_desc');
        expect(state.perPage).toBe('20');
    });

    it('global state에 모달/벌크 관련 상태가 있다', () => {
        expect(global_state.targetPolicy).toBeNull();
        expect(global_state.targetDefaultPolicy).toBeNull();
        expect(global_state.isDeleting).toBe(false);
        expect(global_state.isBulkDeleting).toBe(false);
        expect(global_state.isBulkToggling).toBe(false);
        expect(global_state.isSettingDefault).toBe(false);
        expect(global_state.bulkSelectedItems).toEqual([]);
    });
});

// ──────────────────────────────────────────────
// 3. Data Sources
// ──────────────────────────────────────────────
describe('Data Sources', () => {
    const { data_sources } = listLayout;

    it('2개 data_source가 정의되어 있다', () => {
        expect(data_sources).toHaveLength(2);
    });

    it('shipping_policies API가 올바르게 설정되어 있다', () => {
        const ds = data_sources.find((d: any) => d.id === 'shipping_policies');
        expect(ds).toBeDefined();
        expect(ds.endpoint).toBe('/api/modules/sirsoft-ecommerce/admin/shipping-policies');
        expect(ds.method).toBe('GET');
        expect(ds.auto_fetch).toBe(true);
        expect(ds.auth_required).toBe(true);
    });

    it('shipping_policies params가 query 기반이다', () => {
        const ds = data_sources.find((d: any) => d.id === 'shipping_policies');
        const { params } = ds;
        expect(params.page).toContain('query.page');
        expect(params.per_page).toContain('query.per_page');
        expect(params.search).toContain('query.search');
        expect(params.shipping_methods).toContain("query['shipping_methods[]']");
        expect(params.charge_policies).toContain("query['charge_policies[]']");
        expect(params.countries).toContain("query['countries[]']");
        expect(params.is_active).toContain('query.is_active');
    });

    it('shipping_available_countries data_source가 있다 (Settings 기반 동적 국가)', () => {
        const ds = data_sources.find((d: any) => d.id === 'shipping_available_countries');
        expect(ds).toBeDefined();
        expect(ds.endpoint).toContain('/admin/settings');
        expect(ds.auto_fetch).toBe(true);
        expect(ds.transform).toContain('shipping');
        expect(ds.transform).toContain('available_countries');
    });
});

// ──────────────────────────────────────────────
// 4. init_actions
// ──────────────────────────────────────────────
describe('init_actions', () => {
    it('setState로 query에서 필터 값을 복원한다', () => {
        const { init_actions } = listLayout;
        expect(init_actions).toHaveLength(1);
        const action = init_actions[0];
        expect(action.handler).toBe('setState');
        expect(action.params._local.filter).toBeDefined();
        expect(action.params._local.filter.search).toContain('query.search');
        expect(action.params._local.filter.countries).toContain("query['countries[]']");
    });
});

// ──────────────────────────────────────────────
// 5. named_actions
// ──────────────────────────────────────────────
describe('named_actions', () => {
    it('searchShippingPolicies가 navigate + replace로 정의되어 있다', () => {
        const action = listLayout.named_actions.searchShippingPolicies;
        expect(action.handler).toBe('navigate');
        expect(action.params.replace).toBe(true);
        expect(action.params.path).toBe('/admin/ecommerce/shipping-policies');
    });

    it('searchShippingPolicies query에 countries[] 파라미터가 있다', () => {
        const { query } = listLayout.named_actions.searchShippingPolicies.params;
        expect(query['countries[]']).toBeDefined();
        expect(query['countries[]']).toContain('_local.filter.countries');
    });

    it('searchShippingPolicies query에 모든 필터 파라미터가 있다', () => {
        const { query } = listLayout.named_actions.searchShippingPolicies.params;
        expect(query.search).toBeDefined();
        expect(query['shipping_methods[]']).toBeDefined();
        expect(query['charge_policies[]']).toBeDefined();
        expect(query.is_active).toBeDefined();
    });
});

// ──────────────────────────────────────────────
// 6. Partials 참조
// ──────────────────────────────────────────────
describe('Partials 참조', () => {
    const allNodes = flattenAll(listLayout);
    const partialRefs = allNodes.filter((n: any) => n.partial).map((n: any) => n.partial);

    it('content 슬롯에 _partial_filter.json 참조가 있다', () => {
        expect(partialRefs).toContainEqual(expect.stringContaining('_partial_filter.json'));
    });

    it('content 슬롯에 _partial_datagrid.json 참조가 있다', () => {
        expect(partialRefs).toContainEqual(expect.stringContaining('_partial_datagrid.json'));
    });

    it('content 슬롯에 _partial_bulk_actions.json 참조가 있다', () => {
        expect(partialRefs).toContainEqual(expect.stringContaining('_partial_bulk_actions.json'));
    });

    it('5개 모달 partial이 있다', () => {
        expect(listLayout.modals).toHaveLength(5);
        const modalPartials = listLayout.modals.map((m: any) => m.partial);
        expect(modalPartials).toContainEqual(expect.stringContaining('_modal_delete.json'));
        expect(modalPartials).toContainEqual(expect.stringContaining('_modal_copy.json'));
        expect(modalPartials).toContainEqual(expect.stringContaining('_modal_bulk_delete.json'));
        expect(modalPartials).toContainEqual(expect.stringContaining('_modal_bulk_toggle.json'));
        expect(modalPartials).toContainEqual(expect.stringContaining('_modal_set_default.json'));
    });
});

// ──────────────────────────────────────────────
// 7. DataGrid Partial
// ──────────────────────────────────────────────
describe('DataGrid Partial (_partial_datagrid.json)', () => {
    it('DataGrid 컴포넌트로 정의되어 있다', () => {
        expect(datagridPartial.type).toBe('composite');
        expect(datagridPartial.name).toBe('DataGrid');
    });

    it('selectable이 true이다', () => {
        expect(datagridPartial.props.selectable).toBe(true);
    });

    it('data가 shipping_policies를 참조한다', () => {
        expect(datagridPartial.props.data).toContain('shipping_policies');
    });

    describe('컬럼 구조', () => {
        const columns = datagridPartial.props.columns;

        it('5개 컬럼이 정의되어 있다 (is_default, name, countries, fee_summary, is_active)', () => {
            expect(columns).toHaveLength(5);
        });

        it('is_default 컬럼이 Toggle을 사용한다', () => {
            const col = columns.find((c: any) => c.field === 'is_default');
            expect(col).toBeDefined();
            const toggle = col.cellChildren?.find((c: any) => c.name === 'Toggle');
            expect(toggle).toBeDefined();
        });

        it('name_localized 컬럼이 sortable이다', () => {
            const col = columns.find((c: any) => c.field === 'name_localized');
            expect(col).toBeDefined();
            expect(col.sortable).toBe(true);
        });

        it('countries_display 컬럼이 country_settings iteration으로 국기를 표시한다', () => {
            const col = columns.find((c: any) => c.field === 'countries_display');
            expect(col).toBeDefined();
            // cellChildren에서 iteration 찾기
            const allInCol = flattenAll({ children: col.cellChildren });
            const iterNode = allInCol.find((n: any) => n.iteration);
            expect(iterNode).toBeDefined();
            expect(iterNode.iteration.source).toContain('row.country_settings');
            expect(iterNode.iteration.item_var).toBe('countryCode');
        });

        it('fee_summary 컬럼이 row.fee_summary를 표시한다', () => {
            const col = columns.find((c: any) => c.field === 'fee_summary');
            expect(col).toBeDefined();
            const textNode = flattenAll({ children: col.cellChildren }).find((n: any) => n.text);
            expect(textNode.text).toContain('row.fee_summary');
        });

        it('is_active 컬럼이 Toggle + apiCall toggle-active를 사용한다', () => {
            const col = columns.find((c: any) => c.field === 'is_active');
            expect(col).toBeDefined();
            const toggle = col.cellChildren?.find((c: any) => c.name === 'Toggle');
            expect(toggle).toBeDefined();
            const apiAction = toggle.actions?.find((a: any) => a.handler === 'apiCall');
            expect(apiAction).toBeDefined();
            expect(apiAction.target).toContain('toggle-active');
        });

        it('기존 flat 컬럼(shipping_method, carrier, charge_policy, currency_code)이 제거되었다', () => {
            const fieldNames = columns.map((c: any) => c.field);
            expect(fieldNames).not.toContain('shipping_method');
            expect(fieldNames).not.toContain('carrier');
            expect(fieldNames).not.toContain('charge_policy');
            expect(fieldNames).not.toContain('currency_code');
            expect(fieldNames).not.toContain('extra_fee_enabled');
        });
    });

    describe('rowActions', () => {
        it('edit, copy, delete 액션이 있다', () => {
            const { rowActions } = datagridPartial.props;
            const actionIds = rowActions.map((a: any) => a.id);
            expect(actionIds).toContain('edit');
            expect(actionIds).toContain('copy');
            expect(actionIds).toContain('delete');
        });

        it('delete 액션이 danger variant이다', () => {
            const deleteAction = datagridPartial.props.rowActions.find((a: any) => a.id === 'delete');
            expect(deleteAction.variant).toBe('danger');
        });
    });

    describe('subRowChildren (국가별 상세)', () => {
        const subRow = datagridPartial.props.subRowChildren;

        it('subRowChildren이 정의되어 있다', () => {
            expect(subRow).toBeDefined();
            expect(Array.isArray(subRow)).toBe(true);
        });

        it('country_settings가 비어있을 때 안내 메시지를 표시한다', () => {
            const allNodes = flattenAll({ children: subRow });
            const emptyNode = allNodes.find(
                (n: any) => n.if && n.if.includes('country_settings') && n.if.includes('length === 0')
            );
            expect(emptyNode).toBeDefined();
            expect(emptyNode.text).toContain('no_country_settings');
        });

        it('country_settings iteration으로 국가별 상세를 표시한다', () => {
            const allNodes = flattenAll({ children: subRow });
            const iterNode = allNodes.find(
                (n: any) => n.iteration && n.iteration.source?.includes('country_settings')
            );
            expect(iterNode).toBeDefined();
            expect(iterNode.iteration.item_var).toBe('cs');
            expect(iterNode.iteration.index_var).toBe('csIdx');
        });

        it('국가별 상세에 국기+코드, 배송방법, 부과정책 정보가 있다', () => {
            const allNodes = flattenAll({ children: subRow });
            // 국기 아이콘
            const flagNode = allNodes.find(
                (n: any) => n.props?.className?.includes('fi-{{cs.country_code')
            );
            expect(flagNode).toBeDefined();

            // 배송방법 라벨
            const methodNode = allNodes.find(
                (n: any) => n.text && n.text.includes('cs.shipping_method_label')
            );
            expect(methodNode).toBeDefined();

            // 부과정책 라벨
            const policyNode = allNodes.find(
                (n: any) => n.text && n.text.includes('cs.charge_policy_label')
            );
            expect(policyNode).toBeDefined();
        });

        it('국가별 상세에 도서산간 배지(KR 전용)가 있다', () => {
            const allNodes = flattenAll({ children: subRow });
            const extraFeeBadge = allNodes.find(
                (n: any) => n.if && n.if.includes('extra_fee_enabled') && n.if.includes("country_code === 'KR'")
            );
            expect(extraFeeBadge).toBeDefined();
            expect(extraFeeBadge.text).toContain('extra_fee_badge');
        });

        it('국가별 상세에 비활성 배지가 있다', () => {
            const allNodes = flattenAll({ children: subRow });
            const inactiveBadge = allNodes.find(
                (n: any) => n.if && n.if.includes('!cs.is_active')
            );
            expect(inactiveBadge).toBeDefined();
            expect(inactiveBadge.text).toContain('inactive');
        });

        it('국가별 상세에 free_threshold 표시가 있다', () => {
            const allNodes = flattenAll({ children: subRow });
            const thresholdNode = allNodes.find(
                (n: any) => n.if && n.if.includes('cs.free_threshold > 0')
            );
            expect(thresholdNode).toBeDefined();
            expect(thresholdNode.text).toContain('free_above');
        });

    });

    describe('DataGrid 이벤트 액션', () => {
        it('onSelectionChange에서 global bulkSelectedItems를 업데이트한다', () => {
            const selAction = datagridPartial.actions.find(
                (a: any) => a.event === 'onSelectionChange'
            );
            expect(selAction).toBeDefined();
            expect(selAction.handler).toBe('setState');
            expect(selAction.params.target).toBe('global');
            expect(selAction.params.bulkSelectedItems).toContain('$args[0]');
        });

        it('onRowAction에서 switch로 edit/copy/delete를 분기한다', () => {
            const rowAction = datagridPartial.actions.find(
                (a: any) => a.event === 'onRowAction'
            );
            expect(rowAction).toBeDefined();
            expect(rowAction.handler).toBe('switch');
            expect(rowAction.cases.edit).toBeDefined();
            expect(rowAction.cases.copy).toBeDefined();
            expect(rowAction.cases.delete).toBeDefined();
        });

        it('edit 액션이 navigate로 수정 페이지로 이동한다', () => {
            const rowAction = datagridPartial.actions.find(
                (a: any) => a.event === 'onRowAction'
            );
            expect(rowAction.cases.edit.handler).toBe('navigate');
            expect(rowAction.cases.edit.params.path).toContain('/edit');
        });

        it('copy 액션이 sequence(setState → openModal)이다', () => {
            const rowAction = datagridPartial.actions.find(
                (a: any) => a.event === 'onRowAction'
            );
            expect(rowAction.cases.copy.handler).toBe('sequence');
            expect(rowAction.cases.copy.actions).toHaveLength(2);
            expect(rowAction.cases.copy.actions[0].handler).toBe('setState');
            expect(rowAction.cases.copy.actions[1].handler).toBe('openModal');
        });
    });
});

// ──────────────────────────────────────────────
// 8. 필터 Partial
// ──────────────────────────────────────────────
describe('필터 Partial (_partial_filter.json)', () => {
    it('filter_section ID가 있다', () => {
        expect(filterPartial.id).toBe('filter_section');
    });

    it('검색 입력 필드가 있다', () => {
        const allNodes = flattenAll(filterPartial);
        const searchInput = allNodes.find(
            (n: any) => n.name === 'Input' && n.props?.name === 'search'
        );
        expect(searchInput).toBeDefined();
        expect(searchInput.props.value).toContain('_local.filter.search');
    });

    describe('국가 필터 (동적 Checkbox)', () => {
        const allNodes = flattenAll(filterPartial);
        const countryFilter = findById(filterPartial, 'country_filter');

        it('country_filter 섹션이 있다', () => {
            expect(countryFilter).toBeDefined();
        });

        it('shipping_available_countries가 있을 때만 표시된다', () => {
            expect(countryFilter.if).toContain('shipping_available_countries');
        });

        it('동적 iteration으로 국가 Checkbox를 생성한다', () => {
            const iterNodes = allNodes.filter(
                (n: any) => n.iteration?.source?.includes('shipping_available_countries')
            );
            expect(iterNodes.length).toBeGreaterThan(0);
            const iterNode = iterNodes[0];
            expect(iterNode.iteration.item_var).toBe('acountry');
        });

        it('국가 Checkbox가 _local.filter.countries를 토글한다', () => {
            const iterNodes = allNodes.filter(
                (n: any) => n.iteration?.source?.includes('shipping_available_countries')
            );
            // iteration 내의 Input[type=checkbox] 찾기
            const checkboxNodes = flattenAll({ children: iterNodes }).filter(
                (n: any) =>
                    n.name === 'Input' &&
                    n.props?.type === 'checkbox' &&
                    n.props?.checked?.includes('filter.countries')
            );
            expect(checkboxNodes.length).toBeGreaterThan(0);
        });

        it('전체 선택 Checkbox가 있다', () => {
            const allCheckbox = allNodes.find(
                (n: any) =>
                    n.name === 'Input' &&
                    n.props?.type === 'checkbox' &&
                    n.props?.checked?.includes('!_local.filter.countries') &&
                    n.props?.checked?.includes('length === 0')
            );
            expect(allCheckbox).toBeDefined();
        });
    });
});

// ──────────────────────────────────────────────
// 9. 페이지 헤더 & 등록 버튼
// ──────────────────────────────────────────────
describe('페이지 헤더', () => {
    const allNodes = flattenAll(listLayout);

    it('H1 타이틀이 있다', () => {
        const h1 = allNodes.find(
            (n: any) => n.name === 'H1' && n.text?.includes('shipping_policy.title')
        );
        expect(h1).toBeDefined();
    });

    it('배송정책 등록 버튼이 create 페이지로 이동한다', () => {
        const buttons = allNodes.filter((n: any) => n.name === 'Button');
        const createBtn = buttons.find((btn: any) => {
            const children = flattenAll(btn);
            return children.some((c: any) => c.text?.includes('shipping_policy.add'));
        });
        expect(createBtn).toBeDefined();
        const navAction = createBtn.actions?.find((a: any) => a.handler === 'navigate');
        expect(navAction.params.path).toContain('/create');
    });
});

// ──────────────────────────────────────────────
// 10. 정렬 & 페이지네이션
// ──────────────────────────────────────────────
describe('정렬 및 페이지네이션', () => {
    const allNodes = flattenAll(listLayout);

    it('정렬 Select에 5개 옵션이 있다', () => {
        const sortSelect = allNodes.find(
            (n: any) => n.name === 'Select' && n.props?.name === 'sortBy'
        );
        expect(sortSelect).toBeDefined();
        expect(sortSelect.props.options).toHaveLength(5);
        const values = sortSelect.props.options.map((o: any) => o.value);
        expect(values).toContain('created_at_desc');
        expect(values).toContain('created_at_asc');
        expect(values).toContain('name_asc');
        expect(values).toContain('name_desc');
        expect(values).toContain('sort_order_asc');
    });

    it('페이지당 항목 수 Select에 10/20/50/100 옵션이 있다', () => {
        const perPageSelect = allNodes.find(
            (n: any) => n.name === 'Select' && n.props?.name === 'perPage'
        );
        expect(perPageSelect).toBeDefined();
        const values = perPageSelect.props.options.map((o: any) => o.value);
        expect(values).toEqual(['10', '20', '50', '100']);
    });

    it('Pagination 컴포넌트가 있다', () => {
        const pagination = allNodes.find((n: any) => n.name === 'Pagination');
        expect(pagination).toBeDefined();
        expect(pagination.props.currentPage).toContain('_local.currentPage');
        expect(pagination.props.totalPages).toContain('_local.totalPages');
    });
});

// ──────────────────────────────────────────────
// 11. 빈 상태(Empty State)
// ──────────────────────────────────────────────
describe('빈 상태 (Empty State)', () => {
    const emptyState = findById(listLayout, 'empty_state');

    it('empty_state 요소가 있다', () => {
        expect(emptyState).toBeDefined();
    });

    it('데이터가 없을 때만 표시된다', () => {
        expect(emptyState.if).toContain('!shipping_policies?.loading');
        expect(emptyState.if).toContain('length === 0');
    });

    it('필터 없을 때 기본 빈 상태 메시지가 있다', () => {
        const allNodes = flattenAll(emptyState);
        const titleNode = allNodes.find(
            (n: any) => n.name === 'H3' && n.text?.includes('empty.title')
        );
        expect(titleNode).toBeDefined();
        expect(titleNode.if).toContain('!query.search');
    });

    it('필터 있을 때 검색결과 없음 메시지가 있다', () => {
        const allNodes = flattenAll(emptyState);
        const noResultNode = allNodes.find(
            (n: any) => n.name === 'H3' && n.text?.includes('empty.no_results')
        );
        expect(noResultNode).toBeDefined();
        expect(noResultNode.if).toContain('query.search');
    });

    it('필터 초기화 버튼이 있다', () => {
        const allNodes = flattenAll(emptyState);
        const resetBtn = allNodes.find(
            (n: any) => n.text?.includes('empty.reset_filter')
        );
        expect(resetBtn).toBeDefined();
    });
});

// ──────────────────────────────────────────────
// 11-1. 빈 데이터 시 조건부 렌더링
// ──────────────────────────────────────────────
describe('빈 데이터 시 조건부 렌더링', () => {
    const allNodes = flattenAll(listLayout);

    it('DataGrid partial에 데이터 존재 조건(if)이 있다', () => {
        const datagridRef = allNodes.find(
            (n: any) => n.partial?.includes('_partial_datagrid.json')
        );
        expect(datagridRef).toBeDefined();
        expect(datagridRef.if).toContain('shipping_policies?.data?.data?.length > 0');
    });

    it('bulk_actions partial에 데이터 존재 조건(if)이 있다', () => {
        const bulkRef = allNodes.find(
            (n: any) => n.partial?.includes('_partial_bulk_actions.json')
        );
        expect(bulkRef).toBeDefined();
        expect(bulkRef.if).toContain('shipping_policies?.data?.data?.length > 0');
    });

    it('table_header_bar에 데이터 존재 조건(if)이 있다', () => {
        const headerBar = findById(listLayout, 'table_header_bar');
        expect(headerBar).toBeDefined();
        expect(headerBar.if).toContain('shipping_policies?.data?.data?.length > 0');
    });

    it('pagination_section에 데이터 존재 조건(if)이 있다', () => {
        const pagination = findById(listLayout, 'pagination_section');
        expect(pagination).toBeDefined();
        expect(pagination.if).toContain('shipping_policies?.data?.data?.length > 0');
    });

    it('empty_state는 데이터 없을 때만 표시된다 (반대 조건)', () => {
        const emptyState = findById(listLayout, 'empty_state');
        expect(emptyState).toBeDefined();
        expect(emptyState.if).toContain('length === 0');
        expect(emptyState.if).toContain('!shipping_policies?.loading');
    });
});

// ──────────────────────────────────────────────
// 12. 다국어 키 경로 무결성
// ──────────────────────────────────────────────
describe('다국어 키 경로 무결성', () => {
    it('모든 i18n 키가 sirsoft-ecommerce 모듈 네임스페이스를 사용한다', () => {
        const keys = collectI18nKeys(listLayout);
        const datagridKeys = collectI18nKeys(datagridPartial);
        const filterKeys = collectI18nKeys(filterPartial);
        const allKeys = new Set([...keys, ...datagridKeys, ...filterKeys]);

        allKeys.forEach(key => {
            // admin 또는 enums 네임스페이스 허용
            expect(key).toMatch(/^\$t:sirsoft-ecommerce\.(admin|enums)\./);
        });
    });

    it('다국어 키가 20개 이상 사용된다', () => {
        const keys = collectI18nKeys(listLayout);
        const datagridKeys = collectI18nKeys(datagridPartial);
        const filterKeys = collectI18nKeys(filterPartial);
        const allKeys = new Set([...keys, ...datagridKeys, ...filterKeys]);
        expect(allKeys.size).toBeGreaterThanOrEqual(20);
    });
});

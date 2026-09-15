/**
 * @file admin-ecommerce-wishlist-index.test.tsx
 * @description 관리자 찜 관리 목록 (#524 WP-E) — 레이아웃 JSON 계약 회귀.
 *
 * 네 이커머스 관리자 목록(찜·장바구니·배송지·문의)은 같은 뼈대를 쓴다 — 조건은 주소에서 읽고,
 * 검색 조립은 명명 액션 한 자리에 모이며, 선택은 보이는 쪽에만 걸리고, 삭제는 `can_manage`
 * 능력이 있을 때만 열린다. 이 파일은 그 뼈대와 «이 목록만의 차이» 를 함께 고정한다.
 *
 * 이 목록의 차이:
 *  - 숨김·품절 상품은 목록에서 빠진다(`Product::scopeVisible`) — 회원 화면에서 사라진 상품이
 *    관리자 찜 목록에만 남아 있으면 "왜 이 상품이 여기 있나" 를 설명할 수 없다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, callbacksOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../../layouts/admin/admin_ecommerce_wishlist_index.json');

const SEARCH_FIELDS = ['member', 'member_id', 'email', 'product'];
const COLUMNS = ['created_at', 'member', 'product'];

/** 액션 트리를 평탄화한다 — `sequence` 는 자식 액션을 접어 둔다. */
function flattenActions(node: unknown): any[] {
    const out: any[] = [];
    const visit = (value: any): void => {
        if (!value || typeof value !== 'object') return;
        if (Array.isArray(value)) {
            value.forEach(visit);
            return;
        }
        if (typeof value.handler === 'string' || typeof value.actionRef === 'string') out.push(value);
        for (const child of Object.values(value)) visit(child);
    };
    visit(node);
    return out;
}

const dataSource = layout.data_sources.find((d: any) => d.id === 'wishlists');
const namedAction = layout.named_actions['searchadmin_ecommerce_wishlist_index'];
const grid = flattenNodes(layout).find((n: any) => n?.name === 'DataGrid');
const modals: Record<string, any> = Object.fromEntries(asArray(layout.modals).map((m: any) => [m.id, m]));

describe('관리자 찜 관리 목록 (#524 WP-E)', () => {
    // @scenario list=wishlist
    // @effects ecommerce_admin_list_datasource_passes_all_filters
    it('목록 데이터소스가 검색·회원·기간 조건을 주소에서 읽는다', () => {
        expect(dataSource.endpoint).toBe('/api/modules/sirsoft-ecommerce/admin/wishlists');
        expect(dataSource.auth_required).toBe(true);
        expect(dataSource.params).toMatchObject({
            page: expect.stringMatching(/query\.page/),
            per_page: expect.stringMatching(/query\.per_page/),
            sort: expect.stringMatching(/query\.sort/),
            search_field: expect.stringMatching(/query\.search_field/),
            search_keyword: expect.stringMatching(/query\.search_keyword/),
            user_uuid: expect.stringMatching(/query\.user_uuid/),
            start_date: expect.stringMatching(/query\.start_date/),
            end_date: expect.stringMatching(/query\.end_date/),
        });
    });

    // @scenario list=wishlist, permission=read
    // @effects ecommerce_admin_list_requires_read_permission
    it('읽기 권한을 요구한다', () => {
        expect(layout.permissions).toContain('sirsoft-ecommerce.wishlists.read');
    });

    // @scenario list=wishlist, search_field=member
    // @effects ecommerce_admin_list_search_field_matches_server_whitelist
    it('검색 갈래가 서버 화이트리스트와 같다 — 장식 셀렉트 금지', () => {
        const select = flattenNodes(layout)
            .find((n: any) => asArray(n?.props?.options).some((o: any) => o.value === 'member_id'));

        expect(asArray(select.props.options).map((o: any) => o.value)).toEqual(SEARCH_FIELDS);
    });

    // @scenario list=wishlist, search_field=member
    // @effects ecommerce_admin_list_search_action_resets_page_keeps_member_scope
    it('검색은 페이지를 되돌리고 회원 한정은 건드리지 않는다', () => {
        expect(namedAction.handler).toBe('navigate');
        expect(namedAction.params.path).toBe('/admin/ecommerce/wishlists');
        expect(namedAction.params.query.page).toBe('');
        expect(namedAction.params.query.search_field).toMatch(/_local\.filter\.searchField/);
        expect(namedAction.params.replace).toBe(true);
        expect(namedAction.params.transition_overlay_target).toBe('wishlists_datagrid__body');
        // 「이 회원 안에서 검색」이 자연스럽다 — 해제는 배너 버튼이 한다.
        expect(namedAction.params.query).not.toHaveProperty('user_uuid');
    });

    // @scenario list=wishlist
    // @effects ecommerce_admin_list_initlocal_matches_filter_fields
    it('지역 필터 초기값이 화면이 읽는 이름과 같다', () => {
        expect(layout.initLocal.filter).toMatchObject({
            searchField: 'member',
            searchKeyword: '',
            startDate: '',
            endDate: '',
        });
    });

    // @scenario list=wishlist, member_scope=true
    // @effects ecommerce_admin_list_member_banner_shows_only_when_scoped
    it('회원 한정 배너는 user_uuid 가 있을 때만 서고 해제는 그것만 뗀다', () => {
        const banner = findById(layout, 'member_filter_banner');

        expect(banner.if).toMatch(/query\.user_uuid/);

        const clear = findById(layout, 'member_filter_clear');
        const navigate = actionsOf(clear).find((a: any) => a.handler === 'navigate');

        expect(navigate.params.query.user_uuid).toBe('');
        expect(navigate.params.query).not.toHaveProperty('search_keyword');
        // 쪽수를 건드리는 이동이라 목록을 다시 세우는 셈이다 — 표 본문만 덮고 이력에 쌓지 않는다.
        expect(navigate.params.replace).toBe(true);
        expect(navigate.params.transition_overlay_target).toBe('wishlists_datagrid__body');
    });

    // @scenario list=wishlist
    // @effects ecommerce_admin_list_grid_columns_fixed
    it('컬럼 구성이 이 목록의 주어를 그대로 드러낸다', () => {
        expect(asArray(grid.props.columns).map((c: any) => c.field)).toEqual(COLUMNS);
        expect(grid.props.idField).toBe('id');
        expect(grid.props.selectionScope).toBe('page');
    });

    // @scenario list=wishlist, permission=manage
    // @effects ecommerce_admin_list_selectable_requires_manage
    it('선택 칸은 manage 능력이 있을 때만 켜지고 선택은 전역으로 올라간다', () => {
        expect(grid.props.selectable).toMatch(/abilities\?\.can_manage === true/);
        expect(grid.props.selectedIds).toMatch(/selectedItems_wishlists/);

        const action = actionsOf(grid).find((a: any) => a.event === 'onSelectionChange');

        expect(action.params['selectedItems_wishlists']).toMatch(/\$args\[0\]/);
    });

    // @scenario list=wishlist, permission=manage
    // @effects ecommerce_admin_list_single_delete_targets_row
    it('단건 삭제는 누른 행의 id 로 DELETE 한다', () => {
        const call = flattenActions(modals['modal_wishlists_delete']).find((a: any) => a.handler === 'apiCall');

        expect(call.params.method).toBe('DELETE');
        expect(call.target).toBe('/api/modules/sirsoft-ecommerce/admin/wishlists/{{_global.deleteTarget_wishlists?.id}}');
    });

    // @scenario list=wishlist, permission=manage
    // @effects ecommerce_admin_list_bulk_delete_sends_ids_and_clears_selection
    it('일괄 삭제는 선택 목록을 ids 로 보내고 성공 시 비운다', () => {
        const call = flattenActions(modals['modal_wishlists_bulk_delete']).find((a: any) => a.handler === 'apiCall');

        expect(call.params.method).toBe('DELETE');
        expect(call.target).toBe('/api/modules/sirsoft-ecommerce/admin/wishlists/bulk');
        expect(call.params.body.ids).toMatch(/selectedItems_wishlists/);

        const cleared = callbacksOf(call, 'onSuccess')
            .find((a: any) => a.handler === 'setState' && 'selectedItems_wishlists' in (a.params ?? {}));

        expect(cleared.params['selectedItems_wishlists']).toEqual([]);
    });

    // @scenario list=wishlist, permission=manage
    // @effects ecommerce_admin_list_bulk_failure_opens_error_modal
    it('일괄 실패는 토스트로 스쳐 보내지 않고 창으로 세운다', () => {
        const call = flattenActions(modals['modal_wishlists_bulk_delete']).find((a: any) => a.handler === 'apiCall');
        const onError = callbacksOf(call, 'onError');

        expect(onError.some((a: any) => a.handler === 'openModal' && a.target === 'modal_wishlists_error')).toBe(true);
        expect(modals['modal_wishlists_error']).toBeTruthy();
    });
});

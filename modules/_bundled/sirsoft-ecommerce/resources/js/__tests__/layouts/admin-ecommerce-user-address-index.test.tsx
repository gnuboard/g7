/**
 * @file admin-ecommerce-user-address-index.test.tsx
 * @description 관리자 회원 배송지 목록 — 레이아웃 계약 회귀 (2026-09-21 전면 재작성).
 *
 * 이 화면은 쿠폰·주문·상품 목록과 **같은 뼈대**로 다시 짰다: 셸이 데이터소스·명명 액션을 소유하고
 * 필터 조각과 표 조각을 include 하며, 확인 창은 별도 modal 파일이다. 조회 조건은 전부 URL 쿼리로
 * 나간다. 이 파일은 그 뼈대와 «이 화면만의 차이» 를 함께 고정한다.
 *
 * 이 화면만의 차이 셋:
 *  ① 회원 한정이 검색 대상/검색어와 **다른 축**이라 전용 필터가 쥔다(`user_uuids[]`, 여러 명).
 *     종전에는 상단 배너만 「특정 회원만 표시 중」 이라 말하고 필터 줄에는 「검색 대상=회원 ·
 *     검색어 비어 있음」 이 서 있어 두 칸이 한 조건인 것처럼 읽혔다(검수 지적).
 *  ② 등록·수정이 없다 — 배송지는 회원이 만드는 데이터이고 운영자는 보고 정리만 한다.
 *  ③ 검색 편집·상세검색 토글·프리셋이 없다(요구사항). 필터가 넷이라 접어 둘 이유가 없고,
 *     접는 장치가 있으면 「지금 무엇으로 좁혀져 있나」 를 두 군데서 읽어야 한다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../../layouts/admin/admin_ecommerce_user_address_index.json');
const filterSection = require('../../../layouts/admin/partials/admin_ecommerce_user_address_index/_partial_filter_section.json');
const datagrid = require('../../../layouts/admin/partials/admin_ecommerce_user_address_index/_partial_address_datagrid.json');
const deleteModal = require('../../../layouts/admin/partials/admin_ecommerce_user_address_index/_modal_delete_confirm.json');
const bulkDeleteModal = require('../../../layouts/admin/partials/admin_ecommerce_user_address_index/_modal_bulk_delete_confirm.json');

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

const dataSource = layout.data_sources.find((d: any) => d.id === 'addresses');
const searchAction = layout.named_actions.searchUserAddresses;
const resetAction = layout.named_actions.resetUserAddressFilters;
const grid = flattenNodes(datagrid).find((n: any) => n?.name === 'DataGrid');

describe('관리자 회원 배송지 목록 — 셸', () => {
    // @scenario list=user_address
    // @effects ecommerce_admin_list_datasource_passes_all_filters
    it('목록 데이터소스가 검색·회원·기간·기본여부를 주소에서 읽는다', () => {
        expect(dataSource.endpoint).toBe('/api/modules/sirsoft-ecommerce/admin/user-addresses');
        expect(dataSource.auth_required).toBe(true);
        expect(dataSource.params).toMatchObject({
            page: expect.stringMatching(/query\.page/),
            per_page: expect.stringMatching(/query\.per_page/),
            sort: expect.stringMatching(/query\.sort/),
            search_field: expect.stringMatching(/query\.search_field/),
            search_keyword: expect.stringMatching(/query\.search_keyword/),
            user_uuids: expect.stringMatching(/query\['user_uuids\[\]'\]/),
            start_date: expect.stringMatching(/query\.start_date/),
            end_date: expect.stringMatching(/query\.end_date/),
            is_default: expect.stringMatching(/query\.is_default/),
        });
        // 회원 상세에서 건너온 레거시 단건 키도 계속 받는다 — 오래된 링크가 죽지 않게.
        expect(dataSource.params.user_uuid).toMatch(/query\.user_uuid/);
    });

    // @scenario list=user_address, permission=read
    // @effects ecommerce_admin_list_requires_read_permission
    it('읽기 권한을 요구한다', () => {
        expect(layout.permissions).toContain('sirsoft-ecommerce.user-addresses.read');
    });

    // @scenario list=user_address
    // @effects ecommerce_admin_list_search_action_resets_page_and_carries_member_scope
    it('검색은 페이지를 되돌리고 회원 한정을 함께 옮긴다', () => {
        expect(searchAction.handler).toBe('navigate');
        expect(searchAction.params.path).toBe('/admin/ecommerce/user-addresses');
        expect(searchAction.params.replace).toBe(true);
        expect(searchAction.params.transition_overlay_target).toBe('pc_data_table__body');
        // 조건이 바뀌었으니 쪽수는 1로 되돌린다.
        expect(searchAction.params.query.page).toBe(1);
        // 회원 한정은 전용 필터가 쥔 값이고, 검색이 그것을 주소로 옮긴다.
        expect(searchAction.params.query['user_uuids[]']).toMatch(/_local\.filter\.memberUuids/);
        // 레거시 단건 키는 비운다 — mergeQuery 라 남겨 두면 확장 링크로 들어온 회원이
        // 새 선택과 겹쳐 걸려 교집합이 빈다.
        expect(searchAction.params.query.user_uuid).toBe('');
        // 「전체」는 조건이 아니다 — 빈 값으로 떨어뜨린다(쿠폰·주문 목록과 같은 규약).
        expect(searchAction.params.query.search_field).toMatch(/!== 'all'/);
        expect(searchAction.params.query.is_default).toMatch(/!== 'all'/);
    });

    // @scenario list=user_address
    // @effects ecommerce_admin_list_reset_clears_state_before_url
    it('초기화는 손에 든 조건·선택을 먼저 비우고 주소를 턴다', () => {
        const actions = flattenActions(resetAction);
        const setState = actions.find((a: any) => a.handler === 'setState');
        const navigate = actions.find((a: any) => a.handler === 'navigate');

        // URL 만 털면 입력값이 화면에 남는다 — setState 가 navigate 보다 앞이다.
        expect(actions.indexOf(setState)).toBeLessThan(actions.indexOf(navigate));
        expect(setState.params.filter.memberUuids).toEqual([]);
        expect(setState.params.filter.memberOptions).toEqual([]);
        expect(setState.params.selectedItems).toEqual([]);
        expect(navigate.params.mergeQuery).toBe(false);
    });

    // @scenario list=user_address
    // @effects ecommerce_admin_list_initlocal_matches_filter_fields
    it('지역 필터 초기값이 화면이 읽는 이름과 같다', () => {
        expect(layout.initLocal.filter).toMatchObject({
            searchField: 'all',
            searchKeyword: '',
            memberUuids: [],
            memberOptions: [],
            startDate: '',
            endDate: '',
            isDefault: 'all',
        });
    });

    // @scenario list=user_address, member_scope=multi
    // @effects ecommerce_admin_list_member_labels_come_from_the_list_response
    it('회원 상세에서 건너온 진입도 칩이 이름으로 선다 — 목록 응답이 이름을 싣는다', () => {
        // 코어 회원 검색은 uuid 를 한 건씩만 받는다. 한정이 세 명이면 왕복이 세 번 나므로
        // 서버가 적용된 한정을 `filters.members` 로 되돌려 준다.
        const seed = asArray(dataSource.onSuccess).find((a: any) => a.handler === 'setState');

        expect(seed.params.filter.memberOptions).toMatch(/filters\?\.members/);
        expect(seed.params.filter.memberUuids).toMatch(/filters\?\.members/);
    });

    // @scenario list=user_address
    // @effects ecommerce_admin_list_has_no_preset_or_edit_mode
    it('검색 편집·상세검색 토글·프리셋이 없다', () => {
        const text = JSON.stringify(layout) + JSON.stringify(filterSection);

        expect(text).not.toMatch(/isEditMode/);
        expect(text).not.toMatch(/showDetailFilter/);
        expect(text).not.toMatch(/visibleFilters/);
        // 「프리셋 모드」만 겨냥한다 — 기간 빠른 버튼의 `preset: '1year'` 는 다른 것이다.
        expect(text).not.toMatch(/selectedPreset|presetName|preset_id|savingPreset|modal_preset/);
        // 슬롯 등록 영역도 없다 — 필터 행을 숨겼다 꺼낼 일이 없기 때문이다.
        expect(flattenNodes(filterSection).some((n: any) => n?.name === 'SlotContainer')).toBe(false);
    });
});

describe('관리자 회원 배송지 목록 — 필터', () => {
    // @scenario list=user_address, member_scope=multi
    // @effects ecommerce_admin_list_member_filter_is_its_own_multi_select_axis
    it('회원 한정은 전용 다중선택 필터가 쥔다 — 검색 대상에서 회원을 뺐다', () => {
        const dropdown = findById(filterSection, 'member_filter_dropdown');

        expect(dropdown.name).toBe('SearchableDropdown');
        expect(dropdown.props.multiple).toBe(true);
        expect(dropdown.props.value).toMatch(/_local\.filter\.memberUuids/);

        // 후보는 코어 회원 검색에서 온다 — 회원 수가 많아 선택지를 미리 받아 둘 수 없다.
        const search = actionsOf(dropdown).find((a: any) => a.event === 'onSearch');
        const call = flattenActions(search).find((a: any) => a.handler === 'apiCall');

        expect(call.target).toBe('/api/admin/users/search');
        expect(call.debounce).toBe(300);

        // 검색 대상 select 에는 회원 갈래가 없다 — 그 축은 위 필터가 가져갔다.
        const select = findById(filterSection, 'search_field_select');

        expect(asArray(select.props.options).map((o: any) => o.value)).toEqual(['all', 'recipient', 'address']);
    });

    // @scenario list=user_address, member_scope=multi
    // @effects ecommerce_admin_list_member_filter_keeps_labels_outside_search_results
    it('고른 회원의 이름표를 따로 든다 — 검색 결과는 다음 검색어에 통째로 갈린다', () => {
        const dropdown = findById(filterSection, 'member_filter_dropdown');
        const change = actionsOf(dropdown).find((a: any) => a.event === 'onChange');
        const setState = flattenActions(change).find((a: any) => a.handler === 'setState');

        expect(setState.params['filter.memberUuids']).toMatch(/\$args\[0\]/);
        expect(setState.params['filter.memberOptions']).toMatch(/memberSearchResults/);

        // 칩은 이름표 쪽을 읽는다 — uuid 만 들고 있으면 고른 회원이 숫자로 남는다.
        const chips = findById(filterSection, 'member_filter_chips');

        expect(chips.if).toMatch(/_local\.filter\?\.memberOptions/);
    });

    // @scenario list=user_address, extra_filter=is_default
    // @effects ecommerce_admin_list_is_default_filter_reaches_server
    it('기본 배송지 갈래가 전체/기본만/기본 제외 셋이다', () => {
        const row = findById(filterSection, 'is_default_filter_row');
        const radios = flattenNodes(row).filter((n: any) => n?.props?.type === 'radio');

        expect(radios.map((r: any) => r.props.value)).toEqual(['all', '1', '0']);
    });
});

describe('관리자 회원 배송지 목록 — 표', () => {
    // @scenario list=user_address
    // @effects ecommerce_admin_list_grid_columns_fixed
    it('컬럼 구성이 이 목록의 주어를 그대로 드러낸다', () => {
        expect(asArray(grid.props.columns).map((c: any) => c.field))
            .toEqual(['checkbox', 'created_at', 'member', 'recipient', 'contact', 'address_summary']);
        expect(grid.props.idField).toBe('id');
        expect(grid.props.selectionScope).toBe('page');
    });

    // @scenario list=user_address, permission=manage
    // @effects ecommerce_admin_list_selectable_requires_manage
    it('선택 칸은 manage 능력이 있을 때만 켜진다', () => {
        expect(grid.props.selectable).toMatch(/canManage/);
        expect(layout.computed.canManage).toMatch(/abilities\?\.can_manage === true/);
    });

    // @scenario list=user_address
    // @effects ecommerce_admin_list_member_name_opens_context_menu_not_navigation
    it('회원 이름은 곧바로 떠나지 않고 두 갈래 메뉴를 연다', () => {
        // 지우려고 조건을 세워 둔 목록에서 한 칸 잘못 누르면 그 조건을 다시 세워야 했다.
        const menu = flattenNodes(datagrid).find(
            (n: any) => n?.name === 'ActionMenu' && asArray(n?.props?.items).some((i: any) => i.id === 'search_member')
        );

        expect(asArray(menu.props.items).map((i: any) => i.id)).toEqual(['view_member', 'search_member']);

        const branches = flattenActions(actionsOf(menu).find((a: any) => a.event === 'onItemClick'));

        expect(branches.some((a: any) => a.handler === 'openWindow')).toBe(true);

        const navigate = branches.find((a: any) => a.handler === 'navigate');

        expect(navigate.params.query['user_uuids[]']).toMatch(/row\.member\?\.uuid/);
    });

    // @scenario list=user_address
    // @effects ecommerce_admin_list_page_change_clears_selection
    it('쪽 이동은 선택을 먼저 비운다', () => {
        // 화면에 없는 행이 선택된 채로 일괄 삭제에 실리면 안 된다.
        const pageChange = actionsOf(grid).find((a: any) => a.event === 'onPageChange');
        const actions = flattenActions(pageChange);
        const clear = actions.find((a: any) => a.handler === 'setState');

        expect(clear.params.selectedItems).toEqual([]);
        expect(actions.indexOf(clear)).toBeLessThan(actions.findIndex((a: any) => a.handler === 'navigate'));
    });

    // @scenario list=user_address, permission=manage
    // @effects ecommerce_admin_list_single_delete_targets_row
    it('단건 삭제는 누른 행의 id 로 DELETE 한다', () => {
        const call = flattenActions(deleteModal).find((a: any) => a.handler === 'apiCall');

        expect(call.params.method).toBe('DELETE');
        expect(call.target).toBe('/api/modules/sirsoft-ecommerce/admin/user-addresses/{{_global.deleteTarget?.id}}');
    });

    // @scenario list=user_address, permission=manage
    // @effects ecommerce_admin_list_bulk_delete_sends_ids_and_clears_selection
    it('일괄 삭제는 선택 목록을 ids 로 보내고 성공 시 비운다', () => {
        const call = flattenActions(bulkDeleteModal).find((a: any) => a.handler === 'apiCall');

        expect(call.params.method).toBe('DELETE');
        expect(call.target).toBe('/api/modules/sirsoft-ecommerce/admin/user-addresses/bulk');
        expect(call.params.body.ids).toMatch(/bulkSelectedItems/);

        const cleared = flattenActions(call.onSuccess)
            .filter((a: any) => a.handler === 'setState')
            .map((a: any) => a.params);

        expect(cleared.some((p: any) => Array.isArray(p.bulkSelectedItems) && p.bulkSelectedItems.length === 0)).toBe(true);
        expect(cleared.some((p: any) => Array.isArray(p.selectedItems) && p.selectedItems.length === 0)).toBe(true);
    });

    // @scenario list=user_address, permission=manage
    // @effects ecommerce_admin_list_bulk_failure_opens_error_modal
    it('삭제 실패는 토스트로 스쳐 보내지 않고 창으로 세운다', () => {
        for (const modal of [deleteModal, bulkDeleteModal]) {
            const call = flattenActions(modal).find((a: any) => a.handler === 'apiCall');
            const onError = flattenActions(call.onError);

            expect(onError.some((a: any) => a.handler === 'openModal' && a.target === 'modal_addresses_error')).toBe(true);
        }
    });

    // @scenario list=user_address
    // @effects ecommerce_admin_list_has_no_create_or_edit_entry
    it('등록·수정 진입점이 없다 — 배송지는 회원이 만드는 데이터다', () => {
        const text = JSON.stringify(layout) + JSON.stringify(datagrid);

        expect(text).not.toMatch(/user-addresses\/create/);
        expect(text).not.toMatch(/\/edit/);
        expect(asArray(grid.props.rowActions).map((a: any) => a.id))
            .toEqual(['view_member', 'search_member', 'divider', 'delete']);
    });
});

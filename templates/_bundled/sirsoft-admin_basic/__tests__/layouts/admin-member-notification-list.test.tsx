/**
 * @file admin-member-notification-list.test.tsx
 * @description 관리자 회원 알림함 (#524 WP-F) — 발송 이력 화면과 같은 틀이지만 «다른 축»임을 고정한다.
 *
 * 이 화면이 지키는 계약:
 *  - 조회 대상은 회원이 «받은» in-app 알림이다. 발송 이력(`admin_notification_log_list`)이
 *    "무엇을 보냈나" 를 남기는 것과 달리, 여기서 지우면 그 회원의 수신함에서 사라진다.
 *  - `search_type` 4종(all/recipient/subject/body)이 **실제로 동작한다**. 발송 이력 화면의
 *    셀렉트는 Request 규칙에 없어 무시되는 장식이었다(계획서 부록 B D7) — 그 회귀를 막는다.
 *  - 식별자가 uuid 문자열이라 `idField`/일괄 삭제 body 가 문자열 id 를 다룬다.
 *  - 회원 한정(`user_uuid`)으로 들어오면 배너가 서고, 해제 버튼은 회원 조건만 뗀다.
 *
 * 레이아웃 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽어 검사한다 —
 * 손으로 만든 fixture 를 검사하면 실제 화면이 바뀌어도 초록이 유지된다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, callbacksOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../layouts/admin_member_notification_list.json');
const filterPartial = require('../../layouts/partials/admin_member_notification_list/_partial_filter.json');
const grid = require('../../layouts/partials/admin_member_notification_list/_partial_datagrid.json');
const routes = require('../../routes.json');

/** 액션 트리를 평탄화한다 — `sequence` 는 자식 액션을 `actions` 로 접는다. */
function flattenActions(node: any): any[] {
    const out: any[] = [];
    const visit = (value: any) => {
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

/** 이벤트 이름으로 컴포넌트 액션 하나를 집는다. */
function actionForEvent(node: any, event: string): any {
    return actionsOf(node).find((a: any) => a.event === event) ?? null;
}

const columns = asArray(grid.props.columns);

describe('관리자 회원 알림함 — 레이아웃 계약 (#524 WP-F)', () => {
    describe('데이터소스 · 권한', () => {
        // @scenario surface=admin_member_notifications
        // @effects list_datasource_passes_all_filters
        it('memberNotifications 가 관리자 수신함 엔드포인트를 조건 전량과 함께 호출한다', () => {
            const ds = layout.data_sources.find((d: any) => d.id === 'memberNotifications');

            expect(ds).toBeTruthy();
            expect(ds.endpoint).toBe('/api/admin/member-notifications');
            expect(ds.method).toBe('GET');
            expect(ds.auth_required).toBe(true);
            // 조건은 전부 주소에서 읽는다 — 지역 상태로 조회하면 새로고침·뒤로가기에서 조건이 사라진다.
            expect(ds.params).toMatchObject({
                page: expect.stringMatching(/query\.page/),
                per_page: expect.stringMatching(/query\.per_page/),
                search: expect.stringMatching(/query\.search/),
                search_type: expect.stringMatching(/query\.search_type/),
                read: expect.stringMatching(/query\.read/),
                user_id: expect.stringMatching(/query\.user_id/),
                user_uuid: expect.stringMatching(/query\.user_uuid/),
                created_from: expect.stringMatching(/query\.created_from/),
                created_to: expect.stringMatching(/query\.created_to/),
                sort_by: expect.stringMatching(/query\.sort_by/),
                sort_order: expect.stringMatching(/query\.sort_order/),
            });
        });

        // @scenario surface=admin_member_notifications, permission=read
        // @effects list_requires_read_permission, list_forbidden_shows_error_page
        it('읽기 권한을 요구하고 403 은 빈 표가 아니라 에러 화면으로 바꾼다', () => {
            expect(layout.permissions).toContain('core.member-notifications.read');

            const ds = layout.data_sources.find((d: any) => d.id === 'memberNotifications');

            expect(ds.errorHandling['403'].handler).toBe('showErrorPage');
        });

        // @scenario surface=admin_member_notifications, permission=read
        // @effects route_bound_to_read_permission
        it('라우트가 같은 권한으로 잠겨 있다', () => {
            const route = routes.routes.find((r: any) => r.path === '*/admin/member-notifications');

            expect(route).toBeTruthy();
            expect(route.layout).toBe('admin_member_notification_list');
            expect(route.auth_required).toBe(true);
            expect(route.meta.permission).toBe('core.member-notifications.read');
        });
    });

    describe('검색 — search_type 4종이 장식이 아니다', () => {
        // @scenario surface=admin_member_notifications, search_type=all
        // @effects search_type_select_offers_four_targets
        it('검색 대상 셀렉트가 all/recipient/subject/body 네 값을 준다', () => {
            const select = flattenNodes(filterPartial).find((n: any) => n?.props?.name === 'searchType');
            const values = asArray(select?.props?.options).map((o: any) => o.value);

            expect(values).toEqual(['all', 'recipient', 'subject', 'body']);
        });

        // @scenario surface=admin_member_notifications, search_type=subject
        // @effects search_action_maps_search_type_to_query
        it('명명 액션이 고른 값을 그대로 주소의 search_type 으로 옮긴다', () => {
            const action = layout.named_actions.searchMemberNotifications;

            expect(action.handler).toBe('navigate');
            expect(action.params.path).toBe('/admin/member-notifications');
            expect(action.params.query.search_type).toMatch(/_local\.filter\.searchType/);
            // 「전체」는 조건을 거는 것이 아니라 빼는 것이다.
            expect(action.params.query.search_type).toMatch(/undefined/);
            expect(action.params.query.read).toMatch(/undefined/);
        });

        // @scenario surface=admin_member_notifications, search_type=all
        // @effects search_resets_page_and_keeps_member_filter
        it('새 검색은 1페이지로 되돌리고 회원 한정은 건드리지 않는다', () => {
            const query = layout.named_actions.searchMemberNotifications.params.query;

            expect(query.page).toBe(1);
            // 「이 회원의 알림함 안에서 검색」이 자연스럽다 — 해제는 배너 버튼이 한다.
            expect(query).not.toHaveProperty('user_uuid');
            expect(query).not.toHaveProperty('user_id');
        });

        // @scenario surface=admin_member_notifications, search_type=all
        // @effects search_entrypoints_share_named_action
        it('검색 버튼과 Enter 가 같은 명명 액션을 부른다 — 규칙이 한 자리에 있다', () => {
            const refs = flattenActions(filterPartial).filter((a: any) => a.actionRef === 'searchMemberNotifications');

            expect(refs).toHaveLength(2);
            expect(refs.some((r: any) => r.type === 'keydown' && r.key === 'Enter')).toBe(true);
            expect(refs.some((r: any) => r.type === 'click')).toBe(true);
        });

        // @scenario surface=admin_member_notifications, search_type=all
        // @effects init_restores_filters_from_query
        it('init 이 주소의 조건을 지역 필터로 되살린다 — 새로고침에도 입력값이 남는다', () => {
            const filter = layout.init_actions[0].params._local.filter;

            expect(filter.searchType).toMatch(/query\.search_type/);
            expect(filter.search).toMatch(/query\.search/);
            expect(filter.read).toMatch(/query\.read/);
            expect(filter.createdFrom).toMatch(/query\.created_from/);
            expect(filter.createdTo).toMatch(/query\.created_to/);
            expect(layout.initLocal.filter).toEqual({
                searchType: 'all',
                search: '',
                read: 'all',
                createdFrom: '',
                createdTo: '',
            });
        });

        // @scenario surface=admin_member_notifications, read=unread
        // @effects read_state_select_offers_three_values
        it('읽음 상태는 셀렉트 하나로 all/unread/read 를 고른다', () => {
            const select = flattenNodes(filterPartial).find((n: any) => n?.props?.name === 'readState');
            const values = asArray(select?.props?.options).map((o: any) => o.value);

            expect(values).toEqual(['all', 'unread', 'read']);
        });

        // @scenario surface=admin_member_notifications
        // @effects created_range_uses_date_inputs
        it('수신 기간은 서버가 받는 Y-m-d 그대로 두 칸으로 받는다', () => {
            const names = flattenNodes(filterPartial)
                .filter((n: any) => n?.props?.type === 'date')
                .map((n: any) => n.props.name);

            expect(names).toEqual(['createdFrom', 'createdTo']);
        });

        // @scenario surface=admin_member_notifications
        // @effects reset_clears_all_conditions
        it('초기화는 병합하지 않는다 — 회원 한정까지 전부 떨어져야 한다', () => {
            const reset = flattenActions(filterPartial)
                .find((a: any) => a.handler === 'navigate' && a.params?.mergeQuery === false);

            expect(reset).toBeTruthy();
            expect(reset.params.path).toBe('/admin/member-notifications');
        });
    });

    describe('회원 한정 배너', () => {
        // @scenario surface=admin_member_notifications, member_scope=true
        // @effects member_filter_banner_shows_only_when_scoped
        it('user_id 또는 user_uuid 가 있을 때만 선다', () => {
            const banner = findById(layout, 'member_filter_banner');

            expect(banner).toBeTruthy();
            expect(banner.if).toMatch(/query\.user_id/);
            expect(banner.if).toMatch(/query\.user_uuid/);
        });

        // @scenario surface=admin_member_notifications, member_scope=true
        // @effects member_filter_clear_keeps_search_and_range
        it('해제 버튼은 회원 조건만 떼고 검색어·기간은 남긴다', () => {
            const clear = findById(layout, 'member_filter_clear');
            const navigate = actionsOf(clear).find((a: any) => a.handler === 'navigate');

            expect(navigate.params.mergeQuery).toBe(true);
            expect(navigate.params.query).toEqual({ user_id: '', user_uuid: '', page: '' });
            // 쪽수를 건드리는 이동이라 목록을 다시 세우는 셈이다 — 표 본문만 덮고 이력에 쌓지 않는다.
            expect(navigate.params.replace).toBe(true);
            expect(navigate.params.transition_overlay_target).toBe('member_notification_datagrid__body');
        });
    });

    describe('표 — uuid 식별자 · 권한 있을 때만 선택', () => {
        // @scenario surface=admin_member_notifications, permission=delete
        // @effects grid_selectable_only_with_delete_permission
        it('선택 칸은 삭제 권한이 있을 때만 켜진다', () => {
            expect(grid.name).toBe('DataGrid');
            expect(grid.props.selectable).toMatch(/abilities\?\.can_delete === true/);
            expect(grid.props.selectionScope).toBe('page');
        });

        // @scenario surface=admin_member_notifications
        // @effects grid_uses_uuid_id_field
        it('식별자는 uuid 문자열 컬럼 id 다', () => {
            expect(grid.props.idField).toBe('id');
            expect(grid.props.selectedIds).toMatch(/selectedIds_admin_member_notifications/);
        });

        // @scenario surface=admin_member_notifications
        // @effects grid_columns_fixed_five
        it('컬럼은 수신일시·회원·종류·제목·읽음 다섯이다', () => {
            expect(columns.map((c: any) => c.field)).toEqual([
                'created_at', 'member', 'type_label', 'subject', 'read_at',
            ]);
        });

        // @scenario surface=admin_member_notifications
        // @effects grid_sortable_columns_match_server
        it('정렬은 서버가 받는 두 컬럼에만 열려 있다', () => {
            const sortable = columns.filter((c: any) => c.sortable).map((c: any) => c.field);

            expect(sortable.sort()).toEqual(['created_at', 'read_at']);
        });

        // @scenario surface=admin_member_notifications, permission=delete
        // @effects grid_selection_lifts_to_global
        it('선택은 전역으로 올린다 — 일괄 버튼이 표 밖에 있다', () => {
            const action = actionForEvent(grid, 'onSelectionChange');

            expect(action.handler).toBe('setState');
            expect(action.params.target).toBe('global');
            expect(action.params.selectedIds_admin_member_notifications).toMatch(/\$args\[0\]/);
        });

        // @scenario surface=admin_member_notifications
        // @effects member_cell_handles_deleted_member
        it('회원 칸은 삭제된 회원이면 글자로만 남고 살아 있으면 상세·한정 두 갈래를 준다', () => {
            const member = JSON.stringify(columns.find((c: any) => c.field === 'member'));

            // 다형 관계에 외래키가 없어 회원이 먼저 지워질 수 있다 — 행을 감추면 정리 대상을 못 찾는다.
            expect(member).toMatch(/deleted_member/);
            expect(member).toMatch(/\/admin\/users\//);
            expect(member).toMatch(/user_uuid/);
        });

        // @scenario surface=admin_member_notifications, read=read
        // @effects read_cell_shows_read_unread_badge
        it('읽음 칸은 배지로 두 상태를 구분한다', () => {
            const read = JSON.stringify(columns.find((c: any) => c.field === 'read_at'));

            expect(read).toMatch(/badge\.read/);
            expect(read).toMatch(/badge\.unread/);
            expect(read).toMatch(/row\.is_read/);
        });

        // @scenario surface=admin_member_notifications, permission=delete
        // @effects row_delete_opens_confirm_modal
        it('행 삭제는 바로 지우지 않고 확인 창을 연다', () => {
            expect(asArray(grid.props.rowActions).map((a: any) => a.id)).toEqual(['delete']);

            const action = actionForEvent(grid, 'onRowAction');
            const serialized = JSON.stringify(action);

            expect(serialized).toMatch(/modal_data/);
            expect(serialized).toMatch(/delete_confirm_modal/);
        });

        // @scenario surface=admin_member_notifications
        // @effects page_change_keeps_conditions
        it('페이지 이동은 조건을 물려받고 표 본문만 덮는다', () => {
            const action = actionForEvent(grid, 'onPageChange');

            expect(action.handler).toBe('navigate');
            expect(action.params.mergeQuery).toBe(true);
            expect(action.params.transition_overlay_target).toBe('member_notification_datagrid__body');
        });
    });

    describe('삭제 — 단건과 일괄이 서로 다른 값을 읽는다', () => {
        // @scenario surface=admin_member_notifications, permission=delete
        // @effects bulk_button_disabled_without_selection_or_permission
        it('일괄 버튼은 선택이 없거나 권한이 없으면 잠긴다', () => {
            const disabled = findById(layout, 'bulk_delete_button').props.disabled;

            expect(disabled).toMatch(/selectedIds_admin_member_notifications/);
            expect(disabled).toMatch(/length === 0/);
            expect(disabled).toMatch(/can_delete !== true/);
        });

        // @scenario surface=admin_member_notifications, permission=delete
        // @effects single_delete_calls_delete_endpoint
        it('단건 모달은 modal_data 의 id 로 DELETE 한다', () => {
            const modal = layout.modals.find((m: any) => m.id === 'delete_confirm_modal');
            const call = flattenActions(modal).find((a: any) => a.handler === 'apiCall');

            expect(call.params.method).toBe('DELETE');
            expect(call.target).toBe('/api/admin/member-notifications/{{_global.modal_data.id}}');
            expect(callbacksOf(call, 'onSuccess').some((c: any) => c.handler === 'refetchDataSource')).toBe(true);
        });

        // @scenario surface=admin_member_notifications, permission=delete
        // @effects bulk_delete_sends_ids_and_clears_selection
        it('일괄 모달은 선택 목록을 ids 로 보내고 성공 시 선택을 비운다', () => {
            const modal = layout.modals.find((m: any) => m.id === 'bulk_delete_confirm_modal');
            const call = flattenActions(modal).find((a: any) => a.handler === 'apiCall');

            expect(call.params.method).toBe('POST');
            expect(call.target).toBe('/api/admin/member-notifications/bulk-delete');
            expect(call.params.body.ids).toMatch(/selectedIds_admin_member_notifications/);

            // 지운 id 가 남아 있으면 다음 일괄이 없는 것을 다시 보낸다.
            const cleared = callbacksOf(call, 'onSuccess')
                .find((c: any) => c.handler === 'setState' && 'selectedIds_admin_member_notifications' in (c.params ?? {}));

            expect(cleared.params.selectedIds_admin_member_notifications).toEqual([]);
        });

        // @scenario surface=admin_member_notifications, permission=read
        // @effects modals_map_403_to_permission_message
        it('두 모달 모두 403 을 권한 메시지로 되돌린다', () => {
            for (const id of ['delete_confirm_modal', 'bulk_delete_confirm_modal']) {
                const modal = layout.modals.find((m: any) => m.id === id);
                const call = flattenActions(modal).find((a: any) => a.handler === 'apiCall');

                expect(JSON.stringify(call.errorHandling['403'])).toMatch(/auth\.permission_denied/);
            }
        });
    });
});

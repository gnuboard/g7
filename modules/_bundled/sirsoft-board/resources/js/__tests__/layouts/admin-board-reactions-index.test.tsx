/**
 * 전 회원 반응 관리 화면 (#525 WP-C) — 레이아웃 JSON 계약 회귀.
 *
 * 이 화면이 지키는 계약:
 *  - 조건은 전부 주소(`query.*`)에서 읽는다. 지역 상태로만 조회하면 새로고침·뒤로가기에
 *    조건이 사라지고, 운영자는 좁혀 놓은 목록을 잃는다.
 *  - 유형 선택지는 **목록 응답 메타**에서 온다 — 반응 조회 권한만 가진 매니저는 환경설정을
 *    볼 수 없어 유형 API 를 부를 수 없다.
 *  - 검색 조립은 명명 액션 한 자리에 모인다 — 진입점이 셋(검색 버튼·PC 엔터·모바일 엔터)이라
 *    각자 조립하면 화면마다 다른 질의가 나간다.
 *  - 회원 한정(`user_uuid`)은 검색이 건드리지 않는다. 해제는 배너의 전용 버튼이 한다.
 *  - 선택은 목록의 «지금 보이는 쪽» 에만 걸린다 — 페이지·정렬이 바뀌면 먼저 비운다.
 *  - 일괄 삭제·체크박스는 `reactions.manage` 능력이 있을 때만 나온다.
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../../layouts/admin/admin_board_reactions_index.json');
// 검색·필터 영역은 형제 관리자 목록들처럼 별도 조각으로 뺐다(저장소 관례). 계약은 그대로이므로
// 두 산출물을 합쳐서 본다 — 조각으로 옮겼다는 이유만으로 검사가 눈을 감으면 안 된다.
const filterPartial = require('../../../layouts/admin/partials/admin_board_reactions_index/_partial_filter.json');
const routes = require('../../../routes/admin.json');

const layoutJson = JSON.stringify(layout) + JSON.stringify(filterPartial);
const grid = findById(layout, 'reactions_data_grid');
const columns = asArray(grid?.props?.columns);
const modals: Record<string, any> = Object.fromEntries(asArray(layout.modals).map((m: any) => [m.id, m]));

/** 액션 트리를 평탄화한다 — `sequence`·`switch` 는 자식 액션을 접어 둔다. */
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

/** 이벤트 이름으로 컴포넌트 액션 하나를 집는다. */
function actionForEvent(node: any, event: string): any {
    return actionsOf(node).find((a: any) => a.event === event) ?? null;
}

describe('관리자 반응 관리 — 데이터소스·권한·라우트', () => {
    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_datasource_reads_every_filter_from_query
    it('reactions 데이터소스가 조건 전부를 주소에서 읽는다', () => {
        const ds = layout.data_sources.find((d: any) => d.id === 'reactions');

        expect(ds.endpoint).toBe('/api/modules/sirsoft-board/admin/reactions');
        expect(ds.auth_required).toBe(true);
        expect(ds.params).toMatchObject({
            page: expect.stringMatching(/query\.page/),
            per_page: expect.stringMatching(/query\.per_page/),
            sort_order: expect.stringMatching(/query\.sort_order/),
            board_id: expect.stringMatching(/query\.board_id/),
            user_id: expect.stringMatching(/query\.user_id/),
            user_uuid: expect.stringMatching(/query\.user_uuid/),
            reaction_type_id: expect.stringMatching(/query\.reaction_type_id/),
            target_type: expect.stringMatching(/query\.target_type/),
            reacted_at_from: expect.stringMatching(/query\.reacted_at_from/),
            reacted_at_to: expect.stringMatching(/query\.reacted_at_to/),
        });
        // 검색은 코어 filters[0] 규약을 탄다 — 서버가 그 형태만 받는다.
        expect(ds.params['filters[0][field]']).toMatch(/filters\[0\]\[field\]/);
        expect(ds.params['filters[0][value]']).toMatch(/filters\[0\]\[value\]/);
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_datasource_reads_every_filter_from_query
    it('권한이 없으면 목록 대신 오류 화면을 세운다', () => {
        const ds = layout.data_sources.find((d: any) => d.id === 'reactions');

        expect(JSON.stringify(ds.error_handling ?? ds.errorHandling ?? {})).toContain('403');
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_route_declares_reactions_view_permission
    it('레이아웃과 라우트가 같은 모듈 권한을 요구한다', () => {
        expect(JSON.stringify(layout.permissions)).toContain('sirsoft-board.reactions.view');

        const route = asArray(routes.routes).find((r: any) => r.layout === 'admin_board_reactions_index');

        expect(route).toBeTruthy();
        expect(route.path).toContain('/admin/boards/reactions');
        expect(route.auth_required).toBe(true);
        expect(route.meta.permission).toBe('sirsoft-board.reactions.view');
    });

    // @scenario case=response_carries_board_options_meta
    // @effects admin_screen_board_select_iterates_response_meta
    it('게시판 셀렉트도 게시판 목록 API 가 아니라 목록 응답 메타를 순회한다', () => {
        // 게시판 목록 API(admin/boards)는 게시판 관리 권한이 있어야 열린다 — 반응 조회만 가진
        // 매니저에게는 403 이 나고 셀렉트가 빈다(K1 실측 2026-10-06). 유형 옵션과 같은 자리로 받는다.
        expect(layout.data_sources.some((d: any) => /admin\/boards\?/.test(d.endpoint ?? ''))).toBe(false);
        expect(layout.data_sources.some((d: any) => d.id === 'reaction_boards')).toBe(false);
        expect(/reactions\?\.data\?\.boards/.test(layoutJson)).toBe(true);
    });
});

describe('관리자 반응 관리 — 필터와 검색', () => {
    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_type_select_iterates_response_meta
    it('유형 셀렉트가 별도 API 가 아니라 목록 응답 메타를 순회한다', () => {
        // reactions.view 만 가진 매니저는 settings.read 가 없어 유형 API 를 부를 수 없다.
        expect(/reactions\?\.data\?\.reaction_types/.test(layoutJson)).toBe(true);
        // 유형 전용 데이터소스를 새로 만들지 않았다.
        expect(layout.data_sources.some((d: any) => /reaction-types/.test(d.endpoint ?? ''))).toBe(false);
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_type_select_iterates_response_meta
    it('비활성 유형도 선택지에 남되 그 사실을 라벨 뒤에 붙인다', () => {
        // 지금은 쓰지 않는 유형으로 쌓인 과거 반응을 찾는 통로다.
        expect(/type_inactive_suffix/.test(layoutJson)).toBe(true);
        expect(/t\.is_active === false/.test(layoutJson)).toBe(true);
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_datasource_reads_every_filter_from_query
    it('대상 셀렉트가 전체·게시글·댓글 세 갈래다', () => {
        expect(/admin-reaction-target-filter/.test(layoutJson)).toBe(true);
        expect(/filters\.target_post/.test(layoutJson)).toBe(true);
        expect(/filters\.target_comment/.test(layoutJson)).toBe(true);
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_named_action_carries_type_and_target
    it('검색 조립은 명명 액션 한 자리에 모이고 유형·대상까지 싣는다', () => {
        const action = layout.named_actions?.searchBoardReactions;

        expect(action).toBeTruthy();

        const json = JSON.stringify(action);

        expect(json).toContain('reaction_type_id');
        expect(json).toContain('target_type');
        expect(json).toContain('filters[0][field]');
        // 새 검색은 1쪽으로 되돌린다 — 3쪽을 보던 중 검색하면 빈 쪽이 뜬다.
        expect(json).toContain('"page"');
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_named_action_carries_type_and_target
    it('검색 진입점이 모두 그 명명 액션을 부른다 — 각자 조립하지 않는다', () => {
        // 검색줄은 형제 화면들처럼 `responsive` 한 벌로 좁은 화면까지 덮으므로 진입점은
        // 검색 버튼과 엔터 둘이다(종전에는 좁은 화면용 줄을 따로 둬 셋이었다).
        const refs = flattenActions(filterPartial).filter((a: any) => a.actionRef === 'searchBoardReactions');

        expect(refs.length).toBeGreaterThanOrEqual(2);
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_named_action_carries_type_and_target
    it('검색 대상은 여섯 갈래다', () => {
        for (const field of ['all', 'target_title', 'target_content', 'board_name', 'member', 'author_name']) {
            expect(layoutJson).toContain(`search_fields.${field}`);
        }
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_named_action_carries_type_and_target
    it('회원 한정 배너는 회원 조건이 있을 때만 서고, 해제가 검색어·기간을 남긴다', () => {
        const banner = flattenNodes(layout).find((n: any) => n.dataRef === 'member_filter_banner' || n.id === 'member_filter_banner');

        expect(banner).toBeTruthy();
        expect(JSON.stringify(banner!.if ?? '')).toMatch(/user_id|user_uuid/);

        // 배너가 말하는 것은 회원 한정뿐이므로 그것만 되돌린다.
        const clear = flattenNodes(layout).find((n: any) => n.dataRef === 'member_filter_clear' || n.id === 'member_filter_clear');
        const json = JSON.stringify(clear);

        expect(json).toContain('user_uuid');
        expect(json).not.toContain('filters[0][value]');
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_named_action_carries_type_and_target
    it('초기화는 유형·대상 필터까지 함께 비운다', () => {
        const reset = flattenNodes(filterPartial).find((n: any) => n.dataRef === 'reset_button' || n.id === 'reset_button');
        const json = JSON.stringify(reset);

        expect(json).toContain('reactionTypeFilter');
        expect(json).toContain('reactionTargetFilter');
        expect(json).toContain('selectedIds');
    });
});

describe('관리자 반응 관리 — 목록 표', () => {
    // @scenario case=screen_gates_bulk_on_manage_ability
    // @effects admin_screen_selectable_follows_can_manage
    it('체크박스와 일괄 삭제는 manage 능력이 있을 때만 나온다', () => {
        // view 만 가진 매니저의 화면에는 고를 수단 자체가 없어야 한다.
        expect(String(grid?.props?.selectable)).toContain('abilities?.can_manage');

        const bulk = flattenNodes(layout).find((n: any) => n.props?.['data-testid'] === 'admin-reaction-bulk-delete');

        expect(bulk).toBeTruthy();
        expect(String(bulk!.props?.disabled)).toContain('can_manage');
    });

    // @scenario case=screen_gates_bulk_on_manage_ability
    // @effects admin_screen_selectable_follows_can_manage
    it('선택 건수 문구는 표가 아니라 상단 바가 낸다 — 두 곳이 세면 숫자가 어긋난다', () => {
        expect(grid?.props?.selectedCountText).toBe('');
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_datasource_reads_every_filter_from_query
    it('컬럼 여섯 — 반응일·회원·대상·게시판·유형·상태', () => {
        expect(columns).toHaveLength(6);
        expect(columns.map((c: any) => c.field)).toEqual([
            'created_at',
            'member',
            'target_title',
            'board',
            'reaction_type',
            'target_status',
        ]);
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_datasource_reads_every_filter_from_query
    it('대상 칸이 게시글·댓글을 배지로 가르고 댓글은 발췌를 함께 낸다', () => {
        const target = columns.find((c: any) => c.field === 'target_title');
        const json = JSON.stringify(target);

        expect(json).toContain("row.target_type === 'comment'");
        expect(json).toContain('target_excerpt');
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_datasource_reads_every_filter_from_query
    it('유형 칸이 이미지 아이콘을 먼저 쓰고 비활성 유형을 배지로 표시한다', () => {
        const type = columns.find((c: any) => c.field === 'reaction_type');
        const json = JSON.stringify(type);

        expect(json).toContain('reaction_type?.icon_url');
        expect(json).toContain('badges.type_inactive');
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_datasource_reads_every_filter_from_query
    it('사라진 대상·게시판·꺼진 반응을 각각의 배지로 구분한다', () => {
        // 이 화면은 삭제된 대상도 보여준다 — 정리할 대상을 찾는 자리이기 때문.
        for (const badge of ['deleted_target', 'board_deleted', 'reaction_off', 'withdrawn']) {
            expect(layoutJson).toContain(`badges.${badge}`);
        }
    });

    // @scenario case=screen_gates_bulk_on_manage_ability
    // @effects admin_screen_sort_and_page_clear_selection
    it('정렬·페이지 이동은 선택을 먼저 비운다', () => {
        for (const event of ['onSortChange', 'onPageChange']) {
            const action = actionForEvent(grid, event);
            const json = JSON.stringify(action);

            expect(json, event).toContain('selectedIds');
            // 화면에 없는 건이 선택된 채로 일괄 삭제에 실리면 안 된다.
            expect(json, event).toMatch(/"selectedIds":\s*\[\]/);
        }
    });

    // @scenario case=screen_binds_all_filters_from_query
    // @effects admin_screen_row_actions_split_three_ways
    it('행 메뉴는 원글·회원·삭제 세 갈래이고 삭제는 능력으로 잠긴다', () => {
        const rowActions = asArray(grid?.props?.rowActions);

        expect(rowActions.map((a: any) => a.id)).toEqual(['go_to_post', 'go_to_member', 'delete']);
        expect(rowActions.find((a: any) => a.id === 'delete')?.disabledField).toBe('abilities.can_delete');

        const handler = actionForEvent(grid, 'onRowAction');

        expect(JSON.stringify(handler)).toContain('go_to_member');
    });

    // @scenario case=row_go_to_post_disabled_when_board_deleted
    // @effects admin_screen_go_to_post_disabled_without_board
    it('게시판이 지워진 행에서는 「게시글 보기」가 잠긴다', () => {
        // 게시판이 하드 삭제되면 행의 board 가 null 이라 경로가 /admin/board//post/N 이 되어 404 로 간다.
        // 제목 칸 링크는 이미 `!row.board` 면 감추므로 행 메뉴도 같은 근거로 잠근다.
        const rowActions = asArray(grid?.props?.rowActions);

        expect(rowActions.find((a: any) => a.id === 'go_to_post')?.disabledField).toBe('board.slug');
    });
});

describe('관리자 반응 관리 — 삭제 모달', () => {
    // @scenario case=screen_gates_bulk_on_manage_ability
    // @effects admin_screen_modals_call_single_and_bulk_delete
    it('단건 삭제가 그 행 하나만 DELETE 한다', () => {
        const modal = modals['admin_reaction_delete_modal'];

        expect(modal).toBeTruthy();

        const json = JSON.stringify(modal);

        expect(json).toContain('/api/modules/sirsoft-board/admin/reactions/');
        expect(json).toContain('deleteTarget?.id');
        expect(json).toContain('"DELETE"');
        // 지운 행이 선택 목록에 남으면 다음 일괄 삭제가 없는 ID 를 싣는다.
        expect(json).toContain('selectedIds');
    });

    // @scenario case=screen_gates_bulk_on_manage_ability
    // @effects admin_screen_modals_call_single_and_bulk_delete
    it('일괄 삭제가 본문에 ID 목록을 싣고 성공하면 선택을 비운다', () => {
        const modal = modals['admin_reaction_bulk_delete_modal'];

        expect(modal).toBeTruthy();

        const json = JSON.stringify(modal);

        expect(json).toContain('/api/modules/sirsoft-board/admin/reactions/bulk');
        expect(json).toContain('"ids"');
        expect(json).toMatch(/"selectedIds":\s*\[\]/);
    });

    // @scenario case=screen_gates_bulk_on_manage_ability
    // @effects admin_screen_modals_call_single_and_bulk_delete
    it('실패는 토스트가 아니라 오류 창으로 띄운다 — 사유를 스쳐 보내지 않는다', () => {
        for (const id of ['admin_reaction_delete_modal', 'admin_reaction_bulk_delete_modal']) {
            expect(JSON.stringify(modals[id]), id).toContain('error_modal');
        }

        expect(modals['error_modal']).toBeTruthy();
    });
});

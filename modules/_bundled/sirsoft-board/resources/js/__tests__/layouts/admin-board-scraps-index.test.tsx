/**
 * @file admin-board-scraps-index.test.tsx
 * @description 전 회원 스크랩 관리 화면 (#524 WP-A) — 레이아웃 JSON 계약 회귀.
 *
 * 이 화면이 지키는 계약:
 *  - 조건은 전부 주소(`query.*`)에서 읽는다. 지역 상태로만 조회하면 새로고침·뒤로가기에
 *    조건이 사라지고, 운영자는 좁혀 놓은 목록을 잃는다.
 *  - 검색 조립은 명명 액션 한 자리에 모인다 — 진입점이 셋(검색 버튼·PC 엔터·모바일 엔터)이라
 *    각자 조립하면 화면마다 다른 질의가 나간다.
 *  - 회원 한정(`user_uuid`)은 검색이 건드리지 않는다. 해제는 배너의 전용 버튼이 한다.
 *  - 선택은 목록의 «지금 보이는 쪽» 에만 걸린다 — 페이지·정렬이 바뀌면 먼저 비운다.
 *    비우지 않으면 화면에 없는 건이 일괄 삭제에 실린다.
 *  - 일괄 삭제는 `admin.manage` 능력이 있을 때만 눌린다.
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다 — 손으로 만든 fixture 를 검사하면
 * 실제 화면이 바뀌어도 초록이 유지된다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, callbacksOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../../layouts/admin/admin_board_scraps_index.json');
// 검색·필터 영역은 형제 관리자 목록들처럼 별도 조각으로 뺐다(저장소 관례). 계약은 그대로이므로
// 두 산출물을 합쳐서 본다 — 조각으로 옮겼다는 이유만으로 검사가 눈을 감으면 안 된다.
const filterPartial = require('../../../layouts/admin/partials/admin_board_scraps_index/_partial_filter.json');
const routes = require('../../../routes/admin.json');

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

const grid = findById(layout, 'scraps_data_grid');
const columns = asArray(grid?.props?.columns);
const modals: Record<string, any> = Object.fromEntries(asArray(layout.modals).map((m: any) => [m.id, m]));

describe('관리자 스크랩 관리 — 레이아웃 계약 (#524 WP-A)', () => {
    describe('데이터소스 · 권한 · 라우트', () => {
        // @scenario surface=admin_list
        // @effects admin_list_datasource_passes_all_filters
        it('scraps 데이터소스가 검색·회원·게시판·기간 조건을 전부 주소에서 읽는다', () => {
            const ds = layout.data_sources.find((d: any) => d.id === 'scraps');

            expect(ds.endpoint).toBe('/api/modules/sirsoft-board/admin/scraps');
            expect(ds.auth_required).toBe(true);
            expect(ds.params).toMatchObject({
                page: expect.stringMatching(/query\.page/),
                per_page: expect.stringMatching(/query\.per_page/),
                sort_order: expect.stringMatching(/query\.sort_order/),
                board_id: expect.stringMatching(/query\.board_id/),
                user_id: expect.stringMatching(/query\.user_id/),
                user_uuid: expect.stringMatching(/query\.user_uuid/),
                scrapped_at_from: expect.stringMatching(/query\.scrapped_at_from/),
                scrapped_at_to: expect.stringMatching(/query\.scrapped_at_to/),
            });
            // 검색은 코어 filters[0] 규약을 탄다 — 서버가 그 형태만 받는다.
            expect(ds.params['filters[0][field]']).toMatch(/filters\[0\]\[field\]/);
            expect(ds.params['filters[0][value]']).toMatch(/filters\[0\]\[value\]/);
        });

        // @scenario surface=admin_list, permission=view
        // @effects admin_list_board_select_source_is_response_meta
        it('게시판 셀렉트는 게시판 목록 API 가 아니라 목록 응답 메타에서 선택지를 받는다', () => {
            // 게시판 목록 API(admin/boards)는 게시판 관리 권한이 있어야 열린다 — 스크랩 조회만 가진
            // 매니저에게는 403 이 나고 셀렉트가 빈다(K2 실측 2026-10-06, 반응 관리 K1-D10 과 같은 결함).
            expect(layout.data_sources.some((d: any) => /admin\/boards\?/.test(d.endpoint ?? ''))).toBe(false);
            expect(layout.data_sources.some((d: any) => d.id === 'scrap_boards')).toBe(false);
        });

        // @scenario surface=admin_list
        // @effects admin_list_board_select_iterates_response_meta
        it('게시판 셀렉트 전량이 목록 응답 메타를 실제로 순회한다 — 정적 목록으로 구워지면 빈 칸만 남는다', () => {
            // 모집단을 레이아웃에서 파생한다(넓은 화면 판 + 좁은 화면 판). 한쪽만 고치면
            // 다른 쪽이 조용히 빈 셀렉트로 남는다.
            // <options> 로 감싸면 컴파일러가 정적 props.options 로 평탄화하면서 data-each 를
            // 버려, 화면에는 풀리지 않은 «빈 항목» 하나만 뜬다(2026-09-15 브라우저 실측).
            const boardSelects = flattenNodes(filterPartial).filter(
                (n: any) =>
                    asArray(n?.children).some(
                        (c: any) => c?.text === '$t:sirsoft-board.admin.scraps.filters.board_all'
                    ) ||
                    asArray(n?.props?.options).some(
                        (o: any) => o?.label === '$t:sirsoft-board.admin.scraps.filters.board_all'
                    )
            );

            // 넓은 화면·좁은 화면을 `responsive` 한 벌로 덮으므로 셀렉트는 하나다(종전에는 두 벌이었다).
            // 개수가 몇이든 **찾은 전량**이 데이터소스를 순회해야 한다는 것이 이 검사의 명제다.
            expect(boardSelects.length).toBeGreaterThanOrEqual(1);

            for (const select of boardSelects) {
                expect(asArray(select?.props?.options)).toHaveLength(0);

                const iterated = asArray(select?.children).find((c: any) => c?.iteration);

                expect(iterated?.iteration?.source).toContain('scraps?.data?.boards');
                expect(iterated?.iteration?.item_var).toBeTruthy();
                expect(iterated?.props?.value).toContain(iterated.iteration.item_var);
            }
        });

        // @scenario surface=admin_list, permission=view
        // @effects admin_list_requires_view_permission
        it('보기 권한을 요구하고 403 은 빈 표가 아니라 에러 화면으로 바꾼다', () => {
            expect(layout.permissions).toContain('sirsoft-board.scraps.view');

            const ds = layout.data_sources.find((d: any) => d.id === 'scraps');

            expect(ds.errorHandling['403'].handler).toBe('showErrorPage');
        });

        // @scenario surface=admin_list, permission=view
        // @effects admin_list_route_bound_to_view_permission
        it('라우트가 같은 권한으로 잠겨 있다', () => {
            const route = asArray(routes.routes).find((r: any) => String(r.path).endsWith('/admin/boards/scraps'));

            expect(route).toBeTruthy();
            expect(route.layout).toBe('admin_board_scraps_index');
            expect(route.meta.permission).toBe('sirsoft-board.scraps.view');
        });
    });

    describe('검색 — 여섯 갈래가 한 자리에서 조립된다', () => {
        // @scenario surface=admin_list, search_field=all
        // @effects admin_list_search_field_offers_six_targets
        it('검색 대상 셀렉트가 서버 화이트리스트와 같은 여섯 값을 준다', () => {
            const selects = flattenNodes(filterPartial).filter((n: any) => asArray(n?.props?.options).some((o: any) => o.value === 'post_title'));

            expect(selects.length).toBeGreaterThanOrEqual(1);

            for (const select of selects) {
                expect(asArray(select.props.options).map((o: any) => o.value)).toEqual([
                    'all', 'post_title', 'post_content', 'board_name', 'member', 'author_name',
                ]);
            }
        });

        // @scenario surface=admin_list, search_field=post_title
        // @effects admin_list_search_action_builds_filters_query
        it('명명 액션이 고른 값을 filters[0] 규약으로 옮기고 페이지를 되돌린다', () => {
            const action = layout.named_actions.searchBoardScraps;

            expect(action.handler).toBe('navigate');
            expect(action.params.path).toBe('/admin/boards/scraps');
            expect(action.params.query['filters[0][field]']).toMatch(/_local\.searchField/);
            expect(action.params.query['filters[0][value]']).toMatch(/_local\.searchQuery/);
            expect(action.params.query['filters[0][operator]']).toBe('like');
            expect(action.params.query.page).toBe('');
        });

        // @scenario surface=admin_list, member_scope=true
        // @effects admin_list_search_keeps_member_scope
        it('검색은 회원 한정을 건드리지 않는다 — 「이 회원 안에서 검색」이 자연스럽다', () => {
            const query = layout.named_actions.searchBoardScraps.params.query;

            expect(query).not.toHaveProperty('user_uuid');
            expect(query).not.toHaveProperty('user_id');
        });

        // @scenario surface=admin_list, search_field=all
        // @effects admin_list_search_entrypoints_share_named_action
        it('검색 진입점(검색 버튼·엔터)이 같은 명명 액션을 부른다', () => {
            // 검색줄은 형제 화면들처럼 `responsive` 한 벌로 좁은 화면까지 덮으므로 진입점은 둘이다
            // (종전에는 좁은 화면용 줄을 따로 둬 셋이었다).
            const refs = flattenActions(filterPartial).filter((a: any) => a.actionRef === 'searchBoardScraps');

            expect(refs.length).toBeGreaterThanOrEqual(2);
            expect(refs.filter((r: any) => r.key === 'Enter').length).toBeGreaterThanOrEqual(1);
            expect(refs.some((r: any) => r.type === 'click')).toBe(true);
        });

        // @scenario surface=admin_list, search_field=all
        // @effects admin_list_init_restores_search_from_query
        it('init 이 주소의 검색 조건을 지역 상태로 되살린다', () => {
            const local = layout.init_actions[0].params._local;

            expect(local.searchField).toMatch(/filters\[0\]\[field\]/);
            expect(local.searchQuery).toMatch(/filters\[0\]\[value\]/);
            expect(layout.initLocal).toEqual({ searchField: 'all', searchQuery: '' });
        });
    });

    describe('회원 한정 배너', () => {
        // @scenario surface=admin_list, member_scope=true
        // @effects admin_list_member_banner_shows_only_when_scoped
        it('user_id 또는 user_uuid 가 있을 때만 선다', () => {
            const banner = findById(layout, 'member_filter_banner');

            expect(banner.if).toMatch(/query\.user_id/);
            expect(banner.if).toMatch(/query\.user_uuid/);
        });

        // @scenario surface=admin_list, member_scope=true
        // @effects admin_list_member_banner_clear_keeps_other_filters
        it('해제 버튼은 회원 조건만 떼고 검색어·기간은 남긴다', () => {
            const clear = findById(layout, 'member_filter_clear');
            const navigate = actionsOf(clear).find((a: any) => a.handler === 'navigate');

            expect(navigate.params.mergeQuery).toBe(true);
            expect(navigate.params.query).toEqual({ user_id: '', user_uuid: '', page: '' });
            // 쪽수를 건드리는 이동이라 쪽 이동·정렬과 같은 규칙을 탄다.
            expect(navigate.params.replace).toBe(true);
            expect(navigate.params.transition_overlay_target).toBe('scraps_data_grid__body');
        });
    });

    describe('표 — 선택은 보이는 쪽에만 걸린다', () => {
        // @scenario surface=admin_list, permission=manage
        // @effects admin_list_selectable_requires_manage
        it('체크 칸은 manage 능력이 있을 때만 켜진다', () => {
            // 권한이 없는 운영자에게 체크 칸을 보여 주면, 고른 뒤 영영 잠긴 버튼을 누르게 된다
            // (게시글 목록·이커머스 목록과 같은 규칙).
            expect(grid.props.selectable).toMatch(/abilities\?\.can_manage === true/);
        });

        // @scenario surface=admin_list
        // @effects admin_list_grid_selection_scoped_to_page
        it('선택 범위가 현재 쪽으로 제한되고 식별자는 id 다', () => {
            expect(grid.props.selectionScope).toBe('page');
            expect(grid.props.idField).toBe('id');
            expect(grid.props.selectedIds).toMatch(/_global\.selectedIds/);
        });

        // @scenario surface=admin_list
        // @effects admin_list_grid_columns_fixed_six
        it('컬럼은 스크랩일·회원·게시판·제목·작성자·상태 여섯이다', () => {
            expect(columns.map((c: any) => c.field)).toEqual([
                'created_at', 'member', 'board', 'post_title', 'author_name', 'post_status',
            ]);
        });

        // @scenario surface=admin_list
        // @effects admin_list_selection_lifts_to_global
        it('선택은 전역으로 올린다 — 일괄 버튼이 표 밖에 있다', () => {
            const action = actionForEvent(grid, 'onSelectionChange');

            expect(action.params.target).toBe('global');
            expect(action.params.selectedIds).toMatch(/\$args\[0\]/);
        });

        // @scenario surface=admin_list
        // @effects admin_list_page_change_clears_selection
        it('페이지를 넘기면 선택을 먼저 비운다 — 화면에 없는 건이 일괄에 실리면 안 된다', () => {
            const action = actionForEvent(grid, 'onPageChange');
            const steps = callbacksOf(action, 'actions');

            expect(steps[0].handler).toBe('setState');
            expect(steps[0].params.selectedIds).toEqual([]);
            expect(steps[1].handler).toBe('navigate');
            expect(steps[1].params.transition_overlay_target).toBe('scraps_data_grid__body');
        });

        // @scenario surface=admin_list
        // @effects admin_list_sort_change_clears_selection
        it('정렬을 바꿔도 선택을 비운다 — 같은 체크가 다른 행을 가리키게 된다', () => {
            const action = actionForEvent(grid, 'onSortChange');
            const steps = callbacksOf(action, 'actions');

            expect(steps[0].params.selectedIds).toEqual([]);
            expect(steps[1].params.query.page).toBe(1);
        });

        // @scenario surface=admin_list
        // @effects admin_list_row_actions_three_branches
        it('행 메뉴는 원글·회원 상세·단건 삭제 세 갈래다', () => {
            expect(asArray(grid.props.rowActions).map((a: any) => a.id)).toEqual([
                'go_to_post', 'go_to_member', 'delete',
            ]);

            const cases = actionForEvent(grid, 'onRowAction').cases;

            expect(cases.go_to_post.params.path).toMatch(/\/admin\/board\//);
            expect(cases.go_to_member.params.path).toMatch(/\/admin\/users\//);
            expect(JSON.stringify(cases.delete)).toMatch(/delete_modal/);
        });

        // @scenario surface=admin_list, board_state=deleted
        // @effects admin_list_go_to_post_disabled_without_board
        it('게시판이 지워진 행에서는 「게시글 보기」가 잠긴다', () => {
            // 게시판이 하드 삭제되면 행의 board 가 null 이라 경로가 /admin/board//post/N 이 되어 404 로 간다
            // (K2 실측 2026-10-06). 제목 칸 링크는 이미 `!row.board` 면 감추므로 행 메뉴도 같은 근거로 잠근다.
            const rowActions = asArray(grid?.props?.rowActions);

            expect(rowActions.find((a: any) => a.id === 'go_to_post')?.disabledField).toBe('board.slug');
            expect(rowActions.find((a: any) => a.id === 'delete')?.disabledField).toBe('abilities.can_delete');
        });

        // @scenario surface=admin_list, board_state=deleted
        // @effects admin_list_marks_deleted_board_and_post
        it('게시판이 지워진 행과 삭제된 원글을 배지로 알린다', () => {
            const serialized = JSON.stringify(columns);

            expect(serialized).toMatch(/board_deleted|!row\.board/);
            expect(serialized).toMatch(/is_deleted/);
        });
    });

    describe('삭제 — 권한이 없으면 버튼이 잠긴다', () => {
        // @scenario surface=admin_list, permission=manage
        // @effects admin_list_bulk_button_requires_manage_and_selection
        it('일괄 삭제 버튼은 manage 능력과 선택이 둘 다 있어야 풀린다', () => {
            const button = flattenNodes(layout).find((n: any) => n?.props?.['data-testid'] === 'admin-scrap-bulk-delete');

            expect(button.props.disabled).toMatch(/abilities\?\.can_manage !== true/);
            expect(button.props.disabled).toMatch(/selectedIds/);
        });

        // @scenario surface=admin_list, permission=manage
        // @effects admin_list_single_delete_removes_id_from_selection
        it('단건 삭제는 지운 id 를 선택 목록에서도 뺀다', () => {
            const call = flattenActions(modals.delete_modal).find((a: any) => a.handler === 'apiCall');

            expect(call.params.method).toBe('DELETE');
            expect(call.target).toMatch(/\/admin\/scraps\/\{\{_global\.deleteTarget\?\.id\}\}/);
            // 남아 있으면 다음 일괄 삭제가 없는 ID 를 싣는다.
            expect(JSON.stringify(callbacksOf(call, 'onSuccess'))).toMatch(/filter\(v => v !== _global\.deleteTarget\?\.id\)/);
        });

        // @scenario surface=admin_list, permission=manage
        // @effects admin_list_bulk_delete_sends_ids_and_clears_selection
        it('일괄 삭제는 선택 목록을 ids 로 보내고 성공 시 비운다', () => {
            const call = flattenActions(modals.bulk_delete_modal).find((a: any) => a.handler === 'apiCall');

            expect(call.params.method).toBe('DELETE');
            expect(call.target).toBe('/api/modules/sirsoft-board/admin/scraps/bulk');
            expect(call.params.body.ids).toMatch(/_global\.selectedIds/);

            const cleared = callbacksOf(call, 'onSuccess')
                .find((c: any) => c.handler === 'setState' && 'selectedIds' in (c.params ?? {}));

            expect(cleared.params.selectedIds).toEqual([]);
        });

        // @scenario surface=admin_list, permission=manage
        // @effects admin_list_bulk_failure_opens_error_modal
        it('일괄 실패는 토스트로 스쳐 보내지 않고 창으로 세운다', () => {
            const call = flattenActions(modals.bulk_delete_modal).find((a: any) => a.handler === 'apiCall');
            const onError = callbacksOf(call, 'onError');

            expect(onError.some((a: any) => a.handler === 'openModal' && a.target === 'error_modal')).toBe(true);
            expect(modals.error_modal).toBeTruthy();
        });
    });
});

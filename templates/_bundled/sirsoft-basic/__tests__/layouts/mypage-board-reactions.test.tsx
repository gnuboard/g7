/**
 * @file mypage-board-reactions.test.tsx
 * @description 마이페이지 「내 반응」 서브탭 레이아웃 회귀 (이슈 #525 WP-B)
 *
 * 이 화면이 지키는 계약:
 *  - 조건은 전역 상태에 있고 데이터소스가 그것을 전부 읽는다 — 하나라도 빠지면 그 필터는
 *    화면에서만 바뀌고 실제 조회는 그대로다.
 *  - 선택은 «지금 보이는 쪽» 을 가리킨다. 검색·초기화·쪽 이동은 먼저 선택을 비운다.
 *  - 행은 세 갈래다 — 살아 있음 / 게시판이 반응을 껐거나 사라짐 / 대상이 지워짐.
 *    어느 갈래든 **해제는 항상 된다**: 게시판 사정 때문에 내가 남긴 것을 못 거두면 안 된다.
 *  - 해제는 목록과 통계 카드를 함께 다시 받는다 — 숫자만 남아 있으면 화면이 거짓말을 한다.
 *
 * 저작본(.g7.html)의 기계 산출물인 `.json` 을 직접 읽는다.
 */

import { describe, it, expect } from 'vitest';

import boardLayout from '../../layouts/mypage/board.json';
import listPartial from '../../layouts/partials/mypage/board/_list.json';
import myReactions from '../../layouts/partials/mypage/board/_my_reactions.json';
import deleteModal from '../../layouts/partials/mypage/board/_modal_reaction_delete.json';
import bulkModal from '../../layouts/partials/mypage/board/_modal_reaction_bulk_delete.json';

const layoutJson = JSON.stringify(boardLayout);
const listJson = JSON.stringify(listPartial);
const json = JSON.stringify(myReactions);
const deleteJson = JSON.stringify(deleteModal);
const bulkJson = JSON.stringify(bulkModal);

describe('내 반응 — 데이터소스와 탭 진입', () => {
    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_datasource_passes_seven_filters
    it('데이터소스가 일곱 가지 조건을 전부 싣는다', () => {
        const ds = (boardLayout as any).data_sources.find((d: any) => d.id === 'myReactions');

        expect(ds).toBeTruthy();
        expect(ds.endpoint).toContain('/api/modules/sirsoft-board/me/reactions');

        for (const key of [
            'page',
            'board_slug',
            'search',
            'search_field',
            'reaction_type_id',
            'target_type',
            'sort',
        ]) {
            expect(ds.endpoint, key).toContain(`${key}=`);
        }

        expect(ds.auth_required).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_subtab_button_refetches_lazily
    it('세 번째 탭이라 열릴 때 처음 받는다', () => {
        const ds = (boardLayout as any).data_sources.find((d: any) => d.id === 'myReactions');

        // auto_fetch 가 켜져 있으면 다른 탭을 보는 동안에도 요청이 나간다.
        expect(ds.auto_fetch).toBe(false);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_subtab_button_refetches_lazily
    it('탭 버튼이 쪽수를 1 로 되돌리고 다시 받는다', () => {
        expect(/mypage-subtab-my-reactions/.test(listJson)).toBe(true);
        expect(/"boardActivitySubTab":\s*"my-reactions"/.test(listJson)).toBe(true);
        expect(/"myReactionsPage":\s*1/.test(listJson)).toBe(true);
        expect(/"dataSourceId":\s*"myReactions"/.test(listJson)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_stats_card_counts_total_reactions
    it('통계 카드가 셋에서 다섯으로 늘고 남긴 반응·신고한 건을 낸다', () => {
        expect(/grid-cols-5/.test(listJson)).toBe(true);
        expect(/total_reactions/.test(listJson)).toBe(true);
        expect(/total_reports/.test(listJson)).toBe(true);
        // 좁은 화면은 두 칸으로 접는다 — 다섯 칸을 그대로 두면 숫자가 잘린다.
        expect(/grid-cols-2/.test(listJson)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_datasource_passes_seven_filters
    it('선택 배열은 전역에 둔다 — 확인 창이 목록과 다른 지역 스코프에 살기 때문', () => {
        // 지역에 두면 창이 비워도 목록 위 선택 바가 「2개 선택」인 채로 남는다
        // (2026-09-15 브라우저 실측: 일괄 해제 후 행 7→5·체크 0개인데 바는 그대로).
        expect(/"initGlobal":\{[^}]*"myReactionsSelectedIds"/.test(layoutJson.replace(/\s/g, ''))).toBe(true);
        expect(/_local\.myReactionsSelectedIds/.test(json)).toBe(false);
        expect(/_global\.myReactionsSelectedIds/.test(json)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_search_and_paging_clear_selection
    it('확인 창은 지역 플래그와 전역 선택을 한 set-state 로 묶지 않는다', () => {
        // 묶으면 둘 중 하나가 엉뚱한 스코프에 쓰여 조용히 사라진다.
        for (const [label, j] of [['단건', deleteJson], ['일괄', bulkJson]] as Array<[string, string]>) {
            const mixed = /\{"target":\s*"local"[^}]*myReactionsSelectedIds/.test(j);

            expect(mixed, label).toBe(false);
        }
    });
});

describe('내 반응 — 필터·검색·선택', () => {
    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_datasource_passes_seven_filters
    it('유형 선택지는 별도 API 가 아니라 목록 응답 메타에서 온다', () => {
        expect(/myReactions\?\.data\?\.reaction_types/.test(json)).toBe(true);
        expect(/my-reactions-type-filter/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_target_badge_splits_post_and_comment
    it('대상 필터가 전체·게시글·댓글 세 갈래다', () => {
        expect(/my-reactions-target-filter/.test(json)).toBe(true);
        expect(/board_activities\.target\.post/.test(json)).toBe(true);
        expect(/board_activities\.target\.comment/.test(json)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_search_and_paging_clear_selection
    it('검색·초기화·쪽 이동이 선택을 먼저 비운다', () => {
        // 비우지 않으면 화면에 없는 건이 일괄 해제에 실린다.
        const cleared = json.match(/"myReactionsSelectedIds":\s*\[\]/g) ?? [];

        // 게시판·유형·대상·정렬·검색·초기화·쪽 이동
        expect(cleared.length).toBeGreaterThanOrEqual(7);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_select_all_covers_current_page_only
    it('전체선택은 지금 쪽만 대상으로 한다', () => {
        // every 로 「지금 쪽이 다 골라졌는지」 보고, map 으로 지금 쪽만 채운다.
        expect(/\.every\(r => \(_global\.myReactionsSelectedIds \?\? \[\]\)\.includes\(r\.id\)\)/.test(json)).toBe(true);
        expect(/\.map\(r => r\.id\)/.test(json)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_select_all_covers_current_page_only
    it('행 체크는 있으면 빼고 없으면 더한다', () => {
        expect(/\.includes\(reaction\.id\)/.test(json)).toBe(true);
        expect(/\.filter\(v => v !== reaction\.id\)/.test(json)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_select_all_covers_current_page_only
    it('선택 바는 고른 것이 있을 때만 서고 건수를 말한다', () => {
        expect(/my-reactions-selected-count/.test(json)).toBe(true);
        expect(/my-reactions-bulk-remove-btn/.test(json)).toBe(true);
        expect(/reactions\.selected_count/.test(json)).toBe(true);
    });
});

describe('내 반응 — 행의 세 갈래', () => {
    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_rows_split_into_three_states
    it('살아 있는 행만 제목을 눌러 원글로 간다', () => {
        expect(/reaction\.reaction_enabled/.test(json)).toBe(true);
        expect(/\/board\/\{\{reaction\.board_slug\}\}\/\{\{reaction\.post_id\}\}/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_rows_split_into_three_states
    it('게시판이 반응을 껐거나 사라진 행은 톤을 낮추고 배지로 사유를 말한다', () => {
        expect(/reaction\.board_deleted/.test(json)).toBe(true);
        expect(/reactions\.disabled_flag/.test(json)).toBe(true);
        expect(/reactions\.board_deleted/.test(json)).toBe(true);
        expect(/opacity-50/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_rows_split_into_three_states
    it('대상이 지워진 행은 삭제된 글·댓글로 갈라 말한다', () => {
        expect(/reaction\.target_deleted/.test(json)).toBe(true);
        expect(/reactions\.deleted_post/.test(json)).toBe(true);
        expect(/reactions\.deleted_comment/.test(json)).toBe(true);
        expect(/italic/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_rows_split_into_three_states
    it('어느 갈래든 해제 버튼은 있다 — 게시판 사정으로 내 것을 못 거두면 안 된다', () => {
        const removeButtons = json.match(/my-reaction-remove-\{\{reaction\.id\}\}/g) ?? [];

        // 세 갈래의 표 행 + 좁은 화면 카드
        expect(removeButtons.length).toBeGreaterThanOrEqual(3);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_target_badge_splits_post_and_comment
    it('대상 배지가 게시글과 댓글을 색으로 가른다', () => {
        expect(/reaction\.target_type === 'comment'/.test(json)).toBe(true);
        expect(/purple/.test(json)).toBe(true);
        expect(/blue/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_rows_split_into_three_states
    it('반응 유형 칸이 이미지 아이콘을 먼저 쓰고 없으면 글꼴 아이콘으로 떨어진다', () => {
        expect(/!!reaction\.reaction_type\?\.icon_url/.test(json)).toBe(true);
        expect(/!reaction\.reaction_type\?\.icon_url/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_rows_split_into_three_states
    it('좁은 화면 카드는 -m- 접미 testid 로 갈린다', () => {
        expect(/my-reaction-row-m-/.test(json)).toBe(true);
        expect(/my-reaction-check-m-/.test(json)).toBe(true);
        expect(/my-reaction-remove-m-/.test(json)).toBe(true);
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_rows_split_into_three_states
    it('빈 상태와 페이지 이동은 항상 자리를 지킨다', () => {
        expect(/my-reactions-empty/.test(json)).toBe(true);
        expect(/reactions\.empty/.test(json)).toBe(true);
        expect(/pagination/i.test(json)).toBe(true);
    });
});

describe('내 반응 — 해제 모달', () => {
    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_single_and_bulk_modals_call_delete
    it('단건 해제가 그 반응 하나만 DELETE 한다', () => {
        expect(/\/api\/modules\/sirsoft-board\/me\/reactions\/\{\{_global\.reactionDeleteModal\?\.id\}\}/.test(deleteJson)).toBe(true);
        expect(/"DELETE"/.test(deleteJson)).toBe(true);
        expect(/reaction-delete-confirm/.test(deleteJson)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_single_and_bulk_modals_call_delete
    it('단건 해제 성공이 선택 배열에서도 그 id 를 걷는다', () => {
        // 남겨 두면 다음 일괄 해제가 이미 없는 ID 를 싣는다.
        expect(/filter\(v => v !== _global\.reactionDeleteModal\?\.id\)/.test(deleteJson)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_single_and_bulk_modals_call_delete
    it('일괄 해제가 본문에 ID 목록을 싣고 성공하면 선택을 비운다', () => {
        expect(/\/api\/modules\/sirsoft-board\/me\/reactions/.test(bulkJson)).toBe(true);
        expect(/"ids"/.test(bulkJson)).toBe(true);
        expect(/"myReactionsSelectedIds":\s*\[\]/.test(bulkJson)).toBe(true);
        expect(/reaction-bulk-delete-confirm/.test(bulkJson)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_stats_card_counts_total_reactions
    it('두 모달 모두 목록과 통계 카드를 함께 다시 받는다', () => {
        for (const [label, j] of [['단건', deleteJson], ['일괄', bulkJson]] as Array<[string, string]>) {
            expect(j, label).toContain('"myReactions"');
            // 숫자만 남아 있으면 화면이 거짓말을 한다.
            expect(j, label).toContain('"myActivityStats"');
        }
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_single_and_bulk_modals_call_delete
    it('삭제된 대상이면 제목 대신 다른 문구로 묻는다', () => {
        expect(/reactionDeleteModal\?\.isDeleted/.test(deleteJson)).toBe(true);
        expect(/reactions\.delete_confirm_deleted/.test(deleteJson)).toBe(true);
        // 글·댓글 자체는 지워지지 않는다는 안내가 두 창 모두에 있다.
        expect(/reactions\.delete_notice/.test(deleteJson)).toBe(true);
        expect(/reactions\.delete_notice/.test(bulkJson)).toBe(true);
    });

    // @scenario case=subtab_bulk_selection_clears_on_navigation
    // @effects mypage_reactions_single_and_bulk_modals_call_delete
    it('세 확인 창이 레이아웃에 등록되어 있다', () => {
        // 모달은 조각 경로로 등록된다 — 등록이 빠지면 열기 액션이 아무 일도 하지 않는다.
        for (const partial of [
            '_modal_reaction_delete.json',
            '_modal_reaction_bulk_delete.json',
            '_modal_report_cancel.json',
        ]) {
            expect(layoutJson, partial).toContain(partial);
        }
    });
});

describe('내 반응 — 검색 0건 안내', () => {
    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_filtered_empty_says_no_results
    it('검색·게시판·유형·대상 중 하나라도 걸려 있으면 검색 결과 없음을 낸다', () => {
        const json = JSON.stringify(myReactions);
        expect(json.includes('!!_global.myReactionsSearchApplied || !!_global.myReactionsBoard || !!_global.myReactionsTypeId || !!_global.myReactionsTargetType')).toBe(true);
        expect(json.includes('$t:board.no_search_results')).toBe(true);
    });
});

/** 레이아웃 트리에서 조건에 맞는 노드를 모두 모은다. */
function collectNodes(node: any, pred: (n: any) => boolean, out: any[] = []): any[] {
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) {
        node.forEach((child) => collectNodes(child, pred, out));
        return out;
    }
    if (pred(node)) out.push(node);
    for (const value of Object.values(node)) collectNodes(value, pred, out);
    return out;
}

describe('내 반응 — 좁은 화면(390px) 가로 넘침', () => {
    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_board_tabs_scroll_inside_bar_on_narrow_screen
    it('네 탭(내 글·내 댓글·내 반응·내 신고) 막대는 좁은 화면에서 자기 안에서 가로로 흐른다', () => {
        // 탭 넷의 폭(92+95+95+94px)과 간격이 343px 칸을 넘어 페이지 전체가 가로로 밀렸다(K1 실측 2026-10-06).
        const bars = collectNodes(listPartial, (n) => typeof n?.props?.className === 'string'
            && n.props.className.includes('border-b border-gray-200')
            && JSON.stringify(n).includes('boardActivitySubTab'));

        expect(bars.length).toBeGreaterThan(0);
        expect(bars[0].props.className).toContain('overflow-x-auto');
        expect(bars[0].props.className).toContain('whitespace-nowrap');
    });

    // @scenario case=subtab_lists_rows_in_three_states
    // @effects mypage_reactions_search_input_shrinks_on_narrow_screen
    it('검색 입력칸이 줄어들 수 있어 검색 줄이 칸 밖으로 밀리지 않는다', () => {
        // 입력칸의 고유 최소 폭(약 213px) 때문에 찾을 곳·입력·검색·초기화 줄이 433px 로 넘쳤다.
        const inputs = collectNodes(myReactions, (n) => n?.name === 'Input'
            && n?.props?.placeholder === '$t:mypage.board_activities.search_placeholder');

        expect(inputs.length).toBe(1);
        expect(inputs[0].props.className).toContain('min-w-0');
    });
});

describe('내 반응 — 해제 확인 버튼 커서', () => {
    // @scenario case=remove_single_own_only_and_recalculates
    // @effects mypage_reaction_confirm_buttons_show_pointer
    it('단건·일괄 해제 확인 버튼이 형제 「취소」 처럼 손가락 커서를 쓴다', () => {
        for (const [tid, layout] of [['reaction-delete-confirm', deleteModal], ['reaction-bulk-delete-confirm', bulkModal]] as const) {
            const buttons = collectNodes(layout, (n) => n?.props?.['data-testid'] === tid);

            expect(buttons.length, tid).toBe(1);
            expect(buttons[0].props.className, tid).toContain('cursor-pointer');
        }
    });
});

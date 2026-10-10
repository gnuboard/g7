/**
 * @file admin-board-posts-bulk.test.tsx
 * @description 관리자 게시글 일괄 삭제·블라인드·복구 (#524 WP-D) — 레이아웃 계약 회귀.
 *
 * 이 화면이 지키는 계약:
 *  - 체크 칸은 `admin.manage` 능력이 있을 때만 나온다. 권한이 없는 운영자에게 누를 수 없는
 *    버튼을 보여 주지 않는다.
 *  - 세 갈래는 **한 확인 창**을 공유하고, 무엇을 하는지는 `bulkPostAction` 이 정한다.
 *    갈래를 고를 때 직전 사유를 비운다 — 남겨 두면 다른 갈래의 문장이 그대로 이력에 실린다.
 *  - 갈래마다 메서드와 경로가 다르다(삭제 DELETE `/bulk`, 블라인드 PATCH `/bulk-blind`,
 *    복구 PATCH `/bulk-restore`).
 *  - 실패는 토스트로 스쳐 보내지 않고 오류 창으로 세운다 — 여러 건을 다루는 자리라
 *    "몇 건이 왜 건너뛰어졌는지" 를 읽을 시간이 필요하다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, callbacksOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../../layouts/admin/admin_board_posts_index.json');

const modals: Record<string, any> = Object.fromEntries(asArray(layout.modals).map((m: any) => [m.id, m]));
const bulkModal = modals.bulk_post_action_modal;

/** 액션 트리를 평탄화한다 — `switch` 는 갈래를 `cases` 에 접어 둔다. */
function flattenActions(node: unknown): any[] {
    const out: any[] = [];
    const visit = (value: any): void => {
        if (!value || typeof value !== 'object') return;
        if (Array.isArray(value)) {
            value.forEach(visit);
            return;
        }
        if (typeof value.handler === 'string') out.push(value);
        for (const child of Object.values(value)) visit(child);
    };
    visit(node);
    return out;
}

const branches = flattenActions(bulkModal).find((a: any) => a.handler === 'switch')?.cases ?? {};

function buttonByTestId(testId: string): any {
    return flattenNodes(layout).find((n: any) => n?.props?.['data-testid'] === testId);
}

describe('관리자 게시글 일괄 처리 (#524 WP-D)', () => {
    // @scenario permission=manage
    // @effects admin_post_bulk_selectable_requires_manage
    it('체크 칸은 manage 능력이 있을 때만 나온다', () => {
        const grid = flattenNodes(layout).find((n: any) => n?.name === 'DataGrid');

        expect(grid.props.selectable).toBe('{{posts?.data?.abilities?.can_manage === true}}');
    });

    // @scenario permission=manage
    // @effects admin_post_bulk_selection_lifts_to_global
    it('선택은 전역으로 올린다 — 일괄 바가 표 밖에 있다', () => {
        const grid = flattenNodes(layout).find((n: any) => n?.name === 'DataGrid');
        const action = actionsOf(grid).find((a: any) => a.event === 'onSelectionChange');

        expect(action.params.selectedIds_board_posts).toMatch(/\$args\[0\]/);
    });

    // @scenario bulk_action=delete
    // @effects admin_post_bulk_buttons_disabled_without_manage_or_selection
    it('세 버튼 모두 manage 능력과 선택이 둘 다 있어야 풀린다', () => {
        for (const testId of ['admin-post-bulk-delete', 'admin-post-bulk-blind', 'admin-post-bulk-restore']) {
            const disabled = buttonByTestId(testId).props.disabled;

            expect(disabled).toMatch(/abilities\?\.can_manage !== true/);
            expect(disabled).toMatch(/selectedIds_board_posts/);
        }
    });

    // @scenario bulk_action=blind
    // @effects admin_post_bulk_buttons_share_one_modal
    it('세 버튼이 같은 확인 창을 열고 갈래만 달리 심는다', () => {
        const expected: Record<string, string> = {
            'admin-post-bulk-delete': 'delete',
            'admin-post-bulk-blind': 'blind',
            'admin-post-bulk-restore': 'restore',
        };

        for (const [testId, action] of Object.entries(expected)) {
            const actions = actionsOf(buttonByTestId(testId));

            expect(actions.find((a: any) => a.handler === 'setState').params.bulkPostAction).toBe(action);
            expect(actions.find((a: any) => a.handler === 'openModal').target).toBe('bulk_post_action_modal');
        }
    });

    // @scenario bulk_action=restore
    // @effects admin_post_bulk_clears_previous_reason
    it('갈래를 고를 때 직전 사유를 비운다 — 다른 갈래의 문장이 이력에 실리면 안 된다', () => {
        for (const testId of ['admin-post-bulk-delete', 'admin-post-bulk-blind', 'admin-post-bulk-restore']) {
            const setState = actionsOf(buttonByTestId(testId)).find((a: any) => a.handler === 'setState');

            expect(setState.params.bulkPostReason).toBe('');
            expect(setState.params.bulkPostLoading).toBe(false);
        }
    });

    // @scenario bulk_action=delete
    // @effects admin_post_bulk_branches_have_distinct_method_and_path
    it('갈래마다 메서드와 경로가 다르다', () => {
        expect(branches.delete.params.method).toBe('DELETE');
        expect(branches.delete.target).toMatch(/\/posts\/bulk$/);

        expect(branches.blind.params.method).toBe('PATCH');
        expect(branches.blind.target).toMatch(/\/posts\/bulk-blind$/);

        expect(branches.restore.params.method).toBe('PATCH');
        expect(branches.restore.target).toMatch(/\/posts\/bulk-restore$/);
    });

    // @scenario bulk_action=blind
    // @effects admin_post_bulk_sends_ids_and_reason
    it('세 갈래 모두 선택 목록과 사유를 함께 보낸다', () => {
        for (const branch of ['delete', 'blind', 'restore']) {
            expect(branches[branch].params.body.ids).toMatch(/selectedIds_board_posts/);
            expect(branches[branch].params.body.reason).toMatch(/bulkPostReason/);
        }
    });

    // @scenario bulk_action=delete
    // @effects admin_post_bulk_clears_selection_on_success
    it('성공하면 선택과 사유를 비우고 목록을 다시 부른다', () => {
        for (const branch of ['delete', 'blind', 'restore']) {
            const onSuccess = callbacksOf(branches[branch], 'onSuccess');
            const cleared = onSuccess.find((a: any) => a.handler === 'setState');

            expect(cleared.params.selectedIds_board_posts).toEqual([]);
            expect(cleared.params.bulkPostReason).toBe('');
            expect(onSuccess.some((a: any) => a.handler === 'refetchDataSource')).toBe(true);
        }
    });

    // @scenario bulk_action=delete
    // @effects admin_post_bulk_failure_opens_error_modal
    it('실패는 토스트가 아니라 오류 창으로 세운다', () => {
        for (const branch of ['delete', 'blind', 'restore']) {
            const onError = callbacksOf(branches[branch], 'onError');

            expect(onError.some((a: any) => a.handler === 'openModal' && a.target === 'bulk_post_error_modal')).toBe(true);
        }

        expect(modals.bulk_post_error_modal).toBeTruthy();
    });

    // @scenario bulk_action=delete
    // @effects admin_post_bulk_confirm_guards_double_submit
    it('확인 버튼은 처리 중 두 번 눌리지 않는다', () => {
        const confirm = buttonByTestId('admin-post-bulk-confirm');

        expect(confirm.props.disabled).toMatch(/bulkPostLoading/);
        // 첫 액션이 진행 표시를 켜 준다 — 여러 건이라 응답이 늦다.
        expect(actionsOf(confirm)[0].params.bulkPostLoading).toBe(true);
    });

    // disabled 는 다시 그려진 뒤에야 걸린다 — 더블클릭의 두 번째 클릭이 요청을 한 번 더 보내고,
    // 그 응답이 방금 처리한 글을 「이미 블라인드된 글」로 건너뛰었다고 창을 띄웠다(K3 실측 2026-10-07: 요청 2건).
    // @scenario bulk_action=blind
    // @effects admin_post_bulk_confirm_guards_double_submit
    it('확인 버튼은 첫 클릭 즉시 스스로 잠근다(lockRepeatClick)', () => {
        const confirm = buttonByTestId('admin-post-bulk-confirm');

        expect(confirm.props.lockRepeatClick).toBe(true);
    });
});

describe('관리자 게시글 일괄 처리 — 건너뛴 글 결과 창', () => {
    // 건너뛴 글이 있으면 어느 글이 왜 빠졌는지 창으로 편다 — 토스트의 「건너뜀 N개」 개수만으로는
    // 다시 고를 수 없다(브라우저 실측 2026-10-03).
    // @scenario bulk_action=delete
    // @effects admin_post_bulk_skipped_items_carry_title_and_reason_label
    it('세 갈래 모두 응답의 skipped 를 담고, 하나라도 있으면 결과 창을 연다', () => {
        for (const action of ['delete', 'blind', 'restore']) {
            const actions = flattenActions(branches[action]);
            const store = actions.find((a: any) => a.handler === 'setState' && 'bulkPostSkipped' in (a.params ?? {}));
            expect(store?.params.bulkPostSkipped).toContain('response?.data?.skipped');

            const open = actions.find((a: any) => a.handler === 'openModal' && a.target === 'bulk_post_skipped_modal');
            expect(open).toBeTruthy();
            expect(String(open.if)).toContain('skipped');
        }
    });

    // @scenario bulk_action=delete
    // @effects admin_post_bulk_skipped_items_carry_title_and_reason_label
    it('결과 창은 글마다 번호·제목과 서버가 번역한 사유를 낸다', () => {
        const skipped = modals.bulk_post_skipped_modal;
        expect(skipped).toBeTruthy();
        const json = JSON.stringify(skipped);
        expect(json.includes('_global.bulkPostSkipped')).toBe(true);
        // 제목은 회원이 쓴 글이다 — 안에 `$t:` 가 있어도 번역하지 않도록 raw 로 그린다
        expect(json.includes("{{raw:skippedPost.title ?? ''}}")).toBe(true);
        // 빈 폴백이 빠진 식(`?? }}`)은 화면에 원문 그대로 나온다
        expect(json.includes("?? }}")).toBe(false);
        expect(json.includes('skippedPost.reason_label')).toBe(true);
    });

    // 편집기는 창 상태가 없으면 「0개 게시글」 빈 목록으로 연다 — 형제 창(일괄 처리, 오류)처럼 창이 읽는 값을 담는다.
    // @scenario bulk_action=delete
    // @effects admin_post_bulk_skipped_items_carry_title_and_reason_label
    it('편집기 창 상태가 결과 창이 읽는 건너뛴 글 목록을 응답과 같은 모양으로 담는다', () => {
        const spec = require('../../../../editor-spec.json');
        const group = (spec.states?.groups ?? []).find(
            (g: any) => g.scope?.kind === 'modal' && g.scope?.match === 'bulk_post_skipped_modal',
        );
        const seeded = group?.items?.find((i: any) => i.default)?.initialState?.global?.bulkPostSkipped;
        expect(Array.isArray(seeded)).toBe(true);
        expect(seeded.length).toBeGreaterThan(0);
        for (const row of seeded) {
            expect(Object.keys(row).sort()).toEqual(['id', 'reason', 'reason_label', 'title']);
        }
    });
});

// e2e:allow 실제 레이아웃 노드(댓글 수정 폼·로그인 화면 틀)를 엔진으로 그리고 내려 전역 상태를 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(비회원 댓글 비밀번호 확인 → 수정 중 다른 글로 이동 → `G7Core.state.get().verifiedGuestPassword`, 로그인 2단계 화면에서 다른 화면으로 이동 → `twoFactor`)이 덮는다.
/**
 * @file guest-secret-global-cleared.test.tsx
 * @description 쓰임새가 끝난 비밀값을 전역에 남기지 않는다 — 비회원 댓글 확인 비밀번호·로그인 단계 요청 번호 (7.1.1)
 *
 * ① 비회원 댓글 수정은 비밀번호 확인 창이 검증한 비밀번호를 `_global.verifiedGuestPassword` 에 두고 수정 저장 요청에 싣는다.
 *    저장·취소는 비웠지만 수정 중에 다른 글·화면으로 이동하면 비밀번호(평문)가 전역에 남았다. 다른 댓글을 바로 수정(회원 댓글·
 *    관리자)으로 넘어가도 앞 비회원 댓글의 비밀번호가 남았다.
 *    고침: 수정 폼 unmount 에서 「지금 수정 대상 = 이 댓글」 일 때 수정 모드와 비밀번호를 비운다(값 식의 최신 `_global` 로 견준다 —
 *    다른 비회원 댓글로 넘어가며 내려갈 때는 그 댓글의 값을 지킨다). 바로 수정 진입은 비밀번호를 비운다.
 * ② 로그인 화면의 2단계 인증·비밀번호 변경 단계 상태(`twoFactor`·`passwordChange` — 요청 번호 포함)는 진입 때만 되돌려, 다른 화면에
 *    머무는 동안 전역에 남았다. 고침: 화면 틀 unmount 에서 비운다.
 *
 * @scenario-manifest tests/scenarios/guest-secret-global-cleared.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest, createMockComponentRegistryWithBasics, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';

import commentItem from '../../../layouts/partials/board/show/_comment_item.json';
import loginLayout from '../../../layouts/auth/login.json';

type Node = Record<string, any>;

/**
 * 트리에서 조건에 맞는 첫 노드
 *
 * @param x 탐색 대상
 * @param pred 조건
 * @returns 노드 또는 null
 */
function findNode(x: any, pred: (n: Node) => boolean): Node | null {
    if (Array.isArray(x)) {
        for (const y of x) {
            const r = findNode(y, pred);
            if (r) return r;
        }

        return null;
    }
    if (!x || typeof x !== 'object') return null;
    if (x.type && x.name && pred(x)) return x;
    for (const v of Object.values(x)) {
        const r = findNode(v, pred);
        if (r) return r;
    }

    return null;
}

/**
 * 트리의 모든 액션을 펼친다.
 *
 * @param x 탐색 대상
 * @param out 결과
 * @returns 액션 목록
 */
function allActions(x: any, out: any[] = []): any[] {
    if (Array.isArray(x)) {
        x.forEach((v) => allActions(v, out));

        return out;
    }
    if (!x || typeof x !== 'object') return out;
    if (typeof x.handler === 'string') out.push(x);
    Object.values(x).forEach((v) => allActions(v, out));

    return out;
}

/** 댓글 수정 폼 노드 — 자식은 떼고 틀(조건·생애주기)만 쓴다 */
const editForm = findNode(commentItem, (n) => String(n.if ?? '').includes('commentEdit?.editingCommentId === comment?.id'));
const { if: editIf, ...editFormBody } = editForm ?? {};

/**
 * 댓글 목록(반복)과 그 안의 수정 폼을 그리는 시험 레이아웃
 *
 * @returns 레이아웃
 */
function commentLayout(): any {
    return {
        version: '1.0.0',
        layout_name: 'test/comment-edit-cleared',
        components: [{
            type: 'basic',
            name: 'Div',
            if: '{{_global.__onPage === true}}',
            children: [{
                type: 'basic',
                name: 'Div',
                iteration: { source: '{{_global.__comments ?? []}}', item_var: 'comment' },
                children: [{ ...editFormBody, if: editIf, children: [] }],
            }],
        }],
    };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('① 비회원 댓글 수정 비밀번호 — 쓰임새가 끝나면 비운다', () => {
    // @scenario surface=comment_edit, exit=leave_page
    // @effects guest_comment_password_cleared_when_edit_ends
    it('수정 중에 화면을 떠나면(수정 폼이 내려가면) 수정 모드와 비밀번호가 null 이 된다', async () => {
        expect(editForm, '수정 폼 노드').toBeTruthy();
        const utils = createLayoutTest(commentLayout(), {
            componentRegistry: createMockComponentRegistryWithBasics() as any,
            locale: 'ko',
            initialState: { _global: { __onPage: true, __comments: [{ id: 5, is_guest_comment: true }], commentEdit: { editingCommentId: 5, editingCommentContent: '고치는 중' }, verifiedGuestPassword: 'pw-5' } },
        });
        await utils.render();
        expect(utils.getState()._global.verifiedGuestPassword).toBe('pw-5');

        utils.setState('__onPage', false, 'global');
        await utils.rerender();
        await waitFor(() => {
            expect(utils.getState()._global.verifiedGuestPassword).toBeNull();
            expect(utils.getState()._global.commentEdit).toBeNull();
        });
        utils.cleanup();
    });

    // @scenario surface=comment_edit, exit=switch_guest_comment
    // @effects guest_comment_password_cleared_when_edit_ends
    it('다른 비회원 댓글 수정으로 넘어가며 앞 폼이 내려가도 새 댓글의 수정 모드·비밀번호는 지킨다', async () => {
        const utils = createLayoutTest(commentLayout(), {
            componentRegistry: createMockComponentRegistryWithBasics() as any,
            locale: 'ko',
            initialState: { _global: { __onPage: true, __comments: [{ id: 5, is_guest_comment: true }, { id: 7, is_guest_comment: true }], commentEdit: { editingCommentId: 5 }, verifiedGuestPassword: 'pw-5' } },
        });
        await utils.render();

        // 비밀번호 확인 창 성공 갈래가 새 대상과 비밀번호를 함께 쓴다
        utils.setState('commentEdit', { editingCommentId: 7, editingCommentContent: '다른 댓글' }, 'global');
        utils.setState('verifiedGuestPassword', 'pw-7', 'global');
        await utils.rerender();
        await new Promise((r) => setTimeout(r, 50));
        expect(utils.getState()._global.commentEdit?.editingCommentId).toBe(7);
        expect(utils.getState()._global.verifiedGuestPassword).toBe('pw-7');
        utils.cleanup();
    });

    // @scenario surface=comment_edit, exit=switch_direct_edit
    // @effects guest_comment_password_cleared_when_edit_ends
    it('회원 댓글·관리자 바로 수정 진입은 앞서 받아 둔 비밀번호를 비운다', () => {
        const direct = allActions(commentItem).filter((a) => a.handler === 'setState' && a.params?.target === 'global'
            && String(a.if ?? '').includes('!comment?.is_guest_comment') && JSON.stringify(a.params).includes('editingCommentId'));
        expect(direct).toHaveLength(1);
        expect(Object.prototype.hasOwnProperty.call(direct[0].params, 'verifiedGuestPassword')).toBe(true);
        expect(direct[0].params.verifiedGuestPassword).toBeNull();
    });
});

describe('② 로그인 단계 상태 — 화면을 떠나면 비운다', () => {
    const frame = findNode(loginLayout, (n) => (n.lifecycle?.onUnmount ?? []).some((a: any) => a.handler === 'setState'
        && a.params?.target === 'global' && a.params.twoFactor === null && a.params.passwordChange === null));

    // @scenario surface=login, exit=leave_page
    // @effects login_step_state_cleared_on_leave
    it('로그인 화면 틀이 내려가면 twoFactor·passwordChange 가 null 이 된다', async () => {
        expect(frame, '로그인 화면 틀의 unmount 정리').toBeTruthy();
        const { if: _if, ...body } = frame!;
        const utils = createLayoutTest(
            { version: '1.0.0', layout_name: 'test/login-leave', components: [{ type: 'basic', name: 'Div', if: '{{_global.__onPage === true}}', children: [{ ...body, children: [] }] }] } as any,
            {
                componentRegistry: createMockComponentRegistryWithBasics() as any,
                locale: 'ko',
                initialState: { _global: { __onPage: true, twoFactor: { required: true, challenge_id: 'c-1' }, passwordChange: { required: true, challenge_id: 'c-2' } } },
            },
        );
        await utils.render();
        expect(utils.getState()._global.twoFactor).not.toBeNull();

        utils.setState('__onPage', false, 'global');
        await utils.rerender();
        await waitFor(() => {
            expect(utils.getState()._global.twoFactor).toBeNull();
            expect(utils.getState()._global.passwordChange).toBeNull();
        });
        utils.cleanup();
    });
});

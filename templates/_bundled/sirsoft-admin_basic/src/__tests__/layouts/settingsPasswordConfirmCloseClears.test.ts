/**
 * @file settingsPasswordConfirmCloseClears.test.ts
 * @description 앱 키 재생성 비밀번호 확인 창을 어느 갈래로 닫든 입력한 비밀번호가 전역에 남지 않는다 (7.1.1)
 *
 * 창은 입력한 비밀번호를 `_global.appKeyPassword` 에 담는다(실행 단추의 잠금 조건과 요청 본문이 함께 읽는다). 「취소」·성공 갈래는
 * 직접 지웠지만, 창 머리 닫기·Esc·바깥 클릭은 엔진이 주입한 closeModal 로만 이어져 레이아웃 동작을 타지 않아 비밀번호가 남았다 —
 * 다시 열면 입력칸은 비었는데(입력칸은 상태를 읽지 않는다) 실행 단추가 열려 있고 누르면 앞 비밀번호가 나갔다. 창이 열려 있는 동안
 * 내내 붙어 있는 본문 틀(조건·반복 없음)의 unmount 에서 지운다. 실제 창 구조에서 unmount 가 닫기 갈래 전부에 걸리는 것은 basic 의
 * 실구조 시험(checkout-address-modal-real-structure.test.tsx 「창을 닫으면 창 이름 공간이 비워진다」)이 잰다.
 *
 * @scenario-manifest tests/scenarios/settings-password-confirm-close-clears.yaml
 */

import { describe, it, expect } from 'vitest';
import passwordConfirmModal from '../../../layouts/partials/admin_settings/_modal_password_confirm.json';

type Node = Record<string, any>;

/**
 * 창 안에서 unmount 가 key 를 전역에서 비우는 노드들과, 각 노드의 창 루트까지 조상에 조건·반복이 있는지
 *
 * @param modal 창(Modal) 노드
 * @param key 비우는 전역 키
 * @returns 찾은 노드마다 조건·반복 조상 여부
 */
function unmountClears(modal: Node, key: string): Array<{ guarded: boolean }> {
    const out: Array<{ guarded: boolean }> = [];
    const walk = (x: any, guarded: boolean): void => {
        if (!x || typeof x !== 'object') return;
        const g = guarded || (x !== modal && (x.if !== undefined || x.iteration !== undefined));
        const hit = (x.lifecycle?.onUnmount ?? []).some((a: any) => a?.handler === 'setState' && a.params?.target === 'global'
            && Object.prototype.hasOwnProperty.call(a.params, key) && (a.params[key] === '' || a.params[key] === null));
        if (hit) out.push({ guarded: g });
        (x.children ?? []).forEach((c: any) => walk(c, g));
    };
    walk(modal, false);

    return out;
}

/**
 * 트리에서 handler 가 같은 액션을 모두 모은다
 *
 * @param x 탐색 대상
 * @param handler 핸들러 이름
 * @returns 액션 목록
 */
function actionsNamed(x: any, handler: string): any[] {
    const out: any[] = [];
    (function visit(o: any): void {
        if (Array.isArray(o)) {
            o.forEach(visit);

            return;
        }
        if (!o || typeof o !== 'object') return;
        if (o.handler === handler) out.push(o);
        Object.values(o).forEach(visit);
    })(x);

    return out;
}

describe('앱 키 재생성 비밀번호 확인 창 — 닫으면 비밀번호를 지운다 (7.1.1)', () => {
    // @scenario close=header_escape_backdrop
    // @effects password_cleared_on_any_close
    it('창 머리 닫기·Esc·바깥 클릭 갈래 — 조건 없는 본문 틀의 unmount 가 _global.appKeyPassword 를 빈 문자열로 지운다', () => {
        const found = unmountClears(passwordConfirmModal as Node, 'appKeyPassword');
        expect(found.length).toBeGreaterThan(0);
        expect(found.every((f) => !f.guarded)).toBe(true);
    });

    // @scenario close=cancel_or_success
    // @effects password_cleared_on_any_close
    it('취소·성공 갈래 — 종전처럼 직접 지운다(실패 갈래는 고쳐 다시 넣게 남긴다)', () => {
        const writes = actionsNamed(passwordConfirmModal, 'setState').filter((a) => a.params?.target === 'global' && Object.prototype.hasOwnProperty.call(a.params, 'appKeyPassword'));
        // 입력 change 1 + 취소 1 + 성공 1 + unmount 1
        expect(writes.filter((a) => a.params.appKeyPassword === '').length).toBe(3);
    });

    // @scenario close=static
    // @effects password_cleared_on_any_close
    it('판정이 살아 있다 — 조건 안 노드의 정리는 잡히고, 정리가 없는 창은 0 이다', () => {
        const clear = { onUnmount: [{ handler: 'setState', params: { target: 'global', appKeyPassword: '' } }] };
        expect(unmountClears({ name: 'Modal', children: [{ name: 'Div', if: '{{x}}', lifecycle: clear }] }, 'appKeyPassword')).toEqual([{ guarded: true }]);
        expect(unmountClears({ name: 'Modal', children: [{ name: 'Div' }] }, 'appKeyPassword')).toEqual([]);
    });
});

// e2e:allow 창 이름 공간 정리의 정적 구조 단언 — 닫는 갈래마다 실제로 비워지는 것은 basic 실구조 시험(checkout-address-modal-real-structure.test.tsx)이 같은 짜임(본문 틀 unmount)으로 재고, 브라우저 축은 7.1.1 출시 전 캠페인 재실측(GDPR 플러그인 설정 → 알림 문안 편집 창 — 회원 검색 뒤 X·Esc·취소로 닫고 _global 확인)이 덮는다.
/**
 * @description GDPR 플러그인 설정 → 알림 문안 편집 창을 어느 갈래로 닫든 회원 검색 결과(이름·이메일)·수신자 규칙이 전역에 남지 않는다 (7.1.1)
 *
 * 창은 「특정 회원」 수신자 검색 결과를 `_global.gdpr_notification_template_form_modal.userSearchResults`(`{label: 이름, description: 이메일}`)에,
 * 고른 회원 이름을 `recipients[].display_names` 에 담는다. 저장 성공 갈래만 이름 공간을 비웠고, 「취소」 와 창 머리 닫기·Esc
 * (엔진이 주입한 closeModal 로만 이어져 레이아웃 동작을 타지 않는다)로 닫으면 회원 이름·이메일이 다음에 열 때까지 전역에 남았다.
 *
 * 고침(`docs/frontend/modal-usage.md` 「창을 닫을 때 이름 공간 비우기」): 창이 열려 있는 동안 내내 붙어 있는 본문 틀(조건·반복 없음)의
 * unmount 에서 이름 공간을 null 로, 「취소」 는 closeModal 뒤에 직접 null 로. 창은 닫히면 본문을 그리지 않으므로 unmount 는 어느 갈래로
 * 닫든 한 번 돈다. 엔진 무변경.
 *
 * @scenario-manifest tests/scenarios/notification-template-edit-close-clears.yaml
 */

import { describe, it, expect } from 'vitest';
import modalLayout from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';

type Node = Record<string, any>;

const NS = 'gdpr_notification_template_form_modal';
const MODAL_ID = 'modal_gdpr_notification_template_edit';

/**
 * 창 노드를 찾는다.
 *
 * @param x 탐색 대상
 * @returns 창 노드
 */
function findModal(x: any): Node | null {
    if (!x || typeof x !== 'object') return null;
    if (x.name === 'Modal' && x.id === MODAL_ID) return x;
    for (const v of Object.values(x)) {
        const r = findModal(v);
        if (r) return r;
    }

    return null;
}

/** 이름 공간 전체를 null 로 비우는 setState 인가 */
const clearsNs = (a: any): boolean => a?.handler === 'setState' && a.params?.target === 'global' && Object.prototype.hasOwnProperty.call(a.params, NS) && a.params[NS] === null;

/**
 * 창 안에서 unmount 가 이름 공간을 비우는 노드마다, 창까지 조상에 조건·반복이 있는지
 *
 * @param modal 창 노드
 * @returns 찾은 노드마다 조건·반복 조상 여부
 */
function unmountClears(modal: Node): Array<{ guarded: boolean }> {
    const out: Array<{ guarded: boolean }> = [];
    const walk = (x: any, guarded: boolean): void => {
        if (!x || typeof x !== 'object') return;
        const g = guarded || (x !== modal && (x.if !== undefined || x.iteration !== undefined));
        if ((x.lifecycle?.onUnmount ?? []).some(clearsNs)) out.push({ guarded: g });
        (x.children ?? []).forEach((c: any) => walk(c, g));
    };
    walk(modal, false);

    return out;
}

/**
 * 액션 트리를 펼친다(sequence·success 등 안쪽 포함).
 *
 * @param x 액션(들)
 * @returns 액션 목록(앞에서부터)
 */
function flatActions(x: any): any[] {
    const out: any[] = [];
    (function visit(o: any): void {
        if (Array.isArray(o)) {
            o.forEach(visit);

            return;
        }
        if (!o || typeof o !== 'object') return;
        if (o.handler) out.push(o);
        Object.values(o).forEach(visit);
    })(x);

    return out;
}

const modal = findModal(modalLayout)!;

describe('알림 문안 편집 창 — 닫으면 회원 검색 결과가 남지 않는다 (7.1.1)', () => {
    // @scenario close=header_escape
    // @effects notification_template_user_search_cleared_on_any_close
    it('창 머리 닫기·Esc 갈래 — 조건 없는 본문 틀의 unmount 가 이름 공간을 null 로 비운다', () => {
        expect(modal, '창 노드를 찾지 못했다').toBeTruthy();
        const found = unmountClears(modal);
        expect(found.length).toBeGreaterThan(0);
        expect(found.every((f) => !f.guarded)).toBe(true);
    });

    // @scenario close=cancel
    // @effects notification_template_user_search_cleared_on_any_close
    it('「취소」 갈래 — closeModal 뒤에 이름 공간을 직접 null 로 비운다', () => {
        const buttons: Node[] = [];
        (function visit(o: any): void {
            if (!o || typeof o !== 'object') return;
            if (o.name === 'Button' && JSON.stringify(o.text ?? o.children ?? '').includes('$t:common.cancel')) buttons.push(o);
            Object.values(o).forEach(visit);
        })(modal);
        expect(buttons).toHaveLength(1);
        const acts = flatActions(buttons[0].actions);
        const close = acts.findIndex((a) => a.handler === 'closeModal');
        expect(close).toBeGreaterThanOrEqual(0);
        expect(acts.slice(close + 1).some(clearsNs)).toBe(true);
    });

    // @scenario close=save_success
    // @effects notification_template_user_search_cleared_on_any_close
    it('저장 성공 갈래 — 종전대로 닫은 뒤 비운다(실패 갈래는 고쳐 다시 저장하게 남긴다)', () => {
        const all = flatActions(modal);
        const success = all.filter((a) => a.handler === 'apiCall').flatMap((a) => flatActions(a.onSuccess ?? a.success ?? []));
        expect(success.some(clearsNs)).toBe(true);
        const fail = all.filter((a) => a.handler === 'apiCall').flatMap((a) => flatActions(a.onError ?? a.fail ?? []));
        expect(fail.some(clearsNs)).toBe(false);
    });
});

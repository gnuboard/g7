// e2e:allow 레이아웃 정적 구조(창 본문 unmount 정리·취소/성공 갈래 정리 순서) + 엔진 렌더(본문이 내려가면 이름 공간이 비워짐) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(창을 X·Esc·바깥 클릭·취소·성공으로 닫은 뒤 `G7Core.state.get().<키>` 가 null)이 덮는다.
/**
 * @file adminModalCloseClearsNamespace.test.tsx
 * @description 이커머스 관리자 창 5개가 어느 갈래로 닫히든 개인정보를 담은 창 이름 공간(`_global.<키>`)을 비우는지 (7.1.1)
 *
 * 환불 실행 창(환불 계좌·예금주), 관리자 현금영수증 발급 창(식별번호), 쿠폰 직접 발급 창(회원 이름·검색어), 마일리지
 * 수동 지급/차감 창(회원 이름·이메일), 관리자 직권 클레임 접수 창(환불 계좌)은 입력값을 `_global` 의 창 고유 이름 공간에
 * 둔다(창은 부모 `_local` 을 읽지 못한다). 그런데 창 머리 X·바깥 클릭·Esc 는 엔진 closeModal 로만 이어져 레이아웃 동작을
 * 타지 않고, 취소 단추도 창만 닫아 값이 다음 열기까지 전역에 남았다(security.md 「창 고유 이름 공간은 창을 닫을 때 비우면
 * 허용」 예외의 조건 미달).
 *
 * 고침(레이아웃만, 엔진·병합 옵션 무변경): 관리자 Modal 도 basic 처럼 `isOpen=false` 면 본문을 그리지 않으므로
 * (`Modal.tsx` `if (!isOpen) return null`) 창이 열린 동안 늘 붙어 있는 본문 노드의 `lifecycle.onUnmount` 에서 비운다.
 * 취소 단추·성공 갈래는 `closeModal` **뒤에** 직접 비운다(창 닫기보다 먼저 비우면 닫히기 전 한 번 다시 그려지며 빈 값이
 * 보이고, 뒤에 두어도 같은 목록 안 다음 동작은 unmount 보다 먼저 돈다 — docs/frontend/modal-usage.md).
 *
 * 축 요약(마커 아님 — 평문): surface, close_path. 매핑은 테스트마다 단다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import React from 'react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    waitFor,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import refundExecute from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.json';
import cashReceipt from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_issue_cash_receipt.json';
import directIssue from '../../../layouts/admin/partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue.json';
import manualTransaction from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json';
import adminClaimRequest from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_admin_claim_request.json';

type Node = Record<string, any>;

/** 창마다 닫힐 때 비워야 하는 `_global` 키와, 렌더 시험에 넣을 예시 값 */
const CASES: Array<{ label: string; modal: Node; keys: Record<string, any> }> = [
    {
        label: '환불 실행 창',
        modal: refundExecute,
        keys: {
            claimRefundExecuteContext: { refundAccountRequired: true },
            claimRefundExecuteBankCode: '004',
            claimRefundExecuteBankAccount: '123-456-7890',
            claimRefundExecuteBankHolder: '홍길동',
            claimRefundExecuteForce: false,
        },
    },
    { label: '관리자 현금영수증 발급 창', modal: cashReceipt, keys: { adminCashReceiptForm: { identifier: '01012345678' } } },
    {
        label: '쿠폰 직접 발급 창',
        modal: directIssue,
        keys: {
            directIssueSearchResults: [{ uuid: 'u1', name: '홍길동' }],
            directIssueSelectedUsers: [{ uuid: 'u1', name: '홍길동' }],
            directIssueSearchKeyword: '홍길',
            directIssueSearchResultsKeyword: '홍길',
        },
    },
    { label: '마일리지 수동 지급/차감 창', modal: manualTransaction, keys: { mileageManual: { selectedMember: { name: '홍길동', email: 'h@example.com' } } } },
    { label: '관리자 직권 클레임 접수 창', modal: adminClaimRequest, keys: { adminClaimRequest: { refundBank: { holder: '홍길동', account: '123' } } } },
];

const isNode = (x: any): boolean => Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string');

/**
 * 액션 목록을 실행 순서대로 펼친다(sequence·conditions·성공 콜백 안까지).
 *
 * @param actions 액션 목록
 * @param out 결과
 * @returns 펼친 액션 목록
 */
function flatten(actions: any[], out: any[] = []): any[] {
    for (const a of actions ?? []) {
        if (!a || typeof a !== 'object') continue;
        out.push(a);
        flatten(callbacksOf(a, 'actions'), out);
        flatten(callbacksOf(a, 'onSuccess'), out);
    }

    return out;
}

/**
 * 액션이 주어진 키를 모두 null 로 비우는 전역 setState 인지.
 *
 * @param a 액션
 * @param keys 키 목록
 * @returns 모두 비우면 true
 */
function clearsAll(a: any, keys: string[]): boolean {
    return a?.handler === 'setState' && a.params?.target === 'global' && keys.every((k) => Object.prototype.hasOwnProperty.call(a.params, k) && a.params[k] === null);
}

/**
 * 창 안에서 unmount 로 키를 비우는 노드를 찾고, 그 노드가 조건·반복 조상 없이 늘 붙어 있는지 함께 돌려준다.
 *
 * @param modal 창 루트
 * @param keys 키 목록
 * @returns [{node, guarded}]
 */
function unmountClearers(modal: Node, keys: string[]): Array<{ node: Node; guarded: boolean }> {
    const out: Array<{ node: Node; guarded: boolean }> = [];
    const walk = (x: any, guarded: boolean): void => {
        if (!isNode(x)) return;
        const g = guarded || (x !== modal && (x.if !== undefined || x.iteration !== undefined));
        if (flatten(x.lifecycle?.onUnmount ?? []).some((a) => clearsAll(a, keys))) out.push({ node: x, guarded: g });
        (x.children ?? []).forEach((c: any) => walk(c, g));
    };
    walk(modal, false);

    return out;
}

/**
 * 창 안의 「취소」 단추(클릭이 창 닫기뿐이고 요청·실행 핸들러가 없는 단추)를 모은다.
 *
 * @param modal 창 루트
 * @returns 단추 노드 목록
 */
function cancelButtons(modal: Node): Node[] {
    const out: Node[] = [];
    const walk = (x: any): void => {
        if (!isNode(x)) return;
        const click = flatten(actionsOf(x).filter((a: any) => (a.type ?? a.event) === 'click'));
        if (x.name === 'Button' && click.some((a) => a.handler === 'closeModal') && !click.some((a) => a.handler === 'apiCall' || /\.(execute|submit)/.test(String(a.handler)))) out.push(x);
        (x.children ?? []).forEach(walk);
    };
    walk(modal);

    return out;
}

describe.each(CASES)('$label — 어느 갈래로 닫든 이름 공간을 비운다', ({ modal, keys }) => {
    const keyList = Object.keys(keys);
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', () => React.createElement('i', { 'aria-hidden': 'true' }));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // @scenario surface=admin_modal, close_path=header_x_overlay_esc
    // @effects admin_modal_namespace_cleared_on_every_close
    it('X·바깥 클릭·Esc 갈래 — 조건·반복 밖 본문 노드의 unmount 가 키를 모두 null 로 비운다', () => {
        const clearers = unmountClearers(modal, keyList);
        expect(clearers.length).toBeGreaterThan(0);
        expect(clearers.every((c) => !c.guarded)).toBe(true);
    });

    // @scenario surface=admin_modal, close_path=cancel_button
    // @effects admin_modal_namespace_cleared_on_every_close
    it('취소 단추 — 창 닫기 뒤에 키를 직접 비운다', () => {
        const buttons = cancelButtons(modal);
        expect(buttons.length).toBeGreaterThan(0);
        for (const b of buttons) {
            const click = flatten(actionsOf(b).filter((a: any) => (a.type ?? a.event) === 'click'));
            const close = click.findIndex((a) => a.handler === 'closeModal');
            const clear = click.findIndex((a) => clearsAll(a, keyList));
            expect(clear, JSON.stringify(click.map((a) => a.handler))).toBeGreaterThan(close);
        }
    });

    // 본문 노드 unmount 는 엔진 closeModal(X·바깥 클릭·Esc)이 창 본문을 내릴 때의 갈래다.
    // @scenario surface=admin_modal, close_path=header_x_overlay_esc
    // @effects admin_modal_namespace_cleared_on_every_close
    it('렌더 —본문 노드가 내려가면(창 닫힘) 키가 null 이 된다', async () => {
        const { node } = unmountClearers(modal, keyList)[0] ?? { node: { type: 'basic', name: 'Div' } };
        const { if: _if, ...body } = node;
        const utils = createLayoutTest(
            {
                version: '1.0.0',
                layout_name: 'test/admin-modal-close-clears',
                components: [{ type: 'basic', name: 'Div', if: '{{_global.__open === true}}', children: [{ ...body, children: [] }] }],
            } as any,
            { componentRegistry: registry as any, locale: 'ko', initialState: { _global: { __open: true, ...keys } } },
        );
        await utils.render();
        expect(utils.getState()._global[keyList[0]]).not.toBeNull();

        utils.setState('__open', false, 'global');
        await utils.rerender();
        await waitFor(() => keyList.forEach((k) => expect(utils.getState()._global[k]).toBeNull()));
        utils.cleanup();
    });
});

describe('성공 갈래 — 창 닫기 뒤 이름 공간 정리', () => {
    // 매니페스트 exclusions 가 이 조합을 cross product 에서 뺐다(쿠폰 직접 발급 창만 새로 단언).
    // @scenario surface=admin_modal, close_path=success
    // @effects admin_modal_namespace_cleared_on_every_close
    it('쿠폰 직접 발급 성공 갈래는 창을 닫은 뒤 회원 이름 공간을 비운다', () => {
        const keyList = Object.keys(CASES[2].keys);
        const all = flatten(collectClicks(directIssue));
        const success = all.find((a) => a.handler === 'apiCall' && String(a.target).includes('issue-direct'));
        const chain = flatten(callbacksOf(success, 'onSuccess'));
        const close = chain.findIndex((a) => a.handler === 'closeModal');
        const clear = chain.findIndex((a) => clearsAll(a, keyList));
        expect(close).toBeGreaterThanOrEqual(0);
        expect(clear).toBeGreaterThan(close);
    });
});

/**
 * 창 안 모든 클릭 액션을 모은다.
 *
 * @param modal 창 루트
 * @returns 클릭 액션 목록
 */
function collectClicks(modal: Node): any[] {
    const out: any[] = [];
    const walk = (x: any): void => {
        if (!isNode(x)) return;
        out.push(...actionsOf(x).filter((a: any) => (a.type ?? a.event) === 'click'));
        (x.children ?? []).forEach(walk);
    };
    walk(modal);

    return out;
}

// e2e:allow 레이아웃 정적 구조 + 엔진 렌더(Tab·Enter·Space 로 배송지 고르기) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(주문 상세 배송지 변경·주문서 배송지 관리 창 키보드 선택)이 덮는다.
/**
 * @file address-select-keyboard.test.tsx
 * @description 사용자 배송지 선택 창 2곳에서 저장 배송지를 키보드로 고를 수 있는지
 *
 * 마이페이지 주문 상세 「배송지 변경」 창(저장 배송지 탭)과 주문서 「배송지 관리」 창의 배송지 카드가 클릭만 받는
 * `<div>` 라 Tab 으로 닿지 않았다. 두 창 모두 배송지를 고르지 않으면 확인 단추(「변경」·「선택」)가 잠기므로
 * 키보드 사용자는 저장 배송지로 진행할 수 없었다(7.1.1 출시 전 실측 대조).
 *
 * 고침: 카드(또는 카드의 정보 영역)를 `<button type="button" aria-pressed>` 로 그린다 — 주문서 결제수단·입금 계좌 카드와
 * 후기 별점의 관례. 「배송지 관리」 카드는 안에 수정·삭제 단추가 있어 카드 전체를 단추로 만들면 단추가 겹치므로, 정보
 * 영역만 단추로 두고 그 단추의 클릭이 카드의 고르기 동작으로 올라가게 한다(수정·삭제는 종전대로 전파를 막는다).
 *
 * 판정: 창(Modal) 안 반복 행에서 클릭을 받는 요소는 단추·링크이거나, 자기 동작 없이 클릭을 그 행으로 올려 보내는
 * 단추(선택 상태 aria-pressed)를 품는다. 모집단은 basic 레이아웃 JSON 전체의 창 안 반복 행에서 파생한다.
 *
 * @scenario surface=user_modal
 * @effects saved_address_selectable_by_keyboard
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import React from 'react';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import userEvent from '@testing-library/user-event';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    waitFor,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import changeAddressModal from '../../../layouts/partials/mypage/orders/_modal_change_address.json';
import addressManageModal from '../../../layouts/partials/shop/_modal_address_manage.json';

type Node = Record<string, any>;

const ROOT = resolve(__dirname, '../../..');

/** 스스로 키보드로 닿고 Enter·Space 로 누를 수 있는 부품 */
const KEYBOARD_NATIVE = new Set(['Button', 'A', 'Link', 'Input', 'Select', 'Textarea', 'Checkbox', 'Toggle', 'Label']);

const isNode = (x: any): boolean =>
    Boolean(x && typeof x === 'object' && !Array.isArray(x) && typeof x.name === 'string' && (x.type || x.props || x.children));
const eventsOf = (n: any): string[] => actionsOf(n).map((a: any) => String(a?.type ?? a?.event ?? ''));

/**
 * 트리에서 조건을 만족하는 노드를 모두 모은다.
 *
 * @param x 탐색 대상
 * @param pred 조건
 * @param out 결과
 * @returns 찾은 노드 목록
 */
function collect(x: any, pred: (n: Node) => boolean, out: Node[] = []): Node[] {
    if (Array.isArray(x)) {
        x.forEach((y) => collect(y, pred, out));

        return out;
    }
    if (!x || typeof x !== 'object') return out;
    if (isNode(x) && pred(x)) out.push(x);
    Object.values(x).forEach((v) => collect(v, pred, out));

    return out;
}

/**
 * 행이 자기 클릭을 키보드로 받을 대리 단추(자기 동작 없이 클릭을 행으로 올려 보내고, 선택 상태를 aria-pressed 로 알리는 단추)를 품는지.
 *
 * @param row 클릭을 받는 행
 * @returns 품으면 true
 */
function hasKeyboardProxy(row: Node): boolean {
    return collect(row.children, (n) => n.name === 'Button' && !eventsOf(n).includes('click') && n.props?.['aria-pressed'] !== undefined).length > 0;
}

/**
 * 창 안 반복 행에서 클릭을 받는데 키보드로 고를 수 없는 요소를 모은다.
 *
 * @param root 레이아웃 JSON
 * @returns 요약 목록
 */
function clickOnlyRowsInModal(root: any): string[] {
    const out: string[] = [];
    const walk = (x: any, inModal: boolean, inRepeat: boolean): void => {
        if (Array.isArray(x)) return x.forEach((y) => walk(y, inModal, inRepeat));
        if (!x || typeof x !== 'object') return;
        const modal = inModal || x.name === 'Modal';
        const repeat = inRepeat || Boolean(x.iteration);
        if (isNode(x) && modal && repeat && eventsOf(x).includes('click') && !KEYBOARD_NATIVE.has(x.name) && !hasKeyboardProxy(x)) {
            out.push(`${x.name} .${String(x.props?.className ?? '').slice(0, 50)}`);
        }
        Object.values(x).forEach((v) => walk(v, modal, repeat));
    };
    walk(root, false, false);

    return out;
}

/**
 * 디렉터리 아래 JSON 경로를 모은다.
 *
 * @param dir 시작 디렉터리
 * @returns JSON 경로 목록
 */
function jsonFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((entry) => {
        const path = join(dir, entry);
        if (statSync(path).isDirectory()) return jsonFiles(path);

        return path.endsWith('.json') ? [path] : [];
    });
}

const ADDRESSES = [
    { id: 11, name: '집', recipient_name: '홍길동', recipient_phone: '010-1111-2222', country_code: 'KR', zipcode: '06236', address: '서울 강남구 테헤란로 1', address_detail: '1층', is_default: true, abilities: {} },
    { id: 22, name: '회사', recipient_name: '김영희', recipient_phone: '010-3333-4444', country_code: 'KR', zipcode: '04524', address: '서울 중구 세종대로 2', address_detail: '2층', is_default: false, abilities: {} },
];

/** 반복 행(저장 배송지 목록) 노드 — 창 노드 없이 목록만 렌더한다 */
const changeRows = collect(changeAddressModal, (n) => String(n.iteration?.source ?? '').includes('userAddresses'))[0];
const manageRows = collect(addressManageModal, (n) => String(n.iteration?.source ?? '').includes('userAddresses'))[0];

/**
 * 목록 노드 하나를 엔진으로 렌더한다.
 *
 * @param rows 반복 행 노드
 * @param registry 부품 레지스트리
 * @returns 테스트 유틸
 */
async function renderRows(rows: Node, registry: MockComponentRegistry) {
    const utils = createLayoutTest(
        {
            version: '1.0.0',
            layout_name: 'test/address-select-keyboard',
            data_sources: [{ id: 'userAddresses', endpoint: '/api/modules/sirsoft-ecommerce/user/addresses', method: 'GET', auto_fetch: true }],
            components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'rows' }, children: [rows] }],
        } as any,
        { componentRegistry: registry as any, locale: 'ko', initialState: { _global: { currentUser: { uuid: 'u-1' } } } },
    );
    utils.mockApi('userAddresses', { response: { success: true, data: { addresses: { data: ADDRESSES } } } });
    await utils.render();
    await waitFor(() => expect(screen.getAllByText(/홍길동/).length).toBeGreaterThan(0));

    return utils;
}

describe('사용자 배송지 선택 창 키보드 선택', () => {
    let registry: MockComponentRegistry;

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    // @scenario surface=user_modal
    // @effects saved_address_selectable_by_keyboard
    it('basic 창 안 반복 행에서 클릭을 받는 요소는 키보드로 고를 수 있다', () => {
        const files = jsonFiles(join(ROOT, 'layouts'));
        expect(files.length).toBeGreaterThan(50);
        const missing = files.flatMap((path) =>
            clickOnlyRowsInModal(JSON.parse(readFileSync(path, 'utf-8'))).map((s) => `${relative(ROOT, path).replace(/\\/g, '/')}: ${s}`),
        );
        expect(missing, missing.join('\n')).toEqual([]);
    });

    // @scenario surface=user_modal
    // @effects saved_address_selectable_by_keyboard
    it('「배송지 변경」 창 — 저장 배송지 카드는 aria-pressed 단추이고 Tab·Enter·Space 로 고른다', async () => {
        const utils = await renderRows(changeRows, registry);
        const user = userEvent.setup();
        const buttons = screen.getAllByRole('button');
        expect(buttons).toHaveLength(2);
        expect(buttons[0].getAttribute('aria-pressed')).toBe('false');

        await user.tab();
        expect(document.activeElement).toBe(buttons[0]);
        await user.keyboard('{Enter}');
        await waitFor(() => expect(utils.getState()._global.selectedAddressId).toBe(11));
        // 테스트 렌더는 상태가 바뀌어도 스스로 다시 그리지 않는다 — 명시적으로 다시 그려 고른 표시를 본다
        await utils.rerender();
        expect(screen.getAllByRole('button').map((b) => b.getAttribute('aria-pressed'))).toEqual(['true', 'false']);

        await user.tab();
        expect(document.activeElement).toBe(screen.getAllByRole('button')[1]);
        await user.keyboard(' ');
        await waitFor(() => expect(utils.getState()._global.selectedAddressId).toBe(22));
        utils.cleanup();
    });

    // @scenario surface=user_modal
    // @effects saved_address_selectable_by_keyboard
    it('「배송지 관리」 창 — 카드 정보 영역 단추로 Tab·Enter 로 고르고, 고른 배송지 객체도 담긴다', async () => {
        const utils = await renderRows(manageRows, registry);
        const user = userEvent.setup();
        const buttons = screen.getAllByRole('button');
        expect(buttons).toHaveLength(2);
        expect(buttons[1].getAttribute('aria-pressed')).toBe('false');

        await user.tab();
        await user.tab();
        expect(document.activeElement).toBe(buttons[1]);
        await user.keyboard('{Enter}');
        // 7.1.1: 창 안 선택은 창 고유 전역 이름 공간(_global.checkoutAddressModal)에 담긴다
        await waitFor(() => expect(utils.getState()._global.checkoutAddressModal?.selectedAddressId).toBe(22));
        expect(utils.getState()._global.checkoutAddressModal?.selectedAddressObj?.id).toBe(22);
        await utils.rerender();
        expect(screen.getAllByRole('button').map((b) => b.getAttribute('aria-pressed'))).toEqual(['false', 'true']);
        utils.cleanup();
    });
});

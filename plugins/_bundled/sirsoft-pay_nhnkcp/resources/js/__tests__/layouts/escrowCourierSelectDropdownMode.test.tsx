// e2e:allow 실제 관리자 템플릿 Select 를 엔진 렌더러로 그려 에스크로 배송 등록의 택배사 칸이 목록형(단추 + 목록 상자)이고 고른 값이 담기는지 잰다 — 브라우저 축은 출시 전 화면 재실측(주문 상세 › 에스크로 배송 등록)이 덮는다
/**
 * @file escrowCourierSelectDropdownMode.test.tsx
 * @description NHN KCP 에스크로 배송 등록(주문 상세 결제 조회 조각)의 「택배사」 칸이 브라우저 기본 선택상자가 아니라 관리자 템플릿의 목록형 드롭다운으로 그려진다
 *
 * 관리자 템플릿 Select 는 `props.options` 가 있으면 단추(`aria-haspopup=listbox`) + 목록 상자로, 없으면 브라우저 기본
 * `<select>` 로 그린다. 택배사 칸은 `<select>` 바로 아래 `<option>` 을 적어 기본 선택상자였고 값도 묶지 않아 브라우저가 고른
 * 항목을 스스로 들고 있었다. 목록형은 값을 스스로 들지 않으므로 담아 둔 값(`_local._ked_deli_corp`)을 단추에 보여야 한다.
 *
 * @scenario-manifest tests/scenarios/escrow-courier-select-dropdown-mode.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { Select } from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/basic/Select';
import fragment from '../../../extensions/admin_order_payment_query.json';

type Node = Record<string, any>;

/**
 * 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 조건
 * @param acc 결과 누적
 * @returns 찾은 노드 목록
 */
function findAll(node: unknown, match: (n: Node) => boolean, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((child) => findAll(child, match, acc));

        return acc;
    }
    if (!node || typeof node !== 'object') return acc;
    if (match(node as Node)) acc.push(node as Node);
    Object.values(node as Node).forEach((value) => findAll(value, match, acc));

    return acc;
}

const courierSelects = findAll(fragment, (n) => n.name === 'Select' && JSON.stringify(n.actions ?? []).includes('_ked_deli_corp'));

let utils: ReturnType<typeof createLayoutTest> | null = null;

afterEach(() => {
    utils?.cleanup();
    utils = null;
    document.body.innerHTML = '';
});

/**
 * 택배사 칸을 실물 Select 로 그린다.
 *
 * @param canUpdate 주문 수정 권한
 * @returns 목록형 단추
 */
async function drawCourier(canUpdate: boolean): Promise<HTMLButtonElement> {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Select', Select as any);
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/nhnkcp-escrow-courier', components: [courierSelects[0]] } as any,
        { componentRegistry: registry as any, locale: 'ko', initialState: { _local: {} }, initialData: { order: { data: { abilities: { can_update: canUpdate } } } } },
    );
    const { container } = await utils.render();
    expect(container.querySelector('select'), '브라우저 기본 선택상자').toBeNull();
    const triggers = container.querySelectorAll<HTMLButtonElement>('button[aria-haspopup="listbox"]');
    expect(triggers).toHaveLength(1);

    return triggers[0];
}

describe('NHN KCP 에스크로 배송 등록 — 택배사 칸 목록형 드롭다운', () => {
    // @scenario screen=escrow_courier
    // @effects select_renders_listbox_dropdown, select_choice_reaches_change_action
    it('택배사 칸은 목록형이고 고른 택배사 코드가 담겨 단추에 남는다', async () => {
        expect(courierSelects).toHaveLength(1);
        expect(Array.isArray(courierSelects[0].props?.options), 'props.options').toBe(true);
        expect(findAll(courierSelects[0].children ?? [], (n) => n.name === 'Option')).toHaveLength(0);

        const trigger = await drawCourier(true);
        expect(trigger.textContent).toContain('escrow_delivery_courier_placeholder');

        fireEvent.click(trigger);
        const option = await waitFor(() => {
            const hit = screen.getAllByRole('option').find((o) => /escrow_couriers\.cj$/.test(o.textContent ?? ''));
            expect(hit).toBeTruthy();

            return hit!;
        });
        fireEvent.click(option);
        await waitFor(() => expect(utils!.getState()._local._ked_deli_corp).toBe('04'));
        expect(trigger.textContent).toMatch(/escrow_couriers\.cj$/);
    });

    // @scenario screen=escrow_courier
    // @effects select_renders_listbox_dropdown
    it('주문 수정 권한이 없으면 택배사 칸 단추가 잠긴다', async () => {
        const trigger = await drawCourier(false);
        expect(trigger.disabled).toBe(true);
    });
});

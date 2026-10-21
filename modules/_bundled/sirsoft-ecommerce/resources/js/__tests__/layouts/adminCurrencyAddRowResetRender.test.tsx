// e2e:allow 실제 DynamicRenderer·ActionDispatcher 로 통화 추가 줄(추가 → 이름 입력 → 취소 → 다시 추가)을 실행해 잰다 — 브라우저 축은 2026-10-18 재실측이 덮는다
/**
 * @file adminCurrencyAddRowResetRender.test.tsx
 * @description 이커머스 환경설정 › 언어·통화 — 통화 추가 줄을 다시 열면 앞서 입력한 통화 이름이 남지 않는다 (7.1.1 출시 전 실측)
 *
 * 정적 JSON 단언(같은 sequence 에 null 덮기가 있는지)만으로는 2026-10-18 재실측의 실패(이름이 그대로 남음)를 잡지 못했다.
 * 그래서 실제 레이아웃 노드(「통화 추가」 단추·추가 줄)를 실제 렌더러·액션 실행기로 렌더하고, 다국어 이름 칸은 운영
 * MultilingualInput 과 같은 onChange 계약(`{ target: { name, value: {언어: 값} } }`)의 대역으로 둔다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { fireEvent, screen, act } from '@testing-library/react';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import tab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_language_currency.json';
import table from '../../../layouts/admin/partials/admin_ecommerce_settings/_currency_exchange_table.json';

type Node = Record<string, any>;

/** 조건에 맞는 노드를 모두 모은다. */
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

const addButton = findAll(tab, (n) => n.id === 'add_currency_button')[0];
const addRowSource = findAll(table, (n) => n.name === 'Tr' && typeof n.if === 'string' && n.if.includes('isAddingCurrency'))[0];

/** 추가 줄 사본 — 「취소」 단추(토스트 없이 줄을 닫는 단추)에 시험용 표식을 단다 */
const addRow: Node = JSON.parse(JSON.stringify(addRowSource ?? null));
const cancelNode = findAll(addRow, (n) => n.name === 'Button' && /"isAddingCurrency":false/.test(JSON.stringify(n.actions ?? [])) && !/"toast"/.test(JSON.stringify(n.actions ?? [])))[0];
if (cancelNode) cancelNode.props = { ...(cancelNode.props ?? {}), 'data-testid': 'cancel' };

/** 운영 MultilingualInput 과 같은 onChange 계약의 대역 — 한국어 칸 하나만 그린다 */
const MultilingualStub: React.FC<any> = ({ name, value, onChange }) =>
    React.createElement('input', {
        'data-testid': `ml-${name}`,
        value: value?.ko ?? '',
        onChange: (e: any) => onChange?.({ target: { name, value: { ...(value ?? {}), ko: e.target.value } }, _changedKeys: ['ko'] }),
    });

/** 표 행·칸 대역 — jsdom 에서 table 밖 tr 경고를 피한다 */
const Box: React.FC<any> = ({ children, className }) => React.createElement('div', { className }, children);

let registry: MockComponentRegistry;

beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
    registry.register('composite', 'MultilingualInput', MultilingualStub);
    registry.register('basic', 'Tr', Box);
    registry.register('basic', 'Td', Box);
});

/** 디바운스·연속 실행이 끝날 때까지 흘려보낸다 */
async function flush(ms = 600) {
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, ms));
    });
}

describe('통화 추가 줄 — 다시 열면 앞 입력이 남지 않는다', () => {
    // @scenario surface=currency_add_row
    // @effects currency_add_row_name_cleared_despite_autobinding_overrides
    it('노드가 있다', () => {
        expect(addButton).toBeTruthy();
        expect(addRow).toBeTruthy();
        expect(cancelNode).toBeTruthy();
    });

    // @scenario surface=currency_add_row
    // @effects currency_add_row_name_cleared_despite_autobinding_overrides
    it('추가 → 이름 입력 → 취소 → 다시 추가 하면 이름 칸이 비어 있다', async () => {
        const local = { form: { language_currency: { currencies: [{ code: 'KRW', is_default: true }] } } };
        const utils = createLayoutTest({
            version: '1.0.0',
            layout_name: 'test/admin-ecommerce-currency-add-row',
            components: [
                {
                    id: 'settings_content',
                    type: 'basic',
                    name: 'Div',
                    dataKey: 'form',
                    trackChanges: true,
                    children: [
                        { ...addButton, props: { ...(addButton.props ?? {}), 'data-testid': 'add' } },
                        addRow,
                    ],
                },
            ],
        } as any, {
            componentRegistry: registry as any,
            locale: 'ko',
            initialState: { _global: { _local: local }, _local: local },
        });
        await utils.render();
        (window as any).G7Core.state.get = () => ({ ...utils.getState()._global });

        // 운영 자동바인딩은 입력을 실물 `G7Core.state.setLocal(patch, { render: false })` 로 저장소 B 에 쓴다
        // (DynamicRenderer performStateUpdate). 하네스 기본 setLocal 은 얕은 대입이라 그 경로가 남기는
        // __g7SetLocalOverrideKeys·__g7ForcedLocalFields 가 생기지 않아 결함이 재현되지 않았다 — 실물로 바꾼다.
        // 저장소 B 는 TemplateApp 계약(최상위 키 얕은 병합, render:false 면 다시 그리지 않음)대로 둔다.
        let storeB: Record<string, any> = { _local: JSON.parse(JSON.stringify(local)) };
        (window as any).__templateApp = {
            getGlobalState: () => storeB,
            setGlobalState: (updates: Record<string, any>) => { storeB = { ...storeB, ...updates }; },
        };
        const harnessG7Core = (window as any).G7Core;
        initializeG7CoreGlobals({
            getState: () => ({}),
            transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
            responsiveManager: {},
            webSocketManager: { subscribe: () => 's', unsubscribe: () => {}, leaveChannel: () => {}, disconnect: () => {}, isInitialized: () => true, getSubscriptionCount: () => 0 },
        } as any);
        const realSetLocal = (window as any).G7Core.state.setLocal;
        (window as any).G7Core = harnessG7Core;
        (window as any).G7Core.state.setLocal = realSetLocal;

        fireEvent.click(screen.getByTestId('add'));
        await flush();
        fireEvent.change(screen.getByTestId('ml-newCurrency.name'), { target: { value: '원화' } });
        await flush();
        expect((screen.getByTestId('ml-newCurrency.name') as HTMLInputElement).value).toBe('원화');

        fireEvent.click(screen.getByTestId('cancel'));
        await flush();
        expect(screen.queryByTestId('ml-newCurrency.name')).toBeNull();

        fireEvent.click(screen.getByTestId('add'));
        await flush();

        expect((screen.getByTestId('ml-newCurrency.name') as HTMLInputElement).value, '다시 연 추가 줄의 이름 칸').toBe('');
        utils.cleanup();
        delete (window as any).__templateApp;
        delete (window as any).__g7SetLocalOverrideKeys;
        delete (window as any).__g7ForcedLocalFields;
        delete (window as any).__g7PendingLocalState;
    });
});

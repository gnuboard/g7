// e2e:allow 실물 DynamicRenderer·ActionDispatcher 로 자동바인딩 칸의 change 동작이 받는 `$event` 를 잰다 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(관리자 메뉴 관리 › 새 메뉴 › 「사용자 정의 메뉴」 선택 → 주소 칸 채움)이 덮는다
/**
 * @file DynamicRenderer.autoBindingChangeEventTarget.test.tsx
 * @description 폼 자동바인딩 칸에 붙은 change 동작의 `$event.target` 이 선택 항목·data-* 를 잃던 결함 (7.1.1)
 *
 * 자동바인딩 칸(`name` + 폼 `data-key`)의 change 는 엔진이 상태를 쓴 뒤 레이아웃 동작을 부른다. 그때 넘기는 이벤트를 엔진이
 * `{ target: { value, name, type, checked } }` 로 줄여 만들어(디바운스 뒤에도 읽히게 하려는 사본), `$event.target.selectedOptions`·
 * `$event.target.dataset`·`$event.target.files` 가 늘 비었다. 관리자 메뉴 관리 「사용자 정의 메뉴」 선택칸의
 * `url: $event.target.selectedOptions[0]?.dataset?.url || …` 이 빈 값이 되어 주소 칸이 채워지지 않았다(전역·지역 폼 공통).
 * 자동바인딩이 없는 칸은 엔진의 표현식용 이벤트 스냅샷(`toExpressionEvent`)이 이 필드들을 이미 싣는다.
 *
 * 고침: 그 사본을 같은 스냅샷 변환기로 만들고 value 만 엔진이 쓴 값으로 둔다(동작이 붙은 칸에서만).
 *
 * @scenario-manifest tests/scenarios/autobinding-change-event-target.yaml
 *
 * @effects autobinding_change_action_sees_selected_option_dataset
 */

import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { render, fireEvent, screen, act } from '@testing-library/react';
import DynamicRenderer from '../DynamicRenderer';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { createMockComponentRegistryWithBasics } from './utils/layoutTestUtils';

/** 네이티브 select — 선택 항목에 data-url 을 단다(관리자 메뉴 폼의 사용자 정의 메뉴 선택칸과 같은 모양) */
const NativeSelect: React.FC<any> = ({ name, value, onChange }) => React.createElement('select', {
    'data-testid': 'custom-menu', name, value: value ?? '', onChange,
}, [
    React.createElement('option', { key: 'none', value: '' }, '-'),
    React.createElement('option', { key: 'a', value: 'shop.main', 'data-url': '/shop' }, 'shop'),
    React.createElement('option', { key: 'b', value: 'board.free', 'data-url': '/board/free' }, 'board'),
]);

function mount(dataKey: '_global.formData' | 'formData') {
    const registry = createMockComponentRegistryWithBasics() as any;
    registry.register('basic', 'Select', NativeSelect);
    const bindingEngine = new DataBindingEngine();
    const translationEngine = new TranslationEngine();
    const actionDispatcher = new ActionDispatcher({});
    const isGlobal = dataKey.startsWith('_global');

    let globalState: Record<string, any> = { formData: { url: '', custom_menu_key: null } };
    let localState: Record<string, any> = { formData: { url: '', custom_menu_key: null } };
    let rerender: (() => void) | null = null;
    const setGlobal = (updates: any) => {
        const next = typeof updates === 'function' ? updates(globalState) : updates;
        globalState = { ...globalState, ...next };
        rerender?.();
    };
    actionDispatcher.setGlobalStateUpdater((updates: any) => setGlobal(updates));
    (window as any).__templateApp = { getGlobalState: () => ({ ...globalState }) };
    (window as any).G7Core = {
        state: {
            get: () => ({ ...globalState }),
            set: setGlobal,
            getLocal: () => localState,
            setLocal: (updates: any) => { localState = { ...localState, ...updates }; },
        },
    };

    const scope = isGlobal ? '_global' : '_local';
    const componentDef: any = {
        id: 'root', type: 'layout', name: 'Fragment',
        children: [{
            id: 'menu_form', type: 'basic', name: 'Div', dataKey,
            children: [{
                id: 'custom_menu', type: 'basic', name: 'Select', props: { name: 'custom_menu_key' },
                // 관리자 메뉴 폼(`admin_menu_list/_panel_form`)의 동작과 같은 식 — 고른 항목의 data-url 로 주소 칸을 채운다
                actions: [{
                    type: 'change', handler: 'setState',
                    params: {
                        target: isGlobal ? 'global' : 'local',
                        'formData.url': `{{$event.target.selectedOptions[0]?.dataset?.url || ${scope}.formData?.url}}`,
                        'formData.picked_label': '{{$event.target.selectedOptions[0]?.text ?? ""}}',
                    },
                }],
            }],
        }],
    };

    const element = () => React.createElement(DynamicRenderer, {
        componentDef,
        dataContext: { _global: globalState, _local: localState, _globalSetState: setGlobal },
        translationContext: { templateId: 't', locale: 'ko' },
        registry, bindingEngine, translationEngine, actionDispatcher,
    });
    const utils = render(element());
    rerender = () => utils.rerender(element());

    return { global: () => globalState, local: () => globalState._local ?? {} };
}

describe('[case:components-form-autobinding-change-event-target] 자동바인딩 칸의 change 동작이 받는 $event.target', () => {
    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).__templateApp;
        (window as any).__g7PendingLocalState = null;
        (window as any).__g7ForcedLocalFields = undefined;
    });

    // @scenario store=global
    // @effects autobinding_change_action_sees_selected_option_dataset
    it('전역 폼: 선택 항목의 data-url·글자를 읽어 주소 칸을 채우고, 자동바인딩 값도 그대로 쓴다', async () => {
        const form = mount('_global.formData');
        await act(async () => {
            fireEvent.change(screen.getByTestId('custom-menu'), { target: { value: 'board.free' } });
        });

        // 종전: selectedOptions 가 없어 url 은 "" 그대로
        expect(form.global().formData.url).toBe('/board/free');
        expect(form.global().formData.picked_label).toBe('board');
        expect(form.global().formData.custom_menu_key).toBe('board.free');
    });

    // @scenario store=local
    // @effects autobinding_change_action_sees_selected_option_dataset
    it('지역 폼도 같다', async () => {
        const form = mount('formData');
        await act(async () => {
            fireEvent.change(screen.getByTestId('custom-menu'), { target: { value: 'shop.main' } });
        });

        expect(form.local().formData?.url).toBe('/shop');
        expect(form.local().formData?.custom_menu_key).toBe('shop.main');
    });
});

// e2e:allow 확장 조각 엔진 렌더(키보드 Esc 로 통화 목록 닫기·초점 복귀) 단언 — 브라우저 축은 7.1.1 출시 전 캠페인 재실측(관리자·사용자 헤더 통화 선택 Esc)이 덮는다.
/**
 * @file headerCurrencySelectorEscape.test.tsx
 * @description 헤더 통화·배송국가 선택 목록이 Esc 로 닫히고 초점이 트리거 단추로 돌아오는지 (관리자·사용자 조각)
 *
 * 통화 선택 목록은 바깥 클릭(`fixed inset-0` 배경)·트리거 재클릭·항목 선택으로만 닫혔다 — 키보드 사용자는 Tab 으로
 * 목록 안에 들어간 뒤 빠져나올 방법이 Esc 가 아니었다. 같은 일을 하는 템플릿 부품(관리자 LanguageSelector·Dropdown·
 * ActionMenu)은 Esc 로 닫고 초점을 트리거로 돌려준다(`closeMenu(true)`).
 *
 * 고침(레이아웃): 열린 목록(role=listbox) 에 `<on type="keydown" key="Escape" :stopPropagation="true">` — 초점을 트리거로
 * 옮긴 뒤(`sirsoft-ecommerce.focusElement`, 목록 항목이 사라지기 전에) 닫는다. 트리거에 초점이 있을 때도 열려 있으면 닫는다.
 * 사용자 기반 레이아웃 뿌리의 Esc(모바일 서랍 닫기)와 같은 `<on type="keydown" key="Escape">` 관례를 따른다.
 *
 * 전파(7.1.1, 2026-10-19): 트리거의 Esc 는 목록이 열려 있을 때만 바깥(기반 레이아웃 뿌리의 서랍 닫기)으로 올라가지 않아야 한다 —
 * 종전에는 막지 않아 서랍 안에서 Esc 한 번에 목록과 서랍이 함께 닫혔다. 액션 키 `stopPropagation` 은 액션의 `if` 보다 먼저
 * 적용돼 조건을 못 받으므로(닫혀 있을 때도 막는다) `sirsoft-ecommerce.stopEventPropagation` 진입점 액션에 `if` 를 단다.
 * 시험은 조각을 뿌리 Esc(열려 있을 때만 서랍 닫기)를 가진 상자 안에 그린다.
 *
 * 축 요약(마커 아님 — 평문): surface=admin_extension. 매핑은 테스트마다 단다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import userEvent from '@testing-library/user-event';
import {
    createLayoutTest,
    createMockComponentRegistryWithBasics,
    screen,
    waitFor,
    type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { handlerMap } from '../../handlers';

type Node = Record<string, any>;

/**
 * 확장 조각 JSON 의 주입 루트(통화 선택 상자)를 읽는다.
 *
 * @param name 조각 파일 이름
 * @returns 주입 루트 노드
 */
function fragmentRoot(name: string): Node {
    const file = path.resolve(__dirname, `../../../extensions/${name}.json`);
    const json = JSON.parse(fs.readFileSync(file, 'utf8'));

    return json.injections[0].components[0];
}

const CURRENCIES = [
    { code: 'KRW', symbol: '₩', flag: '🇰🇷' },
    { code: 'USD', symbol: '$', flag: '🇺🇸' },
];

/**
 * 조각 루트를 엔진으로 렌더한다(목록이 열린 상태 또는 닫힌 상태로).
 *
 * @param root 조각 루트
 * @param registry 부품 레지스트리
 * @param open 처음에 목록을 펼칠지
 * @returns 테스트 유틸
 */
async function renderSelector(root: Node, registry: MockComponentRegistry, open: boolean) {
    const { slot, ...node } = root;
    // 실물 구조: 조각은 헤더 슬롯(SlotContainer)에 주입되고, 슬롯은 root id 를 `{id}__{컨테이너 id}` 로 스코프한다
    // (admin_basic SlotContainer `scopeSlotChildDef` — HTML id 중복 방지). 그래서 DOM 에 `#ext_…_currency_selector` 는 없다.
    // 헤더에는 통화 선택보다 앞에 같은 꼴 트리거(언어 선택 `aria-haspopup=listbox`)가 있다 — 문서 전체에서 찾으면 그쪽으로 간다.
    const scoped = { ...node, id: `${node.id}__header_right_slot` };
    const languageTrigger = { type: 'basic', name: 'Button', props: { type: 'button', 'aria-haspopup': 'listbox', 'aria-label': 'language' }, text: 'KO' };
    // 기반 레이아웃 뿌리(관리자 `admin_layout_root`·사용자 `user_layout_root`)의 Esc 서랍 닫기와 같은 꼴 —
    // 열려 있을 때만 닫는다. 조각은 그 뿌리 안(헤더·서랍)에 그려지므로 트리거의 keydown 이 여기로 올라온다.
    const layoutRoot = {
        type: 'basic',
        name: 'Div',
        id: 'layout_root',
        props: {},
        actions: [{
            type: 'keydown',
            key: 'Escape',
            handler: 'conditions',
            conditions: [{ if: '{{_global.drawerOpen}}', then: { handler: 'setState', params: { target: 'global', drawerOpen: false } } }],
        }],
        children: [languageTrigger, scoped],
    };
    const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'test/header-currency-escape', components: [layoutRoot] } as any,
        {
            componentRegistry: registry as any,
            locale: 'ko',
            initialState: {
                _local: { showCurrencyDropdown: open },
                _global: { availableCurrencies: CURRENCIES, availableShippingCountries: [], preferredCurrency: 'KRW', modules: {}, drawerOpen: true },
            },
        },
    );
    await utils.render();

    return utils;
}

/**
 * Tab 을 눌러 대상에 초점이 갈 때까지 이동한다.
 *
 * @param user userEvent
 * @param target 대상
 */
async function tabTo(user: ReturnType<typeof userEvent.setup>, target: Element): Promise<void> {
    for (let i = 0; i < 10 && document.activeElement !== target; i++) await user.tab();
    expect(document.activeElement).toBe(target);
}

/**
 * 통화 선택 트리거 — 앞의 언어 트리거와 구별해 통화 상자 안에서 찾는다.
 *
 * @returns 트리거 요소
 */
function currencyTrigger(): HTMLElement {
    return document.querySelector<HTMLElement>('[data-currency-selector] [aria-haspopup="listbox"]') ?? document.querySelectorAll<HTMLElement>('[aria-haspopup="listbox"]')[1];
}

/**
 * 테스트 렌더의 지역 상태 — 컴포넌트 상태 저장소가 없으면 target=local 이 `_global._local` 로 폴백해 담긴다.
 *
 * @param utils 테스트 유틸
 * @returns 지역 상태
 */
function localOf(utils: { getState: () => any }): any {
    const s = utils.getState();

    return { ...(s._local ?? {}), ...(s._global?._local ?? {}) };
}

describe.each([
    ['관리자', 'header-currency-selector-admin'],
    ['사용자', 'header-currency-selector-user'],
])('%s 헤더 통화 선택 — Esc 로 닫기·초점 복귀', (_label, name) => {
    let registry: MockComponentRegistry;
    const root = fragmentRoot(name);

    beforeEach(() => {
        registry = createMockComponentRegistryWithBasics();
        registry.register('basic', 'Icon', ({ className }: { className?: string }) => React.createElement('i', { className, 'aria-hidden': 'true' }));
        // 테스트 렌더는 모듈 핸들러를 싣지 않는다 — 운영처럼 `sirsoft-ecommerce.` 접두로 함께 등록한다
        const original = ActionDispatcher.prototype.registerHandler;
        vi.spyOn(ActionDispatcher.prototype, 'registerHandler').mockImplementation(function (this: any, handlerName: string, handler: any, options?: any) {
            original.call(this, handlerName, handler, options);
            if (handlerName === 'toast') {
                Object.entries(handlerMap).forEach(([n, h]) => original.call(this, `sirsoft-ecommerce.${n}`, h as any));
            }
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('목록 항목에 초점이 있을 때 Esc → 목록이 닫히고 초점이 트리거 단추로 돌아온다', async () => {
        const utils = await renderSelector(root, registry, true);
        const user = userEvent.setup();
        const trigger = currencyTrigger();
        expect(trigger).toBeTruthy();
        expect(screen.getAllByRole('option').length).toBeGreaterThanOrEqual(2);

        await tabTo(user, trigger);
        await user.tab();
        expect(document.activeElement?.getAttribute('role')).toBe('option');

        await user.keyboard('{Escape}');
        await waitFor(() => expect(localOf(utils).showCurrencyDropdown).toBe(false));
        expect(document.activeElement).toBe(trigger);
        await utils.rerender();
        expect(screen.queryAllByRole('option')).toHaveLength(0);
        // 앞의 언어 트리거가 아니라 통화 트리거로 돌아온다(실측: 관리자는 언어 단추, 사용자는 BODY 로 갔다)
        expect(document.activeElement).toBe(currencyTrigger());
        expect(document.activeElement?.getAttribute('aria-label')).not.toBe('language');
        utils.cleanup();
    });

    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('트리거에 초점이 있고 목록이 열려 있을 때 Esc → 닫히고 초점은 트리거에 남는다', async () => {
        const utils = await renderSelector(root, registry, true);
        const user = userEvent.setup();
        const trigger = currencyTrigger();

        await tabTo(user, trigger);
        await user.keyboard('{Escape}');
        await waitFor(() => expect(localOf(utils).showCurrencyDropdown).toBe(false));
        expect(document.activeElement).toBe(trigger);
        utils.cleanup();
    });

    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('목록이 닫혀 있을 때 트리거에서 Esc 는 아무것도 열거나 바꾸지 않는다', async () => {
        const utils = await renderSelector(root, registry, false);
        const user = userEvent.setup();

        await tabTo(user, currencyTrigger());
        await user.keyboard('{Escape}');
        expect(localOf(utils).showCurrencyDropdown).toBe(false);
        expect(screen.queryAllByRole('option')).toHaveLength(0);
        utils.cleanup();
    });

    // 서랍 안에서 목록을 연 채 트리거에서 Esc 를 누르면 목록만 닫혀야 한다 — 종전에는 전파를 막지 않아 뿌리의
    // Esc(서랍 닫기)까지 올라가 한 번에 목록과 서랍이 함께 닫혔다(7.1.1).
    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('목록이 열린 채 트리거에서 Esc → 목록만 닫히고 바깥(서랍) Esc 로 올라가지 않는다', async () => {
        const utils = await renderSelector(root, registry, true);
        const user = userEvent.setup();
        const trigger = currencyTrigger();

        await tabTo(user, trigger);
        await user.keyboard('{Escape}');
        // 서랍이 먼저 — 전파되면 뿌리가 곧바로 서랍을 닫는다
        expect(utils.getState()._global.drawerOpen).toBe(true);
        await waitFor(() => expect(localOf(utils).showCurrencyDropdown).toBe(false));
        expect(document.activeElement).toBe(trigger);
        expect(utils.getState()._global.drawerOpen).toBe(true);
        utils.cleanup();
    });

    // 닫혀 있을 때의 Esc 는 조각이 가로채지 않는다 — 서랍의 Esc 닫기가 그대로 받는다.
    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('목록이 닫힌 채 트리거에서 Esc → 전파되어 바깥(서랍) Esc 가 서랍을 닫는다', async () => {
        const utils = await renderSelector(root, registry, false);
        const user = userEvent.setup();

        await tabTo(user, currencyTrigger());
        await user.keyboard('{Escape}');
        await waitFor(() => expect(utils.getState()._global.drawerOpen).toBe(false));
        expect(localOf(utils).showCurrencyDropdown).toBe(false);
        utils.cleanup();
    });
});

describe('focusElement·stopEventPropagation 핸들러', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    // Esc 로 닫을 때 초점을 「자기 상자」의 트리거로 돌려주는 핸들러 단위 단언.
    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('closest 로 이벤트가 난 요소의 상자를 찾아 그 안의 selector 에 초점을 준다(같은 조각이 둘 그려져도 자기 상자)', () => {
        document.body.innerHTML = `
            <div class="box" id="a"><button aria-haspopup="listbox">A</button><button id="a-opt">a</button></div>
            <div class="box" id="b"><button aria-haspopup="listbox">B</button><button id="b-opt">b</button></div>`;
        const origin = document.getElementById('b-opt')!;
        const ok = (handlerMap as any).focusElement({ params: { selector: '[aria-haspopup="listbox"]', closest: '.box' } }, { event: { target: origin } });
        expect(ok).toBe(true);
        expect(document.activeElement?.textContent).toBe('B');
    });

    // 열린 목록의 Esc 만 멈추는 진입점 핸들러의 단위 단언 — 받은 이벤트의 전파를 멈추고, 이벤트가 없으면 아무것도 하지 않는다.
    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('stopEventPropagation 은 받은 이벤트의 전파를 멈추고, 이벤트가 없으면 false 를 돌려준다', () => {
        const stop = vi.fn();
        expect((handlerMap as any).stopEventPropagation({}, { event: { stopPropagation: stop } })).toBe(true);
        expect(stop).toHaveBeenCalledTimes(1);
        expect((handlerMap as any).stopEventPropagation({}, {})).toBe(false);
        expect((handlerMap as any).stopEventPropagation({}, undefined)).toBe(false);
    });

    // 초점 복귀 대상이 없을 때 초점을 엉뚱한 곳으로 옮기지 않는다.
    // @scenario surface=admin_extension
    // @effects currency_dropdown_closes_on_escape
    it('selector 가 없거나 대상을 못 찾으면 false 를 돌려주고 초점을 건드리지 않는다', () => {
        document.body.innerHTML = '<button id="x">x</button>';
        document.getElementById('x')!.focus();
        expect((handlerMap as any).focusElement({ params: {} }, {})).toBe(false);
        expect((handlerMap as any).focusElement({ params: { selector: '#none' } }, {})).toBe(false);
        expect(document.activeElement?.id).toBe('x');
    });
});

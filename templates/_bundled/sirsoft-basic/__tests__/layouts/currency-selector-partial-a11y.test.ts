// e2e:allow 굽기 산출물(JSON) 속성·식 평가 전용 — 이 조각은 지금 basic 레이아웃 어디에도 포함되지 않아(머리글 통화 선택은 이커머스 확장 header-currency-selector-user 가 주입한다) 브라우저로 열 화면이 없다. 확장 쪽 브라우저 축은 keyboard-click-only-areas.spec.ts 가 덮는다.
/**
 * @file currency-selector-partial-a11y.test.ts
 * @description 통화 선택 조각(`partials/common/_currency_selector`)의 여닫기 단추·통화 항목이 키보드·화면 낭독기로 읽힌다 (7.1.1)
 *
 * 여닫기 단추는 `type="button"` 에 목록이 열렸는지를 `aria-expanded` 로 알리고, 통화 항목은 단추마다 `type="button"` 에
 * 지금 고른 통화인지를 `aria-pressed` 로 알린다. 바깥 덮개(`fixed inset-0`)는 마우스용이라 단추가 아니다 — 키보드는 여닫기 단추로
 * 같은 목록을 닫는다. 런타임은 굽기 산출물(.json)을 읽으므로 산출물에서 속성과 식을 평가한다.
 *
 * @scenario-manifest tests/scenarios/currency-selector-partial-a11y.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type Node = Record<string, any>;

const layout: Node = JSON.parse(
    fs.readFileSync(path.resolve(__dirname, '../../layouts/partials/common/_currency_selector.json'), 'utf8'),
);

/**
 * 트리를 깊이 우선으로 평탄화한다.
 *
 * @param node 시작 노드
 * @param acc 누적
 * @returns 노드 목록
 */
function flatten(node: Node | undefined, acc: Node[] = []): Node[] {
    if (!node) return acc;
    acc.push(node);
    (node.children ?? []).forEach((c: Node) => flatten(c, acc));

    return acc;
}

/**
 * `{{식}}` 하나를 주어진 문맥으로 평가한다.
 *
 * @param expr 식
 * @param scope 식이 읽는 이름들
 * @returns 평가 결과
 */
function evalExpr(expr: unknown, scope: Record<string, unknown>): unknown {
    const m = /^\{\{([\s\S]*)\}\}$/.exec(String(expr).trim());
    expect(m, `식 하나로 된 값이 아니다: ${String(expr)}`).not.toBeNull();
    // eslint-disable-next-line no-new-func
    return new Function(...Object.keys(scope), `return (${m![1]});`)(...Object.values(scope));
}

const nodes = flatten(layout);
const buttons = nodes.filter((n) => n.name === 'Button');
/** 여닫기 단추 = showCurrencyDropdown 을 뒤집는 click 액션을 가진 단추 */
const trigger = buttons.find((n) => (n.actions ?? []).some((a: Node) => a.handler === 'setState' && a.params?.showCurrencyDropdown === '{{!_local.showCurrencyDropdown}}'));
/** 통화 항목 = 통화 목록 반복 안의 단추 */
const optionHost = nodes.find((n) => String(n.iteration?.source ?? '').includes('availableCurrencies'));
const option = flatten(optionHost).find((n) => n.name === 'Button');

describe('통화 선택 조각 — 단추 종류와 상태 알림', () => {
    // @scenario control=toggle
    // @effects currency_selector_controls_announce_state
    it('여닫기 단추는 type="button" 이고 목록이 열렸는지를 aria-expanded 로 알린다', () => {
        expect(trigger, '여닫기 단추를 찾지 못했다').toBeDefined();
        expect(trigger!.props?.type).toBe('button');
        expect(evalExpr(trigger!.props?.['aria-expanded'], { _local: { showCurrencyDropdown: true } })).toBe(true);
        expect(evalExpr(trigger!.props?.['aria-expanded'], { _local: {} })).toBe(false);
    });

    // @scenario control=option
    // @effects currency_selector_controls_announce_state
    it('통화 항목은 type="button" 이고 지금 고른 통화만 aria-pressed 가 참이다', () => {
        expect(option, '통화 항목 단추를 찾지 못했다').toBeDefined();
        expect(option!.props?.type).toBe('button');
        const pressed = option!.props?.['aria-pressed'];
        expect(evalExpr(pressed, { _global: { preferredCurrency: 'USD' }, currency: { code: 'USD' } })).toBe(true);
        expect(evalExpr(pressed, { _global: { preferredCurrency: 'USD' }, currency: { code: 'KRW' } })).toBe(false);
    });

    // @scenario control=toggle
    // @effects currency_selector_controls_announce_state
    it('조각 안 모든 단추가 type 을 적는다(폼 안에 놓여도 제출되지 않는다)', () => {
        expect(buttons.length).toBeGreaterThanOrEqual(2);
        expect(buttons.filter((b) => b.props?.type !== 'button')).toEqual([]);
    });
});

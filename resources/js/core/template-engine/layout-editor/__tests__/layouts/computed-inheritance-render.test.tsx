/**
 * computed-inheritance-render.test.tsx — 상속 병합 computed 의 런타임 평가
 *
 *
 * 부모(_admin_base 공통 computed) + 자식(덮은 키) 병합본이 실제 마운트 시:
 *   ① **자식 덮은 키 = 자식 식 평가** (shallow merge 런타임 일치)
 *   ② 미덮은 부모 키 = 부모 식 평가
 *   ③ `__computedSource` 는 computed 키가 아니며 미평가(런타임 무영향)
 *   ④ 되돌린 키는 재마운트 시 부모 값 평가
 *   ⑤ 상호참조(cross-ref) 위상정렬 — 다른 computed 키를 참조하는 식이 정상 평가
 * 를 검증한다.
 *
 * 엔진 사실:
 * - 병합 SSoT = LayoutService.mergeComputed = `array_merge($parent, $child)` → 동일
 *   키는 자식 식이 부모를 덮는다(shallow, LayoutService.php:475-478).
 * - `__computedSource` 출처맵은 with_source_meta(편집기)에서만 부착. 운영 렌더 경로의
 *   computed 맵에는 없다. 만에 하나 섞여도 computed 키처럼 평가되지 않아야 한다.
 * - 런타임 평가 = TemplateApp.calculateComputed(TemplateApp.ts:3892-3927): computed
 *   맵을 순회하며 각 `{{...}}` 식을 DataBindingEngine 평가, 결과를 `_computed` 에 누적
 *   → 뒤 식이 앞 키를 참조 가능(cross-ref). 본 테스트는 그 실제 평가 엔진
 *   (DataBindingEngine.evaluateExpression)으로 병합본을 평가한다.
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '../../../DataBindingEngine';

const engine = new DataBindingEngine();

/**
 * TemplateApp.calculateComputed(TemplateApp.ts:3899-3924) 의 런타임 평가 루프를 실제
 * 엔진(DataBindingEngine.evaluateExpression)으로 재현한다. computed 맵을 선언 순서대로
 * 순회하며 각 `{{...}}` 식을 평가하고 결과를 `_computed` 에 누적한다(cross-ref 지원).
 *
 * 비-`{{}}` 문자열은 순수 문자열로 그대로 사용(calculateComputed:3913-3914) — 즉
 * `__computedSource` 같은 출처 객체/문자열은 식이 아니므로 평가되지 않는다.
 */
function evaluateComputed(
  computed: Record<string, unknown>,
  baseContext: Record<string, unknown>,
): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  for (const [key, expression] of Object.entries(computed)) {
    if (typeof expression === 'string' && expression.startsWith('{{') && expression.endsWith('}}')) {
      const inner = expression.slice(2, -2).trim();
      const ctx = { ...baseContext, _computed: result };
      result[key] = engine.evaluateExpression(inner, ctx as any);
    } else {
      // 식이 아닌 값(순수 문자열/출처 객체 등)은 그대로 — 평가 대상 아님.
      result[key] = expression;
    }
  }
  return result;
}

/** 부모(_admin_base) 공통 computed */
const PARENT_COMPUTED = {
  // 부모: 활성 메뉴 키
  activeMenu: "{{_global.currentMenu ?? 'dashboard'}}",
  // 부모: 페이지 타이틀 접두
  titlePrefix: "{{'관리자'}}",
};

describe('computed 상속 병합 런타임 평가', () => {
  it('① 자식이 덮은 키는 병합본에서 자식 식으로 평가된다 (shallow merge 일치)', () => {
    // 자식이 titlePrefix 를 덮음 → 병합본은 자식 식 사용.
    const child = { titlePrefix: "{{'쇼핑몰'}}" };
    const merged = { ...PARENT_COMPUTED, ...child };

    const computed = evaluateComputed(merged, { _global: {} });

    expect(computed.titlePrefix).toBe('쇼핑몰'); // 자식 식
    expect(computed.activeMenu).toBe('dashboard'); // 미덮은 부모 식
  });

  it('② 미덮은 부모 키는 부모 식으로 평가된다', () => {
    const merged = { ...PARENT_COMPUTED }; // 자식 추가 없음
    const computed = evaluateComputed(merged, { _global: { currentMenu: 'orders' } });

    expect(computed.activeMenu).toBe('orders');
    expect(computed.titlePrefix).toBe('관리자');
  });

  it('③ __computedSource 출처맵은 computed 키처럼 평가되지 않는다 (런타임 무영향)', () => {
    // 운영 경로엔 없지만, 출처 객체가 섞여도 식이 아니므로 평가 대상에서 제외되고
    // 실제 computed 결과(activeMenu/titlePrefix)는 영향받지 않는다.
    const mergedWithMeta = {
      ...PARENT_COMPUTED,
      __computedSource: { activeMenu: 'base', titlePrefix: 'base' },
    } as Record<string, unknown>;

    const computed = evaluateComputed(mergedWithMeta, { _global: { currentMenu: 'users' } });

    // 실제 computed 키는 정상 평가
    expect(computed.activeMenu).toBe('users');
    expect(computed.titlePrefix).toBe('관리자');
    // __computedSource 는 출처 객체 그대로 — 식 평가 결과(문자열 등)로 변형되지 않음.
    expect(computed.__computedSource).toEqual({ activeMenu: 'base', titlePrefix: 'base' });
  });

  it('③-b 운영 병합본(출처맵 부재)과 결과가 동일하다 (메타 무영향 회귀)', () => {
    const plain = { ...PARENT_COMPUTED };
    const withMeta = { ...PARENT_COMPUTED, __computedSource: { activeMenu: 'base' } } as Record<string, unknown>;
    const ctx = { _global: { currentMenu: 'settings' } };

    const a = evaluateComputed(plain, ctx);
    const b = evaluateComputed(withMeta, ctx);

    expect(b.activeMenu).toBe(a.activeMenu);
    expect(b.titlePrefix).toBe(a.titlePrefix);
  });

  it('④ 덮기를 되돌리면(자식 키 제거) 재마운트 시 다시 부모 식이 평가된다', () => {
    // 덮음 상태: 자식이 titlePrefix 덮음
    const overridden = { ...PARENT_COMPUTED, titlePrefix: "{{'쇼핑몰'}}" };
    expect(evaluateComputed(overridden, { _global: {} }).titlePrefix).toBe('쇼핑몰');

    // 되돌림: 자식 키 제거 → 병합본은 부모만 → 부모 식 평가로 복귀
    const reverted = { ...PARENT_COMPUTED };
    expect(evaluateComputed(reverted, { _global: {} }).titlePrefix).toBe('관리자');
  });

  it('⑤ 상호참조(cross-ref) 식이 위상 순서대로 평가된다', () => {
    // fullTitle 이 앞서 평가된 titlePrefix(_computed) 를 참조 — 선언 순서대로 누적.
    const merged = {
      ...PARENT_COMPUTED,
      pageName: "{{'주문 목록'}}",
      fullTitle: "{{_computed.titlePrefix + ' · ' + _computed.pageName}}",
    };

    const computed = evaluateComputed(merged, { _global: {} });

    expect(computed.titlePrefix).toBe('관리자');
    expect(computed.pageName).toBe('주문 목록');
    expect(computed.fullTitle).toBe('관리자 · 주문 목록');
  });

  it('⑤-b 자식이 덮은 키를 참조하는 식은 자식 값을 반영한다', () => {
    // 자식이 titlePrefix 를 덮으면, 그 키를 참조하는 fullTitle 도 자식 값을 본다.
    const merged = {
      ...PARENT_COMPUTED,
      titlePrefix: "{{'쇼핑몰'}}", // 자식 덮음
      pageName: "{{'상품'}}",
      fullTitle: "{{_computed.titlePrefix + ' · ' + _computed.pageName}}",
    };

    const computed = evaluateComputed(merged, { _global: {} });
    expect(computed.fullTitle).toBe('쇼핑몰 · 상품');
  });
});

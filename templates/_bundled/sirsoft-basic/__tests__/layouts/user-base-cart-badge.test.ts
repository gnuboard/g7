/**
 * @file user-base-cart-badge.test.ts
 * @description 모바일 헤더 장바구니 배지 — 세 자리(99+)일 때 글꼴을 줄인다
 *
 * 배지는 20px 고정 원(`w-5 h-5`)에 12px 굵은 글씨라 `99+` 의 실제 폭(23.28px)이 원을 넘쳤다
 * (7.1.0 출시 전 점검 실측). 100 이상일 때만 9px 로 줄이고 1~99 는 12px 그대로 둔다.
 */

import { describe, it, expect } from 'vitest';
import userBase from '../../layouts/_user_base.json';

type Json = Record<string, any>;

/** 조건에 맞는 첫 노드를 찾는다. */
function find(tree: unknown, pred: (n: Json) => boolean): Json | undefined {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (pred(obj)) {
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return found;
}

/** `{{식}}` 을 cartCount 값 하나로 평가한다. */
function evalWith(expr: string, cartCount: number): unknown {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(expr).trim())!;
  // eslint-disable-next-line no-new-func
  return new Function('_global', `return (${m[1]});`)({ cartCount });
}

const tokens = (cls: unknown): string[] => String(cls ?? '').split(/\s+/).filter(Boolean);

describe('모바일 헤더 장바구니 배지', () => {
  const badge = find(userBase, (n) => typeof n.text === 'string' && n.text.includes("_global.cartCount > 99 ? '99+'"));

  /**
   * @scenario defect=count_badge_triple_digit_overflow
   * @effects triple_digit_badge_font_shrinks
   */
  it('배지는 class-map 으로 세 자리일 때만 text-[9px] 를 쓴다', () => {
    expect(badge, '모바일 장바구니 배지가 없습니다.').toBeDefined();
    const map = badge!.classMap;
    expect(map, 'classMap 이 없습니다.').toBeDefined();
    expect(tokens(map.base)).toEqual(expect.arrayContaining(['w-5', 'h-5', 'rounded-full', 'font-bold', 'bg-blue-500']));
    expect(tokens(map.base)).not.toContain('text-xs');

    const variantFor = (count: number): string => {
      const key = String(evalWith(map.key, count));
      return map.variants?.[key] ?? map.default;
    };
    expect(tokens(variantFor(100))).toContain('text-[9px]');
    expect(tokens(variantFor(1000))).toContain('text-[9px]');
    expect(tokens(variantFor(99))).toContain('text-xs');
    expect(tokens(variantFor(9))).toContain('text-xs');
  });

  /**
   * @scenario defect=count_badge_triple_digit_overflow
   * @effects triple_digit_badge_font_shrinks
   */
  it('표시 글자는 100 이상에서 99+ 다', () => {
    expect(evalWith(badge!.text, 150)).toBe('99+');
    expect(evalWith(badge!.text, 99)).toBe(99);
  });
});

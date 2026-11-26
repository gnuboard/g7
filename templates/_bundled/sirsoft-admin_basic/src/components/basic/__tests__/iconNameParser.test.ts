/**
 * @file iconNameParser.test.ts
 * @description 아이콘 이름 해석기 — FA5 단축형(fas·far·fab·fal·fad)과 FA6 장형(fa-solid …) 스타일 토큰을 한 번만 읽는다
 *
 * 종전 Icon 은 장형만 읽어 `fas fa-users` 를 `fas fa-fas fa-users` 로 조합했고 `far`·`fab` 는 스타일을 잃었다.
 * 해석기가 스타일·글리프·추가 클래스로 나누는 표를 전 행 잠근다. 같은 내용의 사본이 sirsoft-basic 에 있다.
 *
 * @scenario-manifest tests/scenarios/icon-component-baseline.yaml
 */

import { describe, it, expect } from 'vitest';
import { parseIconName, STYLE_TOKENS } from '../iconNameParser';
import type { ParsedIconName } from '../iconNameParser';

/** 입력 → 기대 해석 (스타일 null 은 이름에 스타일 토큰이 없다는 뜻 — Icon 이 iconStyle prop 을 쓴다) */
const TABLE: Array<[string, ParsedIconName]> = [
  ['users', { style: null, glyph: 'users', extraClasses: [] }],
  ['fa-users', { style: null, glyph: 'users', extraClasses: [] }],
  ['fas fa-users', { style: 'solid', glyph: 'users', extraClasses: [] }],
  ['far fa-star', { style: 'regular', glyph: 'star', extraClasses: [] }],
  ['fab fa-github', { style: 'brands', glyph: 'github', extraClasses: [] }],
  ['fal fa-x', { style: 'light', glyph: 'x', extraClasses: [] }],
  ['fad fa-x', { style: 'duotone', glyph: 'x', extraClasses: [] }],
  ['fa-solid fa-pen', { style: 'solid', glyph: 'pen', extraClasses: [] }],
  ['fa-regular fa-pen-to-square', { style: 'regular', glyph: 'pen-to-square', extraClasses: [] }],
  ['fas fa-spinner fa-spin', { style: 'solid', glyph: 'spinner', extraClasses: ['fa-spin'] }],
  ['  fas   fa-bell ', { style: 'solid', glyph: 'bell', extraClasses: [] }],
  ['fas fa-star', { style: 'solid', glyph: 'star', extraClasses: [] }],
];

describe('parseIconName — 스타일 토큰 표', () => {
  /**
   * @scenario component=icon_parser
   * @effects style_prefix_tokens_resolved_once
   */
  it.each(TABLE)('%j 를 스타일·글리프·추가 클래스로 나눈다', (input, expected) => {
    expect(parseIconName(input)).toEqual(expected);
  });

  /**
   * @scenario component=icon_parser
   * @effects style_prefix_tokens_resolved_once
   */
  it.each(TABLE)('%j 의 글리프와 추가 클래스에 스타일 토큰이 남지 않는다', (input) => {
    const parsed = parseIconName(input);
    const leftovers = [parsed.glyph, ...parsed.extraClasses];

    for (const token of leftovers) {
      expect(STYLE_TOKENS[token], token).toBeUndefined();
      expect(STYLE_TOKENS[`fa-${token}`], token).toBeUndefined();
      expect(token).not.toMatch(/^(fas|far|fab|fal|fad|solid|regular|brands|light|duotone)$/);
    }
  });
});

describe('parseIconName — 경계', () => {
  /**
   * @scenario component=icon_parser
   * @effects style_prefix_tokens_resolved_once
   */
  it('스타일 토큰이 둘이면 첫 토큰만 스타일로 쓰고 나머지는 버린다', () => {
    expect(parseIconName('far fas fa-user')).toEqual({ style: 'regular', glyph: 'user', extraClasses: [] });
    expect(parseIconName('fa-brands fab fa-github')).toEqual({ style: 'brands', glyph: 'github', extraClasses: [] });
  });

  /**
   * @scenario component=icon_parser
   * @effects style_prefix_tokens_resolved_once
   */
  it('추가 클래스는 순서를 지키고 fa- 접두를 벗기지 않는다', () => {
    expect(parseIconName('fas fa-circle-notch fa-spin fa-fw')).toEqual({
      style: 'solid',
      glyph: 'circle-notch',
      extraClasses: ['fa-spin', 'fa-fw'],
    });
  });

  /**
   * @scenario component=icon_parser
   * @effects style_prefix_tokens_resolved_once
   */
  it.each([[''], ['   '], [null], [undefined]])('빈 값(%j)은 스타일·글리프 없이 돌려준다', (input) => {
    expect(parseIconName(input as string | null | undefined)).toEqual({ style: null, glyph: '', extraClasses: [] });
  });

  /**
   * @scenario component=icon_parser
   * @effects style_prefix_tokens_resolved_once
   */
  it('STYLE_TOKENS 는 단축형 5종과 장형 5종을 모두 같은 스타일로 읽는다', () => {
    expect(STYLE_TOKENS).toEqual({
      fas: 'solid',
      'fa-solid': 'solid',
      far: 'regular',
      'fa-regular': 'regular',
      fal: 'light',
      'fa-light': 'light',
      fad: 'duotone',
      'fa-duotone': 'duotone',
      fab: 'brands',
      'fa-brands': 'brands',
    });
  });
});

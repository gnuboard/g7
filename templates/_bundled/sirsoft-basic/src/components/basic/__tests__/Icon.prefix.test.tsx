/**
 * @file Icon.prefix.test.tsx
 * @description 사용자 템플릿 Icon — 이름 속 스타일 토큰 해석 (#381)
 *
 * 종전 Icon 은 스타일 토큰을 읽지 않아 `fas fa-x` 를 `fas fa-fas fa-x` 로, `far fa-x` 를 `fas fa-far fa-x` 로
 * 그렸다(스타일을 잃었다). 이제 iconNameMap 정확 일치를 먼저 보고(맨 이름 출력 보존) 없을 때만 해석한다.
 * 출력 클래스 전체를 정확히 비교한다.
 *
 * @scenario-manifest tests/scenarios/icon-prefix-parsing.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { Icon } from '../Icon';
import { iconNameMap } from '../IconTypes';

const PREFIX_TABLE: Array<[string, 'regular' | undefined, string]> = [
  ['users', undefined, 'fas fa-users'],
  ['fa-users', undefined, 'fas fa-users'],
  ['fas fa-users', undefined, 'fas fa-users'],
  ['far fa-star', undefined, 'far fa-star'],
  ['fab fa-github', undefined, 'fab fa-github'],
  ['fal fa-x', undefined, 'fal fa-x'],
  ['fad fa-x', undefined, 'fad fa-x'],
  ['fa-solid fa-pen', undefined, 'fas fa-pen'],
  ['fa-regular fa-pen-to-square', undefined, 'far fa-pen-to-square'],
  ['fas fa-spinner fa-spin', undefined, 'fas fa-spinner fa-spin'],
  ['  fas   fa-bell ', undefined, 'fas fa-bell'],
  ['fas fa-star', 'regular', 'fas fa-star'],
];

/** 출력에 나오면 안 되는 「스타일 토큰에 fa- 를 덧붙인」 클래스 */
const DOUBLED_STYLE_CLASS = /^fa-(fas|far|fab|fal|fad|solid|regular|brands|light|duotone)$/;

/**
 * Icon 을 그려 className 을 돌려준다.
 *
 * @param name 아이콘 이름
 * @param iconStyle 스타일 prop
 * @returns className
 */
function classOf(name: string, iconStyle?: 'regular'): string {
  const { container } = render(<Icon name={name} {...(iconStyle ? { iconStyle } : {})} />);

  return container.querySelector('i')?.className ?? '';
}

describe('Icon(sirsoft-basic) — 이름 속 스타일 토큰 해석 (#381)', () => {
  /**
   * @scenario component=icon
   * @effects style_prefix_tokens_resolved_once
   */
  it.each(PREFIX_TABLE)('name=%j iconStyle=%s → className %j', (name, iconStyle, expected) => {
    expect(classOf(name, iconStyle)).toBe(expected);
  });

  /**
   * @scenario component=icon
   * @effects style_prefix_tokens_resolved_once
   */
  it.each(PREFIX_TABLE)('name=%j 출력에 겹친 스타일 클래스가 없고 스타일 클래스는 하나다', (name, iconStyle) => {
    const tokens = classOf(name, iconStyle).split(/\s+/).filter(Boolean);

    expect(tokens.filter((token) => DOUBLED_STYLE_CLASS.test(token))).toEqual([]);
    expect(tokens.filter((token) => ['fas', 'far', 'fab', 'fal', 'fad'].includes(token))).toHaveLength(1);
  });
});

describe('Icon(sirsoft-basic) — iconNameMap 맨 이름 출력 보존', () => {
  /** 표에 있는 맨 이름 중 값이 키와 다른 것(settings → cog 등)을 섞어 고른다 */
  const BARE_KEYS = ['home', 'settings', 'x-circle', 'times', 'ellipsis-v'];

  /**
   * @scenario component=icon
   * @effects icon_name_map_bare_keys_unchanged
   */
  it.each(BARE_KEYS)('맨 이름 %s 는 iconNameMap 이 주는 글리프로 그린다 (`fas {map}`)', (key) => {
    const mapped = iconNameMap[key];

    expect(mapped, key).toBeDefined();
    expect(classOf(key)).toBe(`fas ${mapped}`);
  });

  /**
   * @scenario component=icon
   * @effects icon_name_map_bare_keys_unchanged
   */
  it('맨 이름에 iconStyle prop 을 주면 그 스타일을 쓴다', () => {
    expect(classOf('home', 'regular')).toBe(`far ${iconNameMap.home}`);
  });

  /**
   * @scenario component=icon
   * @effects icon_name_map_bare_keys_unchanged
   */
  it('표 전체의 맨 이름이 스타일 클래스 하나 + fa-글리프 로 그려진다', () => {
    for (const [key, mapped] of Object.entries(iconNameMap)) {
      expect(classOf(key), key).toBe(`fas ${String(mapped).startsWith('fa-') ? mapped : `fa-${mapped}`}`);
    }
  });
});

/**
 * @file Toggle.test.tsx
 * @description 템플릿 설정 화면 스위치(boolean 칸)의 안쪽 checkbox id 를 잠근다 (#408).
 *
 * 종전에는 name 을 그대로 id 로 써서 같은 name 의 스위치가 여럿이면 화면에 같은 id 가 여러 개 생겼다.
 * 인스턴스마다 다른 useId 를 앞에 붙이고, name 에 섞인 공백·한글은 id 에서 걷어 낸다.
 *
 * 시나리오: case=settings_layout
 *
 * 효과: settings_toggle_input_id_unique_per_instance
 */

import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { Toggle } from '../Toggle';

describe('settings/parts/Toggle — input id 고유화', () => {
  /**
   * @scenario case=settings_layout
   * @effects settings_toggle_input_id_unique_per_instance
   */
  it('같은 name 의 스위치 3개는 input id 3개가 모두 다르고, 공백·한글 name 은 id 에서 걷힌다', () => {
    const { container } = render(
      <>
        <Toggle name="show_banner" ariaLabel="a" />
        <Toggle name="show_banner" ariaLabel="b" />
        <Toggle name="show_banner" ariaLabel="c" />
        <Toggle name="배너 표시 2" ariaLabel="d" />
      </>
    );
    const inputs = Array.from(container.querySelectorAll('input[type="checkbox"]')) as HTMLInputElement[];
    const ids = inputs.map((el) => el.id);
    expect(new Set(ids.slice(0, 3)).size).toBe(3);
    ids.forEach((id) => expect(id).toMatch(/^[A-Za-z0-9_-]+$/));
    expect(ids[0].endsWith('-show_banner')).toBe(true);
    expect(ids[3].endsWith('-2')).toBe(true);
    expect(inputs.map((el) => el.name)).toEqual(['show_banner', 'show_banner', 'show_banner', '배너 표시 2']);
  });
});

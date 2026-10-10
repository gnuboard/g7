/**
 * propertyModalAriaLabel.test.ts — 속성 편집 창의 접근 가능한 이름
 *
 * 7.1.0 실측(영역 I2): 속성 편집 창은 화면 제목이 「속성 편집: 문단」인데 접근성 트리의 창 이름(aria-label)이
 * 「속성 편집: {name}」 자리표시 원문이었다 — 창을 여는 쪽이 이름 인자 없이 번역 키를 풀었다.
 *
 * @since engine-v1.78.3
 */

import { describe, it, expect } from 'vitest';
import { propertyModalAriaLabel } from '../../utils/propertyModalAriaLabel';

const dict: Record<string, string> = {
  'layout_editor.property_modal.title': '속성 편집: {name}',
  'layout_editor.property_modal.element': '요소',
  'editor.component.paragraph': '문단',
};
const t = (key: string, params?: Record<string, string | number>): string =>
  (dict[key] ?? key).replace(/\{(\w+)\}/g, (_m, k: string) => String(params?.[k] ?? `{${k}}`));

describe('propertyModalAriaLabel — 속성 편집 창 이름', () => {
  // @scenario source=fixture
  // @effects property_modal_aria_label_has_component_name
  it('편집 역량의 `$t:` 이름을 풀어 끼운다', () => {
    const spec = { componentCapabilities: { P: { label: '$t:editor.component.paragraph' } } } as never;
    expect(propertyModalAriaLabel(t, spec, { name: 'P' })).toBe('속성 편집: 문단');
  });

  // @scenario source=fixture
  // @effects property_modal_aria_label_has_component_name
  it('역량 이름이 없으면 컴포넌트 이름, 그것도 없으면 「요소」 — 자리표시 원문은 남지 않는다', () => {
    expect(propertyModalAriaLabel(t, null, { name: 'Span' })).toBe('속성 편집: Span');
    expect(propertyModalAriaLabel(t, null, {})).toBe('속성 편집: 요소');
    expect(propertyModalAriaLabel(t, null, {})).not.toContain('{name}');
  });
});

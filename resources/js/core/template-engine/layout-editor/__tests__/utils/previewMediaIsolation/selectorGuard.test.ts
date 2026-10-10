/**
 * 미리보기 매체 격리 — 선택자 분리·가드 삽입 (이슈 #141 D7)
 *
 * 폭 `@media` 원본 규칙은 미리보기 프레임 밖에서만 적용되도록 선택자 끝에
 * `:where(:not(.g7le-preview-frame *))` 를 붙인다. 가짜 요소가 있으면 그 앞에 붙여야
 * 유효한 선택자가 되고, 이스케이프된 콜론(`md\:flex`)을 가짜 요소로 오인하면 안 된다.
 */

import { describe, it, expect } from 'vitest';
import {
  splitSelectorList,
  appendGuard,
  guardSelectorList,
} from '../../../utils/previewMediaIsolation/selectorGuard';
import { OUTSIDE_GUARD } from '../../../utils/previewMediaIsolation/constants';

const G = OUTSIDE_GUARD;

describe('splitSelectorList — 최상위 쉼표로만 나눈다', () => {
  /**
   * @scenario selector_list=plain|escaped|nested|attribute
   * @effects preview_media_isolation_selector_split_respects_escapes
   */
  it.each([
    ['.a, .b', ['.a', '.b']],
    ['.md\\:grid-cols-2', ['.md\\:grid-cols-2']],
    ['.\\32 xl\\:p-4, .x', ['.\\32 xl\\:p-4', '.x']],
    [':is(.a, .b) .c', [':is(.a, .b) .c']],
    ['[data-x="a,b"]', ['[data-x="a,b"]']],
    ['.\\[\\&\\>\\*\\]\\:p-2, .x', ['.\\[\\&\\>\\*\\]\\:p-2', '.x']],
  ])('%s', (input, expected) => {
    expect(splitSelectorList(input)).toEqual(expected);
  });
});

describe('appendGuard — 마지막 복합 선택자에 가드를 붙인다', () => {
  /**
   * @scenario selector_shape=descendant|pseudo_class|pseudo_element|nested|escaped
   * @effects preview_media_isolation_guard_keeps_selector_valid
   */
  it.each([
    ['.a .b', `.a .b${G}`],
    ['.a::before', `.a${G}::before`],
    ['.a:before', `.a${G}:before`],
    ['.a:hover', `.a:hover${G}`],
    ['::placeholder', `${G}::placeholder`],
    ['.x::-webkit-scrollbar-thumb:hover', `.x${G}::-webkit-scrollbar-thumb:hover`],
    ['.a::part(x)', `.a${G}::part(x)`],
    ['&:hover', `&:hover${G}`],
    ['.md\\:flex', `.md\\:flex${G}`],
    ['.\\32 xl\\:p-4', `.\\32 xl\\:p-4${G}`],
    [':is(.a, .b)', `:is(.a, .b)${G}`],
    ['.a:not(.b::before)', `.a:not(.b::before)${G}`],
    ['.a:has(> .b)', `.a:has(> .b)${G}`],
    ['li:nth-child(2 of .a)', `li:nth-child(2 of .a)${G}`],
    ['.dark\\:hover\\:bg-x:hover', `.dark\\:hover\\:bg-x:hover${G}`],
    ['.before\\:content-x::before', `.before\\:content-x${G}::before`],
  ])('%s', (input, expected) => {
    expect(appendGuard(input, G)).toBe(expected);
  });

  /**
   * @scenario selector_shape=host|slotted
   * @effects preview_media_isolation_unsupported_counted
   */
  it(':host·::slotted 가 있는 선택자는 건너뛴다 (null)', () => {
    expect(appendGuard(':host(.a)', G)).toBeNull();
    expect(appendGuard('::slotted(p)', G)).toBeNull();
  });

  /**
   * @scenario apply_count=2
   * @effects preview_media_isolation_idempotent
   */
  it('두 번 적용해도 같다 (멱등)', () => {
    const once = appendGuard('.a .b', G)!;
    expect(appendGuard(once, G)).toBe(once);
  });
});

describe('guardSelectorList — 목록의 각 항목에 가드', () => {
  /**
   * @scenario selector_list=multiple
   * @effects preview_media_isolation_guard_keeps_selector_valid
   */
  it('쉼표 목록의 항목마다 붙인다', () => {
    expect(guardSelectorList('.a, .b::after', G)).toBe(`.a${G}, .b${G}::after`);
  });

  /**
   * @scenario selector_list=contains_host
   * @effects preview_media_isolation_unsupported_counted
   */
  it('한 항목이라도 건너뛰기 대상이면 null', () => {
    expect(guardSelectorList('.a, :host', G)).toBeNull();
  });
});

/**
 * @file StyleHelpers.mergeClasses.test.ts
 * @description mergeClasses 가 지우면 안 되는 기본 클래스를 지우던 충돌 판정 (7.1.3)
 *
 * `G7Core.style.mergeClasses(base, className)` 는 같은 충돌 그룹이면 base 쪽을 지운다. 그룹이 너무 거칠게 묶여
 * 축·변이 다른 유틸리티(`px-2` ↔ `p-4`, `top-4` ↔ `left-0`, `border-t` ↔ `border` …)를 같은 속성으로 보고 지웠다.
 * Tailwind 의 실제 충돌 단위로 나누고, 상위 유틸리티(p·inset·border·overflow·rounded·gap)가 하위 축을 덮는 방향만 지운다.
 *
 * @scenario-manifest tests/scenarios/engine-merge-classes.yaml
 */
import { describe, it, expect } from 'vitest';
import { mergeClasses } from '../helpers/StyleHelpers';

const tokens = (s: string): string[] => s.split(/\s+/).filter(Boolean).sort();

describe('mergeClasses — 지우면 안 되는 기본 클래스를 남긴다 (7.1.3)', () => {
  /**
   * @scenario group=spacing
   * @effects axis_utility_keeps_shorthand
   */
  it('padding·margin: 축 유틸리티(px)는 전체(p)를 지우지 않고, 전체(p)는 축을 지운다', () => {
    expect(tokens(mergeClasses('p-4', 'px-2'))).toEqual(['p-4', 'px-2']); // 결함: ['px-2']
    expect(tokens(mergeClasses('px-4 py-2', 'p-0'))).toEqual(['p-0']);
    expect(tokens(mergeClasses('px-4 pl-2', 'px-1'))).toEqual(['px-1']);
    expect(tokens(mergeClasses('m-4', 'mt-2'))).toEqual(['m-4', 'mt-2']);
    expect(tokens(mergeClasses('-mt-1 mb-2', 'mt-3'))).toEqual(['mb-2', 'mt-3']);
    expect(tokens(mergeClasses('pt-2', 'pb-4'))).toEqual(['pb-4', 'pt-2']);
  });

  /**
   * @scenario group=flex
   * @effects flex_direction_wrap_size_separate
   */
  it('flex: 방향·줄바꿈·크기는 서로 지우지 않는다', () => {
    expect(tokens(mergeClasses('flex-1 flex-wrap', 'flex-col'))).toEqual(['flex-1', 'flex-col', 'flex-wrap']); // 결함: ['flex-col']
    expect(tokens(mergeClasses('flex-row', 'flex-col'))).toEqual(['flex-col']);
    expect(tokens(mergeClasses('flex-wrap', 'flex-nowrap'))).toEqual(['flex-nowrap']);
  });

  /**
   * @scenario group=position
   * @effects inset_sides_separate
   */
  it('inset: top 은 left 를 지우지 않고, inset 은 변을 지운다', () => {
    expect(tokens(mergeClasses('top-0 left-0', 'top-4'))).toEqual(['left-0', 'top-4']); // 결함: ['top-4']
    expect(tokens(mergeClasses('top-0 left-0', 'inset-0'))).toEqual(['inset-0']);
    expect(tokens(mergeClasses('left-2 top-1', 'inset-x-0'))).toEqual(['inset-x-0', 'top-1']);
  });

  /**
   * @scenario group=border
   * @effects border_sides_separate
   */
  it('border 폭: border-t 는 border 를 지우지 않고, border 는 변을 지운다', () => {
    expect(tokens(mergeClasses('border', 'border-t'))).toEqual(['border', 'border-t']); // 결함: ['border-t']
    expect(tokens(mergeClasses('border-t-2 border-b', 'border-0'))).toEqual(['border-0']);
    expect(tokens(mergeClasses('border-l', 'border-x-2'))).toEqual(['border-x-2']);
  });

  /**
   * @scenario group=overflow
   * @effects overflow_axes_separate
   */
  it('overflow: 축(overflow-x)은 전체(overflow)를 지우지 않는다', () => {
    expect(tokens(mergeClasses('overflow-hidden', 'overflow-x-auto'))).toEqual(['overflow-hidden', 'overflow-x-auto']); // 결함
    expect(tokens(mergeClasses('overflow-x-auto overflow-y-hidden', 'overflow-visible'))).toEqual(['overflow-visible']);
  });

  /**
   * @scenario group=spacing
   * @effects space_reverse_separate
   */
  it('space: space-x-reverse 는 space-x-4 를 지우지 않는다', () => {
    expect(tokens(mergeClasses('space-x-4', 'space-x-reverse'))).toEqual(['space-x-4', 'space-x-reverse']); // 결함
    expect(tokens(mergeClasses('space-x-4 space-y-2', 'space-x-2'))).toEqual(['space-x-2', 'space-y-2']);
  });

  /**
   * @scenario group=typography
   * @effects break_after_not_word_break
   */
  it('break: break-after-* 는 break-words 를 지우지 않는다', () => {
    expect(tokens(mergeClasses('break-words', 'break-after-page'))).toEqual(['break-after-page', 'break-words']); // 결함
    expect(tokens(mergeClasses('break-words', 'break-all'))).toEqual(['break-all']);
  });

  /**
   * @scenario group=flex
   * @effects order_grouped
   */
  it('order: order-* 끼리 충돌한다(정규식 오타로 그룹에서 빠졌던 것)', () => {
    expect(tokens(mergeClasses('order-1', 'order-2'))).toEqual(['order-2']); // 결함: ['order-1','order-2']
    expect(tokens(mergeClasses('order-first', 'order-last'))).toEqual(['order-last']);
  });

  /**
   * @scenario group=same_type
   * @effects axis_utility_keeps_shorthand
   */
  it('같은 유형: gap-x·rounded-t·translate-y·scale-x 는 다른 축·전체를 지우지 않는다', () => {
    expect(tokens(mergeClasses('gap-4', 'gap-x-2'))).toEqual(['gap-4', 'gap-x-2']);
    expect(tokens(mergeClasses('gap-x-4 gap-y-2', 'gap-0'))).toEqual(['gap-0']);
    expect(tokens(mergeClasses('rounded-lg', 'rounded-t-none'))).toEqual(['rounded-lg', 'rounded-t-none']);
    expect(tokens(mergeClasses('rounded-t-lg', 'rounded-md'))).toEqual(['rounded-md']);
    expect(tokens(mergeClasses('translate-x-1', 'translate-y-2'))).toEqual(['translate-x-1', 'translate-y-2']);
    expect(tokens(mergeClasses('scale-100', 'scale-x-50'))).toEqual(['scale-100', 'scale-x-50']);
  });

  /**
   * @scenario group=unchanged
   * @effects existing_conflicts_unchanged
   */
  it('종전 충돌은 그대로 — 같은 그룹·같은 변이만 지운다', () => {
    expect(tokens(mergeClasses('justify-center items-center', 'justify-between'))).toEqual(['items-center', 'justify-between']);
    expect(tokens(mergeClasses('text-gray-900 dark:text-white', 'text-blue-500'))).toEqual(['dark:text-white', 'text-blue-500']);
    expect(tokens(mergeClasses('dark:px-4 px-4', 'dark:px-2'))).toEqual(['dark:px-2', 'px-4']);
    expect(tokens(mergeClasses('custom-a p-2', 'custom-b'))).toEqual(['custom-a', 'custom-b', 'p-2']);
  });
});

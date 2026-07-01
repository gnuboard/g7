/**
 * textComponents.test.ts — 텍스트 데이터 연결 대상 판정 단위 테스트
 */

import { describe, it, expect } from 'vitest';
import { CORE_TEXT_COMPONENTS, isTextBindableNode } from '../../spec/textComponents';

describe('CORE_TEXT_COMPONENTS', () => {
  it('보편 텍스트 컴포넌트 12종을 포함', () => {
    for (const n of ['Span', 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'A', 'Button', 'Li', 'Label']) {
      expect(CORE_TEXT_COMPONENTS.has(n)).toBe(true);
    }
  });
  it('비-텍스트 컴포넌트는 미포함', () => {
    for (const n of ['Div', 'Img', 'DataGrid', 'Input']) {
      expect(CORE_TEXT_COMPONENTS.has(n)).toBe(false);
    }
  });
});

describe('isTextBindableNode', () => {
  it('코어 텍스트 컴포넌트면 true', () => {
    expect(isTextBindableNode({ name: 'Span', text: 'x' }, null)).toBe(true);
    expect(isTextBindableNode({ name: 'P' }, null)).toBe(true);
  });

  it('코어 집합 밖이라도 string text 보유 시 폴백 true', () => {
    expect(isTextBindableNode({ name: 'Custom', text: '안녕' }, null)).toBe(true);
  });

  it('text 없고 코어 집합 밖이면 false', () => {
    expect(isTextBindableNode({ name: 'Div' }, null)).toBe(false);
    expect(isTextBindableNode({ name: 'Img', props: { src: 'x' } }, null)).toBe(false);
  });

  it('capability.textBinding=false 명시 opt-out → false (코어 집합이어도)', () => {
    expect(isTextBindableNode({ name: 'Span', text: 'x' }, { textBinding: false })).toBe(false);
  });

  it('capability.textBinding=true 명시 opt-in → true (코어 집합 밖이어도)', () => {
    expect(isTextBindableNode({ name: 'CustomBadge' }, { textBinding: true })).toBe(true);
  });

  it('iteration 노드는 어느 경우든 false (반복 소스 축 분리)', () => {
    expect(isTextBindableNode({ name: 'Span', text: 'x', iteration: { source: '{{a}}' } }, null)).toBe(false);
    expect(
      isTextBindableNode({ name: 'Span', text: 'x', iteration: { source: '{{a}}' } }, { textBinding: true }),
    ).toBe(false);
  });

  it('null/undefined 노드 → false', () => {
    expect(isTextBindableNode(null, null)).toBe(false);
    expect(isTextBindableNode(undefined, null)).toBe(false);
  });
});

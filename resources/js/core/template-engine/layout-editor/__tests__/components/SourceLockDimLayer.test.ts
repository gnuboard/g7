/**
 * SourceLockDimLayer.computeEditableHolePaths 테스트
 *
 * 출처 기반 역 스포트라이트 — 편집 가능(미잠금) 중 가장 얕은 노드만 "구멍"(밝게). 그 외는
 * SVG mask 로 어둡게. 조각마다 개별 정밀 구멍.
 */

import { describe, it, expect } from 'vitest';
import { computeEditableHolePaths } from '../../components/SourceLockDimLayer';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const base = (id: string, children?: EditorNode[]): EditorNode => ({
  id,
  __source: { kind: 'base' },
  ...(children ? { children } : {}),
});
const route = (id: string, children?: EditorNode[]): EditorNode => ({
  id,
  __source: { kind: 'route' },
  ...(children ? { children } : {}),
});
const ext = (id: string, extId: number, children?: EditorNode[]): EditorNode => ({
  id,
  __source: { kind: 'extension', extensionId: extId },
  ...(children ? { children } : {}),
});

describe('computeEditableHolePaths ', () => {
  it('extension 모드 — 편집 중 확장 조각만 구멍(밝게), 호스트 본체는 음영', () => {
    const components = [base('header'), ext('ext-frag', 7), base('footer')];
    // 편집 가능 = ext-frag(1). 구멍 path = [[1]].
    expect(computeEditableHolePaths(components, 'extension', 7)).toEqual([[1]]);
  });

  it('extension 모드 — 타 확장은 구멍 아님(음영), 편집 중 확장만 구멍', () => {
    const components = [ext('mine', 7), ext('other', 99)];
    expect(computeEditableHolePaths(components, 'extension', 7)).toEqual([[0]]);
  });

  it('호스트 깊은 곳에 주입된 확장 — 잠금 컨테이너는 내려가고 조각만 구멍', () => {
    const components = [
      base('wrap', [base('sibling-locked'), ext('deep-frag', 7)]),
    ];
    // wrap(잠금)은 편집 가능 자손 보유 → 내려감. deep-frag(0.children.1)만 구멍.
    expect(computeEditableHolePaths(components, 'extension', 7)).toEqual([[0, 1]]);
  });

  it('흩어진 다중 확장 조각 — 각 조각이 개별 구멍', () => {
    const components = [
      base('a'),
      ext('frag1', 7),
      base('b'),
      ext('frag2', 7),
    ];
    expect(computeEditableHolePaths(components, 'extension', 7)).toEqual([[1], [3]]);
  });

  it('route 모드 — 역 스포트라이트 미적용(빈 배열)', () => {
    const components = [route('content'), ext('injected', 5)];
    expect(computeEditableHolePaths(components, 'route', undefined)).toEqual([]);
  });

  it('base 모드 — base 본체(base/route 태깅)는 구멍, 주입 확장만 음영', () => {
    const components = [base('header'), route('slotted'), ext('inj', 3)];
    // base 모드는 base 단독 로드 — 노드가 base/route 로 태깅되며 둘 다 base 본체(편집 대상)다
    // 따라서 header(0)+slotted(1) 모두 구멍, ext(2)만 음영.
    expect(computeEditableHolePaths(components, 'base', undefined)).toEqual([[0], [1]]);
  });

  // path 기반 편집 대상 모드(iteration_item / modal). editableRootPath 가 주어지면
  // 그 노드 박스 하나만 구멍, 나머지 호스트 전체 음영(확장 편집과 동형 인플레이스).
  it('iteration_item 모드 — editableRootPath 노드만 구멍', () => {
    const components = [route('a'), route('list'), route('b')];
    // 편집 대상 = iteration 원본 노드 path [1].
    expect(computeEditableHolePaths(components, 'iteration_item', undefined, [1])).toEqual([[1]]);
  });

  it('iteration_item 모드 — 깊은 위치 editableRootPath 도 그 노드만 구멍', () => {
    const components = [route('wrap', [route('x'), route('list')])];
    expect(computeEditableHolePaths(components, 'iteration_item', undefined, [0, 1])).toEqual([
      [0, 1],
    ]);
  });

  it('modal 모드 — editableRootPath(append 된 모달 노드)만 구멍', () => {
    const components = [route('host-a'), route('host-b'), { id: 'modal' } as EditorNode];
    // 모달은 components 끝에 append → editableRootPath = [2].
    expect(computeEditableHolePaths(components, 'modal', undefined, [2])).toEqual([[2]]);
  });

  it('iteration_item / modal — editableRootPath 부재 시 빈 배열(음영 끔)', () => {
    const components = [route('a'), route('b')];
    expect(computeEditableHolePaths(components, 'iteration_item', undefined, null)).toEqual([]);
    expect(computeEditableHolePaths(components, 'modal', undefined, undefined)).toEqual([]);
  });
});

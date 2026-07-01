/**
 * collectExtensionSourcePaths.test.ts — 확장 편집 렌더 검증용 source path 수집
 *
 *
 * 머지 트리에서 현재 확장(`extensionId`)이 주입한 노드들의 source path 를 수집한다. 이 path 가
 * 렌더 후 캔버스 DOM 에 `data-editor-path` 로 존재하는지 검사해, 0개면 게이트 뒤 미렌더로 판정
 * (폴백). path 는 DynamicRenderer 가 부여하는 형식(`{i}.children.{j}...`)과 일치해야 한다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { collectExtensionSourcePaths } from '../../components/PreviewCanvas';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const ext = (extensionId: number) => ({ kind: 'extension' as const, extensionId });

describe('collectExtensionSourcePaths', () => {
  it('최상위 확장 노드의 path 를 수집', () => {
    const tree: EditorNode[] = [
      { id: 'a', name: 'Div' } as any,
      { id: 'b', name: 'Span', __source: ext(35) } as any,
    ];
    expect(collectExtensionSourcePaths(tree, 35)).toEqual(['1']);
  });

  it('중첩 자식에 주입된 확장 노드의 path 를 children 표기로 수집', () => {
    const tree: EditorNode[] = [
      {
        id: 'host',
        name: 'Div',
        __source: { kind: 'base' },
        children: [
          { id: 'h1', name: 'Div', __source: { kind: 'base' } },
          { id: 'frag', name: 'Span', __source: ext(35) },
        ],
      } as any,
    ];
    expect(collectExtensionSourcePaths(tree, 35)).toEqual(['0.children.1']);
  });

  it('진입점 노드의 자식은 내려가지 않음(진입점 path 만)', () => {
    const tree: EditorNode[] = [
      {
        id: 'frag',
        name: 'Div',
        __source: ext(35),
        children: [{ id: 'child', name: 'Span', __source: ext(35) }],
      } as any,
    ];
    // 진입점 0 만 수집, 그 자식 0.children.0 은 내려가지 않음.
    expect(collectExtensionSourcePaths(tree, 35)).toEqual(['0']);
  });

  it('여러 주입 자리를 모두 수집', () => {
    const tree: EditorNode[] = [
      { id: 'f1', name: 'Span', __source: ext(35) } as any,
      {
        id: 'wrap',
        name: 'Div',
        __source: { kind: 'base' },
        children: [{ id: 'f2', name: 'Span', __source: ext(35) }],
      } as any,
    ];
    expect(collectExtensionSourcePaths(tree, 35)).toEqual(['0', '1.children.0']);
  });

  it('다른 확장 id 는 수집하지 않음', () => {
    const tree: EditorNode[] = [
      { id: 'other', name: 'Span', __source: ext(36) } as any,
      { id: 'mine', name: 'Span', __source: ext(35) } as any,
    ];
    expect(collectExtensionSourcePaths(tree, 35)).toEqual(['1']);
  });

  it('주입 노드 없음 → 빈 배열', () => {
    const tree: EditorNode[] = [
      { id: 'a', name: 'Div', __source: { kind: 'base' } } as any,
    ];
    expect(collectExtensionSourcePaths(tree, 35)).toEqual([]);
  });

  it('undefined/빈 입력 → 빈 배열', () => {
    expect(collectExtensionSourcePaths(undefined, 35)).toEqual([]);
    expect(collectExtensionSourcePaths([], 35)).toEqual([]);
  });
});

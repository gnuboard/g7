/**
 * useElementSelection — 순수 유틸 테스트
 *
 * parseEditorPath / classifyNavAffordance / classifyLockKind 의 분류 매트릭스.
 *
 * @since engine-v1.50.0
 */

import { describe, expect, it } from 'vitest';
import {
  classifyLockKind,
  classifyNavAffordance,
  parseEditorPath,
  isContextMenuAllowed,
  sliceDomPathToDepth,
  resolveSourceExtensionId,
} from '../../hooks/useElementSelection';
import type { EditorNode } from '../../utils/layoutTreeUtils';

describe('parseEditorPath', () => {
  it('빈 문자열 → 빈 배열', () => {
    expect(parseEditorPath('')).toEqual([]);
  });

  it('단일 인덱스', () => {
    expect(parseEditorPath('0')).toEqual([0]);
  });

  it('children 키워드 무시', () => {
    expect(parseEditorPath('0.children.2')).toEqual([0, 2]);
  });

  it('iteration / sortable 뒤 데이터 행 인덱스는 가상 — 소스 path 에서 제외 (항목1)', () => {
    // `.iteration.1`·`.sortable.3` 의 1·3 은 데이터 행 인덱스(가상)다. 모든 행
    // 인스턴스는 같은 템플릿 노드 1개를 가리키므로 행 인덱스를 자식 인덱스로
    // 끼워 넣지 않는다 → 어느 행을 편집해도 템플릿 1개를 패치(모든 행 동시 반영).
    expect(parseEditorPath('0.children.2.iteration.1.sortable.3.children.0')).toEqual([
      0, 2, 0,
    ]);
  });

  it('iteration 단일 — 인스턴스 path 가 iteration 원본(템플릿) path 로 환원', () => {
    // `0.children.2.iteration.0` 와 `...iteration.5` 는 같은 소스 노드 [0,2] 를 가리킨다.
    expect(parseEditorPath('0.children.2.iteration.0')).toEqual([0, 2]);
    expect(parseEditorPath('0.children.2.iteration.5')).toEqual([0, 2]);
    // 인스턴스 내부 자식도 행과 무관하게 동일 소스 자식 [0,2,0].
    expect(parseEditorPath('0.children.2.iteration.0.children.0')).toEqual([0, 2, 0]);
    expect(parseEditorPath('0.children.2.iteration.5.children.0')).toEqual([0, 2, 0]);
  });

  it('sortable 단일 — 인스턴스 path 가 sortable 원본(템플릿) path 로 환원', () => {
    expect(parseEditorPath('0.children.1.sortable.0')).toEqual([0, 1]);
    expect(parseEditorPath('0.children.1.sortable.4.children.2')).toEqual([0, 1, 2]);
  });
});

describe('classifyNavAffordance — 외부 / 내부 / 동적', () => {
  it('actions=undefined 노드 → none', () => {
    const { affordance, targetPath } = classifyNavAffordance({ name: 'Div' } as EditorNode);
    expect(affordance).toBe('none');
    expect(targetPath).toBe(null);
  });

  it('navigate 액션의 절대 외부 URL → external_url', () => {
    const node: EditorNode = {
      name: 'Button',
      actions: [
        { handler: 'navigate', params: { path: 'https://example.com/foo' } },
      ],
    } as any;
    const result = classifyNavAffordance(node);
    expect(result.affordance).toBe('external_url');
    expect(result.targetPath).toBe('https://example.com/foo');
  });

  it('navigate 액션의 protocol-relative URL → external_url', () => {
    const node: EditorNode = {
      name: 'Button',
      actions: [{ handler: 'navigate', params: { path: '//evil.com/x' } }],
    } as any;
    expect(classifyNavAffordance(node).affordance).toBe('external_url');
  });

  it('navigate 액션의 동적 {{...}} 경로 → dynamic_path', () => {
    const node: EditorNode = {
      name: 'Button',
      actions: [
        { handler: 'navigate', params: { path: '/posts/{{post.id}}' } },
      ],
    } as any;
    expect(classifyNavAffordance(node).affordance).toBe('dynamic_path');
  });

  it('내부 라우트 + resolveRouteMatch 사용 시 route_in_tree', () => {
    const node: EditorNode = {
      name: 'Button',
      actions: [{ handler: 'navigate', params: { path: '/posts' } }],
    } as any;
    const result = classifyNavAffordance(node, () => 'route_in_tree');
    expect(result.affordance).toBe('route_in_tree');
    expect(result.targetPath).toBe('/posts');
  });

  it('내부 라우트 + resolveRouteMatch 없으면 보수적으로 route_not_in_tree', () => {
    const node: EditorNode = {
      name: 'Button',
      actions: [{ handler: 'navigate', params: { path: '/posts' } }],
    } as any;
    expect(classifyNavAffordance(node).affordance).toBe('route_not_in_tree');
  });

  it('A 컴포넌트 + href 가 내부 경로 → 라우트 매처 사용', () => {
    const node: EditorNode = {
      name: 'A',
      props: { href: '/board' },
    };
    const result = classifyNavAffordance(node, () => 'route_in_tree');
    expect(result.affordance).toBe('route_in_tree');
  });
});

describe('classifyLockKind', () => {
  it('node=null → none', () => {
    expect(classifyLockKind(null, 'route')).toBe('none');
  });

  it('extension_point 노드 → 모든 모드에서 extension_point', () => {
    const node: EditorNode = { type: 'extension_point' };
    expect(classifyLockKind(node, 'route')).toBe('extension_point');
    expect(classifyLockKind(node, 'base')).toBe('extension_point');
  });

  it('슬롯 노드(slot/__editorSlotName) → extension_point 잠금', () => {
    const slotNode: EditorNode = { type: 'layout', name: 'Container', slot: 'content' } as any;
    expect(classifyLockKind(slotNode, 'base')).toBe('extension_point');
    const markerNode: EditorNode = { type: 'layout', name: 'Container', __editorSlotName: 'content' } as any;
    expect(classifyLockKind(markerNode, 'base')).toBe('extension_point');
  });

  it('iteration 조상 → data_bound', () => {
    const ancestor: EditorNode = { iteration: { source: 'posts' } } as any;
    const node: EditorNode = { name: 'Span' };
    expect(classifyLockKind(node, 'route', undefined, [ancestor])).toBe('data_bound');
  });

  it('route 모드에서 base 출처 노드 → base 잠금', () => {
    const node: EditorNode = { name: 'Header', __source: { kind: 'base' } };
    expect(classifyLockKind(node, 'route')).toBe('base');
  });

  it('route 모드에서 extension 출처 노드 → extension 잠금', () => {
    const node: EditorNode = { name: 'X', __source: { kind: 'extension', extensionId: 7 } };
    expect(classifyLockKind(node, 'route')).toBe('extension');
  });

  it('route 모드 + 일반 라우트 노드 → none', () => {
    const node: EditorNode = { name: 'Div', __source: { kind: 'route' } };
    expect(classifyLockKind(node, 'route')).toBe('none');
  });

  // 출처 잠금이 data_bound 보다 우선.
  it('extension 모드 + 호스트 본체 data_bound 노드 → base 잠금(선택 차단), data_bound 로 새지 않음', () => {
    // 호스트 폼 입력칸: route 출처 + 바인딩값. 확장 편집 모드에서는 잠겨야 한다.
    const node: EditorNode = {
      name: 'Input',
      __source: { kind: 'route' },
      props: { value: '{{registerForm.email}}' },
    };
    // extension 모드에서 route 출처는 잠금 → base 취급(선택 차단). data_bound 아님.
    expect(classifyLockKind(node, 'extension', 7)).toBe('base');
    expect(isContextMenuAllowed(classifyLockKind(node, 'extension', 7))).toBe(false);
  });

  it('extension 모드 + 편집 중 확장 조각의 data_bound 노드 → data_bound(편집 허용)', () => {
    // 편집 중 확장(7)의 조각은 미잠금 → 바인딩 있으면 data_bound(텍스트만 잠금, 선택/스타일 허용).
    const node: EditorNode = {
      name: 'Span',
      __source: { kind: 'extension', extensionId: 7 },
      text: '{{item.label}}',
    };
    expect(classifyLockKind(node, 'extension', 7)).toBe('data_bound');
    expect(isContextMenuAllowed(classifyLockKind(node, 'extension', 7))).toBe(true);
  });

  it('base 모드 + route 출처 data_bound 노드(단독 base 본체) → data_bound (편집 가능)', () => {
    const node: EditorNode = {
      name: 'Input',
      __source: { kind: 'route' },
      text: '{{x}}',
    };
    // base 편집은 base 레이아웃을 단독 로드하며 그 노드는 kind:'route' 로 태깅된다.
    // = base 본체(편집 대상) → 미잠금 → 바인딩 있으면 data_bound(텍스트만 잠금). base 잠금 아님.
    expect(classifyLockKind(node, 'base')).toBe('data_bound');
  });

  it('base 모드 + 주입된 확장 노드 → extension 잠금', () => {
    const node: EditorNode = { name: 'X', __source: { kind: 'extension', extensionId: 3 } };
    expect(classifyLockKind(node, 'base')).toBe('extension');
  });

  // route 모드는 종전 동작(data_bound 우선)을 그대로 보존해야 한다.
  it('route 모드 + 확장 출처 data_bound 노드 → data_bound (종전 동작 보존, 회귀 방지)', () => {
    // 확장 주입 영역 안의 바인딩 노드. route 모드에서는 data_bound 로 분류돼 "데이터 영역" 표식
    // 을 유지하고, 잠긴 확장 루트는 별도로 extension 어포던스를 띄운다(별도 노드).
    const node: EditorNode = {
      name: 'Span',
      __source: { kind: 'extension', extensionId: 35 },
      text: '{{content}}',
    };
    expect(classifyLockKind(node, 'route')).toBe('data_bound');
  });

  it('route 모드 + 확장 출처 (바인딩 없음) 노드 → extension 잠금(확장 편집 어포던스)', () => {
    // 바인딩 없는 확장 주입 노드는 route 모드에서 extension 잠금 → "확장 편집" 어포던스.
    const node: EditorNode = {
      name: 'Div',
      __source: { kind: 'extension', extensionId: 35 },
    };
    expect(classifyLockKind(node, 'route')).toBe('extension');
  });
});

describe('isContextMenuAllowed — ⓘ 속성 메뉴 허용 여부 ', () => {
  it('none / data_bound 만 허용', () => {
    expect(isContextMenuAllowed('none')).toBe(true);
    expect(isContextMenuAllowed('data_bound')).toBe(true);
  });

  it('잠금 출처(base/partial/extension/extension_point)는 차단', () => {
    expect(isContextMenuAllowed('base')).toBe(false);
    expect(isContextMenuAllowed('partial')).toBe(false);
    expect(isContextMenuAllowed('extension')).toBe(false);
    expect(isContextMenuAllowed('extension_point')).toBe(false);
  });
});

describe('sliceDomPathToDepth — DOM path 진입점 prefix 절단 (통짜 표시)', () => {
  it('단순 children 경로를 깊이만큼 자른다', () => {
    // [2,0,0] = "2.children.0.children.0" 깊이 1 → "2"
    expect(sliceDomPathToDepth('2.children.0.children.0', 1)).toBe('2');
    // 깊이 2 → "2.children.0"
    expect(sliceDomPathToDepth('2.children.0.children.0', 2)).toBe('2.children.0');
  });

  it('깊이 0/이상이면 원본 유지', () => {
    expect(sliceDomPathToDepth('2.children.0', 0)).toBe('2.children.0');
    expect(sliceDomPathToDepth('2.children.0', 5)).toBe('2.children.0');
  });

  it('iteration/sortable 행 인덱스 토큰을 건너뛰며 트리 깊이를 센다', () => {
    // "2.children.5.children.1.iteration.0.children.0" — 트리 깊이: 2(루트)→5→1→(iter행)→0
    // parseEditorPath 기준 인덱스: [2,5,1,0] (iteration.0 의 0 은 제외). 깊이 1 → "2"
    expect(sliceDomPathToDepth('2.children.5.children.1.iteration.0.children.0', 1)).toBe('2');
    // 깊이 3 → "2.children.5.children.1"
    expect(sliceDomPathToDepth('2.children.5.children.1.iteration.0.children.0', 3)).toBe(
      '2.children.5.children.1',
    );
  });
});

describe('resolveSourceExtensionId', () => {
  it('extension 출처 노드 → __source.extensionId', () => {
    expect(
      resolveSourceExtensionId({ __source: { kind: 'extension', extensionId: 42 } } as EditorNode),
    ).toBe(42);
  });

  it('inject_props 호스트 노드 → 첫 주입 확장 PK', () => {
    const node = {
      id: 'tabs',
      __injectedProps: [
        { extensionId: 9, props: {} },
        { extensionId: 3, props: {} },
      ],
    } as unknown as EditorNode;
    expect(resolveSourceExtensionId(node)).toBe(9);
  });

  it('일반 노드 / null → null', () => {
    expect(resolveSourceExtensionId({ __source: { kind: 'route' } } as EditorNode)).toBeNull();
    expect(resolveSourceExtensionId({} as EditorNode)).toBeNull();
    expect(resolveSourceExtensionId(null)).toBeNull();
  });
});

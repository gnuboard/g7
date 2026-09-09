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
  isEditableLockKind,
  resolveDndDenial,
  type SelectionLockKind,
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

  // 계약 변경(의도적): 종전에는 route 모드에서 data_bound 를 먼저 판정해 이 노드가
  // `data_bound`(= 편집 허용)로 분류됐다. 그런데 저장 시 `stripInheritedNode` 가 확장 출처
  // 노드를 통째로 폐기하므로 편집분이 오류도 경고도 없이 사라졌다(저장은 200 성공, history
  // clear 로 undo 불가). 종전 기대값이 고정하던 것은 "route 는 한 줄도 바꾸지 않는다" 는
  // 보수성 선언이었고, 그 보수성이 곧 결함이었다.
  it('route 모드 + 확장 출처 data_bound 노드 → extension (편집 차단 + 확장 편집 유도)', () => {
    const node: EditorNode = {
      name: 'Span',
      __source: { kind: 'extension', extensionId: 35 },
      text: '{{content}}',
    };
    expect(classifyLockKind(node, 'route')).toBe('extension');
    expect(isContextMenuAllowed(classifyLockKind(node, 'route'))).toBe(false);
  });

  // ⚠ 위 변경이 좁힌 것은 "어느 노드가 data_bound 로 분류되는가" 뿐이다.
  // `data_bound ⇒ 선택·드래그·구조 편집 허용` 계약은 그대로다 — 아래가 그 반증 가드이며,
  // 반대 방향으로 되돌리는 변경은 여기서 막힌다.
  it('route 모드 + route 출처 data_bound 노드 → data_bound (편집 허용 계약 보존)', () => {
    const node: EditorNode = {
      name: 'Img',
      __source: { kind: 'route' },
      props: { src: '{{product.image}}' },
    };
    expect(classifyLockKind(node, 'route')).toBe('data_bound');
    expect(isContextMenuAllowed(classifyLockKind(node, 'route'))).toBe(true);
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

// ============================================================================
// route 모드 출처 잠금 우선 — 상속·주입 노드 편집 소실 차단 (A3)
//
// 종전에는 route 모드만 `data_bound` 를 먼저 판정해, 상속(base)·주입(extension) 노드 중
// props 값 하나라도 `{{ }}` 인 것이 "편집 가능" 으로 분류됐다. 그런데 저장 시
// `stripInheritedNode` 가 그 노드를 통째로 폐기하므로 편집분이 오류도 경고도 없이
// 사라졌다(저장은 200 성공 + history clear 로 undo 불가).
//
// N5~N9 는 `data_bound ⇒ 선택·드래그·구조 편집 허용` 계약의 **반증 가드**다 —
// 되돌리는 변경은 여기서 막힌다.
// ============================================================================
describe('classifyLockKind — route 모드 출처 잠금 우선 (A3)', () => {
  const menu = (kind: SelectionLockKind) => isContextMenuAllowed(kind);
  const drag = (kind: SelectionLockKind) => resolveDndDenial(kind) === null;

  it('N1 base 출처 + 텍스트 바인딩 → base (ⓘ·드래그 차단)', () => {
    const node: EditorNode = {
      name: 'H1',
      __source: { kind: 'base', layout: '_user_base' },
      text: '{{site.title}}',
    };
    const kind = classifyLockKind(node, 'route');
    expect(kind).toBe('base');
    expect(menu(kind)).toBe(false);
    expect(drag(kind)).toBe(false);
  });

  it('N2 base 출처 + props 바인딩 → base', () => {
    const node: EditorNode = {
      name: 'Div',
      __source: { kind: 'base' },
      props: { className: '{{theme}}' },
    };
    const kind = classifyLockKind(node, 'route');
    expect(kind).toBe('base');
    expect(menu(kind)).toBe(false);
    expect(drag(kind)).toBe(false);
  });

  it('N3 base 출처 + iteration → base (반복 항목 편집 진입도 함께 닫힌다)', () => {
    const node: EditorNode = {
      name: 'List',
      __source: { kind: 'base' },
      iteration: { source: '{{recent.data}}' },
    };
    const kind = classifyLockKind(node, 'route');
    expect(kind).toBe('base');
    expect(menu(kind)).toBe(false);
    expect(drag(kind)).toBe(false);
  });

  it('N4 extension 출처 + 바인딩 → extension', () => {
    const node: EditorNode = {
      name: 'Span',
      __source: { kind: 'extension', extensionId: 35 },
      text: '{{content}}',
    };
    const kind = classifyLockKind(node, 'route');
    expect(kind).toBe('extension');
    expect(menu(kind)).toBe(false);
    expect(drag(kind)).toBe(false);
  });

  it('N5 회귀 가드 — route 출처 + props 바인딩은 data_bound (편집 허용)', () => {
    const node: EditorNode = {
      name: 'Img',
      __source: { kind: 'route' },
      props: { src: '{{product.image}}' },
    };
    const kind = classifyLockKind(node, 'route');
    expect(kind).toBe('data_bound');
    expect(menu(kind)).toBe(true);
    expect(drag(kind)).toBe(true);
  });

  it('N6 회귀 가드 — route 출처 + iteration 도 data_bound (편집 허용)', () => {
    const node: EditorNode = {
      name: 'Div',
      __source: { kind: 'route' },
      iteration: { source: '{{posts.data}}' },
    };
    const kind = classifyLockKind(node, 'route');
    expect(kind).toBe('data_bound');
    expect(menu(kind)).toBe(true);
    expect(drag(kind)).toBe(true);
  });

  it('N7 회귀 가드 — 조상이 route 출처 iteration 이면 data_bound', () => {
    const node: EditorNode = { name: 'Span' };
    const ancestors: EditorNode[] = [
      { name: 'List', __source: { kind: 'route' }, iteration: { source: '{{posts.data}}' } },
    ];
    const kind = classifyLockKind(node, 'route', undefined, ancestors);
    expect(kind).toBe('data_bound');
    expect(menu(kind)).toBe(true);
  });

  it('N8 회귀 가드 — __source 미부여(신규 삽입) + 바인딩은 data_bound', () => {
    const node: EditorNode = { name: 'Span', text: '{{x}}' };
    const kind = classifyLockKind(node, 'route');
    expect(kind).toBe('data_bound');
    expect(menu(kind)).toBe(true);
  });

  it('N9 회귀 가드 — 조상만 base 이고 자신은 미태깅이면 none (조상은 보지 않는다)', () => {
    const node: EditorNode = { name: 'Span' };
    const ancestors: EditorNode[] = [{ name: 'Div', __source: { kind: 'base' } }];
    const kind = classifyLockKind(node, 'route', undefined, ancestors);
    expect(kind).toBe('none');
    expect(menu(kind)).toBe(true);
    expect(drag(kind)).toBe(true);
  });

  it('isEditableLockKind / resolveDndDenial 이 같은 판정을 공유한다 (조건 복사 금지)', () => {
    const kinds: SelectionLockKind[] = [
      'none',
      'data_bound',
      'base',
      'partial',
      'extension',
      'extension_point',
    ];
    for (const k of kinds) {
      expect(resolveDndDenial(k) === null).toBe(isEditableLockKind(k));
      expect(isContextMenuAllowed(k)).toBe(isEditableLockKind(k));
    }
    expect(resolveDndDenial('base')).toBe('denied_base_locked');
    expect(resolveDndDenial('partial')).toBe('denied_base_locked');
    expect(resolveDndDenial('extension')).toBe('denied_extension_locked');
    expect(resolveDndDenial('extension_point')).toBe('denied_extension_locked');
  });
});

// e2e:allow 순수 트리 마스킹 유틸의 단위 테스트 — DOM/네트워크 영향 없음. 본 파일이 검증하는 layoutTreeUtils.ts 와 동일 면제 사유
/**
 * layoutTreeUtils.test.ts — 잠금 판정 + 데이터 결정 노드 판정 검증
 *
 * Pure Logic 매트릭스 전수 커버.
 */

import { describe, it, expect } from 'vitest';
import {
  isBaseOriginatedNode,
  isPartialOriginatedNode,
  isOwnRouteContentNode,
  isExtensionOriginatedNode,
  isExtensionPointNode,
  isDataBoundNode,
  isSelfDataBoundNode,
  isNodeLocked,
  getNodeSource,
  findNodeByPath,
  findParentByPath,
  insertNode,
  removeNode,
  moveNode,
  rebasePathAfterRemoval,
  duplicateNode,
  patchNode,
  ensureNodeId,
  validate,
  stripInheritedNodes,
  stripInheritedFromLayoutContent,
  normalizeToExtensionEntry,
  resolveBaseEditTarget,
  type EditorNode,
} from '../../utils/layoutTreeUtils';

describe('layoutTreeUtils — `__source` 메타 분기', () => {
  it('isBaseOriginatedNode — kind=base 만 true', () => {
    expect(isBaseOriginatedNode({ __source: { kind: 'base', layout: '_admin_base' } })).toBe(true);
    expect(isBaseOriginatedNode({ __source: { kind: 'route', layout: 'index' } })).toBe(false);
    expect(isBaseOriginatedNode({ __source: { kind: 'partial' } })).toBe(false);
    expect(isBaseOriginatedNode({})).toBe(false);
  });

  it('isOwnRouteContentNode — kind=route 만 true', () => {
    expect(isOwnRouteContentNode({ __source: { kind: 'route', layout: 'index' } })).toBe(true);
    expect(isOwnRouteContentNode({ __source: { kind: 'base' } })).toBe(false);
    expect(isOwnRouteContentNode({})).toBe(false);
  });

  it('isPartialOriginatedNode — kind=partial 만 true', () => {
    expect(isPartialOriginatedNode({ __source: { kind: 'partial' } })).toBe(true);
    expect(isPartialOriginatedNode({ __source: { kind: 'base' } })).toBe(false);
  });

  describe('isExtensionOriginatedNode — 현재 확장 ID 분기', () => {
    const extNode: EditorNode = { __source: { kind: 'extension', extensionId: 42 } };

    it('확장 모드 외(`currentExtensionId` 미전달): 모든 extension 노드 잠금', () => {
      expect(isExtensionOriginatedNode(extNode)).toBe(true);
    });

    it('확장 모드(편집 중 확장 != 노드 확장): 잠금', () => {
      expect(isExtensionOriginatedNode(extNode, 99)).toBe(true);
    });

    it('확장 모드(편집 중 확장 == 노드 확장): 편집 가능', () => {
      expect(isExtensionOriginatedNode(extNode, 42)).toBe(false);
    });

    it('extension 메타가 아닌 노드: false', () => {
      expect(isExtensionOriginatedNode({ __source: { kind: 'base' } })).toBe(false);
      expect(isExtensionOriginatedNode({})).toBe(false);
    });
  });

  it('getNodeSource — null 안전', () => {
    expect(getNodeSource({ __source: { kind: 'base' } })).toEqual({ kind: 'base' });
    expect(getNodeSource({})).toBeNull();
  });
});

describe('layoutTreeUtils — extension_point 잠금 ', () => {
  it('type=extension_point 는 잠금', () => {
    expect(isExtensionPointNode({ type: 'extension_point', name: 'sidebar' })).toBe(true);
  });

  it('일반 basic 컴포넌트는 false', () => {
    expect(isExtensionPointNode({ type: 'basic', name: 'Div' })).toBe(false);
  });
});

describe('layoutTreeUtils — isDataBoundNode ', () => {
  it('text 에 `{{...}}` 바인딩이 있으면 true', () => {
    expect(isDataBoundNode({ text: '{{post.title}}' })).toBe(true);
    expect(isDataBoundNode({ text: '정적 텍스트' })).toBe(false);
  });

  it('props 값 중 어느 하나라도 바인딩이면 true', () => {
    expect(isDataBoundNode({ props: { href: '/posts/{{post.id}}' } })).toBe(true);
    expect(isDataBoundNode({ props: { className: 'btn primary' } })).toBe(false);
  });

  it('iteration.source 가 있으면 true', () => {
    expect(isDataBoundNode({ iteration: { source: 'posts' } as any })).toBe(true);
    expect(isDataBoundNode({ iteration: {} as any })).toBe(false);
  });

  it('조상 중 iteration 노드가 있으면 true', () => {
    const ancestors: EditorNode[] = [
      { type: 'basic' }, // 일반 부모
      { iteration: { source: 'items' } as any }, // iteration 부모
    ];
    expect(isDataBoundNode({ text: '정적 텍스트' }, ancestors)).toBe(true);
  });

  it('조상에 iteration 노드가 없으면 false', () => {
    const ancestors: EditorNode[] = [{ type: 'basic' }];
    expect(isDataBoundNode({ text: '정적 텍스트' }, ancestors)).toBe(false);
  });

  it('아무 조건도 충족 안 하면 false', () => {
    expect(isDataBoundNode({})).toBe(false);
    expect(isDataBoundNode({ text: '정적', props: { className: 'foo' } })).toBe(false);
  });
});

describe('layoutTreeUtils — isSelfDataBoundNode', () => {
  it('노드 자신의 text 바인딩 → true', () => {
    expect(isSelfDataBoundNode({ text: '{{post.title}}' })).toBe(true);
  });

  it('노드 자신의 props 바인딩 → true', () => {
    expect(isSelfDataBoundNode({ props: { href: '/p/{{post.id}}' } })).toBe(true);
  });

  it('평문 text/정적 props → false', () => {
    expect(isSelfDataBoundNode({ text: '카테고리' })).toBe(false);
    expect(isSelfDataBoundNode({ props: { className: 'btn' } })).toBe(false);
    expect(isSelfDataBoundNode({})).toBe(false);
  });

  it('조상 iteration 은 보지 않는다 (isDataBoundNode 와의 차이 — 평문은 self 기준 false)', () => {
    // isDataBoundNode 는 조상 iteration 때문에 true 지만, isSelfDataBoundNode 는 노드 자신만 봐 false.
    const ancestors: EditorNode[] = [{ iteration: { source: 'items' } as any }];
    expect(isDataBoundNode({ text: '평문' }, ancestors)).toBe(true);
    expect(isSelfDataBoundNode({ text: '평문' })).toBe(false);
  });

  it('자신이 iteration 정의 노드여도 text/props 바인딩이 없으면 self 기준 false', () => {
    // iteration 정의 자체는 isDataBoundNode 에서 별도 true 지만, self 텍스트/props 바인딩은 아님.
    expect(isSelfDataBoundNode({ iteration: { source: '{{posts.data}}' } as any })).toBe(false);
  });

  // 데이터 칩이 든 custom param 키(`$t:custom.*|pN={{}}`)는 **편집 가능한
  // 다국어 문구**(평문+칩 혼합)지 순수 데이터 노드가 아니다. text 안의 `{{}}` 는 칩의 데이터 인자일
  // 뿐, 표시 텍스트의 본질은 사용자가 인라인/속성탭에서 편집하는 문구다. 종전엔 `{{}}` 포함만 보고
  // 순수 바인딩과 똑같이 data_bound 로 잠가(표 셀·목록·옵션 라벨에 데이터 칩 추가 후 더블클릭 인라인
  // 편집이 막힘) 7차 트랙 기능과 모순됐다. custom param 키는 잠금 대상에서 제외한다.
  describe('데이터 칩(custom param 키)은 편집 가능 — self data_bound 아님', () => {
    it('custom param 키(`$t:custom.*|pN={{}}`) text → false (편집 가능)', () => {
      expect(isSelfDataBoundNode({ text: "$t:custom.shop_index.2|p0={{current_user?.data?.id ?? ''}}" })).toBe(false);
    });

    it('멀티 param custom 키도 false', () => {
      expect(isSelfDataBoundNode({ text: '$t:custom.home.5|p0={{a.b}}|p1={{c.d}}' })).toBe(false);
    });

    it('순수 데이터 바인딩(`{{...}}` only)은 잠금 유지 → true', () => {
      expect(isSelfDataBoundNode({ text: '{{product.data.name}}' })).toBe(true);
    });

    it('lang named-param(`$t:user.*|count={{}}`, 비-custom)은 잠금 유지 → true', () => {
      // 키화 전 lang named-param 은 custom 키가 아니므로 data_bound 유지(종전 동작 보존).
      expect(isSelfDataBoundNode({ text: '$t:user.identity.remaining|count={{Math.max(0,n)}}' })).toBe(true);
    });

    it('custom param 키 text 라도 props 바인딩이 따로 있으면 true (props 는 별개)', () => {
      expect(isSelfDataBoundNode({ text: '$t:custom.x.1|p0={{a.b}}', props: { href: '/p/{{post.id}}' } })).toBe(true);
    });
  });
});

describe('layoutTreeUtils — isNodeLocked (매트릭스)', () => {
  describe('route 편집 모드', () => {
    it('base 노드 → 잠금', () => {
      expect(isNodeLocked({ __source: { kind: 'base' } }, 'route')).toBe(true);
    });

    it('route 노드 → 편집 가능', () => {
      expect(isNodeLocked({ __source: { kind: 'route' } }, 'route')).toBe(false);
    });

    it('extension 노드 → 잠금', () => {
      expect(isNodeLocked({ __source: { kind: 'extension', extensionId: 1 } }, 'route')).toBe(true);
    });

    it('partial 노드 → 잠금', () => {
      expect(isNodeLocked({ __source: { kind: 'partial' } }, 'route')).toBe(true);
    });
  });

  describe('base 편집 모드', () => {
    it('base 노드 → 편집 가능', () => {
      expect(isNodeLocked({ __source: { kind: 'base' } }, 'base')).toBe(false);
    });

    // base 편집 모드는 base 레이아웃을 단독 로드하며, 그 노드는 백엔드가 기본값
    // `kind:'route'` 로 태깅한다(머지 컨텍스트 부재). 단독 로드된 base 노드 = 곧 base 본체이므로
    // 편집 가능해야 한다(route 출처라고 잠그면 base 전체가 잠기는 회귀 — "공통 레이아웃 편집 시
    // 전체 잠금").
    it('route 출처 노드(단독 base 본체) → 편집 가능', () => {
      expect(isNodeLocked({ __source: { kind: 'route' } }, 'base')).toBe(false);
    });

    it('base 에 주입된 확장 노드 → 잠금', () => {
      expect(isNodeLocked({ __source: { kind: 'extension', extensionId: 3 } }, 'base')).toBe(true);
    });
  });

  describe('extension 편집 모드', () => {
    it('편집 중 확장 노드 → 편집 가능', () => {
      const node: EditorNode = { __source: { kind: 'extension', extensionId: 7 } };
      expect(isNodeLocked(node, 'extension', 7)).toBe(false);
    });

    it('다른 확장 노드 → 잠금', () => {
      const node: EditorNode = { __source: { kind: 'extension', extensionId: 7 } };
      expect(isNodeLocked(node, 'extension', 99)).toBe(true);
    });

    it('base/route/partial 노드 → 잠금', () => {
      expect(isNodeLocked({ __source: { kind: 'base' } }, 'extension', 7)).toBe(true);
      expect(isNodeLocked({ __source: { kind: 'route' } }, 'extension', 7)).toBe(true);
      expect(isNodeLocked({ __source: { kind: 'partial' } }, 'extension', 7)).toBe(true);
    });
  });

  describe('모달 편집 모드', () => {
    it('일반 모달 children (메타 없음) → 편집 가능', () => {
      expect(isNodeLocked({}, 'modal')).toBe(false);
    });

    it('모달 안 extension 주입 노드 → 잠금', () => {
      expect(isNodeLocked({ __source: { kind: 'extension', extensionId: 1 } }, 'modal')).toBe(true);
    });
  });

  it('extension_point 노드는 어느 모드에서도 잠금', () => {
    const node: EditorNode = { type: 'extension_point', name: 'sidebar' };
    expect(isNodeLocked(node, 'route')).toBe(true);
    expect(isNodeLocked(node, 'base')).toBe(true);
    expect(isNodeLocked(node, 'modal')).toBe(true);
    expect(isNodeLocked(node, 'extension', 7)).toBe(true);
  });

  describe('iteration_item 편집 모드 ', () => {
    // 반복 항목 편집 모드는 항목 템플릿(children)만 단독 렌더하므로, 캔버스에 보이는
    // 모든 노드가 편집 대상(잠금 없음). 항목 템플릿 외 노드는 애초에 렌더되지 않는다.
    it('일반 항목 템플릿 children → 편집 가능(잠금 없음)', () => {
      expect(isNodeLocked({}, 'iteration_item')).toBe(false);
      expect(isNodeLocked({ __source: { kind: 'route' } }, 'iteration_item')).toBe(false);
    });

    it('단, extension_point 노드는 iteration_item 에서도 잠금(전 모드 공통)', () => {
      const node: EditorNode = { type: 'extension_point', name: 'x' };
      expect(isNodeLocked(node, 'iteration_item')).toBe(true);
    });
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 트리 조작 함수
// ─────────────────────────────────────────────────────────────────────────────

function makeTree(): EditorNode {
  return {
    type: 'root',
    children: [
      { type: 'basic', name: 'Div', children: [
        { type: 'basic', name: 'Span', text: 'a' },
        { type: 'basic', name: 'Span', text: 'b' },
      ] },
      { type: 'basic', name: 'P', text: 'paragraph' },
    ],
  };
}

describe('layoutTreeUtils — findNodeByPath / findParentByPath ', () => {
  it('빈 경로 = 루트', () => {
    const tree = makeTree();
    expect(findNodeByPath(tree, [])).toBe(tree);
  });

  it('1-depth 경로', () => {
    const tree = makeTree();
    const node = findNodeByPath(tree, [1]);
    expect(node?.name).toBe('P');
  });

  it('2-depth 경로', () => {
    const tree = makeTree();
    const node = findNodeByPath(tree, [0, 1]);
    expect(node?.text).toBe('b');
  });

  it('경로 범위 초과 → null', () => {
    const tree = makeTree();
    expect(findNodeByPath(tree, [5])).toBeNull();
    expect(findNodeByPath(tree, [0, 10])).toBeNull();
  });

  it('findParentByPath — 루트의 부모는 null', () => {
    const tree = makeTree();
    expect(findParentByPath(tree, [])).toBeNull();
  });

  it('findParentByPath — 정상 경로', () => {
    const tree = makeTree();
    expect(findParentByPath(tree, [0, 1])?.name).toBe('Div');
  });
});

describe('layoutTreeUtils — insertNode ', () => {
  it('루트 children 끝에 삽입', () => {
    const tree = makeTree();
    const newNode: EditorNode = { type: 'basic', name: 'Hr' };
    const next = insertNode(tree, [], 2, newNode);
    expect((next.children as EditorNode[]).length).toBe(3);
    expect((next.children as EditorNode[])[2]?.name).toBe('Hr');
  });

  it('중간 위치에 삽입', () => {
    const tree = makeTree();
    const newNode: EditorNode = { type: 'basic', name: 'Hr' };
    const next = insertNode(tree, [], 1, newNode);
    const children = next.children as EditorNode[];
    expect(children[0]?.name).toBe('Div');
    expect(children[1]?.name).toBe('Hr');
    expect(children[2]?.name).toBe('P');
  });

  it('자식 컨테이너 안에 삽입', () => {
    const tree = makeTree();
    const newNode: EditorNode = { type: 'basic', name: 'Img' };
    const next = insertNode(tree, [0], 1, newNode);
    const innerChildren = (next.children as EditorNode[])[0]?.children as EditorNode[];
    expect(innerChildren.length).toBe(3);
    expect(innerChildren[1]?.name).toBe('Img');
  });

  it('범위 초과 인덱스 → 끝으로 클램프', () => {
    const tree = makeTree();
    const newNode: EditorNode = { type: 'basic', name: 'Hr' };
    const next = insertNode(tree, [], 99, newNode);
    expect((next.children as EditorNode[])[2]?.name).toBe('Hr');
  });

  it('원본 트리는 변경되지 않음 (immutable)', () => {
    const tree = makeTree();
    const before = JSON.stringify(tree);
    insertNode(tree, [], 0, { type: 'basic', name: 'Hr' });
    expect(JSON.stringify(tree)).toBe(before);
  });
});

describe('layoutTreeUtils — removeNode ', () => {
  it('루트 children 의 노드 제거', () => {
    const tree = makeTree();
    const next = removeNode(tree, [1]);
    const children = next.children as EditorNode[];
    expect(children.length).toBe(1);
    expect(children[0]?.name).toBe('Div');
  });

  it('자식 컨테이너 안의 노드 제거', () => {
    const tree = makeTree();
    const next = removeNode(tree, [0, 0]);
    const innerChildren = (next.children as EditorNode[])[0]?.children as EditorNode[];
    expect(innerChildren.length).toBe(1);
    expect(innerChildren[0]?.text).toBe('b');
  });

  it('빈 경로 = 루트 제거 시도 → 변경 없음', () => {
    const tree = makeTree();
    const next = removeNode(tree, []);
    expect(next).toBe(tree);
  });

  it('범위 외 인덱스 → 변경 없음', () => {
    const tree = makeTree();
    const next = removeNode(tree, [99]);
    expect((next.children as EditorNode[]).length).toBe(2);
  });
});

describe('layoutTreeUtils — moveNode ', () => {
  it('형제 위치 교체 (동일 부모, fromIndex < toIndex)', () => {
    const tree = makeTree();
    // 0 → 2 위치 = 두 번째 형제 뒤 (인덱스 보정 후 1 = 끝)
    const next = moveNode(tree, [0], [], 2);
    const children = next.children as EditorNode[];
    expect(children[0]?.name).toBe('P');
    expect(children[1]?.name).toBe('Div');
  });

  it('다른 컨테이너로 이동', () => {
    const tree = makeTree();
    const next = moveNode(tree, [1], [0], 0);
    const div = (next.children as EditorNode[])[0];
    const innerChildren = div?.children as EditorNode[];
    expect(innerChildren[0]?.name).toBe('P');
    expect(innerChildren.length).toBe(3);
    // root children 에서 P 제거됨
    expect((next.children as EditorNode[]).length).toBe(1);
  });

  it('자신의 자손으로 이동 시도 → 거부', () => {
    const tree = makeTree();
    // Div([0]) 를 그 자식 Span([0,0]) 안으로 이동 시도 — 사이클
    const next = moveNode(tree, [0], [0, 0], 0);
    // 변경 없어야 함
    expect((next.children as EditorNode[])[0]?.name).toBe('Div');
  });

  it('앞쪽 형제를 뒤쪽 컨테이너 안으로 이동해도 노드 유실 없음', () => {
    // 루트 [A, G(컨테이너)]. A([0]) 를 G([1]) 안으로. A 제거 시 G 가 [1]→[0] 으로
    // 밀리므로 toParentPath rebase 없으면 insert 실패 → A 유실.
    const tree: EditorNode = {
      type: 'root',
      children: [
        { name: 'Div', id: 'A' },
        { name: 'Div', id: 'G', children: [{ name: 'Div', id: 'w0' }, { name: 'Div', id: 'w1' }] },
      ],
    };
    const next = moveNode(tree, [0], [1], 1); // A → G.children[1]
    const roots = next.children as EditorNode[];
    expect(roots.length, '루트엔 G 하나만').toBe(1);
    expect(roots[0]?.id).toBe('G');
    const gChildren = roots[0]?.children as EditorNode[];
    expect(gChildren.map((c) => c.id), 'A 가 w0,w1 사이로 보존되어 이동').toEqual(['w0', 'A', 'w1']);
  });
});

describe('layoutTreeUtils — rebasePathAfterRemoval', () => {
  it('fromPath 의 뒤쪽 형제 path 는 -1 (제거로 당겨짐)', () => {
    // [A(0), G(1)] 에서 A(0) 제거 → G 의 path [1] → [0]
    expect(rebasePathAfterRemoval([1], [0])).toEqual([0]);
    expect(rebasePathAfterRemoval([1, 0], [0])).toEqual([0, 0]);
    expect(rebasePathAfterRemoval([2], [0])).toEqual([1]);
  });

  it('fromPath 의 앞쪽 형제 / 다른 가지 path 는 불변', () => {
    expect(rebasePathAfterRemoval([0], [1])).toEqual([0]); // 앞쪽 형제 (0 < 1)
    expect(rebasePathAfterRemoval([0, 1], [3])).toEqual([0, 1]); // 다른 가지 (0 < 3)
  });

  it('같은 부모의 뒤쪽 형제는 깊은 레벨에서도 -1', () => {
    // [2,3] 제거 → node [2] 의 4번째 자식 제거. 그 뒤 형제 [2,5] → [2,4].
    expect(rebasePathAfterRemoval([2, 5], [2, 3])).toEqual([2, 4]);
  });

  it('더 얕은 경로 / 빈 fromPath 는 불변', () => {
    expect(rebasePathAfterRemoval([], [0])).toEqual([]);
    expect(rebasePathAfterRemoval([0], [])).toEqual([0]);
  });
});

describe('layoutTreeUtils — duplicateNode (id 미부여 정책)', () => {
  it('id 없는 노드는 복제본도 id 없음', () => {
    const node: EditorNode = { type: 'basic', name: 'Div' };
    const cloned = duplicateNode(node);
    expect(cloned.id).toBeUndefined();
    expect(cloned).not.toBe(node);
  });

  it('id 있는 노드는 복제본의 id 만 재생성', () => {
    const node: EditorNode = { type: 'basic', name: 'Div', id: 'original_id_123' };
    const cloned = duplicateNode(node);
    expect(cloned.id).not.toBe('original_id_123');
    expect(typeof cloned.id).toBe('string');
    expect(cloned.id as string).toMatch(/^Div_/);
  });

  it('자식 트리도 동일 규칙으로 재귀', () => {
    const node: EditorNode = {
      type: 'basic', name: 'Div', id: 'parent_id',
      children: [
        { type: 'basic', name: 'Span' }, // no id
        { type: 'basic', name: 'A', id: 'child_id' },
      ],
    };
    const cloned = duplicateNode(node);
    const children = cloned.children as EditorNode[];
    expect(children[0]?.id).toBeUndefined();
    expect(children[1]?.id).not.toBe('child_id');
  });
});

describe('layoutTreeUtils — patchNode / ensureNodeId / validate ', () => {
  it('patchNode — 경로 노드 교체', () => {
    const tree = makeTree();
    const next = patchNode(tree, [0, 0], (node) => ({ ...node, text: 'changed' }));
    const replaced = ((next.children as EditorNode[])[0]?.children as EditorNode[])[0];
    expect(replaced?.text).toBe('changed');
  });

  it('ensureNodeId — id 있는 노드는 그대로', () => {
    const node: EditorNode = { type: 'basic', name: 'Div', id: 'foo' };
    expect(ensureNodeId(node)).toBe(node);
  });

  it('ensureNodeId — id 없으면 부여', () => {
    const node: EditorNode = { type: 'basic', name: 'Div' };
    const ensured = ensureNodeId(node);
    expect(ensured.id).toBeDefined();
    expect(ensured.id as string).toMatch(/^Div_/);
    // 원본 변경 없음 (immutable)
    expect(node.id).toBeUndefined();
  });

  it('validate — 정상 트리는 빈 배열', () => {
    const tree = makeTree();
    expect(validate(tree)).toEqual([]);
  });

  it('validate — type/name 둘 다 없는 자식 노드 감지', () => {
    const tree: EditorNode = {
      children: [
        { text: 'no type or name' } as EditorNode,
      ],
    };
    const issues = validate(tree);
    expect(issues.length).toBeGreaterThan(0);
    expect(issues[0]?.code).toBe('missing_type_and_name');
  });

  it('validate — children 이 배열이 아닌 경우 감지', () => {
    const tree: EditorNode = {
      children: 'not-an-array' as unknown as EditorNode[],
    };
    const issues = validate(tree);
    expect(issues.length).toBeGreaterThan(0);
    expect(issues[0]?.code).toBe('children_not_array');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 저장 페이로드 마스킹
// ─────────────────────────────────────────────────────────────────────────────

describe('stripInheritedNodes — base/extension/partial 노드 마스킹', () => {
  it('__source.kind === "base" 노드는 통째 제거', () => {
    const components: EditorNode[] = [
      { type: 'basic', name: 'Div', __source: { kind: 'base', layout: '_base' } },
      { type: 'basic', name: 'P', __source: { kind: 'route', layout: 'index' } },
    ];
    const result = stripInheritedNodes(components);
    expect(result).toHaveLength(1);
    expect(result[0]?.name).toBe('P');
  });

  it('_fromBase: true 노드는 통째 제거 (kind 부재여도)', () => {
    const components: EditorNode[] = [
      { type: 'basic', name: 'Header', _fromBase: true } as EditorNode,
      { type: 'basic', name: 'Main' },
    ];
    const result = stripInheritedNodes(components);
    expect(result).toHaveLength(1);
    expect(result[0]?.name).toBe('Main');
  });

  it('__source.kind === "extension" 노드는 통째 제거', () => {
    const components: EditorNode[] = [
      {
        type: 'composite',
        name: 'AdBanner',
        __source: { kind: 'extension', extensionId: 7 },
      },
      { type: 'basic', name: 'Body' },
    ];
    const result = stripInheritedNodes(components);
    expect(result).toHaveLength(1);
    expect(result[0]?.name).toBe('Body');
  });

  it('__source.kind === "partial" 노드는 통째 제거', () => {
    const components: EditorNode[] = [
      { type: 'basic', name: 'PartialBlock', __source: { kind: 'partial' } },
      { type: 'basic', name: 'Real' },
    ];
    const result = stripInheritedNodes(components);
    expect(result).toHaveLength(1);
    expect(result[0]?.name).toBe('Real');
  });

  it('__source.kind === "route" 노드는 보존하고 메타만 제거', () => {
    const components: EditorNode[] = [
      { type: 'basic', name: 'P', __source: { kind: 'route', layout: 'index' } },
    ];
    const result = stripInheritedNodes(components);
    expect(result).toHaveLength(1);
    expect(result[0]).not.toHaveProperty('__source');
    expect(result[0]).not.toHaveProperty('_fromBase');
    expect(result[0]?.name).toBe('P');
  });

  it('메타 미부여 노드(사용자가 새로 추가)는 보존', () => {
    const components: EditorNode[] = [
      { type: 'basic', name: 'NewDiv', props: { className: 'x' } },
    ];
    const result = stripInheritedNodes(components);
    expect(result).toHaveLength(1);
    expect(result[0]?.name).toBe('NewDiv');
  });

  it('route 노드의 children 안에 base/extension 노드가 섞이면 children 만 제거', () => {
    const components: EditorNode[] = [
      {
        type: 'basic',
        name: 'Main',
        __source: { kind: 'route', layout: 'index' },
        children: [
          { type: 'basic', name: 'KeepMe' },
          {
            type: 'composite',
            name: 'AdBanner',
            __source: { kind: 'extension', extensionId: 7 },
          },
          { type: 'basic', name: 'Header', _fromBase: true } as EditorNode,
        ],
      },
    ];
    const result = stripInheritedNodes(components);
    expect(result).toHaveLength(1);
    expect(result[0]?.name).toBe('Main');
    const children = result[0]?.children as EditorNode[];
    expect(children).toHaveLength(1);
    expect(children[0]?.name).toBe('KeepMe');
  });

  it('빈 입력/undefined 는 빈 배열 반환', () => {
    expect(stripInheritedNodes(undefined)).toEqual([]);
    expect(stripInheritedNodes([])).toEqual([]);
  });

  it('원본 노드 객체를 변형하지 않음 (불변성)', () => {
    const original: EditorNode = {
      type: 'basic',
      name: 'P',
      __source: { kind: 'route', layout: 'index' },
      children: [{ type: 'basic', name: 'C', _fromBase: true } as EditorNode],
    };
    const snapshot = JSON.parse(JSON.stringify(original));
    stripInheritedNodes([original]);
    expect(original).toEqual(snapshot);
  });
});

describe('stripInheritedFromLayoutContent — 레이아웃 content 전체 마스킹', () => {
  it('extends 자식 — 머지된 components 트리에서 base 노드 children 의 route 콘텐츠 추출', () => {
    // 편집기 응답 형태: 머지된 components 트리(base + slot 래퍼 + route 콘텐츠) 가 포함된 상태
    const content = {
      version: '1.0',
      layout_name: 'index',
      extends: '_user_base',
      // 머지된 트리: base 노드들이 깊은 트리를 형성하고 slot 래퍼 children 에 route 콘텐츠가 있음
      components: [
        { type: 'basic', name: 'Header', _fromBase: true } as EditorNode,
        {
          type: 'basic',
          name: 'MainSlot',
          __source: { kind: 'base', layout: '_user_base' }, // slot 래퍼
          children: [
            { type: 'basic', name: 'PageBody', __source: { kind: 'route', layout: 'index' } },
            { type: 'basic', name: 'NewDiv' }, // 사용자 추가 (메타 미부여)
          ],
        },
      ],
      __editor: {
        original: {
          version: '1.0',
          layout_name: 'index',
          extends: '_user_base',
          slots: { main: [] }, // 빈 슬롯 — 골격 메타로만 사용
        },
      },
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result).not.toHaveProperty('components');
    const mainSlot = (result.slots as Record<string, EditorNode[]>).main;
    expect(mainSlot).toHaveLength(2);
    expect(mainSlot[0]?.name).toBe('PageBody');
    expect(mainSlot[0]).not.toHaveProperty('__source');
    expect(mainSlot[1]?.name).toBe('NewDiv');
  });

  it('extends 자식 — 경로 A: 클라이언트가 이미 마스킹한 slots 만 있고 components 없음 → 그대로 보존', () => {
    const content = {
      version: '1.0',
      layout_name: 'index',
      extends: '_user_base',
      slots: {
        content: [
          { type: 'layout', name: 'Container', children: [{ type: 'basic', name: 'X' }] },
          { type: 'basic', name: 'Div' },
        ],
      },
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result).not.toHaveProperty('components');
    const slot = (result.slots as Record<string, EditorNode[]>).content;
    expect(slot).toHaveLength(2);
    expect(slot[0]?.name).toBe('Container');
    expect(slot[1]?.name).toBe('Div');
  });

  it('extends 없는 독립 레이아웃: components 마스킹 + slots 미정의 보존', () => {
    const content = {
      version: '1.0',
      layout_name: '_base',
      components: [
        { type: 'basic', name: 'A', __source: { kind: 'route', layout: '_base' } },
        {
          type: 'composite',
          name: 'AdBanner',
          __source: { kind: 'extension', extensionId: 3 },
        },
      ],
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result.components).toHaveLength(1);
    expect((result.components as EditorNode[])[0]?.name).toBe('A');
    expect((result.components as EditorNode[])[0]).not.toHaveProperty('__source');
  });

  it('lock_version 키는 응답 전용 — 페이로드에서 제거', () => {
    const content = {
      version: '1.0',
      layout_name: 'index',
      lock_version: 42,
      components: [{ type: 'basic', name: 'A' }],
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result).not.toHaveProperty('lock_version');
  });

  it('extends 빈 문자열은 미설정으로 간주 (components 보존)', () => {
    const content = {
      version: '1.0',
      layout_name: 'index',
      extends: '',
      components: [{ type: 'basic', name: 'A' }],
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result.components).toHaveLength(1);
  });

  it('원본 content 객체를 변형하지 않음 (shallow copy)', () => {
    const original: Record<string, unknown> = {
      version: '1.0',
      layout_name: 'index',
      lock_version: 1,
      components: [
        { type: 'basic', name: 'X', __source: { kind: 'route', layout: 'index' } },
      ],
    };
    const snapshot = JSON.parse(JSON.stringify(original));
    stripInheritedFromLayoutContent(original);
    expect(original).toEqual(snapshot);
  });

  it('meta/data_sources/modals 등 메타 키는 그대로 통과', () => {
    const content = {
      version: '1.0',
      layout_name: 'index',
      meta: { title: 'Home' },
      data_sources: [{ id: 'users' }],
      modals: { confirm: { component: 'Modal' } },
      components: [{ type: 'basic', name: 'A' }],
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result.meta).toEqual({ title: 'Home' });
    expect(result.data_sources).toEqual([{ id: 'users' }]);
    expect(result.modals).toEqual({ confirm: { component: 'Modal' } });
  });

  // ─── 결함 P — __editor.original 우선 경로 ──────────────────────────────
  it('__editor.original 이 있으면 그것을 SSoT 로 사용 (머지된 components 무시)', () => {
    const content = {
      // 머지된 응답 (사용자가 캔버스에서 보는 형태)
      version: '1.0',
      layout_name: 'home',
      meta: { title: 'Home (merged)' },
      components: [
        { type: 'basic', name: 'Header', _fromBase: true },
        { type: 'basic', name: 'SlotWrapper', _fromBase: true, children: [
          { type: 'basic', name: 'Welcome', __source: { kind: 'route', layout: 'home' } },
        ]},
      ],
      lock_version: 7,
      // 백엔드가 부착한 자식 원본
      __editor: {
        original: {
          version: '1.0',
          layout_name: 'home',
          extends: '_user_base',
          slots: {
            main: [
              { type: 'basic', name: 'Welcome' },
            ],
          },
          meta: { title: 'Home (original)' },
        },
      },
    };
    const result = stripInheritedFromLayoutContent(content);
    // 원본 그대로 보존 — extends + slots
    expect(result.extends).toBe('_user_base');
    expect(result.slots).toEqual({ main: [{ type: 'basic', name: 'Welcome' }] });
    // 머지된 components 는 결과에 없음
    expect(result).not.toHaveProperty('components');
    // 원본의 meta 가 우선 (머지된 meta 무시)
    expect(result.meta).toEqual({ title: 'Home (original)' });
    // 응답 전용 메타 제거
    expect(result).not.toHaveProperty('lock_version');
    expect(result).not.toHaveProperty('__editor');
  });

  it('__editor.original 이 독립 레이아웃이면 components 그대로 사용', () => {
    const content = {
      version: '1.0',
      layout_name: '_base',
      components: [
        { type: 'basic', name: 'Root', __source: { kind: 'route', layout: '_base' } },
      ],
      lock_version: 3,
      __editor: {
        original: {
          version: '1.0',
          layout_name: '_base',
          endpoint: '/api/admin/test',
          components: [{ type: 'basic', name: 'Root' }],
        },
      },
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result.endpoint).toBe('/api/admin/test');
    expect(result.components).toEqual([{ type: 'basic', name: 'Root' }]);
    // 원본에 메타가 없으므로 결과도 메타 없음
    expect(result.components).toHaveLength(1);
    expect((result.components as EditorNode[])[0]).not.toHaveProperty('__source');
  });

  it('__editor 미존재 시 폴백 경로 (레거시 응답) 동작', () => {
    const content = {
      version: '1.0',
      layout_name: 'index',
      extends: '_user_base',
      slots: {
        main: [
          { type: 'basic', name: 'PageBody', __source: { kind: 'route', layout: 'index' } },
        ],
      },
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result.extends).toBe('_user_base');
    const mainSlot = (result.slots as Record<string, EditorNode[]>).main;
    expect(mainSlot).toHaveLength(1);
    expect(mainSlot[0]?.name).toBe('PageBody');
    expect(mainSlot[0]).not.toHaveProperty('__source');
  });

  it('__editor.original 이 객체가 아닌 잘못된 값이면 폴백 경로 사용', () => {
    const content = {
      version: '1.0',
      layout_name: 'index',
      extends: '_user_base',
      __editor: { original: 'not-an-object' as unknown },
      slots: { main: [{ type: 'basic', name: 'X' }] },
    };
    const result = stripInheritedFromLayoutContent(content);
    expect(result.extends).toBe('_user_base');
    expect(result.slots).toBeDefined();
    expect(result).not.toHaveProperty('__editor');
  });
});

describe('normalizeToExtensionEntry — 확장 조각 통짜 선택 정규화 ', () => {
  // 트리: root.children = [ route, base, extEntry(ext10) { extChild { extLeaf } } ]
  //  - [0] route
  //  - [1] base
  //  - [2] extension 진입점(extId 10) → [2,0] extension 자식 → [2,0,0] extension 손자
  const root: EditorNode = {
    children: [
      { name: 'RouteDiv', __source: { kind: 'route' } },
      { name: 'BaseDiv', __source: { kind: 'base' } },
      {
        name: 'ExtRoot',
        __source: { kind: 'extension', extensionId: 10 },
        children: [
          {
            name: 'ExtChild',
            __source: { kind: 'extension', extensionId: 10 },
            children: [
              { name: 'ExtLeaf', __source: { kind: 'extension', extensionId: 10 } },
            ],
          },
        ],
      },
    ],
  };

  it('확장 조각 내부 자식 path → 진입점 path 로 정규화', () => {
    expect(normalizeToExtensionEntry(root, [2, 0, 0])).toEqual([2]); // 손자 → 진입점
    expect(normalizeToExtensionEntry(root, [2, 0])).toEqual([2]); // 자식 → 진입점
  });

  it('확장 진입점 자체는 그대로 (이미 진입점)', () => {
    expect(normalizeToExtensionEntry(root, [2])).toEqual([2]);
  });

  it('일반 노드(route/base)는 정규화하지 않음', () => {
    expect(normalizeToExtensionEntry(root, [0])).toEqual([0]); // route
    expect(normalizeToExtensionEntry(root, [1])).toEqual([1]); // base
  });

  it('확장 편집 모드에서 편집 중 확장(currentExtensionId 일치)은 정규화 제외', () => {
    // 편집 중 확장(10)이면 그 내부 자식은 자유 편집 대상 — 진입점으로 올리지 않음
    expect(normalizeToExtensionEntry(root, [2, 0, 0], 10)).toEqual([2, 0, 0]);
    // 다른 확장(99) 편집 중이면 확장 10 조각은 여전히 통짜 잠금 → 진입점으로
    expect(normalizeToExtensionEntry(root, [2, 0, 0], 99)).toEqual([2]);
  });

  it('빈 경로 / 경로 불일치는 원본 반환', () => {
    expect(normalizeToExtensionEntry(root, [])).toEqual([]);
    expect(normalizeToExtensionEntry(root, [9, 9])).toEqual([9, 9]); // 존재하지 않는 인덱스
    expect(normalizeToExtensionEntry(null, [2, 0])).toEqual([2, 0]);
  });
});

describe('resolveBaseEditTarget — 공통 레이아웃 편집 진입 식별자', () => {
  it('선택 노드 base 출처(__source.layout)를 우선 사용 — 라우트 layoutName 아님', () => {
    // 회귀: board/form 라우트에서 _user_base 노드 선택 후 진입 시 _user_base 로 가야 함
    expect(resolveBaseEditTarget('_user_base', 'board/form')).toBe('_user_base');
  });

  it('base 출처가 없으면 라우트 layoutName 으로 폴백', () => {
    expect(resolveBaseEditTarget(null, 'board/form')).toBe('board/form');
    expect(resolveBaseEditTarget(undefined, 'home')).toBe('home');
  });

  it('둘 다 없으면 null', () => {
    expect(resolveBaseEditTarget(null, null)).toBeNull();
    expect(resolveBaseEditTarget(undefined, undefined)).toBeNull();
  });
});

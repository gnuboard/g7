/**
 * responsiveBranchPath — 디바이스 분기(responsive 자식 교체) 노드의 편집 path 1급화
 * 단위 테스트.
 *
 * 검증 축:
 *  - parseEditorPath ↔ serializeEditorPath round-trip (responsive 세그먼트 보존,
 *    iteration/sortable 가상 인덱스 제외와 공존).
 *  - findNodeByPath / patchNode / removeNode / insertNode 의 분기 children 하강
 *    (base children 불변).
 *  - segEqual / moveNode / rebase 의 세그먼트 객체 비교(G-1 참조비교 회귀 방지).
 *  - number-only path 동작 불변(무손실 회귀 가드).
 *
 * @since engine-v1.50.0
 */

import { describe, expect, it } from 'vitest';
import {
  parseEditorPath,
} from '../../hooks/useElementSelection';
import { sameBranchContext } from '../../hooks/useCanvasDnd';
import { resolveGlobalInsertionTarget } from '../../dnd/nestingRules';
import type { NestingSpec } from '../../spec/specTypes';
import {
  serializeEditorPath,
  segEqual,
  isResponsiveSegment,
  findNodeByPath,
  findParentByPath,
  patchNode,
  removeNode,
  insertNode,
  moveNode,
  separateBranch,
  mergeBranch,
  type EditorNode,
  type ComponentPath,
} from '../../utils/layoutTreeUtils';

// 분기 children 교체형 트리:
//   root.children[0] = Div(base) → children: [SpanA]
//                                → responsive.portable.children: [SpanM0, SpanM1]
function makeBranchTree(): EditorNode {
  return {
    children: [
      {
        name: 'Div',
        children: [{ name: 'Span', text: 'base-A' }],
        responsive: {
          portable: {
            children: [
              { name: 'Span', text: 'mobile-0' },
              { name: 'Span', text: 'mobile-1' },
            ],
          },
        },
      } as EditorNode,
    ],
  } as EditorNode;
}

describe('parseEditorPath ↔ serializeEditorPath round-trip', () => {
  it('number-only path 는 동작 불변(무손실 회귀)', () => {
    expect(parseEditorPath('0.children.2')).toEqual([0, 2]);
    expect(serializeEditorPath([0, 2])).toBe('0.children.2');
  });

  it('responsive 세그먼트 보존 — 산출 ↔ 해석 왕복', () => {
    const dom = '0.responsive.portable.children.1';
    const parsed = parseEditorPath(dom);
    expect(parsed).toEqual([0, { responsive: 'portable' }, 1]);
    expect(serializeEditorPath(parsed)).toBe(dom);
  });

  it('responsive + iteration 공존 — 가상 인덱스 제외, 분기 보존 (G-2)', () => {
    // 분기 안 iteration 인스턴스 #3 의 첫 자식. iteration.3 은 가상 → 제외,
    // responsive.portable 는 실 위치 → 보존.
    const dom = '0.responsive.portable.children.0.iteration.3.children.0';
    const parsed = parseEditorPath(dom);
    expect(parsed).toEqual([0, { responsive: 'portable' }, 0, 0]);
    // 재직렬화는 가상 인덱스가 빠진 정규형.
    expect(serializeEditorPath(parsed)).toBe('0.responsive.portable.children.0.children.0');
  });

  it('커스텀 범위 키도 보존', () => {
    const parsed = parseEditorPath('1.responsive.600-900.children.0');
    expect(parsed).toEqual([1, { responsive: '600-900' }, 0]);
    expect(serializeEditorPath(parsed)).toBe('1.responsive.600-900.children.0');
  });
});

describe('segEqual (G-1 — 객체 세그먼트 참조비교 금지)', () => {
  it('의미상 같은 responsive 세그먼트는 같음(별개 객체여도)', () => {
    expect(segEqual({ responsive: 'portable' }, { responsive: 'portable' })).toBe(true);
  });
  it('다른 분기 키는 다름', () => {
    expect(segEqual({ responsive: 'portable' }, { responsive: 'mobile' })).toBe(false);
  });
  it('number vs responsive 는 다름', () => {
    expect(segEqual(0, { responsive: 'portable' })).toBe(false);
    expect(segEqual(0, 0)).toBe(true);
  });
  it('isResponsiveSegment 판정', () => {
    expect(isResponsiveSegment({ responsive: 'portable' })).toBe(true);
    expect(isResponsiveSegment(0)).toBe(false);
  });
});

describe('findNodeByPath — 분기 children 하강', () => {
  it('responsive 세그먼트로 분기 children 노드 조회', () => {
    const root = makeBranchTree();
    const node = findNodeByPath(root, [0, { responsive: 'portable' }, 1]);
    expect(node?.text).toBe('mobile-1');
  });
  it('number-only 는 base children 조회(불변)', () => {
    const root = makeBranchTree();
    const node = findNodeByPath(root, [0, 0]);
    expect(node?.text).toBe('base-A');
  });
  it('findParentByPath — 분기 노드의 부모는 분기 소유 노드', () => {
    const root = makeBranchTree();
    const parent = findParentByPath(root, [0, { responsive: 'portable' }, 1]);
    expect(parent?.name).toBe('Div');
  });
});

describe('patchNode — 분기 노드만 변경, base 불변', () => {
  it('분기 노드 text 패치 → base children 원본 유지', () => {
    const root = makeBranchTree();
    const next = patchNode(root, [0, { responsive: 'portable' }, 0], (n) => ({
      ...n,
      text: 'mobile-0-edited',
    }));
    // 분기 변경 반영
    expect(findNodeByPath(next, [0, { responsive: 'portable' }, 0])?.text).toBe('mobile-0-edited');
    // base children 불변
    expect(findNodeByPath(next, [0, 0])?.text).toBe('base-A');
    // 원본 트리 불변(immutable)
    expect(findNodeByPath(root, [0, { responsive: 'portable' }, 0])?.text).toBe('mobile-0');
  });
});

describe('removeNode / insertNode — 분기 children 대상', () => {
  it('분기 노드 삭제 → 분기 children 에서만 제거, base 불변', () => {
    const root = makeBranchTree();
    const next = removeNode(root, [0, { responsive: 'portable' }, 0]);
    const branchChildren = findNodeByPath(next, [0])?.responsive?.portable?.children as EditorNode[];
    expect(branchChildren).toHaveLength(1);
    expect(branchChildren[0]?.text).toBe('mobile-1');
    // base 불변
    expect(findNodeByPath(next, [0, 0])?.text).toBe('base-A');
  });

  it('분기 children 에 삽입 → 그 분기에만, base 불변', () => {
    const root = makeBranchTree();
    const next = insertNode(
      root,
      [0, { responsive: 'portable' }],
      1,
      { name: 'Span', text: 'mobile-new' } as EditorNode,
    );
    const branchChildren = findNodeByPath(next, [0])?.responsive?.portable?.children as EditorNode[];
    expect(branchChildren.map((c) => c.text)).toEqual(['mobile-0', 'mobile-new', 'mobile-1']);
    expect((findNodeByPath(next, [0])?.children as EditorNode[]).length).toBe(1); // base 불변
  });
});

describe('moveNode — 분기 내 정렬(G-1 rebase) + base 불변', () => {
  it('분기 children 0↔끝 순서 이동', () => {
    const root = makeBranchTree();
    // mobile-0(index 0) 을 index 2(끝) 로 이동
    const next = moveNode(root, [0, { responsive: 'portable' }, 0], [0, { responsive: 'portable' }], 2);
    const branchChildren = findNodeByPath(next, [0])?.responsive?.portable?.children as EditorNode[];
    expect(branchChildren.map((c) => c.text)).toEqual(['mobile-1', 'mobile-0']);
    // base 불변
    expect(findNodeByPath(next, [0, 0])?.text).toBe('base-A');
  });
});

describe('sameBranchContext — 분기 경계 가드(DnD 거부 판정)', () => {
  it('base ↔ base 는 같은 컨텍스트(이동 허용)', () => {
    expect(sameBranchContext([0], [0, 1])).toBe(true);
  });
  it('같은 분기 안끼리는 같은 컨텍스트', () => {
    expect(
      sameBranchContext(
        [0, { responsive: 'portable' }],
        [0, { responsive: 'portable' }, 1],
      ),
    ).toBe(true);
  });
  it('base ↔ 분기 는 다른 컨텍스트(이동 거부)', () => {
    expect(sameBranchContext([0], [0, { responsive: 'portable' }])).toBe(false);
  });
  it('서로 다른 분기는 다른 컨텍스트', () => {
    expect(
      sameBranchContext(
        [0, { responsive: 'portable' }],
        [0, { responsive: 'mobile' }],
      ),
    ).toBe(false);
  });
});

describe('resolveGlobalInsertionTarget — 분기 세그먼트 보존(결함② +요소추가 회귀)', () => {
  // nesting: Div 는 컨테이너(자식 accepts), Span 은 leaf.
  const nesting: NestingSpec = {
    draggable: ['Span', 'Div'],
    containers: { Div: { accepts: ['Span', 'Div'] } },
  } as unknown as NestingSpec;

  it('분기 안 leaf 선택 → 그 형제 다음(분기 세그먼트 보존)', () => {
    // 선택: [0, {responsive:'portable'}, 0] (분기 안 Span — leaf)
    // 기대: parentPath = [0, {responsive:'portable'}], index = 1 (형제 다음)
    const target = resolveGlobalInsertionTarget(
      'Span',
      0,
      [0, { responsive: 'portable' }, 0],
      nesting,
      1,
    );
    expect(target.parentPath).toEqual([0, { responsive: 'portable' }]);
    expect(target.index).toBe(1);
  });

  it('분기 안 컨테이너 선택 → 그 children 끝(분기 세그먼트 보존)', () => {
    // 선택: [0, {responsive:'portable'}, 1] (분기 안 Div — 컨테이너, 자식 2개)
    // 기대: parentPath = 그 노드 자신(분기 세그먼트 보존), index = 2 (children 끝)
    const target = resolveGlobalInsertionTarget(
      'Div',
      2,
      [0, { responsive: 'portable' }, 1],
      nesting,
      1,
    );
    expect(target.parentPath).toEqual([0, { responsive: 'portable' }, 1]);
    expect(target.index).toBe(2);
  });

  it('number-only(base) 선택 — 동작 불변(무손실 회귀)', () => {
    const leaf = resolveGlobalInsertionTarget('Span', 0, [0, 2], nesting, 5);
    expect(leaf.parentPath).toEqual([0]);
    expect(leaf.index).toBe(3);
    const container = resolveGlobalInsertionTarget('Div', 4, [1], nesting, 5);
    expect(container.parentPath).toEqual([1]);
    expect(container.index).toBe(4);
  });

  it('선택 없음 → 루트 끝(폴백 불변)', () => {
    const target = resolveGlobalInsertionTarget(undefined, 0, null, nesting, 7);
    expect(target.parentPath).toEqual([]);
    expect(target.index).toBe(7);
  });
});

describe('insertNode — 분기 안 컨테이너 자식 삽입(결함② 분기 라우팅)', () => {
  // 분기 안 컨테이너에 자식이 삽입되면 그 컨테이너의 children(분기 안)만 늘고
  // base 분기는 불변이어야 한다(라이브에서 base 로 새던 결함의 단위 잠금).
  it('분기 안 노드(leaf)의 형제로 삽입 → 분기 children 만 증가', () => {
    const root = makeBranchTree();
    // [0, {responsive:'portable'}, 0] 의 형제 다음 = parentPath [0,{responsive:'portable'}], index 1
    const next = insertNode(
      root,
      [0, { responsive: 'portable' }],
      1,
      { name: 'Span', text: 'inserted' } as EditorNode,
    );
    const branchChildren = findNodeByPath(next, [0])?.responsive?.portable?.children as EditorNode[];
    expect(branchChildren.map((c) => c.text)).toEqual(['mobile-0', 'inserted', 'mobile-1']);
    // base 분기 불변 (1개 그대로)
    expect((findNodeByPath(next, [0])?.children as EditorNode[]).map((c) => c.text)).toEqual([
      'base-A',
    ]);
  });
});

describe('deepCloneTree 스냅샷 격리 — 분기 children 참조 비공유(undo 복사 결함)', () => {
  // 분기 안 노드 이동/추가 후 undo 시 원복 안 되고 "복사(중복)"됨.
  // 근본: insertNode/removeNode/moveNode 가 만든 새 트리가 원본과 responsive 분기 배열을
  // 참조 공유하면, history 스냅샷 사이에 분기 substructure 가 번진다.
  it('insertNode 결과 트리가 원본 분기 children 배열을 공유하지 않는다', () => {
    const root = makeBranchTree();
    const originalBranch = (root.children as EditorNode[])[0]!.responsive!.portable!
      .children as EditorNode[];
    const next = insertNode(
      root,
      [0, { responsive: 'portable' }],
      1,
      { name: 'Span', text: 'new' } as EditorNode,
    );
    const nextBranch = (next.children as EditorNode[])[0]!.responsive!.portable!
      .children as EditorNode[];
    // 결과 트리의 분기 배열은 원본과 다른 인스턴스(참조 비공유).
    expect(nextBranch).not.toBe(originalBranch);
    // 원본 분기는 불변(2개 그대로) — 결과 트리만 3개.
    expect(originalBranch).toHaveLength(2);
    expect(nextBranch).toHaveLength(3);
  });

  it('한 분기 변형이 다른 스냅샷의 동일 분기를 오염시키지 않는다(undo 복사 방지)', () => {
    // 두 분기를 가진 트리: 노드 A(portable 2개) + 노드 B(portable 2개).
    const root: EditorNode = {
      children: [
        {
          name: 'DivA',
          children: [{ name: 'Span', text: 'baseA' }],
          responsive: { portable: { children: [{ name: 'Span', text: 'a0' }] } },
        },
        {
          name: 'DivB',
          children: [{ name: 'Span', text: 'baseB' }],
          responsive: { portable: { children: [{ name: 'Span', text: 'b0' }] } },
        },
      ],
    } as EditorNode;

    // 스냅샷 S1 = 노드 A 분기에 삽입한 결과.
    const s1 = insertNode(root, [0, { responsive: 'portable' }], 1, {
      name: 'Span',
      text: 'a1',
    } as EditorNode);
    // 스냅샷 S2 = S1 에서 노드 B 분기에 삽입.
    const s2 = insertNode(s1, [1, { responsive: 'portable' }], 1, {
      name: 'Span',
      text: 'b1',
    } as EditorNode);

    // S2 변형(B 분기)이 S1 의 B 분기를 오염시키면 안 된다(undo S2→S1 시 B 가 원복돼야 함).
    const s1BranchB = (s1.children as EditorNode[])[1]!.responsive!.portable!
      .children as EditorNode[];
    const s2BranchB = (s2.children as EditorNode[])[1]!.responsive!.portable!
      .children as EditorNode[];
    expect(s1BranchB.map((c) => c.text)).toEqual(['b0']); // S1 의 B 분기 불변
    expect(s2BranchB.map((c) => c.text)).toEqual(['b0', 'b1']); // S2 만 b1 추가
    // S1 의 A 분기도 S2 변형과 무관하게 보존.
    const s1BranchA = (s1.children as EditorNode[])[0]!.responsive!.portable!
      .children as EditorNode[];
    expect(s1BranchA.map((c) => c.text)).toEqual(['a0', 'a1']);
  });

  it('moveNode(분기 내) 결과가 원본 분기를 공유하지 않는다(이동→undo 중복 방지)', () => {
    const root = makeBranchTree(); // portable: [mobile-0, mobile-1]
    const originalBranch = (root.children as EditorNode[])[0]!.responsive!.portable!
      .children as EditorNode[];
    const moved = moveNode(
      root,
      [0, { responsive: 'portable' }, 0],
      [0, { responsive: 'portable' }],
      2,
    );
    const movedBranch = (moved.children as EditorNode[])[0]!.responsive!.portable!
      .children as EditorNode[];
    expect(movedBranch).not.toBe(originalBranch);
    // 원본은 불변(순서 그대로), 결과만 재정렬.
    expect(originalBranch.map((c) => c.text)).toEqual(['mobile-0', 'mobile-1']);
    expect(movedBranch.map((c) => c.text)).toEqual(['mobile-1', 'mobile-0']);
  });
});

describe('moveNode 노드 격리 — 이동 노드 참조 비공유(이동→undo 복사 결함)', () => {
  // 노드 이동 후 undo 하면 원위치에 다시 생기고 새 위치에도 남아 "복사"됨.
  // 근본: moveNode 가 findNodeByPath 로 얻은 **입력 트리 참조**를 그대로 재삽입해, 결과
  // 트리와 입력 트리(=직전 history 스냅샷)가 이동 노드 서브트리를 참조 공유했다.
  function tree(): EditorNode {
    return {
      children: [
        { name: 'A', children: [{ name: 'A0' }] },
        { name: 'B', children: [{ name: 'B0' }] },
        { name: 'C' },
      ],
    } as EditorNode;
  }

  it('이동된 노드와 그 자손이 입력 트리와 참조 공유되지 않는다(base)', () => {
    const root = tree();
    const inputA = (root.children as EditorNode[])[0]!;
    const inputA0 = (inputA.children as EditorNode[])[0]!;
    const moved = moveNode(root, [0], [], 2); // A 를 인덱스 2 위치로 이동(제거 후 보정 → [B,A,C])
    const after = moved.children as EditorNode[];
    const outputA = after.find((c) => c.name === 'A')!;
    const outputA0 = (outputA.children as EditorNode[])[0]!;
    expect(outputA).not.toBe(inputA); // 이동 노드 = 사본(참조 비공유)
    expect(outputA0).not.toBe(inputA0); // 자손도 사본
    // 입력 트리는 완전 불변 — 직전 스냅샷이 오염되지 않아 undo 정합.
    expect((root.children as EditorNode[]).map((c) => c.name)).toEqual(['A', 'B', 'C']);
    // 같은 부모 fromIndex(0) < toIndex(2) 보정으로 A 는 B 다음에 삽입된다.
    expect(after.map((c) => c.name)).toEqual(['B', 'A', 'C']);
  });

  it('컨테이너 간 이동 후 입력 스냅샷에 이동 노드가 중복 잔존하지 않는다', () => {
    const root = tree();
    // A 를 B(index 1) 의 children 안으로 이동.
    const moved = moveNode(root, [0], [1], 0);
    // 입력(직전 스냅샷)은 A 가 여전히 루트 0번 — 불변.
    expect((root.children as EditorNode[]).map((c) => c.name)).toEqual(['A', 'B', 'C']);
    // 결과는 A 가 B 안으로, 루트엔 B,C 만.
    const after = moved.children as EditorNode[];
    expect(after.map((c) => c.name)).toEqual(['B', 'C']);
    const bChildren = after[0]!.children as EditorNode[];
    expect(bChildren.map((c) => c.name)).toEqual(['A', 'B0']);
    // 이동 노드(결과 안 A)는 입력 A 와 다른 인스턴스.
    expect(bChildren[0]).not.toBe((root.children as EditorNode[])[0]);
  });
});

describe('number-only 트리 — 동작 불변(무손실 회귀)', () => {
  function plainTree(): EditorNode {
    return {
      children: [
        { name: 'Div', children: [{ name: 'A' }, { name: 'B' }, { name: 'C' }] } as EditorNode,
      ],
    } as EditorNode;
  }
  it('moveNode 정렬 — 분기 무관 동작 동일', () => {
    const root = plainTree();
    const next = moveNode(root, [0, 0], [0], 3);
    const children = findNodeByPath(next, [0])?.children as EditorNode[];
    expect(children.map((c) => c.name)).toEqual(['B', 'C', 'A']);
  });
  it('patchNode — base children 동작 동일', () => {
    const root = plainTree();
    const next = patchNode(root, [0, 1], (n) => ({ ...n, name: 'B2' }));
    expect(findNodeByPath(next, [0, 1])?.name).toBe('B2');
  });
});

describe('separateBranch — 디바이스 전용 분리 생성(신규 ③)', () => {
  function container(): EditorNode {
    return {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A', text: 'a' }, { name: 'B', text: 'b' }],
        } as EditorNode,
      ],
    } as EditorNode;
  }

  it('base children 을 깊은 사본으로 responsive[key].children 에 신설', () => {
    const root = container();
    const next = separateBranch(root, [0], 'mobile');
    const node = findNodeByPath(next, [0])!;
    // 분기 children = base children 복제(같은 형태)
    const branch = node.responsive?.mobile?.children as EditorNode[];
    expect(branch.map((c) => c.name)).toEqual(['A', 'B']);
    // base children 불변
    expect((node.children as EditorNode[]).map((c) => c.name)).toEqual(['A', 'B']);
  });

  it('분기 children 은 base children 과 참조 비공유(깊은 사본)', () => {
    const root = container();
    const next = separateBranch(root, [0], 'mobile');
    const node = findNodeByPath(next, [0])!;
    const baseFirst = (node.children as EditorNode[])[0];
    const branchFirst = (node.responsive!.mobile!.children as EditorNode[])[0];
    expect(branchFirst).not.toBe(baseFirst);
    // 분기 노드를 변형해도 base 불변
    branchFirst.name = 'A-mobile';
    expect(baseFirst.name).toBe('A');
  });

  it('이미 그 키 분기가 있으면 멱등(중복 분리 차단)', () => {
    const root = container();
    const once = separateBranch(root, [0], 'mobile');
    // 분기 children 을 사용자가 변형했다고 가정
    (findNodeByPath(once, [0])!.responsive!.mobile!.children as EditorNode[]).push({ name: 'X' });
    const twice = separateBranch(once, [0], 'mobile');
    // 두 번째 분리는 기존 분기를 덮어쓰지 않음(X 보존)
    const branch = findNodeByPath(twice, [0])!.responsive!.mobile!.children as EditorNode[];
    expect(branch.map((c) => c.name)).toEqual(['A', 'B', 'X']);
  });

  it('분기 세그먼트로 끝나는 path 는 대상 아님(no-op)', () => {
    const root = container();
    const next = separateBranch(root, [0, { responsive: 'mobile' }], 'mobile');
    expect(next).toBe(root);
  });

  it('빈 key 는 no-op', () => {
    const root = container();
    expect(separateBranch(root, [0], '')).toBe(root);
  });

  //  회귀: props-only 분기가 있어도 children 분리는 신설돼야 한다(props/children 독립).
  // 결함: 과거 가드가 `responsive[key]` 키 존재만 보고 no-op → props-only 노드는 분리 불가.
  it('props-only 분기가 이미 있어도 children 을 신설하고 props 는 보존', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A', text: 'a' }, { name: 'B', text: 'b' }],
          responsive: { mobile: { props: { className: 'p-4' } } },
        } as EditorNode,
      ],
    } as EditorNode;
    const next = separateBranch(root, [0], 'mobile');
    const node = findNodeByPath(next, [0])!;
    const branch = node.responsive!.mobile!;
    // children 이 base 복제로 신설됨
    expect((branch.children as EditorNode[]).map((c) => c.name)).toEqual(['A', 'B']);
    // 기존 props override 보존
    expect((branch.props as Record<string, unknown>).className).toBe('p-4');
    // 입력 트리 불변(순수 함수)
    expect((root.children as EditorNode[])[0].responsive!.mobile!.children).toBeUndefined();
  });

  it('children 이 이미 있는 분기는 멱등(props-only 아님 → 덮어쓰지 않음)', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A' }],
          responsive: { mobile: { props: { className: 'p-4' }, children: [{ name: 'Z' }] } },
        } as EditorNode,
      ],
    } as EditorNode;
    const next = separateBranch(root, [0], 'mobile');
    const branch = findNodeByPath(next, [0])!.responsive!.mobile!.children as EditorNode[];
    expect(branch.map((c) => c.name)).toEqual(['Z']); // 보존(덮어쓰기 없음)
  });

  // 포괄 분기(portable)에 children 이 있으면, mobile 전용 분리 생성 시
  // base 가 아니라 그 포괄 분기 children 을 복제 원본으로 쓴다(화면에 보이는 구성 이어받기).
  it('sourceKey 지정 시 그 포괄 분기 children 을 복제 원본으로 사용', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'BASE_A' }, { name: 'BASE_B' }],
          responsive: { portable: { children: [{ name: 'PORT_X' }, { name: 'PORT_Y' }] } },
        } as EditorNode,
      ],
    };
    const next = separateBranch(root, [0], 'mobile', 'portable');
    const mobileChildren = findNodeByPath(next, [0])!.responsive!.mobile!.children as EditorNode[];
    // portable children 복제(base 아님)
    expect(mobileChildren.map((c) => c.name)).toEqual(['PORT_X', 'PORT_Y']);
    // 깊은 복제(원본과 다른 인스턴스)
    expect(mobileChildren[0]).not.toBe(
      (root.children as EditorNode[])[0].responsive!.portable!.children![0],
    );
    // 포괄 분기(portable)는 그대로 보존
    expect(
      (findNodeByPath(next, [0])!.responsive!.portable!.children as EditorNode[]).map((c) => c.name),
    ).toEqual(['PORT_X', 'PORT_Y']);
  });

  it('sourceKey 분기에 children 이 없으면 base children 복제로 폴백', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'BASE_A' }],
          responsive: { portable: { props: { className: 'p-2' } } }, // children 없음(props-only)
        } as EditorNode,
      ],
    };
    const next = separateBranch(root, [0], 'mobile', 'portable');
    const mobileChildren = findNodeByPath(next, [0])!.responsive!.mobile!.children as EditorNode[];
    expect(mobileChildren.map((c) => c.name)).toEqual(['BASE_A']); // base 폴백
  });
});

describe('mergeBranch — 분리 제거(되돌림 신규 ④)', () => {
  function branched(): EditorNode {
    return {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A' }],
          responsive: { mobile: { children: [{ name: 'M' }] } },
        } as EditorNode,
      ],
    } as EditorNode;
  }

  it('responsive[key] 제거 → 그 키 분기 사라짐, base 불변', () => {
    const root = branched();
    const next = mergeBranch(root, [0], 'mobile');
    const node = findNodeByPath(next, [0])!;
    expect(node.responsive).toBeUndefined(); // 유일 키였으므로 responsive 자체 제거
    expect((node.children as EditorNode[]).map((c) => c.name)).toEqual(['A']);
  });

  it('responsive 에 다른 키가 남으면 그 키만 제거', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A' }],
          responsive: {
            mobile: { children: [{ name: 'M' }] },
            tablet: { children: [{ name: 'T' }] },
          },
        } as EditorNode,
      ],
    };
    const next = mergeBranch(root, [0], 'mobile');
    const node = findNodeByPath(next, [0])!;
    expect(node.responsive?.mobile).toBeUndefined();
    expect(node.responsive?.tablet?.children).toBeTruthy();
  });

  it('없는 키는 no-op(트리 보존)', () => {
    const root = branched();
    const next = mergeBranch(root, [0], 'desktop');
    // 변형 없음 — mobile 분기 그대로
    expect(findNodeByPath(next, [0])!.responsive?.mobile?.children).toBeTruthy();
  });

  it('separateBranch ↔ mergeBranch 왕복 — 원상 복구', () => {
    const root: EditorNode = {
      children: [{ name: 'Div', children: [{ name: 'A' }, { name: 'B' }] } as EditorNode],
    };
    const sep = separateBranch(root, [0], 'mobile');
    expect(findNodeByPath(sep, [0])!.responsive?.mobile).toBeTruthy();
    const merged = mergeBranch(sep, [0], 'mobile');
    expect(findNodeByPath(merged, [0])!.responsive).toBeUndefined();
    expect((findNodeByPath(merged, [0])!.children as EditorNode[]).map((c) => c.name)).toEqual(['A', 'B']);
  });

  //  회귀: props + children 둘 다 가진 분기를 merge 하면 children 만 제거하고 props 는 보존.
  // 결함: 과거 분기 통째 삭제 → props-only 였던 원본 스타일 오버라이드까지 사라짐(V3 실측 발견).
  it('props + children 분기 merge → children 만 제거, props 보존', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A' }],
          responsive: { mobile: { props: { className: 'p-4' }, children: [{ name: 'M' }] } },
        } as EditorNode,
      ],
    };
    const next = mergeBranch(root, [0], 'mobile');
    const branch = findNodeByPath(next, [0])!.responsive!.mobile!;
    expect(branch.children).toBeUndefined(); // children 교체 제거됨
    expect((branch.props as Record<string, unknown>).className).toBe('p-4'); // props 보존
  });

  // separate(props-only 분기에 children 추가) → merge 왕복 시 props-only 상태로 정확히 복귀.
  it('props-only → separate → merge 왕복 시 props-only 로 복귀(분기 통째 삭제 아님)', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A' }, { name: 'B' }],
          responsive: { mobile: { props: { className: 'p-4' } } },
        } as EditorNode,
      ],
    };
    const sep = separateBranch(root, [0], 'mobile');
    expect(Array.isArray(findNodeByPath(sep, [0])!.responsive!.mobile!.children)).toBe(true);
    const merged = mergeBranch(sep, [0], 'mobile');
    const branch = findNodeByPath(merged, [0])!.responsive!.mobile!;
    expect(branch.children).toBeUndefined();
    expect((branch.props as Record<string, unknown>).className).toBe('p-4'); // 원본 props-only 복귀
  });

  it('children 없는 props-only 분기 merge → no-op(제거할 children 없음)', () => {
    const root: EditorNode = {
      children: [
        {
          name: 'Div',
          children: [{ name: 'A' }],
          responsive: { mobile: { props: { className: 'p-4' } } },
        } as EditorNode,
      ],
    };
    const next = mergeBranch(root, [0], 'mobile');
    expect((findNodeByPath(next, [0])!.responsive!.mobile!.props as Record<string, unknown>).className).toBe('p-4');
  });
});

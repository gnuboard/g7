/**
 * 컴포넌트 배열 prop(`props.footer`) 안 노드 편집 — 경로 문법·선택·속성·이동·삭제·저장 회귀 (#141)
 *
 * 배경: 관리자 확인 창(Modal)의 하단 단추 220여 개가 본문 children 에서 `props.footer` 배열로 옮겨졌다.
 * 편집기 경로 문법은 `children`·`iteration`·`responsive` 만 알아서 footer 안 단추를 고르거나 고칠 수
 * 없었다. footer 를 responsive 와 같은 「배열 전환 세그먼트」(`{propSlot:'footer'}`)로 넣어, 선택
 * (parseEditorPath → findNodeByPath), 속성 패치(patchNode), 삭제(removeNode), 복제(duplicateNode +
 * insertNode), 이동(moveNode, 끌어 놓기 칸)이 children 과 같은 트리 함수를 타게 했다. DOM path 표기는
 * `{owner}.props.footer.{index}` 다.
 *
 * 이 파일은 편집기 쪽 계약을 잠근다. 미리보기 DOM 에 이 path 를 싣는 일은 footer 를 그리는 컴포넌트가
 * componentPath 를 넘겨야 한다(엔진·템플릿 쪽, 보고 참조).
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import {
  serializeEditorPath,
  findNodeByPath,
  findParentByPath,
  patchNode,
  removeNode,
  insertNode,
  moveNode,
  duplicateNode,
  collectAncestors,
  stripInheritedNodes,
  segEqual,
  type EditorNode,
  type ComponentPath,
} from '../../utils/layoutTreeUtils';
import { parseEditorPath, useElementSelection } from '../../hooks/useElementSelection';
import { buildDropSlots } from '../../dnd/dropSlots';

const FOOTER = { propSlot: 'footer' } as const;

/** 루트 > [0] Modal(본문 Div 1개 + footer 단추 2개) */
function tree(): EditorNode {
  return {
    children: [
      {
        type: 'composite',
        name: 'Modal',
        id: 'confirm_modal',
        props: {
          title: '$t:common.confirm',
          footer: [
            { type: 'basic', name: 'Button', props: { className: 'btn btn-secondary' }, text: '$t:common.cancel' },
            {
              type: 'basic',
              name: 'Button',
              props: { className: 'btn btn-danger' },
              children: [{ type: 'basic', name: 'Span', text: '$t:common.delete' }],
            },
          ],
        },
        children: [{ type: 'basic', name: 'Div', props: { className: 'modal-body' } }],
      },
    ],
  };
}

/** 노드의 children 배열(타입 좁힘용) */
function kids(n: EditorNode): EditorNode[] {
  return (n.children as EditorNode[] | undefined) ?? [];
}

/** 첫 자식 */
function kid0(n: EditorNode): EditorNode {
  return kids(n)[0]!;
}

function footerOf(root: EditorNode): EditorNode[] {
  return (kid0(root).props as { footer: EditorNode[] }).footer;
}

describe('[case:wysiwyg-12] 경로 문법 — `N.props.footer.M`', () => {
  /** @effects editor_footer_path_round_trips */
  it('parseEditorPath ↔ serializeEditorPath 가 footer 세그먼트를 보존한다', () => {
    expect(parseEditorPath('0.props.footer.1')).toEqual([0, FOOTER, 1]);
    expect(parseEditorPath('0.props.footer.1.children.0')).toEqual([0, FOOTER, 1, 0]);
    expect(serializeEditorPath([0, FOOTER, 1])).toBe('0.props.footer.1');
    expect(serializeEditorPath([0, FOOTER, 1, 0])).toBe('0.props.footer.1.children.0');
    expect(serializeEditorPath([0, FOOTER])).toBe('0.props.footer');
    // 목록 밖 prop(배열 prop 항목)은 세그먼트가 아니다 — 종전 해석 그대로.
    expect(parseEditorPath('0.children.2.props.tabs.0')).toEqual([0, 2, 0]);
  });

  /** @effects editor_footer_path_round_trips */
  it('세그먼트 비교는 prop 이름으로 한다', () => {
    expect(segEqual({ propSlot: 'footer' }, { propSlot: 'footer' })).toBe(true);
    expect(segEqual({ propSlot: 'footer' }, { responsive: 'footer' })).toBe(false);
    expect(segEqual({ propSlot: 'footer' }, 0)).toBe(false);
  });
});

describe('[case:wysiwyg-12] 선택 — footer 단추를 노드로 찾는다', () => {
  /** @effects editor_footer_node_selectable */
  it('findNodeByPath·findParentByPath·collectAncestors 가 footer 안 노드를 가리킨다', () => {
    const root = tree();
    expect(findNodeByPath(root, [0, FOOTER, 1])?.props).toEqual({ className: 'btn btn-danger' });
    expect(findNodeByPath(root, [0, FOOTER, 1, 0])?.name).toBe('Span');
    expect(findParentByPath(root, [0, FOOTER, 1])?.name).toBe('Modal');
    expect(collectAncestors(root, [0, FOOTER, 1, 0]).map((n) => n.name)).toEqual(['Modal', 'Button']);
    expect(findNodeByPath(root, [0, FOOTER, 5])).toBeNull();
  });

  /** @effects editor_footer_node_selectable */
  it('캔버스 클릭(data-editor-path)으로 고르면 선택 노드가 footer 단추다', () => {
    const root = tree();
    const { result } = renderHook(() => useElementSelection({ rootNode: root, editMode: 'route' }));
    act(() => {
      result.current.handleSelect('', { dataset: { editorPath: '0.props.footer.0' } as DOMStringMap });
    });
    expect(result.current.selectedPath).toBe('0.props.footer.0');
    expect(result.current.selectedNode?.text).toBe('$t:common.cancel');
  });
});

describe('[case:wysiwyg-12] 속성·삭제·복제·이동 — children 과 같은 트리 함수', () => {
  /** @effects editor_footer_node_patch_remove_move */
  it('patchNode 는 footer 단추만 바꾸고 원본 트리는 그대로 둔다', () => {
    const root = tree();
    const next = patchNode(root, [0, FOOTER, 0], (n) => ({ ...n, props: { ...(n.props ?? {}), className: 'btn btn-primary' } }));
    expect(footerOf(next)[0]!.props).toEqual({ className: 'btn btn-primary' });
    expect(footerOf(root)[0]!.props).toEqual({ className: 'btn btn-secondary' });
    // 본문 children 과 다른 prop 은 그대로
    expect(kid0(next).children).toEqual(kid0(root).children);
    expect((kid0(next).props as Record<string, unknown>).title).toBe('$t:common.confirm');
  });

  /** @effects editor_footer_node_patch_remove_move */
  it('removeNode 는 footer 배열에서 지운다', () => {
    const next = removeNode(tree(), [0, FOOTER, 0]);
    expect(footerOf(next).map((n) => (n.props as { className: string }).className)).toEqual(['btn btn-danger']);
  });

  /** @effects editor_footer_node_patch_remove_move */
  it('복제(duplicateNode + 바로 뒤 insertNode)는 footer 배열 안에 넣는다', () => {
    const root = tree();
    const dup = duplicateNode(findNodeByPath(root, [0, FOOTER, 0])!);
    const next = insertNode(root, [0, FOOTER], 1, dup);
    expect(footerOf(next)).toHaveLength(3);
    expect(footerOf(next)[1]!.text).toBe('$t:common.cancel');
  });

  /** @effects editor_footer_node_patch_remove_move */
  it('moveNode 로 footer 안 순서를 바꾸고, footer ↔ 본문 children 사이로 옮긴다', () => {
    const root = tree();
    // footer 안: 1 → 0 앞
    const reordered = moveNode(root, [0, FOOTER, 1], [0, FOOTER], 0);
    expect(footerOf(reordered).map((n) => (n.props as { className: string }).className)).toEqual([
      'btn btn-danger',
      'btn btn-secondary',
    ]);
    // footer → 본문 children 끝
    const toBody = moveNode(root, [0, FOOTER, 0], [0], 1);
    expect(footerOf(toBody)).toHaveLength(1);
    expect(kids(kid0(toBody)).map((n) => n.name)).toEqual(['Div', 'Button']);
    // 본문 children → footer 앞
    const toFooter = moveNode(root, [0, 0], [0, FOOTER], 0);
    expect(footerOf(toFooter).map((n) => n.name)).toEqual(['Div', 'Button', 'Button']);
    expect(kid0(toFooter).children).toEqual([]);
  });

  /** @effects editor_footer_node_patch_remove_move */
  it('트리 사본은 footer 배열을 원본과 공유하지 않는다(실행 취소 스냅샷 오염 차단)', () => {
    const root = tree();
    const next = removeNode(root, [0, 0]); // 본문만 고쳐도 사본 전체가 깊은 사본
    expect(footerOf(next)).not.toBe(footerOf(root));
    expect(footerOf(next)[1]).not.toBe(footerOf(root)[1]);
    const dupModal = duplicateNode(kid0(root));
    expect(footerOf({ children: [dupModal] })).not.toBe(footerOf(root));
  });
});

describe('[case:wysiwyg-12] 저장 직렬화', () => {
  /** @effects editor_footer_edit_serialized_on_save */
  it('편집 결과가 저장 형식(components)의 `props.footer` 에 그대로 실린다', () => {
    const edited = patchNode(tree(), [0, FOOTER, 1, 0], (n) => ({ ...n, text: '$t:common.remove' }));
    const saved = stripInheritedNodes(edited.children as EditorNode[]);
    const footer = (saved[0]!.props as { footer: EditorNode[] }).footer;
    expect(footer).toHaveLength(2);
    expect(kid0(footer[1]!).text).toBe('$t:common.remove');
    // children 으로 새거나 다른 키가 생기지 않는다.
    expect(saved[0]!.children).toEqual([{ type: 'basic', name: 'Div', props: { className: 'modal-body' } }]);
    expect(Object.keys(saved[0]!.props as object).sort()).toEqual(['footer', 'title']);
  });
});

describe('[case:wysiwyg-12] 저장 마스킹 — footer 안 출처 메타', () => {
  /** @effects editor_footer_edit_serialized_on_save */
  it('stripInheritedNodes 는 footer 배열도 재귀한다 — 메타 제거, 확장·partial 노드 제거, 상속 노드의 route 자식 끌어올림', () => {
    const merged: EditorNode[] = [
      {
        type: 'composite',
        name: 'Modal',
        __source: { kind: 'route' },
        props: {
          footer: [
            { type: 'basic', name: 'Button', text: '취소', __source: { kind: 'route' }, _fromBase: false },
            { type: 'basic', name: 'Button', text: '확장', __source: { kind: 'extension', extensionId: 3 } },
            {
              type: 'basic',
              name: 'Div',
              __source: { kind: 'base' },
              children: [{ type: 'basic', name: 'Button', text: '삭제', __source: { kind: 'route' } }],
            },
          ],
        },
      } as unknown as EditorNode,
    ];
    const saved = stripInheritedNodes(merged);
    const footer = (saved[0]!.props as { footer: EditorNode[] }).footer;
    expect(footer.map((n) => n.text)).toEqual(['취소', '삭제']);
    for (const n of footer) {
      expect(n).not.toHaveProperty('__source');
      expect(n).not.toHaveProperty('_fromBase');
    }
    // 원본 트리는 그대로(불변)
    expect(((merged[0]!.props as { footer: EditorNode[] }).footer[0] as Record<string, unknown>).__source).toEqual({ kind: 'route' });
  });
});

describe('[case:wysiwyg-12] 끌어 놓기 칸 — footer 가상 컨테이너', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  function rect(left: number, top: number, width: number, height: number): DOMRect {
    return { left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) } as DOMRect;
  }

  /** @effects editor_footer_node_patch_remove_move */
  it('footer 단추 사이에 `{owner}.props.footer` 컨테이너 칸이 생기고, 그 path 는 footer 배열을 가리킨다', () => {
    // frame > Modal(0) > [본문 Div(0.children.0)] + footer 바(class 만) > [단추 0, 단추 1]
    const frame = document.createElement('div');
    const modal = document.createElement('div');
    modal.setAttribute('data-editor-path', '0');
    const body = document.createElement('div');
    body.setAttribute('data-editor-path', '0.children.0');
    const bar = document.createElement('div');
    bar.className = 'modal-footer-bar';
    const b0 = document.createElement('button');
    b0.setAttribute('data-editor-path', '0.props.footer.0');
    const b1 = document.createElement('button');
    b1.setAttribute('data-editor-path', '0.props.footer.1');
    bar.append(b0, b1);
    modal.append(body, bar);
    frame.appendChild(modal);
    document.body.appendChild(frame);
    const rects = new Map<Element, DOMRect>([
      [frame, rect(0, 0, 600, 400)],
      [modal, rect(0, 0, 600, 300)],
      [body, rect(0, 0, 600, 200)],
      [bar, rect(0, 200, 600, 60)],
      [b0, rect(400, 210, 80, 40)],
      [b1, rect(500, 210, 80, 40)],
    ]);
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
      return rects.get(this) ?? rect(0, 0, 0, 0);
    });
    const styles = new Map<Element, Record<string, string>>([[bar, { display: 'flex', flexDirection: 'row', flexWrap: 'nowrap' }]]);
    vi.spyOn(window, 'getComputedStyle').mockImplementation(
      (el: Element) => (styles.get(el) ?? { display: 'block', flexDirection: '', flexWrap: '' }) as unknown as CSSStyleDeclaration,
    );

    const slots = buildDropSlots({
      frameEl: frame,
      draggedPath: '0.props.footer.1',
      acceptsContainer: () => true,
      allowsNestSlot: () => false,
    });
    const footerSlots = slots.filter((s) => s.containerPath === '0.props.footer' && s.kind === 'gap');
    // 끄는 단추(1)를 뺀 단추 0 앞 칸(0) + 남은 마지막 자식 뒤 칸(0 + 1). children 칸과 같은 규칙.
    expect(footerSlots.map((s) => s.index).sort((a, b) => a - b)).toEqual([0, 1]);
    expect(footerSlots[0]!.orientation).toBe('vertical'); // flex row → 세로선
    // 칸의 컨테이너 path 를 commit 처럼 파싱하면 footer 배열을 가리킨다.
    const parent: ComponentPath = parseEditorPath('0.props.footer');
    expect(parent).toEqual([0, FOOTER]);
    const moved = moveNode(tree(), parseEditorPath('0.props.footer.1'), parent, 0);
    expect(footerOf(moved).map((n) => (n.props as { className: string }).className)).toEqual([
      'btn btn-danger',
      'btn btn-secondary',
    ]);
  });
});

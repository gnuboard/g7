/**
 * inheritSourceIntoBranches — 잠긴 공통 레이아웃 노드의 분기 자식·footer 에 출처 메타를 잇는다 (#869)
 *
 * 결함: 화면 편집에서 공통 레이아웃(base) 헤더 단추의 portable 분기 자식에 드래그 핸들이 생겼는데 놓을 칸이 없었다.
 * 서버는 출처 메타를 children 에만 달아 분기 자식은 「잠금 없음」(classifyLockKind = none)이었다. 메타를 이으면
 * 잠금(base)으로 판정돼 드래그 핸들이 그려지지 않고(DndCanvasLayer 는 잠금 노드를 핸들에서 뺀다) 선택하면 잠금
 * 배지(공통 레이아웃 편집 진입)가 뜬다.
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_locked_branch_children_have_no_drag_handle
 */

import { describe, it, expect } from 'vitest';
import { inheritSourceIntoBranches, stripInheritedNodes, type EditorNode } from '../../utils/layoutTreeUtils';
import { classifyLockKind } from '../../hooks/useElementSelection';

const BASE = { kind: 'base', layoutName: '_admin_base' } as unknown as EditorNode['__source'];

function tree(): EditorNode[] {
  return [
    {
      name: 'Button',
      type: 'basic',
      __source: BASE,
      _fromBase: true,
      children: [{ name: 'Icon', type: 'basic', __source: BASE, _fromBase: true }],
      responsive: {
        portable: {
          children: [
            { name: 'Icon', type: 'basic', props: { name: 'bars' } },
            { name: 'Span', type: 'basic', text: '메뉴', children: [{ name: 'B', type: 'basic' }] },
          ],
        },
      },
    } as unknown as EditorNode,
    {
      name: 'Div',
      type: 'basic',
      __source: { kind: 'route' } as unknown as EditorNode['__source'],
      responsive: { portable: { children: [{ name: 'Span', type: 'basic', text: 'route branch' }] } },
      props: { footer: [{ name: 'Button', type: 'basic', text: '확인' }] },
    } as unknown as EditorNode,
  ];
}

describe('[case:wysiwyg-12] 분기·footer 출처 메타 잇기', () => {
  /** @effects editor_locked_branch_children_have_no_drag_handle */
  it('base 노드의 분기 자식(과 그 자손)이 base 메타를 받아 잠금(base)으로 판정된다', () => {
    const out = inheritSourceIntoBranches(tree());
    const branch = (out[0]!.responsive as Record<string, { children: EditorNode[] }>).portable.children;
    expect(branch[0]!.__source).toEqual(BASE);
    expect((branch[0] as Record<string, unknown>)._fromBase).toBe(true);
    expect((branch[1]!.children as EditorNode[])[0]!.__source).toEqual(BASE);
    expect(classifyLockKind(branch[0]!, 'route')).toBe('base');
    // 이을 전에는 잠금 없음(결함 재현)
    const before = (tree()[0]!.responsive as Record<string, { children: EditorNode[] }>).portable.children;
    expect(classifyLockKind(before[0]!, 'route')).toBe('none');
  });

  /** @effects editor_locked_branch_children_have_no_drag_handle */
  it('라우트 소유 노드의 분기·footer 자식은 그대로(메타 없음 = 편집 가능), 입력은 바꾸지 않는다', () => {
    const input = tree();
    const out = inheritSourceIntoBranches(input);
    const routeBranch = (out[1]!.responsive as Record<string, { children: EditorNode[] }>).portable.children;
    expect(routeBranch[0]!.__source).toBeUndefined();
    expect(((out[1]!.props as { footer: EditorNode[] }).footer[0]!).__source).toBeUndefined();
    expect(out[1]).toBe(input[1]); // 바꿀 것이 없으면 같은 참조
    expect(((input[0]!.responsive as Record<string, { children: EditorNode[] }>).portable.children[0]!).__source).toBeUndefined();
  });

  /** @effects editor_locked_branch_children_have_no_drag_handle */
  it('저장 마스킹 결과는 잇기 전과 같다(base 소유 노드는 분기째 버려진다)', () => {
    expect(stripInheritedNodes(inheritSourceIntoBranches(tree()))).toEqual(stripInheritedNodes(tree()));
  });
});

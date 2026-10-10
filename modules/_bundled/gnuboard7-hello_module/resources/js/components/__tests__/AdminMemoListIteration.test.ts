/**
 * @file AdminMemoListIteration.test.ts
 * @description 관리자 메모 목록의 반복 위치 — 메모 카드 사이 간격 (사용자 메모 목록과 같은 결함군)
 *
 * 7.1.0 S2 검증: 사용자 메모 목록은 반복(`data-each`)을 묶음 쪽에 두어 메모마다 묶음이 새로 생기고
 * 카드 사이 간격이 0px 이던 것을 카드 반복으로 고쳤다. 관리자 메모 목록도 같은 짜임이었다 — 반복이
 * `row-stack` 묶음에 붙어 메모마다 자식 하나뿐인 묶음이 생기고, `row-stack` 은 첫·마지막 자식의 위아래
 * 여백을 0 으로 두므로 카드가 간격 없이 붙었다. 반복은 카드에 두고, 카드를 감싸는 묶음 하나가 간격을 준다.
 *
 * 레이아웃 계약만 본다(짝 JSON 트리 직접 분석). 실제 간격 픽셀은 템플릿 CSS 가 정한다.
 *
 * @scenario-manifest tests/scenarios/admin-memo-list-iteration.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

type LayoutNode = {
  id?: string;
  name?: string;
  props?: { className?: string };
  iteration?: { source?: string };
  children?: LayoutNode[];
};

const LAYOUT = path.resolve(__dirname, '../../../layouts/admin/admin_memo_list.json');

/** 트리에서 메모 목록을 반복하는 노드와 그 부모를 찾는다 */
const findIteration = (node: LayoutNode, parent: LayoutNode | null): { node: LayoutNode; parent: LayoutNode | null } | null => {
  if (node.iteration?.source?.includes('memos')) return { node, parent };
  for (const child of node.children ?? []) {
    const found = findIteration(child, node);
    if (found) return found;
  }
  return null;
};

describe('[case:admin-memo-list-iteration] 관리자 메모 목록 반복 위치', () => {
  const layout = JSON.parse(fs.readFileSync(LAYOUT, 'utf8')) as { slots: { content: LayoutNode[] } };
  const found = layout.slots.content.map((n) => findIteration(n, null)).find(Boolean) ?? null;

  /**
   * @scenario part=iteration
   * @effects memo_cards_repeat_inside_single_spaced_list
   */
  it('반복은 메모 카드에 있고, 카드를 감싼 묶음 하나가 카드 사이 간격을 준다', () => {
    expect(found).not.toBeNull();
    const { node, parent } = found!;

    // 반복 노드가 곧 카드다(테두리·여백을 가진 상자)
    expect(node.props?.className ?? '').toContain('border');
    expect(node.children?.some((c) => c.name === 'Div')).toBe(false);
    // 묶음은 반복되지 않고 간격 클래스를 갖는다
    expect(parent?.iteration).toBeUndefined();
    expect(parent?.props?.className ?? '').toContain('space-y-3');
  });
});

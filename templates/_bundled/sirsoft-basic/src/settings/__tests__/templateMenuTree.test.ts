/**
 * templateMenuTree — 메뉴 편집기의 트리 계산
 *
 * 끌어서 놓기는 「펴기 → 옮기기 → 놓을 깊이 정하기 → 다시 묶기」다. 규칙(2단·자동 항목은 부모가 못 됨·자식 있는 항목은
 * 다른 항목 아래로 못 감)은 서버 검증(`TemplateMenu::structureErrors`)과 같아야 저장이 거절되지 않는다.
 */

import { describe, expect, it } from 'vitest';
import { arrayMove } from '@dnd-kit/sortable';
import {
  TemplateMenuNode,
  buildMenu,
  countMenuItems,
  flattenMenu,
  indentMenuItem,
  menuItemPath,
  moveMenuItem,
  outdentMenuItem,
  projectDepth,
  removeMenuItem,
  updateMenuItem,
} from '../templateMenuTree';

const link = (id: string, children: TemplateMenuNode[] = []): TemplateMenuNode => ({ id, kind: 'link', label: { ko: id }, url: `/${id}`, children });
const auto = (id: string): TemplateMenuNode => ({ id, kind: id, children: [] });
const shape = (items: TemplateMenuNode[]): string[] => items.map((i) => (i.children?.length ? `${i.id}(${i.children.map((c) => c.id).join(',')})` : i.id));

describe('templateMenuTree', () => {
  // @scenario case=menu_editor
  // @effects menu_editor_tree_rules
  it('펴고 다시 묶으면 같은 트리다', () => {
    const tree = [link('a', [link('a1'), link('a2')]), auto('boards'), link('b')];

    expect(shape(buildMenu(flattenMenu(tree)))).toEqual(['a(a1,a2)', 'boards', 'b']);
    expect(countMenuItems(tree)).toBe(5);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_tree_rules
  it('끌어서 오른쪽으로 놓으면 바로 위 링크의 하위 메뉴가 된다 — 가로 거리가 반 칸 이상일 때', () => {
    const tree = [link('a'), link('b')];
    const rows = flattenMenu(tree);

    expect(projectDepth(rows, 1, 0, 30, 28, 2)).toBe(1);
    expect(projectDepth(rows, 1, 0, 10, 28, 2)).toBe(0);

    rows[1] = { ...rows[1], depth: 1 };
    expect(shape(buildMenu(rows))).toEqual(['a(b)']);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_tree_rules
  it('자동 항목 아래·맨 앞·1단 설정·자식 있는 항목은 하위로 못 들어간다', () => {
    expect(projectDepth(flattenMenu([auto('boards'), link('b')]), 1, 0, 60, 28, 2)).toBe(0);
    expect(projectDepth(flattenMenu([link('a'), link('b')]), 0, 0, 60, 28, 2)).toBe(0);
    expect(projectDepth(flattenMenu([link('a'), link('b')]), 1, 0, 60, 28, 1)).toBe(0);
    expect(projectDepth(flattenMenu([link('a'), link('b', [link('b1')])], 'b'), 1, 0, 60, 28, 2)).toBe(0);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_tree_rules
  it('하위 메뉴를 왼쪽으로 끌면 상위로 나오고, 다른 상위 메뉴 아래로 옮길 수 있다', () => {
    const tree = [link('a', [link('a1')]), link('b')];
    const rows = flattenMenu(tree);

    // a1 을 맨 끝(b 아래)으로 옮기며 들여쓴 채 놓기 → b 의 하위
    const moved = arrayMove(rows, 1, 2);
    moved[2] = { ...moved[2], depth: projectDepth(moved, 2, 1, 0, 28, 2) };
    expect(shape(buildMenu(moved))).toEqual(['a', 'b(a1)']);

    // a1 을 왼쪽으로 한 칸 → 상위
    const back = flattenMenu(tree);
    back[1] = { ...back[1], depth: projectDepth(back, 1, 1, -30, 28, 2) };
    expect(shape(buildMenu(back))).toEqual(['a', 'a1', 'b']);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_tree_rules
  it('상위 메뉴를 끄는 동안 자식을 빼 두었다가 놓은 자리에 그대로 붙인다', () => {
    const tree = [link('a', [link('a1')]), link('b')];
    const rows = flattenMenu(tree, 'a');

    expect(rows.map((r) => r.node.id)).toEqual(['a', 'b']);
    expect(shape(buildMenu(arrayMove(rows, 0, 1), 'a'))).toEqual(['b', 'a(a1)']);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_tree_rules
  it('단추 이동 — 위·아래(같은 단계 안), 들여쓰기(위 형제가 링크일 때만), 내어쓰기(부모 바로 뒤)', () => {
    const tree = [link('a', [link('a1'), link('a2')]), link('b'), auto('boards'), link('c')];

    expect(shape(moveMenuItem(tree, 'a2', -1))).toEqual(['a(a2,a1)', 'b', 'boards', 'c']);
    expect(shape(moveMenuItem(tree, 'a', -1))).toEqual(shape(tree));
    expect(shape(indentMenuItem(tree, 'b', 2))).toEqual(['a(a1,a2,b)', 'boards', 'c']);
    expect(shape(indentMenuItem(tree, 'c', 2))).toEqual(shape(tree));
    expect(shape(indentMenuItem(tree, 'b', 1))).toEqual(shape(tree));
    expect(shape(outdentMenuItem(tree, 'a1'))).toEqual(['a(a2)', 'a1', 'b', 'boards', 'c']);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_tree_rules
  it('고치기·지우기·오류 위치', () => {
    const tree = [link('a', [link('a1')]), link('b')];

    expect(updateMenuItem(tree, 'a1', { url: '/x' })[0].children?.[0].url).toBe('/x');
    expect(shape(removeMenuItem(tree, 'a'))).toEqual(['b']);
    expect(shape(removeMenuItem(tree, 'a1'))).toEqual(['a', 'b']);
    expect(menuItemPath(tree, 'a1')).toBe('0.children.0');
    expect(menuItemPath(tree, 'b')).toBe('1');
    expect(menuItemPath(tree, 'none')).toBeNull();
  });
});

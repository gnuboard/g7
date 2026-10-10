/**
 * @file row-stack-boxed-child-fixes.test.ts
 * @description row-stack 직속 자식이 테두리 상자이던 자리를 고친 구조를 잠근다
 *
 * `.row-stack` 은 자식에 `py-2`(첫 자식 pt-0, 마지막 자식 pb-0)를 준다. 자식이 테두리 상자이면 그
 * 8px 이 테두리 **안쪽**에 들어가 상자 안 위·아래가 빈다(환경설정 개인정보·보안 탭 실측: 첫 행
 * 오프셋 8.6px). admin-card 가 자식이면 카드 자체 패딩(16px)이 위 8 / 아래 0 으로 덮어써진다.
 * 테두리 없는 래퍼로 감싸거나 컨테이너를 카드 묶음용으로 바꾼다. 같은 판정을 audit
 * `row-stack-boxed-child` 가 저장소 전체에 건다.
 */

import { describe, it, expect } from 'vitest';
import privacyTab from '../../layouts/partials/admin_settings/_tab_privacy.json';
import passwordPolicyTab from '../../layouts/partials/admin_settings/_tab_security_password_policy.json';
import layoutEdit from '../../layouts/admin_template_layout_edit.json';
import activityLogGrid from '../../layouts/partials/admin_activity_log_list/_partial_datagrid.json';

type Json = Record<string, any>;

/** 조건에 맞는 첫 노드를 찾는다. */
function find(tree: unknown, pred: (n: Json) => boolean): Json | undefined {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (pred(obj)) {
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return found;
}

/** id 로 노드를 찾고 없으면 실패한다. */
function nodeById(tree: unknown, id: string): Json {
  const node = find(tree, (n) => n.id === id);
  expect(node, `${id} 노드가 없습니다.`).toBeDefined();
  return node!;
}

const tokens = (cls: unknown): string[] => String(cls ?? '').split(/\s+/).filter(Boolean);

/** row-stack 직속 자식으로 래퍼가 있고 그 안에 상자가 있는지 본다. */
function expectWrapped(tree: unknown, wrapperId: string, boxId: string, conditionOnWrapper: boolean): void {
  const parent = find(tree, (n) => tokens(n.props?.className).includes('row-stack') && (n.children ?? []).some((c: Json) => c.id === wrapperId));
  expect(parent, `${wrapperId} 가 row-stack 의 직속 자식이 아닙니다.`).toBeDefined();
  expect(find(parent, (n) => n.id === boxId && (parent!.children ?? []).includes(n)), `${boxId} 가 아직 row-stack 직속입니다.`).toBeUndefined();

  const wrapper = nodeById(tree, wrapperId);
  const wrapperClass = tokens(wrapper.props?.className);
  expect(wrapperClass, '래퍼는 테두리가 없어야 합니다').not.toContain('border');
  const box = (wrapper.children ?? []).find((c: Json) => c.id === boxId);
  expect(box, `${boxId} 가 래퍼 바로 안에 없습니다.`).toBeDefined();
  expect(tokens(box.props?.className)).toContain('border');
  if (conditionOnWrapper) {
    expect(typeof wrapper.if, `${wrapperId} 가 표시 조건을 갖지 않습니다.`).toBe('string');
    expect(box.if, `${boxId} 에 조건이 남아 있습니다(래퍼로 옮겨야 함).`).toBeUndefined();
  }
}

describe('[case:components-misc-row-stack-boxed-child-padding] 환경설정 — 대상 목록 상자 래퍼', () => {
  /**
   * @scenario defect=row_stack_boxed_child_padding
   * @effects boxed_child_has_no_row_stack_padding_inside
   */
  it('개인정보 탭: 탈퇴 보관·휴면 대상 상자가 래퍼 안에 있고 조건은 래퍼가 갖는다', () => {
    expectWrapped(privacyTab, 'withdrawn_target_list_row', 'withdrawn_target_list', true);
    expectWrapped(privacyTab, 'dormant_target_list_row', 'dormant_target_list', true);
  });

  /**
   * @scenario defect=row_stack_boxed_child_padding
   * @effects boxed_child_has_no_row_stack_padding_inside
   */
  it('보안 탭: 비밀번호 만료 대상 상자가 래퍼 안에 있다', () => {
    expectWrapped(passwordPolicyTab, 'password_expiry_target_list_row', 'password_expiry_target_list', false);
  });
});

describe('카드 묶음 컨테이너', () => {
  /**
   * @scenario defect=row_stack_boxed_child_padding
   * @effects admin_card_padding_kept_on_mobile
   */
  it('레이아웃 편집: 모바일 main_grid 가 row-stack 이 아닌 flex 세로 쌓기다', () => {
    const grid = nodeById(layoutEdit, 'main_grid');
    const portable = tokens(grid.responsive?.portable?.props?.className);
    expect(portable).not.toContain('row-stack');
    expect(portable).toEqual(expect.arrayContaining(['mt-6', 'flex', 'flex-col', 'gap-4', 'h-[calc(100dvh-264px)]', 'min-h-[420px]']));
  });

  /**
   * @scenario defect=row_stack_boxed_child_padding
   * @effects boxed_child_has_no_row_stack_padding_inside
   */
  it('활동 로그: 일괄 변경 그룹 상자 묶음이 stack-tight 다', () => {
    const container = find(activityLogGrid, (n) => (n.children ?? []).some((c: Json) => c.iteration?.source === 'row.bulk_changes'));
    expect(container, '일괄 변경 그룹 묶음이 없습니다.').toBeDefined();
    const cls = tokens(container!.props?.className);
    expect(cls).toContain('stack-tight');
    expect(cls).not.toContain('row-stack');
  });
});

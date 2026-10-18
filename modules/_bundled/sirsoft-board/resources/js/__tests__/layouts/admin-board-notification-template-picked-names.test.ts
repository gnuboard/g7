/**
 * @file admin-board-notification-template-picked-names.test.ts
 * @description 게시판 환경설정 — 알림 템플릿 편집 창: 방금 고른 역할·회원이 이름으로 보인다 (7.1.0 전수 실측 영역 G, 2026-10-06)
 *
 * 서버는 저장된 수신자 값에만 표시 이름(display_names)을 붙여 준다. 선택 칸이 값만 담으면 방금 고른 역할은
 * 식별자(`qa736_g_reader`), 회원은 UUID 로 보이고, 검색어가 남아 있으면 그 항목이 목록에서 걸러져 선택을 풀 수
 * 없었다. 선택 칸의 onChange 표현식을 엔진에 그대로 넣어, 검색 결과의 이름이 같은 순서로 담기는지 본다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import modal from '../../../layouts/admin/partials/admin_board_settings/_modal_notification_template_edit.json';

type Json = Record<string, any>;

const NS = 'board_notification_template_form_modal';

/** 조건에 맞는 노드를 모두 찾는다. */
function findAll(node: unknown, pred: (n: Json) => boolean, acc: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((item) => findAll(item, pred, acc));
    return acc;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) acc.push(obj);
    Object.values(obj).forEach((v) => findAll(v, pred, acc));
  }
  return acc;
}

/** `{{ … }}` 한 덩어리 바인딩을 값으로 평가한다. */
function evalBinding(binding: string, context: Json): unknown {
  return dataBindingEngine.evaluateExpression(binding.trim().replace(/^\{\{|\}\}$/g, ''), context);
}

const dropdowns = findAll(modal, (n) => n.name === 'SearchableDropdown');
const onChangeExpr = (placeholderKey: string): string => {
  const dropdown = dropdowns.find((n) => String(n.props?.searchPlaceholder ?? '').includes(placeholderKey))!;
  return actionsOf(dropdown).find((a: Json) => a.event === 'onChange')!.params[`${NS}.recipients`];
};

describe('게시판 알림 템플릿 편집 창 — 방금 고른 항목의 표시 이름', () => {
  /**
   * 방금 고른 역할이 식별자가 아니라 검색 결과 이름으로 보인다.
   *
   * @scenario case=picked_role
   * @effects recipient_picked_role_keeps_search_result_name
   */
  it('역할 — 저장돼 있던 역할은 서버 이름을, 방금 고른 역할은 검색 결과 이름을 같은 순서로 담는다', () => {
    const result = evalBinding(onChangeExpr('role_search_placeholder'), {
      _global: {
        [NS]: {
          recipients: [{ type: 'role', value: ['admin'], display_names: ['관리자'] }],
          roleSearchResults: [{ value: 'cs_team', label: '고객센터', description: 'cs_team' }],
        },
      },
      rcptIdx: 0,
      $args: [['admin', 'cs_team']],
    }) as Json[];

    expect(result[0].value).toEqual(['admin', 'cs_team']);
    expect(result[0].display_names).toEqual(['관리자', '고객센터']);
  });

  /**
   * 방금 고른 회원이 UUID 가 아니라 검색 결과 이름으로 보인다.
   *
   * @scenario case=picked_user
   * @effects recipient_picked_user_keeps_search_result_name
   */
  it('회원 — 방금 고른 회원은 UUID 가 아니라 검색 결과 이름으로 담기고, 뺀 회원의 이름은 함께 빠진다', () => {
    const picked = evalBinding(onChangeExpr('users_placeholder'), {
      _global: {
        [NS]: {
          recipients: [{ type: 'specific_users', value: ['uuid-0'], display_names: ['기존 회원'] }],
          userSearchResults: [{ value: 'uuid-1', label: '홍길동', description: 'hong@example.test' }],
        },
      },
      rcptIdx: 0,
      $args: [['uuid-1']],
    }) as Json[];

    expect(picked[0].value).toEqual(['uuid-1']);
    expect(picked[0].display_names).toEqual(['홍길동']);
  });
});

describe('고른 역할·회원 칩 줄 — 코어 편집 창과 같은 칩, 열린 목록에 가리지 않는 자리 (7.1.0 칩 줄 통일)', () => {
  /** 칩 줄을 찾아 이름 표시와 빼기 단추의 값·이름 동시 제거를 확인한다. */
  const checkChipRow = (chipsId: string, indexVar: string): void => {
    const chips = findAll(modal, (n) => n.id === chipsId);
    expect(chips).toHaveLength(1);

    const chip = chips[0].children[0];
    expect(chip.iteration.index_var).toBe(indexVar);

    const label = findAll(chip, (n) => n.name === 'Span')[0];
    const shown = evalBinding(String(label.text), {
      rcpt: { value: ['admin', 'cs_team'], display_names: ['관리자', '고객센터'] },
      [indexVar]: 1,
      [chip.iteration.item_var]: 'cs_team',
    });
    expect(shown).toBe('고객센터');

    const removeButton = findAll(chip, (n) => n.name === 'Button')[0];
    expect(String(removeButton.props['aria-label'])).toContain('recipients.remove_selected');

    const removeExpr = actionsOf(removeButton)[0].params[`${NS}.recipients`];
    const result = evalBinding(removeExpr, {
      _global: { [NS]: { recipients: [{ type: 'role', value: ['admin', 'cs_team'], display_names: ['관리자', '고객센터'] }] } },
      rcptIdx: 0,
      [indexVar]: 0,
    }) as Json[];

    expect(result[0].value).toEqual(['cs_team']);
    expect(result[0].display_names).toEqual(['고객센터']);
  };

  /**
   * 칩 줄이 같은 칸의 선택 드롭다운보다 앞(위)에 놓인다 — 펼친 목록은 트리거 아래로 떠서 그 아래 형제를 덮는다.
   * 다중 선택은 고른 뒤에도 목록이 열려 있으므로, 칩이 아래에 있으면 방금 고른 칩도 빼기 단추도 가려진다.
   */
  const checkChipsAboveDropdown = (chipsId: string, placeholderKey: string): void => {
    const parents = findAll(modal, (n) => Array.isArray(n.children) && n.children.some((c: Json) => c?.id === chipsId));
    expect(parents).toHaveLength(1);
    const kids = parents[0].children as Json[];
    const chipsAt = kids.findIndex((c) => c?.id === chipsId);
    const dropdownAt = kids.findIndex(
      (c) => c?.name === 'SearchableDropdown' && String(c.props?.searchPlaceholder ?? '').includes(placeholderKey),
    );
    expect(dropdownAt).toBeGreaterThanOrEqual(0);
    expect(chipsAt).toBeLessThan(dropdownAt);
  };

  /**
   * 역할 칩이 이름을 보이고, 빼기 단추가 같은 자리의 값과 표시 이름을 함께 뺀다.
   *
   * @scenario case=role_chip_remove
   * @effects role_chip_removes_value_and_name_together
   */
  it('역할 칩 — 이름을 보이고, 빼기 단추가 같은 자리의 값과 이름을 함께 뺀다', () => {
    checkChipRow('board_rcpt_role_chips_{{rcptIdx}}', 'ridIdx');
  });

  /**
   * 회원 칩이 이름을 보이고, 빼기 단추가 같은 자리의 값과 표시 이름을 함께 뺀다.
   *
   * @scenario case=user_chip_remove
   * @effects user_chip_removes_value_and_name_together
   */
  it('회원 칩 — 이름을 보이고, 빼기 단추가 같은 자리의 값과 이름을 함께 뺀다', () => {
    checkChipRow('board_rcpt_user_chips_{{rcptIdx}}', 'uidIdx');
  });


  /**
   * 역할 칩 줄이 역할 선택 드롭다운 위에 놓인다.
   *
   * @scenario case=chips_above_open_dropdown
   * @effects chips_stay_visible_while_dropdown_open
   */
  it('역할 칩 줄은 드롭다운 위에 있어 목록을 연 동안에도 가려지지 않는다', () => {
    checkChipsAboveDropdown('board_rcpt_role_chips_{{rcptIdx}}', 'role_search_placeholder');
  });

  /**
   * 회원 칩 줄이 회원 선택 드롭다운 위에 놓인다.
   *
   * @scenario case=chips_above_open_dropdown
   * @effects chips_stay_visible_while_dropdown_open
   */
  it('회원 칩 줄은 드롭다운 위에 있어 목록을 연 동안에도 가려지지 않는다', () => {
    checkChipsAboveDropdown('board_rcpt_user_chips_{{rcptIdx}}', 'users_placeholder');
  });
});

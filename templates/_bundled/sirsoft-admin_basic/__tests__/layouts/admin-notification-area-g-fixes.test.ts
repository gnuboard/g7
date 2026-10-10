/**
 * @file admin-notification-area-g-fixes.test.ts
 * @description 7.1.0 전수 실측(영역 G — 알림, 2026-10-06)에서 찾은 화면 결함의 회귀 잠금
 *
 * - 상한(result_cap)에 걸린 총 건수를 「총 N건 이상」 으로 말한다(회원 알림함·알림 발송 이력·본인인증 이력·회원 목록).
 *   종전에는 24,177건인 알림함이 「총 10000건」 으로 보였다.
 * - 회원 상세·수정의 [알림함 보기] 는 회원 알림함을 볼 수 있는 관리자에게만 선다(보는 사람 권한 목록 current_user 기준).
 * - 알림 템플릿 편집 창에서 방금 고른 역할·회원이 이름으로 보이고(종전: 식별자·UUID), 칩으로 하나씩 뺄 수 있다.
 *
 * 구조 단언만으로는 표현식이 런타임에 무엇을 만드는지 보장하지 못하므로 표현식은 엔진에 그대로 넣어 결과를 본다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import memberNotificationList from '../../layouts/admin_member_notification_list.json';
import notificationLogList from '../../layouts/admin_notification_log_list.json';
import identityLogs from '../../layouts/admin_identity_logs.json';
import userList from '../../layouts/admin_user_list.json';
import userDetail from '../../layouts/admin_user_detail.json';
import userForm from '../../layouts/admin_user_form.json';
import templateModal from '../../layouts/partials/admin_settings/_modal_notification_template_form.json';

type Json = Record<string, any>;

const NS = 'notification_template_form_modal';

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

describe('상한에 걸린 총 건수 표시', () => {
  const cases: Array<[string, Json, string, string]> = [
    ['회원 알림함', memberNotificationList as Json, 'memberNotifications', 'admin.member_notification'],
    ['알림 발송 이력', notificationLogList as Json, 'notificationLogs', 'admin.notification_log'],
    ['본인인증 이력', identityLogs as Json, 'identityLogs', 'admin.identity.logs'],
    ['회원 목록', userList as Json, 'users', 'admin.users'],
  ];

  /**
   * 상한에 걸린 총 건수는 「총 N건 이상」, 끝까지 센 총 건수는 「총 N건」 으로 말한다.
   *
   * @scenario case=capped_total
   * @effects capped_total_says_at_least
   */
  it.each(cases)('%s — 정확한 총계와 「이상」 문구가 total_is_exact 로 갈린다', (_label, layout, source, prefix) => {
    const exact = findAll(layout, (n) => String(n.text ?? '').startsWith(`$t:${prefix}.total_count|`));
    const atLeast = findAll(layout, (n) => String(n.text ?? '').startsWith(`$t:${prefix}.total_at_least|`));

    expect(exact).toHaveLength(1);
    expect(atLeast).toHaveLength(1);
    expect(exact[0].if).toBe(`{{${source}?.data?.pagination?.total_is_exact !== false}}`);
    expect(atLeast[0].if).toBe(`{{${source}?.data?.pagination?.total_is_exact === false}}`);

    const capped = { [source]: { data: { pagination: { total: 10000, total_is_exact: false } } } };
    const counted = { [source]: { data: { pagination: { total: 37, total_is_exact: true } } } };
    expect(evalBinding(atLeast[0].if, capped)).toBe(true);
    expect(evalBinding(exact[0].if, capped)).toBe(false);
    expect(evalBinding(exact[0].if, counted)).toBe(true);
    expect(evalBinding(atLeast[0].if, counted)).toBe(false);
  });
});

describe('회원 상세·수정 [알림함 보기] 권한', () => {
  /**
   * [알림함 보기] 는 회원 알림함 읽기 권한이 있는 관리자에게만 선다.
   *
   * @scenario case=inbox_button_permission
   * @effects inbox_button_requires_member_notification_read
   */
  it.each([
    ['상세', userDetail as Json],
    ['수정', userForm as Json],
  ])('%s — 보는 사람에게 core.member-notifications.read 가 있을 때만 선다', (_label, layout) => {
    const link = findAll(layout, (n) => n.id === 'notifications_inbox_link');
    expect(link).toHaveLength(1);
    const cond = link[0].if as string;
    const withPerm = { current_user: { data: { permissions: [{ identifier: 'core.users.read' }, { identifier: 'core.member-notifications.read' }] } } };
    const usersOnly = { current_user: { data: { permissions: [{ identifier: 'core.users.read' }] } } };
    expect(evalBinding(cond, withPerm)).toBe(true);
    expect(evalBinding(cond, usersOnly)).toBe(false);
    expect(evalBinding(cond, { current_user: { data: {} } })).toBe(false);
  });
});

describe('알림 템플릿 편집 창 — 방금 고른 역할·회원의 이름과 칩', () => {
  const dropdowns = findAll(templateModal, (n) => n.name === 'SearchableDropdown');
  const roleDropdown = dropdowns.find((n) => String(n.props?.searchPlaceholder ?? '').includes('role_search_placeholder'))!;
  const userDropdown = dropdowns.find((n) => String(n.props?.searchPlaceholder ?? '').includes('users_placeholder'))!;

  const onChangeExpr = (dropdown: Json): string =>
    actionsOf(dropdown).find((a: Json) => a.event === 'onChange')!.params[`${NS}.recipients`];

  /**
   * 방금 고른 역할은 검색 결과 이름으로 담긴다.
   *
   * @scenario case=picked_role_name
   * @effects picked_role_keeps_search_result_name
   */
  it('역할 — 저장돼 있던 역할은 서버 이름을, 방금 고른 역할은 검색 결과 이름을 같은 순서로 담는다', () => {
    const result = evalBinding(onChangeExpr(roleDropdown), {
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
   * 방금 고른 회원은 검색 결과 이름으로 담긴다.
   *
   * @scenario case=picked_user_name
   * @effects picked_user_keeps_search_result_name
   */
  it('회원 — 방금 고른 회원은 UUID 가 아니라 검색 결과의 이름으로 담긴다', () => {
    const result = evalBinding(onChangeExpr(userDropdown), {
      _global: {
        [NS]: {
          recipients: [{ type: 'specific_users', value: [] }],
          userSearchResults: [{ value: 'uuid-1', label: '홍길동', description: 'hong@example.test' }],
        },
      },
      rcptIdx: 0,
      $args: [['uuid-1']],
    }) as Json[];

    expect(result[0].value).toEqual(['uuid-1']);
    expect(result[0].display_names).toEqual(['홍길동']);
  });

  /** 칩 줄을 찾아 이름 표시와 빼기 단추의 값·이름 동시 제거를 확인한다. */
  const checkChipRow = (chipsId: string, indexVar: string): void => {
    const chips = findAll(templateModal, (n) => n.id === chipsId);
    expect(chips).toHaveLength(1);

    const chip = chips[0].children[0];
    expect(chip.iteration.index_var).toBe(indexVar);

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
   * 역할 칩 빼기 단추가 같은 자리의 값과 표시 이름을 함께 뺀다.
   *
   * @scenario case=role_chip_remove
   * @effects role_chip_removes_value_and_name_together
   */
  it('역할 칩 — 이름을 보이고, 빼기 단추가 같은 자리의 값과 이름을 함께 뺀다', () => {
    checkChipRow('rcpt_role_chips_{{rcptIdx}}', 'ridIdx');
  });

  /**
   * 회원 칩 빼기 단추가 같은 자리의 값과 표시 이름을 함께 뺀다.
   *
   * @scenario case=user_chip_remove
   * @effects user_chip_removes_value_and_name_together
   */
  it('회원 칩 — 이름을 보이고, 빼기 단추가 같은 자리의 값과 이름을 함께 뺀다', () => {
    checkChipRow('rcpt_user_chips_{{rcptIdx}}', 'uidIdx');
  });
});

describe('고른 역할·회원 칩 줄 — 열린 목록에 가리지 않는 자리 (7.1.0 칩 줄 통일)', () => {
  /**
   * 칩 줄이 같은 칸의 선택 드롭다운보다 앞(위)에 놓인다 — 펼친 목록은 트리거 아래로 떠서 그 아래 형제를 덮는다.
   * 다중 선택은 고른 뒤에도 목록이 열려 있으므로, 칩이 아래에 있으면 방금 고른 칩도 빼기 단추도 가려진다.
   */
  const checkChipsAboveDropdown = (chipsId: string, placeholderKey: string): void => {
    const parents = findAll(templateModal, (n) => Array.isArray(n.children) && n.children.some((c: Json) => c?.id === chipsId));
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
   * 역할 칩 줄이 역할 선택 드롭다운 위에 놓인다.
   *
   * @scenario case=chips_above_open_dropdown
   * @effects chips_stay_visible_while_dropdown_open
   */
  it('역할 칩 줄은 드롭다운 위에 있어 목록을 연 동안에도 가려지지 않는다', () => {
    checkChipsAboveDropdown('rcpt_role_chips_{{rcptIdx}}', 'role_search_placeholder');
  });

  /**
   * 회원 칩 줄이 회원 선택 드롭다운 위에 놓인다.
   *
   * @scenario case=chips_above_open_dropdown
   * @effects chips_stay_visible_while_dropdown_open
   */
  it('회원 칩 줄은 드롭다운 위에 있어 목록을 연 동안에도 가려지지 않는다', () => {
    checkChipsAboveDropdown('rcpt_user_chips_{{rcptIdx}}', 'users_placeholder');
  });
});

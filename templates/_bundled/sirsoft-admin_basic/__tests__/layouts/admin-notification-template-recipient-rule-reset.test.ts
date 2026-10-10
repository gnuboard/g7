/**
 * @file admin-notification-template-recipient-rule-reset.test.ts
 * @description 알림 템플릿 편집 창 — 수신자 규칙 종류 전환·관련 사용자 관계·다중 선택 문구 (#599 validate P7)
 *
 * 구조 단언만으로는 표현식이 런타임에 무엇을 만드는지 보장하지 못한다. 레이아웃에 적힌 표현식을
 * 엔진에 그대로 넣어 결과 배열을 본다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import modal from '../../layouts/partials/admin_settings/_modal_notification_template_form.json';

type Json = Record<string, any>;

const NS = 'notification_template_form_modal';

/** 조건에 맞는 첫 노드를 찾는다. */
function findNode(node: unknown, pred: (n: Json) => boolean): Json | undefined {
  if (Array.isArray(node)) {
    for (const item of node) {
      const hit = findNode(item, pred);
      if (hit) return hit;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (pred(obj)) return obj;
    for (const v of Object.values(obj)) {
      const hit = findNode(v, pred);
      if (hit) return hit;
    }
  }
  return undefined;
}

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

const typeSelect = findNode(modal, (n) =>
  n.name === 'Select' && JSON.stringify(actionsOf(n)).includes('type: $event.target.value'),
)!;
const typeSwitchExpr: string = actionsOf(typeSelect)[0].params[`${NS}.recipients`];

const relationSelect = findNode(modal, (n) => n.name === 'Select' && String(n.props?.value ?? '').includes('rcpt.relation'))!;
const relationWarning = findNode(modal, (n) => String(n.text ?? '').includes('relation_undeclared'))!;

/**
 * `{{ … }}` 한 덩어리 바인딩을 값으로 평가한다.
 *
 * `resolveBindings` 는 문자열 보간이라 배열·객체를 문자열로 돌려준다 — 값 자체를 보려면
 * 괄호를 벗겨 `evaluateExpression` 에 넣는다(엔진이 단일 바인딩에서 하는 일과 같다).
 */
function evalBinding(binding: string, context: Json): unknown {
  return dataBindingEngine.evaluateExpression(binding.trim().replace(/^\{\{|\}\}$/g, ''), context);
}

/** 규칙 종류 전환 표현식을 주어진 상태로 평가한다. */
function switchType(recipients: Json[], next: string, relations: string[] = ['post_author']): Json[] {
  return evalBinding(typeSwitchExpr, {
    _global: { [NS]: { recipients, definition: { relations } } },
    rcptIdx: 0,
    $event: { target: { value: next } },
  }) as unknown as Json[];
}

describe('알림 템플릿 편집 창 — 수신자 규칙 종류 전환', () => {
  /**
   * 특정 회원 → 역할로 바꾸면 회원 UUID·이름이 역할 칸으로 넘어가지 않아야 한다(넘어가면 UUID 가 역할 식별자로 저장돼 수신자 0명).
   *
   * @scenario case=specific_users_to_role
   * @effects recipient_rule_type_switch_clears_previous_value
   */
  it('종류를 바꾸면 앞 종류의 value·display_names 를 비운다', () => {
    const result = switchType(
      [{ type: 'specific_users', value: ['0198-uuid'], display_names: ['홍길동'] }],
      'role',
    );

    expect(result[0]).toEqual({ type: 'role' });
  });

  /**
   * 관련 사용자로 바꾸면 서버가 필수로 받는 relation 을 정의가 선언한 첫 관계로 채운다(비우면 저장 422).
   *
   * @scenario case=to_related_user
   * @effects recipient_rule_related_user_seeds_first_declared_relation
   */
  it('관련 사용자로 바꾸면 선언된 첫 relation 을 채운다', () => {
    const result = switchType([{ type: 'role', value: ['admin'], display_names: ['관리자'] }], 'related_user', [
      'order_owner',
      'claim_owner',
    ]);

    expect(result[0]).toEqual({ type: 'related_user', relation: 'order_owner' });
  });

  /**
   * 관련 사용자에서 다른 종류로 가면 relation 이 남지 않는다.
   *
   * @scenario case=related_user_to_trigger_user
   * @effects recipient_rule_type_switch_clears_previous_value
   */
  it('관련 사용자에서 벗어나면 relation 을 지운다', () => {
    const result = switchType([{ type: 'related_user', relation: 'post_author' }], 'trigger_user');

    expect(result[0]).toEqual({ type: 'trigger_user' });
  });

  /**
   * 「행위자 제외」는 역할·특정 회원 사이에서만 이어 가고, 발생시킨 본인 규칙에는 남기지 않는다(남으면 본인이 빠져 0명).
   *
   * @scenario case=exclude_trigger_user_carry
   * @effects recipient_rule_exclude_trigger_user_only_carries_between_group_types
   */
  it('행위자 제외는 역할↔특정 회원 사이에서만 이어 간다', () => {
    const toUsers = switchType([{ type: 'role', value: ['admin'], exclude_trigger_user: true }], 'specific_users');
    const toTrigger = switchType([{ type: 'role', value: ['admin'], exclude_trigger_user: true }], 'trigger_user');

    expect(toUsers[0]).toEqual({ type: 'specific_users', exclude_trigger_user: true });
    expect(toTrigger[0]).toEqual({ type: 'trigger_user' });
  });

  /**
   * 다른 줄은 건드리지 않는다.
   *
   * @scenario case=other_rows_untouched
   * @effects recipient_rule_type_switch_clears_previous_value
   */
  it('전환은 그 줄만 바꾼다', () => {
    const other = { type: 'specific_users', value: ['u-2'], display_names: ['김철수'] };
    const result = switchType([{ type: 'trigger_user' }, other], 'role');

    expect(result[1]).toEqual(other);
  });
});

describe('알림 템플릿 편집 창 — 관련 사용자 관계 선택', () => {
  /**
   * 관계 선택칸 첫 줄은 고를 수 없는 안내 선택지다 — 비어 있을 때 무엇을 골라야 하는지 보인다.
   *
   * @scenario case=empty
   * @effects recipient_relation_select_shows_placeholder
   */
  it('관계 선택지 맨 앞에 비활성 안내 선택지를 둔다', () => {
    const options = evalBinding(relationSelect.props.options, {
      _global: { [NS]: { definition: { relations: ['post_author'] } } },
    }) as unknown as Json[];

    // 하네스에는 번역 사전이 없어 엔진이 `$t:` 접두사를 벗기고 키만 돌려준다 — 키가 맞는지만 본다.
    expect(options[0]).toMatchObject({ value: '', disabled: true });
    expect(String(options[0].label)).toMatch(/notification_definitions\.recipients\.relation_placeholder$/);
    expect(options.slice(1)).toEqual([{ value: 'post_author', label: 'post_author' }]);
  });

  /**
   * 선택지는 정의가 실어 준 관계 이름(`relation_labels`)을 보인다 — 운영자에게 `post_author` 같은 키를 내밀지 않는다.
   * 이름이 없는 관계만 키를 그대로 쓴다.
   *
   * @scenario case=named
   * @effects recipient_relation_select_shows_relation_names
   */
  it('관계 선택지는 relation_labels 의 이름을 쓰고, 이름이 없으면 키를 쓴다', () => {
    const options = evalBinding(relationSelect.props.options, {
      _global: {
        [NS]: { definition: { relations: ['post_author', 'board_managers'], relation_labels: { post_author: '게시글 작성자' } } },
      },
    }) as unknown as Json[];

    expect(options.slice(1)).toEqual([
      { value: 'post_author', label: '게시글 작성자' },
      { value: 'board_managers', label: 'board_managers' },
    ]);
  });

  /**
   * 저장된 관계가 정의의 선언 밖이면 경고를 띄우고, 선언 안이거나 비어 있으면 띄우지 않는다.
   *
   * @scenario case=undeclared
   * @effects recipient_relation_outside_declaration_warns
   */
  it('선언 밖 relation 에만 경고를 띄운다', () => {
    const visible = (relation: string) =>
      evalBinding(
        String(relationWarning?.if),
        { rcpt: { type: 'related_user', relation }, _global: { [NS]: { definition: { relations: ['post_author'] } } } },
      );

    expect(visible('legacy_owner')).toBe(true);
    expect(visible('post_author')).toBe(false);
    expect(visible('')).toBe(false);
  });
});

describe('알림 템플릿 편집 창 — 다중 선택 트리거 문구', () => {
  /**
   * 역할·회원 다중 선택에서 둘 이상 고르면 컴포넌트 기본값("2 selected") 대신 번역 문구가 뜬다.
   *
   * @scenario case=multiple
   * @effects recipient_multi_select_count_label_is_translated
   */
  it('역할·회원 드롭다운이 selectedCountLabel 번역 키를 넘긴다', () => {
    const dropdowns = findAll(modal, (n) => n.name === 'SearchableDropdown');
    const labels = dropdowns.map((d) => d.props?.selectedCountLabel);

    expect(labels).toEqual([
      '$t:admin.settings.notification_definitions.recipients.roles_selected_count|count={{(rcpt.value ?? []).length}}',
      '$t:admin.settings.notification_definitions.recipients.users_selected_count|count={{(rcpt.value ?? []).length}}',
    ]);
  });
});

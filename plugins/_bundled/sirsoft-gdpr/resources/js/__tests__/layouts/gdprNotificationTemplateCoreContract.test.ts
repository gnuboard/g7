/**
 * @file gdprNotificationTemplateCoreContract.test.ts
 * @description GDPR 알림 템플릿 편집 창 — 코어 모달 계약(수신자·관계·역할 다중·채널별 본문 편집기) 정합
 *
 * 이 창은 코어 알림 템플릿 창과 같은 저장 API 를 쓰면서 7.1.0 코어 계약을 따라가지 못했다(실측 2026-10-03):
 * 메일 본문이 CKEditor 없이 HTML 원문 입력칸으로 열리고, 관계를 선언하지 않은 정의에 「관련 사용자」가 나오며,
 * 역할은 하나만 고를 수 있었고, 자기 편집 양식을 대는 알림톡 채널에서는 코어 제목·본문과 저장 버튼이 함께 떠
 * 「저장」이 두 개였다. 환율 플러그인 창과 같은 방식으로 코어 창의 블록을 이식했다.
 *
 * 구조 단언만으로는 표현식이 런타임에 무엇을 만드는지 보장하지 못한다. 레이아웃에 적힌 표현식을
 * 엔진에 그대로 넣어 결과를 본다.
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import modal from '../../../layouts/admin/partials/plugin_settings/_modal_notification_template_edit.json';

type Json = Record<string, any>;

const NS = 'gdpr_notification_template_form_modal';

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

/**
 * 실측한 채널 선언(메일 rich · 사이트내 알림 plain · 알림톡 none)으로 편집 창 문맥을 만든다.
 * 서식 편집기 슬롯은 설치 언어마다 하나라, 그 슬롯이 맡은 언어(`bodyLoc`)를 편집 언어와 같게 둔다.
 */
function channelsCtx(channel: string, editor: string | undefined): Json {
  return {
    gdprNotificationChannels: { data: { channels: [{ id: channel, name: channel, template_editor: editor }] } },
    _global: { [NS]: { editLang: 'ko', template: { channel }, definition: { relations: [], variables: [{ key: 'recipient_name' }] } } },
    $locale: 'ko',
    bodyLoc: 'ko',
  };
}

describe('GDPR 알림 템플릿 편집 창 — 수신자 규칙 종류 전환', () => {
  /**
   * 특정 회원 → 역할로 바꾸면 회원 UUID·이름이 역할 칸으로 넘어가지 않아야 한다(넘어가면 UUID 가 역할 식별자로 저장돼 수신자 0명).
   *
   * @scenario case=specific_users_to_role
   * @effects gdpr_recipient_rule_type_switch_clears_previous_value
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
   * @effects gdpr_recipient_rule_related_user_seeds_first_declared_relation
   */
  it('관련 사용자로 바꾸면 선언된 첫 relation 을 채운다', () => {
    const result = switchType([{ type: 'role', value: ['admin'], display_names: ['관리자'] }], 'related_user', [
      'order_owner',
      'claim_owner',
    ]);

    expect(result[0]).toEqual({ type: 'related_user', relation: 'order_owner' });
  });

  /**
   * 「행위자 제외」는 역할·특정 회원 사이에서만 이어 가고, 발생시킨 본인 규칙에는 남기지 않는다(남으면 본인이 빠져 0명).
   *
   * @scenario case=exclude_trigger_user_carry
   * @effects gdpr_recipient_rule_exclude_trigger_user_only_carries_between_group_types
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
   * @effects gdpr_recipient_rule_type_switch_clears_previous_value
   */
  it('전환은 그 줄만 바꾼다', () => {
    const other = { type: 'specific_users', value: ['u-2'], display_names: ['김철수'] };
    const result = switchType([{ type: 'trigger_user' }, other], 'role');

    expect(result[1]).toEqual(other);
  });
});

describe('GDPR 알림 템플릿 편집 창 — 관련 사용자 선택지와 관계 선택', () => {
  /**
   * 연간 통지 정의는 관계를 선언하지 않는다 — 그런 정의에는 「관련 사용자」 선택지를 세우지 않는다
   * (고르면 서버가 선언 밖 relation 을 422 로 거절하거나 수신자가 0명이 된다).
   *
   * @scenario case=none_declared
   * @effects gdpr_related_user_option_follows_declared_relations
   */
  it('관련 사용자 선택지는 정의가 relation 을 선언할 때만 나온다', () => {
    const values = (relations: string[]) =>
      (evalBinding(typeSelect.props.options, { _global: { [NS]: { definition: { relations } } } }) as Json[]).map(
        (o) => o.value,
      );

    expect(values([])).toEqual(['trigger_user', 'role', 'specific_users']);
    expect(values(['post_author'])).toEqual(['trigger_user', 'related_user', 'role', 'specific_users']);
  });

  /**
   * 관계 선택지는 정의가 실어 준 이름(`relation_labels`)을 쓰고, 맨 앞에 고를 수 없는 안내 선택지를 둔다.
   *
   * @scenario case=named
   * @effects gdpr_recipient_relation_select_shows_relation_names
   */
  it('관계 선택지는 안내 선택지 다음에 relation_labels 의 이름을 보인다', () => {
    const options = evalBinding(relationSelect.props.options, {
      _global: {
        [NS]: { definition: { relations: ['post_author', 'board_managers'], relation_labels: { post_author: '게시글 작성자' } } },
      },
    }) as unknown as Json[];

    // 하네스에는 번역 사전이 없어 엔진이 `$t:` 접두사를 벗기고 키만 돌려준다 — 키가 맞는지만 본다.
    expect(options[0]).toMatchObject({ value: '', disabled: true });
    expect(String(options[0].label)).toMatch(/notification_definitions\.recipients\.relation_placeholder$/);
    expect(options.slice(1)).toEqual([
      { value: 'post_author', label: '게시글 작성자' },
      { value: 'board_managers', label: 'board_managers' },
    ]);
  });

  /**
   * 저장된 관계가 정의의 선언 밖이면 경고를 띄우고, 선언 안이거나 비어 있으면 띄우지 않는다.
   *
   * @scenario case=undeclared
   * @effects gdpr_recipient_relation_outside_declaration_warns
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

describe('GDPR 알림 템플릿 편집 창 — 역할·회원 다중 선택', () => {
  /**
   * 역할은 여러 개를 고르고 값은 배열이다 — 서버가 역할 값을 언제나 배열로 내려 준다.
   *
   * @scenario case=role
   * @effects gdpr_role_rule_is_multi_select
   */
  it('역할 드롭다운은 다중 선택이다', () => {
    const role = findNode(modal, (n) => n.name === 'SearchableDropdown' && String(n.props?.triggerLabel ?? '').includes('role_label'))!;

    expect(role.props.multiple).toBe(true);
    expect(role.props.value).toBe('{{rcpt.value ?? []}}');
  });

  /**
   * 둘 이상 고르면 컴포넌트 기본값("2 selected") 대신 번역 문구가 뜬다.
   *
   * @scenario case=multiple
   * @effects gdpr_recipient_multi_select_count_label_is_translated
   */
  it('역할·회원 드롭다운이 selectedCountLabel 번역 키를 넘긴다', () => {
    const labels = findAll(modal, (n) => n.name === 'SearchableDropdown').map((d) => d.props?.selectedCountLabel);

    expect(labels).toEqual([
      '$t:admin.settings.notification_definitions.recipients.roles_selected_count',
      '$t:admin.settings.notification_definitions.recipients.users_selected_count',
    ]);
  });
});

describe('GDPR 알림 템플릿 편집 창 — 채널별 본문 편집기', () => {
  /**
   * 메일(rich)은 html_editor 확장 슬롯으로 연다 — CKEditor 가 설치돼 있으면 그 편집기가 붙고, 쓴 본문은
   * 이 창이 저장하는 자리(현재 언어 칸)에 곧바로 담긴다. 사이트내 알림·문자(plain)는 평문 입력이다.
   *
   * @scenario case=rich
   * @effects gdpr_body_editor_follows_channel_declaration
   */
  it('rich 채널은 html_editor 슬롯, 그 밖은 평문 편집기를 쓴다', () => {
    const rich = findNode(modal, (n) => n.type === 'extension_point' && n.name === 'html_editor')!;
    const plain = findNode(modal, (n) => n.id === 'gdpr_template_body_plain')!;

    expect(rich).toBeTruthy();
    expect(evalBinding(String(rich.if), channelsCtx('mail', 'rich'))).toBe(true);
    expect(evalBinding(String(rich.if), channelsCtx('database', 'plain'))).toBe(false);
    expect(evalBinding(String(plain.if), channelsCtx('mail', 'rich'))).toBe(false);
    expect(evalBinding(String(plain.if), channelsCtx('database', 'plain'))).toBe(true);
    expect(evalBinding(String(plain.if), channelsCtx('ext_channel', undefined))).toBe(true);
    expect(plain.props.isHtml).toBe(false);
  });

  /**
   * 슬롯의 쓰기 자리는 _global 의 그 슬롯 언어 본문 칸이다 — 편집 언어가 en 이면 en 슬롯이 서고 en 칸에 쓴다.
   *
   * @scenario case=rich
   * @effects gdpr_rich_editor_writes_to_active_language_body
   */
  it('html_editor 슬롯은 _global 의 편집 언어 본문 칸에 쓴다', () => {
    const rich = findNode(modal, (n) => n.type === 'extension_point' && n.name === 'html_editor')!;
    const ctx = {
      gdprNotificationChannels: { data: { channels: [{ id: 'mail', template_editor: 'rich' }] } },
      _global: { [NS]: { editLang: 'en', template: { channel: 'mail' } } },
      $locale: 'ko',
      bodyLoc: 'en',
    };

    expect(evalBinding(String(rich.if), ctx)).toBe(true);
    expect(rich.props.writeScope).toBe('global');
    expect(String(dataBindingEngine.resolveBindings(rich.props.writePath, ctx))).toBe(`${NS}.template.body.en`);
  });

  /**
   * 채널이 자기 편집 양식을 대면(template_editor='none', 예: 알림톡) 코어 언어 탭·제목·본문·이동 경로·변수 안내와
   * 미리보기·저장 버튼을 숨긴다 — 숨기지 않으면 확장 양식 아래 코어 칸이 한 벌 더 뜨고 「저장」이 두 개가 된다.
   *
   * @scenario case=none
   * @effects gdpr_core_editor_hidden_when_channel_owns_editor
   */
  it('template_editor=none 채널이면 코어 편집 칸과 미리보기·저장 버튼을 숨긴다', () => {
    const refs = [
      'gdpr_template_lang_tabs',
      'gdpr_template_subject_input',
      'gdpr_template_body_input',
      'gdpr_template_variables_info',
    ];

    for (const id of refs) {
      const node = findNode(modal, (n) => n.id === id)!;
      expect(node, id).toBeTruthy();
      expect(evalBinding(String(node.if), channelsCtx('alimtalk', 'none')), id).toBe(false);
      expect(evalBinding(String(node.if), channelsCtx('sms', 'plain')), id).toBe(true);
    }

    const footer = findNode(modal, (n) => n.id === 'gdpr_template_modal_footer')!;
    const coreButtons = findAll(footer, (n) => n.name === 'Button' && typeof n.if === 'string');
    expect(coreButtons.length, '미리보기·저장 버튼').toBe(2);
    for (const button of coreButtons) {
      expect(evalBinding(String(button.if), channelsCtx('alimtalk', 'none'))).toBe(false);
      expect(evalBinding(String(button.if), channelsCtx('mail', 'rich'))).toBe(true);
    }
  });

  /**
   * 이동 경로 칸은 사이트내 알림에만 뜬다(메일에는 갈 자리가 없다).
   *
   * @scenario case=none
   * @effects gdpr_click_url_only_for_database_channel
   */
  it('이동 경로 칸은 사이트내 알림 채널에서만 보인다', () => {
    const clickUrl = findNode(modal, (n) => n.id === 'gdpr_template_click_url_input')!;

    expect(evalBinding(String(clickUrl.if), channelsCtx('database', 'plain'))).toBe(true);
    expect(evalBinding(String(clickUrl.if), channelsCtx('mail', 'rich'))).toBe(false);
  });
});

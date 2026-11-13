/**
 * @file admin-real-name-secondary-line.test.ts
 * @description 관리자 화면의 실명 보조 줄 (#490 P17)
 *
 * 시나리오 SSoT: tests/scenarios/admin-real-name-secondary-line.yaml
 *
 * 관리자 응답의 이름 키는 관리자 영역 표시 이름이다. 서버는 회원 조회 권한이 있는 관리자에게, 실명이 표시 이름과
 * 다를 때만 `real_name`(또는 같은 꼬리말의 키)을 싣고 아니면 키 자체를 싣지 않는다. 화면은 그 키가 있을 때만
 * 이름 아래에 작은 보조 줄을 그린다.
 *
 * 화면마다 한 사례로 재는 것:
 * - 보조 줄 노드가 정해진 id 로 한 개 있다.
 * - 그 노드의 조건을 엔진에 넣어, 키가 있으면 보이고 키가 없거나 비었으면 보이지 않는다.
 * - 글자가 응답의 값 경로를 읽는다.
 * - 표(DataGrid) 칸 안의 줄은 번역 인자에 행 값을 쓰지 않는다(칸 안에서는 인자가 행 값을 받지 못한다).
 */

import { describe, it, expect } from 'vitest';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

type Json = Record<string, any>;

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

interface Screen {
  /** 구운 레이아웃 JSON */
  layout: Json;
  /** 보조 줄 노드의 id (원문 그대로) */
  id: string;
  /** 글자가 읽어야 하는 값 경로 */
  valuePath: string;
  /** 표 칸 안인지 */
  inGrid: boolean;
  /** 실명 값(없으면 키를 싣지 않는다)으로 평가 문맥을 만든다 */
  context: (realName?: string) => Json;
}

/** 값이 있을 때만 키를 싣는다(서버 계약과 같은 모양). */
const withKey = (key: string, value?: string): Json => (value === undefined ? {} : { [key]: value });

const SCREENS: Record<string, Screen> = {
  activity_log: {
    layout: require('../../layouts/partials/admin_activity_log_list/_partial_datagrid.json'),
    id: 'activity_log_actor_real_name_{{$rowNumber}}',
    valuePath: 'row.user.real_name',
    inGrid: true,
    context: (rn) => ({ row: { user: { uuid: 'u-1', name: '길동이', ...withKey('real_name', rn) } } }),
  },
  notification_log_recipient: {
    layout: require('../../layouts/partials/admin_notification_log_list/_partial_datagrid.json'),
    id: 'notification_log_recipient_real_name_{{$rowNumber}}',
    valuePath: 'row.recipient.real_name',
    inGrid: true,
    context: (rn) => ({ row: { recipient: { uuid: 'u-1', name: '길동이', ...withKey('real_name', rn) } } }),
  },
  notification_log_sender: {
    layout: require('../../layouts/partials/admin_notification_log_list/_partial_datagrid.json'),
    id: 'notification_log_sender_real_name_{{$rowNumber}}',
    valuePath: 'row.sender.real_name',
    inGrid: true,
    context: (rn) => ({ row: { sender: { uuid: 'u-1', name: '길동이', ...withKey('real_name', rn) } } }),
  },
  member_notification: {
    layout: require('../../layouts/partials/admin_member_notification_list/_partial_datagrid.json'),
    id: 'member_notification_member_real_name_{{$rowNumber}}',
    valuePath: 'row.member.real_name',
    inGrid: true,
    context: (rn) => ({ row: { member: { uuid: 'u-1', name: '길동이', ...withKey('real_name', rn) } } }),
  },
  schedule_history: {
    layout: require('../../layouts/partials/admin_schedule_list/_modal_history.json'),
    id: 'history_triggered_by_real_name_{{$idx}}',
    valuePath: 'item.triggered_by?.real_name',
    inGrid: false,
    context: (rn) => ({ item: { triggered_by: { name: '길동이', ...withKey('real_name', rn) } } }),
  },
  menu_view: {
    layout: require('../../layouts/partials/admin_menu_list/_panel_view.json'),
    id: 'menu_view_creator_real_name',
    valuePath: '_global.selectedMenu?.creator?.real_name',
    inGrid: false,
    context: (rn) => ({ _global: { selectedMenu: { creator: { name: '길동이', ...withKey('real_name', rn) } } } }),
  },
  menu_form: {
    layout: require('../../layouts/partials/admin_menu_list/_panel_form.json'),
    id: 'menu_form_creator_real_name',
    valuePath: '_global.selectedMenu?.creator?.real_name',
    inGrid: false,
    context: (rn) => ({ _global: { selectedMenu: { creator: { name: '길동이', ...withKey('real_name', rn) } } } }),
  },
  layout_version: {
    layout: require('../../layouts/partials/admin_template_layout_edit/_modal_version_history.json'),
    id: 'version_saved_by_real_name_{{idx}}',
    valuePath: 'version.created_by_real_name',
    inGrid: false,
    context: (rn) => ({ version: { created_by_name: '길동이', ...withKey('created_by_real_name', rn) } }),
  },
  layout_extension_version: {
    layout: require('../../layouts/partials/admin_template_layout_edit/_modal_extension_version_history.json'),
    id: 'extension_version_saved_by_real_name_{{idx}}',
    valuePath: 'version.created_by_real_name',
    inGrid: false,
    context: (rn) => ({ version: { created_by_name: '길동이', ...withKey('created_by_real_name', rn) } }),
  },
  privacy_withdrawn: {
    layout: require('../../layouts/partials/admin_settings/_tab_privacy.json'),
    id: 'withdrawn_manual_run_real_name',
    valuePath: "privacyManualRuns?.data?.targets?.['retention:user-withdrawn']?.run?.requester_real_name",
    inGrid: false,
    context: (rn) => ({
      privacyManualRuns: {
        data: { targets: { 'retention:user-withdrawn': { run: { requester_name: '길동이', ...withKey('requester_real_name', rn) } } } },
      },
    }),
  },
  privacy_dormant: {
    layout: require('../../layouts/partials/admin_settings/_tab_privacy.json'),
    id: 'dormant_manual_run_real_name_{{stage}}',
    valuePath: "privacyManualRuns?.data?.targets?.['dormant:' + stage]?.run?.requester_real_name",
    inGrid: false,
    context: (rn) => ({
      stage: 'notice',
      privacyManualRuns: {
        data: { targets: { 'dormant:notice': { run: { requester_name: '길동이', ...withKey('requester_real_name', rn) } } } },
      },
    }),
  },
  privacy_search_history: {
    layout: require('../../layouts/partials/admin_settings/_tab_privacy.json'),
    id: 'search_history_manual_run_real_name',
    valuePath: "privacyManualRuns?.data?.targets?.['retention:search-history']?.run?.requester_real_name",
    inGrid: false,
    context: (rn) => ({
      privacyManualRuns: {
        data: { targets: { 'retention:search-history': { run: { requester_name: '길동이', ...withKey('requester_real_name', rn) } } } },
      },
    }),
  },
  password_expiry: {
    layout: require('../../layouts/partials/admin_settings/_tab_security_password_policy.json'),
    id: 'password_expiry_manual_run_real_name_{{stage}}',
    valuePath: "passwordExpiryManualRuns?.data?.targets?.['password_expiry:' + stage]?.run?.requester_real_name",
    inGrid: false,
    context: (rn) => ({
      stage: 'notice',
      passwordExpiryManualRuns: {
        data: { targets: { 'password_expiry:notice': { run: { requester_name: '길동이', ...withKey('requester_real_name', rn) } } } },
      },
    }),
  },
  visitors: {
    layout: require('../../layouts/admin_analytics_visitors.json'),
    id: 'visitors_member_real_name_{{$rowNumber}}',
    valuePath: 'row.user.real_name',
    inGrid: true,
    context: (rn) => ({ row: { user: { uuid: 'u-1', name: '길동이', ...withKey('real_name', rn) } } }),
  },
  realtime: {
    layout: require('../../layouts/admin_analytics_realtime.json'),
    id: 'realtime_member_real_name_{{$rowNumber}}',
    valuePath: 'row.user.real_name',
    inGrid: true,
    context: (rn) => ({ row: { user: { uuid: 'u-1', name: '길동이', ...withKey('real_name', rn) } } }),
  },
  visitor_detail: {
    layout: require('../../layouts/admin_analytics_visitor_detail.json'),
    id: 'card_member_real_name',
    valuePath: 'analytics_visitor?.data?.visitor?.user?.real_name',
    inGrid: false,
    context: (rn) => ({
      analytics_visitor: { data: { visitor: { user: { name: '길동이', ...withKey('real_name', rn) } } } },
    }),
  },
};

/** 한 화면의 보조 줄을 잰다. */
function assertSecondaryLine(key: keyof typeof SCREENS): void {
  const screen = SCREENS[key];
  const nodes = findAll(screen.layout, (n) => n.id === screen.id);

  // 보조 줄은 화면에 한 개다
  expect(nodes.length).toBe(1);
  const node = nodes[0];

  // 키가 있을 때만 보인다. 서버는 조건에 맞지 않으면 키 자체를 싣지 않는다
  expect(typeof node.if).toBe('string');
  expect(evalBinding(node.if, screen.context('홍길동'))).toBe(true);
  expect(evalBinding(node.if, screen.context())).toBe(false);
  expect(evalBinding(node.if, screen.context(''))).toBe(false);
  // 회원 자체가 없는 줄(시스템 기록, 지워진 회원)에서도 터지지 않고 보이지 않는다
  expect(evalBinding(node.if, {})).toBe(false);

  // 글자가 응답의 실명 값을 읽는다
  const text = String(node.text ?? '');
  expect(text).toContain(screen.valuePath);

  if (screen.inGrid) {
    // 표 칸 안: 값을 그대로 그리고 번역 인자에 행 값을 싣지 않는다. 무엇인지는 title 로 알린다
    expect(text).toBe(`{{${screen.valuePath}}}`);
    expect(text).not.toContain('$t:');
    expect(node.props?.title).toBe('$t:admin.common.real_name.label');
    expect(evalBinding(text, screen.context('홍길동'))).toBe('홍길동');
  } else if (text.startsWith('$t:')) {
    // 문장 안의 이름(실행자, 저장한 사람, 요청자)은 「실명: 홍길동」 꼴의 줄을 따로 둔다
    expect(text).toBe(`$t:admin.common.real_name.with_label|name={{${screen.valuePath}}}`);
  } else {
    expect(text).toBe(`{{${screen.valuePath}}}`);
    expect(node.props?.title).toBe('$t:admin.common.real_name.label');
    expect(evalBinding(text, screen.context('홍길동'))).toBe('홍길동');
  }

  // 흐린 작은 글자
  expect(String(node.props?.className ?? '')).toMatch(/text-tertiary|text-hint/);
}

describe('관리자 화면의 실명 보조 줄', () => {
  /**
   * @scenario case=activity_log
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('활동 로그 목록의 행위자 칸', () => assertSecondaryLine('activity_log'));

  /**
   * @scenario case=notification_log_recipient
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('알림 발송 이력의 받는 사람 칸', () => assertSecondaryLine('notification_log_recipient'));

  /**
   * @scenario case=notification_log_sender
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('알림 발송 이력의 보낸 사람 칸', () => assertSecondaryLine('notification_log_sender'));

  /**
   * @scenario case=member_notification
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('회원 알림함의 회원 칸', () => assertSecondaryLine('member_notification'));

  /**
   * @scenario case=schedule_history
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('스케줄 실행 이력의 실행자', () => assertSecondaryLine('schedule_history'));

  /**
   * @scenario case=menu_view
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('메뉴 보기 패널의 만든 사람', () => assertSecondaryLine('menu_view'));

  /**
   * @scenario case=menu_form
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('메뉴 수정 패널의 만든 사람', () => assertSecondaryLine('menu_form'));

  /**
   * @scenario case=layout_version
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('레이아웃 버전 이력의 저장한 사람', () => assertSecondaryLine('layout_version'));

  /**
   * 확장 조각의 버전 이력에는 저장한 사람 줄이 없었다. 화면 판본 이력과 같은 줄을 두고 그 아래에 실명 보조 줄을 둔다.
   *
   * @scenario case=layout_extension_version
   * @effects real_name_line_shows_only_when_server_sends_key, extension_version_history_shows_saved_by
   */
  it('레이아웃 확장 버전 이력의 저장한 사람', () => {
    assertSecondaryLine('layout_extension_version');

    const savedBy = findAll(SCREENS.layout_extension_version.layout, (n) => n.id === 'extension_version_saved_by_{{idx}}');
    expect(savedBy.length).toBe(1);
    expect(savedBy[0].text).toBe('$t:admin.templates.modals.version_history.saved_by|name={{version.created_by_name}}');
    // 저장한 사람이 없는 판본(동기화가 만든 것)은 줄을 그리지 않는다
    expect(evalBinding(savedBy[0].if, { version: { created_by_name: '길동이' } })).toBe(true);
    expect(evalBinding(savedBy[0].if, { version: { created_by_name: null } })).toBe(false);
  });

  /**
   * @scenario case=privacy_withdrawn
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('개인정보 수동 실행(탈퇴 회원 파기)의 요청자', () => assertSecondaryLine('privacy_withdrawn'));

  /**
   * @scenario case=privacy_dormant
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('개인정보 수동 실행(휴면 단계)의 요청자', () => assertSecondaryLine('privacy_dormant'));

  /**
   * @scenario case=privacy_search_history
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('개인정보 수동 실행(최근 검색어)의 요청자', () => assertSecondaryLine('privacy_search_history'));

  /**
   * @scenario case=password_expiry
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('비밀번호 만료 수동 실행의 요청자', () => assertSecondaryLine('password_expiry'));

  /**
   * @scenario case=visitors
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('방문자 목록의 회원 칸', () => assertSecondaryLine('visitors'));

  /**
   * @scenario case=realtime
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('현재 접속자의 회원', () => assertSecondaryLine('realtime'));

  /**
   * 방문자 상세 카드는 이름과 값이 좌우로 놓이는 줄이라, 이름과 보조 줄을 한 묶음으로 감싸 값 쪽에 세로로 쌓는다.
   *
   * @scenario case=visitor_detail
   * @effects real_name_line_shows_only_when_server_sends_key
   */
  it('방문자 상세 카드의 회원 줄', () => {
    assertSecondaryLine('visitor_detail');

    const row = findAll(SCREENS.visitor_detail.layout, (n) => n.id === 'card_member_row')[0];
    const group = (row.children as Json[]).find((c) => JSON.stringify(c).includes('card_member_real_name'))!;
    // 이름이 있을 때만 묶음이 서고, 그 안에 이름 줄과 보조 줄이 차례로 놓인다
    expect(group.if).toBe('{{!!analytics_visitor?.data?.visitor?.user?.name}}');
    expect((group.children as Json[]).map((c) => c.id ?? null)).toEqual([null, 'card_member_real_name']);
    expect(String((group.children as Json[])[0].text)).toContain('analytics_visitor.data.visitor.user.name');
  });
});

describe('실명 보조 줄의 문구', () => {
  /**
   * @scenario case=lang_keys
   * @effects real_name_lang_keys_exist_in_ko_and_en
   */
  it('ko 와 en 에 같은 키가 있다', () => {
    const ko = require('../../lang/partial/ko/admin.json');
    const en = require('../../lang/partial/en/admin.json');

    expect(ko.common.real_name).toEqual({ label: '실명', with_label: '실명: {{name}}' });
    expect(Object.keys(en.common.real_name)).toEqual(Object.keys(ko.common.real_name));
    expect(en.common.real_name.with_label).toContain('{{name}}');
  });
});

/**
 * @file admin-notification-identity-category.test.tsx
 * @description 알림 설정 — 분류 칩 · 본인인증 카드 · 본인인증 탭 안내 (#699)
 *
 * 7.1.0 에서 본인인증 메일 문구가 알림 정의(category=identity)가 되어 「알림 설정」 탭으로 옮겨졌다.
 * 이 파일은 그 화면 계약을 바인딩 식을 실제 값으로 평가해 고정한다.
 *
 *  - 분류 칩(전체·일반 알림·본인인증)은 `?category=` 로 영속하고 목록 데이터소스에 그대로 넘긴다
 *  - [정책별 문구 추가] 는 분류가 본인인증이고 서버가 can_create 를 줄 때만 보인다
 *  - 본인인증 카드: 범위 배지(공통 기본/목적 이름/정책) · 분류 배지 · 수신자 고정 문구, 수신자 규칙 배지는 감춤
 *  - 운영자가 추가한 정의는 [기본값 복원] 을 감추고, [삭제] 는 서버 abilities.can_delete 로만 연다
 *  - 템플릿 편집 창은 본인인증 정의에서 수신자 편집 영역 대신 고정 문구를 보인다
 *  - 본인인증 탭 「메시지」 구획은 알림 설정(분류 본인인증)으로 가는 안내 상자만 남는다
 *
 * @scenario surface=admin_screen
 */

import { describe, it, expect } from 'vitest';
import notificationTab from '../../layouts/partials/admin_settings/_tab_notification_definitions.json';
import templateFormModal from '../../layouts/partials/admin_settings/_modal_notification_template_form.json';
import identityMessagesTab from '../../layouts/partials/admin_settings/_tab_identity_messages.json';
import adminSettingsLayout from '../../layouts/admin_settings.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/** 트리 전체를 돌며 조건에 맞는 노드를 모두 모은다. */
function collect(node: unknown, match: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (match(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(node);
  return out;
}

/** id 로 노드 하나를 찾는다. */
function byId(node: unknown, id: string): Json {
  const found = collect(node, (n) => n.id === id)[0];
  expect(found, `노드 ${id} 가 없습니다.`).toBeDefined();
  return found;
}

/** 텍스트(정확히 같음)로 노드 하나를 찾는다. */
function byText(node: unknown, text: string): Json {
  const found = collect(node, (n) => n.text === text)[0];
  expect(found, `텍스트 ${text} 노드가 없습니다.`).toBeDefined();
  return found;
}

/** `{{ ... }}` 바인딩 식을 주어진 문맥으로 평가한다. */
function evaluate(binding: string, ctx: Record<string, unknown>): any {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(binding).trim());
  expect(m, `바인딩 식이 아닙니다: ${binding}`).not.toBeNull();
  const keys = Object.keys(ctx);
  // eslint-disable-next-line @typescript-eslint/no-implied-eval, no-new-func
  const fn = new Function(...keys, `return (${m![1]});`);
  return fn(...keys.map((k) => ctx[k]));
}

/** 키 경로(`a.b.c`)로 lang 사전 값을 읽는다. */
function langAt(dict: unknown, key: string): unknown {
  return key.split('.').reduce<any>((acc, part) => (acc == null ? undefined : acc[part]), dict);
}

const $t = (key: string) => `[${key}]`;

const identityDef = (over: Json = {}) => ({
  id: 10,
  type: 'identity.purpose.signup',
  category: 'identity',
  extension_type: 'core',
  extension_identifier: 'core',
  is_default: true,
  has_customized_templates: false,
  abilities: { can_update: true, can_delete: false },
  templates: [{ channel: 'mail', recipients: [] }],
  ...over,
});

describe('분류 칩 — ?category= 로 영속하고 목록에 넘긴다', () => {
  /**
   * @effects category_chips_persist_in_query
   */
  it('칩 3개(전체·일반·본인인증)가 category 쿼리를 바꾸며 1쪽으로 돌아간다', () => {
    const chip = collect(notificationTab, (n) => n.iteration?.item_var === 'cat')[0];
    expect(chip, '분류 칩 반복 노드가 없습니다.').toBeDefined();
    const values = JSON.parse(chip.iteration.source).map((c: Json) => c.value);
    expect(values).toEqual(['', 'general', 'identity']);

    const nav = collect(chip, (n) => n.handler === 'navigate')[0];
    expect(nav.params.mergeQuery).toBe(true);
    expect(nav.params.query).toEqual({ category: '{{cat.value}}', page: 1 });
  });

  /**
   * @effects category_chips_persist_in_query
   */
  it('목록 데이터소스가 category 쿼리와 코어 소유 범위(extension_type=core)를 그대로 보낸다', () => {
    const source = collect(adminSettingsLayout, (n) => n.id === 'notificationDefinitions' && n.endpoint)[0];
    expect(source.params.category).toBe('{{query.category}}');
    expect(source.params.extension_type).toBe('core');
    expect(source.params.extension_identifier).toBeUndefined();
  });

  /**
   * @effects category_chips_persist_in_query
   */
  it('칩 라벨 키가 ko·en 에 모두 있다', () => {
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      for (const key of ['label', 'all', 'general', 'identity']) {
        expect(langAt(dict, `settings.notification_definitions.category.${key}`), key).toBeTruthy();
      }
    }
  });
});

describe('[정책별 문구 추가] 버튼', () => {
  /**
   * @effects identity_add_button_gated_by_category_and_ability
   */
  it('분류가 본인인증이고 서버가 can_create 를 줄 때만 보인다', () => {
    const btn = byId(notificationTab, 'notif_identity_add_button');
    const ctx = (category: string | undefined, canCreate: boolean) => ({
      query: { category },
      notificationDefinitions: { data: { abilities: { can_create: canCreate } } },
    });
    expect(evaluate(btn.if, ctx('identity', true))).toBe(true);
    expect(evaluate(btn.if, ctx('identity', false))).toBe(false);
    expect(evaluate(btn.if, ctx('general', true))).toBe(false);
    expect(evaluate(btn.if, ctx(undefined, true))).toBe(false);
  });

  /**
   * @effects identity_add_button_gated_by_category_and_ability
   */
  it('창을 열기 전에 빈 폼을 전역에 마련하고 추가 창을 연다', () => {
    const btn = byId(notificationTab, 'notif_identity_add_button');
    const init = collect(btn, (n) => n.handler === 'setState' && n.params?.identity_notification_add_modal)[0];
    expect(init.params.identity_notification_add_modal.form).toEqual({ policy_key: '', name: null, subject: null, body: null });
    expect(init.params.identity_notification_add_modal.isSaving).toBe(false);
    const open = collect(btn, (n) => n.handler === 'openModal')[0];
    expect(JSON.stringify(open)).toContain('modal_notification_identity_definition_add');
  });
});

describe('본인인증 카드', () => {
  const card = () => collect(notificationTab, (n) => n.iteration?.item_var === 'def')[0];

  /**
   * @effects identity_card_badges_and_fixed_recipient
   */
  it('범위 배지가 type 에서 공통 기본 / 목적 / 정책 라벨을 만든다', () => {
    const badge = collect(card(), (n) => typeof n.text === 'string' && n.text.includes('identity_scope.default'))[0];
    expect(evaluate(badge.if, { def: identityDef() })).toBe(true);
    expect(evaluate(badge.if, { def: { category: null } })).toBe(false);

    expect(evaluate(badge.text, { def: identityDef({ type: 'identity.default' }), $t, identityPurposes: undefined })).toBe(
      '[admin.settings.notification_definitions.identity_scope.default]',
    );
    // 목적은 서버가 주는 목적 이름(로케일)으로 — 목록을 아직 못 받았으면 키로 보인다
    const purposes = { data: [{ id: 'signup', label: '회원가입' }] };
    expect(evaluate(badge.text, { def: identityDef({ type: 'identity.purpose.signup' }), $t, identityPurposes: purposes })).toBe(
      '[admin.settings.notification_definitions.identity_scope.purpose]: 회원가입',
    );
    expect(evaluate(badge.text, { def: identityDef({ type: 'identity.purpose.signup' }), $t, identityPurposes: undefined })).toBe(
      '[admin.settings.notification_definitions.identity_scope.purpose]: signup',
    );
    expect(evaluate(badge.text, { def: identityDef({ type: 'identity.policy.admin.withdraw' }), $t, identityPurposes: undefined })).toBe(
      '[admin.settings.notification_definitions.identity_scope.policy]: admin.withdraw',
    );
    // 정책 키는 공백 없는 긴 문자열일 수 있다(최대 120자) — 줄바꿈되지 않으면 모바일(390px)에서 화면 전체가
    // 가로로 밀린다(실측: 90자 키에서 콘텐츠 영역 scrollWidth 649 > 375).
    expect(String(badge.props?.className ?? '')).toContain('break-all');
    // 해시 type 은 서버가 실어 주는 정책 키로 보인다
    expect(evaluate(badge.text, { def: identityDef({ type: 'identity.policy.~0123456789abcdef0123', policy_key: 'admin.long' }), $t, identityPurposes: undefined })).toBe(
      '[admin.settings.notification_definitions.identity_scope.policy]: admin.long',
    );
  });

  /**
   * @effects identity_card_badges_and_fixed_recipient
   */
  it('본인인증 카드는 수신자 고정 문구를 보이고 수신자 규칙 배지는 감춘다', () => {
    const fixed = byText(card(), '$t:admin.settings.notification_definitions.identity_recipient_fixed');
    expect(evaluate(fixed.if, { def: identityDef() })).toBe(true);
    expect(evaluate(fixed.if, { def: { category: null } })).toBe(false);

    const rules = collect(card(), (n) => typeof n.id === 'string' && n.id.startsWith('recipients_badge_'))[0];
    const withRules = { channel: 'mail', recipients: [{ type: 'trigger_user' }] };
    expect(evaluate(rules.if, { def: identityDef({ templates: [withRules] }), query: {} })).toBe(false);
    expect(evaluate(rules.if, { def: { category: null, templates: [withRules] }, query: {} })).toBe(true);
  });

  /**
   * @effects operator_definition_reset_hidden_delete_by_ability
   */
  it('운영자가 추가한 정의는 [기본값 복원] 을 감추고, 선언 정의는 고쳤을 때 보인다', () => {
    const reset = byText(card(), '$t:admin.settings.notification_definitions.btn_reset');
    const operator = identityDef({ type: 'identity.policy.p', extension_identifier: 'admin', is_default: false });
    expect(evaluate(reset.if, { def: operator })).toBe(false);
    expect(evaluate(reset.if, { def: identityDef({ is_default: false }) })).toBe(true);
    expect(evaluate(reset.if, { def: identityDef() })).toBe(false);
    // 일반 알림은 종전 그대로
    expect(evaluate(reset.if, { def: { category: null, is_default: true, has_customized_templates: true } })).toBe(true);
  });

  /**
   * @effects operator_definition_reset_hidden_delete_by_ability
   */
  it('[삭제] 는 서버 abilities.can_delete 로만 열고, 삭제 창에 대상 id·이름을 넘긴다', () => {
    const del = byText(card(), '$t:admin.settings.notification_definitions.btn_delete');
    expect(evaluate(del.if, { def: identityDef({ abilities: { can_delete: true } }) })).toBe(true);
    expect(evaluate(del.if, { def: identityDef() })).toBe(false);

    const seed = collect(del, (n) => n.handler === 'setState' && n.params?.identity_notification_delete_modal)[0];
    expect(seed.params.identity_notification_delete_modal.id).toBe('{{def.id}}');
    expect(seed.params.identity_notification_delete_modal.isDeleting).toBe(false);
  });

  /**
   * @effects identity_card_badges_and_fixed_recipient
   */
  it('카드 제목은 서버가 준 로케일 이름을 쓴다(운영자 추가 정의는 번역 키가 없다)', () => {
    const title = collect(card(), (n) => n.name === 'H4')[0];
    expect(title.text).toBe('{{$localized(def.name) || def.type}}');
  });

  /**
   * @effects identity_card_badges_and_fixed_recipient
   */
  it('빈 목록 안내는 분류에 맞는 문구를 쓴다', () => {
    const empty = collect(notificationTab, (n) => typeof n.text === 'string' && n.text.includes('identity_empty'))[0];
    expect(evaluate(empty.text, { query: { category: 'identity' } })).toBe('$t:admin.settings.notification_definitions.identity_empty');
    expect(evaluate(empty.text, { query: {} })).toBe('$t:admin.settings.notification_definitions.empty_message');
  });
});

describe('템플릿 편집 창 — 본인인증 정의', () => {
  /**
   * @effects template_form_hides_recipients_for_identity
   */
  it('수신자 편집 영역 대신 고정 문구를 보인다', () => {
    const fixed = byId(templateFormModal, 'template_recipients_fixed');
    const section = byId(templateFormModal, 'template_recipients_section');
    const ctx = (category: string | null) => ({ _global: { notification_template_form_modal: { definition: { category } } } });
    expect(evaluate(fixed.if, ctx('identity'))).toBe(true);
    expect(evaluate(section.if, ctx('identity'))).toBe(false);
    expect(evaluate(fixed.if, ctx(null))).toBe(false);
    expect(evaluate(section.if, ctx(null))).toBe(true);
  });
});

describe('본인인증 탭 「메시지」 구획 — 안내 상자', () => {
  /**
   * @effects identity_tab_points_to_notification_settings
   */
  it('편집 UI 없이 알림 설정(분류 본인인증)으로 가는 링크만 둔다', () => {
    expect(collect(identityMessagesTab, (n) => n.iteration).length, '목록 반복이 남아 있습니다.').toBe(0);
    expect(collect(identityMessagesTab, (n) => n.handler === 'apiCall').length).toBe(0);

    const link = byId(identityMessagesTab, 'identity_messages_moved_link');
    expect(link.props.href).toBe('/admin/settings?tab=notification_definitions&category=identity');
    const nav = collect(link, (n) => n.handler === 'navigate')[0];
    expect(nav.params.path).toBe('/admin/settings');
    expect(nav.params.query).toEqual({ tab: 'notification_definitions', category: 'identity' });
    const tab = collect(link, (n) => n.handler === 'setState' && n.params?.activeSettingsTab)[0];
    expect(tab.params.activeSettingsTab).toBe('notification_definitions');
  });

  /**
   * @effects identity_tab_points_to_notification_settings
   */
  it('안내 문구 키가 ko·en 에 있고, 옛 메시지 편집 키는 남지 않았다', () => {
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      expect(langAt(dict, 'settings.identity.messages.moved_notice.body')).toBeTruthy();
      expect(langAt(dict, 'settings.identity.messages.moved_notice.link')).toBeTruthy();
      expect(langAt(dict, 'settings.identity.messages.add_modal')).toBeUndefined();
      expect(langAt(dict, 'settings.identity.messages.modal')).toBeUndefined();
    }
  });
});

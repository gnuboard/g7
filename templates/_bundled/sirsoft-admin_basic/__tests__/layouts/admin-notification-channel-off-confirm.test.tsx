/**
 * @file admin-notification-channel-off-confirm.test.tsx
 * @description 알림 설정 [저장] — 메일 채널 끄기 확인 창 (#699)
 *
 * 7.1.0 부터 본인인증·관리자 2단계 인증 메일도 알림 설정의 메일 토글을 따른다. 메일을 켜짐 → 꺼짐으로
 * 바꿔 저장하면 바로 저장하지 않고 확인 창을 띄운다(인증 앱을 등록하지 않은 관리자는 로그인할 수 없게 된다).
 *
 *  - [저장] 의 분기 식: 알림 탭 · 저장된 값이 켜짐 · 폼 값이 꺼짐 일 때만 확인 창, 그 밖에는 종전 저장
 *  - 확인 창은 부모 화면 상태를 못 읽으므로 저장할 채널 목록을 전역으로 받는다
 *  - [취소] 는 부모 폼 커밋 규칙대로 두 자리(전역 _local · $parent._local)에 같은 값을 쓴다
 *  - [끄기] 는 알림 채널 묶음(_tab=notifications)으로 저장하고 부모의 「고친 것 있음」 을 끈다
 *
 * @scenario surface=admin_screen
 */

import { describe, it, expect } from 'vitest';
import adminSettingsLayout from '../../layouts/admin_settings.json';
import confirmModal from '../../layouts/partials/admin_settings/_modal_notification_channel_off_confirm.json';
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

/** `{{ ... }}` 바인딩 식을 주어진 문맥으로 평가한다. */
function evaluate(binding: string, ctx: Record<string, unknown>): any {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(binding).trim());
  expect(m, `바인딩 식이 아닙니다: ${binding}`).not.toBeNull();
  const keys = Object.keys(ctx);
  // eslint-disable-next-line @typescript-eslint/no-implied-eval, no-new-func
  const fn = new Function(...keys, `return (${m![1]});`);
  return fn(...keys.map((k) => ctx[k]));
}

const channels = (mail: boolean, database = true) => [
  { id: 'mail', is_active: mail, sort_order: 1 },
  { id: 'database', is_active: database, sort_order: 2 },
];

describe('[저장] 분기 — 메일을 끄는 저장만 확인 창으로', () => {
  const saveSwitch = () =>
    collect(adminSettingsLayout, (n) => n.handler === 'switch' && JSON.stringify(n.cases ?? {}).includes('confirm_mail_off'))[0];

  const ctx = (tab: string, saved: boolean, form: boolean) => ({
    _global: { activeSettingsTab: tab },
    query: {},
    settings: { data: { notifications: { channels: channels(saved) } } },
    _local: { form: { notifications: { channels: channels(form) } } },
  });

  /**
   * @effects mail_off_save_asks_confirmation
   */
  it('알림 탭에서 켜짐 → 꺼짐일 때만 confirm_mail_off, 그 밖에는 save', () => {
    const sw = saveSwitch();
    expect(sw, '저장 버튼의 분기(switch)가 없습니다.').toBeDefined();
    expect(evaluate(sw.params.value, ctx('notification_definitions', true, false))).toBe('confirm_mail_off');
    // 이미 꺼져 있던 것을 다시 저장 / 켜는 저장 / 그대로 저장
    expect(evaluate(sw.params.value, ctx('notification_definitions', false, false))).toBe('save');
    expect(evaluate(sw.params.value, ctx('notification_definitions', false, true))).toBe('save');
    expect(evaluate(sw.params.value, ctx('notification_definitions', true, true))).toBe('save');
    // 다른 탭의 저장은 영향을 받지 않는다
    expect(evaluate(sw.params.value, ctx('general', true, false))).toBe('save');
  });

  /**
   * @effects mail_off_save_asks_confirmation
   */
  it('사이트 내 알림(database) 채널만 끄는 저장은 확인 창 없이 바로 저장한다', () => {
    const sw = saveSwitch();
    const ctxDb = (savedDb: boolean, formDb: boolean, formMail = true) => ({
      _global: { activeSettingsTab: 'notification_definitions' },
      query: {},
      settings: { data: { notifications: { channels: channels(true, savedDb) } } },
      _local: { form: { notifications: { channels: channels(formMail, formDb) } } },
    });
    // 메일은 켜진 채 사이트 내 알림만 켜짐 → 꺼짐
    expect(evaluate(sw.params.value, ctxDb(true, false))).toBe('save');
    // 사이트 내 알림을 다시 켜는 저장
    expect(evaluate(sw.params.value, ctxDb(false, true))).toBe('save');
    // 둘 다 끄면 메일을 끄는 저장이므로 확인 창
    expect(evaluate(sw.params.value, ctxDb(true, false, false))).toBe('confirm_mail_off');
  });

  /**
   * @effects mail_off_save_asks_confirmation
   */
  it('확인 분기는 저장할 채널 목록을 전역에 담고 확인 창을 연다 — 저장 요청은 보내지 않는다', () => {
    const sw = saveSwitch();
    const confirmCase = sw.cases.confirm_mail_off;
    const seed = collect(confirmCase, (n) => n.handler === 'setState' && n.params?.notification_channel_off_modal)[0];
    expect(seed.params.notification_channel_off_modal.channels).toBe('{{_local.form?.notifications?.channels ?? []}}');
    expect(JSON.stringify(collect(confirmCase, (n) => n.handler === 'openModal'))).toContain('modal_notification_channel_off_confirm');
    expect(collect(confirmCase, (n) => n.handler === 'apiCall').length).toBe(0);

    // 종전 저장 경로는 save 분기에 그대로 있다
    expect(collect(sw.cases.save, (n) => n.handler === 'apiCall').length).toBeGreaterThan(0);
  });
});

describe('확인 창', () => {
  /**
   * @effects mail_off_cancel_restores_toggle_in_both_stores
   */
  it('[취소] 는 메일만 켜짐으로 되돌린 목록을 전역 _local 과 $parent._local 두 자리에 같은 식으로 쓴다', () => {
    const cancel = byId(confirmModal, 'channel_off_cancel_button');
    const writes = collect(cancel, (n) => n.handler === 'setState');
    const toGlobal = writes.find((w) => w.params?.target === 'global' && '_local.form.notifications.channels' in w.params);
    const toParent = writes.find((w) => w.params?.target === '$parent._local.form.notifications.channels');
    expect(toGlobal, '저장이 읽는 자리(전역 _local)에 쓰지 않습니다.').toBeDefined();
    expect(toParent, '화면이 읽는 자리($parent._local)에 쓰지 않습니다.').toBeDefined();
    expect(toGlobal!.params['_local.form.notifications.channels']).toBe(toParent!.params.value);

    const restored = evaluate(toParent!.params.value, { _global: { notification_channel_off_modal: { channels: channels(false) } } });
    expect(restored).toEqual(channels(true));
    expect(collect(cancel, (n) => n.handler === 'apiCall').length).toBe(0);
  });

  /**
   * @effects mail_off_confirm_saves_channels
   */
  it('[끄기] 는 알림 채널 묶음으로 저장하고, 성공하면 부모의 「고친 것 있음」 을 끄고 설정을 다시 받는다', () => {
    const confirm = byId(confirmModal, 'channel_off_confirm_button');
    const calls = collect(confirm, (n) => n.handler === 'apiCall');
    expect(calls.length, '[끄기] 는 저장 요청을 한 번만 보낸다').toBe(1);
    const call = calls[0];
    expect(call.target).toBe('/api/admin/settings');
    expect(call.params.method).toBe('POST');
    expect(call.params.body).toEqual({
      _tab: 'notifications',
      notifications: { channels: '{{_global.notification_channel_off_modal?.channels ?? []}}' },
    });

    const parent = collect(call, (n) => n.handler === 'setState' && n.params?.target === '$parent._local')[0];
    expect(parent.params.hasChanges).toBe(false);
    expect(collect(call, (n) => n.handler === 'refetchDataSource' && n.params?.dataSourceId === 'settings').length).toBe(1);
  });

  /**
   * @effects mail_off_save_asks_confirmation
   */
  it('바깥을 눌러 닫히지 않고, 경고 문구가 ko·en 에 있다', () => {
    expect(confirmModal.props.closeOnOverlayClick).toBe(false);
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      const c = (dict as Json).settings.notification_definitions.channel_off_confirm;
      expect(c.title && c.message && c.btn_cancel && c.btn_confirm).toBeTruthy();
    }
  });
});

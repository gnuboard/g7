/**
 * @file admin-notification-identity-definition-modals.test.tsx
 * @description 알림 설정 > 본인인증 — 정책별 문구 추가·삭제 창 (#699)
 *
 * 7.0.x 본인인증 탭의 「메시지 정의 추가·삭제」 창을 알림 정의 API 로 옮긴 것이다. 필드·검증·오류 표시는
 * 그대로고 보내는 곳과 본문 모양만 바뀌었다.
 *
 *  - 추가: closeOnOverlayClick=false · 언어 탭 · 이름/제목/본문 다국어(로케일 키만 갈아 끼움) · 오류 상자 ·
 *          POST /api/admin/notification-definitions (category=identity · policy_key · templates.mail)
 *  - 삭제: DELETE /api/admin/notification-definitions/{id} · 경고 문구 · 성공 뒤 두 목록 재조회
 *
 * @scenario surface=admin_screen
 */

import { describe, it, expect } from 'vitest';
import addModal from '../../layouts/partials/admin_settings/_modal_notification_identity_definition_add.json';
import deleteModal from '../../layouts/partials/admin_settings/_modal_notification_identity_definition_delete.json';
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

/** `{{ ... }}` 바인딩 식을 주어진 문맥으로 평가한다. */
function evaluate(binding: string, ctx: Record<string, unknown>): any {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(binding).trim());
  expect(m, `바인딩 식이 아닙니다: ${binding}`).not.toBeNull();
  const keys = Object.keys(ctx);
  // eslint-disable-next-line @typescript-eslint/no-implied-eval, no-new-func
  const fn = new Function(...keys, `return (${m![1]});`);
  return fn(...keys.map((k) => ctx[k]));
}

/** `$t:` 로 시작하는 문자열에서 키를 모두 뽑는다. */
function langKeys(node: unknown): string[] {
  return [...new Set((JSON.stringify(node).match(/\$t:admin\.[a-z0-9_.]+/g) ?? []).map((k) => k.slice('$t:admin.'.length)))];
}

describe('정책별 문구 추가 창', () => {
  /**
   * @effects identity_add_modal_posts_notification_definition
   */
  it('바깥을 눌러 닫히지 않는 xl 창이고 모달 목록에 등록되어 있다', () => {
    expect(addModal.id).toBe('modal_notification_identity_definition_add');
    expect(addModal.props.size).toBe('xl');
    expect(addModal.props.closeOnOverlayClick).toBe(false);
    expect(JSON.stringify(adminSettingsLayout.modals)).toContain('_modal_notification_identity_definition_add');
  });

  /**
   * @effects identity_add_modal_posts_notification_definition
   */
  it('이름·제목·본문은 지금 탭 로케일 키만 갈아 끼운다(다른 언어 값이 남는다)', () => {
    for (const field of ['name', 'subject', 'body']) {
      const key = `identity_notification_add_modal.form.${field}`;
      const write = collect(addModal, (n) => n.handler === 'setState' && typeof n.params?.[key] === 'string')[0];
      expect(write, `${field} 입력 처리가 없습니다.`).toBeDefined();
      const next = evaluate(write.params[key], {
        _global: { identity_notification_add_modal: { editLang: 'en', form: { [field]: { ko: '가' } } } },
        $locale: 'ko',
        $event: { target: { value: 'A' } },
      });
      expect(next).toEqual({ ko: '가', en: 'A' });
    }
  });

  /**
   * @effects identity_add_modal_posts_notification_definition
   */
  it('저장은 알림 정의 API 로 category=identity · policy_key · 메일 템플릿을 보낸다(소유·type·채널은 서버가 정한다)', () => {
    const save = byId(addModal, 'identity_notification_add_save_button');
    const call = collect(save, (n) => n.handler === 'apiCall')[0];
    expect(call.target).toBe('/api/admin/notification-definitions');
    expect(call.params.method).toBe('POST');
    expect(Object.keys(call.params.body).sort()).toEqual(['category', 'name', 'policy_key', 'templates']);
    expect(call.params.body.category).toBe('identity');
    expect(Object.keys(call.params.body.templates)).toEqual(['mail']);
    expect(Object.keys(call.params.body.templates.mail).sort()).toEqual(['body', 'subject']);
  });

  /**
   * @effects identity_add_modal_posts_notification_definition
   */
  it('실패하면 창을 닫지 않고 필드 오류를 상단 상자에 남긴다', () => {
    const save = byId(addModal, 'identity_notification_add_save_button');
    const call = collect(save, (n) => n.handler === 'apiCall')[0];
    const onError = JSON.stringify(call.onError ?? call.params?.onError ?? '');
    expect(onError).toContain('identity_notification_add_modal.errors');
    expect(onError).not.toContain('closeModal');

    // 실패 토스트 — 서버 문구를 우선하고 없으면 저장 실패 문구
    const toasts = collect(call.onError ?? call.params?.onError, (n) => n.handler === 'toast');
    expect(toasts.length, '실패 토스트가 없습니다.').toBe(1);
    expect(toasts[0].params.type).toBe('error');
    expect(toasts[0].params.message).toContain('error.message');
    expect(toasts[0].params.message).toContain('identity_add.save_failed');

    const errors = byId(addModal, 'identity_notification_add_errors');
    expect(evaluate(errors.if, { _global: { identity_notification_add_modal: { errors: { policy_key: ['x'] } } } })).toBeTruthy();
    expect(evaluate(errors.if, { _global: { identity_notification_add_modal: { errors: null } } })).toBeFalsy();
  });
});

describe('정책별 문구 삭제 창', () => {
  /**
   * @effects identity_delete_modal_deletes_operator_definition
   */
  it('대상 id 로 DELETE 하고, 성공하면 목록·「문구가 있는 정책」 을 다시 받는다', () => {
    const btn = byId(deleteModal, 'identity_notification_delete_confirm_button');
    const call = collect(btn, (n) => n.handler === 'apiCall')[0];
    expect(call.params.method).toBe('DELETE');
    expect(call.target).toBe('/api/admin/notification-definitions/{{_global.identity_notification_delete_modal?.id}}');

    const refetched = collect(call, (n) => n.handler === 'refetchDataSource').map((n) => n.params.dataSourceId);
    expect(refetched).toEqual(expect.arrayContaining(['notificationDefinitions', 'identityPolicyDefinitions']));

    // 실패하면 창을 닫지 않고 진행 표시를 끈 뒤 서버 문구(없으면 삭제 실패 문구)를 토스트로 띄운다
    const onError = call.onError ?? call.params?.onError;
    expect(JSON.stringify(onError ?? '')).not.toContain('closeModal');
    const toasts = collect(onError, (n) => n.handler === 'toast');
    expect(toasts.length, '삭제 실패 토스트가 없습니다.').toBe(1);
    expect(toasts[0].params.type).toBe('error');
    expect(toasts[0].params.message).toContain('error.message');
    expect(toasts[0].params.message).toContain('identity_delete.delete_failed');
  });

  /**
   * @effects identity_delete_modal_deletes_operator_definition
   */
  it('확인·경고 문구와 삭제 중 잠금이 있다', () => {
    expect(JSON.stringify(deleteModal)).toContain('$t:admin.settings.notification_definitions.identity_delete.warning_message');
    const btn = byId(deleteModal, 'identity_notification_delete_confirm_button');
    expect(evaluate(btn.props.disabled, { _global: { identity_notification_delete_modal: { isDeleting: true } } })).toBe(true);
    expect(evaluate(btn.props.disabled, { _global: { identity_notification_delete_modal: { isDeleting: false } } })).toBe(false);
  });
});

describe('문구 키', () => {
  /**
   * @effects identity_add_modal_posts_notification_definition
   */
  it('두 창이 쓰는 admin.* 키가 ko·en 에 모두 있다', () => {
    const keys = [...langKeys(addModal), ...langKeys(deleteModal)];
    expect(keys.length).toBeGreaterThan(10);
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      for (const key of keys) {
        const value = key.split('.').reduce<any>((acc, part) => (acc == null ? undefined : acc[part]), dict);
        expect(value, `${key} 가 없습니다.`).toBeTruthy();
      }
    }
  });
});

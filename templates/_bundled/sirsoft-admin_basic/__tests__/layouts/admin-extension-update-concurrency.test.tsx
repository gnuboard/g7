import { describe, it, expect } from 'vitest';
import moduleUpdateModal from '../../layouts/partials/admin_module_list/_modal_update.json';
import pluginUpdateModal from '../../layouts/partials/admin_plugin_list/_modal_update.json';
import templateUpdateModal from '../../layouts/partials/admin_template_list/_modal_update.json';

/**
 * 확장 업데이트 모달 — 동시 업데이트 거절(409)과 「업데이트할 것 없음」 문구 (#138 실측 ②·④)
 *
 * ② 확인 버튼을 두 번 누르면 업데이트가 두 번 수행됐다. 서버가 같은 확장의 두 번째 요청을 409 로 거절하므로,
 *    화면은 409 를 실패(오류 상자)로 보이지 않고 안내 토스트만 띄운다. 버튼은 풀어 둔다 — CLI 가 업데이트 중일 때
 *    409 를 받고도 버튼·취소를 잠가 두면 창을 닫을 수 없다.
 * ④ 성공 토스트가 고정 문구(「업데이트되었습니다」)라 업데이트할 것이 없어도 그렇게 보였다 — 서버 문구를 띄운다.
 */

type Json = Record<string, any>;

/** 모달에서 `/update` 로 가는 apiCall 을 찾는다. */
function findUpdateApiCall(node: unknown): Json | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const found = findUpdateApiCall(child);
      if (found) return found;
    }
    return undefined;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    const target = String(obj.target ?? obj.params?.target ?? '');
    if (obj.handler === 'apiCall' && target.endsWith('/update')) return obj;
    for (const value of Object.values(obj)) {
      const found = findUpdateApiCall(value);
      if (found) return found;
    }
  }
  return undefined;
}

describe('확장 업데이트 모달 — 동시 업데이트 409 · 업데이트할 것 없음 문구', () => {
  const cases = [
    { name: '모듈', modal: moduleUpdateModal, flag: 'isModuleUpdating', errorKey: 'moduleUpdateError', fallback: 'admin.modules.update_success' },
    { name: '플러그인', modal: pluginUpdateModal, flag: 'isPluginUpdating', errorKey: 'pluginUpdateError', fallback: 'admin.plugins.update_success' },
    { name: '템플릿', modal: templateUpdateModal, flag: 'isTemplateUpdating', errorKey: 'templateUpdateError', fallback: 'admin.templates.update_success' },
  ];

  /**
   * @scenario case=modal_409_and_message
   * @effects update_modal_treats_409_as_notice_and_shows_server_message
   */
  it.each(cases)('$name: 성공 토스트는 서버 문구를 띄운다 (고정 문구는 폴백만)', ({ modal, fallback }) => {
    const call = findUpdateApiCall(modal);
    expect(call).toBeDefined();

    const toasts = (call!.onSuccess as Json[]).filter((a) => a.handler === 'toast');
    expect(toasts).toHaveLength(1);
    expect(toasts[0].params.message).toBe(`{{response?.message ?? '$t:${fallback}'}}`);
  });

  /**
   * @scenario case=modal_409_and_message
   * @effects update_modal_treats_409_as_notice_and_shows_server_message
   */
  it.each(cases)('$name: 409 는 오류 상자 없이 안내 토스트만 띄우고 버튼을 푼다', ({ modal, flag, errorKey }) => {
    const call = findUpdateApiCall(modal);
    const onError = call!.onError as Json[];

    const conflict = onError.filter((a) => a.if === '{{error.status === 409}}');
    const conflictState = conflict.find((a) => a.handler === 'setState');
    const conflictToast = conflict.find((a) => a.handler === 'toast');

    expect(conflictState?.params[flag]).toBe(false);
    expect(conflictState?.params).not.toHaveProperty(errorKey);
    expect(conflictToast?.params.type).toBe('info');
  });

  /**
   * @scenario case=modal_409_and_message
   * @effects update_modal_treats_409_as_notice_and_shows_server_message
   */
  it.each(cases)('$name: 409 가 아닌 실패만 오류 상자와 오류 토스트를 띄운다', ({ modal, flag, errorKey }) => {
    const call = findUpdateApiCall(modal);
    const onError = call!.onError as Json[];

    // 조건 없이 오류를 띄우는 동작이 남아 있으면 409 에도 오류 상자가 뜬다
    onError
      .filter((a) => a.handler === 'toast' || (a.handler === 'setState' && errorKey in (a.params ?? {})))
      .forEach((a) => expect(a.if, `${a.handler} 에 409 분기 조건이 없습니다`).toBeDefined());

    const failure = onError.filter((a) => a.if === '{{error.status !== 409}}');
    expect(failure.find((a) => a.handler === 'setState')?.params).toMatchObject({ [flag]: false, [errorKey]: '{{error.message}}' });
    expect(failure.find((a) => a.handler === 'toast')?.params.type).toBe('error');
  });
});
